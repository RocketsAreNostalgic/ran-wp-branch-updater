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

if ( ! class_exists( RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentPackage::class ) ) {
	throw new RuntimeException( 'Composer did not load the branch updater source.' );
}
if ( ! class_exists( RAN\UpdaterSupport\V1\ArchiveSafety::class ) ) {
	throw new RuntimeException( 'Composer did not load updater support.' );
}
if (
	class_exists( RAN\WPBranchUpdater\V1\GitHubFixtureProvider::class )
	|| class_exists( RAN\WPBranchUpdater\V1\BitbucketFixtureProvider::class )
	|| class_exists( RAN\WPBranchUpdater\V1\RecordingExecutor::class )
) {
	throw new RuntimeException( 'Consumer installation loaded test-only fixture classes.' );
}

foreach ( array( RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentPackage::class, RAN\UpdaterSupport\V1\ArchiveSafety::class ) as $class ) {
	$file = ( new ReflectionClass( $class ) )->getFileName();
	if ( ! is_string( $file ) || ! str_starts_with( $file, $consumer . '/vendor/' ) || is_link( $file ) ) {
		throw new RuntimeException( 'Composer did not load an installed regular package file.' );
	}
}
if ( str_contains( (string) file_get_contents( $bootstrap ), "vendor/autoload" ) ) {
	throw new RuntimeException( 'Installed bootstrap loads a package-private autoloader.' );
}

$configure = require $bootstrap;
if ( ! $configure instanceof Closure ) {
	throw new RuntimeException( 'Installed bootstrap did not return its configuration closure.' );
}

$provider = new class implements RAN\WPBranchUpdater\V1\Contract\BranchProvider {
	public function prepare( RAN\WPBranchUpdater\V1\Runtime\Deployment $deployment ): RAN\WPBranchUpdater\V1\Archive\ArchiveOffer {
		throw new RuntimeException( 'The consumer smoke must not prepare an archive.' );
	}
};
$private = $consumer . '/private';
if ( ! mkdir( $private, 0700 ) ) {
	throw new RuntimeException( 'Consumer private state directory could not be created.' );
}
$state = $private . '/attempts.json';
$branches = $configure( $provider, new RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore( $state ), $consumer . '/private/archives' );
if ( ! $branches instanceof RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentPackage ) {
	throw new RuntimeException( 'Installed bootstrap did not configure the branch package.' );
}
$packageFacts = new ReflectionObject( $branches );
$runner = $packageFacts->getProperty( 'runner' )->getValue( $branches );
if ( ! $runner instanceof RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentOperation ) {
	throw new RuntimeException( 'Installed bootstrap did not configure the standalone runner.' );
}
$runnerFacts = new ReflectionObject( $runner );
if (
	! $runnerFacts->getProperty( 'executor' )->getValue( $runner ) instanceof RAN\WPBranchUpdater\V1\WordPress\WordPressPackageExecutor
	|| ! $runnerFacts->getProperty( 'lock' )->getValue( $runner ) instanceof RAN\WPBranchUpdater\V1\WordPress\WordPressUpdaterLock
) {
	throw new RuntimeException( 'Installed bootstrap did not retain its default executor and lock.' );
}

echo "PASS installed Composer consumer bootstrap\n";
