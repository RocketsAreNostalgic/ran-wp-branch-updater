<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;

interface AdmittedTargetFacts {
	public function assertMutationAllowed(): void;
	/** @return array{identifier:string,version:string,active:bool}|null */
	public function frozenTarget( BranchDeploymentDeclaration $deployment, bool $deferExisting ): ?array;
	public function maintenanceActive(): bool;
	public function recheckManaged( BranchDeploymentDeclaration $deployment ): void;
	/** @return array{identifier:string,version:string,active:bool} */
	public function installed( BranchDeploymentDeclaration $deployment ): array;
	/** @param array{identifier:string,version:string,active:bool} $baseline @return array{identifier:string,version:string,active:bool}|null */
	public function baselineNow( BranchDeploymentDeclaration $deployment, array $baseline ): ?array;
	public function adopt( BranchDeploymentDeclaration $deployment ): bool;
}
