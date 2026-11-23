<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Tests;

use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RAN\WPBranchUpdater\V1\WordPress\WordPressCorePackageExecutor;
use RuntimeException;

/** Minimal host-side observations and dispatch for the installed proof. */
final class InstalledProofExecutor {
	public function target_directory( BranchDeploymentDeclaration $d ): string {
		return ( 'plugin' === $d->package_type ? WP_PLUGIN_DIR : get_theme_root( $d->installed_identifier ?? $d->slug ) ) . '/' . $d->slug;
	}
	public function execute_core( BranchDeploymentDeclaration $d, PreparedArchive $archive ): CorePackageExecutionResult {
		$core = new WordPressCorePackageExecutor();
		return match ( array( $d->operation, $d->package_type ) ) {
			array( 'install', 'plugin' ) => $core->install_plugin( $archive, $d->slug, $d->subdirectory ),
			array( 'install', 'theme' ) => $core->install_theme( $archive, $d->slug, $d->subdirectory ),
			array( 'update', 'plugin' ) => $core->update_plugin( $archive, $d->slug, $d->subdirectory, $d->installed_identifier ?? $d->slug . '/' . $d->slug . '.php' ),
			array( 'update', 'theme' ) => $core->update_theme( $archive, $d->slug, $d->subdirectory, $d->installed_identifier ?? $d->slug ),
			default => throw new RuntimeException( 'Unsupported fixture operation.' ),
		};
	}
	/** @return array{identifier:string,version:string,active:bool} */
	public function installed_facts( BranchDeploymentDeclaration $d ): array {
		$identifier = $d->expected_identifier ?? $d->installed_identifier ?? ( 'plugin' === $d->package_type ? $d->slug . '/' . $d->slug . '.php' : $d->slug );
		wp_cache_delete_multiple( array( 'alloptions', 'notoptions', 'active_plugins', 'stylesheet', 'template' ), 'options' );
		if ( 'plugin' === $d->package_type ) {
			$data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $identifier, false, false );
			$version = $data['Version'];
			$active  = is_plugin_active( $identifier );
		} else {
			$theme = wp_get_theme( $identifier );
			$theme->cache_delete();
			$version = $theme->get( 'Version' );
			$active  = get_stylesheet() === $identifier || get_template() === $identifier;
		}
		if ( ! is_string( $version ) || '' === $version ) {
			throw new RuntimeException( 'Missing fixture version.' ); }
		return array(
			'identifier' => $identifier,
			'version'    => $version,
			'active'     => $active,
		);
	}
}
