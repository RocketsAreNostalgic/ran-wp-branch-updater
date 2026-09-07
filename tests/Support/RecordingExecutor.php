<?php

declare(strict_types=1);

namespace RAN\BranchDeployment;

use RuntimeException;

/** Test-only executor that records the immutable archive presented for mutation. */
final class RecordingExecutor implements PackageExecutor {

	public array $calls = array();

	public function __construct( private readonly bool $fail = false ) {}

	public function preflight( Deployment $deployment, PreparedArchive $archive ): array {
		$archive->assertUnchanged();
		return array();
	}

	public function execute( Deployment $deployment, PreparedArchive $archive ): void {
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
