<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- This standalone CLI runner and its test doubles never load into WordPress global scope.
declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;

$assert = static function ( bool $actual, string $message ): void {
	if ( ! $actual ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion diagnostics are not HTML; preserve the actual failing fixture detail.
		throw new RuntimeException( 'FAIL: ' . $message );
	}
};

$root = __DIR__ . '/build/prepared-archive-facts-' . bin2hex( random_bytes( 4 ) );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
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
	attempt_id: 'prepared-archive-facts',
	package_type: 'plugin',
	slug: 'demo',
	repository: 'acme/demo',
	repository_id: 'repository-id',
	branch: 'main',
	expected_head: 'abc123',
	operation: 'install',
	subdirectory: 'demo',
	installed_identifier: null
);

$offer = static fn(): ArchiveOffer => new ArchiveOffer(
	provider: 'fixture',
	repository_id: 'repository-id',
	resolved_ref: 'abc123',
	copy_to: static function ( string $destination, int $maximum_artifact_bytes ) use ( $source ): void {
		$size = filesize( $source );
		if ( false === $size || $size < 1 || $size > $maximum_artifact_bytes || ! copy( $source, $destination ) ) {
			throw new RuntimeException( 'Cannot acquire prepared archive fixture.' );
		}
	},
	verify_head: static function (): void {}
);

$constructor = ( new ReflectionClass( PreparedArchive::class ) )->getConstructor();
$assert( null !== $constructor && $constructor->isPrivate(), 'prepared archive facts cannot be supplied through public construction' );

$artifact = PreparedArchive::download_and_validate(
	offer: $offer(),
	d: $deployment,
	directory: $root . '/archives',
	maximum_artifact_bytes: PreparedArchive::DEFAULT_MAXIMUM_ARTIFACT_BYTES
);
$assert( strlen( $contents ) === $artifact->expanded_bytes(), 'prepared archive retains the expanded byte count from validation' );
$artifact->cleanup();
$assert( ! glob( $root . '/archives/*' ), 'successful prepared archive cleanup remains unchanged' );

$tampered = PreparedArchive::download_and_validate( $offer(), $deployment, $root . '/archives' );
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone CLI fixture variable does not share WordPress runtime globals.
$path = $tampered->path();
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
file_put_contents( $path, 'changed' );
try {
	$tampered->expanded_bytes();
	$assert( false, 'expanded archive facts must not be returned after custody is broken' );
} catch ( RuntimeException $expected ) {
	$assert( 'Prepared archive changed before use.' === $expected->getMessage(), 'expanded archive fact remains bound to exact prepared bytes' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
unlink( $path );
// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
unlink( $source );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
rmdir( $root . '/archives' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
rmdir( $root );

echo "PASS prepared archive validated expanded-byte facts and custody binding\n";
