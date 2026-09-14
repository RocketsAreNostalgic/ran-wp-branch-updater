<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput -- Standalone CLI fixture owns its isolated ZIP corpus and proof output.
declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;

$assert = static function ( bool $actual, string $message ): void {
	if ( ! $actual ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
};

$root = __DIR__ . '/build/prepared-archive-facts-' . bin2hex( random_bytes( 4 ) );
if ( ! mkdir( $root . '/archives', 0700, true ) ) {
	throw new RuntimeException( 'Cannot create prepared archive fixture directory.' );
}

$contents = "<?php\n/*\nPlugin Name: Demo\nVersion: 1.2.3\n*/\n" . str_repeat( 'A', 4096 );
$source   = $root . '/source.zip';
$zip      = new ZipArchive();
if ( true !== $zip->open( $source, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	throw new RuntimeException( 'Cannot create prepared archive fixture ZIP.' );
}
$zip->addFromString( 'repository/demo/demo.php', $contents );
$zip->close();

$deployment = new BranchDeploymentDeclaration(
	'prepared-archive-facts',
	'plugin',
	'demo',
	'acme/demo',
	'repository-id',
	'main',
	'abc123',
	'install',
	'demo',
	null
);

$offer = static fn(): ArchiveOffer => new ArchiveOffer(
	'fixture',
	'repository-id',
	'abc123',
	static function ( string $destination, int $maximumArtifactBytes ) use ( $source ): void {
		$size = filesize( $source );
		if ( false === $size || $size < 1 || $size > $maximumArtifactBytes || ! copy( $source, $destination ) ) {
			throw new RuntimeException( 'Cannot acquire prepared archive fixture.' );
		}
	},
	static function (): void {}
);

$constructor = ( new ReflectionClass( PreparedArchive::class ) )->getConstructor();
$assert( null !== $constructor && $constructor->isPrivate(), 'prepared archive facts cannot be supplied through public construction' );

$artifact = PreparedArchive::downloadAndValidate( $offer(), $deployment, $root . '/archives' );
$assert( strlen( $contents ) === $artifact->expandedBytes(), 'prepared archive retains the expanded byte count from validation' );
$artifact->cleanup();
$assert( ! glob( $root . '/archives/*' ), 'successful prepared archive cleanup remains unchanged' );

$tampered = PreparedArchive::downloadAndValidate( $offer(), $deployment, $root . '/archives' );
$path     = $tampered->path();
file_put_contents( $path, 'changed' );
try {
	$tampered->expandedBytes();
	$assert( false, 'expanded archive facts must not be returned after custody is broken' );
} catch ( RuntimeException $expected ) {
	$assert( 'Prepared archive changed before use.' === $expected->getMessage(), 'expanded archive fact remains bound to exact prepared bytes' );
}
unlink( $path );
unlink( $source );
rmdir( $root . '/archives' );
rmdir( $root );

echo "PASS prepared archive validated expanded-byte facts and custody binding\n";
