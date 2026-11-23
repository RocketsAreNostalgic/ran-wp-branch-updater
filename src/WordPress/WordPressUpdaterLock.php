<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\WordPress;

use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockReleaseFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockStorageFailure;
use RAN\WPBranchUpdater\V1\Runtime\FailureReason;
use RAN\WPBranchUpdater\V1\Runtime\ReasonedFailure;
use RuntimeException;
use Throwable;

/** Exact-token auto_updater.lock adapter, including stale-token and option-cache behaviour. */
class WordPressUpdaterLock implements MutationLock {
	private const NAME             = 'auto_updater.lock';
	private const TIMEOUT          = 3600;
	private const ACQUIRE_ATTEMPTS = 3;

	private ?FailureReason $contention_reason = null;

	public function run( callable $operation ): mixed {
		$token             = $this->acquire();
		$operation_failure = null;
		try {
			return $operation();
		} catch ( Throwable $failure ) {
			$operation_failure = $failure;
			throw $failure;
		} finally {
			try {
				$released = $this->release( $token );
			} catch ( Throwable $failure ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the previous Throwable as the exception cause, not rendered output.
				throw new BranchDeploymentLockReleaseFailure( 'WordPress updater lock release failed.', 0, $operation_failure ?? $failure );
			}
			if ( ! $released ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the operation failure as the exception cause, not rendered output.
				throw new BranchDeploymentLockReleaseFailure( 'WordPress updater lock could not be released.', 0, $operation_failure );
			}
		}
	}

	public function current_token(): ?string {
		$row    = $this->read_row();
		$stored = $row->option_value ?? null;
		return self::valid_token( $stored ) && $stored > time() - self::TIMEOUT ? $stored : null;
	}

	/**
	 * Acquire only an absent row. Present rows, including expired or malformed
	 * tokens, belong to their existing owner/recovery policy and are never reclaimed.
	 * Database failure throws; null means occupancy, not permission to proceed.
	 */
	public function try_acquire_without_reclaim(): ?string {
		$token = self::new_token();
		return $this->insert( $token ) ? $token : null;
	}

	public function acquire(): string {
		$this->contention_reason = FailureReason::LOCK_HELD;
		$token                   = self::new_token();
		for ( $attempt = 0; $attempt < self::ACQUIRE_ATTEMPTS; ++$attempt ) {
			if ( $this->insert( $token ) ) {
				return $token;
			}
			$row = $this->read_row();
			if ( null === $row ) {
				// The owner can release between our failed INSERT and the read.
				continue;
			}
			$stored = $row->option_value ?? null;
			if ( ! self::valid_token( $stored ) ) {
				$this->contention_reason = FailureReason::LOCK_TOKEN_INVALID;
				break;
			}
			if ( $stored > time() - self::TIMEOUT ) {
				break;
			}
			if ( $this->replace_stale( $stored, $token ) ) {
				return $token;
			}
			// A concurrent release or takeover changed the row. Retry against current ownership.
		}
		throw $this->contention_failure();
	}

	public function release( string $token ): bool {
		global $wpdb;
		$this->database();
		$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS BINARY) = %s', $wpdb->options, self::NAME, $token ) );
		return $this->changed_row( $result );
	}

	/** Core retains its established diagnostic without retaining the algorithm. */
	protected function contention_failure(): RuntimeException {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A closed reason id is recorded outcome data, not rendered output.
		return new ReasonedFailure( 'WordPress updater is already running.', ( $this->contention_reason ?? FailureReason::LOCK_HELD )->value );
	}

	private function insert( string $token ): bool {
		global $wpdb;
		$this->database();
		$result = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::NAME, $token, 'off' ) );
		return $this->changed_row( $result );
	}

	private function replace_stale( string $stored, string $token ): bool {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = %s', $wpdb->options, $token, 'off', self::NAME, $stored ) );
		return $this->changed_row( $result );
	}

	private function changed_row( int|false $result ): bool {
		if ( false === $result ) {
			throw new BranchDeploymentLockStorageFailure( 'WordPress updater database is unavailable.' );
		}
		if ( 1 === $result ) {
			$this->invalidate_option_cache();
		}
		return 1 === $result;
	}

	private function read_row(): ?object {
		global $wpdb;
		$this->database();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::NAME ) );
		$this->assert_database();
		return $row;
	}

	private static function new_token(): string {
		// Core compares the option numerically to its expiry timestamp. Keep the timestamp
		// as the integer part and use a random decimal fraction for exact-token ownership.
		return sprintf( '%d.%09d%09d%09d', time(), random_int( 0, 999999999 ), random_int( 0, 999999999 ), random_int( 0, 999999999 ) );
	}

	/** @phpstan-assert-if-true string $token */
	private static function valid_token( mixed $token ): bool {
		return is_string( $token ) && 1 === preg_match( '/^[0-9]+(?:\.[0-9]{27})?$/D', $token );
	}

	private function database(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			throw new BranchDeploymentLockStorageFailure( 'WordPress database is unavailable.' );
		}
	}

	private function assert_database(): void {
		global $wpdb;
		if ( property_exists( $wpdb, 'last_error' ) && '' !== trim( (string) $wpdb->last_error ) ) {
			throw new BranchDeploymentLockStorageFailure( 'WordPress updater database is unavailable.' );
		}
	}

	private function invalidate_option_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::NAME, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}
}
