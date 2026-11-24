<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RAN\UpdaterSupport\V1\PackageDirectory;
use RAN\UpdaterSupport\V1\PluginFileIdentifier;
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
		public ?string $installed_identifier = null,
		public ?string $selected_commit = null,
		public ?string $expected_identifier = null,
		public bool $allow_working_copy = false
	) {
		if ( null !== $expected_identifier && ( strlen( $expected_identifier ) > 191 || 'install' !== $operation || ( 'theme' === $package_type
			? $expected_identifier !== $slug
			: ( 1 !== substr_count( $expected_identifier, '/' )
				|| ! str_starts_with( $expected_identifier, $slug . '/' )
				|| ! str_ends_with( $expected_identifier, '.php' )
				|| ! PluginFileIdentifier::is_valid( $expected_identifier ) ) ) ) ) {
			throw new RuntimeException( 'Invalid expected installation identifier.' );
		}
		if ( null !== $selected_commit && ( null !== $expected_head || 1 !== preg_match( '/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $selected_commit ) ) ) {
			throw new RuntimeException( 'Invalid historical commit selection.' );
		}
		if ( ! PackageDirectory::is_valid_name( $slug ) || ! in_array( $package_type, array( 'plugin', 'theme' ), true )
			|| ! in_array( $operation, array( 'install', 'update' ), true ) || '' === trim( $attempt_id ) || '' === trim( $repository ) || '' === trim( $repository_id )
			|| '' === trim( $branch ) || ( null !== $expected_head && '' === trim( $expected_head ) ) ) {
			throw new RuntimeException( 'Invalid branch deployment declaration.' ); }
	}
}
