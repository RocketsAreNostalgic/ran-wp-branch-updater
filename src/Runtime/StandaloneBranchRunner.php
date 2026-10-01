<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Contract\BranchProvider;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Contract\PackageExecutor;
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptJournal;
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore;

/** Standalone consumer wiring around the shared admitted runner. */
final class StandaloneBranchRunner {
	public function __construct(
		private readonly BranchProvider $provider,
		private readonly FileAttemptStore $attempts,
		private readonly PackageExecutor $executor,
		private readonly string $archive_directory,
		private readonly MutationLock $lock,
		private readonly mixed $maximum_artifact_bytes = PreparedArchive::DEFAULT_MAXIMUM_ARTIFACT_BYTES
	) {}

	/** Returns the closed terminal outcome; normal failures remain terminal outcomes. */
	public function execute( BranchDeploymentDeclaration $deployment ): string {
		$this->attempts->begin( $deployment );
		$target = new StandaloneTargetFacts( $this->executor );
		$runner = new AdmittedBranchRunner(
			new FileAttemptJournal( $this->attempts, $deployment->attemptId ),
			new ProviderArchiveSource( $this->provider, $this->archive_directory, $this->maximum_artifact_bytes ),
			$target,
			new StandalonePackageExecutor( $this->executor, $target ),
			$this->lock
		);
		return $runner->run( $deployment );
	}
}
