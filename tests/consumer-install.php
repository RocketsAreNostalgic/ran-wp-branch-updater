<?php

declare(strict_types=1);

$consumer = getenv( 'BRANCH_UPDATER_CONSUMER_ROOT' );
if ( ! is_string( $consumer ) || '' === $consumer ) {
	throw new RuntimeException( 'BRANCH_UPDATER_CONSUMER_ROOT is required.' );
}

$autoload = $consumer . '/vendor/autoload.php';
$bootstrap = $consumer . '/vendor/ran/wp-branch-updater/bootstrap.php';
if ( ! is_file( $autoload ) || ! is_file( $bootstrap ) ) {
	throw new RuntimeException( 'Consumer installation is incomplete.' );
}

require $autoload;

if ( ! class_exists( RAN\BranchDeployment\BranchDeploymentPackage::class ) ) {
	throw new RuntimeException( 'Composer did not load the branch updater source.' );
}
if ( ! class_exists( RAN\UpdaterSupport\V1\ArchiveSafety::class ) ) {
	throw new RuntimeException( 'Composer did not load updater support.' );
}

$configure = require $bootstrap;
if ( ! $configure instanceof Closure ) {
	throw new RuntimeException( 'Installed bootstrap did not return its configuration closure.' );
}

echo "PASS installed Composer consumer bootstrap\n";
