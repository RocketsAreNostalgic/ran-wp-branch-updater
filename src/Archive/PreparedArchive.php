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
	 * @param array{dev:int,ino:int,size:int,mode:int,nlink:int,uid?:int,gid?:int,mtime?:int,ctime?:int} $identity
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
			|| ( 0 === (int) $pre_dir_stat['ino']
				&& ( (int) $directory_stat['uid'] !== (int) $pre_dir_stat['uid']
					|| (int) $directory_stat['gid'] !== (int) $pre_dir_stat['gid']
					|| (int) $directory_stat['mtime'] !== (int) $pre_dir_stat['mtime'] ) )
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
		$created = null;
		$owned   = null;
		try {
			$fstat = fstat( $handle );
			if ( ! is_array( $fstat )
				|| ( $fstat['mode'] & 0170000 ) !== 0100000
				|| 1 !== (int) $fstat['nlink']
				|| 0 !== (int) $fstat['size']
			) {
				throw new RuntimeException( 'Private archive identity is invalid.' );
			}
			$owned     = self::stat_snapshot( $fstat );
			$pre_chmod = RegularFileStat::read( $path );
			if ( null === $pre_chmod
				|| 1 !== $pre_chmod['nlink']
				|| ! self::same_regular_stat( $owned, $pre_chmod )
			) {
				throw new RuntimeException( 'Private archive identity is invalid.' );
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Make the verified exclusively created native archive file private before acquisition.
			if ( ! @chmod( $path, 0600 ) ) {
				throw new RuntimeException( 'Cannot create private archive file.' );
			}
			$after_chmod = fstat( $handle );
			$post_chmod  = RegularFileStat::read( $path );
			$created     = null === $post_chmod ? null : self::identity_from_stat( $post_chmod );
			if ( ! is_array( $after_chmod ) ) {
				$created = null;
				throw new RuntimeException( 'Private archive identity is invalid.' );
			}
			$handle_after = self::stat_snapshot( $after_chmod );
			if ( ( $handle_after['mode'] & 0170000 ) === 0100000
				&& 1 === $handle_after['nlink']
				&& 0 === $handle_after['size']
				&& $handle_after['dev'] === $owned['dev']
				&& $handle_after['ino'] === $owned['ino']
				&& ( 0 !== $owned['ino']
					|| ( $handle_after['uid'] === $owned['uid']
						&& $handle_after['gid'] === $owned['gid']
						&& $handle_after['mtime'] === $owned['mtime'] ) )
			) {
				$owned = $handle_after;
			}
			if ( null === $post_chmod
				|| null === $created
				|| ( $handle_after['mode'] & 0170000 ) !== 0100000
				|| ( $handle_after['mode'] & 0777 ) !== 0600
				|| 1 !== $handle_after['nlink']
				|| 0 !== $handle_after['size']
				|| $handle_after !== $owned
				|| ! self::same_regular_stat( $owned, $post_chmod )
				|| 0 !== $created['size']
			) {
				$created = null;
				throw new RuntimeException( 'Private archive identity is invalid.' );
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exclusively created descriptor before identity-checked cleanup.
			fclose( $handle );
			if ( null !== $owned ) {
				self::cleanup_matching_file( $path, $owned, null, $e );
			}
			throw $e;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the verified archive file descriptor only after descriptor-to-path identity is established.
		if ( ! fclose( $handle ) ) {
			$close_error = new RuntimeException( 'Cannot create private archive file.' );
			self::cleanup_matching_file( $path, $owned, null, $close_error );
			throw $close_error;
		}
		$owned_digest = null;
		try {
			$offer->acquire( $path, $maximum_artifact_bytes );
			$written  = RegularFileStat::read( $path );
			$identity = null === $written ? null : self::identity_from_stat( $written );
			if ( null === $written
				|| null === $identity
				|| $written['dev'] !== $created['dev']
				|| $written['ino'] !== $created['ino']
				|| ( 0 === $created['ino'] && ( $written['uid'] !== $owned['uid'] || $written['gid'] !== $owned['gid'] ) )
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
			if ( 0 === $written['ino'] && RegularFileStat::read( $path ) !== $written ) {
				throw new RuntimeException( 'Archive file identity or size is invalid.' );
			}
			$owned        = $written;
			$owned_digest = $digest;
			$inspection   = ( new ArchiveValidator() )->validate(
				$path,
				$d,
				'update' === $d->operation ? $d->installed_identifier : null,
				$maximum_artifact_bytes,
				$maximum_expanded_bytes,
				self::runtime_wordpress_version()
			);
			$archive      = new self( $path, $offer->resolved_ref, $identity, $digest, $inspection['expected_version'], $inspection['expanded'] );
			$archive->assert_unchanged();
			return $archive;
		} catch ( \Throwable $e ) {
			self::cleanup_matching_file( $path, $owned, $owned_digest, $e );
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
			} elseif ( 0 === $this->identity['ino'] && self::identity( $this->path ) !== $this->identity ) {
				$changed = 'identity';
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

	/**
	 * @param array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int} $owned
	 */
	private static function cleanup_matching_file( string $path, array $owned, ?string $digest, \Throwable $cause ): void {
		$current = RegularFileStat::read( $path );
		if ( null !== $current && self::owns_file( $path, $current, $owned, $digest ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete only the rejected archive whose verified file identity still matches the owned file.
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
	 * Without an inode number, a matching device does not identify the file.
	 * Require the last verified metadata and content instead.
	 *
	 * @param array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int} $current
	 * @param array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int} $owned
	 */
	private static function owns_file( string $path, array $current, array $owned, ?string $digest ): bool {
		if ( 1 !== $current['nlink'] || ! self::same_regular_stat( $owned, $current ) ) {
			return false;
		}
		if ( 0 !== $owned['ino'] || null === $digest ) {
			return true;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Concurrent removal or read failure during zero-inode cleanup ownership verification fails closed.
		$current_digest = @hash_file( 'sha256', $path );
		return is_string( $current_digest )
			&& hash_equals( $digest, $current_digest )
			&& RegularFileStat::read( $path ) === $current;
	}

	/**
	 * @param array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int} $expected
	 * @param array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int} $observed
	 */
	private static function same_regular_stat( array $expected, array $observed ): bool {
		if ( $expected['dev'] !== $observed['dev'] || $expected['ino'] !== $observed['ino'] ) {
			return false;
		}
		if ( 0 !== $expected['ino'] ) {
			return true;
		}
		return $expected === $observed;
	}

	/**
	 * @param array<int|string,int> $stat
	 * @return array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int}
	 */
	private static function stat_snapshot( array $stat ): array {
		return array(
			'dev'   => (int) $stat['dev'],
			'ino'   => (int) $stat['ino'],
			'mode'  => (int) $stat['mode'],
			'nlink' => (int) $stat['nlink'],
			'uid'   => (int) $stat['uid'],
			'gid'   => (int) $stat['gid'],
			'size'  => (int) $stat['size'],
			'mtime' => (int) $stat['mtime'],
			'ctime' => (int) $stat['ctime'],
		);
	}

	/**
	 * @return array{dev:int,ino:int,size:int,mode:int,nlink:int,uid?:int,gid?:int,mtime?:int,ctime?:int}|null
	 */
	private static function identity( string $path ): ?array {
		$s = RegularFileStat::read( $path );
		return null === $s ? null : self::identity_from_stat( $s );
	}

	/**
	 * @param array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int} $s
	 * @return array{dev:int,ino:int,size:int,mode:int,nlink:int,uid?:int,gid?:int,mtime?:int,ctime?:int}|null
	 */
	private static function identity_from_stat( array $s ): ?array {
		if ( ( $s['mode'] & 0777 ) !== 0600 || 1 !== $s['nlink'] ) {
			return null;
		}
		$identity = array(
			'dev'   => $s['dev'],
			'ino'   => $s['ino'],
			'size'  => $s['size'],
			'mode'  => $s['mode'] & 0777,
			'nlink' => $s['nlink'],
		);
		if ( 0 === $s['ino'] ) {
			$identity['uid']   = $s['uid'];
			$identity['gid']   = $s['gid'];
			$identity['mtime'] = $s['mtime'];
			$identity['ctime'] = $s['ctime'];
		}
		return $identity;
	}
}
