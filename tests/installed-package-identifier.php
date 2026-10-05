<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- This standalone CLI runner and its test doubles never load into WordPress global scope.

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\WordPress\InstalledPackageIdentifier;

if ( 'demo/demo.php' !== InstalledPackageIdentifier::normalize( ' demo/demo.php ' ) ) {
	throw new RuntimeException( 'Installed identifier normalization changed.' );
}

foreach ( array(
	'',
	'/demo/demo.php',
	'demo\\demo.php',
	'demo/../demo.php',
	"demo/\x00demo.php",
) as $invalid ) {
	try {
		InstalledPackageIdentifier::normalize( $invalid );
		throw new RuntimeException( 'Unsafe installed identifier was accepted.' );
	// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Reaching this catch proves the expected rejection; no mutation is needed.
	} catch ( InvalidArgumentException ) { // Expected rejection is the assertion; execution continues only for this exception.
	}
}

echo "PASS installed package identifier policy\n";
