<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RuntimeException;

/** Declaration is deliberately source-specific: a branch revision is not a release. */
final readonly class BranchDeploymentDeclaration {
	public function __construct(
		public string $attempt_id,
		public string $package_type,
		public string $slug,
		public string $repository,
		public string $repository_id,
		public string $branch,
		public ?string $expected_head,
		public string $operation = 'update',
		public ?string $subdirectory = null,
		public ?string $installed_identifier = null
	) {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,190}$/D', $slug ) || ! in_array( $package_type, array( 'plugin', 'theme' ), true )
			|| ! in_array( $operation, array( 'install', 'update' ), true ) || '' === trim( $attempt_id ) || '' === trim( $repository ) || '' === trim( $repository_id )
			|| '' === trim( $branch ) || ( null !== $expected_head && '' === trim( $expected_head ) ) ) {
			throw new RuntimeException( 'Invalid branch deployment declaration.' ); }
	}
}
