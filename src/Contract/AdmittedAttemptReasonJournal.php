<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

use RAN\WPBranchUpdater\V1\Runtime\AdmittedFailureReason;

/**
 * Optional capability. When a journal implements it, the runner calls
 * finish_with_reason() instead of finish() for a failure that has a reason,
 * with the same terminal code. The record is untrusted display text.
 */
interface AdmittedAttemptReasonJournal extends AdmittedAttemptJournal {
	public function finish_with_reason( string $code, AdmittedFailureReason $reason ): void;
}
