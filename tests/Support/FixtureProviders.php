<?php
declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1;

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Contract\BranchProvider;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RuntimeException;

/** Test-only local transport. It proves the contract shape, never claims to be a provider client. */
abstract class FixtureProvider implements BranchProvider {
	public int $prepare_calls      = 0;
	public int $verification_calls = 0;
	public bool $historical_access = true;
	public ?string $forced_ref     = null;
	public function __construct( private readonly string $archive, private string $head, private readonly ?string $repository_id = null, private readonly bool $historical = false ) {}
	abstract protected function name(): string;
	public function move_head( string $head ): void {
		$this->head = $head;
	}
	public function supports_selected_commit(): bool {
		return $this->historical;
	}
	public function prepare( BranchDeploymentDeclaration $d ): ArchiveOffer {
		++$this->prepare_calls;
		if ( null !== $d->expected_head && $d->expected_head !== $this->head ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion diagnostics are not HTML; preserve the actual failing fixture detail.
			throw new RuntimeException( $this->name() . ': expected head is stale.' );
		}
		$archive  = $this->archive;
		$head     =&$this->head;
		$expected = $this->forced_ref ?? $d->selected_commit ?? $this->head;
		return new ArchiveOffer(
			$this->name(),
			$this->repository_id ?? $d->repository_id,
			$expected,
			static function ( string $destination, int $maximum_artifact_bytes ) use ( $archive ): void {
				$size = filesize( $archive );
				if ( false === $size || $size < 1 || $size > $maximum_artifact_bytes ) {
					throw new RuntimeException( 'Fixture archive exceeds the provider acquisition limit.' );
				}
				if ( ! copy( $archive, $destination ) ) {
					throw new RuntimeException( 'Fixture archive copy failed.' );
				}
			},
			function () use ( &$head, $expected, $d ): void {
				++$this->verification_calls;
				if ( ! $this->historical_access || ( null === $d->selected_commit && $head !== $expected ) ) {
					throw new RuntimeException( 'Branch head became stale before mutation.' );
				}
			}
		);
	}
}
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class GitHubFixtureProvider extends FixtureProvider {
	protected function name(): string {
		return 'github-fixture';
	}
}
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class BitbucketFixtureProvider extends FixtureProvider {
	protected function name(): string {
		return 'bitbucket-fixture';
	}
}
