<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Archive;

use Closure;
use RuntimeException;

final readonly class ArchiveOffer {
	/** @var Closure(string,int):void */
	private Closure $copy_to;

	/**
	 * $copy_to is a provider implementation boundary, not a generic network callback framework.
	 * It must stop acquisition before writing more than $maximum_artifact_bytes.
	 *
	 * @param Closure(string,int):void $copy_to
	 */
	public function __construct(
		public string $provider,
		public string $repository_id,
		public string $resolved_ref,
		Closure $copy_to,
		private Closure $verify_head
	) {
		$this->copy_to = $copy_to;
	}

	public function acquire( string $destination, int $maximum_artifact_bytes ): void {
		if ( $maximum_artifact_bytes < 1 ) {
			throw new RuntimeException( 'Maximum artifact bytes is invalid.' );
		}
		( $this->copy_to )( $destination, $maximum_artifact_bytes );
	}

	public function verify_current_head(): void {
		( $this->verify_head )();
	}
}
