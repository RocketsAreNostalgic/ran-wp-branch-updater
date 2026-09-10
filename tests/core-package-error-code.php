<?php

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private readonly string $code ) {}
		public function get_error_code(): string {
			return $this->code;
		}
	}
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\WordPress\CorePackageExecutor;

$method = new ReflectionMethod( CorePackageExecutor::class, 'invalidPackageSource' );
$error  = $method->invoke( null );
if ( ! $error instanceof WP_Error || 'ran_branch_deployment_invalid_package_source' !== $error->get_error_code() ) {
	throw new RuntimeException( 'Package source failures use the wrong error code.' );
}

echo "PASS branch deployment package source error code\n";
