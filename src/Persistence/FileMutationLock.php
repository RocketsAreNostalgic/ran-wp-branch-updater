<?php

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- flock relies on direct local file descriptors.
namespace RAN\WPBranchUpdater\V1\Persistence;

use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockReleaseFailure;
use RuntimeException;

final class FileMutationLock implements MutationLock {
	public function __construct( private readonly string $path ) {}

	public function run( callable $operation ): mixed {
		$handle = fopen( $this->path, 'c+' );
		if ( false === $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			throw new RuntimeException( 'Mutation lock is held.' );
		}
		try {
			return $operation();
		} finally {
			if ( ! flock( $handle, LOCK_UN ) || ! fclose( $handle ) ) {
				throw new BranchDeploymentLockReleaseFailure( 'Mutation lock could not be released.' );
			}
		}
	}
}
