<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RAN\WPBranchUpdater\V1\Runtime\Deployment;

interface AdmittedPackageExecutor {
	public function preflight( Deployment $deployment, AdmittedBranchArtifact $artifact ): void;
	/** @param array{identifier:string,version:string,active:bool}|null $baseline */
	public function execute( Deployment $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult;
}
