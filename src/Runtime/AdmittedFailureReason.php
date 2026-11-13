<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RAN\UpdaterSupport\V1\FailureText;

/**
 * Optional, bounded refinement of one terminal outcome code.
 *
 * `detail` is untrusted display text: consumers must escape it. It has passed
 * screen_detail(), the only screening function every detail goes through.
 */
final readonly class AdmittedFailureReason {
	public const MAXIMUM_DETAIL_LENGTH = 64;

	public ?string $detail;
	public ?string $primary_outcome;

	/**
	 * @param ?string $primary_outcome The outcome a failing cleanup superseded, when it did.
	 * @param bool    $cleanup_failed  Cleanup also failed, whatever the recorded outcome is.
	 */
	public function __construct(
		public FailureReason $reason,
		?string $detail = null,
		?string $primary_outcome = null,
		public bool $cleanup_failed = false
	) {
		$this->detail          = null === $detail ? null : self::screen_detail( $detail );
		$this->primary_outcome = null !== $primary_outcome && 1 === preg_match( '/\A[a-z][a-z0-9_]{0,47}\z/D', $primary_outcome ) ? $primary_outcome : null;
	}

	public function with_cleanup( ?string $primary_outcome ): self {
		return new self( $this->reason, $this->detail, $primary_outcome ?? $this->primary_outcome, true );
	}

	/**
	 * Return the detail only if the COMPLETE input passes, else null; only a passing
	 * input is then bounded to MAXIMUM_DETAIL_LENGTH. Unsafe input is dropped, never
	 * redacted or partly kept.
	 *
	 * The whole input must first be this caller's charset allow-list
	 * ([A-Za-z0-9 ._-], ASCII only); any other character drops it. The shared
	 * `FailureText::strict()` shape screen then runs on the whole input and bounds
	 * the passing result to MAXIMUM_DETAIL_LENGTH. The shared screener is a
	 * safety net by shape, not a proof. The real control is that every call site
	 * passes only closed literals or allow-listed values, never message text; a test
	 * guards the call sites.
	 */
	public static function screen_detail( string $detail ): ?string {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9 ._-]+\z/D', $detail ) ) {
			return null;
		}
		return FailureText::strict( $detail, self::MAXIMUM_DETAIL_LENGTH );
	}
}
