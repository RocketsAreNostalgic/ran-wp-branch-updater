<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RuntimeException;

/** A WordPress updater-lock database read/write could not be reconciled. */
final class BranchDeploymentLockStorageFailure extends RuntimeException {
	/** Closed failure reason; never reads exception messages or chained causes. */
	public function reason(): FailureReason {
		return FailureReason::LOCK_STORAGE;
	}

	public function as_reason(): AdmittedFailureReason {
		return new AdmittedFailureReason( $this->reason() );
	}
}
