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

	public function assert_mutation_allowed(): void {}

	public function frozen_target( BranchDeploymentDeclaration $deployment, bool $defer_existing ): ?array {
		if ( 'update' === $deployment->operation && $this->executor instanceof WordPressPackageExecutor ) {
			return $this->executor->installed_facts( $deployment );
		}
		return null;
	}

	public function maintenance_active(): bool {
		return defined( 'ABSPATH' ) && ( file_exists( ABSPATH . '.maintenance' ) || is_link( ABSPATH . '.maintenance' ) );
	}

	public function recheck_managed( BranchDeploymentDeclaration $deployment ): void {}

	public function installed( BranchDeploymentDeclaration $deployment ): array {
		if ( null === $this->installed ) {
			throw new AdmittedBranchDurabilityFailure( 'Standalone executor cannot prove installed package state.' );
		}
		return $this->installed;
	}

	public function baseline_now( BranchDeploymentDeclaration $deployment, array $baseline ): ?array {
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
			'identifier' => $deployment->installed_identifier ?? $deployment->slug,
			'version'    => $artifact->expected_version(),
			'active'     => false,
		);
	}
}
