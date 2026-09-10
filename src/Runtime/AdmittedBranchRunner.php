<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedPackageExecutor;
use RAN\WPBranchUpdater\V1\Contract\AdmittedTargetFacts;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Persistence\BranchDeploymentJournalFailure;
use RuntimeException;
use Throwable;

/** Shared terminal sequencing for one already-admitted branch deployment. */
final class AdmittedBranchRunner {
	private bool $consumed = false;
	public function __construct(
		private AdmittedAttemptJournal $journal,
		private AdmittedArchiveSource $archives,
		private AdmittedTargetFacts $target,
		private AdmittedPackageExecutor $executor,
		private MutationLock $lock
	) {}

	/** Returns the closed outcome only after the attempt journal has finished. */
	public function run( BranchDeploymentDeclaration $deployment ): string {
		if ( $this->consumed ) {
			throw new RuntimeException( 'An admitted deployment runner is already consumed.' );
		}
		$this->consumed   = true;
		$artifact         = null;
		$fenced           = false;
		$cleanupAttempted = false;
		$stage            = 'policy_blocked';
		try {
			$this->target->assertMutationAllowed();
			$baseline = $this->target->frozenTarget( $deployment, true );
			$stage    = 'preflight_failed';
			$artifact = $this->archives->prepare( $deployment, $baseline );
			if ( null !== $baseline && version_compare( $artifact->expectedVersion(), $baseline['version'], '<' ) ) {
				throw new AdmittedBranchStageFailure( 'downgrade_blocked' );
			}
			$this->executor->preflight( $deployment, $artifact );
			$this->journal->recordResolvedRef( $artifact->resolvedRef() );
			$stage   = 'lock_unavailable';
			$outcome = $this->lock->run(
				function () use ( $deployment, $baseline, $artifact, &$fenced, &$cleanupAttempted, &$stage ): string {
					$primary = null;
					try {
						$stage           = 'policy_blocked';
						$currentBaseline = $this->target->frozenTarget( $deployment, false );
						if ( null !== $currentBaseline && version_compare( $artifact->expectedVersion(), $currentBaseline['version'], '<' ) ) {
							throw new AdmittedBranchStageFailure( 'downgrade_blocked' );
						}
						$stage = 'provider_failed';
						$this->archives->verifyCurrentHead();
						$stage = 'archive_integrity_failed';
						$artifact->assertUnchanged();
						$stage = 'policy_blocked';
						if ( $this->target->maintenanceActive() ) {
							return 'deployment_maintenance_active';
						}
						$this->target->assertMutationAllowed();
						$this->executor->preflight( $deployment, $artifact );
						$this->journal->markMutationStarted();
						$fenced = true;
						$result = $this->executor->execute( $deployment, $baseline, $artifact );
						if ( $this->target->maintenanceActive() ) {
							return 'maintenance_remaining';
						}
						if ( $result->isSuccessful() ) {
							if ( 'update' === $deployment->operation ) {
								$this->target->recheckManaged( $deployment );
							}
							$installed = $this->target->installed( $deployment );
							if ( ! hash_equals( $artifact->expectedVersion(), $installed['version'] ) ) {
								return 'installed_version_mismatch';
							}
							if ( null !== $baseline && $baseline['active'] !== $installed['active'] ) {
								return 'activation_state_changed';
							}
							if ( 'install' === $deployment->operation && $installed['active'] ) {
								return 'activation_state_changed';
							}
							if ( 'install' === $deployment->operation && ! $this->target->adopt( $deployment ) ) {
								return 'persistence_uncertain';
							}
							return 'deployed';
						}
						$current = null === $baseline ? null : $this->target->baselineNow( $deployment, $baseline );
						if ( null === $current || $current['version'] !== $baseline['version'] || $current['active'] !== $baseline['active'] ) {
							return 'restoration_uncertain';
						}
						return match ( $result->getFailure() ) {
							CorePackageExecutionFailure::WORDPRESS_RESTORED => 'activation_failed',
							CorePackageExecutionFailure::WORDPRESS_REFUSED,
							CorePackageExecutionFailure::WORDPRESS_FAILED => 'upgrader_failed',
							default => 'restoration_uncertain',
						};
					} catch ( Throwable $failure ) {
						$primary = $failure;
						throw $failure;
					} finally {
						try {
							$cleanupAttempted = true;
							$artifact->cleanup();
						} catch ( Throwable $cleanupFailure ) {
							if ( $primary instanceof AdmittedBranchDurabilityFailure || $this->isAmbiguous( $primary ) ) {
								throw $primary;
							}
							$stage = 'archive_cleanup_failed';
							throw $cleanupFailure;
						}
					}
				}
			);
		} catch ( Throwable $failure ) {
			if ( null !== $artifact && ! $cleanupAttempted ) {
				try {
					$cleanupAttempted = true;
					$artifact->cleanup();
				} catch ( Throwable $cleanupFailure ) {
					if ( $failure instanceof AdmittedBranchDurabilityFailure ) {
						throw $failure;
					}
					if ( ! $this->isAmbiguous( $failure ) ) {
						$stage   = 'archive_cleanup_failed';
						$failure = $cleanupFailure;
					}
				}
			}
			if ( $this->isAmbiguous( $failure ) ) {
				throw $failure;
			}
			$outcome = $fenced ? 'interrupted' : ( $failure instanceof AdmittedBranchStageFailure ? $failure->outcomeCode : $stage );
		}
		$this->journal->finish( $outcome );
		return $outcome;
	}

	private function isAmbiguous( ?Throwable $failure ): bool {
		return $failure instanceof AdmittedBranchDurabilityFailure
			|| $failure instanceof BranchDeploymentJournalFailure
			|| $failure instanceof BranchDeploymentLockReleaseFailure
			|| $failure instanceof BranchDeploymentLockStorageFailure;
	}
}
