<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused fault-injection doubles exercise the package's public contracts in one CLI fixture.
// phpcs:disable WordPress.Security.EscapeOutput -- This standalone fixture prints its own pass result only.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Generic.Formatting.MultipleStatementAlignment,Generic.CodeAnalysis.EmptyStatement,Squiz.Functions.MultiLineFunctionDeclaration.ContentAfterBrace,Squiz.ControlStructures.ControlSignature.NewlineAfterOpenBrace,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- The isolated fixture retains compact contract doubles and exception assertions.
declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\AdmittedPackageExecutor;
use RAN\WPBranchUpdater\V1\Contract\AdmittedTargetFacts;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Persistence\BranchDeploymentJournalFailure;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchDurabilityFailure;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchRunner;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;

final class RunnerFixtureJournal implements AdmittedAttemptJournal {
	public int $finishCalls = 0;
	public function __construct( private ?\Throwable $recordFailure = null, private ?\Throwable $fenceFailure = null ) {}
	public function recordResolvedRef( string $ref ): void {
		if ( null !== $this->recordFailure ) {
			throw $this->recordFailure;
		}
	}
	public function markMutationStarted(): void {
		if ( null !== $this->fenceFailure ) {
			throw $this->fenceFailure;
		}
	}
	public function finish( string $code ): void {
		++$this->finishCalls;
	}
}

final class RunnerFixtureArtifact implements AdmittedBranchArtifact {
	public int $cleanupCalls = 0;
	public function __construct( private ?\Throwable $cleanupFailure = null ) {}
	public function resolvedRef(): string { return 'abc123'; }
	public function expectedVersion(): string { return '1.2.3'; }
	public function assertUnchanged(): void {}
	public function cleanup(): void {
		++$this->cleanupCalls;
		if ( null !== $this->cleanupFailure ) {
			throw $this->cleanupFailure;
		}
	}
}

final class RunnerFixtureArchives implements AdmittedArchiveSource {
	public function __construct( private RunnerFixtureArtifact $artifact ) {}
	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact { return $this->artifact; }
	public function verifyCurrentHead(): void {}
}

final class RunnerFixtureTarget implements AdmittedTargetFacts {
	public function __construct( private ?\Throwable $firstFrozenFailure = null, private bool $installedActive = false ) {}
	public function assertMutationAllowed(): void {}
	public function frozenTarget( BranchDeploymentDeclaration $deployment, bool $deferExisting ): ?array {
		if ( $deferExisting && null !== $this->firstFrozenFailure ) {
			throw $this->firstFrozenFailure;
		}
		return null;
	}
	public function maintenanceActive(): bool { return false; }
	public function recheckManaged( BranchDeploymentDeclaration $deployment ): void {}
	public function installed( BranchDeploymentDeclaration $deployment ): array { return array( 'identifier' => 'demo', 'version' => '1.2.3', 'active' => $this->installedActive ); }
	public function baselineNow( BranchDeploymentDeclaration $deployment, array $baseline ): ?array { return $baseline; }
	public function adopt( BranchDeploymentDeclaration $deployment ): bool { return true; }
}

final class RunnerFixtureExecutor implements AdmittedPackageExecutor {
	public function preflight( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): void {}
	public function execute( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
		return CorePackageExecutionResult::succeeded();
	}
}

final class RunnerFixtureLock implements MutationLock {
	public function __construct( private ?\Throwable $failure = null ) {}
	public function run( callable $operation ): mixed {
		if ( null !== $this->failure ) {
			throw $this->failure;
		}
		return $operation();
	}
}

$assert = static function ( bool $actual, string $message ): void {
	if ( ! $actual ) {
		throw new \RuntimeException( 'FAIL: ' . $message );
	}
};
$deployment = new BranchDeploymentDeclaration( 'focused-runner', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', 'abc123', 'update', 'demo', 'demo/demo.php' );
$runner = static fn( RunnerFixtureJournal $journal, RunnerFixtureArtifact $artifact, RunnerFixtureTarget $target, RunnerFixtureLock $lock ): AdmittedBranchRunner => new AdmittedBranchRunner( $journal, new RunnerFixtureArchives( $artifact ), $target, new RunnerFixtureExecutor(), $lock );
$acceptedSlug = 'package.' . str_repeat( 'a', 183 );
$accepted = new BranchDeploymentDeclaration( 'accepted-dotted-slug', 'plugin', $acceptedSlug, 'acme/demo', 'fixture-1', 'main', 'abc123' );
$assert( $acceptedSlug === $accepted->slug, 'admitted Core dotted package slugs remain accepted through 191 characters' );

$journal = new RunnerFixtureJournal( new BranchDeploymentJournalFailure( 'record unavailable' ) );
$artifact = new RunnerFixtureArtifact();
try {
	$runner( $journal, $artifact, new RunnerFixtureTarget(), new RunnerFixtureLock() )->run( $deployment );
	$assert( false, 'journal record failure must escape' );
} catch ( BranchDeploymentJournalFailure ) {}
$assert( 1 === $artifact->cleanupCalls, 'journal record failure cleans the prepared artifact' );
$assert( 0 === $journal->finishCalls, 'journal record failure never finishes the attempt' );

$journal = new RunnerFixtureJournal( null, new BranchDeploymentJournalFailure( 'fence unavailable' ) );
$artifact = new RunnerFixtureArtifact( new \RuntimeException( 'cleanup unavailable' ) );
try {
	$runner( $journal, $artifact, new RunnerFixtureTarget(), new RunnerFixtureLock() )->run( $deployment );
	$assert( false, 'journal fence failure must escape' );
} catch ( BranchDeploymentJournalFailure ) {}
$assert( 1 === $artifact->cleanupCalls, 'journal fence failure attempts cleanup once' );
$assert( 0 === $journal->finishCalls, 'journal fence failure never finishes the attempt' );

$journal = new RunnerFixtureJournal();
$artifact = new RunnerFixtureArtifact();
$outcome = $runner( $journal, $artifact, new RunnerFixtureTarget(), new RunnerFixtureLock( new \RuntimeException( 'lock held' ) ) )->run( $deployment );
$assert( 'lock_unavailable' === $outcome, 'generic pre-fence lock contention is lock_unavailable' );
$assert( 1 === $journal->finishCalls, 'lock contention records its terminal outcome' );

$journal = new RunnerFixtureJournal();
$artifact = new RunnerFixtureArtifact();
$outcome = $runner( $journal, $artifact, new RunnerFixtureTarget( new \RuntimeException( 'policy unavailable' ) ), new RunnerFixtureLock() )->run( $deployment );
$assert( 'policy_blocked' === $outcome, 'generic pre-lock policy failure is policy_blocked' );
$assert( 1 === $journal->finishCalls, 'policy failure records its terminal outcome' );

$journal = new RunnerFixtureJournal();
$artifact = new RunnerFixtureArtifact( new \RuntimeException( 'cleanup unavailable' ) );
$outcome = $runner( $journal, $artifact, new RunnerFixtureTarget(), new RunnerFixtureLock( new \RuntimeException( 'lock held' ) ) )->run( $deployment );
$assert( 'archive_cleanup_failed' === $outcome, 'generic pre-fence cleanup failure is archive_cleanup_failed' );
$assert( 1 === $artifact->cleanupCalls, 'cleanup failure is attempted once' );
$assert( 1 === $journal->finishCalls, 'cleanup failure records its terminal outcome' );

$journal = new RunnerFixtureJournal();
$artifact = new RunnerFixtureArtifact();
$install = new BranchDeploymentDeclaration( 'focused-install', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', 'abc123', 'install', 'demo', 'demo/demo.php' );
$outcome = $runner( $journal, $artifact, new RunnerFixtureTarget( null, true ), new RunnerFixtureLock() )->run( $install );
$assert( 'activation_state_changed' === $outcome, 'an install unexpectedly activated by WordPress cannot succeed' );

echo "PASS admitted branch runner fault-injection contracts\n";
