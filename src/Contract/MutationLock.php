<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

interface MutationLock {
	public function run( callable $operation ): mixed;
}
