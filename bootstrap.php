<?php

declare(strict_types=1);

use RAN\WPBranchUpdater\V1\Contract\BranchProvider;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Contract\PackageExecutor;
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentOperation;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentPackage;
use RAN\WPBranchUpdater\V1\WordPress\WordPressPackageExecutor;
use RAN\WPBranchUpdater\V1\WordPress\WordPressUpdaterLock;

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
