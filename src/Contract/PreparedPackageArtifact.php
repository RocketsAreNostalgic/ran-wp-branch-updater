<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

/** Immutable archive facts required by the scoped WordPress mutation. */
interface PreparedPackageArtifact {

	public function get_path(): string;

	public function get_expected_version(): string;

	public function assert_unchanged(): void;
}
