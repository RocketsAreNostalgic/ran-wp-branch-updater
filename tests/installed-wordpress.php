<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Tests;

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockStorageFailure;
use RAN\WPBranchUpdater\V1\WordPress\WordPressPackageExecutor;
use RAN\WPBranchUpdater\V1\WordPress\WordPressUpdaterLock;
use RuntimeException;
use WP_Error;
use ZipArchive;

/** Real adapters in a disposable, installed single-site WordPress database. */
final class InstalledWordPressProof {
	public static function check( bool $condition, string $message ): void {
		if ( ! $condition ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion identifies the failing integration boundary, not HTML output.
			throw new RuntimeException( $message );
		}
	}

	public function run(): void {
		$this->lock();
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$this->package( $type );
		}
	}

	private function lock(): void {
		global $wpdb;
		$name = 'auto_updater.lock';
		self::check( false === get_option( $name ), 'Disposable site must start without an updater lock.' );
		$owner = new WordPressUpdaterLock();
		$other = new WordPressUpdaterLock();
		// Prime WordPress's negative option cache before the native INSERT.
		get_option( $name );
		$token = $owner->acquire();
		self::check( $other->current_token() === $token && get_option( $name ) === $token, 'Insertion must be visible through SQL and the options cache.' );
		self::check( 'no' === $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, $name ) ), 'Native lock must not autoload.' );
		$contended = false;
		try {
			$other->acquire();
		} catch ( RuntimeException $error ) {
			$contended = 'WordPress updater is already running.' === $error->getMessage();
		}
		self::check( $contended && $owner->current_token() === $token, 'Contender must preserve the current owner.' );
		self::check( ! $other->release( 'wrong-token' ) && get_option( $name ) === $token, 'Wrong-token release must preserve the row and cached owner.' );
		self::check( $owner->release( $token ) && false === get_option( $name ) && null === $other->current_token(), 'Exact release must invalidate positive option cache.' );
		self::check( ! $owner->release( $token ), 'Repeated release must report lost ownership.' );

		$stale = (string) ( time() - 7200 );
		update_option( $name, $stale, false );
		self::check( get_option( $name ) === $stale && null === $other->current_token(), 'Expired database token must not be current.' );
		$replacement = $other->acquire();
		self::check( $replacement !== $stale && get_option( $name ) === $replacement, 'Stale takeover must invalidate the cached old token.' );
		self::check( ! $owner->release( $stale ) && $other->current_token() === $replacement, 'Expired owner must not delete replacement ownership.' );
		self::check( $other->release( $replacement ), 'Replacement owner must release its own token.' );

		update_option( $name, 'malformed-token', false );
		$contended = false;
		try {
			$owner->acquire();
		} catch ( RuntimeException $error ) {
			$contended = 'WordPress updater is already running.' === $error->getMessage();
		}
		self::check( $contended && 'malformed-token' === get_option( $name ), 'Malformed token must fail closed without stealing the row.' );
		delete_option( $name );
		self::check( 42 === $owner->run( static fn (): int => 42 ) && false === get_option( $name ), 'run must return the operation result and release.' );
		$observed = null;
		$failure  = new RuntimeException( 'operation failed' );
		try {
			$owner->run(
				static function () use ( $failure ): void {
					throw $failure;
				}
			);
		} catch ( RuntimeException $error ) {
			$observed = $error;
		}
		self::check( $observed === $failure, 'run must propagate the operation exception.' );
		self::check( false === get_option( $name ), 'Throwing operation must release the database lock.' );
		$original_options     = $wpdb->options;
		$previous_suppression = $wpdb->suppress_errors( true );
		try {
			$wpdb->options = $original_options . '_missing_branch_proof';
			foreach ( array( static fn () => $owner->acquire(), static fn () => $owner->current_token(), static fn () => $owner->release( '1' ) ) as $operation ) {
				$failed = false;
				try {
					$operation();
				} catch ( BranchDeploymentLockStorageFailure ) {
					$failed = true;
				}
				self::check( $failed, 'Real SQL failure must become a lock storage failure.' );
			}
		} finally {
			$wpdb->options = $original_options;
			$wpdb->suppress_errors( $previous_suppression );
		}
	}

	private function package( string $type ): void {
		$slug     = 'ran-branch-proof-' . $type;
		$executor = new WordPressPackageExecutor();
		foreach ( array( 'install', 'update' ) as $operation ) {
			$version     = 'install' === $operation ? '1.0.0' : '2.0.0';
			$declaration = new BranchDeploymentDeclaration( 'installed-proof', $type, $slug, 'fixture/repository', 'fixture', 'main', null, $operation, 'packages/target', 'plugin' === $type ? $slug . '/' . $slug . '.php' : $slug );
			$artifact    = $this->artifact( $declaration, $version );
			$before      = $this->hooks();
			$observed    = false;
			$observer    = static function ( mixed $reply, mixed $package, mixed $upgrader, array $extra ) use ( &$observed, $artifact, $type, $operation ): mixed {
				if ( ( $extra['type'] ?? null ) === $type && ( $extra['action'] ?? null ) === $operation ) {
					$observed  = $reply === $artifact->get_path() && $package === $artifact->get_path();
					$unrelated = apply_filters(
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Probe native source selection with unrelated context during a real operation.
						'upgrader_source_selection',
						'/unrelated-source',
						'/unrelated-remote',
						$upgrader,
						array(
							'type'   => 'unrelated',
							'action' => $operation,
						)
					);
					self::check( '/unrelated-source' === $unrelated, 'Scoped source hook must ignore unrelated operations.' );
				}
				return $reply;
			};
			add_filter( 'upgrader_pre_download', $observer, 20, 4 );
			try {
				$executor->preflight( $declaration, $artifact );
				$result = $executor->execute_core( $declaration, $artifact );
				self::check( $result->is_successful(), $type . ' ' . $operation . ' must succeed through the default adapter.' );
				$facts = $executor->installed_facts( $declaration );
				self::check( $version === $facts['version'] && false === $facts['active'], 'Installed bytes/version and inactive state must match.' );
				$path = 'plugin' === $type ? WP_PLUGIN_DIR . '/' . $slug : get_theme_root() . '/' . $slug;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local installed fixture bytes, never a remote URL.
				self::check( file_get_contents( $path . '/proof.txt' ) === $version, 'Selected nested source must replace the installed payload.' );
				self::check( ! file_exists( $path . '/outside.txt' ), 'Repository siblings must not enter the installed package.' );
				self::check( ! file_exists( ABSPATH . '.maintenance' ) && ! wp_doing_cron(), 'Maintenance and cron scope must be restored.' );
				self::check( $observed, 'Real upgrader must consume the exact local archive through the scoped hook.' );
			} finally {
				remove_filter( 'upgrader_pre_download', $observer, 20 );
				$artifact->cleanup();
			}
			self::check( $before === $this->hooks(), 'Executor hooks must be restored after success.' );
		}
		$this->refused_update( $type, $slug );
		$this->failures( $type );
	}

	private function refused_update( string $type, string $slug ): void {
		$d        = new BranchDeploymentDeclaration( 'refusal-proof', $type, $slug, 'fixture/repository', 'fixture', 'main', null, 'update', 'packages/target', 'plugin' === $type ? $slug . '/' . $slug . '.php' : $slug );
		$artifact = $this->artifact( $d, '3.0.0' );
		$executor = new WordPressPackageExecutor();
		$refuse   = static fn (): bool => false;
		add_filter( 'auto_update_' . $type, $refuse );
		$before   = $this->hooks();
		try {
			$result = $executor->execute_core( $d, $artifact );
			self::check( CorePackageExecutionFailure::WORDPRESS_REFUSED === $result->get_failure(), 'Automatic updater refusal must not be reported as success.' );
			self::check( '2.0.0' === $executor->installed_facts( $d )['version'], 'Refused update must retain installed version.' );
			self::check( $before === $this->hooks() && ! wp_doing_cron() && ! file_exists( ABSPATH . '.maintenance' ), 'Failed update must restore transient, cron, updater and maintenance scope.' );
		} finally {
			remove_filter( 'auto_update_' . $type, $refuse );
			$artifact->cleanup();
		}
	}

	private function failures( string $type ): void {
		$slug     = 'ran-branch-failure-' . $type;
		$d        = new BranchDeploymentDeclaration( 'failure-proof', $type, $slug, 'fixture/repository', 'fixture', 'main', null, 'install', 'packages/target' );
		$artifact = $this->artifact( $d, '1.0.0' );
		$executor = new WordPressPackageExecutor();
		$veto     = static fn (): WP_Error => new WP_Error( 'controlled_local_veto' );
		add_filter( 'upgrader_pre_download', $veto, 5 );
		$before   = $this->hooks();
		try {
			$result = $executor->execute_core( $d, $artifact );
			self::check( CorePackageExecutionFailure::WORDPRESS_UNCERTAIN === $result->get_failure(), 'Native install returns null on early download veto; adapter must fail closed.' );
			self::check( $before === $this->hooks(), 'Executor must retain pre-existing hooks and remove its hooks after failure.' );
		} finally {
			remove_filter( 'upgrader_pre_download', $veto, 5 );
		}
		$path = 'plugin' === $type ? WP_PLUGIN_DIR . '/' . $slug : get_theme_root() . '/' . $slug;
		self::check( ! file_exists( $path ), 'Veto must prevent installation.' );

		// A genuine successful installation with an extra unrelated completion must fail closed.
		$noise = static function ( mixed $response ): mixed {
			do_action(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Inject a foreign native completion to prove result validation.
				'upgrader_process_complete',
				new \stdClass(),
				array(
					'type'   => 'unrelated',
					'action' => 'install',
				)
			);
			return $response;
		};
		add_filter( 'upgrader_pre_install', $noise );
		$before = $this->hooks();
		try {
			$result = $executor->execute_core( $d, $artifact );
			self::check( CorePackageExecutionFailure::OPERATION_MISMATCH === $result->get_failure(), 'Foreign completion must prevent a success claim.' );
			self::check( is_file( $path . '/proof.txt' ), 'Mismatch proof must still exercise actual installed bytes.' );
			self::check( $before === $this->hooks(), 'Mismatch must restore executor hook scope.' );
		} finally {
			remove_filter( 'upgrader_pre_install', $noise );
			$artifact->cleanup();
		}
	}

	private function artifact( BranchDeploymentDeclaration $d, string $version ): PreparedArchive {
		$source = wp_tempnam( 'branch-proof.zip' );
		$zip    = new ZipArchive();
		self::check( true === $zip->open( $source, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Fixture ZIP must open.' );
		$prefix = 'repository/packages/target/';
		if ( 'plugin' === $d->package_type ) {
			$zip->addFromString( $prefix . $d->slug . '.php', "<?php\n/*\nPlugin Name: Branch proof\nVersion: " . $version . "\n*/\n" );
		} else {
			$zip->addFromString( $prefix . 'style.css', "/*\nTheme Name: Branch proof\nVersion: " . $version . "\n*/\n" );
			$zip->addFromString( $prefix . 'index.php', '<?php // Local fixture.' );
		}
		$zip->addFromString( $prefix . 'proof.txt', $version );
		$zip->addFromString( 'repository/outside.txt', 'excluded' );
		$zip->close();
		$offer = new ArchiveOffer(
			'fixture',
			'fixture',
			'local',
			static function ( string $destination, int $maximum_artifact_bytes ) use ( $source ): void {
				self::check( filesize( $source ) <= $maximum_artifact_bytes && copy( $source, $destination ), 'Copy controlled fixture into production custody.' );
			},
			static function (): void {}
		);
		try {
			return PreparedArchive::download_and_validate( $offer, $d, get_temp_dir() . 'ran-branch-proof-custody' );
		} finally {
			wp_delete_file( $source );
		}
	}

	private function hooks(): array {
		global $wp_filter;
		$result = array();
		foreach ( array( 'upgrader_pre_download', 'upgrader_source_selection', 'upgrader_process_complete', 'pre_site_transient_update_plugins', 'pre_site_transient_update_themes', 'automatic_updates_is_vcs_checkout', 'wp_doing_cron', 'wp_maybe_auto_update' ) as $hook ) {
			$result[ $hook ] = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array();
		}
		return $result;
	}
}

$ran_wp_branch_updater_site = getenv( 'RAN_BRANCH_WORDPRESS_PATH' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local disposable-site marker before loading WordPress.
if ( ! is_string( $ran_wp_branch_updater_site ) || '1' !== getenv( 'RAN_BRANCH_DISPOSABLE' ) || ! is_file( $ran_wp_branch_updater_site . '/.ran-branch-disposable' ) || 'RAN Branch disposable site' !== trim( (string) file_get_contents( $ran_wp_branch_updater_site . '/.ran-branch-disposable' ) ) ) {
	throw new RuntimeException( 'A marked disposable WordPress site is required.' );
}
require $ran_wp_branch_updater_site . '/wp-load.php';
require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
( new InstalledWordPressProof() )->run();
echo "PASS Branch default executor and database lock installed proofs\n";
