<?php
// phpcs:disable WordPress.Security.EscapeOutput -- The executor returns internal failures to its caller.
declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\WordPress;

use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Contract\PackageExecutor;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionFailure;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RAN\WPBranchUpdater\V1\Runtime\Deployment;
use RuntimeException;

/** Thin real-WordPress executor: WP owns installation; this package supplies one local ZIP. */
final class WordPressPackageExecutor implements PackageExecutor {
	public function __construct( private readonly CorePackageExecutor $core = new CorePackageExecutor() ) {}
	public function execute( Deployment $d, PreparedArchive $archive ): void {
		$result = $this->executeCore( $d, $archive );
		if ( ! $result->isSuccessful() ) {
			throw new RuntimeException( 'WordPress execution failed: ' . ( $result->getFailure()?->value ?? 'unknown' ) );
		}
	}
	public function executeCore( Deployment $d, PreparedArchive $archive ): CorePackageExecutionResult {
		if ( ! defined( 'ABSPATH' ) ) {
			throw new RuntimeException( 'WordPress upgrader is unavailable.' );
		}
		return match ( array( $d->operation, $d->packageType ) ) {
			array( 'install', 'plugin' ) => $this->core->installPlugin( $archive, $d->slug, $d->subdirectory ),
			array( 'install', 'theme' ) => $this->core->installTheme( $archive, $d->slug, $d->subdirectory ),
			array( 'update', 'plugin' ) => $this->core->updatePlugin( $archive, $d->slug, $d->subdirectory, $d->installedIdentifier ?? $d->slug . '/' . $d->slug . '.php' ),
			array( 'update', 'theme' ) => $this->core->updateTheme( $archive, $d->slug, $d->subdirectory, $d->installedIdentifier ?? $d->slug ),
		};
	}
	public function preflight( Deployment $d, PreparedArchive $archive ): array {
		if ( is_multisite() ) {
			throw new RuntimeException( 'Branch deployment supports single-site WordPress only.' );
		}
		if ( ! wp_is_file_mod_allowed( 'ran-branch-deployment' ) ) {
			throw new RuntimeException( 'WordPress file modifications are disabled.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method() ) {
			throw new RuntimeException( 'The WordPress direct filesystem method is required.' );
		}
		if ( file_exists( ABSPATH . '.maintenance' ) || is_link( ABSPATH . '.maintenance' ) ) {
			throw new RuntimeException( 'WordPress maintenance mode is active.' );
		}
		$identifier = $d->installedIdentifier ?? ( 'plugin' === $d->packageType ? $d->slug . '/' . $d->slug . '.php' : $d->slug );
		$before     = $this->installedState( $d->packageType, $identifier );
		$archive->assertUnchanged();
		return $before;
	}
	public function installedFacts( Deployment $d ): array {
		$identifier = $d->installedIdentifier ?? ( 'plugin' === $d->packageType ? $d->slug . '/' . $d->slug . '.php' : $d->slug );
		$state      = $this->installedState( $d->packageType, $identifier );
		if ( null === $state['version'] ) {
			throw new RuntimeException( 'WordPress did not report an installed package version.' );
		}
		return array(
			'identifier' => $identifier,
			'version'    => $state['version'],
			'active'     => $state['active'],
		);
	}
	private function installedState( string $type, string $identifier ): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		if ( 'plugin' === $type ) {
			$absolute = WP_PLUGIN_DIR . '/' . $identifier;
			$data     = is_file( $absolute ) ? get_plugin_data( $absolute, false, false ) : array();
			return array(
				'version' => isset( $data['Version'] ) && is_string( $data['Version'] ) ? $data['Version'] : null,
				'active'  => is_plugin_active( $identifier ),
			);
		}
		$theme = wp_get_theme( $identifier );
		return array(
			'version' => $theme->exists() ? $theme->get( 'Version' ) : null,
			'active'  => get_stylesheet() === $identifier || get_template() === $identifier,
		);
	}
}
