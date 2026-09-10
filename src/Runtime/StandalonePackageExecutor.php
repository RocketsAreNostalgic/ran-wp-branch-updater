<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RAN\WPBranchUpdater\V1\Archive\PreparedArchiveArtifact;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\AdmittedPackageExecutor;
use RAN\WPBranchUpdater\V1\Contract\PackageExecutor;
use RAN\WPBranchUpdater\V1\WordPress\WordPressPackageExecutor;
use RuntimeException;

final readonly class StandalonePackageExecutor implements AdmittedPackageExecutor {
	public function __construct( private PackageExecutor $executor, private StandaloneTargetFacts $target ) {}

	public function preflight( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): void {
		if ( ! $artifact instanceof PreparedArchiveArtifact ) {
			throw new RuntimeException( 'The standalone executor requires the admitted archive artifact.' );
		}
		$this->executor->preflight( $deployment, $artifact->archive() );
	}

	public function execute( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
		if ( ! $artifact instanceof PreparedArchiveArtifact ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::INVALID_REQUEST );
		}
		if ( $this->executor instanceof WordPressPackageExecutor ) {
			$result = $this->executor->executeCore( $deployment, $artifact->archive() );
			if ( $result->isSuccessful() ) {
				$this->target->recordInstalled( $deployment, $artifact, $this->executor->installedFacts( $deployment ) );
			}
			return $result;
		}
		try {
			$this->executor->execute( $deployment, $artifact->archive() );
			$this->target->recordInstalled( $deployment, $artifact, null );
			return CorePackageExecutionResult::succeeded();
		} catch ( RuntimeException ) {
			return CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED );
		}
	}
}
