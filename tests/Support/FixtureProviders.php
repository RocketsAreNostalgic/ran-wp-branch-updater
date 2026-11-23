<?php
declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1;

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RuntimeException;

/** Local ZIP fixture for exercising archive acquisition and validation. */
final readonly class GitHubFixtureProvider {
	public function __construct( private string $archive, private string $ref ) {}

	public function prepare( BranchDeploymentDeclaration $d ): ArchiveOffer {
		$archive = $this->archive;
		return new ArchiveOffer(
			'github-fixture',
			$d->repository_id,
			$this->ref,
			static function ( string $destination, int $maximum_artifact_bytes ) use ( $archive ): void {
				$size = filesize( $archive );
				if ( false === $size || $size < 1 || $size > $maximum_artifact_bytes ) {
					throw new RuntimeException( 'Fixture archive exceeds the provider acquisition limit.' );
				}
				if ( ! copy( $archive, $destination ) ) {
					throw new RuntimeException( 'Fixture archive copy failed.' );
				}
			},
			static function (): void {}
		);
	}
}
