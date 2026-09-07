<?php
declare(strict_types=1);
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Typed failures and lock adapters are one package boundary.
// phpcs:disable WordPress.WP.AlternativeFunctions -- flock relies on direct local file descriptors.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are not rendered.
namespace RAN\BranchDeployment;

use RuntimeException;
use Throwable;

/** The exact mutation lock token could not be released, so state is uncertain. */
final class BranchDeploymentLockReleaseFailure extends RuntimeException {}
/** A WordPress updater-lock database read/write could not be reconciled. */
final class BranchDeploymentLockStorageFailure extends RuntimeException {}

final class FileMutationLock implements MutationLock {
	public function __construct( private readonly string $path ) {}
	public function run( callable $operation ): mixed {
		$handle = fopen( $this->path, 'c+' );
		if ( false === $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			throw new RuntimeException( 'Mutation lock is held.' );
		}
		try {
			return $operation();
		} finally {
			if ( ! flock( $handle, LOCK_UN ) || ! fclose( $handle ) ) {
				throw new BranchDeploymentLockReleaseFailure( 'Mutation lock could not be released.' );
			}
		}
	}
}

/** Exact-token auto_updater.lock adapter, including stale-token and option-cache behaviour. */
class WordPressUpdaterLock implements MutationLock {
	private const NAME    = 'auto_updater.lock';
	private const TIMEOUT = 3600;
	public function run( callable $operation ): mixed {
		$token = $this->acquire();
		try {
			return $operation();
		} finally {
			try {
				$released = $this->release( $token );
			} catch ( Throwable $failure ) {
				throw new BranchDeploymentLockReleaseFailure( 'WordPress updater lock release failed.', 0, $failure );
			}
			if ( ! $released ) {
				throw new BranchDeploymentLockReleaseFailure( 'WordPress updater lock could not be released.' );
			}
		}
	}
	public function currentToken(): ?string {
		global $wpdb;
		$this->database();
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::NAME ) );
		$this->assertDatabase();
		return is_string( $stored ) && 1 === preg_match( '/^\d+$/D', $stored ) && (int) $stored > time() - self::TIMEOUT ? $stored : null;
	}
	public function acquire(): string {
		global $wpdb;
		$this->database();
		$token = (string) time();
		if ( $this->insert( $token ) ) {
			return $token;
		}
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::NAME ) );
		$this->assertDatabase();
		if ( ! is_string( $stored )
			|| 1 !== preg_match( '/^\d+$/D', $stored )
			|| (int) $stored > time() - self::TIMEOUT
			|| ! $this->release( $stored )
			|| ! $this->insert( $token ) ) {
			throw $this->contentionFailure();
		}
		return $token;
	}
	public function release( string $token ): bool {
		global $wpdb;
		$this->database();
		$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, self::NAME, $token ) );
		if ( false === $result ) {
			throw new BranchDeploymentLockStorageFailure( 'WordPress updater database is unavailable.' );
		}
		if ( 1 === $result ) {
			$this->invalidateOptionCache();
		}
		return 1 === $result;
	}
	/** Core retains its established diagnostic without retaining the algorithm. */
	protected function contentionFailure(): RuntimeException {
		return new RuntimeException( 'WordPress updater is already running.' );
	}
	private function insert( string $token ): bool {
		global $wpdb;
		$this->database();
		$result = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::NAME, $token, 'no' ) );
		if ( false === $result ) {
			throw new BranchDeploymentLockStorageFailure( 'WordPress updater database is unavailable.' );
		}
		if ( 1 === $result ) {
			$this->invalidateOptionCache();
		}
		return 1 === $result;
	}
	private function database(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			throw new BranchDeploymentLockStorageFailure( 'WordPress database is unavailable.' );
		}
	}
	private function assertDatabase(): void {
		global $wpdb;
		if ( property_exists( $wpdb, 'last_error' ) && '' !== trim( (string) $wpdb->last_error ) ) {
			throw new BranchDeploymentLockStorageFailure( 'WordPress updater database is unavailable.' );
		}
	}
	private function invalidateOptionCache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::NAME, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}
}
