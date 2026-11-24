<?php
declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Archive;

use InvalidArgumentException;
use RAN\UpdaterSupport\V1\ArchiveSafety;
use RAN\UpdaterSupport\V1\RegularFileStat;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\PreparedPackageArtifact;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\FailureReason;
use RAN\WPBranchUpdater\V1\Runtime\ReasonedFailure;
use RuntimeException;

/** Exact-byte custody from provider download through the WordPress boundary. */
final class PreparedArchive implements PreparedPackageArtifact, AdmittedBranchArtifact {
	public const DEFAULT_MAXIMUM_ARTIFACT_BYTES = 52428800;

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
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Verify native archive directory type and ownership before changing permissions.
		$pre_dir_stat = @lstat( $directory );
		if ( ! is_array( $pre_dir_stat ) || ( $pre_dir_stat['mode'] & 0170000 ) !== 0040000 ) {
			throw new RuntimeException( 'Archive directory is unsafe.' );
		}
		if ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== (int) $pre_dir_stat['uid'] ) {
			throw new RuntimeException( 'Archive directory ownership is invalid.' );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native private-directory permissions before the custody recheck; failures reject the directory.
		if ( ! @chmod( $directory, 0700 ) ) {
			throw new RuntimeException( 'Archive directory is not private.' );
		}
		// @phpstan-ignore booleanOr.leftAlwaysFalse (retain symlink recheck at the custody boundary)
		if ( is_link( $directory ) || ( fileperms( $directory ) & 0777 ) !== 0700 ) {
			throw new RuntimeException( 'Archive directory is not private.' );
		}
		clearstatcache( true, $directory );
		$resolved_directory = realpath( $directory );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Verify native staging directory writability before exclusive archive creation.
		if ( false === $resolved_directory || ! is_dir( $resolved_directory ) || ! is_writable( $resolved_directory ) ) {
			throw new RuntimeException( 'Archive directory is not writable.' );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Re-verify native archive directory identity, mode and ownership after chmod.
		$directory_stat = @lstat( $resolved_directory );
		if ( ! is_array( $directory_stat )
			|| ( $directory_stat['mode'] & 0170000 ) !== 0040000
			|| ( $directory_stat['mode'] & 0777 ) !== 0700
			|| (int) $directory_stat['dev'] !== (int) $pre_dir_stat['dev']
			|| (int) $directory_stat['ino'] !== (int) $pre_dir_stat['ino']
			|| ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== (int) $directory_stat['uid'] )
		) {
			throw new RuntimeException( 'Archive directory ownership is invalid.' );
		}
		try {
			$suffix = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the previous Throwable for internal custody diagnostics; this is not rendered output.
			throw new RuntimeException( 'Cannot create private archive file.', 0, $e );
		}
		$path = $resolved_directory . DIRECTORY_SEPARATOR . 'ran-branch-' . $suffix;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusively create the private archive file inside the verified directory without tempnam fallback.
		$handle = @fopen( $path, 'x+b' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Cannot create private archive file.' );
		}
		$created     = null;
		$created_dev = null;
		$created_ino = null;
		try {
			$fstat = fstat( $handle );
			if ( ! is_array( $fstat )
				|| ( $fstat['mode'] & 0170000 ) !== 0100000
				|| 1 !== (int) $fstat['nlink']
				|| 0 !== (int) $fstat['size']
			) {
				throw new RuntimeException( 'Private archive identity is invalid.' );
			}
			$created_dev = (int) $fstat['dev'];
			$created_ino = (int) $fstat['ino'];
			$pre_chmod   = RegularFileStat::read( $path );
			if ( null === $pre_chmod
				|| 1 !== $pre_chmod['nlink']
				|| $pre_chmod['dev'] !== $created_dev
				|| $pre_chmod['ino'] !== $created_ino
			) {
				throw new RuntimeException( 'Private archive identity is invalid.' );
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Make the verified exclusively created native archive file private before acquisition.
			if ( ! @chmod( $path, 0600 ) ) {
				throw new RuntimeException( 'Cannot create private archive file.' );
			}
			$after_chmod = fstat( $handle );
			$created     = self::identity( $path );
			if ( ! is_array( $after_chmod )
				|| null === $created
				|| ( $after_chmod['mode'] & 0170000 ) !== 0100000
				|| ( $after_chmod['mode'] & 0777 ) !== 0600
				|| 1 !== (int) $after_chmod['nlink']
				|| 0 !== (int) $after_chmod['size']
				|| (int) $after_chmod['dev'] !== $created_dev
				|| (int) $after_chmod['ino'] !== $created_ino
				|| $created['dev'] !== $created_dev
				|| $created['ino'] !== $created_ino
				|| 0 !== $created['size']
			) {
				$created = null;
				throw new RuntimeException( 'Private archive identity is invalid.' );
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exclusively created descriptor before identity-checked cleanup.
			fclose( $handle );
			if ( null !== $created_dev && null !== $created_ino ) {
				self::cleanup_matching_file( $path, $created_dev, $created_ino, $e );
			}
			throw $e;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the verified archive file descriptor only after descriptor-to-path identity is established.
		if ( ! fclose( $handle ) ) {
			$close_error = new RuntimeException( 'Cannot create private archive file.' );
			self::cleanup_matching_file( $path, $created_dev, $created_ino, $close_error );
			throw $close_error;
		}
		try {
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
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Concurrent removal or read failure during initial fingerprinting fails closed cleanly.
			$digest = @hash_file( 'sha256', $path );
			if ( ! is_string( $digest ) ) {
				throw new RuntimeException( 'Cannot fingerprint archive.' );
			}
			$inspection = ( new ArchiveValidator() )->validate(
				$path,
				$d,
				'update' === $d->operation ? $d->installed_identifier : null,
				$maximum_artifact_bytes,
				$maximum_expanded_bytes,
				self::runtime_wordpress_version()
			);
			$archive    = new self( $path, $offer->resolved_ref, $identity, $digest, $inspection['expected_version'], $inspection['expanded'] );
			$archive->assert_unchanged();
			return $archive;
		} catch ( \Throwable $e ) {
			self::cleanup_matching_file( $path, $created['dev'], $created['ino'], $e );
			throw $e;
		}
	}

	public function resolved_ref(): string {
		return $this->resolved_ref;
	}

	public function expected_version(): string {
		return $this->version;
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
		$changed = null;
		if ( $this->cleaned ) {
			$changed = 'cleaned';
		} elseif ( self::identity( $this->path ) !== $this->identity ) {
			$changed = 'identity';
		} else {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Concurrent removal or read failure during custody fingerprinting fails closed as a digest mismatch.
			$current_digest = @hash_file( 'sha256', $this->path );
			if ( ! is_string( $current_digest ) || ! hash_equals( $this->digest, $current_digest ) ) {
				$changed = 'digest';
			}
		}
		if ( null !== $changed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A closed reason id and closed cause are recorded outcome data, not rendered output.
			throw new ReasonedFailure( 'Prepared archive changed before use.', FailureReason::ARCHIVE_CUSTODY_CHANGED->value, $changed );
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

	private static function runtime_wordpress_version(): string {
		if ( function_exists( 'wp_get_wp_version' ) ) {
			return wp_get_wp_version();
		}

		return isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '';
	}

	private static function maximum_artifact_bytes( mixed $maximum_artifact_bytes ): int {
		if ( ! is_int( $maximum_artifact_bytes ) || $maximum_artifact_bytes < 1 ) {
			throw new RuntimeException( 'Maximum artifact bytes is invalid.' );
		}

		return $maximum_artifact_bytes;
	}

	private static function maximum_expanded_bytes( int $maximum_artifact_bytes ): int {
		try {
			return ArchiveSafety::maximum_expanded_bytes( $maximum_artifact_bytes );
		} catch ( InvalidArgumentException ) {
			throw new RuntimeException( 'Maximum artifact bytes is invalid for expanded archive validation.' );
		}
	}

	private static function cleanup_matching_file( string $path, int $dev, int $ino, \Throwable $cause ): void {
		$current = RegularFileStat::read( $path );
		if ( null !== $current
			&& 1 === $current['nlink']
			&& $current['dev'] === $dev
			&& $current['ino'] === $ino
		) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete only the rejected archive whose device and inode still match the created file.
			if ( ! unlink( $path ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the previous Throwable for internal custody diagnostics; this is not rendered output.
				throw new RuntimeException( 'Rejected archive could not be cleaned safely.', 0, $cause );
			}
			return;
		}
		if ( null === $current && ! file_exists( $path ) && ! is_link( $path ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the previous Throwable for internal custody diagnostics; this is not rendered output.
		throw new RuntimeException( 'Rejected archive replacement could not be cleaned safely.', 0, $cause );
	}

	/**
	 * @return array{dev:int,ino:int,size:int,mode:int,nlink:int}|null
	 */
	private static function identity( string $path ): ?array {
		$s = RegularFileStat::read( $path );
		if ( null === $s || ( $s['mode'] & 0777 ) !== 0600 || 1 !== $s['nlink'] ) {
			return null;
		}
		return array(
			'dev'   => $s['dev'],
			'ino'   => $s['ino'],
			'size'  => $s['size'],
			'mode'  => $s['mode'] & 0777,
			'nlink' => $s['nlink'],
		);
	}
}
