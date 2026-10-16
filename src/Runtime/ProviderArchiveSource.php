<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\BranchProvider;
use RuntimeException;

final class ProviderArchiveSource implements AdmittedArchiveSource {
	private ?ArchiveOffer $offer = null;
	private bool $verify_source  = false;

	public function __construct(
		private readonly BranchProvider $provider,
		private readonly string $directory,
		private readonly mixed $maximum_artifact_bytes = PreparedArchive::DEFAULT_MAXIMUM_ARTIFACT_BYTES
	) {}

	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact {
		try {
			if ( null !== $deployment->selected_commit && ! $this->provider->supports_selected_commit() ) {
				throw new AdmittedBranchStageFailure( 'provider_failed' );
			}
			$offer = $this->provider->prepare( $deployment );
			if ( ! hash_equals( $deployment->repository_id, $offer->repository_id ) ) {
				throw new AdmittedBranchStageFailure( 'provider_failed' );
			}
			if ( null !== ( $deployment->selected_commit ?? $deployment->expected_head ) && ! hash_equals( $deployment->selected_commit ?? $deployment->expected_head, $offer->resolved_ref ) ) {
				throw new AdmittedBranchStageFailure( 'provider_failed' );
			}
			$this->offer         = $offer;
			$this->verify_source = null !== $deployment->expected_head || null !== $deployment->selected_commit;
		} catch ( AdmittedBranchStageFailure $failure ) {
			throw $failure;
		} catch ( RuntimeException $failure ) {
			throw new AdmittedBranchStageFailure( 'provider_failed' );
		}
		try {
			return PreparedArchive::download_and_validate(
				$offer,
				$deployment,
				$this->directory,
				$this->maximum_artifact_bytes
			);
		} catch ( RuntimeException $failure ) {
			throw new AdmittedBranchStageFailure( 'archive_integrity_failed' );
		}
	}

	public function verify_current_head(): void {
		if ( $this->verify_source && null !== $this->offer ) {
			$this->offer->verify_current_head();
		}
	}
}
