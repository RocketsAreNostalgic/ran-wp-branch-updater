<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptReasonJournal;
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

	/** Returns the closed outcome only after the attempt journal has finished. */
	public function run( BranchDeploymentDeclaration $deployment ): string {
		if ( $this->consumed ) {
			throw new RuntimeException( 'An admitted deployment runner is already consumed.' );
		}
		$this->consumed    = true;
		$artifact          = null;
		$fenced            = false;
		$cleanup_attempted = false;
		$stage             = 'policy_blocked';
		$reason            = null;
		try {
			$this->target->assert_mutation_allowed();
			$baseline = $this->target->frozen_target( $deployment, true );
			$stage    = 'preflight_failed';
			$artifact = $this->archives->prepare( $deployment, $baseline );
			if ( null !== $deployment->selected_commit && ! hash_equals( $deployment->selected_commit, $artifact->resolved_ref() ) ) {
				throw new AdmittedBranchStageFailure( 'provider_failed', FailureReason::REF_MISMATCH->value );
			}
			if ( null !== $baseline && version_compare( $artifact->expected_version(), $baseline['version'], '<' ) ) {
				throw new AdmittedBranchStageFailure( 'downgrade_blocked' );
			}
			$this->executor->preflight( $deployment, $artifact );
			$this->journal->record_resolved_ref( $artifact->resolved_ref() );
			$stage   = 'lock_unavailable';
			$outcome = $this->lock->run(
				function () use ( $deployment, $baseline, $artifact, &$fenced, &$cleanup_attempted, &$stage, &$reason ): string {
					$primary = null;
					try {
						$stage            = 'policy_blocked';
						$current_baseline = $this->target->frozen_target( $deployment, false );
						if ( null !== $current_baseline && version_compare( $artifact->expected_version(), $current_baseline['version'], '<' ) ) {
							throw new AdmittedBranchStageFailure( 'downgrade_blocked' );
						}
						$stage = 'provider_failed';
						$this->archives->verify_current_head();
						$stage = 'archive_integrity_failed';
						$artifact->assert_unchanged();
						$stage = 'policy_blocked';
						if ( $this->target->maintenance_active() ) {
							return 'deployment_maintenance_active';
						}
						$this->target->assert_mutation_allowed();
						$this->executor->preflight( $deployment, $artifact );
						$this->journal->mark_mutation_started();
						$fenced = true;
						$result = $this->executor->execute( $deployment, $baseline, $artifact );
						if ( $this->target->maintenance_active() ) {
							return 'maintenance_remaining';
						}
						if ( $result->is_successful() ) {
							if ( 'update' === $deployment->operation ) {
								$this->target->recheck_managed( $deployment );
							}
							$installed = $this->target->installed( $deployment );
							if ( null !== $deployment->expected_identifier && ! hash_equals( $deployment->expected_identifier, $installed['identifier'] ) ) {
								$reason = new AdmittedFailureReason( FailureReason::IDENTIFIER_MISMATCH );
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
								$reason = new AdmittedFailureReason( FailureReason::ADOPT_FAILED );
								return 'persistence_uncertain';
							}
							return 'deployed';
						}
						$reason  = new AdmittedFailureReason( FailureReason::for_core( $result->get_failure() ), $result->get_detail() );
						$current = null === $baseline ? null : $this->target->baseline_now( $deployment, $baseline );
						if ( null === $baseline || null === $current || $current['version'] !== $baseline['version'] || $current['active'] !== $baseline['active'] ) {
							if ( null !== $baseline ) {
								$reason = new AdmittedFailureReason( null === $current ? FailureReason::BASELINE_UNREADABLE : FailureReason::BASELINE_CHANGED );
							}
							return 'restoration_uncertain';
						}
						return match ( $result->get_failure() ) {
							CorePackageExecutionFailure::WORDPRESS_RESTORED => 'activation_failed',
							CorePackageExecutionFailure::WORDPRESS_REFUSED,
							CorePackageExecutionFailure::WORDPRESS_FAILED => 'upgrader_failed',
							default => 'restoration_uncertain',
						};
					} catch ( Throwable $failure ) {
						$primary = $failure;
						$reason  = $this->reason_of( $failure );
						throw $failure;
					} finally {
						try {
							$cleanup_attempted = true;
							$artifact->cleanup();
						} catch ( Throwable $cleanup_failure ) {
							if ( $primary instanceof AdmittedBranchDurabilityFailure || $this->is_ambiguous( $primary ) ) {
								throw $primary;
							}
							$stage = 'archive_cleanup_failed';
							throw $cleanup_failure;
						}
					}
				}
			);
		} catch ( Throwable $failure ) {
			$reason ??= $this->reason_of( $failure );
			if ( null !== $artifact && ! $cleanup_attempted ) {
				try {
					$cleanup_attempted = true;
					$artifact->cleanup();
				} catch ( Throwable $cleanup_failure ) {
					if ( $failure instanceof AdmittedBranchDurabilityFailure ) {
						$this->last_reason = $this->reason_of( $failure );
						throw $failure;
					}
					if ( ! $this->is_ambiguous( $failure ) ) {
						$stage   = 'archive_cleanup_failed';
						$failure = $cleanup_failure;
					}
				}
			}
			if ( $this->is_ambiguous( $failure ) ) {
				$this->last_reason = $this->reason_of( $failure );
				throw $failure;
			}
			$outcome = $fenced ? 'interrupted' : ( $failure instanceof AdmittedBranchStageFailure ? $failure->outcome_code : $stage );
		}
		$this->last_reason = $reason;
		if ( null !== $reason && $this->journal instanceof AdmittedAttemptReasonJournal ) {
			$this->journal->finish_with_reason( $outcome, $reason );
		} else {
			$this->journal->finish( $outcome );
		}
		return $outcome;
	}

	/** Maps only the failure's own closed type or reason; a message is never read. */
	private function reason_of( ?Throwable $failure ): ?AdmittedFailureReason {
		return match ( true ) {
			$failure instanceof ReasonedFailure => $failure->as_reason(),
			$failure instanceof BranchDeploymentLockStorageFailure => new AdmittedFailureReason( FailureReason::LOCK_STORAGE ),
			$failure instanceof BranchDeploymentLockReleaseFailure => new AdmittedFailureReason( FailureReason::LOCK_RELEASE ),
			$failure instanceof BranchDeploymentJournalFailure => new AdmittedFailureReason( FailureReason::JOURNAL_UNCERTAIN ),
			$failure instanceof AdmittedBranchDurabilityFailure => new AdmittedFailureReason( FailureReason::DURABILITY_UNCERTAIN ),
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
