<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Runtime\Deployment;

interface BranchProvider {
	/** Must resolve $deployment->expectedHead and reject it when $branch has advanced. */
	public function prepare( Deployment $deployment ): ArchiveOffer;
}
