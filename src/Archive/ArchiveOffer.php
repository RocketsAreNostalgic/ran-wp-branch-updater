<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Archive;

use Closure;

final readonly class ArchiveOffer {
	/** $copyTo is a provider implementation boundary, not a generic network callback framework. */
	public function __construct(
		public string $provider,
		public string $repositoryId,
		public string $resolvedRef,
		private Closure $copyTo,
		private Closure $verifyHead
	) {}
	public function acquire( string $destination ): void {
		( $this->copyTo )( $destination );
	}
	public function verifyCurrentHead(): void {
		( $this->verifyHead )();
	}
}
