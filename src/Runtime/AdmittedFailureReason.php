<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

/**
 * Optional, bounded refinement of one terminal outcome code.
 *
 * `detail` is untrusted display text: consumers must escape it. It has passed
 * screen_detail(), the only screening function every detail goes through.
 */
final readonly class AdmittedFailureReason {
	public const MAXIMUM_DETAIL_LENGTH = 64;
	private const MAXIMUM_INPUT_LENGTH = 1024;

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
	 * Return the detail only if the COMPLETE input is safe, else null. Unsafe
	 * input is dropped, never redacted or partly kept, and only a safe input is
	 * then bounded to MAXIMUM_DETAIL_LENGTH. Dropped: non-printable and non-ASCII
	 * text, markup, paths, URLs, userinfo, credential-looking words, digest-shaped
	 * text (digest prefixes, hex runs with or without separators, long mixed
	 * tokens) and anything outside [A-Za-z0-9 ._-]. A harmless word that merely
	 * contains one of those patterns (for example "tokenizer") is dropped too.
	 */
	public static function screen_detail( string $detail ): ?string {
		if ( '' === $detail || strlen( $detail ) > self::MAXIMUM_INPUT_LENGTH ) {
			return null;
		}
		if ( 1 !== preg_match( '/\A[A-Za-z0-9 ._-]+\z/D', $detail ) ) {
			return null;
		}
		$unsafe = array(
			'/[<>&:\/\\\\~@]|\.\./',
			'/token|secret|passw|credential|bearer|authoriz|cookie|session|signature|api[ _.-]?key|private[ _.-]?key|\bkey\b|[_.-]key\b|\bkey[_.-]/i',
			'/gh[pousr]_|github_pat|glpat|xox[abprs]|\beyJ|\bsk-|\b(?:AKIA|ASIA)[A-Z0-9]{12,}/i',
			'/(?:sha|md)-?\d/i',
			'/\b(?=[0-9a-f]*\d)(?=[0-9a-f]*[a-f])[0-9a-f]{7,}\b/i',
		);
		foreach ( $unsafe as $pattern ) {
			if ( 1 === preg_match( $pattern, $detail ) ) {
				return null;
			}
		}
		if ( 1 === preg_match( '/[0-9a-f]{16}/i', str_replace( array( ' ', '.', '_', '-' ), '', $detail ) ) ) {
			return null;
		}
		foreach ( (array) preg_split( '/[ .]+/', $detail ) as $word ) {
			$word = (string) $word;
			if ( ( strlen( $word ) >= 40 && 1 !== preg_match( '/\A[a-z]+(?:[_-][a-z]+)*\z/D', $word ) ) || ( strlen( $word ) >= 24 && 1 === preg_match( '/\d/', $word ) && 1 === preg_match( '/[A-Za-z]/', $word ) ) ) {
				return null;
			}
		}
		$bounded = rtrim( substr( $detail, 0, self::MAXIMUM_DETAIL_LENGTH ) );
		return '' === $bounded ? null : $bounded;
	}
}
