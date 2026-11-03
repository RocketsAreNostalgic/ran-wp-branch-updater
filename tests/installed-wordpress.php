<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Tests;

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\AdmittedPackageExecutor;
use RAN\WPBranchUpdater\V1\Contract\AdmittedTargetFacts;
use RAN\WPBranchUpdater\V1\Runtime\BranchUpdater;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockStorageFailure;
use RAN\WPBranchUpdater\V1\WordPress\WordPressPackageExecutor;
use RAN\WPBranchUpdater\V1\WordPress\WordPressUpdaterLock;
use RuntimeException;
use ReflectionMethod;
use InvalidArgumentException;
use RAN\WPBranchUpdater\V1\WordPress\WordPressCorePackageExecutor;
use WP_Error;
use ZipArchive;
use RAN\WPBranchUpdater\V1\Archive\ArchiveValidator;
use RAN\WPBranchUpdater\V1\GitHubFixtureProvider;
use RAN\WPBranchUpdater\V1\HeaderFormattingFixtures;

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
		$this->non_reclaiming_lock();
		$this->lock_race();
		$this->adapter_boundaries();
		$this->header_compatibility();
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$this->package( $type );
			$this->initial_identity( $type, false );
			$this->initial_identity( $type, true );
		}
	}

	private function header_compatibility(): void {
		$parent_slug = 'ran-header-parent-' . wp_generate_uuid4();
		$parent_root = get_theme_root() . '/' . $parent_slug;
		self::check( ! file_exists( $parent_root ) && wp_mkdir_p( $parent_root ), 'Synthetic parent directory must be new.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Create only the synthetic parent under the verified disposable site's theme root.
		file_put_contents( $parent_root . '/style.css', "/* Theme Name: Header parent */\nVersion: 1.0.0" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Create only the synthetic parent under the verified disposable site's theme root.
		file_put_contents( $parent_root . '/index.php', '<?php // Synthetic parent.' );
		wp_clean_themes_cache();
		$read_headers = new ReflectionMethod( ArchiveValidator::class, 'read_headers' );
		$read_parent  = new ReflectionMethod( WordPressCorePackageExecutor::class, 'theme_parent_from_archive' );
		$eligibility  = new ReflectionMethod( WordPressCorePackageExecutor::class, 'theme_parent_is_available' );
		$executor     = new WordPressCorePackageExecutor();
		try {
			foreach ( array(
				'plugin' => 'Plugin Name',
				'theme'  => 'Theme Name',
			) as $type => $name ) {
				foreach ( HeaderFormattingFixtures::variants( $name ) as $format => $contents ) {
					$contents = str_replace( 'Template: parent', 'Template: ' . $parent_slug, $contents );
					$source   = wp_tempnam( 'branch-header.txt' );
					$archive  = wp_tempnam( 'branch-header.zip' );
					$zip      = new ZipArchive();
					$artifact = null;
					try {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Differential proof writes only its synthetic temporary header file.
						file_put_contents( $source, $contents );
						$fields = array(
							$name               => $name,
							'Version'           => 'Version',
							'Requires at least' => 'Requires at least',
							'Requires PHP'      => 'Requires PHP',
						);
						$native = get_file_data( $source, $fields );
						self::check( 'Demo' === $native[ $name ] && '1.2.3' === $native['Version'], 'WordPress must read the synthetic formatting control.' );
						self::check( true === $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Header fixture ZIP must open.' );
						$zip->addFromString( 'repository/demo/' . ( 'plugin' === $type ? 'demo.php' : 'style.css' ), $contents );
						$zip->close();
						self::check( true === $zip->open( $archive, ZipArchive::RDONLY ), 'Reopen the committed header bytes for unchanged-entry reads.' );
						self::check( $native === $read_headers->invoke( new ArchiveValidator(), $zip, 0, $name ), 'Archive fields must match actual get_file_data for ' . $type . '/' . $format );
						$zip->close();
						if ( 'theme' === $type ) {
							$d        = new BranchDeploymentDeclaration( 'header-' . $format, 'theme', 'demo', 'synthetic/demo', 'fixture', 'main', 'abc123', 'install', 'demo' );
							$artifact = PreparedArchive::download_and_validate( ( new GitHubFixtureProvider( $archive, 'abc123' ) )->prepare( $d ), $d, get_temp_dir() . 'ran-branch-proof-custody' );
							$template = get_file_data( $source, array( 'Template' => 'Template' ) )['Template'];
							self::check( $parent_slug === $template && $template === $read_parent->invoke( $executor, $artifact, 'demo' ), 'Template must match actual get_file_data for ' . $format );
							self::check( true === $eligibility->invoke( $executor, $artifact, 'demo', 'demo' ), 'Existing synthetic parent must remain eligible.' );
							self::check( false === $eligibility->invoke( $executor, $artifact, $parent_slug, 'demo' ), 'A theme must not use itself as parent.' );
							if ( 'lf' === $format ) {
								$parent_theme = wp_get_theme( $parent_slug );
								wp_delete_file( $parent_root . '/index.php' );
								wp_delete_file( $parent_root . '/style.css' );
								// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only this proof's empty synthetic parent so WordPress observes a missing theme directory.
								rmdir( $parent_root );
								clearstatcache();
								$parent_theme->cache_delete();
								wp_clean_themes_cache();
								self::check( false === $eligibility->invoke( $executor, $artifact, 'demo', 'demo' ), 'Unavailable synthetic parent must remain ineligible.' );
								self::check( wp_mkdir_p( $parent_root ), 'Restore only the synthetic parent for remaining formatting controls.' );
								// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Restore only the synthetic parent before remaining formatting controls.
								file_put_contents( $parent_root . '/style.css', "/* Theme Name: Header parent */\nVersion: 1.0.0" );
								// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Restore only the synthetic parent before remaining formatting controls.
								file_put_contents( $parent_root . '/index.php', '<?php // Synthetic parent.' );
								wp_clean_themes_cache();
							}
						}
					} finally {
						$artifact?->cleanup();
						wp_delete_file( $source );
						wp_delete_file( $archive );
					}
				}
			}
		} finally {
			wp_delete_file( $parent_root . '/index.php' );
			wp_delete_file( $parent_root . '/style.css' );
			if ( is_dir( $parent_root ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the empty synthetic parent created by this proof in the verified disposable theme root.
				rmdir( $parent_root );
			}
			wp_clean_themes_cache();
		}
	}

	private function adapter_boundaries(): void {
		$executor = new WordPressCorePackageExecutor();
		$method   = new ReflectionMethod( $executor, 'transient_filter' );
		$offer    = (object) array( 'new_version' => '2.0.0' );
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$filter            = $method->invoke( $executor, $type, $offer, 'target' );
			$foreign           = new class() {
				public string $last_checked = 'preserved';
				/** @var array<string,string> */
				public array $response = array();
			};
			$previous          = 'previous';
			$response          = array(
				'other'  => 'preserved',
				'target' => &$previous,
			);
			$foreign->response =& $response;
			$foreign_before    = array(
				'last_checked' => 'preserved',
				'response'     => array(
					'other'  => 'preserved',
					'target' => 'previous',
				),
			);
			$native            = (object) array( 'response' => array( 'other' => 'preserved' ) );
			foreach ( array( false, (object) array( 'response' => false ), $foreign, $native ) as $input ) {
				$result = $filter( $input );
				self::check( $result instanceof \stdClass && is_array( $result->response ), 'Update transient must be a native value bag with an array response.' );
				self::check( ( 'plugin' === $type ? $offer : (array) $offer ) === $result->response['target'], 'Offer must retain native plugin/theme representation.' );
				if ( $input === $foreign || $input === $native ) {
					self::check( 'preserved' === $result->response['other'], 'Unrelated offers must survive normalization.' );
				}
				if ( $input === $foreign ) {
					self::check( 'preserved' === $result->last_checked && get_object_vars( $foreign ) === $foreign_before, 'Foreign public fields survive without mutating even a referenced response.' );
				}
				if ( $input === $native ) {
					self::check( $result === $native, 'Native transient identity must remain unchanged.' );
				}
			}
		}
		$method = new ReflectionMethod( $executor, 'run_core_operation' );
		$failed = false;
		try {
			$method->invoke( $executor, 'update', 'plugin', '', null );
		} catch ( InvalidArgumentException $error ) {
			$failed = 'WordPress update requires an offer.' === $error->getMessage();
		}
		self::check( $failed, 'Missing update offer must be rejected before the automatic updater call.' );

		global $wp_filesystem;
		WP_Filesystem();
		$filesystem = $wp_filesystem;
		if ( ! $filesystem instanceof \WP_Filesystem_Base ) {
			throw new RuntimeException( 'Installed proof requires the native filesystem.' );
		}
		$remote = get_temp_dir() . 'ran-branch-source-' . wp_generate_uuid4();
		$source = $remote . '/repository';
		self::check( wp_mkdir_p( $source ), 'Source boundary fixture must exist.' );
		$method = new ReflectionMethod( $executor, 'source_selection_filter' );
		$filter = $method->invoke( $executor, 'target', null, 'plugin', 'install', null );
		try {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Inject an invalid native filesystem only for this boundary proof; restore it in finally.
			$wp_filesystem = new \stdClass();
			$result        = $filter(
				$source,
				$remote,
				new \stdClass(),
				array(
					'type'   => 'plugin',
					'action' => 'install',
				)
			);
			self::check( $result instanceof WP_Error && 'ran_branch_deployment_invalid_package_source' === $result->get_error_code(), 'Invalid filesystem object must return the package-source failure.' );
			self::check( is_dir( $source ) && ! file_exists( $remote . '/target' ), 'Invalid filesystem must not move package bytes.' );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact native filesystem after the controlled invalid-object proof.
			$wp_filesystem = $filesystem;
			$filesystem->delete( $remote, true );
		}
	}

	private function missing_theme_version( string $slug ): void {
		global $wp_filesystem;
		$filesystem = $wp_filesystem;
		if ( ! $filesystem instanceof \WP_Filesystem_Base ) {
			throw new RuntimeException( 'Installed proof requires the native filesystem.' );
		}
		$style = get_theme_root() . '/' . $slug . '/style.css';
		self::check( $filesystem->move( $style, $style . '.proof-backup', false ), 'Temporarily remove the installed theme stylesheet.' );
		try {
			wp_clean_themes_cache();
			$theme = wp_get_theme( $slug );
			self::check( $theme->exists() && false === $theme->get( 'Version' ), 'Real malformed theme must expose the false-version boundary.' );
			$d      = new BranchDeploymentDeclaration( 'missing-version', 'theme', $slug, 'fixture/repository', 'fixture', 'main', null, 'update', null, $slug );
			$failed = false;
			try {
				( new WordPressPackageExecutor() )->installed_facts( $d );
			} catch ( RuntimeException $error ) {
				$failed = 'WordPress did not report an installed package version.' === $error->getMessage();
			}
			self::check( $failed, 'Unavailable theme version must not become an admitted installed fact.' );
		} finally {
			self::check( $filesystem->move( $style . '.proof-backup', $style, false ), 'Restore installed theme stylesheet.' );
			wp_clean_themes_cache();
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
			foreach ( array( static fn () => $owner->acquire(), static fn () => $owner->try_acquire_without_reclaim(), static fn () => $owner->current_token(), static fn () => $owner->release( '1' ) ) as $operation ) {
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

	private function non_reclaiming_lock(): void {
		global $wpdb;
		$name = 'auto_updater.lock';
		$lock = new WordPressUpdaterLock();
		self::check( false === get_option( $name ), 'Non-reclaiming proof requires no existing lock.' );
		$token = $lock->try_acquire_without_reclaim();
		if ( ! is_string( $token ) ) {
			throw new RuntimeException( 'Absent-row acquisition must succeed.' );
		}
		self::check( get_option( $name ) === $token, 'Absent-row acquisition must invalidate the negative cache.' );
		self::check( null === $lock->try_acquire_without_reclaim() && get_option( $name ) === $token, 'A present current owner must remain unchanged.' );
		self::check( $lock->release( $token ) && false === get_option( $name ), 'Non-reclaiming token uses exact release and positive cache invalidation.' );
		foreach ( array( (string) ( time() - 7200 ), 'malformed-token', '', '0' ) as $occupied ) {
			update_option( $name, $occupied, false );
			self::check( get_option( $name ) === $occupied, 'Prime occupied option cache.' );
			$query_count = $wpdb->num_queries;
			self::check( null === $lock->try_acquire_without_reclaim(), 'Every occupied row must refuse acquisition.' );
			self::check( 1 === $wpdb->num_queries - $query_count, 'Non-reclaiming acquisition must execute exactly one INSERT, not a check-then-reclaim sequence.' );
			// get_var() maps an empty SQL string to null; a row read preserves the exact stored bytes.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
			self::check( null !== $row && $row->option_value === $occupied && get_option( $name ) === $occupied, 'Occupied SQL and cached bytes must survive unchanged.' );
			self::check( ! $lock->release( 'wrong-token' ), 'Wrong-token release must not erase an occupied row.' );
			self::check( $lock->release( $occupied ) && false === get_option( $name ), 'Proof cleanup releases only its exact inserted row.' );
		}
	}

	/** @param resource $output */
	private static function child_line( $output ): string {
		$read   = array( $output );
		$write  = null;
		$except = null;
		self::check( 1 === stream_select( $read, $write, $except, 15 ), 'Lock contender must respond within the bounded proof deadline.' );
		$line = fgets( $output );
		if ( ! is_string( $line ) ) {
			throw new RuntimeException( 'Lock contender must provide a result.' );
		}
		return trim( $line );
	}

	/** Independent PHP processes load WordPress and contend through separate DB connections. */
	private function lock_race(): void {
		global $wpdb;
		$name = 'auto_updater.lock';
		self::check( false === get_option( $name ), 'Race requires an absent updater lock.' );
		$workers = array();
		$tokens  = array();
		try {
			for ( $index = 0; $index < 2; ++$index ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Independent CLI processes prove actual database contention on the verified disposable site.
				$process = proc_open(
					array( PHP_BINARY, __FILE__, '--lock-contender' ),
					array(
						0 => array( 'pipe', 'r' ),
						1 => array( 'pipe', 'w' ),
						2 => array( 'pipe', 'w' ),
					),
					$pipes
				);
				if ( ! is_resource( $process ) ) {
					throw new RuntimeException( 'Cannot start independent WordPress lock contender.' );
				}
				$workers[] = array( $process, $pipes );
				self::check( 'ready' === self::child_line( $pipes[1] ), 'Contender must load WordPress before racing.' );
			}
			foreach ( $workers as $worker ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Send a bounded command through this proof-owned subprocess pipe.
				fwrite( $worker[1][0], "acquire\n" );
			}
			foreach ( $workers as $worker ) {
				$line = self::child_line( $worker[1][1] );
				self::check( 'occupied' === $line || 1 === preg_match( '/^owned:[0-9]+$/D', $line ), 'Contender must report explicit ownership or occupancy.' );
				if ( str_starts_with( $line, 'owned:' ) ) {
					$tokens[] = substr( $line, 6 );
				}
			}
			self::check( 1 === count( $tokens ), 'Exactly one independent process may own the atomic INSERT.' );
			self::check( $tokens[0] === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) ), 'Winning row must remain while both contenders report.' );
			foreach ( $workers as $worker ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Send a bounded command through this proof-owned subprocess pipe.
				fwrite( $worker[1][0], "release\n" );
				self::check( 'done' === self::child_line( $worker[1][1] ), 'Each contender must complete exact-owner cleanup.' );
			}
		} finally {
			foreach ( $workers as $worker ) {
				foreach ( $worker[1] as $pipe ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close this proof-owned subprocess pipe.
					fclose( $pipe );
				}
				proc_terminate( $worker[0] );
				proc_close( $worker[0] );
			}
			foreach ( $tokens as $token ) {
				( new WordPressUpdaterLock() )->release( $token );
			}
		}
		self::check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) ), 'Race must leave no durable lock.' );
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	public static function lock_contender(): void {
		$lock = new WordPressUpdaterLock();
		echo "ready\n";
		self::check( "acquire\n" === fgets( STDIN ), 'Contender requires explicit parent acquisition signal.' );
		$token = $lock->try_acquire_without_reclaim();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Closed CLI protocol transmits only this adapter-generated decimal lock token, never HTML or secrets.
		echo null === $token ? "occupied\n" : 'owned:' . $token . "\n";
		self::check( "release\n" === fgets( STDIN ), 'Contender retains ownership until explicit parent release signal.' );
		if ( null !== $token ) {
			self::check( $lock->release( $token ), 'Winner must release exactly its token.' );
		}
		echo "done\n";
	}

	/** Exercise API 3 admission with real archive validation, database lock and WordPress installation. */
	private function initial_identity( string $type, bool $mismatch ): void {
		$slug       = 'ran-identity-' . $type . ( $mismatch ? '-refused' : '-accepted' );
		$identifier = 'plugin' === $type ? $slug . '/Bootstrap.php' : $slug;
		$d          = new BranchDeploymentDeclaration( 'identity-' . $slug, $type, $slug, 'fixture/repository', 'fixture', 'main', null, 'install', 'packages/target', null, null, $identifier );
		$path       = 'plugin' === $type ? WP_PLUGIN_DIR . '/' . $slug : get_theme_root() . '/' . $slug;
		self::check( ! file_exists( $path ), 'Identity proof requires an absent destination.' );
		self::check( array() === glob( get_temp_dir() . 'ran-branch-proof-custody/*' ), 'Identity proof requires empty archive custody.' );
		$journal  = new class() implements AdmittedAttemptJournal {
			public int $fences     = 0;
			public string $outcome = '';
			public function record_resolved_ref( string $ref ): void {}
			public function mark_mutation_started(): void {
				++$this->fences;
			}
			public function finish( string $code ): void {
				$this->outcome = $code;
			}
		};
		$target   = new class() implements AdmittedTargetFacts {
			public int $adoptions = 0;
			public function assert_mutation_allowed(): void {}
			public function frozen_target( BranchDeploymentDeclaration $deployment, bool $defer_existing ): ?array {
				InstalledWordPressProof::check( 'install' === $deployment->operation && ( $defer_existing || null === $deployment->installed_identifier ), 'Initial admission must not carry installed management authority.' );
				return null;
			}
			public function maintenance_active(): bool {
				return file_exists( ABSPATH . '.maintenance' );
			}
			public function recheck_managed( BranchDeploymentDeclaration $deployment ): void {}
			public function installed( BranchDeploymentDeclaration $deployment ): array {
				return ( new WordPressPackageExecutor() )->installed_facts( $deployment );
			}
			public function baseline_now( BranchDeploymentDeclaration $deployment, array $baseline ): array {
				InstalledWordPressProof::check( '' !== $baseline['identifier'], 'A baseline must identify its package.' );
				return $this->installed( $deployment );
			}
			public function adopt( BranchDeploymentDeclaration $deployment ): bool {
				InstalledWordPressProof::check( $deployment->expected_identifier === $this->installed( $deployment )['identifier'], 'Adoption must receive exactly the installed requested identity.' );
				++$this->adoptions;
				return true;
			}
		};
		$archives = new class( fn (): PreparedArchive => $this->artifact( $d, '1.0.0', $mismatch ? 'Wrong.php' : 'Bootstrap.php', $mismatch && 'theme' === $type ) ) implements AdmittedArchiveSource {
			/** @param \Closure():PreparedArchive $prepare */
			public function __construct( private \Closure $prepare ) {}
			public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact {
				InstalledWordPressProof::check( null === $baseline && null !== $deployment->expected_identifier, 'Initial archive admission must retain the requested identity without a baseline.' );
				return ( $this->prepare )();
			}
			public function verify_current_head(): void {}
		};
		$executor = new class() implements AdmittedPackageExecutor {
			public int $calls = 0;
			public function preflight( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): void {
				if ( ! $artifact instanceof PreparedArchive ) {
					throw new RuntimeException( 'Installed proof requires real validated archive custody.' );
				}
				( new WordPressPackageExecutor() )->preflight( $deployment, $artifact );
			}
			public function execute( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
				if ( ! $artifact instanceof PreparedArchive ) {
					throw new RuntimeException( 'Installed proof requires a validated archive.' );
				}
				++$this->calls;
				return ( new WordPressPackageExecutor() )->execute_core( $deployment, $artifact );
			}
		};
		$updater  = BranchUpdater::for_admitted_attempt( $d, $journal, $archives, $target, $executor, new WordPressUpdaterLock() );
		$pending  = 'plugin' === $type
			? $updater->plugin( $d->repository, $d->repository_id, $d->branch, null, $slug, $d->subdirectory )
			: $updater->theme( $d->repository, $d->repository_id, $d->branch, $slug, $d->subdirectory );
		$outcome  = $pending->deploy();
		self::check( $journal->outcome === $outcome, 'The admitted outcome must be journalled.' );
		if ( $mismatch ) {
			self::check( 'preflight_failed' === $outcome && 0 === $journal->fences && 0 === $executor->calls && 0 === $target->adoptions, 'Mismatched archive must refuse before mutation or adoption.' );
			self::check( ! file_exists( $path ), 'Refused identity must leave destination absent.' );
		} else {
			self::check( 'deployed' === $outcome && 1 === $journal->fences && 1 === $executor->calls && 1 === $target->adoptions, 'Exact identity must install and adopt once.' );
			$facts = $target->installed( $d );
			self::check( $identifier === $facts['identifier'] && '1.0.0' === $facts['version'] && ! $facts['active'], 'WordPress must report the exact inactive identity and version.' );
			self::check( is_file( $path . '/proof.txt' ) && ! file_exists( $path . '/outside.txt' ), 'Only the selected repository subdirectory may be installed.' );
		}
		self::check( false === get_option( 'auto_updater.lock' ) && ! file_exists( ABSPATH . '.maintenance' ), 'Identity proof must release native lock and maintenance scope.' );
		self::check( array() === glob( get_temp_dir() . 'ran-branch-proof-custody/*' ), 'Accepted and rejected archives must leave no custody files.' );
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
					$observed = $reply === $artifact->get_path() && $package === $artifact->get_path();
				}
				return $reply;
			};
			add_filter( 'upgrader_pre_download', $observer, 20, 4 );
			try {
				$this->preflight_admission( $executor, $declaration, $artifact );
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
		if ( 'theme' === $type ) {
			$this->missing_theme_version( $slug );
		}
	}

	private function preflight_admission( WordPressPackageExecutor $executor, BranchDeploymentDeclaration $declaration, PreparedArchive $artifact ): void {
		$observations = 0;
		$observe      = static function ( mixed $value ) use ( &$observations ): mixed {
			++$observations;
			return $value;
		};
		add_filter( 'extra_plugin_headers', $observe );
		add_filter( 'stylesheet', $observe );
		try {
			self::check( array() === $executor->preflight( $declaration, $artifact ), 'WordPress preflight must return no installed observations.' );
			self::check( 0 === $observations, 'Preflight must not query plugin headers or the active stylesheet.' );
		} finally {
			remove_filter( 'extra_plugin_headers', $observe );
			remove_filter( 'stylesheet', $observe );
		}
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

	private function artifact( BranchDeploymentDeclaration $d, string $version, ?string $main_file = null, bool $omit_style = false ): PreparedArchive {
		$source = wp_tempnam( 'branch-proof.zip' );
		$zip    = new ZipArchive();
		self::check( true === $zip->open( $source, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Fixture ZIP must open.' );
		$prefix = 'repository/packages/target/';
		if ( 'plugin' === $d->package_type ) {
			$zip->addFromString( $prefix . ( $main_file ?? $d->slug . '.php' ), "<?php\n/*\nPlugin Name: Branch proof\nVersion: " . $version . "\n*/\n" );
		} else {
			$zip->addFromString( $prefix . ( $omit_style ? 'missing-style.css' : 'style.css' ), "/*\nTheme Name: Branch proof\nVersion: " . $version . "\n*/\n" );
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

	/**
	 * @return array<string,array<int,array<string,array{function:callable,accepted_args:int}>>>
	 */
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
InstalledWordPressProof::check( realpath( ABSPATH ) === realpath( $ran_wp_branch_updater_site ) && ! is_link( $ran_wp_branch_updater_site ) && realpath( WP_CONTENT_DIR ) === realpath( $ran_wp_branch_updater_site . '/wp-content' ) && ! is_link( WP_CONTENT_DIR ) && 'http://localhost' === untrailingslashit( home_url() ), 'Proof requires the exact disposable site, content directory and local URL.' );
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
if ( in_array( '--lock-contender', $argv ?? array(), true ) ) {
	InstalledWordPressProof::lock_contender();
	return;
}
( new InstalledWordPressProof() )->run();
echo "PASS Branch default executor and database lock installed proofs\n";
