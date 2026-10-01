<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RuntimeException;

final class AdmittedBranchStageFailure extends RuntimeException {
	// phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Promotion creates the public readonly outcome property as well as the exception message.
	public function __construct( public readonly string $outcomeCode ) {
		parent::__construct( $outcomeCode );
	}
}
