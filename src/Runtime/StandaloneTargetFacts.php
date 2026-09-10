<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RAN\WPBranchUpdater\V1\Archive\PreparedArchiveArtifact;
use RAN\WPBranchUpdater\V1\Contract\AdmittedTargetFacts;
use RAN\WPBranchUpdater\V1\Contract\PackageExecutor;
use RAN\WPBranchUpdater\V1\WordPress\WordPressPackageExecutor;

final class StandaloneTargetFacts implements AdmittedTargetFacts {
	/** @var array{identifier:string,version:string,active:bool}|null */
	private ?array $installed = null;

	public function __construct( private readonly PackageExecutor $executor ) {}

	public function assertMutationAllowed(): void {}

	public function frozenTarget( Deployment $deployment, bool $deferExisting ): ?array {
		if ( 'update' === $deployment->operation && $this->executor instanceof WordPressPackageExecutor ) {
			return $this->executor->installedFacts( $deployment );
		}
		return null;
	}

	public function maintenanceActive(): bool {
		return defined( 'ABSPATH' ) && ( file_exists( ABSPATH . '.maintenance' ) || is_link( ABSPATH . '.maintenance' ) );
	}

	public function recheckManaged( Deployment $deployment ): void {}

	public function installed( Deployment $deployment ): array {
		if ( null === $this->installed ) {
			throw new AdmittedBranchDurabilityFailure( 'Standalone executor cannot prove installed package state.' );
		}
		return $this->installed;
	}

	public function baselineNow( Deployment $deployment, array $baseline ): ?array {
		if ( $this->executor instanceof WordPressPackageExecutor ) {
			return $this->executor->installedFacts( $deployment );
		}
		return $this->installed;
	}

	public function adopt( Deployment $deployment ): bool {
		return null !== $this->installed;
	}

	/** @param array{identifier:string,version:string,active:bool}|null $observed */
	public function recordInstalled( Deployment $deployment, PreparedArchiveArtifact $artifact, ?array $observed ): void {
		$this->installed = $observed ?? array(
			'identifier' => $deployment->installedIdentifier ?? $deployment->slug,
			'version'    => $artifact->expectedVersion(),
			'active'     => false,
		);
	}
}
