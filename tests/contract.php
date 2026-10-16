<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.
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

$build_root = __DIR__ . '/build/harness';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
if ( ! is_dir( $build_root ) && ! mkdir( $build_root, 0700, true ) ) {
	throw new \RuntimeException( 'Cannot create durable harness build directory.' );
}
$root = $build_root . '/run-' . bin2hex( random_bytes( 4 ) );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
mkdir( $root, 0700, true );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
mkdir( $root . '/archives', 0700 );
$zip = $root . '/fixture.zip';
$z   = new ZipArchive();
$z->open( $zip, ZipArchive::CREATE );
$z->addFromString( 'repository/demo/demo.php', "<?php\n/*\nPlugin Name: Demo\nVersion: 1.2.3\n*/\n" );
$z->close();
$assert    = static function ( bool $value, string $message ): void {
	if ( ! $value ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion diagnostics are not HTML; preserve the actual failing fixture detail.
		throw new \RuntimeException( 'FAIL: ' . $message );
	}
};
$deploy    = static fn( string $id, string $head ): BranchDeploymentDeclaration => new BranchDeploymentDeclaration( $id, 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', $head, 'update', 'demo', 'demo/demo.php' );
$store     = new FileAttemptStore( $root . '/attempts.json' );
$executor  = new RecordingExecutor();
$lock      = new FileMutationLock( $root . '/mutation.lock' );
$bootstrap = require dirname( __DIR__ ) . '/bootstrap.php';
$package   = $bootstrap( new GitHubFixtureProvider( $zip, 'abc123' ), $store, $root . '/archives', $executor, $lock );
$result    = $package->plugin( repository: 'acme/demo', repository_id: 'fixture-1', branch: 'main', plugin_file: 'demo/demo.php', subdirectory: 'demo' )->deploy( expected_commit: 'abc123' );
$assert( 'deployed' === $result, 'github fixture executes' );
$assert( count( $executor->calls ) === 1, 'executor received real local ZIP' );
$assert( ! glob( $root . '/archives/*' ), 'archive is cleaned' );

// Initial installs bind the exact main file before executor entry, even with identical version/ref/slug.
foreach ( array(
	'demo/demo.php'  => 'deployed',
	'demo/other.php' => 'archive_integrity_failed',
) as $identifier => $outcome ) {
	$identity_store    = new FileAttemptStore( $root . '/identity-' . basename( $identifier ) . '.json' );
	$identity_executor = new RecordingExecutor();
	$identity          = $bootstrap( new GitHubFixtureProvider( $zip, 'abc123' ), $identity_store, $root . '/archives', $identity_executor, $lock )->plugin( repository: 'acme/demo', repository_id: 'fixture-1', branch: 'main', plugin_file: 'demo/demo.php', subdirectory: 'demo' );
	$assert( $outcome === $identity->deploy( expected_commit: 'abc123', operation: 'install', expected_identifier: $identifier ), 'initial install enforces exact target main file' );
	$assert( ( 'deployed' === $outcome ? 1 : 0 ) === count( $identity_executor->calls ), 'mismatched main file never enters mutation' );
	$assert( $identifier === $identity_store->get( $identity->attempt_id() )['expected_identifier'], 'exact target survives public declaration copies and journal' );
	$assert( ! glob( $root . '/archives/*' ), 'identity validation preserves archive cleanup' );
}
foreach ( array( '', 'demo/../demo.php', 'demo/main.php ', '/demo/main.php', 'demo/main.PHP', 'demo/' . str_repeat( 'a', 183 ) . '.php', 'other/main.php', 'demo/nested/main.php', 'demo\\main.php' ) as $identifier ) {
	try {
		new BranchDeploymentDeclaration( 'invalid-target', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', null, operation: 'install', expected_identifier: $identifier );
		$assert( false, 'malformed exact target must refuse' );
	} catch ( RuntimeException $expected ) {
		$assert( 'Invalid expected installation identifier.' === $expected->getMessage(), 'malformed target rejected during declaration' );
	}
}
try {
	new BranchDeploymentDeclaration( 'update-target', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', null, expected_identifier: 'demo/main.php' );
	$assert( false, 'update target field must refuse' );
} catch ( RuntimeException $expected ) {
	$assert( 'Invalid expected installation identifier.' === $expected->getMessage(), 'target field is initial-install only' );
}

$bitbucket_store    = new FileAttemptStore( $root . '/bitbucket-attempts.json' );
$bitbucket_executor = new RecordingExecutor();
$bitbucket_result   = $bootstrap( new BitbucketFixtureProvider( $zip, 'abc123' ), $bitbucket_store, $root . '/archives', $bitbucket_executor, $lock )->plugin( repository: 'acme/demo', repository_id: 'fixture-1', branch: 'main', plugin_file: 'demo/demo.php', subdirectory: 'demo' )->deploy( expected_commit: 'abc123' );
$assert( 'deployed' === $bitbucket_result, 'bitbucket fixture executes' );
$assert( count( $bitbucket_executor->calls ) === 1, 'bitbucket executor received real local ZIP' );
$assert( ! glob( $root . '/archives/*' ), 'bitbucket archive is cleaned' );

$fixture_bytes = filesize( $zip );
$assert( is_int( $fixture_bytes ) && $fixture_bytes > 1, 'artifact-limit fixture has measurable bytes' );
$standalone_limit_store = new FileAttemptStore( $root . '/standalone-limit-attempts.json' );
$standalone_limit       = $fixture_bytes - 1;
$standalone_result      = $bootstrap(
	provider: new GitHubFixtureProvider( $zip, 'abc123' ),
	attempts: $standalone_limit_store,
	archive_directory: $root . '/archives',
	executor: new RecordingExecutor(),
	lock: $lock,
	maximum_artifact_bytes: $standalone_limit
)->plugin(
	repository: 'acme/demo',
	repository_id: 'fixture-1',
	branch: 'main',
	plugin_file: 'demo/demo.php',
	subdirectory: 'demo'
)->deploy( expected_commit: 'abc123' );
$assert( 'archive_integrity_failed' === $standalone_result, 'documented standalone composition honors the configured artifact ceiling' );
$assert( ! glob( $root . '/archives/*' ), 'standalone over-limit acquisition leaves no archive behind' );

$offer         = ( new GitHubFixtureProvider( $zip, 'abc123' ) )->prepare( $deploy( 'tamper', 'abc123' ) );
$artifact      = PreparedArchive::download_and_validate( $offer, $deploy( 'tamper', 'abc123' ), $root . '/archives' );
$tampered_path = $artifact->path();
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
file_put_contents( $tampered_path, 'changed' );
try {
	$artifact->assert_unchanged();
	$assert( false, 'tampered archive must fail custody assertion' );
} catch ( \RuntimeException $expected ) {
	$assert( $expected->getMessage() === 'Prepared archive changed before use.', 'tamper is rejected by custody assertion' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
unlink( $tampered_path );

$provider_factory = static function ( string $archive ): BranchProvider {
	return new class( $archive ) implements BranchProvider {
		public int $acquisitions = 0;
		/** @var list<int> */
		public array $limits = array();

		public function __construct( private readonly string $archive ) {}

		public function supports_selected_commit(): bool {
			return false;
		}
		public function prepare( BranchDeploymentDeclaration $deployment ): ArchiveOffer {
			return new ArchiveOffer(
				'limit-fixture',
				$deployment->repository_id,
				$deployment->expected_head ?? 'abc123',
				function ( string $destination, int $maximum_artifact_bytes ): void {
					++$this->acquisitions;
					$this->limits[] = $maximum_artifact_bytes;
					$size           = filesize( $this->archive );
					if ( false === $size || $size < 1 || $size > $maximum_artifact_bytes ) {
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

$bounded_provider = $provider_factory( $zip );
$bounded_source   = new ProviderArchiveSource( $bounded_provider, $root . '/archives', $fixture_bytes );
$bounded_artifact = $bounded_source->prepare( $deploy( 'configured-limit', 'abc123' ), null );
$assert( 1 === $bounded_provider->acquisitions, 'configured source acquires once' );
$assert( array( $fixture_bytes ) === $bounded_provider->limits, 'configured source forwards the exact provider acquisition ceiling' );
$bounded_artifact->cleanup();

$small_provider = $provider_factory( $zip );
$small_limit    = $fixture_bytes - 1;
try {
	( new ProviderArchiveSource( $small_provider, $root . '/archives', $small_limit ) )->prepare( $deploy( 'small-limit', 'abc123' ), null );
	$assert( false, 'provider acquisition must reject an artifact beyond the configured ceiling' );
} catch ( AdmittedBranchStageFailure $expected ) {
	$assert( 'archive_integrity_failed' === $expected->outcome_code, 'provider acquisition limit maps to archive integrity failure' );
}
$assert( 1 === $small_provider->acquisitions, 'bounded provider is invoked once for an over-limit artifact' );
$assert( array( $small_limit ) === $small_provider->limits, 'over-limit provider sees the configured ceiling before writing' );
$assert( ! glob( $root . '/archives/*' ), 'over-limit provider acquisition leaves no archive behind' );

foreach ( array( 0, '536870912', intdiv( PHP_INT_MAX, 4 ) + 1 ) as $invalid_limit ) {
	$invalid_provider = $provider_factory( $zip );
	try {
		( new ProviderArchiveSource( $invalid_provider, $root . '/archives', $invalid_limit ) )->prepare( $deploy( 'invalid-limit', 'abc123' ), null );
		$assert( false, 'invalid artifact limit must fail' );
	} catch ( AdmittedBranchStageFailure $expected ) {
		$assert( 'archive_integrity_failed' === $expected->outcome_code, 'invalid artifact limit uses the closed source failure' );
	}
	$assert( 0 === $invalid_provider->acquisitions, 'invalid artifact limit fails before provider acquisition' );
}

$expanded_zip     = $root . '/expanded-fixture.zip';
$expanded_content = "<?php\n/*\nPlugin Name: Demo\nVersion: 1.2.3\n*/\n" . str_repeat( 'A', 1048576 );
$z                = new ZipArchive();
$z->open( $expanded_zip, ZipArchive::CREATE );
$z->addFromString( 'repository/demo/demo.php', $expanded_content );
$z->close();
$expanded_limit = filesize( $expanded_zip );
$assert( is_int( $expanded_limit ) && $expanded_limit > 0, 'expanded-limit fixture has measurable compressed bytes' );
$assert( strlen( $expanded_content ) > $expanded_limit * 4, 'expanded-limit fixture crosses the configured 4x boundary' );
$expanded_provider = $provider_factory( $expanded_zip );
try {
	( new ProviderArchiveSource( $expanded_provider, $root . '/archives', $expanded_limit ) )->prepare( $deploy( 'expanded-limit', 'abc123' ), null );
	$assert( false, 'configured 4x expanded archive ceiling must reject the fixture' );
} catch ( AdmittedBranchStageFailure $expected ) {
	$assert( 'archive_integrity_failed' === $expected->outcome_code, 'expanded archive limit maps to archive integrity failure' );
}
$assert( 1 === $expanded_provider->acquisitions, 'expanded-limit fixture is acquired once before ZIP inspection' );
$assert( array( $expanded_limit ) === $expanded_provider->limits, 'expanded-limit path retains the configured compressed ceiling' );
$assert( ! glob( $root . '/archives/*' ), 'expanded-limit rejection cleans the acquired archive' );

$stale = $bootstrap( new BitbucketFixtureProvider( $zip, 'new' ), $store, $root . '/archives', new RecordingExecutor(), $lock )->plugin( repository: 'acme/demo', repository_id: 'fixture-1', branch: 'main', plugin_file: 'demo/demo.php', subdirectory: 'demo' );
$assert( $stale->deploy( expected_commit: 'old' ) === 'provider_failed', 'stale head is a closed pre-fence outcome' );
$assert( 'failed' === $store->get( $stale->attempt_id() )['state'], 'stale head rejects pre-fence' );

$wrong_repository = $bootstrap( new GitHubFixtureProvider( $zip, 'abc123', 'wrong-id' ), $store, $root . '/archives', new RecordingExecutor(), $lock )->plugin( repository: 'acme/demo', repository_id: 'fixture-1', branch: 'main', plugin_file: 'demo/demo.php', subdirectory: 'demo' );
$assert( $wrong_repository->deploy( expected_commit: 'abc123' ) === 'provider_failed', 'provider identity is a closed pre-fence outcome' );
$assert( 'failed' === $store->get( $wrong_repository->attempt_id() )['state'], 'provider repository identity rejects pre-fence' );

$failing = $bootstrap( new GitHubFixtureProvider( $zip, 'abc123' ), $store, $root . '/archives', new RecordingExecutor( true ), $lock )->plugin( repository: 'acme/demo', repository_id: 'fixture-1', branch: 'main', plugin_file: 'demo/demo.php', subdirectory: 'demo' );
$assert( $failing->deploy( expected_commit: 'abc123' ) === 'restoration_uncertain', 'post-fence failure is a closed outcome' );
$assert( 'needs_attention' === $store->get( $failing->attempt_id() )['state'], 'post-fence failure is durable attention' );
$assert( ! glob( $root . '/archives/*' ), 'failure cleans exact archive' );

// Historical selection is one acquisition, not a permanent branch tracking lock.
foreach ( array( 40, 64 ) as $length ) {
	$commit           = str_repeat( 'a', $length );
	$history_provider = new GitHubFixtureProvider( $zip, str_repeat( 'b', $length ), historical: true );
	$history_executor = new RecordingExecutor();
	$history_store    = new FileAttemptStore( $root . '/historical-' . $length . '.json' );
	$history          = $bootstrap( $history_provider, $history_store, $root . '/archives', $history_executor, $lock )->plugin( repository: 'acme/demo', repository_id: 'fixture-1', branch: 'main', plugin_file: 'demo/demo.php', subdirectory: 'demo' );
	$assert( 'deployed' === $history->deploy( selected_commit: $commit ), 'historical selection survives a moved branch' );
	$record = $history_store->get( $history->attempt_id() );
	$assert( $commit === $record['selected_commit'] && $commit === $record['resolved_ref'] && 'main' === $record['branch'] && null === $record['expected_head'], 'journal retains exact selection and future branch' );
	$assert( 1 === $history_provider->verification_calls && count( $history_executor->calls ) === 1, 'historical mode runs final verifier and executor' );
	$assert( ! glob( $root . '/archives/*' ), 'historical completion cleans custody' );
	$head_only = $bootstrap( $history_provider, $history_store, $root . '/archives', new RecordingExecutor(), $lock )->plugin( repository: 'acme/demo', repository_id: 'fixture-1', branch: 'main', plugin_file: 'demo/demo.php', subdirectory: 'demo' );
	$assert( 'provider_failed' === $head_only->deploy( expected_commit: $commit ), 'expected-head mode still refuses moved branch' );
}
foreach ( array( 'unsupported', 'mismatch', 'revoked' ) as $case ) {
	$commit                              = str_repeat( 'a', 40 );
	$history_provider                    = new GitHubFixtureProvider( $zip, str_repeat( 'b', 40 ), historical: 'unsupported' !== $case );
	$history_provider->forced_ref        = 'mismatch' === $case ? str_repeat( 'c', 40 ) : null;
	$history_provider->historical_access = 'revoked' !== $case;
	$history_executor                    = new RecordingExecutor();
	$history_store                       = new FileAttemptStore( $root . '/historical-' . $case . '.json' );
	$history                             = $bootstrap( $history_provider, $history_store, $root . '/archives', $history_executor, $lock )->plugin( repository: 'acme/demo', repository_id: 'fixture-1', branch: 'main', plugin_file: 'demo/demo.php', subdirectory: 'demo' );
	$assert( 'provider_failed' === $history->deploy( selected_commit: $commit ), 'unsupported, mismatched or revoked historical selection refuses' );
	$assert( count( $history_executor->calls ) === 0 && null === $history_store->get( $history->attempt_id() )['mutation_started_at'], 'historical refusal precedes mutation' );
	$assert( ( 'unsupported' === $case ? 0 : 1 ) === $history_provider->prepare_calls, 'unsupported mode refuses before provider preparation' );
	$assert( ( 'revoked' === $case ? 1 : 0 ) === $history_provider->verification_calls, 'final verifier is required for acquired historical archives' );
	$assert( ! glob( $root . '/archives/*' ), 'historical refusal cleans custody' );
}
foreach ( array( 'abc123', str_repeat( 'A', 40 ), str_repeat( 'a', 41 ), ' ' . str_repeat( 'a', 40 ) ) as $invalid ) {
	try {
		new BranchDeploymentDeclaration( 'invalid-history', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', null, selected_commit: $invalid );
		$assert( false, 'invalid historical identity must refuse' );
	} catch ( RuntimeException $failure ) {
		$assert( 'Invalid historical commit selection.' === $failure->getMessage(), 'canonical historical identity is mandatory' );
	}
}
try {
	new BranchDeploymentDeclaration( 'conflicting-history', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', str_repeat( 'b', 40 ), selected_commit: str_repeat( 'a', 40 ) );
	$assert( false, 'historical and current-head modes cannot coexist' );
} catch ( RuntimeException $failure ) {
	$assert( 'Invalid historical commit selection.' === $failure->getMessage(), 'conflicting modes refuse' );
}

$selected_store = new FileAttemptStore( $root . '/selected-journal.json' );
$selected_store->begin( new BranchDeploymentDeclaration( 'selected-journal', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', null, selected_commit: str_repeat( 'a', 40 ) ) );
try {
	$selected_store->resolved( 'selected-journal', str_repeat( 'b', 40 ) );
	$assert( false, 'journal cannot record a different selected revision' );
} catch ( RuntimeException $failure ) {
	$assert( 'Revision is already recorded or differs from the selected commit.' === $failure->getMessage(), 'journal rejects mismatched revision before write' );
}
$assert( null === $selected_store->get( 'selected-journal' )['resolved_ref'], 'mismatched journal write preserves readable original state' );

$recovery_store = new FileAttemptStore( $root . '/recovery-attempts.json' );
$recovery_store->begin( $deploy( 'stopped-before-fence', 'abc123' ) );
$recovery_store->recover_stopped( 'stopped-before-fence' );
$assert( 'worker_stopped' === $recovery_store->get( 'stopped-before-fence' )['outcome'], 'pre-fence recovery fails safely' );
$recovery_store->begin( $deploy( 'stopped-after-fence', 'abc123' ) );
$recovery_store->resolved( 'stopped-after-fence', 'abc123' );
$recovery_store->fence( 'stopped-after-fence' );
$recovery_store->recover_stopped( 'stopped-after-fence' );
$assert( 'needs_attention' === $recovery_store->get( 'stopped-after-fence' )['state'], 'fenced recovery does not retry' );

echo "PASS branch package harness: github + bitbucket fixture contracts, ZIP custody, bounded artifact limits, standalone configuration, stale rejection, cleanup, durable recovery\n";
