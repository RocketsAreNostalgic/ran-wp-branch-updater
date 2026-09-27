<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Persistence;

use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal;

final readonly class FileAttemptJournal implements AdmittedAttemptJournal {
	public function __construct( private FileAttemptStore $store, private string $attempt_id ) {}

	public function record_resolved_ref( string $ref ): void {
		$this->store->resolved( $this->attempt_id, $ref );
	}

	public function mark_mutation_started(): void {
		$this->store->fence( $this->attempt_id );
	}

	public function finish( string $code ): void {
		$state = match ( $code ) {
			'deployed', 'already_managed' => 'succeeded',
			'interrupted', 'maintenance_remaining', 'installed_version_mismatch', 'activation_state_changed', 'persistence_uncertain', 'restoration_uncertain' => 'needs_attention',
			default => 'failed',
		};
		$this->store->finish( $this->attempt_id, $state, $code );
	}
}
