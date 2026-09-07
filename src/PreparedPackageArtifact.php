<?php

declare(strict_types=1);

namespace RAN\BranchDeployment;

/** Immutable archive facts required by the scoped WordPress mutation. */
interface PreparedPackageArtifact {
	public function getPath(): string;

	public function getExpectedVersion(): string;

	public function assertUnchanged(): void;
}
