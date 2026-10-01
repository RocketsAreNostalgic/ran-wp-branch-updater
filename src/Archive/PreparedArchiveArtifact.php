<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Archive;

use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;

final readonly class PreparedArchiveArtifact implements AdmittedBranchArtifact {
	public function __construct( private PreparedArchive $archive ) {}

	public function resolved_ref(): string {
		return $this->archive->resolved_ref;
	}

	public function expected_version(): string {
		return $this->archive->version;
	}

	public function assert_unchanged(): void {
		$this->archive->assert_unchanged();
	}

	public function cleanup(): void {
		$this->archive->cleanup();
	}

	public function archive(): PreparedArchive {
		return $this->archive;
	}
}
