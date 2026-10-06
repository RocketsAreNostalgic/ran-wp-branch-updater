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
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchRunner;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionFailure;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;

final class RAN_WP_Branch_Updater_ArchitectureBaselineTrace {
	/** @var list<string> */
	public array $events = array();
	public function add( string $event ): void {
		$this->events[] = $event;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ArchitectureBaselineJournal implements AdmittedAttemptJournal {
	public function __construct( private RAN_WP_Branch_Updater_ArchitectureBaselineTrace $trace ) {}
	public function record_resolved_ref( string $ref ): void {
		$this->trace->add( 'journal.resolved:' . $ref );
	}
	public function mark_mutation_started(): void {
		$this->trace->add( 'journal.fence' );
	}
	public function finish( string $code ): void {
		$this->trace->add( 'journal.finish:' . $code );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ArchitectureBaselineArtifact implements AdmittedBranchArtifact {
	public function __construct(
		private RAN_WP_Branch_Updater_ArchitectureBaselineTrace $trace,
		private string $version = '1.2.3',
		private ?\Throwable $integrity_failure = null
	) {}
	public function resolved_ref(): string {
		$this->trace->add( 'artifact.resolved' );
		return 'abc123';
	}
	public function expected_version(): string {
		$this->trace->add( 'artifact.version' );
		return $this->version;
	}
	public function assert_unchanged(): void {
		$this->trace->add( 'artifact.integrity' );
		if ( null !== $this->integrity_failure ) {
			throw $this->integrity_failure;
		}
	}
	public function cleanup(): void {
		$this->trace->add( 'artifact.cleanup' );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ArchitectureBaselineArchives implements AdmittedArchiveSource {
	public function __construct(
		private RAN_WP_Branch_Updater_ArchitectureBaselineTrace $trace,
		private RAN_WP_Branch_Updater_ArchitectureBaselineArtifact $artifact,
		private ?\Throwable $head_failure = null
	) {}
	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact {
		$this->trace->add( 'archives.prepare' );
		return $this->artifact;
	}
	public function verify_current_head(): void {
		$this->trace->add( 'archives.verify_head' );
		if ( null !== $this->head_failure ) {
			throw $this->head_failure;
		}
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ArchitectureBaselineTarget implements AdmittedTargetFacts {
	private int $policy_calls      = 0;
	private int $maintenance_calls = 0;
	public function __construct(
		private RAN_WP_Branch_Updater_ArchitectureBaselineTrace $trace,
		private ?array $initial_baseline = array(
			'identifier' => 'demo/demo.php',
			'version'    => '1.0.0',
			'active'     => true,
		),
		private ?array $locked_baseline = array(
			'identifier' => 'demo/demo.php',
			'version'    => '1.0.0',
			'active'     => true,
		),
		private array $installed_facts = array(
			'identifier' => 'demo/demo.php',
			'version'    => '1.2.3',
			'active'     => true,
		),
		private array $maintenance_states = array( false, false ),
		private bool $adopted = true,
		private ?array $restored_facts = array(
			'identifier' => 'demo/demo.php',
			'version'    => '1.0.0',
			'active'     => true,
		),
		private ?\Throwable $initial_policy_failure = null,
		private ?\Throwable $locked_policy_failure = null
	) {}
	public function assert_mutation_allowed(): void {
		++$this->policy_calls;
		$initial = 1 === $this->policy_calls;
		$this->trace->add( $initial ? 'target.policy.initial' : 'target.policy.locked' );
		$failure = $initial ? $this->initial_policy_failure : $this->locked_policy_failure;
		if ( null !== $failure ) {
			throw $failure;
		}
	}
	public function frozen_target( BranchDeploymentDeclaration $deployment, bool $defer_existing ): ?array {
		$this->trace->add( $defer_existing ? 'target.baseline.initial' : 'target.baseline.locked' );
		return $defer_existing ? $this->initial_baseline : $this->locked_baseline;
	}
	public function maintenance_active(): bool {
		$label = 0 === $this->maintenance_calls ? 'target.maintenance.before' : 'target.maintenance.after';
		$state = $this->maintenance_states[ $this->maintenance_calls ] ?? false;
		++$this->maintenance_calls;
		$this->trace->add( $label );
		return $state;
	}
	public function recheck_managed( BranchDeploymentDeclaration $deployment ): void {
		$this->trace->add( 'target.recheck_managed' );
	}
	public function installed( BranchDeploymentDeclaration $deployment ): array {
		$this->trace->add( 'target.installed' );
		return $this->installed_facts;
	}
	public function baseline_now( BranchDeploymentDeclaration $deployment, array $baseline ): ?array {
		$this->trace->add( 'target.baseline.restored' );
		return $this->restored_facts;
	}
	public function adopt( BranchDeploymentDeclaration $deployment ): bool {
		$this->trace->add( 'target.adopt' );
		return $this->adopted;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ArchitectureBaselineExecutor implements AdmittedPackageExecutor {
	private int $preflight_calls = 0;
	public function __construct(
		private RAN_WP_Branch_Updater_ArchitectureBaselineTrace $trace,
		private CorePackageExecutionResult $result,
		private ?\Throwable $first_preflight_failure = null,
		private ?\Throwable $second_preflight_failure = null
	) {}
	public function preflight( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): void {
		++$this->preflight_calls;
		$initial = 1 === $this->preflight_calls;
		$this->trace->add( $initial ? 'executor.preflight.initial' : 'executor.preflight.locked' );
		$failure = $initial ? $this->first_preflight_failure : $this->second_preflight_failure;
		if ( null !== $failure ) {
			throw $failure;
		}
	}
	public function execute( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
		$this->trace->add( 'executor.execute' );
		return $this->result;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ArchitectureBaselineLock implements MutationLock {
	public function __construct( private RAN_WP_Branch_Updater_ArchitectureBaselineTrace $trace ) {}
	public function run( callable $operation ): mixed {
		$this->trace->add( 'lock.enter' );
		try {
			return $operation();
		} finally {
			$this->trace->add( 'lock.exit' );
		}
	}
}

$assert     = static function ( bool $actual, string $message ): void {
	if ( ! $actual ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion diagnostics are not HTML; preserve the actual failing fixture detail.
		throw new \RuntimeException( 'FAIL: ' . $message );
	}
};
$deployment = static fn( string $id = 'architecture-baseline', string $operation = 'update' ): BranchDeploymentDeclaration => new BranchDeploymentDeclaration(
	$id,
	'plugin',
	'demo',
	'acme/demo',
	'repository-id',
	'main',
	'abc123',
	$operation,
	'demo',
	'demo/demo.php'
);

$trace    = new RAN_WP_Branch_Updater_ArchitectureBaselineTrace();
$target   = new RAN_WP_Branch_Updater_ArchitectureBaselineTarget( $trace );
$artifact = new RAN_WP_Branch_Updater_ArchitectureBaselineArtifact( $trace );
$executor = new RAN_WP_Branch_Updater_ArchitectureBaselineExecutor( $trace, CorePackageExecutionResult::succeeded() );
$runner   = new AdmittedBranchRunner(
	new RAN_WP_Branch_Updater_ArchitectureBaselineJournal( $trace ),
	new RAN_WP_Branch_Updater_ArchitectureBaselineArchives( $trace, $artifact ),
	$target,
	$executor,
	new RAN_WP_Branch_Updater_ArchitectureBaselineLock( $trace )
);
$outcome  = $runner->run( $deployment() );
$assert( 'deployed' === $outcome, 'successful update remains deployed' );
$assert(
	array(
		'target.policy.initial',
		'target.baseline.initial',
		'archives.prepare',
		'artifact.version',
		'executor.preflight.initial',
		'artifact.resolved',
		'journal.resolved:abc123',
		'lock.enter',
		'target.baseline.locked',
		'artifact.version',
		'archives.verify_head',
		'artifact.integrity',
		'target.maintenance.before',
		'target.policy.locked',
		'executor.preflight.locked',
		'journal.fence',
		'executor.execute',
		'target.maintenance.after',
		'target.recheck_managed',
		'target.installed',
		'artifact.version',
		'artifact.cleanup',
		'lock.exit',
		'journal.finish:deployed',
	) === $trace->events,
	'successful update ordering remains fixed'
);
try {
	$runner->run( $deployment( 'consumed-runner' ) );
	$assert( false, 'runner must remain one-shot' );
} catch ( \RuntimeException $expected ) {
	$assert( 'An admitted deployment runner is already consumed.' === $expected->getMessage(), 'runner one-shot failure remains stable' );
}

$scenario = static function ( array $options ) use ( $assert ): string {
	$trace    = new RAN_WP_Branch_Updater_ArchitectureBaselineTrace();
	$target   = new RAN_WP_Branch_Updater_ArchitectureBaselineTarget(
		$trace,
		array_key_exists( 'initialBaseline', $options ) ? $options['initialBaseline'] : array(
			'identifier' => 'demo/demo.php',
			'version'    => '1.0.0',
			'active'     => true,
		),
		array_key_exists( 'lockedBaseline', $options ) ? $options['lockedBaseline'] : array(
			'identifier' => 'demo/demo.php',
			'version'    => '1.0.0',
			'active'     => true,
		),
		$options['installedFacts'] ?? array(
			'identifier' => 'demo/demo.php',
			'version'    => '1.2.3',
			'active'     => true,
		),
		$options['maintenanceStates'] ?? array( false, false ),
		$options['adopted'] ?? true,
		array_key_exists( 'restoredFacts', $options ) ? $options['restoredFacts'] : array(
			'identifier' => 'demo/demo.php',
			'version'    => '1.0.0',
			'active'     => true,
		),
		$options['initialPolicyFailure'] ?? null,
		$options['lockedPolicyFailure'] ?? null
	);
	$artifact = new RAN_WP_Branch_Updater_ArchitectureBaselineArtifact( $trace, $options['version'] ?? '1.2.3', $options['integrityFailure'] ?? null );
	$executor = new RAN_WP_Branch_Updater_ArchitectureBaselineExecutor(
		$trace,
		$options['result'] ?? CorePackageExecutionResult::succeeded(),
		$options['firstPreflightFailure'] ?? null,
		$options['secondPreflightFailure'] ?? null
	);
	$runner   = new AdmittedBranchRunner(
		new RAN_WP_Branch_Updater_ArchitectureBaselineJournal( $trace ),
		new RAN_WP_Branch_Updater_ArchitectureBaselineArchives( $trace, $artifact, $options['headFailure'] ?? null ),
		$target,
		$executor,
		new RAN_WP_Branch_Updater_ArchitectureBaselineLock( $trace )
	);
	$outcome  = $runner->run( new BranchDeploymentDeclaration( 'scenario-' . bin2hex( random_bytes( 4 ) ), 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', $options['operation'] ?? 'update', 'demo', 'demo/demo.php' ) );
	$assert( end( $trace->events ) === 'journal.finish:' . $outcome, 'terminal outcome is journaled last for ' . $outcome );
	return $outcome;
};

$assert( 'policy_blocked' === $scenario( array( 'initialPolicyFailure' => new \RuntimeException( 'blocked' ) ) ), 'initial policy failure remains policy_blocked' );
$assert( 'preflight_failed' === $scenario( array( 'firstPreflightFailure' => new \RuntimeException( 'preflight' ) ) ), 'first preflight failure remains preflight_failed' );
$assert(
	'downgrade_blocked' === $scenario(
		array(
			'initialBaseline' => array(
				'identifier' => 'demo/demo.php',
				'version'    => '2.0.0',
				'active'     => true,
			),
		)
	),
	'initial downgrade remains blocked'
);
$assert(
	'downgrade_blocked' === $scenario(
		array(
			'lockedBaseline' => array(
				'identifier' => 'demo/demo.php',
				'version'    => '2.0.0',
				'active'     => true,
			),
		)
	),
	'locked baseline downgrade remains blocked'
);
$assert( 'provider_failed' === $scenario( array( 'headFailure' => new \RuntimeException( 'head advanced' ) ) ), 'locked source-head advancement remains provider_failed' );
$assert( 'archive_integrity_failed' === $scenario( array( 'integrityFailure' => new \RuntimeException( 'changed' ) ) ), 'locked artifact replacement remains archive_integrity_failed' );
$assert( 'deployment_maintenance_active' === $scenario( array( 'maintenanceStates' => array( true ) ) ), 'pre-mutation maintenance remains a closed failure' );
$assert( 'policy_blocked' === $scenario( array( 'secondPreflightFailure' => new \RuntimeException( 'locked preflight' ) ) ), 'second preflight generic failure retains current policy_blocked mapping' );
$assert( 'maintenance_remaining' === $scenario( array( 'maintenanceStates' => array( false, true ) ) ), 'post-mutation maintenance remains needs-attention outcome' );
$assert(
	'installed_version_mismatch' === $scenario(
		array(
			'installedFacts' => array(
				'identifier' => 'demo/demo.php',
				'version'    => '9.9.9',
				'active'     => true,
			),
		)
	),
	'installed version mismatch remains fail-closed'
);
$assert(
	'activation_state_changed' === $scenario(
		array(
			'installedFacts' => array(
				'identifier' => 'demo/demo.php',
				'version'    => '1.2.3',
				'active'     => false,
			),
		)
	),
	'update activation drift remains fail-closed'
);
$assert(
	'activation_state_changed' === $scenario(
		array(
			'operation'       => 'install',
			'initialBaseline' => null,
			'lockedBaseline'  => null,
			'installedFacts'  => array(
				'identifier' => 'demo/demo.php',
				'version'    => '1.2.3',
				'active'     => true,
			),
		)
	),
	'unexpected install activation remains fail-closed'
);
$assert(
	'persistence_uncertain' === $scenario(
		array(
			'operation'       => 'install',
			'initialBaseline' => null,
			'lockedBaseline'  => null,
			'installedFacts'  => array(
				'identifier' => 'demo/demo.php',
				'version'    => '1.2.3',
				'active'     => false,
			),
			'adopted'         => false,
		)
	),
	'install adoption failure remains persistence_uncertain'
);
$assert( 'activation_failed' === $scenario( array( 'result' => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_RESTORED ) ) ), 'WordPress-restored failure remains activation_failed' );
$assert( 'upgrader_failed' === $scenario( array( 'result' => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_REFUSED ) ) ), 'WordPress refusal remains upgrader_failed' );
$assert( 'upgrader_failed' === $scenario( array( 'result' => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED ) ) ), 'WordPress failure remains upgrader_failed' );
$assert(
	'restoration_uncertain' === $scenario(
		array(
			'result'        => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED ),
			'restoredFacts' => null,
		)
	),
	'unproved restoration remains restoration_uncertain'
);

try {
	new BranchDeploymentDeclaration( '', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123' );
	$assert( false, 'invalid declaration must remain rejected' );
} catch ( \RuntimeException $expected ) {
	$assert( 'Invalid branch deployment declaration.' === $expected->getMessage(), 'declaration validation failure remains stable' );
}

echo "PASS architectural normalisation behavioural sequence and terminal outcomes\n";
