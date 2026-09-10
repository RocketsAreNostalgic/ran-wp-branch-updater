<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

use RAN\WPBranchUpdater\V1\Runtime\Deployment;

interface AdmittedTargetFacts {
	public function assertMutationAllowed(): void;
	/** @return array{identifier:string,version:string,active:bool}|null */
	public function frozenTarget( Deployment $deployment, bool $deferExisting ): ?array;
	public function maintenanceActive(): bool;
	public function recheckManaged( Deployment $deployment ): void;
	/** @return array{identifier:string,version:string,active:bool} */
	public function installed( Deployment $deployment ): array;
	/** @param array{identifier:string,version:string,active:bool} $baseline @return array{identifier:string,version:string,active:bool}|null */
	public function baselineNow( Deployment $deployment, array $baseline ): ?array;
	public function adopt( Deployment $deployment ): bool;
}
