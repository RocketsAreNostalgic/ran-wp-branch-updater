<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.
declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptReasonJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\AdmittedPackageExecutor;
use RAN\WPBranchUpdater\V1\Contract\AdmittedTargetFacts;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Persistence\BranchDeploymentJournalFailure;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchDurabilityFailure;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchStageFailure;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedFailureReason;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockReleaseFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockStorageFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchUpdater;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionFailure;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RAN\WPBranchUpdater\V1\Runtime\FailureReason;
use RAN\WPBranchUpdater\V1\Runtime\ReasonedFailure;

$assert = static function ( bool $actual, string $message ): void {
	if ( ! $actual ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion diagnostics are not HTML; preserve the actual failing fixture detail.
		throw new RuntimeException( 'FAIL: ' . $message );
	}
};

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
class RAN_WP_Branch_Updater_ReasonJournal implements AdmittedAttemptJournal {
	/** @var list<string> */
	public array $finished = array();
	public function record_resolved_ref( string $ref ): void {}
	public function mark_mutation_started(): void {}
	public function finish( string $code ): void {
		$this->finished[] = $code;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonAwareJournal extends RAN_WP_Branch_Updater_ReasonJournal implements AdmittedAttemptReasonJournal {
	/** @var list<array{string,AdmittedFailureReason}> */
	public array $reasoned = array();
	public function finish_with_reason( string $code, AdmittedFailureReason $reason ): void {
		$this->reasoned[] = array( $code, $reason );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonArtifact implements AdmittedBranchArtifact {
	public int $cleanups = 0;
	public function __construct( private ?Throwable $cleanup_failure = null, private ?Throwable $custody_failure = null ) {}
	public function resolved_ref(): string {
		return 'abc123'; }
	public function expected_version(): string {
		return '1.2.3'; }
	public function assert_unchanged(): void {
		if ( null !== $this->custody_failure ) {
			throw $this->custody_failure;
		}
	}
	public function cleanup(): void {
		++$this->cleanups;
		if ( null !== $this->cleanup_failure ) {
			throw $this->cleanup_failure;
		}
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonArchives implements AdmittedArchiveSource {
	public function __construct( private RAN_WP_Branch_Updater_ReasonArtifact $artifact, private ?Throwable $failure ) {}
	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact {
		if ( null !== $this->failure ) {
			throw $this->failure;
		}
		return $this->artifact;
	}
	public function verify_current_head(): void {}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonTarget implements AdmittedTargetFacts {
	/**
	 * @param array{identifier:string,version:string,active:bool}|null $baseline
	 * @param array<string,mixed> $o
	 */
	public function __construct( private ?array $baseline, private array $o ) {}
	public function assert_mutation_allowed(): void {}
	public function frozen_target( BranchDeploymentDeclaration $deployment, bool $defer_existing ): ?array {
		return $this->baseline;
	}
	public function maintenance_active(): bool {
		return false;
	}
	public function recheck_managed( BranchDeploymentDeclaration $deployment ): void {}
	public function installed( BranchDeploymentDeclaration $deployment ): array {
		return array(
			'identifier' => $this->o['installed_identifier'] ?? 'demo/demo.php',
			'version'    => '1.2.3',
			'active'     => false,
		);
	}
	public function baseline_now( BranchDeploymentDeclaration $deployment, array $baseline ): ?array {
		return array_key_exists( 'baseline_now', $this->o ) ? $this->o['baseline_now'] : $baseline;
	}
	public function adopt( BranchDeploymentDeclaration $deployment ): bool {
		return $this->o['adopt'] ?? true;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonExecutor implements AdmittedPackageExecutor {
	public function __construct( private CorePackageExecutionResult $result, private ?Throwable $preflight ) {}
	public function preflight( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): void {
		if ( null !== $this->preflight ) {
			throw $this->preflight;
		}
	}
	public function execute( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
		return $this->result;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonLock implements MutationLock {
	public function __construct( private ?Throwable $failure ) {}
	public function run( callable $operation ): mixed {
		if ( null !== $this->failure ) {
			throw $this->failure;
		}
		return $operation();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonFixtures {
	public const BASELINE = array(
		'identifier' => 'demo/demo.php',
		'version'    => '1.0.0',
		'active'     => false,
	);

	/**
	 * Run one admitted attempt through the public BranchUpdater path.
	 *
	 * @param array<string,mixed> $o Fixture options.
	 * @return array{string,?AdmittedFailureReason,RAN_WP_Branch_Updater_ReasonJournal,RAN_WP_Branch_Updater_ReasonArtifact,?Throwable}
	 */
	public static function run( array $o = array() ): array {
		$journal  = $o['journal'] ?? new RAN_WP_Branch_Updater_ReasonJournal();
		$artifact = new RAN_WP_Branch_Updater_ReasonArtifact( $o['cleanup_failure'] ?? null, $o['custody_failure'] ?? null );
		$baseline = array_key_exists( 'baseline', $o ) ? $o['baseline'] : ( 'install' === ( $o['operation'] ?? 'update' ) ? null : self::BASELINE );
		$declared = new BranchDeploymentDeclaration( 'reason-attempt', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', isset( $o['selected_commit'] ) ? null : 'abc123', $o['operation'] ?? 'update', null, $o['declared_identifier'] ?? 'demo/demo.php', $o['selected_commit'] ?? null, $o['expected_identifier'] ?? null );
		$bound    = BranchUpdater::for_admitted_attempt(
			$declared,
			$journal,
			new RAN_WP_Branch_Updater_ReasonArchives( $artifact, $o['prepare_failure'] ?? null ),
			new RAN_WP_Branch_Updater_ReasonTarget( $baseline, $o ),
			new RAN_WP_Branch_Updater_ReasonExecutor( $o['result'] ?? CorePackageExecutionResult::succeeded(), $o['preflight_failure'] ?? null ),
			new RAN_WP_Branch_Updater_ReasonLock( $o['lock_failure'] ?? null )
		)->plugin( 'acme/demo', 'fixture-1', 'main', package_slug: 'demo' );
		$thrown   = null;
		try {
			$outcome = $bound->deploy();
		} catch ( Throwable $thrown ) {
			$outcome = 'thrown';
		}
		return array( $outcome, $bound->last_reason(), $journal, $artifact, $thrown );
	}
}

$check = static function ( string $label, array $options, string $outcome, FailureReason $reason, ?string $detail = null ) use ( $assert ): void {
	[$actual, $record, $journal] = RAN_WP_Branch_Updater_ReasonFixtures::run( $options );
	$assert( $outcome === $actual, $label . ': outcome code is unchanged (' . $actual . ')' );
	$assert( null !== $record && $reason === $record->reason && $detail === $record->detail && ! $record->cleanup_failed, $label . ': reason ' . $reason->value . ' is recorded' );
	$assert( array( $outcome ) === $journal->finished, $label . ': an old journal still receives only the closed code' );
};

// Old-journal compatibility: ignoring the new capability is exactly today's behaviour.
[$outcome, , $old] = RAN_WP_Branch_Updater_ReasonFixtures::run( array( 'result' => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED ) ) );
$assert( 'upgrader_failed' === $outcome && array( 'upgrader_failed' ) === $old->finished, 'an old journal finishes with the unchanged code' );
$aware              = new RAN_WP_Branch_Updater_ReasonAwareJournal();
[$outcome, $record] = RAN_WP_Branch_Updater_ReasonFixtures::run(
	array(
		'journal' => $aware,
		'result'  => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED, 'incompatible_archive' ),
	)
);
$assert( 'upgrader_failed' === $outcome && array() === $aware->finished && 1 === count( $aware->reasoned ), 'a reason-aware journal gets finish_with_reason instead of finish' );
$assert( 'upgrader_failed' === $aware->reasoned[0][0] && $record === $aware->reasoned[0][1], 'the journal receives the same code and the runner record' );
$aware_success = new RAN_WP_Branch_Updater_ReasonAwareJournal();
RAN_WP_Branch_Updater_ReasonFixtures::run( array( 'journal' => $aware_success ) );
$assert( array( 'deployed' ) === $aware_success->finished && array() === $aware_success->reasoned, 'success and reason-less outcomes use finish() even for a reason-aware journal' );
[$outcome, $record] = RAN_WP_Branch_Updater_ReasonFixtures::run();
$assert( 'deployed' === $outcome && null === $record, 'success carries no reason' );

// Core results: one reason per CorePackageExecutionFailure, detail passes through the screen.
$core_outcomes = array(
	'invalid_request'     => 'restoration_uncertain',
	'runtime_unsupported' => 'restoration_uncertain',
	'wordpress_refused'   => 'upgrader_failed',
	'wordpress_failed'    => 'upgrader_failed',
	'wordpress_restored'  => 'activation_failed',
	'wordpress_uncertain' => 'restoration_uncertain',
	'operation_mismatch'  => 'restoration_uncertain',
);
foreach ( CorePackageExecutionFailure::cases() as $failure ) {
	$check(
		'core ' . $failure->value,
		array(
			'baseline' => RAN_WP_Branch_Updater_ReasonFixtures::BASELINE,
			'result'   => CorePackageExecutionResult::failed( $failure ),
		),
		$core_outcomes[ $failure->value ],
		FailureReason::for_core( $failure )
	);
}
$check(
	'core failed with an upgrader code',
	array(
		'baseline' => RAN_WP_Branch_Updater_ReasonFixtures::BASELINE,
		'result'   => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED, 'copy_failed' ),
	),
	'upgrader_failed',
	FailureReason::CORE_WORDPRESS_FAILED,
	'copy_failed'
);
$check(
	'core detail is screened again by the record',
	array(
		'baseline' => RAN_WP_Branch_Updater_ReasonFixtures::BASELINE,
		'result'   => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED, '/var/www/secret/path' ),
	),
	'upgrader_failed',
	FailureReason::CORE_WORDPRESS_FAILED
);
$check(
	'install failure without a baseline keeps the core reason',
	array(
		'operation' => 'install',
		'result'    => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED ),
	),
	'restoration_uncertain',
	FailureReason::CORE_WORDPRESS_FAILED
);

// Restoration and postcondition checks.
$check(
	'unreadable baseline',
	array(
		'baseline'     => RAN_WP_Branch_Updater_ReasonFixtures::BASELINE,
		'baseline_now' => null,
		'result'       => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED ),
	),
	'restoration_uncertain',
	FailureReason::BASELINE_UNREADABLE
);
$check(
	'changed baseline',
	array(
		'baseline'     => RAN_WP_Branch_Updater_ReasonFixtures::BASELINE,
		'baseline_now' => array_merge( RAN_WP_Branch_Updater_ReasonFixtures::BASELINE, array( 'version' => '9.9.9' ) ),
		'result'       => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED ),
	),
	'restoration_uncertain',
	FailureReason::BASELINE_CHANGED
);
$check(
	'identifier mismatch',
	array(
		'operation'            => 'install',
		'expected_identifier'  => 'demo/demo.php',
		'installed_identifier' => 'demo/other.php',
	),
	'persistence_uncertain',
	FailureReason::IDENTIFIER_MISMATCH
);
$check(
	'adoption refused',
	array(
		'operation' => 'install',
		'adopt'     => false,
	),
	'persistence_uncertain',
	FailureReason::ADOPT_FAILED
);
$check( 'selected commit differs from the resolved ref', array( 'selected_commit' => str_repeat( 'b', 40 ) ), 'provider_failed', FailureReason::REF_MISMATCH );

// Ambiguous durability failures still escape, and their reason is available from last_reason().
$ambiguous = array(
	'lock storage' => array( new BranchDeploymentLockStorageFailure( 'private storage text' ), FailureReason::LOCK_STORAGE ),
	'lock release' => array( new BranchDeploymentLockReleaseFailure( 'private release text' ), FailureReason::LOCK_RELEASE ),
	'journal'      => array( new BranchDeploymentJournalFailure( 'private journal text' ), FailureReason::JOURNAL_UNCERTAIN ),
	'durability'   => array( new AdmittedBranchDurabilityFailure( 'private durability text' ), FailureReason::DURABILITY_UNCERTAIN ),
);
foreach ( $ambiguous as $label => [$thrown_failure, $expected_reason] ) {
	[$outcome, $record, $journal, , $thrown] = RAN_WP_Branch_Updater_ReasonFixtures::run( array( 'lock_failure' => $thrown_failure ) );
	$assert( 'thrown' === $outcome && $thrown === $thrown_failure && array() === $journal->finished, $label . ': the ambiguous failure still escapes and nothing is finished' );
	$assert( null !== $record && $expected_reason === $record->reason && null === $record->detail, $label . ': last_reason names the ambiguous cause without its message' );
}

echo "PASS admitted failure reasons\n";
