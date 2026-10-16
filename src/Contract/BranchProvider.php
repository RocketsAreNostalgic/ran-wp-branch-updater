<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Contract;

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;

interface BranchProvider {
	/** Explicit opt-in; historical acquisition retains fresh identity/access and exact-ref checks. */
	public function supports_selected_commit(): bool;

	/** Must resolve $deployment->expected_head and reject it when $branch has advanced. Historical selected_commit instead requires exact equality and a fresh final access verifier. */
	public function prepare( BranchDeploymentDeclaration $deployment ): ArchiveOffer;
}
