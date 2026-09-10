<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

use RAN\WPBranchUpdater\V1\Runtime\Deployment;

interface AdmittedArchiveSource {
	/** @param array{identifier:string,version:string,active:bool}|null $baseline */
	public function prepare( Deployment $deployment, ?array $baseline ): AdmittedBranchArtifact;
	public function verifyCurrentHead(): void;
}
