<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RAN\UpdaterSupport\V1\PackageDirectory;
use RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedPackageExecutor;
use RAN\WPBranchUpdater\V1\Contract\AdmittedTargetFacts;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\WordPress\InstalledPackageIdentifier;

/** Public declaration-to-terminal-operation entry point. */
final class BranchUpdater {
	public const ADMITTED_API_VERSION = 4;
	private function __construct(
		private readonly AdmittedBranchRunner $runner,
		private readonly BranchDeploymentDeclaration $admitted
	) {}

	/** Create the single runner allowed to complete one already-admitted attempt. */
	public static function for_admitted_attempt(
		BranchDeploymentDeclaration $deployment,
		AdmittedAttemptJournal $journal,
		AdmittedArchiveSource $archives,
		AdmittedTargetFacts $target,
		AdmittedPackageExecutor $executor,
		MutationLock $lock
	): self {
		return new self( new AdmittedBranchRunner( $journal, $archives, $target, $executor, $lock ), $deployment );
	}

	public function plugin(
		string $repository,
		string $repository_id,
		string $branch,
		?string $plugin_file = null,
		?string $package_slug = null,
		?string $subdirectory = null
	): BranchDeployment {
		if ( null === $plugin_file && null === $package_slug ) {
			throw new \InvalidArgumentException( 'A plugin file or package slug is required.' );
		}
		if ( null !== $plugin_file && null !== $package_slug && $this->plugin_slug( $plugin_file ) !== $package_slug ) {
			throw new \InvalidArgumentException( 'The plugin file and package slug disagree.' );
		}
		$slug = $package_slug ?? $this->plugin_slug( (string) $plugin_file );
		return $this->pending( 'plugin', $repository, $repository_id, $branch, $slug, $subdirectory, $plugin_file ?? $this->admitted->installed_identifier );
	}

	public function theme( string $repository, string $repository_id, string $branch, string $stylesheet, ?string $subdirectory = null ): BranchDeployment {
		return $this->pending( 'theme', $repository, $repository_id, $branch, $stylesheet, $subdirectory, $this->admitted->installed_identifier );
	}

	private function pending( string $type, string $repository, string $repository_id, string $branch, string $slug, ?string $subdirectory, ?string $installed_identifier ): BranchDeployment {
		$deployment = new BranchDeploymentDeclaration(
			$this->admitted->attempt_id,
			$type,
			$slug,
			$repository,
			$repository_id,
			$branch,
			$this->admitted->expected_head,
			$this->admitted->operation,
			PackageDirectory::normalize( $subdirectory ),
			$installed_identifier,
			$this->admitted->selected_commit,
			$this->admitted->expected_identifier,
			$this->admitted->allow_working_copy
		);
		if ( (array) $deployment !== (array) $this->admitted ) {
			throw new \InvalidArgumentException( 'The admitted deployment declaration does not match.' );
		}
		return new BranchDeployment( $this->runner, $deployment );
	}

	private function plugin_slug( string $plugin_file ): string {
		try {
			if ( trim( $plugin_file ) !== $plugin_file ) {
				throw new \InvalidArgumentException( 'The plugin file is invalid.' );
			}
			$plugin_file = InstalledPackageIdentifier::normalize( $plugin_file );
		} catch ( \InvalidArgumentException ) {
			throw new \InvalidArgumentException( 'The plugin file is invalid.' );
		}
		if ( 1 !== substr_count( $plugin_file, '/' ) || preg_match( '/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*\.php$/Di', $plugin_file ) !== 1 ) {
			throw new \InvalidArgumentException( 'The plugin file is invalid.' );
		}
		return PackageDirectory::installed_slug( 'plugin', $plugin_file );
	}
}
