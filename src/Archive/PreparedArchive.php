<?php
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

	/**
	 * @param array{dev:int,ino:int,size:int,mode:int,nlink:int} $identity
	 */
	private function __construct(
		private string $path,
		public readonly string $resolved_ref,
		private array $identity,
		private string $digest,
		public readonly string $version,
		private readonly int $expanded_bytes
	) {}

	public static function download_and_validate(
		ArchiveOffer $offer,
		BranchDeploymentDeclaration $d,
		string $directory,
		mixed $maximum_artifact_bytes = self::DEFAULT_MAXIMUM_ARTIFACT_BYTES
	): self {
		$maximum_artifact_bytes = self::maximum_artifact_bytes( $maximum_artifact_bytes );
		$maximum_expanded_bytes = self::maximum_expanded_bytes( $maximum_artifact_bytes );
		if ( ( file_exists( $directory ) || is_link( $directory ) ) && ( is_link( $directory ) || ! is_dir( $directory ) ) ) {
			throw new RuntimeException( 'Archive directory is unsafe.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the private native archive directory before acquiring provider bytes.
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) ) {
			throw new RuntimeException( 'Cannot create private archive directory.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native private-directory permissions before the custody recheck.
		chmod( $directory, 0700 );
		// @phpstan-ignore booleanOr.leftAlwaysFalse (retain symlink recheck at the custody boundary)
		if ( is_link( $directory ) || ( fileperms( $directory ) & 0777 ) !== 0700 ) {
			throw new RuntimeException( 'Archive directory is not private.' );
		}
		$path = tempnam( $directory, 'ran-branch-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Make the newly created native archive file private before acquisition.
		if ( false === $path || ! chmod( $path, 0600 ) ) {
			throw new RuntimeException( 'Cannot create private archive file.' );
		}
		$created = null;
		try {
			$created = self::identity( $path );
			if ( null === $created ) {
				throw new RuntimeException( 'Private archive identity is invalid.' );
			}
			$offer->acquire( $path, $maximum_artifact_bytes );
			$identity = self::identity( $path );
			if ( null === $identity
				|| $identity['dev'] !== $created['dev']
				|| $identity['ino'] !== $created['ino']
				|| $identity['size'] < 1
				|| $identity['size'] > $maximum_artifact_bytes
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
				'update' === $d->operation ? $d->installed_identifier : null,
				$maximum_artifact_bytes,
				$maximum_expanded_bytes,
				function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : ''
			);
			return new self( $path, $offer->resolved_ref, $identity, $digest, $inspection['expected_version'], $inspection['expanded'] );
		} catch ( \Throwable $e ) {
			if ( null !== $created ) {
				$current = self::identity( $path );
				if ( null !== $current && $current['dev'] === $created['dev'] && $current['ino'] === $created['ino'] ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete only the rejected archive whose device and inode still match the created file.
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
		$this->assert_unchanged();
		return $this->path;
	}

	public function get_path(): string {
		return $this->path();
	}

	public function get_expected_version(): string {
		return $this->version;
	}

	public function expanded_bytes(): int {
		$this->assert_unchanged();
		return $this->expanded_bytes;
	}

	public function assert_unchanged(): void {
		if ( $this->cleaned || self::identity( $this->path ) !== $this->identity || ! hash_equals( $this->digest, (string) hash_file( 'sha256', $this->path ) ) ) {
			throw new RuntimeException( 'Prepared archive changed before use.' );
		}
	}

	public function cleanup(): void {
		if ( $this->cleaned ) {
			return;
		}
		$this->assert_unchanged();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete only the archive after the exact custody identity and digest recheck.
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

	/**
	 * @return array{dev:int,ino:int,size:int,mode:int,nlink:int}|null
	 */
	private static function identity( string $path ): ?array {
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing or replaced paths fail the identity check; suppress only the native missing-path warning.
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
