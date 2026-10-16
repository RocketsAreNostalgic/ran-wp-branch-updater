<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;

interface AdmittedArchiveSource {
	/** @param array{identifier:string,version:string,active:bool}|null $baseline */
	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact;

	/** Recheck current source authority: branch head, or exact selected commit with fresh repository/access verification. */
	public function verify_current_head(): void;
}
