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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function assertMutationAllowed(): void {}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function frozenTarget( BranchDeploymentDeclaration $deployment, bool $deferExisting ): ?array {
		if ( 'update' === $deployment->operation && $this->executor instanceof WordPressPackageExecutor ) {
			return $this->executor->installed_facts( $deployment );
		}
		return null;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function maintenanceActive(): bool {
		return defined( 'ABSPATH' ) && ( file_exists( ABSPATH . '.maintenance' ) || is_link( ABSPATH . '.maintenance' ) );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function recheckManaged( BranchDeploymentDeclaration $deployment ): void {}

	public function installed( BranchDeploymentDeclaration $deployment ): array {
		if ( null === $this->installed ) {
			throw new AdmittedBranchDurabilityFailure( 'Standalone executor cannot prove installed package state.' );
		}
		return $this->installed;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function baselineNow( BranchDeploymentDeclaration $deployment, array $baseline ): ?array {
		if ( $this->executor instanceof WordPressPackageExecutor ) {
			return $this->executor->installed_facts( $deployment );
		}
		return $this->installed;
	}

	public function adopt( BranchDeploymentDeclaration $deployment ): bool {
		return null !== $this->installed;
	}

	/** @param array{identifier:string,version:string,active:bool}|null $observed */
	public function record_installed( BranchDeploymentDeclaration $deployment, PreparedArchiveArtifact $artifact, ?array $observed ): void {
		$this->installed = $observed ?? array(
			'identifier' => $deployment->installedIdentifier ?? $deployment->slug,
			'version'    => $artifact->expectedVersion(),
			'active'     => false,
		);
	}
}
