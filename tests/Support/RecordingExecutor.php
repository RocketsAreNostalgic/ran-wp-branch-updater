<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1;

use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Contract\PackageExecutor;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RuntimeException;

/** Test-only executor that records the immutable archive presented for mutation. */
final class RecordingExecutor implements PackageExecutor {
	public array $calls = array();

	public function __construct( private readonly bool $fail = false ) {}

	public function preflight( BranchDeploymentDeclaration $deployment, PreparedArchive $archive ): array {
		$archive->assertUnchanged();
		return array();
	}

	public function execute( BranchDeploymentDeclaration $deployment, PreparedArchive $archive ): void {
		$this->calls[] = array(
			'id'      => $deployment->attemptId,
			'path'    => $archive->path(),
			'version' => $archive->version,
		);
		if ( $this->fail ) {
			throw new RuntimeException( 'Simulated installer failure.' );
		}
	}
}
