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
if ( 'demo' !== InstalledPackageIdentifier::normalize( 'demo' ) || 'MixedCase/Bootstrap.php' !== InstalledPackageIdentifier::normalize( 'MixedCase/Bootstrap.php' ) ) {
	throw new RuntimeException( 'Valid single-level and two-level installed identifiers must remain accepted.' );
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
	'demo/',
	'demo/ ',
	'demo/demo.php/',
	'demo//demo.php',
	'demo\\demo.php',
	'demo/../demo.php',
	'other/demo/demo.php',
	'x/demo/demo.php',
	'a/b/c',
	'C:demo.php',
	'C:/demo.php',
	'c:demo/demo.php',
	'C%3Ademo.php',
	'c%3a/demo.php',
	'%2e%2e/demo.php',
	'demo/%2e%2e',
	'%2E%2E/demo.php',
	'demo/%2e',
	'demo%2fdemo.php',
	'demo%2F..%2Fdemo.php',
	'demo%5cdemo.php',
	'%252e%252e/demo.php',
	'demo/%00demo.php',
	"demo/\x00demo.php",
	"demo/demo.php\x00",
	"\x00demo/demo.php",
	"demo/demo.php\n",
	"\ndemo/demo.php",
	"demo/demo.php\x0B",
	"demo/\u{200B}demo.php",
	"demo/\u{202E}demo.php",
	"demo/\xFFdemo.php",
) as $invalid ) {
	try {
		InstalledPackageIdentifier::normalize( $invalid );
		throw new RuntimeException( 'Unsafe installed identifier was accepted.' );
	} catch ( InvalidArgumentException $expected ) {
		if ( InvalidArgumentException::class !== $expected::class || 'The installed package identifier is invalid.' !== $expected->getMessage() ) {
			throw $expected;
		}
	}
}

echo "PASS installed package identifier policy\n";
