<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

interface AdmittedBranchArtifact {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function resolvedRef(): string;
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function expectedVersion(): string;
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function assertUnchanged(): void;
	public function cleanup(): void;
}
