<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Archive;

use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;

final readonly class PreparedArchiveArtifact implements AdmittedBranchArtifact {
	public function __construct( private PreparedArchive $archive ) {}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function resolvedRef(): string {
		return $this->archive->resolvedRef;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function expectedVersion(): string {
		return $this->archive->version;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function assertUnchanged(): void {
		$this->archive->assertUnchanged();
	}

	public function cleanup(): void {
		$this->archive->cleanup();
	}

	public function archive(): PreparedArchive {
		return $this->archive;
	}
}
