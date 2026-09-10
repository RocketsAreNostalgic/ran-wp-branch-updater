<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput -- Standalone CLI fixture owns its isolated ZIP corpus and proof output.
declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\Archive\ArchiveValidator;
use RAN\WPBranchUpdater\V1\Runtime\Deployment;

$assert = static function ( bool $actual, string $message ): void {
	if ( ! $actual ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
};
$root = __DIR__ . '/build/archive-normalisation-' . bin2hex( random_bytes( 4 ) );
if ( ! mkdir( $root, 0700, true ) ) {
	throw new RuntimeException( 'Cannot create archive baseline fixture directory.' );
}
$sequence = 0;
$zip = static function ( array $entries ) use ( $root, &$sequence ): string {
	$path = sprintf( '%s/fixture-%02d.zip', $root, ++$sequence );
	$zip  = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'Cannot create archive baseline ZIP.' );
	}
	foreach ( $entries as $name => $contents ) {
		if ( ! $zip->addFromString( $name, $contents ) ) {
			throw new RuntimeException( 'Cannot add archive baseline ZIP entry.' );
		}
	}
	$zip->close();
	return $path;
};
$plugin = static fn( string $headers = "Plugin Name: Demo\nVersion: 1.2.3" ): string => "<?php\n/*\n{$headers}\n*/\n";
$deployment = static fn(
	string $id,
	string $operation = 'install',
	?string $subdirectory = 'demo',
	?string $installedIdentifier = null
): Deployment => new Deployment(
	$id,
	'plugin',
	'demo',
	'acme/demo',
	'repository-id',
	'main',
	'abc123',
	$operation,
	$subdirectory,
	$installedIdentifier
);
$validator = new ArchiveValidator();
$validate = static function ( string $path, Deployment $deployment, ?string $installed = null, string $wordpressVersion = '6.5' ) use ( $validator ): array {
	return $validator->validate( $path, $deployment, $installed, 10485760, 20971520, $wordpressVersion );
};
$reject = static function ( int $code, string $path, Deployment $deployment, ?string $installed = null, string $wordpressVersion = '6.5' ) use ( $validate, $assert ): void {
	try {
		$validate( $path, $deployment, $installed, $wordpressVersion );
		$assert( false, 'archive validation unexpectedly succeeded for code ' . $code );
	} catch ( RuntimeException $expected ) {
		$assert( $code === $expected->getCode(), 'archive validation code remains ' . $code . ', got ' . $expected->getCode() );
	}
};

$valid = $zip( array( 'repository/demo/demo.php' => $plugin() ) );
$inspection = $validate( $valid, $deployment( 'valid' ) );
$assert( '1.2.3' === $inspection['expected_version'], 'valid plugin version remains discoverable' );

$unsafe = $zip( array( 'repository/demo/demo.php' => $plugin(), 'repository/../escape.php' => '<?php' ) );
$reject( ArchiveValidator::CODE_PATH_UNSAFE, $unsafe, $deployment( 'unsafe-path' ) );

$fileParent = $zip( array( 'repository/demo' => 'not a directory', 'repository/demo/demo.php' => $plugin() ) );
$reject( ArchiveValidator::CODE_FILE_PARENT_COLLISION, $fileParent, $deployment( 'file-parent' ) );

$multipleRoots = $zip( array( 'repository/demo/demo.php' => $plugin(), 'other/readme.txt' => 'second root' ) );
$reject( ArchiveValidator::CODE_MULTIPLE_ROOTS, $multipleRoots, $deployment( 'multiple-roots' ) );

$missingSubdirectory = $zip( array( 'repository/demo/demo.php' => $plugin() ) );
$reject( ArchiveValidator::CODE_SUBDIRECTORY_MISSING, $missingSubdirectory, $deployment( 'missing-subdirectory', 'install', 'plugin' ) );

$multiplePlugins = $zip( array(
	'repository/demo/demo.php'   => $plugin(),
	'repository/demo/second.php' => $plugin( "Plugin Name: Second\nVersion: 1.2.3" ),
) );
$reject( ArchiveValidator::CODE_MULTIPLE_PLUGINS, $multiplePlugins, $deployment( 'multiple-plugins' ) );

$missingVersion = $zip( array( 'repository/demo/demo.php' => $plugin( 'Plugin Name: Demo' ) ) );
$reject( ArchiveValidator::CODE_VERSION_MISSING, $missingVersion, $deployment( 'missing-version' ) );

$invalidVersion = $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3 beta!" ) ) );
$reject( ArchiveValidator::CODE_VERSION_INVALID, $invalidVersion, $deployment( 'invalid-version' ) );

$newerPhp = $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires PHP: 999.0" ) ) );
$reject( ArchiveValidator::CODE_REQUIRES_NEWER_PHP, $newerPhp, $deployment( 'newer-php' ) );

$newerWordPress = $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires at least: 999.0" ) ) );
$reject( ArchiveValidator::CODE_REQUIRES_NEWER_WP, $newerWordPress, $deployment( 'newer-wordpress' ), null, '6.5' );

$wrongIdentity = $zip( array( 'repository/demo/demo.php' => $plugin() ) );
$reject( ArchiveValidator::CODE_PACKAGE_IDENTITY, $wrongIdentity, $deployment( 'wrong-identity', 'update', 'demo', 'other/other.php' ), 'other/other.php' );

$missingMain = $zip( array( 'repository/demo/demo.php' => $plugin() ) );
$reject( ArchiveValidator::CODE_PLUGIN_MISSING, $missingMain, $deployment( 'missing-main', 'update', 'demo', 'demo/missing.php' ), 'demo/missing.php' );

foreach ( glob( $root . '/*.zip' ) ?: array() as $path ) {
	unlink( $path );
}
rmdir( $root );

echo "PASS archive safety, identity, header, and compatibility baseline\n";
