<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchiveArtifact;
use RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\BranchProvider;
use RuntimeException;

final class ProviderArchiveSource implements AdmittedArchiveSource {
	private ?ArchiveOffer $offer       = null;
	private bool $constrainCurrentHead = false;

	public function __construct( private readonly BranchProvider $provider, private readonly string $directory ) {}

	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact {
		try {
			$offer = $this->provider->prepare( $deployment );
			if ( ! hash_equals( $deployment->repositoryId, $offer->repositoryId ) ) {
				throw new AdmittedBranchStageFailure( 'provider_failed' );
			}
			if ( null !== $deployment->expectedHead && ! hash_equals( $deployment->expectedHead, $offer->resolvedRef ) ) {
				throw new AdmittedBranchStageFailure( 'provider_failed' );
			}
			$this->offer                = $offer;
			$this->constrainCurrentHead = null !== $deployment->expectedHead;
		} catch ( AdmittedBranchStageFailure $failure ) {
			throw $failure;
		} catch ( RuntimeException $failure ) {
			throw new AdmittedBranchStageFailure( 'provider_failed' );
		}
		try {
			return new PreparedArchiveArtifact( PreparedArchive::downloadAndValidate( $offer, $deployment, $this->directory ) );
		} catch ( RuntimeException $failure ) {
			throw new AdmittedBranchStageFailure( 'archive_integrity_failed' );
		}
	}

	public function verifyCurrentHead(): void {
		if ( $this->constrainCurrentHead && null !== $this->offer ) {
			$this->offer->verifyCurrentHead();
		}
	}
}
