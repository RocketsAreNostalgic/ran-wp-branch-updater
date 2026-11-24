<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RuntimeException;
use Throwable;

/** Deployment durability could not be proved, so execution state is uncertain. */
final class AdmittedBranchDurabilityFailure extends RuntimeException {
	public function __construct( string $message = 'Branch deployment durability is uncertain.', ?Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
	}

	/** Closed failure reason; never reads exception messages or chained causes. */
	public function reason(): FailureReason {
		return FailureReason::DURABILITY_UNCERTAIN;
	}

	public function as_reason(): AdmittedFailureReason {
		return new AdmittedFailureReason( $this->reason() );
	}
}
