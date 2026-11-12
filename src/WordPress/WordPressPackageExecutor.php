<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\WordPress;

use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Contract\PackageExecutor;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionFailure;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RAN\WPBranchUpdater\V1\Runtime\FailureReason;
use RAN\WPBranchUpdater\V1\Runtime\ReasonedFailure;
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
	/**
	 * @return array{}
	 */
	public function preflight( BranchDeploymentDeclaration $d, PreparedArchive $archive ): array {
		if ( is_multisite() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A closed reason id is recorded outcome data, not rendered output.
			throw new ReasonedFailure( 'Branch deployment supports single-site WordPress only.', FailureReason::PREFLIGHT_MULTISITE->value );
		}
		if ( ! wp_is_file_mod_allowed( 'ran-branch-deployment' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A closed reason id is recorded outcome data, not rendered output.
			throw new ReasonedFailure( 'WordPress file modifications are disabled.', FailureReason::PREFLIGHT_FILE_MODS_DISABLED->value );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A closed reason id is recorded outcome data, not rendered output.
			throw new ReasonedFailure( 'The WordPress direct filesystem method is required.', FailureReason::PREFLIGHT_FILESYSTEM_NOT_DIRECT->value );
		}
		if ( file_exists( ABSPATH . '.maintenance' ) || is_link( ABSPATH . '.maintenance' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A closed reason id is recorded outcome data, not rendered output.
			throw new ReasonedFailure( 'WordPress maintenance mode is active.', FailureReason::PREFLIGHT_MAINTENANCE_ACTIVE->value );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		$archive->assert_unchanged();
		return array();
	}
	/**
	 * @return array{identifier:string,version:string,active:bool}
	 */
	public function installed_facts( BranchDeploymentDeclaration $d ): array {
		$identifier = $d->expected_identifier ?? $d->installed_identifier ?? ( 'plugin' === $d->package_type ? $d->slug . '/' . $d->slug . '.php' : $d->slug );
		$state      = $this->installed_state( $d->package_type, $identifier );
		if ( ! is_string( $state['version'] ) ) {
			throw new RuntimeException( 'WordPress did not report an installed package version.' );
		}
		return array(
			'identifier' => $identifier,
			'version'    => $state['version'],
			'active'     => $state['active'],
		);
	}
	/**
	 * @return array{version:string|false|null,active:bool}
	 */
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
