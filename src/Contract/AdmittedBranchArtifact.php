<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

interface AdmittedBranchArtifact {
	public function resolvedRef(): string;
	public function expectedVersion(): string;
	public function assertUnchanged(): void;
	public function cleanup(): void;
}
