<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RuntimeException;

/** Declaration is deliberately source-specific: a branch revision is not a release. */
final readonly class Deployment {
	public function __construct(
		public string $attemptId,
		public string $packageType,
		public string $slug,
		public string $repository,
		public string $repositoryId,
		public string $branch,
		public ?string $expectedHead,
		public string $operation = 'update',
		public ?string $subdirectory = null,
		public ?string $installedIdentifier = null
	) {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,190}$/D', $slug ) || ! in_array( $packageType, array( 'plugin', 'theme' ), true )
			|| ! in_array( $operation, array( 'install', 'update' ), true ) || '' === trim( $attemptId ) || '' === trim( $repository ) || '' === trim( $repositoryId )
			|| '' === trim( $branch ) || ( null !== $expectedHead && '' === trim( $expectedHead ) ) ) {
			throw new RuntimeException( 'Invalid branch deployment declaration.' ); }
	}
}
