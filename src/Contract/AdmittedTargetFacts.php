<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;

interface AdmittedTargetFacts {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function assertMutationAllowed(): void;
	/** @return array{identifier:string,version:string,active:bool}|null */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function frozenTarget( BranchDeploymentDeclaration $deployment, bool $deferExisting ): ?array;
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function maintenanceActive(): bool;
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function recheckManaged( BranchDeploymentDeclaration $deployment ): void;
	/** @return array{identifier:string,version:string,active:bool} */
	public function installed( BranchDeploymentDeclaration $deployment ): array;
	/** @param array{identifier:string,version:string,active:bool} $baseline @return array{identifier:string,version:string,active:bool}|null */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function baselineNow( BranchDeploymentDeclaration $deployment, array $baseline ): ?array;
	public function adopt( BranchDeploymentDeclaration $deployment ): bool;
}
