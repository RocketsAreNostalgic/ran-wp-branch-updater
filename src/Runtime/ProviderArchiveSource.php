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
	private ?ArchiveOffer $offer         = null;
	private bool $constrain_current_head = false;

	public function __construct(
		private readonly BranchProvider $provider,
		private readonly string $directory,
		private readonly mixed $maximum_artifact_bytes = PreparedArchive::DEFAULT_MAXIMUM_ARTIFACT_BYTES
	) {}

	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact {
		try {
			$offer = $this->provider->prepare( $deployment );
			if ( ! hash_equals( $deployment->repository_id, $offer->repository_id ) ) {
				throw new AdmittedBranchStageFailure( 'provider_failed' );
			}
			if ( null !== $deployment->expected_head && ! hash_equals( $deployment->expected_head, $offer->resolved_ref ) ) {
				throw new AdmittedBranchStageFailure( 'provider_failed' );
			}
			$this->offer                  = $offer;
			$this->constrain_current_head = null !== $deployment->expected_head;
		} catch ( AdmittedBranchStageFailure $failure ) {
			throw $failure;
		} catch ( RuntimeException $failure ) {
			throw new AdmittedBranchStageFailure( 'provider_failed' );
		}
		try {
			return new PreparedArchiveArtifact(
				PreparedArchive::download_and_validate(
					$offer,
					$deployment,
					$this->directory,
					$this->maximum_artifact_bytes
				)
			);
		} catch ( RuntimeException $failure ) {
			throw new AdmittedBranchStageFailure( 'archive_integrity_failed' );
		}
	}

	public function verify_current_head(): void {
		if ( $this->constrain_current_head && null !== $this->offer ) {
			$this->offer->verify_current_head();
		}
	}
}
