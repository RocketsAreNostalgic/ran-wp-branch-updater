<?php

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\InstalledPackageIdentifier;

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
	} catch ( InvalidArgumentException ) {
	}
}

echo "PASS installed package identifier policy\n";
