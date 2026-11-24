<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\WordPress;

use InvalidArgumentException;
use RAN\UpdaterSupport\V1\RepositoryRelativePath;

/** @internal Canonical safety normalization for installed package identifiers. */
final class InstalledPackageIdentifier {
	public static function normalize( string $identifier ): string {
		if ( 0 !== preg_match( '/[\p{Cc}\p{Cf}]/u', $identifier ) ) {
			throw new InvalidArgumentException( 'The installed package identifier is invalid.' );
		}
		try {
			$normalized = RepositoryRelativePath::normalize( $identifier );
		} catch ( InvalidArgumentException ) {
			throw new InvalidArgumentException( 'The installed package identifier is invalid.' );
		}
		if ( str_ends_with( trim( $identifier ), '/' ) || substr_count( $normalized, '/' ) > 1 ) {
			throw new InvalidArgumentException( 'The installed package identifier is invalid.' );
		}
		return $normalized;
	}
}
