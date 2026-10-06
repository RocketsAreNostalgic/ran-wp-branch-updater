<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.

declare(strict_types=1);

$consumer = getenv( 'BRANCH_UPDATER_CONSUMER_ROOT' );
if ( ! is_string( $consumer ) || '' === $consumer ) {
	throw new RuntimeException( 'BRANCH_UPDATER_CONSUMER_ROOT is required.' );
}

$autoload  = $consumer . '/vendor/autoload.php';
$bootstrap = $consumer . '/vendor/ran/wp-branch-updater/bootstrap.php';
if ( ! is_file( $autoload ) || ! is_file( $bootstrap ) ) {
	throw new RuntimeException( 'Consumer installation is incomplete.' );
}

require $autoload;

if ( ! class_exists( RAN\WPBranchUpdater\V1\Runtime\BranchUpdater::class ) ) {
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

foreach ( array( RAN\WPBranchUpdater\V1\Runtime\BranchUpdater::class, RAN\UpdaterSupport\V1\ArchiveSafety::class ) as $class ) {
	$file = ( new ReflectionClass( $class ) )->getFileName();
	if ( ! is_string( $file ) || ! str_starts_with( $file, $consumer . '/vendor/' ) || is_link( $file ) ) {
		throw new RuntimeException( 'Composer did not load an installed regular package file.' );
	}
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the local installed fixture or checked source bytes; no WordPress runtime is loaded.
if ( str_contains( (string) file_get_contents( $bootstrap ), 'vendor/autoload' ) ) {
	throw new RuntimeException( 'Installed bootstrap loads a package-private autoloader.' );
}

$configure = require $bootstrap;
if ( ! $configure instanceof Closure ) {
	throw new RuntimeException( 'Installed bootstrap did not return its configuration closure.' );
}

$provider = new class() implements RAN\WPBranchUpdater\V1\Contract\BranchProvider {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The non-acquiring consumer stub retains the BranchProvider prepare signature.
	public function prepare( RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration $deployment ): RAN\WPBranchUpdater\V1\Archive\ArchiveOffer {
		throw new RuntimeException( 'The consumer smoke must not prepare an archive.' );
	}
};
$private  = $consumer . '/private';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
if ( ! mkdir( $private, 0700 ) ) {
	throw new RuntimeException( 'Consumer private state directory could not be created.' );
}
$state    = $private . '/attempts.json';
$branches = $configure( $provider, new RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore( $state ), $consumer . '/private/archives' );
if ( ! $branches instanceof RAN\WPBranchUpdater\V1\Runtime\BranchUpdater ) {
	throw new RuntimeException( 'Installed bootstrap did not configure the branch package.' );
}
$package_facts = new ReflectionObject( $branches );
$runner        = $package_facts->getProperty( 'runner' )->getValue( $branches );
if ( ! $runner instanceof RAN\WPBranchUpdater\V1\Runtime\StandaloneBranchRunner ) {
	throw new RuntimeException( 'Installed bootstrap did not configure the standalone runner.' );
}
$runner_facts = new ReflectionObject( $runner );
if (
	! $runner_facts->getProperty( 'executor' )->getValue( $runner ) instanceof RAN\WPBranchUpdater\V1\WordPress\WordPressPackageExecutor
	|| ! $runner_facts->getProperty( 'lock' )->getValue( $runner ) instanceof RAN\WPBranchUpdater\V1\WordPress\WordPressUpdaterLock
) {
	throw new RuntimeException( 'Installed bootstrap did not retain its default executor and lock.' );
}

$named        = $configure(
	provider: $provider,
	attempts: new RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore( $state ),
	archive_directory: $private . '/named-archives',
	maximum_artifact_bytes: 134217728
);
$named_runner = ( new ReflectionObject( $named ) )->getProperty( 'runner' )->getValue( $named );
if ( ! $named_runner instanceof RAN\WPBranchUpdater\V1\Runtime\StandaloneBranchRunner ) {
	throw new RuntimeException( 'Named configuration did not create a standalone runner.' );
}
$named_facts = new ReflectionObject( $named_runner );
if (
	$private . '/named-archives' !== $named_facts->getProperty( 'archive_directory' )->getValue( $named_runner )
	|| 134217728 !== $named_facts->getProperty( 'maximum_artifact_bytes' )->getValue( $named_runner )
) {
	throw new RuntimeException( 'Installed bootstrap did not forward its named arguments.' );
}

echo "PASS installed Composer consumer bootstrap (positional defaults and named arguments)\n";
