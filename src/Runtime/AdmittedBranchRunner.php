<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RAN\UpdaterSupport\V1\TargetWorkingCopy;
use RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptReasonJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\AdmittedPackageExecutor;
use RAN\WPBranchUpdater\V1\Contract\AdmittedTargetFacts;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Persistence\BranchDeploymentJournalFailure;
use RuntimeException;
use Throwable;

/** Shared terminal sequencing for one already-admitted branch deployment. */
final class AdmittedBranchRunner {
	private bool $consumed                      = false;
	private ?AdmittedFailureReason $last_reason = null;
	private ?AdmittedBranchArtifact $artifact   = null;
	private bool $fenced                        = false;
	private bool $cleanup_attempted             = false;
	private bool $cleanup_failed                = false;
	private string $stage                       = 'policy_blocked';
	private ?AdmittedFailureReason $reason      = null;

	public function __construct(
		private AdmittedAttemptJournal $journal,
		private AdmittedArchiveSource $archives,
		private AdmittedTargetFacts $target,
		private AdmittedPackageExecutor $executor,
		private MutationLock $lock
	) {}

	/** The reason for the last run's outcome, if any; also set before an ambiguous failure escapes. */
	public function last_reason(): ?AdmittedFailureReason {
		return $this->last_reason;
	}

	/** Whether run() has already started; a consumed runner refuses every further call. */
	public function is_consumed(): bool {
		return $this->consumed;
	}

	/**
	 * Returns the closed outcome only after the attempt journal has finished.
	 *
	 * Escaping failure contract:
	 * - `AdmittedBranchDurabilityFailure` (`FailureReason::DURABILITY_UNCERTAIN`)
	 * - `BranchDeploymentJournalFailure` (`FailureReason::JOURNAL_UNCERTAIN`)
	 * - `BranchDeploymentLockReleaseFailure` (`FailureReason::LOCK_RELEASE`)
	 * - `BranchDeploymentLockStorageFailure` (`FailureReason::LOCK_STORAGE`)
	 * - `RuntimeException` when called on an already-consumed runner
	 * - Any unexpected `Throwable` raised by the host journal during terminal `finish()` / `finish_with_reason()`
	 *
	 * @throws AdmittedBranchDurabilityFailure    When durability cannot be proved.
	 * @throws BranchDeploymentJournalFailure     When durable journal state is unknown.
	 * @throws BranchDeploymentLockReleaseFailure When mutation lock release cannot be proved.
	 * @throws BranchDeploymentLockStorageFailure When mutation lock storage cannot be reconciled.
	 * @throws RuntimeException                   When this one-shot runner has already been consumed.
	 */
	public function run( BranchDeploymentDeclaration $deployment ): string {
		if ( $this->consumed ) {
			throw new RuntimeException( 'An admitted deployment runner is already consumed.' );
		}
		$this->consumed = true;
		try {
			$artifact    = $this->prepare_before_lock( $deployment );
			$this->stage = 'lock_unavailable';
			$outcome     = $this->lock->run( fn (): string => $this->run_locked( $deployment, $artifact ) );
		} catch ( Throwable $failure ) {
			$outcome = $this->handle_failure( $failure );
		} finally {
			$this->artifact = null;
		}
		$this->last_reason = $this->with_cleanup( $this->reason, $this->cleanup_failed );
		$this->finish_journal( $outcome );
		return $outcome;
	}

	private function prepare_before_lock( BranchDeploymentDeclaration $deployment ): AdmittedBranchArtifact {
		$this->target->assert_mutation_allowed();
		$baseline       = $this->target->frozen_target( $deployment, true );
		$this->stage    = 'preflight_failed';
		$artifact       = $this->archives->prepare( $deployment, $baseline );
		$this->artifact = $artifact;
		if ( null !== $deployment->selected_commit && ! hash_equals( $deployment->selected_commit, $artifact->resolved_ref() ) ) {
			$this->reason = new AdmittedFailureReason( FailureReason::REF_MISMATCH );
			throw new AdmittedBranchStageFailure( 'provider_failed' );
		}
		$this->assert_not_downgrade( $deployment, $baseline, $artifact );
		$this->executor->preflight( $deployment, $artifact );
		$this->journal->record_resolved_ref( $artifact->resolved_ref() );
		$this->stage = 'provider_failed';
		$this->archives->verify_current_head();
		return $artifact;
	}

	private function run_locked( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): string {
		try {
			return $this->execute_locked( $deployment, $artifact );
		} catch ( Throwable $failure ) {
			$this->reason = $this->reason_of( $failure );
			throw $failure;
		} finally {
			$this->cleanup_locked_artifact( $artifact );
		}
	}

	private function execute_locked( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): string {
		$this->stage = 'policy_blocked';
		$baseline    = $this->target->frozen_target( $deployment, false );
		$this->assert_not_downgrade( $deployment, $baseline, $artifact );
		$this->stage = 'archive_integrity_failed';
		$artifact->assert_unchanged();
		$this->stage = 'policy_blocked';
		if ( $this->target->maintenance_active() ) {
			return 'deployment_maintenance_active';
		}
		$this->target->assert_mutation_allowed();
		$this->executor->preflight( $deployment, $artifact );
		if ( ! $deployment->allow_working_copy && TargetWorkingCopy::is_working_copy( $this->target->target_directory( $deployment ) ) ) {
			$this->reason = new AdmittedFailureReason( FailureReason::TARGET_IS_WORKING_COPY );
			return 'policy_blocked';
		}
		$this->journal->mark_mutation_started();
		$this->fenced = true;
		$result       = $this->executor->execute( $deployment, $baseline, $artifact );
		if ( $this->target->maintenance_active() ) {
			return 'maintenance_remaining';
		}
		if ( $result->is_successful() ) {
			return $this->verify_installed_postconditions( $deployment, $baseline, $artifact );
		}
		$this->reason = new AdmittedFailureReason( FailureReason::for_core( $result->get_failure() ), $result->get_detail() );
		return $this->classify_execution_failure( $deployment, $baseline, $result->get_failure() );
	}

	/** @param array{identifier:string,version:string,active:bool}|null $baseline */
	private function assert_not_downgrade( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): void {
		// The host admits an exact historical selection as rollback intent.
		if ( null === $deployment->selected_commit && null !== $baseline && version_compare( $artifact->expected_version(), $baseline['version'], '<' ) ) {
			throw new AdmittedBranchStageFailure( 'downgrade_blocked' );
		}
	}

	/** @param array{identifier:string,version:string,active:bool}|null $baseline */
	private function verify_installed_postconditions( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): string {
		if ( 'update' === $deployment->operation ) {
			$this->target->recheck_managed( $deployment );
		}
		$installed = $this->target->installed( $deployment );
		if ( null !== $deployment->expected_identifier && ! hash_equals( $deployment->expected_identifier, $installed['identifier'] ) ) {
			$this->reason = new AdmittedFailureReason( FailureReason::IDENTIFIER_MISMATCH );
			return 'persistence_uncertain';
		}
		if ( ! hash_equals( $artifact->expected_version(), $installed['version'] ) ) {
			return 'installed_version_mismatch';
		}
		if ( null !== $baseline && $baseline['active'] !== $installed['active'] ) {
			return 'activation_state_changed';
		}
		if ( 'install' === $deployment->operation && $installed['active'] ) {
			return 'activation_state_changed';
		}
		if ( 'install' === $deployment->operation && ! $this->target->adopt( $deployment ) ) {
			$this->reason = new AdmittedFailureReason( FailureReason::ADOPT_FAILED );
			return 'persistence_uncertain';
		}
		return 'deployed';
	}

	/** @param array{identifier:string,version:string,active:bool}|null $baseline */
	private function classify_execution_failure( BranchDeploymentDeclaration $deployment, ?array $baseline, CorePackageExecutionFailure $failure ): string {
		if ( null === $baseline ) {
			if ( 'install' !== $deployment->operation ) {
				return 'restoration_uncertain';
			}
			if ( CorePackageExecutionFailure::WORDPRESS_REFUSED !== $failure && CorePackageExecutionFailure::WORDPRESS_FAILED !== $failure ) {
				return 'restoration_uncertain';
			}
			$raw_target_directory = $this->target->target_directory( $deployment );
			$target_directory     = rtrim( $raw_target_directory, '/\\' );
			if ( '' === trim( $raw_target_directory ) || '' === $target_directory ) {
				return 'restoration_uncertain';
			}
			clearstatcache( true, $target_directory );
			if ( file_exists( $target_directory ) || is_link( $target_directory ) ) {
				return 'restoration_uncertain';
			}
			return 'upgrader_failed';
		}
		$current = $this->target->baseline_now( $deployment, $baseline );
		if ( null === $current || $current['version'] !== $baseline['version'] || $current['active'] !== $baseline['active'] ) {
			$this->reason = new AdmittedFailureReason( null === $current ? FailureReason::BASELINE_UNREADABLE : FailureReason::BASELINE_CHANGED );
			return 'restoration_uncertain';
		}
		return match ( $failure ) {
			CorePackageExecutionFailure::WORDPRESS_RESTORED,
			CorePackageExecutionFailure::WORDPRESS_REFUSED,
			CorePackageExecutionFailure::WORDPRESS_FAILED => 'upgrader_failed',
			default => 'restoration_uncertain',
		};
	}

	private function cleanup_locked_artifact( AdmittedBranchArtifact $artifact ): void {
		try {
			$this->cleanup_attempted = true;
			$artifact->cleanup();
		} catch ( Throwable $cleanup_failure ) {
			$this->cleanup_failed = true;
			$this->reason       ??= ReasonedFailure::find( $cleanup_failure )?->as_reason()
				?? new AdmittedFailureReason( FailureReason::CLEANUP_ALSO_FAILED );
		}
	}

	private function handle_failure( Throwable $failure ): string {
		$this->reason ??= $this->reason_of( $failure );
		if ( null !== $this->artifact && ! $this->cleanup_attempted ) {
			try {
				$this->cleanup_attempted = true;
				$this->artifact->cleanup();
			} catch ( Throwable $cleanup_failure ) {
				$this->cleanup_failed = true;
				$this->reason       ??= ReasonedFailure::find( $cleanup_failure )?->as_reason();
			}
		}
		if ( $this->is_ambiguous( $failure ) ) {
			$this->last_reason = $this->with_cleanup( $this->reason_of( $failure ), $this->cleanup_failed );
			throw $failure;
		}
		return $this->outcome_for_failure( $failure );
	}

	private function finish_journal( string $outcome ): void {
		try {
			if ( null !== $this->last_reason && $this->journal instanceof AdmittedAttemptReasonJournal ) {
				$this->journal->finish_with_reason( $outcome, $this->last_reason );
			} else {
				$this->journal->finish( $outcome );
			}
		} catch ( Throwable $terminal_failure ) {
			if ( $this->is_ambiguous( $terminal_failure ) ) {
				$this->last_reason = $this->with_cleanup( $this->reason_of( $terminal_failure ), $this->cleanup_failed );
			}
			throw $terminal_failure;
		}
	}

	private function outcome_for_failure( Throwable $failure ): string {
		return $this->fenced ? 'interrupted' : ( $failure instanceof AdmittedBranchStageFailure ? $failure->outcome_code : $this->stage );
	}

	/** A cleanup that failed beside another cause is recorded with it; it never replaces that cause. */
	private function with_cleanup( ?AdmittedFailureReason $reason, bool $cleanup_failed ): ?AdmittedFailureReason {
		return $cleanup_failed ? ( $reason ?? new AdmittedFailureReason( FailureReason::CLEANUP_ALSO_FAILED ) )->with_cleanup( null ) : $reason;
	}

	/** Maps only the failure's own closed type or reason; a message is never read. */
	private function reason_of( ?Throwable $failure ): ?AdmittedFailureReason {
		return match ( true ) {
			$failure instanceof ReasonedFailure => $failure->as_reason(),
			$this->is_ambiguous( $failure ) => $failure->as_reason(),
			default => null,
		};
	}

	/** @phpstan-assert-if-true AdmittedBranchDurabilityFailure|BranchDeploymentJournalFailure|BranchDeploymentLockReleaseFailure|BranchDeploymentLockStorageFailure $failure */
	private function is_ambiguous( ?Throwable $failure ): bool {
		return $failure instanceof AdmittedBranchDurabilityFailure
			|| $failure instanceof BranchDeploymentJournalFailure
			|| $failure instanceof BranchDeploymentLockReleaseFailure
			|| $failure instanceof BranchDeploymentLockStorageFailure;
	}
}
