<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

/** Immutable archive facts required by the scoped WordPress mutation. */
interface PreparedPackageArtifact {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function getPath(): string;
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function getExpectedVersion(): string;
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function assertUnchanged(): void;
}
