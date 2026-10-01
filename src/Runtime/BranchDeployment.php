<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

/** One immutable source declaration; deploy() is the only mutation entry point. */
final readonly class BranchDeployment {
	public function __construct(
		private StandaloneBranchRunner|AdmittedBranchRunner $runner,
		private BranchDeploymentDeclaration $declaration,
		private BranchDeploymentDeclaration|false $admitted
	) {}

	/** The durable standalone journal key, stable before and after deploy(). */
	public function attempt_id(): string {
		return $this->declaration->attempt_id;
	}

	/** Returns the runner's closed terminal outcome; ambiguous durability failures propagate. */
	public function deploy( ?string $expected_commit = null, ?string $operation = null ): string {
		$deployment = new BranchDeploymentDeclaration(
			$this->declaration->attempt_id,
			$this->declaration->package_type,
			$this->declaration->slug,
			$this->declaration->repository,
			$this->declaration->repository_id,
			$this->declaration->branch,
			null === $expected_commit ? $this->declaration->expected_head : $expected_commit,
			null === $operation ? $this->declaration->operation : $operation,
			$this->declaration->subdirectory,
			$this->declaration->installed_identifier
		);
		if ( false !== $this->admitted && (array) $deployment !== (array) $this->admitted ) {
			throw new \InvalidArgumentException( 'The admitted deployment terminal declaration does not match.' );
		}
		return $this->runner instanceof StandaloneBranchRunner
			? $this->runner->execute( $deployment )
			: $this->runner->run( $deployment );
	}
}
