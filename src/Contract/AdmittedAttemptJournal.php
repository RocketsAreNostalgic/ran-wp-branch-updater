<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

interface AdmittedAttemptJournal {
	public function record_resolved_ref( string $ref ): void;
	public function mark_mutation_started(): void;
	public function finish( string $code ): void;
}
