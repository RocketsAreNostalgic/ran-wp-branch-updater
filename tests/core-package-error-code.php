<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This fixture exercises the exact foreign WordPress/Core contract name.
	class WP_Error {
		public function __construct( private readonly string $code ) {}
		public function get_error_code(): string {
			return $this->code;
		}
	}
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\WordPress\WordPressCorePackageExecutor;

$method = new ReflectionMethod( WordPressCorePackageExecutor::class, 'invalid_package_source' );
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone CLI fixture variable does not share WordPress runtime globals.
$error = $method->invoke( null );
if ( ! $error instanceof WP_Error || 'ran_branch_deployment_invalid_package_source' !== $error->get_error_code() ) {
	throw new RuntimeException( 'Package source failures use the wrong error code.' );
}

echo "PASS branch deployment package source error code\n";
