<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile,WordPress.Security.EscapeOutput -- Local fake provider variants share one non-production transport contract.
declare(strict_types=1);
namespace RAN\WPBranchUpdater\V1;

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Contract\BranchProvider;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RuntimeException;

/** Test-only local transport. It proves the contract shape, never claims to be a provider client. */
abstract class FixtureProvider implements BranchProvider {
	public function __construct( private readonly string $archive, private string $head, private readonly ?string $repositoryId = null ) {}
	abstract protected function name(): string;
	public function moveHead( string $head ): void {
		$this->head = $head;
	}
	public function prepare( BranchDeploymentDeclaration $d ): ArchiveOffer {
		if ( null !== $d->expectedHead && $d->expectedHead !== $this->head ) {
			throw new RuntimeException( $this->name() . ': expected head is stale.' );
		}
		$archive  = $this->archive;
		$head     =&$this->head;
		$expected = $this->head;
		return new ArchiveOffer(
			$this->name(),
			$this->repositoryId ?? $d->repositoryId,
			$expected,
			static function ( string $destination ) use ( $archive ): void {
				if ( ! copy( $archive, $destination ) ) {
					throw new RuntimeException( 'Fixture archive copy failed.' );
				}
			},
			static function () use ( &$head, $expected ): void {
				if ( $head !== $expected ) {
					throw new RuntimeException( 'Branch head became stale before mutation.' );
				}
			}
		);
	}
}
final class GitHubFixtureProvider extends FixtureProvider {
	protected function name(): string {
		return 'github-fixture';
	}
}
final class BitbucketFixtureProvider extends FixtureProvider {
	protected function name(): string {
		return 'bitbucket-fixture';
	}
}
