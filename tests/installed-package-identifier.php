<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\WordPress\InstalledPackageIdentifier;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;

foreach ( array( 'plugin', 'theme' ) as $package_type ) {
	$declaration = new BranchDeploymentDeclaration( 'mixed-case', $package_type, 'MixedCase', 'fixture/repository', 'fixture', 'main', null );
	if ( 'MixedCase' !== $declaration->slug ) {
		throw new RuntimeException( 'Installed folder admission must preserve case.' );
	}
	$identifier = 'plugin' === $package_type ? 'MixedCase/Bootstrap.php' : 'MixedCase';
	new BranchDeploymentDeclaration( 'mixed-install', $package_type, 'MixedCase', 'fixture/repository', 'fixture', 'main', null, operation: 'install', expected_identifier: $identifier );
}

new BranchDeploymentDeclaration( 'long-name', 'theme', str_repeat( 'A', 191 ), 'fixture/repository', 'fixture', 'main', null );
foreach ( array( '', '.hidden', 'x/demo', 'Demo ', "Demo\n", str_repeat( 'A', 192 ) ) as $slug ) {
	try {
		new BranchDeploymentDeclaration( 'invalid-name', 'plugin', $slug, 'fixture/repository', 'fixture', 'main', null );
		throw new LogicException( 'Invalid folder name was admitted.' );
	} catch ( RuntimeException $expected ) {
		if ( 'Invalid branch deployment declaration.' !== $expected->getMessage() ) {
			throw $expected;
		}
	}
}

if ( 'demo/demo.php' !== InstalledPackageIdentifier::normalize( ' demo/demo.php ' ) ) {
	throw new RuntimeException( 'Installed identifier normalization changed.' );
}

foreach ( array( '', 'demo/../demo.php', 'demo/main.php ', '/demo/main.php', 'demo/main.PHP', 'demo/' . str_repeat( 'a', 183 ) . '.php', 'other/main.php', 'demo/nested/main.php', 'demo\\main.php' ) as $identifier ) {
	try {
		new BranchDeploymentDeclaration( 'invalid-target', 'plugin', 'demo', 'fixture/repository', 'fixture', 'main', null, operation: 'install', expected_identifier: $identifier );
		throw new LogicException( 'Invalid exact installation identifier was admitted.' );
	} catch ( RuntimeException $expected ) {
		if ( 'Invalid expected installation identifier.' !== $expected->getMessage() ) {
			throw $expected;
		}
	}
}
try {
	new BranchDeploymentDeclaration( 'update-target', 'plugin', 'demo', 'fixture/repository', 'fixture', 'main', null, expected_identifier: 'demo/main.php' );
	throw new LogicException( 'An update was admitted with an initial-install identifier.' );
} catch ( RuntimeException $expected ) {
	if ( 'Invalid expected installation identifier.' !== $expected->getMessage() ) {
		throw $expected;
	}
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
