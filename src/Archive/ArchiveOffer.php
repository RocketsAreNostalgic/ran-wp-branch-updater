<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Archive;

use Closure;
use RuntimeException;

final readonly class ArchiveOffer {
	/** @var Closure(string,int):void */
	private Closure $copy_to;

	/**
	 * $copyTo is a provider implementation boundary, not a generic network callback framework.
	 * It must stop acquisition before writing more than $maximum_artifact_bytes.
	 *
	 * @param Closure(string,int):void $copyTo
	 */
	public function __construct(
		public string $provider,
		public string $repositoryId,
		public string $resolvedRef,
		Closure $copyTo,
		private Closure $verifyHead
	) {
		$this->copy_to = $copyTo;
	}

	public function acquire( string $destination, int $maximum_artifact_bytes ): void {
		if ( $maximum_artifact_bytes < 1 ) {
			throw new RuntimeException( 'Maximum artifact bytes is invalid.' );
		}
		( $this->copy_to )( $destination, $maximum_artifact_bytes );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function verifyCurrentHead(): void {
		( $this->verifyHead )();
	}
}
