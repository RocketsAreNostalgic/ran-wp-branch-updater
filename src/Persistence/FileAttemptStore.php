<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Persistence;

use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RuntimeException;
use Throwable;

/** Strict flock-serialised file journal; an unprovable write leaves execution state uncertain. */
final class FileAttemptStore {
	public function __construct( private readonly string $path ) {}

	public function begin( BranchDeploymentDeclaration $d ): array {
		return $this->mutate(
			function ( array $records ) use ( $d ): array {
				if ( isset( $records[ $d->attempt_id ] ) ) {
					throw new RuntimeException( 'Attempt already exists.' );
				}
				foreach ( $records as $record ) {
					if ( in_array( $record['state'], array( 'running', 'needs_attention' ), true ) && $record['package_type'] === $d->package_type && $record['slug'] === $d->slug ) {
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
				if ( null !== $record['resolved_ref'] ) {
					throw new RuntimeException( 'Revision is already recorded.' );
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

	private function record( BranchDeploymentDeclaration $d ): array {
		return array(
			'id'                  => $d->attempt_id,
			'state'               => 'running',
			'package_type'        => $d->package_type,
			'slug'                => $d->slug,
			'repository'          => $d->repository,
			'branch'              => $d->branch,
			'expected_head'       => $d->expected_head,
			'resolved_ref'        => null,
			'mutation_started_at' => null,
			'outcome'             => null,
			'finished_at'         => null,
		);
	}

	/** @return array<string,array<string,string|null>> */
	private function read(): array {
		if ( ! file_exists( $this->path ) && ! is_link( $this->path ) ) {
			return array();
		}
		if ( is_link( $this->path ) || ! is_file( $this->path ) || ! is_readable( $this->path ) ) {
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

	private function valid_record( string $id, array $r ): bool {
		$keys = array( 'id', 'state', 'package_type', 'slug', 'repository', 'branch', 'expected_head', 'resolved_ref', 'mutation_started_at', 'outcome', 'finished_at' );
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
		foreach ( array( 'expected_head', 'resolved_ref', 'mutation_started_at', 'outcome', 'finished_at' ) as $field ) {
			if ( null !== $r[ $field ] && ( ! is_string( $r[ $field ] ) || '' === $r[ $field ] ) ) {
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

	private function write( array $records ): void {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Preserve JSON_THROW_ON_ERROR and exact journal serialization before atomic replacement and readback.
			$json = json_encode( $records, JSON_THROW_ON_ERROR );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the previous Throwable for internal journal diagnostics; this is not rendered output.
			throw new BranchDeploymentJournalFailure( 'Journal cannot be encoded.', 0, $e );
		}
		$tmp = $this->path . '.new';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.rename_rename -- Write the locked sibling file and atomically replace the native journal before readback.
		if ( false === file_put_contents( $tmp, $json, LOCK_EX ) || ! rename( $tmp, $this->path ) ) {
			$this->fail( 'Journal replacement failed.' );
		}
		clearstatcache( true, $this->path );
		if ( is_link( $this->path ) || ! is_file( $this->path ) ) {
			$this->fail( 'Journal replacement is unsafe.' );
		}
	}

	private function locked( int $mode, callable $operation ): mixed {
		$this->ensure_parent();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- flock requires the native journal lock descriptor across the complete read or mutation.
		$handle = fopen( $this->path . '.lock', 'c+' );
		if ( false === $handle || ! flock( $handle, $mode ) ) {
			throw new BranchDeploymentJournalFailure( 'Journal lock is unavailable.' );
		}
		try {
			return $operation();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the same journal descriptor after unlocking and preserve failure reporting.
			if ( ! flock( $handle, LOCK_UN ) || ! fclose( $handle ) ) {
				throw new BranchDeploymentJournalFailure( 'Journal lock release failed.' );
			}
		}
	}

	private function ensure_parent(): void {
		$parent = dirname( $this->path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the native private journal directory before opening the lock descriptor.
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0700, true ) ) {
			$this->fail( 'Journal directory cannot be created.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Require a real private native journal directory before accessing persistent state.
		if ( ! is_dir( $parent ) || is_link( $parent ) || ! chmod( $parent, 0700 ) ) {
			$this->fail( 'Journal directory is unsafe.' );
		}
	}

	/** @return never */
	private function fail( string $message ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The internal journal failure message is not rendered output and must retain its diagnostic value.
		throw new BranchDeploymentJournalFailure( $message );
	}
}
