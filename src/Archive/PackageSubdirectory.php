<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Archive;

/** Normalize and validate a repository-relative package directory. */
final class PackageSubdirectory {
	public static function normalize( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			throw self::invalid();
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		if ( str_starts_with( $value, '/' )
			|| str_contains( $value, '\\' )
			|| preg_match( '/^[A-Za-z]:/', $value )
			|| preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw self::invalid();
		}
		if ( str_ends_with( $value, '/' ) ) {
			$value = substr( $value, 0, -1 );
		}
		$segments = explode( '/', $value );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				throw self::invalid();
			}
			self::assertDecodedSegmentIsSafe( $segment );
		}
		return implode( '/', $segments );
	}

	public static function slug( mixed $value ): string {
		$path = self::normalize( $value );
		if ( null === $path ) {
			throw self::invalid();
		}
		$segments = explode( '/', $path );
		return (string) end( $segments );
	}

	public static function normalizeSlug( mixed $value ): string {
		$slug = self::normalize( $value );
		if ( null === $slug
			|| ! is_string( $value )
			|| str_ends_with( trim( $value ), '/' )
			|| str_contains( $slug, '/' ) ) {
			throw self::invalid();
		}
		return $slug;
	}

	public static function installationSlug( mixed $providerSlug, mixed $subdirectory ): string {
		$path = self::normalize( $subdirectory );
		return null === $path ? self::normalizeSlug( $providerSlug ) : self::slug( $path );
	}

	public static function deploymentSlug( mixed $providerSlug, mixed $subdirectory ): string {
		return strtolower( self::installationSlug( $providerSlug, $subdirectory ) );
	}

	private static function assertDecodedSegmentIsSafe( string $segment ): void {
		$decoded = $segment;
		for ( $pass = 0, $limit = strlen( $segment ); $pass < $limit; ++$pass ) {
			$next = rawurldecode( $decoded );
			if ( $next === $decoded ) {
				break;
			}
			$decoded = $next;
			if ( '.' === $decoded
				|| '..' === $decoded
				|| str_contains( $decoded, '/' )
				|| str_contains( $decoded, '\\' )
				|| preg_match( '/[\x00-\x1F\x7F]/', $decoded ) ) {
				throw self::invalid();
			}
		}
	}

	private static function invalid(): \InvalidArgumentException {
		return new \InvalidArgumentException( 'The package subdirectory must be a normalized relative path.' );
	}
}
