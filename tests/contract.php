<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput,Generic.CodeAnalysis.EmptyStatement -- Standalone CLI fixture creates and mutates its isolated temporary corpus.
declare(strict_types=1);
require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\BitbucketFixtureProvider;
use RAN\WPBranchUpdater\V1\GitHubFixtureProvider;
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore;
use RAN\WPBranchUpdater\V1\Persistence\FileMutationLock;
use RAN\WPBranchUpdater\V1\RecordingExecutor;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;

$buildRoot = __DIR__ . '/build/harness';
if ( ! is_dir( $buildRoot ) && ! mkdir( $buildRoot, 0700, true ) ) {
	throw new \RuntimeException( 'Cannot create durable harness build directory.' );
}
$root = $buildRoot . '/run-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0700, true );
mkdir( $root . '/archives', 0700 );
$zip = $root . '/fixture.zip';
$z   = new ZipArchive();
$z->open( $zip, ZipArchive::CREATE );
$z->addFromString( 'repository/demo/demo.php', "<?php\n/*\nPlugin Name: Demo\nVersion: 1.2.3\n*/\n" );
$z->close();
$assert = static function ( bool $value, string $message ): void {
	if ( ! $value ) {
		throw new \RuntimeException( 'FAIL: ' . $message );
	}
};
$deploy    = static fn( string $id, string $head ): BranchDeploymentDeclaration => new BranchDeploymentDeclaration( $id, 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', $head, 'update', 'demo', 'demo/demo.php' );
$store     = new FileAttemptStore( $root . '/attempts.json' );
$executor  = new RecordingExecutor();
$lock      = new FileMutationLock( $root . '/mutation.lock' );
$bootstrap = require dirname( __DIR__ ) . '/bootstrap.php';
$package   = $bootstrap( new GitHubFixtureProvider( $zip, 'abc123' ), $store, $root . '/archives', $executor, $lock );
$result    = $package->plugin( repository: 'acme/demo', repositoryId: 'fixture-1', branch: 'main', pluginFile: 'demo/demo.php', subdirectory: 'demo' )->deploy( expectedCommit: 'abc123' );
$assert( $result === 'deployed', 'github fixture executes' );
$assert( count( $executor->calls ) === 1, 'executor received real local ZIP' );
$assert( ! glob( $root . '/archives/*' ), 'archive is cleaned' );

$bitbucketStore    = new FileAttemptStore( $root . '/bitbucket-attempts.json' );
$bitbucketExecutor = new RecordingExecutor();
$bitbucketResult   = $bootstrap( new BitbucketFixtureProvider( $zip, 'abc123' ), $bitbucketStore, $root . '/archives', $bitbucketExecutor, $lock )->plugin( repository: 'acme/demo', repositoryId: 'fixture-1', branch: 'main', pluginFile: 'demo/demo.php', subdirectory: 'demo' )->deploy( expectedCommit: 'abc123' );
$assert( $bitbucketResult === 'deployed', 'bitbucket fixture executes' );
$assert( count( $bitbucketExecutor->calls ) === 1, 'bitbucket executor received real local ZIP' );
$assert( ! glob( $root . '/archives/*' ), 'bitbucket archive is cleaned' );

$offer        = ( new GitHubFixtureProvider( $zip, 'abc123' ) )->prepare( $deploy( 'tamper', 'abc123' ) );
$artifact     = PreparedArchive::downloadAndValidate( $offer, $deploy( 'tamper', 'abc123' ), $root . '/archives' );
$tamperedPath = $artifact->path();
file_put_contents( $tamperedPath, 'changed' );
try {
	$artifact->assertUnchanged();
	$assert( false, 'tampered archive must fail custody assertion' );
} catch ( \RuntimeException $expected ) {
	$assert( $expected->getMessage() === 'Prepared archive changed before use.', 'tamper is rejected by custody assertion' );
}
unlink( $tamperedPath );

$limitAcquisitions = 0;
$limitOffer = static function () use ( $zip, &$limitAcquisitions ): ArchiveOffer {
	return new ArchiveOffer(
		'fixture',
		'fixture-1',
		'abc123',
		static function ( string $destination ) use ( $zip, &$limitAcquisitions ): void {
			++$limitAcquisitions;
			if ( ! copy( $zip, $destination ) ) {
				throw new \RuntimeException( 'Cannot copy artifact-limit fixture.' );
			}
		},
		static function (): void {}
	);
};
$configuredArtifact = PreparedArchive::downloadAndValidate(
	$limitOffer(),
	$deploy( 'configured-limit', 'abc123' ),
	$root . '/archives',
	536870912
);
$configuredArtifact->cleanup();
$assert( 1 === $limitAcquisitions, 'configured 512 MiB artifact limit acquires once' );
foreach ( array( 0, '536870912', intdiv( PHP_INT_MAX, 4 ) + 1 ) as $invalidLimit ) {
	try {
		PreparedArchive::downloadAndValidate(
			$limitOffer(),
			$deploy( 'invalid-limit', 'abc123' ),
			$root . '/archives',
			$invalidLimit
		);
		$assert( false, 'invalid artifact limit must fail' );
	} catch ( \RuntimeException $expected ) {
		$assert( str_contains( $expected->getMessage(), 'Maximum artifact bytes' ), 'invalid artifact limit uses the closed configuration failure' );
	}
}
$assert( 1 === $limitAcquisitions, 'invalid artifact limits fail before acquisition' );

$stale = $bootstrap( new BitbucketFixtureProvider( $zip, 'new' ), $store, $root . '/archives', new RecordingExecutor(), $lock )->plugin( repository: 'acme/demo', repositoryId: 'fixture-1', branch: 'main', pluginFile: 'demo/demo.php', subdirectory: 'demo' );
$assert( $stale->deploy( expectedCommit: 'old' ) === 'provider_failed', 'stale head is a closed pre-fence outcome' );
$assert( $store->get( $stale->attemptId() )['state'] === 'failed', 'stale head rejects pre-fence' );

$wrongRepository = $bootstrap( new GitHubFixtureProvider( $zip, 'abc123', 'wrong-id' ), $store, $root . '/archives', new RecordingExecutor(), $lock )->plugin( repository: 'acme/demo', repositoryId: 'fixture-1', branch: 'main', pluginFile: 'demo/demo.php', subdirectory: 'demo' );
$assert( $wrongRepository->deploy( expectedCommit: 'abc123' ) === 'provider_failed', 'provider identity is a closed pre-fence outcome' );
$assert( $store->get( $wrongRepository->attemptId() )['state'] === 'failed', 'provider repository identity rejects pre-fence' );

$failing = $bootstrap( new GitHubFixtureProvider( $zip, 'abc123' ), $store, $root . '/archives', new RecordingExecutor( true ), $lock )->plugin( repository: 'acme/demo', repositoryId: 'fixture-1', branch: 'main', pluginFile: 'demo/demo.php', subdirectory: 'demo' );
$assert( $failing->deploy( expectedCommit: 'abc123' ) === 'restoration_uncertain', 'post-fence failure is a closed outcome' );
$assert( $store->get( $failing->attemptId() )['state'] === 'needs_attention', 'post-fence failure is durable attention' );
$assert( ! glob( $root . '/archives/*' ), 'failure cleans exact archive' );

$recoveryStore = new FileAttemptStore( $root . '/recovery-attempts.json' );
$recoveryStore->begin( $deploy( 'stopped-before-fence', 'abc123' ) );
$recoveryStore->recoverStopped( 'stopped-before-fence' );
$assert( $recoveryStore->get( 'stopped-before-fence' )['outcome'] === 'worker_stopped', 'pre-fence recovery fails safely' );
$recoveryStore->begin( $deploy( 'stopped-after-fence', 'abc123' ) );
$recoveryStore->resolved( 'stopped-after-fence', 'abc123' );
$recoveryStore->fence( 'stopped-after-fence' );
$recoveryStore->recoverStopped( 'stopped-after-fence' );
$assert( $recoveryStore->get( 'stopped-after-fence' )['state'] === 'needs_attention', 'fenced recovery does not retry' );

echo "PASS branch package harness: github + bitbucket fixture contracts, ZIP custody, artifact limits, stale rejection, cleanup, durable recovery\n";
