<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;

interface AdmittedTargetFacts {

	public function assert_mutation_allowed(): void;
	/** @return array{identifier:string,version:string,active:bool}|null */

	public function frozen_target( BranchDeploymentDeclaration $deployment, bool $defer_existing ): ?array;

	public function maintenance_active(): bool;

	public function recheck_managed( BranchDeploymentDeclaration $deployment ): void;
	/** @return array{identifier:string,version:string,active:bool} */
	public function installed( BranchDeploymentDeclaration $deployment ): array;
	/**
	 * @param array{identifier:string,version:string,active:bool} $baseline
	 * @return array{identifier:string,version:string,active:bool}|null
	 */

	public function baseline_now( BranchDeploymentDeclaration $deployment, array $baseline ): ?array;
	public function adopt( BranchDeploymentDeclaration $deployment ): bool;
}
