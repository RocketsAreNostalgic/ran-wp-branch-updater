<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\AdmittedPackageExecutor;
use RAN\WPBranchUpdater\V1\Contract\PackageExecutor;
use RAN\WPBranchUpdater\V1\WordPress\WordPressPackageExecutor;
use RuntimeException;

final readonly class StandalonePackageExecutor implements AdmittedPackageExecutor {
	public function __construct( private PackageExecutor $executor, private StandaloneTargetFacts $target ) {}

	public function preflight( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): void {
		if ( ! $artifact instanceof PreparedArchive ) {
			throw new RuntimeException( 'The standalone executor requires the admitted archive artifact.' );
		}
		$this->executor->preflight( $deployment, $artifact );
	}

	public function execute( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
		if ( ! $artifact instanceof PreparedArchive ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::INVALID_REQUEST );
		}
		if ( $this->executor instanceof WordPressPackageExecutor ) {
			$result = $this->executor->execute_core( $deployment, $artifact );
			if ( $result->is_successful() ) {
				$this->target->record_installed( $deployment, $artifact, $this->executor->installed_facts( $deployment ) );
			}
			return $result;
		}
		try {
			$this->executor->execute( $deployment, $artifact );
			$this->target->record_installed( $deployment, $artifact, null );
			return CorePackageExecutionResult::succeeded();
		} catch ( RuntimeException ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED );
		}
	}
}
