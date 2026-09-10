<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1;

/** Public declaration-to-terminal-operation entry point. */
final class BranchDeploymentPackage {
	private function __construct(
		private readonly BranchDeploymentOperation|AdmittedBranchRunner $runner,
		private readonly Deployment|false $admitted
	) {}

	public static function forStandalone( BranchDeploymentOperation $operation ): self {
		return new self( $operation, false );
	}

	/** Create the single runner allowed to complete one already-admitted attempt. */
	public static function forAdmittedAttempt(
		Deployment $deployment,
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
		string $repositoryId,
		string $branch,
		?string $pluginFile = null,
		?string $packageSlug = null,
		?string $subdirectory = null
	): PendingDeployment {
		if ( null === $pluginFile && null === $packageSlug ) {
			throw new \InvalidArgumentException( 'A plugin file or package slug is required.' );
		}
		if ( null !== $pluginFile && null !== $packageSlug && $this->pluginSlug( $pluginFile ) !== $packageSlug ) {
			throw new \InvalidArgumentException( 'The plugin file and package slug disagree.' );
		}
		$slug = $packageSlug ?? $this->pluginSlug( (string) $pluginFile );

		return $this->pending( 'plugin', $repository, $repositoryId, $branch, $slug, $subdirectory, false === $this->admitted ? $pluginFile : ( $pluginFile ?? $this->admitted->installedIdentifier ) );
	}

	public function theme( string $repository, string $repositoryId, string $branch, string $stylesheet, ?string $subdirectory = null ): PendingDeployment {
		return $this->pending( 'theme', $repository, $repositoryId, $branch, $stylesheet, $subdirectory, false === $this->admitted ? $stylesheet : $this->admitted->installedIdentifier );
	}

	private function pending( string $type, string $repository, string $repositoryId, string $branch, string $slug, ?string $subdirectory, ?string $installedIdentifier ): PendingDeployment {
		if ( false === $this->admitted && 'plugin' === $type && null === $installedIdentifier ) {
			throw new \InvalidArgumentException( 'A standalone plugin deployment requires its installed plugin file.' );
		}
		$bound      = false !== $this->admitted;
		$deployment = new Deployment(
			$bound ? $this->admitted->attemptId : bin2hex( random_bytes( 16 ) ),
			$type,
			$slug,
			$repository,
			$repositoryId,
			$branch,
			$bound ? $this->admitted->expectedHead : null,
			$bound ? $this->admitted->operation : 'update',
			PackageSubdirectory::normalize( $subdirectory ),
			$installedIdentifier
		);
		if ( $bound && (array) $deployment !== (array) $this->admitted ) {
			throw new \InvalidArgumentException( 'The admitted deployment declaration does not match.' );
		}

		return new PendingDeployment( $this->runner, $deployment, $this->admitted );
	}

	private function pluginSlug( string $pluginFile ): string {
		try {
			if ( trim( $pluginFile ) !== $pluginFile ) {
				throw new \InvalidArgumentException( 'The plugin file is invalid.' );
			}
			$pluginFile = InstalledPackageIdentifier::normalize( $pluginFile );
		} catch ( \InvalidArgumentException ) {
			throw new \InvalidArgumentException( 'The plugin file is invalid.' );
		}
		if ( 1 !== substr_count( $pluginFile, '/' )
			|| preg_match( '/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*\.php$/Di', $pluginFile ) !== 1 ) {
			throw new \InvalidArgumentException( 'The plugin file is invalid.' );
		}
		$slug = dirname( $pluginFile );
		if ( '.' === $slug || str_contains( $slug, '/' ) ) {
			throw new \InvalidArgumentException( 'The plugin file must be in its package directory.' );
		}
		return PackageSubdirectory::normalizeSlug( $slug );
	}
}
