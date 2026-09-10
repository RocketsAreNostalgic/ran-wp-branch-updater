<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused lifecycle doubles belong to one standalone characterization fixture.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,WordPress.Security.EscapeOutput -- Standalone CLI fixture owns only local proof state and output.
// phpcs:disable Squiz.Functions.MultiLineFunctionDeclaration.ContentAfterBrace,Generic.CodeAnalysis.EmptyStatement -- Compact fixture doubles keep the characterization readable.
declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\BranchDeployment\{
	AdmittedArchiveSource,
	AdmittedAttemptJournal,
	AdmittedBranchArtifact,
	AdmittedBranchRunner,
	AdmittedPackageExecutor,
	AdmittedTargetFacts,
	CorePackageExecutionFailure,
	CorePackageExecutionResult,
	Deployment,
	MutationLock
};

final class ArchitectureBaselineTrace {
	/** @var list<string> */
	public array $events = array();
	public function add( string $event ): void {
		$this->events[] = $event;
	}
}

final class ArchitectureBaselineJournal implements AdmittedAttemptJournal {
	public function __construct( private ArchitectureBaselineTrace $trace ) {}
	public function recordResolvedRef( string $ref ): void {
		$this->trace->add( 'journal.resolved:' . $ref );
	}
	public function markMutationStarted(): void {
		$this->trace->add( 'journal.fence' );
	}
	public function finish( string $code ): void {
		$this->trace->add( 'journal.finish:' . $code );
	}
}

final class ArchitectureBaselineArtifact implements AdmittedBranchArtifact {
	public function __construct(
		private ArchitectureBaselineTrace $trace,
		private string $version = '1.2.3',
		private ?\Throwable $integrityFailure = null
	) {}
	public function resolvedRef(): string {
		$this->trace->add( 'artifact.resolved' );
		return 'abc123';
	}
	public function expectedVersion(): string {
		$this->trace->add( 'artifact.version' );
		return $this->version;
	}
	public function assertUnchanged(): void {
		$this->trace->add( 'artifact.integrity' );
		if ( null !== $this->integrityFailure ) {
			throw $this->integrityFailure;
		}
	}
	public function cleanup(): void {
		$this->trace->add( 'artifact.cleanup' );
	}
}

final class ArchitectureBaselineArchives implements AdmittedArchiveSource {
	public function __construct(
		private ArchitectureBaselineTrace $trace,
		private ArchitectureBaselineArtifact $artifact,
		private ?\Throwable $headFailure = null
	) {}
	public function prepare( Deployment $deployment, ?array $baseline ): AdmittedBranchArtifact {
		$this->trace->add( 'archives.prepare' );
		return $this->artifact;
	}
	public function verifyCurrentHead(): void {
		$this->trace->add( 'archives.verify_head' );
		if ( null !== $this->headFailure ) {
			throw $this->headFailure;
		}
	}
}

final class ArchitectureBaselineTarget implements AdmittedTargetFacts {
	private int $policyCalls = 0;
	private int $maintenanceCalls = 0;
	public function __construct(
		private ArchitectureBaselineTrace $trace,
		private ?array $initialBaseline = array( 'identifier' => 'demo/demo.php', 'version' => '1.0.0', 'active' => true ),
		private ?array $lockedBaseline = array( 'identifier' => 'demo/demo.php', 'version' => '1.0.0', 'active' => true ),
		private array $installedFacts = array( 'identifier' => 'demo/demo.php', 'version' => '1.2.3', 'active' => true ),
		private array $maintenanceStates = array( false, false ),
		private bool $adopted = true,
		private ?array $restoredFacts = array( 'identifier' => 'demo/demo.php', 'version' => '1.0.0', 'active' => true ),
		private ?\Throwable $initialPolicyFailure = null,
		private ?\Throwable $lockedPolicyFailure = null
	) {}
	public function assertMutationAllowed(): void {
		++$this->policyCalls;
		$initial = 1 === $this->policyCalls;
		$this->trace->add( $initial ? 'target.policy.initial' : 'target.policy.locked' );
		$failure = $initial ? $this->initialPolicyFailure : $this->lockedPolicyFailure;
		if ( null !== $failure ) {
			throw $failure;
		}
	}
	public function frozenTarget( Deployment $deployment, bool $deferExisting ): ?array {
		$this->trace->add( $deferExisting ? 'target.baseline.initial' : 'target.baseline.locked' );
		return $deferExisting ? $this->initialBaseline : $this->lockedBaseline;
	}
	public function maintenanceActive(): bool {
		$label = 0 === $this->maintenanceCalls ? 'target.maintenance.before' : 'target.maintenance.after';
		$state = $this->maintenanceStates[ $this->maintenanceCalls ] ?? false;
		++$this->maintenanceCalls;
		$this->trace->add( $label );
		return $state;
	}
	public function recheckManaged( Deployment $deployment ): void {
		$this->trace->add( 'target.recheck_managed' );
	}
	public function installed( Deployment $deployment ): array {
		$this->trace->add( 'target.installed' );
		return $this->installedFacts;
	}
	public function baselineNow( Deployment $deployment, array $baseline ): ?array {
		$this->trace->add( 'target.baseline.restored' );
		return $this->restoredFacts;
	}
	public function adopt( Deployment $deployment ): bool {
		$this->trace->add( 'target.adopt' );
		return $this->adopted;
	}
}

final class ArchitectureBaselineExecutor implements AdmittedPackageExecutor {
	private int $preflightCalls = 0;
	public function __construct(
		private ArchitectureBaselineTrace $trace,
		private CorePackageExecutionResult $result,
		private ?\Throwable $firstPreflightFailure = null,
		private ?\Throwable $secondPreflightFailure = null
	) {}
	public function preflight( Deployment $deployment, AdmittedBranchArtifact $artifact ): void {
		++$this->preflightCalls;
		$initial = 1 === $this->preflightCalls;
		$this->trace->add( $initial ? 'executor.preflight.initial' : 'executor.preflight.locked' );
		$failure = $initial ? $this->firstPreflightFailure : $this->secondPreflightFailure;
		if ( null !== $failure ) {
			throw $failure;
		}
	}
	public function execute( Deployment $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
		$this->trace->add( 'executor.execute' );
		return $this->result;
	}
}

final class ArchitectureBaselineLock implements MutationLock {
	public function __construct( private ArchitectureBaselineTrace $trace ) {}
	public function run( callable $operation ): mixed {
		$this->trace->add( 'lock.enter' );
		try {
			return $operation();
		} finally {
			$this->trace->add( 'lock.exit' );
		}
	}
}

$assert = static function ( bool $actual, string $message ): void {
	if ( ! $actual ) {
		throw new \RuntimeException( 'FAIL: ' . $message );
	}
};
$deployment = static fn( string $id = 'architecture-baseline', string $operation = 'update' ): Deployment => new Deployment(
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

$trace    = new ArchitectureBaselineTrace();
$target   = new ArchitectureBaselineTarget( $trace );
$artifact = new ArchitectureBaselineArtifact( $trace );
$executor = new ArchitectureBaselineExecutor( $trace, CorePackageExecutionResult::succeeded() );
$runner   = new AdmittedBranchRunner(
	new ArchitectureBaselineJournal( $trace ),
	new ArchitectureBaselineArchives( $trace, $artifact ),
	$target,
	$executor,
	new ArchitectureBaselineLock( $trace )
);
$outcome = $runner->run( $deployment() );
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
	$trace = new ArchitectureBaselineTrace();
	$target = new ArchitectureBaselineTarget(
		$trace,
		array_key_exists( 'initialBaseline', $options ) ? $options['initialBaseline'] : array( 'identifier' => 'demo/demo.php', 'version' => '1.0.0', 'active' => true ),
		array_key_exists( 'lockedBaseline', $options ) ? $options['lockedBaseline'] : array( 'identifier' => 'demo/demo.php', 'version' => '1.0.0', 'active' => true ),
		$options['installedFacts'] ?? array( 'identifier' => 'demo/demo.php', 'version' => '1.2.3', 'active' => true ),
		$options['maintenanceStates'] ?? array( false, false ),
		$options['adopted'] ?? true,
		array_key_exists( 'restoredFacts', $options ) ? $options['restoredFacts'] : array( 'identifier' => 'demo/demo.php', 'version' => '1.0.0', 'active' => true ),
		$options['initialPolicyFailure'] ?? null,
		$options['lockedPolicyFailure'] ?? null
	);
	$artifact = new ArchitectureBaselineArtifact(
		$trace,
		$options['version'] ?? '1.2.3',
		$options['integrityFailure'] ?? null
	);
	$executor = new ArchitectureBaselineExecutor(
		$trace,
		$options['result'] ?? CorePackageExecutionResult::succeeded(),
		$options['firstPreflightFailure'] ?? null,
		$options['secondPreflightFailure'] ?? null
	);
	$runner = new AdmittedBranchRunner(
		new ArchitectureBaselineJournal( $trace ),
		new ArchitectureBaselineArchives( $trace, $artifact, $options['headFailure'] ?? null ),
		$target,
		$executor,
		new ArchitectureBaselineLock( $trace )
	);
	$outcome = $runner->run( new Deployment( 'scenario-' . bin2hex( random_bytes( 4 ) ), 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', $options['operation'] ?? 'update', 'demo', 'demo/demo.php' ) );
	$assert( 'journal.finish:' . $outcome === end( $trace->events ), 'terminal outcome is journaled last for ' . $outcome );
	return $outcome;
};

$assert( 'policy_blocked' === $scenario( array( 'initialPolicyFailure' => new \RuntimeException( 'blocked' ) ) ), 'initial policy failure remains policy_blocked' );
$assert( 'preflight_failed' === $scenario( array( 'firstPreflightFailure' => new \RuntimeException( 'preflight' ) ) ), 'first preflight failure remains preflight_failed' );
$assert( 'downgrade_blocked' === $scenario( array( 'initialBaseline' => array( 'identifier' => 'demo/demo.php', 'version' => '2.0.0', 'active' => true ) ) ), 'initial downgrade remains blocked' );
$assert( 'downgrade_blocked' === $scenario( array( 'lockedBaseline' => array( 'identifier' => 'demo/demo.php', 'version' => '2.0.0', 'active' => true ) ) ), 'locked baseline downgrade remains blocked' );
$assert( 'provider_failed' === $scenario( array( 'headFailure' => new \RuntimeException( 'head advanced' ) ) ), 'locked source-head advancement remains provider_failed' );
$assert( 'archive_integrity_failed' === $scenario( array( 'integrityFailure' => new \RuntimeException( 'changed' ) ) ), 'locked artifact replacement remains archive_integrity_failed' );
$assert( 'deployment_maintenance_active' === $scenario( array( 'maintenanceStates' => array( true ) ) ), 'pre-mutation maintenance remains a closed failure' );
$assert( 'policy_blocked' === $scenario( array( 'secondPreflightFailure' => new \RuntimeException( 'locked preflight' ) ) ), 'second preflight generic failure retains current policy_blocked mapping' );
$assert( 'maintenance_remaining' === $scenario( array( 'maintenanceStates' => array( false, true ) ) ), 'post-mutation maintenance remains needs-attention outcome' );
$assert( 'installed_version_mismatch' === $scenario( array( 'installedFacts' => array( 'identifier' => 'demo/demo.php', 'version' => '9.9.9', 'active' => true ) ) ), 'installed version mismatch remains fail-closed' );
$assert( 'activation_state_changed' === $scenario( array( 'installedFacts' => array( 'identifier' => 'demo/demo.php', 'version' => '1.2.3', 'active' => false ) ) ), 'update activation drift remains fail-closed' );
$assert( 'activation_state_changed' === $scenario( array( 'operation' => 'install', 'initialBaseline' => null, 'lockedBaseline' => null, 'installedFacts' => array( 'identifier' => 'demo/demo.php', 'version' => '1.2.3', 'active' => true ) ) ), 'unexpected install activation remains fail-closed' );
$assert( 'persistence_uncertain' === $scenario( array( 'operation' => 'install', 'initialBaseline' => null, 'lockedBaseline' => null, 'installedFacts' => array( 'identifier' => 'demo/demo.php', 'version' => '1.2.3', 'active' => false ), 'adopted' => false ) ), 'install adoption failure remains persistence_uncertain' );
$assert( 'activation_failed' === $scenario( array( 'result' => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_RESTORED ) ) ), 'WordPress-restored failure remains activation_failed' );
$assert( 'upgrader_failed' === $scenario( array( 'result' => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_REFUSED ) ) ), 'WordPress refusal remains upgrader_failed' );
$assert( 'upgrader_failed' === $scenario( array( 'result' => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED ) ) ), 'WordPress failure remains upgrader_failed' );
$assert( 'restoration_uncertain' === $scenario( array( 'result' => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED ), 'restoredFacts' => null ) ), 'unproved restoration remains restoration_uncertain' );

try {
	new Deployment( '', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123' );
	$assert( false, 'invalid declaration must remain rejected' );
} catch ( \RuntimeException $expected ) {
	$assert( 'Invalid branch deployment declaration.' === $expected->getMessage(), 'declaration validation failure remains stable' );
}

echo "PASS architectural normalisation behavioural sequence and terminal outcomes\n";
