<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;

interface PackageExecutor {
	/** Absolute directory WordPress/the executor will overwrite, including an absent install target. */
	public function target_directory( BranchDeploymentDeclaration $deployment ): string;

	/**
	 * Run target/maintenance/version admission before the durable mutation fence.
	 *
	 * @return array<array-key,mixed> Executor-specific observations; the runner does not consume this result.
	 */
	public function preflight( BranchDeploymentDeclaration $deployment, PreparedArchive $archive ): array;
	public function execute( BranchDeploymentDeclaration $deployment, PreparedArchive $archive ): void;
}
