<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

final class AdmittedBranchStageFailure extends ReasonedFailure {
	// phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Promotion creates the public readonly outcome property; the parent additionally validates reason and detail.
	public function __construct( public readonly string $outcome_code, ?string $reason = null, ?string $detail = null ) {
		parent::__construct( $outcome_code, $reason, $detail );
	}
}
