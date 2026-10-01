<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

interface AdmittedBranchArtifact {

	public function resolved_ref(): string;

	public function expected_version(): string;

	public function assert_unchanged(): void;
	public function cleanup(): void;
}
