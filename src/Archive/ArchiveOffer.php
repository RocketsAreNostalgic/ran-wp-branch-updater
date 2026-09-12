<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Archive;

use Closure;
use RuntimeException;

final readonly class ArchiveOffer {
	/** @var Closure(string,int):void */
	private Closure $copyTo;

	/**
	 * $copyTo is a provider implementation boundary, not a generic network callback framework.
	 * It must stop acquisition before writing more than $maximumArtifactBytes.
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
		$this->copyTo = $copyTo;
	}

	public function acquire( string $destination, int $maximumArtifactBytes ): void {
		if ( $maximumArtifactBytes < 1 ) {
			throw new RuntimeException( 'Maximum artifact bytes is invalid.' );
		}
		( $this->copyTo )( $destination, $maximumArtifactBytes );
	}

	public function verifyCurrentHead(): void {
		( $this->verifyHead )();
	}
}
