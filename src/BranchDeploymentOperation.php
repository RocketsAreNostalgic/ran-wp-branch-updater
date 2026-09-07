<?php

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Package-local adapters are deliberately co-located; internal exception text is not rendered.
declare(strict_types=1);

namespace RAN\BranchDeployment;

use RuntimeException;

/** Standalone consumer wiring around the shared admitted runner. */
final class BranchDeploymentOperation {
	public function __construct(
		private readonly BranchProvider $provider,
		private readonly FileAttemptStore $attempts,
		private readonly PackageExecutor $executor,
		private readonly string $archiveDirectory,
		private readonly MutationLock $lock
	) {}

	/** Returns the closed terminal outcome; normal failures remain terminal outcomes. */
	public function execute( Deployment $deployment ): string {
		$this->attempts->begin( $deployment );
		$target = new StandaloneTargetFacts( $this->executor );
		$runner = new AdmittedBranchRunner(
			new FileAttemptJournal( $this->attempts, $deployment->attemptId ),
			new ProviderArchiveSource( $this->provider, $this->archiveDirectory ),
			$target,
			new StandalonePackageExecutor( $this->executor, $target ),
			$this->lock
		);
		return $runner->run( $deployment );
	}
}

final readonly class FileAttemptJournal implements AdmittedAttemptJournal {
	public function __construct( private FileAttemptStore $store, private string $attemptId ) {}
	public function recordResolvedRef( string $ref ): void {
		$this->store->resolved( $this->attemptId, $ref ); }
	public function markMutationStarted(): void {
		$this->store->fence( $this->attemptId ); }
	public function finish( string $code ): void {
		$state = match ( $code ) {
			'deployed', 'already_managed' => 'succeeded',
			'interrupted', 'maintenance_remaining', 'installed_version_mismatch', 'activation_state_changed', 'persistence_uncertain', 'restoration_uncertain' => 'needs_attention',
			default => 'failed',
		};
		$this->store->finish( $this->attemptId, $state, $code );
	}
}

final class ProviderArchiveSource implements AdmittedArchiveSource {
	private ?ArchiveOffer $offer       = null;
	private bool $constrainCurrentHead = false;
	public function __construct( private readonly BranchProvider $provider, private readonly string $directory ) {}
	public function prepare( Deployment $deployment, ?array $baseline ): AdmittedBranchArtifact {
		try {
			$offer = $this->provider->prepare( $deployment );
			if ( ! hash_equals( $deployment->repositoryId, $offer->repositoryId ) ) {
				throw new AdmittedBranchStageFailure( 'provider_failed' );
			}
			if ( null !== $deployment->expectedHead && ! hash_equals( $deployment->expectedHead, $offer->resolvedRef ) ) {
				throw new AdmittedBranchStageFailure( 'provider_failed' );
			}
			$this->offer                = $offer;
			$this->constrainCurrentHead = null !== $deployment->expectedHead;
		} catch ( AdmittedBranchStageFailure $failure ) {
			throw $failure;
		} catch ( RuntimeException $failure ) {
			throw new AdmittedBranchStageFailure( 'provider_failed' );
		}
		try {
			return new PreparedArchiveArtifact( PreparedArchive::downloadAndValidate( $offer, $deployment, $this->directory ) );
		} catch ( RuntimeException $failure ) {
			throw new AdmittedBranchStageFailure( 'archive_integrity_failed' );
		}
	}
	public function verifyCurrentHead(): void {
		if ( $this->constrainCurrentHead && null !== $this->offer ) {
			$this->offer->verifyCurrentHead();
		}
	}
}

final readonly class PreparedArchiveArtifact implements AdmittedBranchArtifact {
	public function __construct( private PreparedArchive $archive ) {}
	public function resolvedRef(): string {
		return $this->archive->resolvedRef; }
	public function expectedVersion(): string {
		return $this->archive->version; }
	public function assertUnchanged(): void {
		$this->archive->assertUnchanged(); }
	public function cleanup(): void {
		$this->archive->cleanup(); }
	public function archive(): PreparedArchive {
		return $this->archive; }
}

final class StandaloneTargetFacts implements AdmittedTargetFacts {
	/** @var array{identifier:string,version:string,active:bool}|null */
	private ?array $installed = null;
	public function __construct( private readonly PackageExecutor $executor ) {}
	public function assertMutationAllowed(): void {}
	public function frozenTarget( Deployment $deployment, bool $deferExisting ): ?array {
		if ( 'update' === $deployment->operation && $this->executor instanceof WordPressPackageExecutor ) {
			return $this->executor->installedFacts( $deployment );
		}
		return null;
	}
	public function maintenanceActive(): bool {
		return defined( 'ABSPATH' ) && ( file_exists( ABSPATH . '.maintenance' ) || is_link( ABSPATH . '.maintenance' ) ); }
	public function recheckManaged( Deployment $deployment ): void {}
	public function installed( Deployment $deployment ): array {
		if ( null === $this->installed ) {
			throw new AdmittedBranchDurabilityFailure( 'Standalone executor cannot prove installed package state.' );
		}
		return $this->installed;
	}
	public function baselineNow( Deployment $deployment, array $baseline ): ?array {
		if ( $this->executor instanceof WordPressPackageExecutor ) {
			return $this->executor->installedFacts( $deployment );
		}
		return $this->installed;
	}
	public function adopt( Deployment $deployment ): bool {
		return null !== $this->installed; }
	/** @param array{identifier:string,version:string,active:bool}|null $observed */
	public function recordInstalled( Deployment $deployment, PreparedArchiveArtifact $artifact, ?array $observed ): void {
		$this->installed = $observed ?? array(
			'identifier' => $deployment->installedIdentifier ?? $deployment->slug,
			'version'    => $artifact->expectedVersion(),
			'active'     => false,
		);
	}
}

final readonly class StandalonePackageExecutor implements AdmittedPackageExecutor {
	public function __construct( private PackageExecutor $executor, private StandaloneTargetFacts $target ) {}
	public function preflight( Deployment $deployment, AdmittedBranchArtifact $artifact ): void {
		if ( ! $artifact instanceof PreparedArchiveArtifact ) {
			throw new RuntimeException( 'The standalone executor requires the admitted archive artifact.' );
		}
		$this->executor->preflight( $deployment, $artifact->archive() );
	}
	public function execute( Deployment $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
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
