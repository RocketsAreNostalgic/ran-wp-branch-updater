<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/Support/WordPressErrorStub.php';

use RAN\WPBranchUpdater\V1\WordPress\WordPressCorePackageExecutor;

$method = new ReflectionMethod( WordPressCorePackageExecutor::class, 'invalid_package_source' );
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone CLI fixture variable does not share WordPress runtime globals.
$error = $method->invoke( null );
if ( ! $error instanceof WP_Error || 'ran_branch_deployment_invalid_package_source' !== $error->get_error_code() ) {
	throw new RuntimeException( 'Package source failures use the wrong error code.' );
}
$source_error = $method->invoke( null, 'destination_exists' );
if ( ! $source_error instanceof WP_Error || 'ran_branch_deployment_invalid_package_source' !== $source_error->get_error_code() || 'destination_exists' !== $source_error->get_error_data() ) {
	throw new RuntimeException( 'Package source failures must carry their closed cause as WP_Error data.' );
}

echo "PASS branch deployment package source error code\n";
