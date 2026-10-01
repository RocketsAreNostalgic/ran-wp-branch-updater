<?php
// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors -- Archive custody requires atomic local-file identity operations.
declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Archive;

use RAN\WPBranchUpdater\V1\Contract\PreparedPackageArtifact;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RuntimeException;

/** Exact-byte custody from provider download through the WordPress boundary. */
final class PreparedArchive implements PreparedPackageArtifact {
	public const DEFAULT_MAXIMUM_ARTIFACT_BYTES = 52428800;

	private const EXPANDED_RATIO = 4;

	private bool $cleaned = false;

	private function __construct(
		private string $path,
		public readonly string $resolvedRef,
		private array $identity,
		private string $digest,
		public readonly string $version,
		private readonly int $expanded_bytes
	) {}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public static function downloadAndValidate(
		ArchiveOffer $offer,
		BranchDeploymentDeclaration $d,
		string $directory,
		mixed $maximumArtifactBytes = self::DEFAULT_MAXIMUM_ARTIFACT_BYTES
	): self {
		$maximumArtifactBytes   = self::maximum_artifact_bytes( $maximumArtifactBytes );
		$maximum_expanded_bytes = self::maximum_expanded_bytes( $maximumArtifactBytes );
		if ( ( file_exists( $directory ) || is_link( $directory ) ) && ( is_link( $directory ) || ! is_dir( $directory ) ) ) {
			throw new RuntimeException( 'Archive directory is unsafe.' );
		}
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) ) {
			throw new RuntimeException( 'Cannot create private archive directory.' );
		}
		chmod( $directory, 0700 );
		// @phpstan-ignore booleanOr.leftAlwaysFalse (retain symlink recheck at the custody boundary)
		if ( is_link( $directory ) || ( fileperms( $directory ) & 0777 ) !== 0700 ) {
			throw new RuntimeException( 'Archive directory is not private.' );
		}
		$path = tempnam( $directory, 'ran-branch-' );
		if ( false === $path || ! chmod( $path, 0600 ) ) {
			throw new RuntimeException( 'Cannot create private archive file.' );
		}
		$created = null;
		try {
			$created = self::identity( $path );
			if ( null === $created ) {
				throw new RuntimeException( 'Private archive identity is invalid.' );
			}
			$offer->acquire( $path, $maximumArtifactBytes );
			$identity = self::identity( $path );
			if ( null === $identity
				|| $identity['dev'] !== $created['dev']
				|| $identity['ino'] !== $created['ino']
				|| $identity['size'] < 1
				|| $identity['size'] > $maximumArtifactBytes
			) {
				throw new RuntimeException( 'Archive file identity or size is invalid.' );
			}
			$digest = hash_file( 'sha256', $path );
			if ( ! is_string( $digest ) ) {
				throw new RuntimeException( 'Cannot fingerprint archive.' );
			}
			$inspection = ( new ArchiveValidator() )->validate(
				$path,
				$d,
				'update' === $d->operation ? $d->installedIdentifier : null,
				$maximumArtifactBytes,
				$maximum_expanded_bytes,
				function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : ''
			);
			return new self( $path, $offer->resolvedRef, $identity, $digest, $inspection['expected_version'], $inspection['expanded'] );
		} catch ( \Throwable $e ) {
			if ( null !== $created ) {
				$current = self::identity( $path );
				if ( null !== $current && $current['dev'] === $created['dev'] && $current['ino'] === $created['ino'] ) {
					if ( ! unlink( $path ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the previous Throwable for internal custody diagnostics; this is not rendered output.
						throw new RuntimeException( 'Rejected archive could not be cleaned safely.', 0, $e );
					}
				} elseif ( file_exists( $path ) || is_link( $path ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the previous Throwable for internal custody diagnostics; this is not rendered output.
					throw new RuntimeException( 'Rejected archive replacement could not be cleaned safely.', 0, $e );
				}
			}
			throw $e;
		}
	}

	public function path(): string {
		$this->assertUnchanged();
		return $this->path;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function getPath(): string {
		return $this->path();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function getExpectedVersion(): string {
		return $this->version;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function expandedBytes(): int {
		$this->assertUnchanged();
		return $this->expanded_bytes;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- #59 / Core #167: coordinated public contract migration pending.
	public function assertUnchanged(): void {
		if ( $this->cleaned || self::identity( $this->path ) !== $this->identity || ! hash_equals( $this->digest, (string) hash_file( 'sha256', $this->path ) ) ) {
			throw new RuntimeException( 'Prepared archive changed before use.' );
		}
	}

	public function cleanup(): void {
		if ( $this->cleaned ) {
			return;
		}
		$this->assertUnchanged();
		if ( ! unlink( $this->path ) ) {
			throw new RuntimeException( 'Cannot safely remove archive.' );
		}
		$this->cleaned = true;
	}

	private static function maximum_artifact_bytes( mixed $maximum_artifact_bytes ): int {
		if ( ! is_int( $maximum_artifact_bytes ) || $maximum_artifact_bytes < 1 ) {
			throw new RuntimeException( 'Maximum artifact bytes is invalid.' );
		}

		return $maximum_artifact_bytes;
	}

	private static function maximum_expanded_bytes( int $maximum_artifact_bytes ): int {
		if ( $maximum_artifact_bytes > intdiv( PHP_INT_MAX, self::EXPANDED_RATIO ) ) {
			throw new RuntimeException( 'Maximum artifact bytes is invalid for expanded archive validation.' );
		}

		return $maximum_artifact_bytes * self::EXPANDED_RATIO;
	}

	private static function identity( string $path ): ?array {
		clearstatcache( true, $path );
		$s = @lstat( $path );
		if ( ! is_array( $s ) || is_link( $path ) || ! is_file( $path ) || ( ( $s['mode'] & 0170000 ) !== 0100000 ) || ( ( $s['mode'] & 0777 ) !== 0600 ) || 1 !== $s['nlink'] ) {
			return null;
		}
		return array(
			'dev'   => (int) $s['dev'],
			'ino'   => (int) $s['ino'],
			'size'  => (int) $s['size'],
			'mode'  => (int) $s['mode'] & 0777,
			'nlink' => (int) $s['nlink'],
		);
	}
}
