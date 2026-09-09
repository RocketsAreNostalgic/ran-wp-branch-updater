<?php

declare(strict_types=1);

namespace RAN\BranchDeployment;

use InvalidArgumentException;

/** @internal Canonical safety normalization for installed package identifiers. */
final class InstalledPackageIdentifier {
	public static function normalize( string $identifier ): string {
		$identifier = trim( $identifier );
		if ( '' === $identifier
			|| str_starts_with( $identifier, '/' )
			|| str_contains( $identifier, '\\' )
			|| preg_match( '/[\x00-\x1F\x7F]/', $identifier ) === 1
			|| preg_match( '#(^|/)\.\.?(/|$)#', $identifier ) === 1
		) {
			throw new InvalidArgumentException( 'The installed package identifier is invalid.' );
		}

		return $identifier;
	}
}
