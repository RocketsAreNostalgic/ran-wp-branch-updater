<?php
declare(strict_types=1);
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The journal's typed failure is inseparable from this adapter.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Atomic local journal custody requires direct filesystem calls.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are not rendered.
namespace RAN\BranchDeployment;

use RuntimeException;
use Throwable;

/** A persistence failure means the durable execution state is unknown. */
final class BranchDeploymentJournalFailure extends RuntimeException {}

/** Strict flock-serialised file journal; an unprovable write leaves execution state uncertain. */
final class FileAttemptStore {
	public function __construct( private readonly string $path ) {}
	public function begin( Deployment $d ): array {
		return $this->mutate(
			function ( array $records ) use ( $d ): array {
				if ( isset( $records[ $d->attemptId ] ) ) {
					throw new RuntimeException( 'Attempt already exists.' ); }
				foreach ( $records as $record ) {
					if ( in_array( $record['state'], array( 'running', 'needs_attention' ), true ) && $record['package_type'] === $d->packageType && $record['slug'] === $d->slug ) {
						throw new RuntimeException( 'Target already has an unresolved execution.' ); }
				}
				$records[ $d->attemptId ] = $this->record( $d );
				return $records;
			}
		)[ $d->attemptId ];
	}
	public function resolved( string $id, string $ref ): void {
		if ( '' === $ref || trim( $ref ) !== $ref || strlen( $ref ) > 191 || preg_match( '/[[:cntrl:]]/', $ref ) === 1 ) {
			throw new RuntimeException( 'Resolved revision is invalid.' );
		}
		$this->runningTransition(
			$id,
			function ( array $record ) use ( $ref ): array {
				if ( null !== $record['resolved_ref'] ) {
					throw new RuntimeException( 'Revision is already recorded.' ); }
				$record['resolved_ref'] = $ref;
				return $record;
			}
		);
	}
	public function fence( string $id ): void {
		$this->runningTransition(
			$id,
			static function ( array $record ): array {
				if ( null === $record['resolved_ref'] || null !== $record['mutation_started_at'] ) {
					throw new RuntimeException( 'Attempt cannot enter the mutation fence.' ); }
				$record['mutation_started_at'] = gmdate( 'c' );
				return $record;
			}
		);
	}
	public function finish( string $id, string $state, string $outcome ): void {
		if ( ! in_array( $state, array( 'succeeded', 'failed', 'needs_attention' ), true ) || '' === $outcome ) {
			throw new RuntimeException( 'Attempt finish state is invalid.' );
		}
		$this->runningTransition(
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
	public function recoverStopped( string $id ): void {
		$this->runningTransition(
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
	private function runningTransition( string $id, callable $change ): void {
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
	private function record( Deployment $d ): array {
		return array(
			'id'                  => $d->attemptId,
			'state'               => 'running',
			'package_type'        => $d->packageType,
			'slug'                => $d->slug,
			'repository'          => $d->repository,
			'branch'              => $d->branch,
			'expected_head'       => $d->expectedHead,
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
			$records = json_decode( (string) file_get_contents( $this->path ), true, 512, JSON_THROW_ON_ERROR );
		} catch ( Throwable $e ) {
			throw new BranchDeploymentJournalFailure( 'Journal is malformed.', 0, $e );
		}
		if ( ! is_array( $records ) || array_is_list( $records ) ) {
			$this->fail( 'Journal is malformed.' );
		}
		foreach ( $records as $id => $record ) {
			if ( ! is_string( $id ) || ! is_array( $record ) || ! $this->validRecord( $id, $record ) ) {
				$this->fail( 'Journal is malformed.' );
			}
		}
		return $records;
	}
	private function validRecord( string $id, array $r ): bool {
		$keys = array( 'id', 'state', 'package_type', 'slug', 'repository', 'branch', 'expected_head', 'resolved_ref', 'mutation_started_at', 'outcome', 'finished_at' );
		sort( $keys );
		$actual = array_keys( $r );
		sort( $actual );
		if ( $keys !== $actual || $id !== ( $r['id'] ?? null ) || ! is_string( $r['state'] ?? null ) || ! in_array( $r['state'], array( 'running', 'succeeded', 'failed', 'needs_attention' ), true ) ) {
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
		if ( 'needs_attention' === $r['state'] && null !== $r['mutation_started_at'] ) {
			return is_string( $r['resolved_ref'] );
		}
		// Versions before the stricter transition invariant could persist a
		// pre-fence needs_attention record. Keep that conservative legacy state
		// readable so it continues to block automatic target reuse after upgrade.
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
			$json = json_encode( $records, JSON_THROW_ON_ERROR );
		} catch ( Throwable $e ) {
			throw new BranchDeploymentJournalFailure( 'Journal cannot be encoded.', 0, $e );
		}
		$tmp = $this->path . '.new';
		if ( false === file_put_contents( $tmp, $json, LOCK_EX ) || ! rename( $tmp, $this->path ) ) {
			$this->fail( 'Journal replacement failed.' );
		}
		clearstatcache( true, $this->path );
		if ( is_link( $this->path ) || ! is_file( $this->path ) ) {
			$this->fail( 'Journal replacement is unsafe.' );
		}
	}
	private function locked( int $mode, callable $operation ): mixed {
		$this->ensureParent();
		$handle = fopen( $this->path . '.lock', 'c+' );
		if ( false === $handle || ! flock( $handle, $mode ) ) {
			throw new BranchDeploymentJournalFailure( 'Journal lock is unavailable.' );
		}
		try {
			return $operation();
		} finally {
			if ( ! flock( $handle, LOCK_UN ) || ! fclose( $handle ) ) {
				throw new BranchDeploymentJournalFailure( 'Journal lock release failed.' );
			}
		}
	}
	private function ensureParent(): void {
		$parent = dirname( $this->path );
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0700, true ) ) {
			$this->fail( 'Journal directory cannot be created.' );
		}
		if ( ! is_dir( $parent ) || is_link( $parent ) || ! chmod( $parent, 0700 ) ) {
			$this->fail( 'Journal directory is unsafe.' );
		}
	}
	/** @return never */
	private function fail( string $message ): never {
		throw new BranchDeploymentJournalFailure( $message );
	}
}
