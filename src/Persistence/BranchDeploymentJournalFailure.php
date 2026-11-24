<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Persistence;

use RAN\WPBranchUpdater\V1\Runtime\AdmittedFailureReason;
use RAN\WPBranchUpdater\V1\Runtime\FailureReason;
use RuntimeException;

/** A persistence failure means the durable execution state is unknown. */
final class BranchDeploymentJournalFailure extends RuntimeException {
	/** Closed failure reason; never reads exception messages or chained causes. */
	public function reason(): FailureReason {
		return FailureReason::JOURNAL_UNCERTAIN;
	}

	public function as_reason(): AdmittedFailureReason {
		return new AdmittedFailureReason( $this->reason() );
	}
}
