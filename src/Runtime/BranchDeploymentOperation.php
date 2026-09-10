<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RAN\WPBranchUpdater\V1\Contract\BranchProvider;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Contract\PackageExecutor;
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptJournal;
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore;

/** Standalone consumer wiring around the shared admitted runner. */
final class BranchDeploymentOperation {
	public function __construct(
		private readonly BranchProvider $provider,
		private readonly FileAttemptStore $attempts,
		private readonly PackageExecutor $executor,
		private readonly string $archiveDirectory,
		private readonly MutationLock $lock
	) {}

	/** Returns the closed terminal outcome; normal failures remain terminal outcomes. */
	public function execute( Deployment $deployment ): string {
		$this->attempts->begin( $deployment );
		$target = new StandaloneTargetFacts( $this->executor );
		$runner = new AdmittedBranchRunner(
			new FileAttemptJournal( $this->attempts, $deployment->attemptId ),
			new ProviderArchiveSource( $this->provider, $this->archiveDirectory ),
			$target,
			new StandalonePackageExecutor( $this->executor, $target ),
			$this->lock
		);
		return $runner->run( $deployment );
	}
}
