<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RuntimeException;
use Throwable;

final class AdmittedBranchDurabilityFailure extends RuntimeException {
	public function __construct( string $message = 'Branch deployment durability is uncertain.', ?Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
	}
}
