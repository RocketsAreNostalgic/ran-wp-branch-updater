<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;

interface AdmittedArchiveSource {
	/** @param array{identifier:string,version:string,active:bool}|null $baseline */
	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact;
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function verifyCurrentHead(): void;
}
