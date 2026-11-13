<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

/** One source declaration handle; deploy() is the only mutation entry point. */
final class BranchDeployment {
	/** This handle's own last reason; the runner is shared by every handle of an updater. */
	private ?AdmittedFailureReason $last_reason = null;

	public function __construct(
		private readonly StandaloneBranchRunner|AdmittedBranchRunner $runner,
		private readonly BranchDeploymentDeclaration $declaration,
		private readonly BranchDeploymentDeclaration|false $admitted
	) {}

	/** The durable standalone journal key, stable before and after deploy(). */
	public function attempt_id(): string {
		return $this->declaration->attempt_id;
	}

	/** The reason for the last deploy() outcome, if the runner recorded one. Detail is untrusted text: escape it. */
	public function last_reason(): ?AdmittedFailureReason {
		return $this->last_reason;
	}

	/** Returns the runner's closed terminal outcome; ambiguous durability failures propagate. */
	public function deploy( ?string $expected_commit = null, ?string $operation = null, ?string $selected_commit = null, ?string $expected_identifier = null ): string {
		$this->last_reason = null;
		$deployment        = new BranchDeploymentDeclaration(
			$this->declaration->attempt_id,
			$this->declaration->package_type,
			$this->declaration->slug,
			$this->declaration->repository,
			$this->declaration->repository_id,
			$this->declaration->branch,
			null === $expected_commit ? $this->declaration->expected_head : $expected_commit,
			null === $operation ? $this->declaration->operation : $operation,
			$this->declaration->subdirectory,
			$this->declaration->installed_identifier,
			$selected_commit ?? $this->declaration->selected_commit,
			$expected_identifier ?? $this->declaration->expected_identifier
		);
		if ( false !== $this->admitted && (array) $deployment !== (array) $this->admitted ) {
			throw new \InvalidArgumentException( 'The admitted deployment terminal declaration does not match.' );
		}
		// A refused call (an already consumed admitted runner) never ran, so it has no reason of its own.
		$started = $this->runner instanceof StandaloneBranchRunner || ! $this->runner->is_consumed();
		try {
			return $this->runner instanceof StandaloneBranchRunner
				? $this->runner->execute( $deployment )
				: $this->runner->run( $deployment );
		} finally {
			$this->last_reason = $started ? $this->runner->last_reason() : null;
		}
	}
}
