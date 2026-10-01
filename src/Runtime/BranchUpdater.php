<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Runtime;

use RAN\WPBranchUpdater\V1\Archive\PackageSubdirectory;
use RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedPackageExecutor;
use RAN\WPBranchUpdater\V1\Contract\AdmittedTargetFacts;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\WordPress\InstalledPackageIdentifier;

/** Public declaration-to-terminal-operation entry point. */
final class BranchUpdater {
	private function __construct(
		private readonly StandaloneBranchRunner|AdmittedBranchRunner $runner,
		private readonly BranchDeploymentDeclaration|false $admitted
	) {}

	public static function for_standalone( StandaloneBranchRunner $operation ): self {
		return new self( $operation, false );
	}

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
		return $this->pending( 'plugin', $repository, $repository_id, $branch, $slug, $subdirectory, false === $this->admitted ? $plugin_file : ( $plugin_file ?? $this->admitted->installed_identifier ) );
	}

	public function theme( string $repository, string $repository_id, string $branch, string $stylesheet, ?string $subdirectory = null ): BranchDeployment {
		return $this->pending( 'theme', $repository, $repository_id, $branch, $stylesheet, $subdirectory, false === $this->admitted ? $stylesheet : $this->admitted->installed_identifier );
	}

	private function pending( string $type, string $repository, string $repository_id, string $branch, string $slug, ?string $subdirectory, ?string $installed_identifier ): BranchDeployment {
		if ( false === $this->admitted && 'plugin' === $type && null === $installed_identifier ) {
			throw new \InvalidArgumentException( 'A standalone plugin deployment requires its installed plugin file.' );
		}
		$bound      = false !== $this->admitted;
		$deployment = new BranchDeploymentDeclaration(
			$bound ? $this->admitted->attempt_id : bin2hex( random_bytes( 16 ) ),
			$type,
			$slug,
			$repository,
			$repository_id,
			$branch,
			$bound ? $this->admitted->expected_head : null,
			$bound ? $this->admitted->operation : 'update',
			PackageSubdirectory::normalize( $subdirectory ),
			$installed_identifier
		);
		if ( $bound && (array) $deployment !== (array) $this->admitted ) {
			throw new \InvalidArgumentException( 'The admitted deployment declaration does not match.' );
		}
		return new BranchDeployment( $this->runner, $deployment, $this->admitted );
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
		$slug = dirname( $plugin_file );
		if ( '.' === $slug || str_contains( $slug, '/' ) ) {
			throw new \InvalidArgumentException( 'The plugin file must be in its package directory.' );
		}
		return PackageSubdirectory::normalize_slug( $slug );
	}
}
