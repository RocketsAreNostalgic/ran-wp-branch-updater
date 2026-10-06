<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.

declare(strict_types=1);

if ( 3 !== $argc ) {
	throw new RuntimeException( 'Usage: prepare-consumer-fixture.php CONSUMER_ROOT PACKAGE_ROOT' );
}

[$script, $consumer, $package] = $argv;
if ( ! is_dir( $package ) || is_link( $package ) ) {
	throw new RuntimeException( 'Package root is unavailable or unsafe.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
if ( ! is_dir( $consumer ) && ! mkdir( $consumer, 0700, true ) ) {
	throw new RuntimeException( 'Consumer root could not be created.' );
}


$manifest = array(
	'name'              => 'ran/branch-updater-consumer-fixture',
	'minimum-stability' => 'beta',
	'prefer-stable'     => true,
	'repositories'      => array(
		array(
			'type'    => 'path',
			'url'     => $package,
			'options' => array(
				'symlink'  => false,
				'versions' => array( 'ran/wp-branch-updater' => 'dev-main' ),
			),
		),
		array(
			'type' => 'vcs',
			'url'  => 'https://github.com/RocketsAreNostalgic/ran-updater-support',
		),
	),
	'require'           => array(
		'php'                   => '^8.2',
		'ran/wp-branch-updater' => 'dev-main',
	),
);

// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone CLI fixture preserves explicit JSON flags and throws before writing its test manifest or journal.
$json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
if ( false === file_put_contents( $consumer . '/composer.json', $json, LOCK_EX ) ) {
	throw new RuntimeException( 'Consumer manifest could not be written.' );
}
