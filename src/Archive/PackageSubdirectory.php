<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\Archive;

use InvalidArgumentException;
use RAN\UpdaterSupport\V1\RepositoryRelativePath;

/** Normalize and validate a repository-relative package directory. */
final class PackageSubdirectory {
	public static function normalize( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			throw self::invalid();
		}
		if ( '' === trim( $value ) ) {
			if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				throw self::invalid();
			}
			return null;
		}

		try {
			return RepositoryRelativePath::normalize( $value );
		} catch ( InvalidArgumentException ) {
			throw self::invalid();
		}
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

	private static function invalid(): InvalidArgumentException {
		return new InvalidArgumentException( 'The package subdirectory must be a normalized relative path.' );
	}
}
