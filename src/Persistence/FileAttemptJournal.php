<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Persistence;

use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal;

final readonly class FileAttemptJournal implements AdmittedAttemptJournal {
	public function __construct( private FileAttemptStore $store, private string $attemptId ) {}

	public function recordResolvedRef( string $ref ): void {
		$this->store->resolved( $this->attemptId, $ref );
	}

	public function markMutationStarted(): void {
		$this->store->fence( $this->attemptId );
	}

	public function finish( string $code ): void {
		$state = match ( $code ) {
			'deployed', 'already_managed' => 'succeeded',
			'interrupted', 'maintenance_remaining', 'installed_version_mismatch', 'activation_state_changed', 'persistence_uncertain', 'restoration_uncertain' => 'needs_attention',
			default => 'failed',
		};
		$this->store->finish( $this->attemptId, $state, $code );
	}
}
