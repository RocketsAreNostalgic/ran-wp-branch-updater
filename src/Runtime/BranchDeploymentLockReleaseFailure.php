<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RuntimeException;

/** The exact mutation lock token could not be released, so state is uncertain. */
final class BranchDeploymentLockReleaseFailure extends RuntimeException {
	/** Closed failure reason; never reads exception messages or chained causes. */
	public function reason(): FailureReason {
		return FailureReason::LOCK_RELEASE;
	}

	public function as_reason(): AdmittedFailureReason {
		return new AdmittedFailureReason( $this->reason() );
	}
}
