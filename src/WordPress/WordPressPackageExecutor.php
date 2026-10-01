<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\WordPress;

use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Contract\PackageExecutor;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionFailure;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RuntimeException;

/** Thin real-WordPress executor: WP owns installation; this package supplies one local ZIP. */
final class WordPressPackageExecutor implements PackageExecutor {
	public function __construct( private readonly WordPressCorePackageExecutor $core = new WordPressCorePackageExecutor() ) {}
	public function execute( BranchDeploymentDeclaration $d, PreparedArchive $archive ): void {
		$result = $this->execute_core( $d, $archive );
		if ( ! $result->is_successful() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal enum failure diagnostics are not rendered output.
			throw new RuntimeException( 'WordPress execution failed: ' . $result->get_failure()->value );
		}
	}
	public function execute_core( BranchDeploymentDeclaration $d, PreparedArchive $archive ): CorePackageExecutionResult {
		if ( ! defined( 'ABSPATH' ) ) {
			throw new RuntimeException( 'WordPress upgrader is unavailable.' );
		}
		return match ( array( $d->operation, $d->package_type ) ) {
			array( 'install', 'plugin' ) => $this->core->install_plugin( $archive, $d->slug, $d->subdirectory ),
			array( 'install', 'theme' ) => $this->core->install_theme( $archive, $d->slug, $d->subdirectory ),
			array( 'update', 'plugin' ) => $this->core->update_plugin( $archive, $d->slug, $d->subdirectory, $d->installed_identifier ?? $d->slug . '/' . $d->slug . '.php' ),
			array( 'update', 'theme' ) => $this->core->update_theme( $archive, $d->slug, $d->subdirectory, $d->installed_identifier ?? $d->slug ),
			default => throw new RuntimeException( 'Unsupported package operation.' ),
		};
	}
	public function preflight( BranchDeploymentDeclaration $d, PreparedArchive $archive ): array {
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
		$identifier = $d->installed_identifier ?? ( 'plugin' === $d->package_type ? $d->slug . '/' . $d->slug . '.php' : $d->slug );
		$before     = $this->installed_state( $d->package_type, $identifier );
		$archive->assert_unchanged();
		return $before;
	}
	public function installed_facts( BranchDeploymentDeclaration $d ): array {
		$identifier = $d->installed_identifier ?? ( 'plugin' === $d->package_type ? $d->slug . '/' . $d->slug . '.php' : $d->slug );
		$state      = $this->installed_state( $d->package_type, $identifier );
		if ( null === $state['version'] ) {
			throw new RuntimeException( 'WordPress did not report an installed package version.' );
		}
		return array(
			'identifier' => $identifier,
			'version'    => $state['version'],
			'active'     => $state['active'],
		);
	}
	private function installed_state( string $type, string $identifier ): array {
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
