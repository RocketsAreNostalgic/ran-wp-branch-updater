<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;

interface PackageExecutor {
	/** Run target/maintenance/version admission before the durable mutation fence. */
	public function preflight( BranchDeploymentDeclaration $deployment, PreparedArchive $archive ): array;
	public function execute( BranchDeploymentDeclaration $deployment, PreparedArchive $archive ): void;
}
