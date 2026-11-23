<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Persistence;

use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockReleaseFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockStorageFailure;
use RAN\WPBranchUpdater\V1\Runtime\FailureReason;
use RAN\WPBranchUpdater\V1\Runtime\ReasonedFailure;

final class FileMutationLock implements MutationLock {
	public function __construct( private readonly string $path ) {}

	public function run( callable $operation ): mixed {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- flock requires a native descriptor; translate the open warning to a fixed storage failure.
		$handle = @fopen( $this->path, 'c+' );
		if ( false === $handle ) {
			throw new BranchDeploymentLockStorageFailure( 'Mutation lock storage is unavailable.' );
		}
		$locked = false;
		try {
			$locked = flock( $handle, LOCK_EX | LOCK_NB, $would_block );
			if ( ! $locked ) {
				if ( 1 !== $would_block ) {
					throw new BranchDeploymentLockStorageFailure( 'Mutation lock storage is unavailable.' );
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A closed reason id is recorded outcome data, not rendered output.
				throw new ReasonedFailure( 'Mutation lock is held.', FailureReason::LOCK_HELD->value );
			}
			return $operation();
		} finally {
			$unlocked = ! $locked || flock( $handle, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native descriptor on every path, including failed acquisition and unlock.
			$closed = fclose( $handle );
			if ( ! $unlocked || ! $closed ) {
				throw new BranchDeploymentLockReleaseFailure( 'Mutation lock could not be released.' );
			}
		}
	}
}
