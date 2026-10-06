<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.

declare(strict_types=1);

$root = dirname( __DIR__ );
require $root . '/vendor/autoload.php';
// The candidate fixture can provide its own root while retaining the real locked tool.
$root                = $argv[1] ?? $root;
$config              = ( new PHPStan\DependencyInjection\NeonAdapter( array() ) )->load( $root . '/phpstan.neon' );
$exemptions          = array( 'tests', 'scripts', 'vendor', 'node_modules', '.git' );
$expected_exclusions = array_map( static fn( string $path ): string => $path . '/*', $exemptions );
if ( array( '.' ) !== ( $config['parameters']['paths'] ?? null )
	|| array( 'analyseAndScan' => $expected_exclusions ) !== ( $config['parameters']['excludePaths'] ?? null )
	|| array( 'vendor/szepeviktor/phpstan-wordpress/extension.neon' ) !== ( $config['includes'] ?? null )
	|| isset( $config['parameters']['fileExtensions'] ) ) {
	throw new RuntimeException( 'Review inclusive analysis scope and its explicit role exemptions.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local canonical command, never runtime or remote state.
$composer = json_decode( file_get_contents( $root . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR );
if ( 'phpstan analyse --configuration=phpstan.neon --no-progress --memory-limit=1G' !== $composer['scripts']['analyze'] ) {
	throw new RuntimeException( 'Review analysis command overrides.' );
}
$iterator = new RecursiveCallbackFilterIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
	static function ( SplFileInfo $entry ) use ( $root, $exemptions ): bool {
		return ! $entry->isDir() || ! in_array( substr( $entry->getPathname(), strlen( $root ) + 1 ), $exemptions, true );
	}
);
$expected = array();
foreach ( new RecursiveIteratorIterator( $iterator ) as $entry ) {
	if ( ! $entry->isFile() ) {
		continue;
	}
	if ( 0 === strcasecmp( $entry->getExtension(), 'php' ) ) {
		if ( 'php' !== $entry->getExtension() ) {
			throw new RuntimeException( 'Unsupported PHP extension must not evade analysis.' );
		}
		$expected[] = $entry->getPathname();
	} elseif ( '' === $entry->getExtension() ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect only the local extensionless file header, never execute it.
		$header = file_get_contents( $entry->getPathname(), false, null, 0, 256 );
		if ( preg_match( '/^(?:#![^\n]*\n)?\s*<\?php\b/', $header ) ) {
			throw new RuntimeException( 'Extensionless PHP needs an explicit reviewed analysis decision.' );
		}
	}
}
if ( array() === $expected ) {
	throw new RuntimeException( 'No production PHP discovered.' );
}
$temp = sys_get_temp_dir() . '/ran-branch-analysis-' . bin2hex( random_bytes( 12 ) );
try {
	$container = ( new PHPStan\DependencyInjection\ContainerFactory( $root ) )->create( $temp, array( $root . '/phpstan.neon' ), array() );
	$actual    = $container->getService( 'fileFinderAnalyse' )->findFiles( $container->getParameter( 'paths' ) )->getFiles();
	if ( array() !== array_diff( $expected, $actual ) || array() !== array_diff( $actual, $expected ) ) {
		throw new RuntimeException( 'Effective PHPStan selection differs from independently discovered production PHP.' );
	}
} finally {
	if ( is_dir( $temp ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $temp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $entry ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the unique private PHPStan container cache created above.
			$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the unique private PHPStan container cache created above.
		rmdir( $temp );
	}
}
printf( "Effective production analysis covers %d independently discovered PHP files.\n", count( $expected ) );
