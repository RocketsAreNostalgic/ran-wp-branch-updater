<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Persistence;

use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockReleaseFailure;
use RAN\WPBranchUpdater\V1\Runtime\FailureReason;
use RAN\WPBranchUpdater\V1\Runtime\ReasonedFailure;

final class FileMutationLock implements MutationLock {
	public function __construct( private readonly string $path ) {}

	public function run( callable $operation ): mixed {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- flock requires the native local descriptor held through the mutation callback.
		$handle = fopen( $this->path, 'c+' );
		if ( false === $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A closed reason id is recorded outcome data, not rendered output.
			throw new ReasonedFailure( 'Mutation lock is held.', FailureReason::LOCK_HELD->value );
		}
		try {
			return $operation();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the same native lock descriptor only after unlocking; preserve release-failure ordering.
			if ( ! flock( $handle, LOCK_UN ) || ! fclose( $handle ) ) {
				throw new BranchDeploymentLockReleaseFailure( 'Mutation lock could not be released.' );
			}
		}
	}
}
