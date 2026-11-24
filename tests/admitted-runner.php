<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.
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
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchStageFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionFailure;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RAN\WPBranchUpdater\V1\Runtime\FailureReason;

final class RAN_WP_Branch_Updater_RunnerFixtureJournal implements AdmittedAttemptJournal {
	public int $finish_calls = 0;
	public function __construct( private ?\Throwable $record_failure = null, private ?\Throwable $fence_failure = null ) {}
	public function record_resolved_ref( string $ref ): void {
		if ( null !== $this->record_failure ) {
			throw $this->record_failure;
		}
	}
	public function mark_mutation_started(): void {
		if ( null !== $this->fence_failure ) {
			throw $this->fence_failure;
		}
	}
	public function finish( string $code ): void {
		++$this->finish_calls;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_RunnerFixtureArtifact implements AdmittedBranchArtifact {
	public int $cleanup_calls = 0;
	public function __construct( private ?\Throwable $cleanup_failure = null, private string $ref = 'abc123' ) {}
	public function resolved_ref(): string {
		return $this->ref; }
	public function expected_version(): string {
		return '1.2.3'; }
	public function assert_unchanged(): void {}
	public function cleanup(): void {
		++$this->cleanup_calls;
		if ( null !== $this->cleanup_failure ) {
			throw $this->cleanup_failure;
		}
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_RunnerFixtureArchives implements AdmittedArchiveSource {
	public int $verify_calls = 0;
	/** @param \ArrayObject<int,string>|null $trace */
	public function __construct(
		private RAN_WP_Branch_Updater_RunnerFixtureArtifact $artifact,
		private ?\Throwable $verify_failure = null,
		private ?\ArrayObject $trace = null
	) {}
	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact {
		if ( null !== $this->trace ) {
			$this->trace[] = 'prepare';
		}
		return $this->artifact; }
	public function verify_current_head(): void {
		++$this->verify_calls;
		if ( null !== $this->trace ) {
			$this->trace[] = 'verify_current_head';
		}
		if ( null !== $this->verify_failure ) {
			throw $this->verify_failure;
		}
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_RunnerFixtureTarget implements AdmittedTargetFacts {
	public function target_directory( BranchDeploymentDeclaration $deployment ): string {
		return __DIR__ . '/build/absent-target/' . $deployment->slug;
	}

	public int $adoptions            = 0;
	public bool $baseline_unreadable = false;
	public function __construct( private ?\Throwable $first_frozen_failure = null, private bool $installed_active = false, private string $identifier = 'demo', private ?bool $pre_lock_active = null ) {}
	public function assert_mutation_allowed(): void {}
	public function frozen_target( BranchDeploymentDeclaration $deployment, bool $defer_existing ): ?array {
		if ( $defer_existing && null !== $this->first_frozen_failure ) {
			throw $this->first_frozen_failure;
		}
		if ( null !== $this->pre_lock_active ) {
			return array(
				'identifier' => $this->identifier,
				'version'    => '1.2.2',
				'active'     => $defer_existing ? $this->pre_lock_active : $this->installed_active,
			);
		}
		return null;
	}
	public function maintenance_active(): bool {
		return false; }
	public function recheck_managed( BranchDeploymentDeclaration $deployment ): void {}
	public function installed( BranchDeploymentDeclaration $deployment ): array {
		return array(
			'identifier' => $this->identifier,
			'version'    => '1.2.3',
			'active'     => $this->installed_active,
		); }
	public function baseline_now( BranchDeploymentDeclaration $deployment, array $baseline ): ?array {
		return $this->baseline_unreadable ? null : ( $this->frozen_target( $deployment, false ) ?? $baseline );
	}
	public function adopt( BranchDeploymentDeclaration $deployment ): bool {
		++$this->adoptions;
		return true; }
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_RunnerFixtureExecutor implements AdmittedPackageExecutor {
	/** @var array{identifier:string,version:string,active:bool}|null */
	public ?array $observed_baseline = null;
	public function __construct( private ?CorePackageExecutionResult $result = null ) {}
	public function preflight( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): void {}
	public function execute( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
		$this->observed_baseline = $baseline;
		return $this->result ?? CorePackageExecutionResult::succeeded();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_RunnerFixtureLock implements MutationLock {
	public int $calls = 0;
	/** @param \ArrayObject<int,string>|null $trace */
	public function __construct( private ?\Throwable $failure = null, private ?\ArrayObject $trace = null ) {}
	public function run( callable $operation ): mixed {
		++$this->calls;
		if ( null !== $this->trace ) {
			$this->trace[] = 'lock.enter';
		}
		if ( null !== $this->failure ) {
			throw $this->failure;
		}
		try {
			return $operation();
		} finally {
			if ( null !== $this->trace ) {
				$this->trace[] = 'lock.exit';
			}
		}
	}
}

$assert              = static function ( bool $actual, string $message ): void {
	if ( ! $actual ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion diagnostics are not HTML; preserve the actual failing fixture detail.
		throw new \RuntimeException( 'FAIL: ' . $message );
	}
};
$deployment          = new BranchDeploymentDeclaration( 'focused-runner', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', 'abc123', 'update', 'demo', 'demo/demo.php' );
$runner              = static fn( RAN_WP_Branch_Updater_RunnerFixtureJournal $journal, RAN_WP_Branch_Updater_RunnerFixtureArtifact $artifact, RAN_WP_Branch_Updater_RunnerFixtureTarget $target, RAN_WP_Branch_Updater_RunnerFixtureLock $lock ): AdmittedBranchRunner => new AdmittedBranchRunner( $journal, new RAN_WP_Branch_Updater_RunnerFixtureArchives( $artifact ), $target, new RAN_WP_Branch_Updater_RunnerFixtureExecutor(), $lock );
$changed_before_lock = new RAN_WP_Branch_Updater_RunnerFixtureTarget( installed_active: true, pre_lock_active: false );
$assert(
	'deployed' === $runner( new RAN_WP_Branch_Updater_RunnerFixtureJournal(), new RAN_WP_Branch_Updater_RunnerFixtureArtifact(), $changed_before_lock, new RAN_WP_Branch_Updater_RunnerFixtureLock() )->run( $deployment ),
	'activation changes before the lock must not be attributed to the updater'
);
foreach ( array( false, true ) as $unreadable ) {
	$changed_before_lock->baseline_unreadable = $unreadable;
	$failed_executor                          = new RAN_WP_Branch_Updater_RunnerFixtureExecutor( CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_REFUSED ) );
	$failed_runner                            = new AdmittedBranchRunner(
		new RAN_WP_Branch_Updater_RunnerFixtureJournal(),
		new RAN_WP_Branch_Updater_RunnerFixtureArchives( new RAN_WP_Branch_Updater_RunnerFixtureArtifact() ),
		$changed_before_lock,
		$failed_executor,
		new RAN_WP_Branch_Updater_RunnerFixtureLock()
	);
	$failed_outcome                           = $failed_runner->run( $deployment );
	$assert( true === ( $failed_executor->observed_baseline['active'] ?? false ), 'executor receives the post-lock activation state' );
	$assert( ( $unreadable ? 'restoration_uncertain' : 'upgrader_failed' ) === $failed_outcome, 'failure restoration compares against the locked baseline' );
	$assert( ! $unreadable || FailureReason::BASELINE_UNREADABLE === $failed_runner->last_reason()?->reason, 'unreadable facts retain the bounded baseline reason' );
}
$accepted_slug = 'package.' . str_repeat( 'a', 183 );
$accepted      = new BranchDeploymentDeclaration( 'accepted-dotted-slug', 'plugin', $accepted_slug, 'acme/demo', 'fixture-1', 'main', 'abc123' );
$assert( $accepted_slug === $accepted->slug, 'admitted Core dotted package slugs remain accepted through 191 characters' );

$numeric_attempt = new BranchDeploymentDeclaration( '123', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', 'abc123' );
$numeric_journal = new RAN_WP_Branch_Updater_RunnerFixtureJournal();
$numeric_bound   = \RAN\WPBranchUpdater\V1\Runtime\BranchUpdater::for_admitted_attempt( $numeric_attempt, $numeric_journal, new RAN_WP_Branch_Updater_RunnerFixtureArchives( new RAN_WP_Branch_Updater_RunnerFixtureArtifact() ), new RAN_WP_Branch_Updater_RunnerFixtureTarget(), new RAN_WP_Branch_Updater_RunnerFixtureExecutor(), new RAN_WP_Branch_Updater_RunnerFixtureLock() )->plugin( 'acme/demo', 'fixture-1', 'main', package_slug: 'demo' );
$assert( 'deployed' === $numeric_bound->deploy() && 1 === $numeric_journal->finish_calls, 'custom admitted journals retain numeric attempt IDs' );

foreach ( array(
	'demo/custom.php' => 'deployed',
	'demo/wrong.php'  => 'persistence_uncertain',
) as $identifier => $expected_outcome ) {
	$exact          = new BranchDeploymentDeclaration( 'exact-initial', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', 'abc123', operation: 'install', expected_identifier: 'demo/custom.php' );
	$exact_artifact = new RAN_WP_Branch_Updater_RunnerFixtureArtifact();
	$exact_target   = new RAN_WP_Branch_Updater_RunnerFixtureTarget( identifier: $identifier );
	$exact_bound    = \RAN\WPBranchUpdater\V1\Runtime\BranchUpdater::for_admitted_attempt( $exact, new RAN_WP_Branch_Updater_RunnerFixtureJournal(), new RAN_WP_Branch_Updater_RunnerFixtureArchives( $exact_artifact ), $exact_target, new RAN_WP_Branch_Updater_RunnerFixtureExecutor(), new RAN_WP_Branch_Updater_RunnerFixtureLock() )->plugin( 'acme/demo', 'fixture-1', 'main', package_slug: 'demo' );
	$assert( $expected_outcome === $exact_bound->deploy() && 1 === $exact_artifact->cleanup_calls, 'admitted copies retain exact initial target and postcondition' );
	$assert( ( 'deployed' === $expected_outcome ? 1 : 0 ) === $exact_target->adoptions, 'installed identity mismatch refuses adoption' );
	try {
		$exact_bound->deploy( expected_identifier: 'demo/override.php' );
		$assert( false, 'admitted initial target cannot be overridden' );
	} catch ( InvalidArgumentException $failure ) {
		$assert( 'The admitted deployment terminal declaration does not match.' === $failure->getMessage(), 'exact initial target remains immutable' );
	}
}

// Per-call overrides cannot broaden or narrow an immutable admitted declaration.
foreach ( array( false, true ) as $admitted_override ) {
	$guarded = new BranchDeploymentDeclaration( 'immutable-override', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', 'abc123', allow_working_copy: $admitted_override );
	$journal = new RAN_WP_Branch_Updater_RunnerFixtureJournal();
	$bound   = \RAN\WPBranchUpdater\V1\Runtime\BranchUpdater::for_admitted_attempt( $guarded, $journal, new RAN_WP_Branch_Updater_RunnerFixtureArchives( new RAN_WP_Branch_Updater_RunnerFixtureArtifact() ), new RAN_WP_Branch_Updater_RunnerFixtureTarget(), new RAN_WP_Branch_Updater_RunnerFixtureExecutor(), new RAN_WP_Branch_Updater_RunnerFixtureLock() )->plugin( 'acme/demo', 'fixture-1', 'main', package_slug: 'demo' );
	try {
		$bound->deploy( allow_working_copy: ! $admitted_override );
		$assert( false, 'terminal calls cannot change admitted working-copy policy' );
	} catch ( InvalidArgumentException $failure ) {
		$assert( 'The admitted deployment terminal declaration does not match.' === $failure->getMessage() && 0 === $journal->finish_calls, 'override mismatch refuses before the runner starts' );
	}
	$assert( 'deployed' === $bound->deploy(), 'refused override does not consume the admitted runner' );
}

$historical = new BranchDeploymentDeclaration( 'historical', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', null, selected_commit: str_repeat( 'a', 40 ) );
$journal    = new RAN_WP_Branch_Updater_RunnerFixtureJournal();
$artifact   = new RAN_WP_Branch_Updater_RunnerFixtureArtifact();
$outcome    = $runner( $journal, $artifact, new RAN_WP_Branch_Updater_RunnerFixtureTarget(), new RAN_WP_Branch_Updater_RunnerFixtureLock() )->run( $historical );
$assert( 'provider_failed' === $outcome && 1 === $artifact->cleanup_calls && 1 === $journal->finish_calls, 'admitted historical artifact mismatch fails with captured cleanup' );

$artifact = new RAN_WP_Branch_Updater_RunnerFixtureArtifact( ref: str_repeat( 'a', 40 ) );
$journal  = new RAN_WP_Branch_Updater_RunnerFixtureJournal();
$target   = new RAN_WP_Branch_Updater_RunnerFixtureTarget();
$bound    = \RAN\WPBranchUpdater\V1\Runtime\BranchUpdater::for_admitted_attempt( $historical, $journal, new RAN_WP_Branch_Updater_RunnerFixtureArchives( $artifact ), $target, new RAN_WP_Branch_Updater_RunnerFixtureExecutor(), new RAN_WP_Branch_Updater_RunnerFixtureLock() )->plugin( 'acme/demo', 'fixture-1', 'main', package_slug: 'demo' );
$assert( 'deployed' === $bound->deploy() && 1 === $artifact->cleanup_calls, 'admitted pending and deploy copies preserve historical selection' );
try {
	$bound->deploy( selected_commit: str_repeat( 'b', 40 ) );
	$assert( false, 'admitted historical identity cannot be overridden' );
} catch ( InvalidArgumentException $failure ) {
	$assert( 'The admitted deployment terminal declaration does not match.' === $failure->getMessage(), 'admitted exact selection remains immutable' );
}

$journal  = new RAN_WP_Branch_Updater_RunnerFixtureJournal( new BranchDeploymentJournalFailure( 'record unavailable' ) );
$artifact = new RAN_WP_Branch_Updater_RunnerFixtureArtifact();
try {
	$runner( $journal, $artifact, new RAN_WP_Branch_Updater_RunnerFixtureTarget(), new RAN_WP_Branch_Updater_RunnerFixtureLock() )->run( $deployment );
	$assert( false, 'journal record failure must escape' );
// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Reaching this catch proves the expected rejection; no mutation is needed.
} catch ( BranchDeploymentJournalFailure ) { // Expected rejection is the assertion; execution continues only for this exception.
}
$assert( 1 === $artifact->cleanup_calls, 'journal record failure cleans the prepared artifact' );
$assert( 0 === $journal->finish_calls, 'journal record failure never finishes the attempt' );

$journal  = new RAN_WP_Branch_Updater_RunnerFixtureJournal( null, new BranchDeploymentJournalFailure( 'fence unavailable' ) );
$artifact = new RAN_WP_Branch_Updater_RunnerFixtureArtifact( new \RuntimeException( 'cleanup unavailable' ) );
try {
	$runner( $journal, $artifact, new RAN_WP_Branch_Updater_RunnerFixtureTarget(), new RAN_WP_Branch_Updater_RunnerFixtureLock() )->run( $deployment );
	$assert( false, 'journal fence failure must escape' );
// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Reaching this catch proves the expected rejection; no mutation is needed.
} catch ( BranchDeploymentJournalFailure ) { // Expected rejection is the assertion; execution continues only for this exception.
}
$assert( 1 === $artifact->cleanup_calls, 'journal fence failure attempts cleanup once' );
$assert( 0 === $journal->finish_calls, 'journal fence failure never finishes the attempt' );

$journal  = new RAN_WP_Branch_Updater_RunnerFixtureJournal();
$artifact = new RAN_WP_Branch_Updater_RunnerFixtureArtifact();
$outcome  = $runner( $journal, $artifact, new RAN_WP_Branch_Updater_RunnerFixtureTarget(), new RAN_WP_Branch_Updater_RunnerFixtureLock( new \RuntimeException( 'lock held' ) ) )->run( $deployment );
$assert( 'lock_unavailable' === $outcome, 'generic pre-fence lock contention is lock_unavailable' );
$assert( 1 === $journal->finish_calls, 'lock contention records its terminal outcome' );

$journal  = new RAN_WP_Branch_Updater_RunnerFixtureJournal();
$artifact = new RAN_WP_Branch_Updater_RunnerFixtureArtifact();
$outcome  = $runner( $journal, $artifact, new RAN_WP_Branch_Updater_RunnerFixtureTarget( new \RuntimeException( 'policy unavailable' ) ), new RAN_WP_Branch_Updater_RunnerFixtureLock() )->run( $deployment );
$assert( 'policy_blocked' === $outcome, 'generic pre-lock policy failure is policy_blocked' );
$assert( 1 === $journal->finish_calls, 'policy failure records its terminal outcome' );

$journal             = new RAN_WP_Branch_Updater_RunnerFixtureJournal();
$artifact            = new RAN_WP_Branch_Updater_RunnerFixtureArtifact( new \RuntimeException( 'cleanup unavailable' ) );
$cleanup_lock_runner = $runner( $journal, $artifact, new RAN_WP_Branch_Updater_RunnerFixtureTarget(), new RAN_WP_Branch_Updater_RunnerFixtureLock( new \RuntimeException( 'lock held' ) ) );
$outcome             = $cleanup_lock_runner->run( $deployment );
$cleanup_lock_reason = $cleanup_lock_runner->last_reason();
$assert( 'lock_unavailable' === $outcome, 'primary lock failure wins when archive cleanup also fails' );
$assert( null !== $cleanup_lock_reason && FailureReason::CLEANUP_ALSO_FAILED === $cleanup_lock_reason->reason && $cleanup_lock_reason->cleanup_failed && null === $cleanup_lock_reason->primary_outcome, 'cleanup failure beside plain lock failure records cleanup_also_failed' );
$assert( 1 === $artifact->cleanup_calls, 'cleanup failure is attempted once' );
$assert( 1 === $journal->finish_calls, 'cleanup failure records its terminal outcome' );

$deployed_cleanup_journal  = new RAN_WP_Branch_Updater_RunnerFixtureJournal();
$deployed_cleanup_artifact = new RAN_WP_Branch_Updater_RunnerFixtureArtifact( new \RuntimeException( 'cleanup unavailable' ) );
$deployed_cleanup_runner   = $runner( $deployed_cleanup_journal, $deployed_cleanup_artifact, new RAN_WP_Branch_Updater_RunnerFixtureTarget(), new RAN_WP_Branch_Updater_RunnerFixtureLock() );
$deployed_cleanup_outcome  = $deployed_cleanup_runner->run( $deployment );
$deployed_cleanup_reason   = $deployed_cleanup_runner->last_reason();
$assert( 'deployed' === $deployed_cleanup_outcome && 1 === $deployed_cleanup_artifact->cleanup_calls && 1 === $deployed_cleanup_journal->finish_calls, 'verified deployment stays deployed when only archive cleanup fails' );
$assert( null !== $deployed_cleanup_reason && FailureReason::CLEANUP_ALSO_FAILED === $deployed_cleanup_reason->reason && $deployed_cleanup_reason->cleanup_failed && null === $deployed_cleanup_reason->primary_outcome, 'post-deploy cleanup failure records cleanup_also_failed on last_reason' );

// Cleanup failing alone without a primary failure or verified outcome preserves archive_cleanup_failed.
$solo_cleanup_runner = $runner( new RAN_WP_Branch_Updater_RunnerFixtureJournal(), new RAN_WP_Branch_Updater_RunnerFixtureArtifact(), new RAN_WP_Branch_Updater_RunnerFixtureTarget(), new RAN_WP_Branch_Updater_RunnerFixtureLock() );
$solo_cleanup_method = new \ReflectionMethod( AdmittedBranchRunner::class, 'cleanup_locked_artifact' );
$solo_stage_property = new \ReflectionProperty( AdmittedBranchRunner::class, 'stage' );
$solo_error          = new \RuntimeException( 'solo cleanup failed' );
try {
	$solo_cleanup_method->invoke( $solo_cleanup_runner, new RAN_WP_Branch_Updater_RunnerFixtureArtifact( $solo_error ), null, null );
	$assert( false, 'cleanup failing alone must throw' );
} catch ( \RuntimeException $caught_solo ) {
	$assert( $caught_solo === $solo_error && 'archive_cleanup_failed' === $solo_stage_property->getValue( $solo_cleanup_runner ), 'cleanup failing alone preserves archive_cleanup_failed' );
}

$journal  = new RAN_WP_Branch_Updater_RunnerFixtureJournal();
$artifact = new RAN_WP_Branch_Updater_RunnerFixtureArtifact();
$install  = new BranchDeploymentDeclaration( 'focused-install', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', 'abc123', 'install', 'demo', 'demo/demo.php' );
$outcome  = $runner( $journal, $artifact, new RAN_WP_Branch_Updater_RunnerFixtureTarget( null, true ), new RAN_WP_Branch_Updater_RunnerFixtureLock() )->run( $install );
$assert( 'activation_state_changed' === $outcome, 'an install unexpectedly activated by WordPress cannot succeed' );

// Issue #149: Remote head verification runs before acquiring auto_updater.lock and cleans up without locking on failure.
/** @var \ArrayObject<int,string> $order_trace */
$order_trace = new \ArrayObject();
$order_art   = new RAN_WP_Branch_Updater_RunnerFixtureArtifact();
$order_arch  = new RAN_WP_Branch_Updater_RunnerFixtureArchives( $order_art, null, $order_trace );
$order_lock  = new RAN_WP_Branch_Updater_RunnerFixtureLock( null, $order_trace );
$order_out   = ( new AdmittedBranchRunner(
	new RAN_WP_Branch_Updater_RunnerFixtureJournal(),
	$order_arch,
	new RAN_WP_Branch_Updater_RunnerFixtureTarget(),
	new RAN_WP_Branch_Updater_RunnerFixtureExecutor(),
	$order_lock
) )->run( $deployment );
$assert(
	'deployed' === $order_out
		&& array( 'prepare', 'verify_current_head', 'lock.enter', 'lock.exit' ) === $order_trace->getArrayCopy()
		&& 1 === $order_arch->verify_calls
		&& 1 === $order_lock->calls
		&& 1 === $order_art->cleanup_calls,
	'verify_current_head must execute before acquiring the mutation lock'
);

foreach ( array(
	'generic head failure' => array( new \RuntimeException( 'provider timeout' ), null, 'provider_failed', false ),
	'stale head stage'     => array( new AdmittedBranchStageFailure( 'stale_event' ), null, 'stale_event', false ),
	'head + cleanup fail'  => array( new AdmittedBranchStageFailure( 'stale_event' ), new \RuntimeException( 'cleanup failed' ), 'stale_event', true ),
) as $head_label => [$head_failure, $cleanup_failure, $expected_outcome, $expected_cleanup_failed] ) {
	$head_journal = new RAN_WP_Branch_Updater_RunnerFixtureJournal();
	$head_art     = new RAN_WP_Branch_Updater_RunnerFixtureArtifact( $cleanup_failure );
	$head_arch    = new RAN_WP_Branch_Updater_RunnerFixtureArchives( $head_art, $head_failure );
	$head_lock    = new RAN_WP_Branch_Updater_RunnerFixtureLock();
	$head_runner  = new AdmittedBranchRunner(
		$head_journal,
		$head_arch,
		new RAN_WP_Branch_Updater_RunnerFixtureTarget(),
		new RAN_WP_Branch_Updater_RunnerFixtureExecutor(),
		$head_lock
	);
	$head_outcome = $head_runner->run( $deployment );
	$assert(
		$expected_outcome === $head_outcome
			&& 1 === $head_arch->verify_calls
			&& 0 === $head_lock->calls
			&& 1 === $head_art->cleanup_calls
			&& 1 === $head_journal->finish_calls,
		$head_label . ': pre-lock head failure must clean up and finish without acquiring the lock'
	);
	if ( $expected_cleanup_failed ) {
		$head_reason = $head_runner->last_reason();
		$assert(
			null !== $head_reason
				&& $head_reason->cleanup_failed
				&& FailureReason::CLEANUP_ALSO_FAILED === $head_reason->reason
				&& null === $head_reason->primary_outcome,
			$head_label . ': pre-lock head failure with failing cleanup keeps primary outcome and flags cleanup_failed'
		);
	}
}


echo "PASS admitted branch runner fault-injection contracts\n";
