<?php

declare(strict_types=1);

use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Contract\BranchProvider;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Contract\PackageExecutor;
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore;
use RAN\WPBranchUpdater\V1\Runtime\BranchUpdater;
use RAN\WPBranchUpdater\V1\Runtime\StandaloneBranchRunner;
use RAN\WPBranchUpdater\V1\WordPress\WordPressPackageExecutor;
use RAN\WPBranchUpdater\V1\WordPress\WordPressUpdaterLock;

return static function (
	BranchProvider $provider,
	FileAttemptStore $attempts,
	string $archiveDirectory,
	?PackageExecutor $executor = null,
	?MutationLock $lock = null,
	mixed $maximumArtifactBytes = PreparedArchive::DEFAULT_MAXIMUM_ARTIFACT_BYTES
): BranchUpdater {
	return BranchUpdater::forStandalone(
		new StandaloneBranchRunner(
			$provider,
			$attempts,
			$executor ?? new WordPressPackageExecutor(),
			$archiveDirectory,
			$lock ?? new WordPressUpdaterLock(),
			$maximumArtifactBytes
		)
	);
};
