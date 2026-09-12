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
		public readonly string $version
	) {}

	public static function downloadAndValidate(
		ArchiveOffer $offer,
		BranchDeploymentDeclaration $d,
		string $directory,
		mixed $maximumArtifactBytes = self::DEFAULT_MAXIMUM_ARTIFACT_BYTES
	): self {
		$maximumArtifactBytes = self::maximumArtifactBytes( $maximumArtifactBytes );
		$maximumExpandedBytes = self::maximumExpandedBytes( $maximumArtifactBytes );
		if ( ( file_exists( $directory ) || is_link( $directory ) ) && ( is_link( $directory ) || ! is_dir( $directory ) ) ) {
			throw new RuntimeException( 'Archive directory is unsafe.' );
		}
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) ) {
			throw new RuntimeException( 'Cannot create private archive directory.' );
		}
		chmod( $directory, 0700 );
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
				$maximumExpandedBytes,
				function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : ''
			);
			return new self( $path, $offer->resolvedRef, $identity, $digest, $inspection['expected_version'] );
		} catch ( \Throwable $e ) {
			if ( null !== $created ) {
				$current = self::identity( $path );
				if ( null !== $current && $current['dev'] === $created['dev'] && $current['ino'] === $created['ino'] ) {
					if ( ! unlink( $path ) ) {
						throw new RuntimeException( 'Rejected archive could not be cleaned safely.', 0, $e );
					}
				} elseif ( file_exists( $path ) || is_link( $path ) ) {
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

	public function getPath(): string {
		return $this->path();
	}

	public function getExpectedVersion(): string {
		return $this->version;
	}

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

	private static function maximumArtifactBytes( mixed $maximumArtifactBytes ): int {
		if ( ! is_int( $maximumArtifactBytes ) || $maximumArtifactBytes < 1 ) {
			throw new RuntimeException( 'Maximum artifact bytes is invalid.' );
		}

		return $maximumArtifactBytes;
	}

	private static function maximumExpandedBytes( int $maximumArtifactBytes ): int {
		if ( $maximumArtifactBytes > intdiv( PHP_INT_MAX, self::EXPANDED_RATIO ) ) {
			throw new RuntimeException( 'Maximum artifact bytes is invalid for expanded archive validation.' );
		}

		return $maximumArtifactBytes * self::EXPANDED_RATIO;
	}

	private static function identity( string $path ): ?array {
		clearstatcache( true, $path );
		$s = @lstat( $path );
		if ( ! is_array( $s ) || is_link( $path ) || ! is_file( $path ) || ( ( $s['mode'] & 0170000 ) !== 0100000 ) || ( ( $s['mode'] & 0777 ) !== 0600 ) || $s['nlink'] !== 1 ) {
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
