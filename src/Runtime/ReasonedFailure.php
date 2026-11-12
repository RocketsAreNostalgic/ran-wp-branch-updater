<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * A RuntimeException that also names a closed reason and an optional screened
 * detail. Its message and code are the caller's own and are never recorded.
 */
class ReasonedFailure extends RuntimeException {
	public readonly ?string $reason;
	public readonly ?string $detail;

	/** @throws InvalidArgumentException When $reason is not in the closed FailureReason list. */
	public function __construct( string $message, ?string $reason = null, ?string $detail = null, int $code = 0, ?Throwable $previous = null ) {
		if ( null !== $reason && null === FailureReason::tryFrom( $reason ) ) {
			throw new InvalidArgumentException( 'The failure reason is not in the closed list.' );
		}
		$this->reason = $reason;
		$this->detail = null === $detail ? null : AdmittedFailureReason::screen_detail( $detail );
		parent::__construct( $message, $code, $previous );
	}

	/** The nearest reason-carrying failure in the exception chain, if any. */
	public static function find( Throwable $failure ): ?self {
		for ( $depth = 0; null !== $failure && $depth < 8; ++$depth, $failure = $failure->getPrevious() ) {
			if ( $failure instanceof self && null !== $failure->reason ) {
				return $failure;
			}
		}
		return null;
	}

	public function as_reason(): ?AdmittedFailureReason {
		return null === $this->reason ? null : new AdmittedFailureReason( FailureReason::from( $this->reason ), $this->detail );
	}
}
