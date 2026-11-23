<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Persistence;

use RAN\UpdaterSupport\V1\PackageDirectory;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RuntimeException;
use Throwable;

/**
 * Strict flock-serialised file journal; an unprovable write leaves execution state uncertain.
 *
 * @phpstan-type AttemptRecord array{id:string,state:string,package_type:string,slug:string,repository:string,branch:string,expected_head:string|null,selected_commit:string|null,expected_identifier:string|null,resolved_ref:string|null,mutation_started_at:string|null,outcome:string|null,finished_at:string|null}
 */
final class FileAttemptStore {
	/** @var array{dev:int,ino:int}|null */
	private ?array $active_parent_identity = null;

	public function __construct( private readonly string $path ) {}

	/**
	 * @return AttemptRecord
	 */
	public function begin( BranchDeploymentDeclaration $d ): array {
		// JSON object keys must survive PHP's array-key coercion on readback.
		if ( is_int( array_key_first( array( $d->attempt_id => true ) ) ) ) {
			throw new RuntimeException( 'Attempt ID cannot be stored as a string journal key.' );
		}
		return $this->mutate(
			function ( array $records ) use ( $d ): array {
				if ( isset( $records[ $d->attempt_id ] ) ) {
					throw new RuntimeException( 'Attempt already exists.' );
				}
				foreach ( $records as $record ) {
					if ( in_array( $record['state'], array( 'running', 'needs_attention' ), true ) && $record['package_type'] === $d->package_type && PackageDirectory::names_collide( $record['slug'], $d->slug ) ) {
						throw new RuntimeException( 'Target already has an unresolved execution.' );
					}
				}
				$records[ $d->attempt_id ] = $this->record( $d );
				return $records;
			}
		)[ $d->attempt_id ];
	}

	public function resolved( string $id, string $ref ): void {
		if ( '' === $ref || trim( $ref ) !== $ref || strlen( $ref ) > 191 || preg_match( '/[[:cntrl:]]/', $ref ) === 1 ) {
			throw new RuntimeException( 'Resolved revision is invalid.' );
		}
		$this->running_transition(
			$id,
			function ( array $record ) use ( $ref ): array {
				if ( null !== $record['resolved_ref'] || ( null !== $record['selected_commit'] && $record['selected_commit'] !== $ref ) ) {
					throw new RuntimeException( 'Revision is already recorded or differs from the selected commit.' );
				}
				$record['resolved_ref'] = $ref;
				return $record;
			}
		);
	}

	public function fence( string $id ): void {
		$this->running_transition(
			$id,
			static function ( array $record ): array {
				if ( null === $record['resolved_ref'] || null !== $record['mutation_started_at'] ) {
					throw new RuntimeException( 'Attempt cannot enter the mutation fence.' );
				}
				$record['mutation_started_at'] = gmdate( 'c' );
				return $record;
			}
		);
	}

	public function finish( string $id, string $state, string $outcome ): void {
		if ( ! in_array( $state, array( 'succeeded', 'failed', 'needs_attention' ), true ) || '' === $outcome ) {
			throw new RuntimeException( 'Attempt finish state is invalid.' );
		}
		$this->running_transition(
			$id,
			static function ( array $record ) use ( $state, $outcome ): array {
				if ( in_array( $state, array( 'succeeded', 'needs_attention' ), true ) && null === $record['mutation_started_at'] ) {
					throw new RuntimeException( 'An unfenced attempt cannot enter this terminal state.' );
				}
				$record['state']       = $state;
				$record['outcome']     = $outcome;
				$record['finished_at'] = gmdate( 'c' );
				return $record;
			}
		);
	}

	/** Stopped work before the fence failed safely; fenced work remains conservative. */
	public function recover_stopped( string $id ): void {
		$this->running_transition(
			$id,
			static function ( array $record ): array {
				$fenced                = null !== $record['mutation_started_at'];
				$record['state']       = $fenced ? 'needs_attention' : 'failed';
				$record['outcome']     = $fenced ? 'interrupted' : 'worker_stopped';
				$record['finished_at'] = gmdate( 'c' );
				return $record;
			}
		);
	}

	/**
	 * Read mutable journal state from disk on every call.
	 *
	 * @return AttemptRecord
	 * @phpstan-impure
	 */
	public function get( string $id ): array {
		return $this->locked(
			LOCK_SH,
			function () use ( $id ): array {
				$records = $this->read();
				if ( ! isset( $records[ $id ] ) ) {
					throw new RuntimeException( 'Attempt not found.' );
				}
				return $records[ $id ];
			}
		);
	}

	/**
	 * @param callable(AttemptRecord):AttemptRecord $change
	 */
	private function running_transition( string $id, callable $change ): void {
		$this->mutate(
			function ( array $records ) use ( $id, $change ): array {
				if ( ! isset( $records[ $id ] ) || 'running' !== $records[ $id ]['state'] ) {
					throw new RuntimeException( 'Attempt is not running.' );
				}
				$records[ $id ] = $change( $records[ $id ] );
				return $records;
			}
		);
	}

	/**
	 * @return AttemptRecord
	 */
	private function record( BranchDeploymentDeclaration $d ): array {
		return array(
			'id'                  => $d->attempt_id,
			'state'               => 'running',
			'package_type'        => $d->package_type,
			'slug'                => $d->slug,
			'repository'          => $d->repository,
			'branch'              => $d->branch,
			'expected_head'       => $d->expected_head,
			'selected_commit'     => $d->selected_commit,
			'expected_identifier' => $d->expected_identifier,
			'resolved_ref'        => null,
			'mutation_started_at' => null,
			'outcome'             => null,
			'finished_at'         => null,
		);
	}

	/** @return array<string,AttemptRecord> */
	private function read(): array {
		$this->assert_parent_identity();
		clearstatcache( true, $this->path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing journal paths return an empty store; suppress only the native missing-path warning.
		$existing = @lstat( $this->path );
		if ( false === $existing ) {
			return array();
		}
		if ( null === $this->regular_single_link_stat( $this->path ) || ! is_readable( $this->path ) ) {
			$this->fail( 'Journal path is unsafe.' );
		}
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the native journal under its descriptor lock before validating every record.
			$records = json_decode( (string) file_get_contents( $this->path ), true, 512, JSON_THROW_ON_ERROR );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the previous Throwable for internal journal diagnostics; this is not rendered output.
			throw new BranchDeploymentJournalFailure( 'Journal is malformed.', 0, $e );
		}
		if ( ! is_array( $records ) || array_is_list( $records ) ) {
			$this->fail( 'Journal is malformed.' );
		}
		foreach ( $records as $id => $record ) {
			if ( ! is_string( $id ) || ! is_array( $record ) || ! $this->valid_record( $id, $record ) ) {
				$this->fail( 'Journal is malformed.' );
			}
		}
		return $records;
	}

	/**
	 * @param array<array-key,mixed> $r
	 * @phpstan-assert-if-true AttemptRecord $r
	 */
	private function valid_record( string $id, array $r ): bool {
		$keys = array( 'id', 'state', 'package_type', 'slug', 'repository', 'branch', 'expected_head', 'selected_commit', 'expected_identifier', 'resolved_ref', 'mutation_started_at', 'outcome', 'finished_at' );
		sort( $keys );
		$actual = array_keys( $r );
		sort( $actual );
		if ( $keys !== $actual || ( $r['id'] ?? null ) !== $id || ! is_string( $r['state'] ?? null ) || ! in_array( $r['state'], array( 'running', 'succeeded', 'failed', 'needs_attention' ), true ) ) {
			return false;
		}
		foreach ( array( 'package_type', 'slug', 'repository', 'branch' ) as $field ) {
			if ( ! is_string( $r[ $field ] ?? null ) || '' === $r[ $field ] ) {
				return false;
			}
		}
		foreach ( array( 'expected_head', 'selected_commit', 'expected_identifier', 'resolved_ref', 'mutation_started_at', 'outcome', 'finished_at' ) as $field ) {
			if ( null !== $r[ $field ] && ( ! is_string( $r[ $field ] ) || '' === $r[ $field ] ) ) {
				return false;
			}
		}
		if ( null !== $r['selected_commit'] && ( null !== $r['expected_head'] || 1 !== preg_match( '/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $r['selected_commit'] ) || ( null !== $r['resolved_ref'] && $r['selected_commit'] !== $r['resolved_ref'] ) ) ) {
			return false;
		}
		if ( null !== $r['expected_identifier'] ) {
			try {
				new BranchDeploymentDeclaration( $id, $r['package_type'], $r['slug'], $r['repository'], 'journal', $r['branch'], null, operation: 'install', expected_identifier: $r['expected_identifier'] );
			} catch ( \RuntimeException ) {
				return false;
			}
		}
		if ( null !== $r['mutation_started_at'] && null === $r['resolved_ref'] ) {
			return false;
		}
		if ( 'running' === $r['state'] ) {
			return null === $r['outcome'] && null === $r['finished_at'];
		}
		if ( ! is_string( $r['outcome'] ) || ! is_string( $r['finished_at'] ) ) {
			return false;
		}
		if ( 'succeeded' === $r['state'] ) {
			return is_string( $r['resolved_ref'] ) && is_string( $r['mutation_started_at'] );
		}
		if ( 'needs_attention' === $r['state'] ) {
			return is_string( $r['resolved_ref'] ) && is_string( $r['mutation_started_at'] );
		}
		return true;
	}

	/**
	 * @param callable(array<string,AttemptRecord>):array<array-key,AttemptRecord> $change
	 * @return array<string,AttemptRecord>
	 */
	private function mutate( callable $change ): array {
		return $this->locked(
			LOCK_EX,
			function () use ( $change ): array {
				$records = $change( $this->read() );
				$this->write( $records );
				$readback = $this->read();
				if ( $readback !== $records ) {
					$this->fail( 'Journal replacement could not be read back.' );
				}
				return $readback;
			}
		);
	}

	/**
	 * @param array<array-key,AttemptRecord> $records
	 */
	private function write( array $records ): void {
		$this->assert_parent_identity();
		clearstatcache( true, $this->path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing journal paths are valid on first write; suppress only the native missing-path warning.
		$existing = @lstat( $this->path );
		if ( false !== $existing && null === $this->regular_single_link_stat( $this->path ) ) {
			$this->fail( 'Journal path is unsafe.' );
		}
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Preserve JSON_THROW_ON_ERROR and exact journal serialization before atomic replacement and readback.
			$json = json_encode( $records, JSON_THROW_ON_ERROR );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the previous Throwable for internal journal diagnostics; this is not rendered output.
			throw new BranchDeploymentJournalFailure( 'Journal cannot be encoded.', 0, $e );
		}
		try {
			$suffix = bin2hex( random_bytes( 8 ) );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the previous Throwable for internal journal diagnostics; this is not rendered output.
			throw new BranchDeploymentJournalFailure( 'Journal temporary name cannot be generated.', 0, $e );
		}
		$tmp = $this->path . '.new.' . $suffix;
		clearstatcache( true, $tmp );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Pre-check unique temporary path before exclusive creation.
		if ( false !== @lstat( $tmp ) ) {
			$this->fail( 'Journal temporary path is unsafe.' );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusively create the unique sibling journal file before atomic replacement.
		$handle = @fopen( $tmp, 'x+b' );
		if ( false === $handle ) {
			$this->fail( 'Journal replacement failed.' );
		}
		$created = $this->handle_single_link_stat( $handle );
		$ok      = null !== $created;
		try {
			if ( $ok ) {
				$path_stat = $this->regular_single_link_stat( $tmp );
				if ( null === $path_stat || $path_stat['dev'] !== $created['dev'] || $path_stat['ino'] !== $created['ino'] ) {
					$ok = false;
				}
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restrict the native temporary journal file to owner-only permissions before writing.
			if ( $ok && ! @chmod( $tmp, 0600 ) ) {
				$ok = false;
			}
			if ( $ok ) {
				$after_chmod = $this->handle_single_link_stat( $handle );
				if ( null === $after_chmod || $after_chmod['dev'] !== $created['dev'] || $after_chmod['ino'] !== $created['ino'] || ( $after_chmod['mode'] & 0777 ) !== 0600 ) {
					$ok = false;
				}
			}
			$length = strlen( $json );
			for ( $written = 0; $ok && $written < $length; ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write exact journal JSON bytes to the exclusively created sibling file.
				$chunk = fwrite( $handle, substr( $json, $written ) );
				if ( false === $chunk || 0 === $chunk ) {
					$ok = false;
					break;
				}
				$written += $chunk;
			}
			if ( $ok && ! fflush( $handle ) ) {
				$ok = false;
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exclusively created sibling journal descriptor before rename or cleanup.
			if ( ! fclose( $handle ) ) {
				$ok = false;
			}
		}
		$this->assert_parent_identity();
		$tmp_stat = $this->regular_single_link_stat( $tmp );
		$matches  = null !== $created
			&& null !== $tmp_stat
			&& $tmp_stat['dev'] === $created['dev']
			&& $tmp_stat['ino'] === $created['ino']
			&& ( $tmp_stat['mode'] & 0777 ) === 0600;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomically replace the native journal with the private sibling file before readback.
		if ( ! $ok || ! $matches || ! rename( $tmp, $this->path ) ) {
			if ( null !== $created && null !== $tmp_stat && $tmp_stat['dev'] === $created['dev'] && $tmp_stat['ino'] === $created['ino'] ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Clean up only the matching failed unique temporary journal file.
				unlink( $tmp );
			}
			$this->fail( 'Journal replacement failed.' );
		}
		$final_stat = $this->regular_single_link_stat( $this->path );
		if ( null === $final_stat || ( $final_stat['mode'] & 0777 ) !== 0600 ) {
			$this->fail( 'Journal replacement is unsafe.' );
		}
	}

	/**
	 * @template TResult
	 * @param LOCK_SH|LOCK_EX $mode
	 * @param callable():TResult $operation
	 * @return TResult
	 */
	private function locked( int $mode, callable $operation ): mixed {
		$parent_identity = $this->ensure_parent();
		$lock_path       = $this->path . '.lock';
		clearstatcache( true, $lock_path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing lock files are created by fopen('c+'); suppress only the native missing-path warning.
		$existing = @lstat( $lock_path );
		if ( false !== $existing && null === $this->regular_single_link_stat( $lock_path ) ) {
			$this->fail( 'Journal lock path is unsafe.' );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- flock requires the native journal lock descriptor across the complete read or mutation.
		$handle = @fopen( $lock_path, 'c+' );
		if ( false === $handle ) {
			throw new BranchDeploymentJournalFailure( 'Journal lock is unavailable.' );
		}
		$opened = $this->handle_single_link_stat( $handle );
		if ( null === $opened ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the unsafe lock descriptor without mutating pathname permissions.
			fclose( $handle );
			$this->fail( 'Journal lock path is unsafe.' );
		}
		if ( ! flock( $handle, $mode ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the lock descriptor when lock acquisition fails.
			fclose( $handle );
			throw new BranchDeploymentJournalFailure( 'Journal lock is unavailable.' );
		}
		$previous_parent              = $this->active_parent_identity;
		$this->active_parent_identity = $parent_identity;
		try {
			$this->assert_parent_identity();
			$locked_handle = $this->handle_single_link_stat( $handle );
			$locked_path   = $this->regular_single_link_stat( $lock_path );
			if ( null === $locked_handle
				|| null === $locked_path
				|| $locked_handle['dev'] !== $locked_path['dev']
				|| $locked_handle['ino'] !== $locked_path['ino']
			) {
				$this->fail( 'Journal lock path is unsafe.' );
			}
			if ( ( $locked_handle['mode'] & 0777 ) !== 0600 ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restrict the verified single-link native journal lock file to owner-only permissions under the lock.
				if ( ! @chmod( $lock_path, 0600 ) ) {
					$this->fail( 'Journal lock path is unsafe.' );
				}
				$this->assert_parent_identity();
				$after_handle = $this->handle_single_link_stat( $handle );
				$after_path   = $this->regular_single_link_stat( $lock_path );
				if ( null === $after_handle
					|| null === $after_path
					|| $after_handle['dev'] !== $locked_handle['dev']
					|| $after_handle['ino'] !== $locked_handle['ino']
					|| $after_path['dev'] !== $locked_handle['dev']
					|| $after_path['ino'] !== $locked_handle['ino']
					|| ( $after_handle['mode'] & 0777 ) !== 0600
				) {
					$this->fail( 'Journal lock path is unsafe.' );
				}
			}
			return $operation();
		} finally {
			$this->active_parent_identity = $previous_parent;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the same journal descriptor after unlocking and preserve failure reporting.
			if ( ! flock( $handle, LOCK_UN ) || ! fclose( $handle ) ) {
				throw new BranchDeploymentJournalFailure( 'Journal lock release failed.' );
			}
		}
	}

	/** @return array{dev:int,ino:int} */
	private function ensure_parent(): array {
		$parent = dirname( $this->path );
		clearstatcache( true, $parent );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing journal parent directories are created below; suppress only the native missing-path warning.
		$existing = @lstat( $parent );
		if ( false !== $existing && ( $existing['mode'] & 0170000 ) !== 0040000 ) {
			$this->fail( 'Journal directory is unsafe.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the native private journal directory before opening the lock descriptor.
		if ( false === $existing && ! mkdir( $parent, 0700, true ) ) {
			$this->fail( 'Journal directory cannot be created.' );
		}
		clearstatcache( true, $parent );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Re-verify parent directory type and ownership before applying permissions.
		$created = @lstat( $parent );
		if ( ! is_array( $created ) || ( $created['mode'] & 0170000 ) !== 0040000 || ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== (int) $created['uid'] ) ) {
			$this->fail( 'Journal directory is unsafe.' );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Require a real private native journal directory before accessing persistent state.
		if ( ! @chmod( $parent, 0700 ) ) {
			$this->fail( 'Journal directory is unsafe.' );
		}
		$identity = $this->directory_identity( $parent );
		if ( null === $identity || $identity['dev'] !== (int) $created['dev'] || $identity['ino'] !== (int) $created['ino'] ) {
			$this->fail( 'Journal directory is unsafe.' );
		}
		return $identity;
	}

	private function assert_parent_identity(): void {
		if ( null === $this->active_parent_identity ) {
			return;
		}
		$current = $this->directory_identity( dirname( $this->path ) );
		if ( null === $current || $current !== $this->active_parent_identity ) {
			$this->fail( 'Journal directory is unsafe.' );
		}
	}

	/** @return array{dev:int,ino:int}|null */
	private function directory_identity( string $directory ): ?array {
		clearstatcache( true, $directory );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing or replaced parent directories fail the identity check.
		$stat = @lstat( $directory );
		if ( ! is_array( $stat )
			|| ( $stat['mode'] & 0170000 ) !== 0040000
			|| ( $stat['mode'] & 0777 ) !== 0700
			|| ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== (int) $stat['uid'] )
		) {
			return null;
		}
		return array(
			'dev' => (int) $stat['dev'],
			'ino' => (int) $stat['ino'],
		);
	}

	/** @return array{dev:int,ino:int,mode:int}|null */
	private function regular_single_link_stat( string $path ): ?array {
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing or replaced paths fail the safety check without leaking warnings.
		$stat = @lstat( $path );
		return $this->validate_single_link_stat( $stat );
	}

	/**
	 * @param resource $handle
	 * @return array{dev:int,ino:int,mode:int}|null
	 */
	private function handle_single_link_stat( $handle ): ?array {
		return $this->validate_single_link_stat( fstat( $handle ) );
	}

	/**
	 * @param array<int|string,int>|false $stat
	 * @return array{dev:int,ino:int,mode:int}|null
	 */
	private function validate_single_link_stat( array|false $stat ): ?array {
		if ( ! is_array( $stat )
			|| ( $stat['mode'] & 0170000 ) !== 0100000
			|| 1 !== (int) $stat['nlink']
			|| ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== (int) $stat['uid'] )
		) {
			return null;
		}
		return array(
			'dev'  => (int) $stat['dev'],
			'ino'  => (int) $stat['ino'],
			'mode' => (int) $stat['mode'],
		);
	}

	/** @return never */
	private function fail( string $message ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The internal journal failure message is not rendered output and must retain its diagnostic value.
		throw new BranchDeploymentJournalFailure( $message );
	}
}
