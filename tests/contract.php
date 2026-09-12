<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput,Generic.CodeAnalysis.EmptyStatement -- Standalone CLI fixture creates and mutates its isolated temporary corpus.
declare(strict_types=1);
require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\BitbucketFixtureProvider;
use RAN\WPBranchUpdater\V1\Contract\BranchProvider;
use RAN\WPBranchUpdater\V1\GitHubFixtureProvider;
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore;
use RAN\WPBranchUpdater\V1\Persistence\FileMutationLock;
use RAN\WPBranchUpdater\V1\RecordingExecutor;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchStageFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\ProviderArchiveSource;

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

$providerFactory = static function ( string $archive ): BranchProvider {
	return new class( $archive ) implements BranchProvider {
		public int $acquisitions = 0;
		/** @var list<int> */
		public array $limits = array();

		public function __construct( private readonly string $archive ) {}

		public function prepare( BranchDeploymentDeclaration $deployment ): ArchiveOffer {
			return new ArchiveOffer(
				'limit-fixture',
				$deployment->repositoryId,
				$deployment->expectedHead ?? 'abc123',
				function ( string $destination, int $maximumArtifactBytes ): void {
					++$this->acquisitions;
					$this->limits[] = $maximumArtifactBytes;
					$size           = filesize( $this->archive );
					if ( false === $size || $size < 1 || $size > $maximumArtifactBytes ) {
						throw new \RuntimeException( 'Provider acquisition limit reached.' );
					}
					if ( ! copy( $this->archive, $destination ) ) {
						throw new \RuntimeException( 'Cannot copy artifact-limit fixture.' );
					}
				},
				static function (): void {}
			);
		}
	};
};

$fixtureBytes = filesize( $zip );
$assert( is_int( $fixtureBytes ) && $fixtureBytes > 1, 'artifact-limit fixture has measurable bytes' );
$boundedProvider = $providerFactory( $zip );
$boundedSource   = new ProviderArchiveSource( $boundedProvider, $root . '/archives', $fixtureBytes );
$boundedArtifact = $boundedSource->prepare( $deploy( 'configured-limit', 'abc123' ), null );
$assert( 1 === $boundedProvider->acquisitions, 'configured source acquires once' );
$assert( array( $fixtureBytes ) === $boundedProvider->limits, 'configured source forwards the exact provider acquisition ceiling' );
$boundedArtifact->cleanup();

$smallProvider = $providerFactory( $zip );
$smallLimit    = $fixtureBytes - 1;
try {
	( new ProviderArchiveSource( $smallProvider, $root . '/archives', $smallLimit ) )->prepare( $deploy( 'small-limit', 'abc123' ), null );
	$assert( false, 'provider acquisition must reject an artifact beyond the configured ceiling' );
} catch ( AdmittedBranchStageFailure $expected ) {
	$assert( 'archive_integrity_failed' === $expected->outcomeCode, 'provider acquisition limit maps to archive integrity failure' );
}
$assert( 1 === $smallProvider->acquisitions, 'bounded provider is invoked once for an over-limit artifact' );
$assert( array( $smallLimit ) === $smallProvider->limits, 'over-limit provider sees the configured ceiling before writing' );
$assert( ! glob( $root . '/archives/*' ), 'over-limit provider acquisition leaves no archive behind' );

foreach ( array( 0, '536870912', intdiv( PHP_INT_MAX, 4 ) + 1 ) as $invalidLimit ) {
	$invalidProvider = $providerFactory( $zip );
	try {
		( new ProviderArchiveSource( $invalidProvider, $root . '/archives', $invalidLimit ) )->prepare( $deploy( 'invalid-limit', 'abc123' ), null );
		$assert( false, 'invalid artifact limit must fail' );
	} catch ( AdmittedBranchStageFailure $expected ) {
		$assert( 'archive_integrity_failed' === $expected->outcomeCode, 'invalid artifact limit uses the closed source failure' );
	}
	$assert( 0 === $invalidProvider->acquisitions, 'invalid artifact limit fails before provider acquisition' );
}

$expandedZip     = $root . '/expanded-fixture.zip';
$expandedContent = "<?php\n/*\nPlugin Name: Demo\nVersion: 1.2.3\n*/\n" . str_repeat( 'A', 1048576 );
$z               = new ZipArchive();
$z->open( $expandedZip, ZipArchive::CREATE );
$z->addFromString( 'repository/demo/demo.php', $expandedContent );
$z->close();
$expandedLimit = filesize( $expandedZip );
$assert( is_int( $expandedLimit ) && $expandedLimit > 0, 'expanded-limit fixture has measurable compressed bytes' );
$assert( strlen( $expandedContent ) > $expandedLimit * 4, 'expanded-limit fixture crosses the configured 4x boundary' );
$expandedProvider = $providerFactory( $expandedZip );
try {
	( new ProviderArchiveSource( $expandedProvider, $root . '/archives', $expandedLimit ) )->prepare( $deploy( 'expanded-limit', 'abc123' ), null );
	$assert( false, 'configured 4x expanded archive ceiling must reject the fixture' );
} catch ( AdmittedBranchStageFailure $expected ) {
	$assert( 'archive_integrity_failed' === $expected->outcomeCode, 'expanded archive limit maps to archive integrity failure' );
}
$assert( 1 === $expandedProvider->acquisitions, 'expanded-limit fixture is acquired once before ZIP inspection' );
$assert( array( $expandedLimit ) === $expandedProvider->limits, 'expanded-limit path retains the configured compressed ceiling' );
$assert( ! glob( $root . '/archives/*' ), 'expanded-limit rejection cleans the acquired archive' );

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

echo "PASS branch package harness: github + bitbucket fixture contracts, ZIP custody, bounded artifact limits, stale rejection, cleanup, durable recovery\n";
