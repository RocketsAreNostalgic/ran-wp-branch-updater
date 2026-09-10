<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

interface AdmittedAttemptJournal {
	public function recordResolvedRef( string $ref ): void;
	public function markMutationStarted(): void;
	public function finish( string $code ): void;
}
