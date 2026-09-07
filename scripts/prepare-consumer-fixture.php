<?php

declare(strict_types=1);

if ( 3 !== $argc ) {
	throw new RuntimeException( 'Usage: prepare-consumer-fixture.php CONSUMER_ROOT PACKAGE_ROOT' );
}

[$script, $consumer, $package] = $argv;
if ( ! is_dir( $package ) || is_link( $package ) ) {
	throw new RuntimeException( 'Package root is unavailable or unsafe.' );
}
if ( ! is_dir( $consumer ) && ! mkdir( $consumer, 0700, true ) ) {
	throw new RuntimeException( 'Consumer root could not be created.' );
}


$manifest = array(
	'name'         => 'ran/branch-updater-consumer-fixture',
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
	'require'      => array(
		'php'                   => '^8.2',
		'ran/updater-support'   => 'dev-main',
		'ran/wp-branch-updater' => 'dev-main',
	),
);

$json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
if ( false === file_put_contents( $consumer . '/composer.json', $json, LOCK_EX ) ) {
	throw new RuntimeException( 'Consumer manifest could not be written.' );
}
