<?php

declare(strict_types=1);

namespace RAN\WPBranchUpdater\V1\WordPress;

use InvalidArgumentException;

/** @internal Canonical safety normalization for installed package identifiers. */
final class InstalledPackageIdentifier {
	public static function normalize( string $identifier ): string {
		$identifier = trim( $identifier );
		if ( '' === $identifier
			|| str_starts_with( $identifier, '/' )
			|| str_ends_with( $identifier, '/' )
			|| str_contains( $identifier, '//' )
			|| str_contains( $identifier, '\\' )
			|| substr_count( $identifier, '/' ) > 1
			|| 0 !== preg_match( '/[\p{Cc}\p{Cf}]/u', $identifier )
			|| preg_match( '#(^|/)\.\.?(/|$)#', $identifier ) === 1
		) {
			throw new InvalidArgumentException( 'The installed package identifier is invalid.' );
		}
		return $identifier;
	}
}
