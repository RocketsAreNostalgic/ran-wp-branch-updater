<?php

declare(strict_types=1);

use RAN\WPBranchUpdater\V1\{BranchDeploymentOperation, BranchDeploymentPackage, BranchProvider, FileAttemptStore, MutationLock, PackageExecutor, WordPressPackageExecutor, WordPressUpdaterLock};

return static function (
	BranchProvider $provider,
	FileAttemptStore $attempts,
	string $archiveDirectory,
	?PackageExecutor $executor = null,
	?MutationLock $lock = null
): BranchDeploymentPackage {
	return BranchDeploymentPackage::forStandalone(
		new BranchDeploymentOperation(
			$provider,
			$attempts,
			$executor ?? new WordPressPackageExecutor(),
			$archiveDirectory,
			$lock ?? new WordPressUpdaterLock()
		)
	);
};
