<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RuntimeException;

final class AdmittedBranchStageFailure extends RuntimeException {
	public function __construct( public readonly string $outcomeCode ) {
		parent::__construct( $outcomeCode );
	}
}
