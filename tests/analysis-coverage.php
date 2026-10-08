<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.

declare(strict_types=1);

$root = dirname( __DIR__ );
require $root . '/vendor/autoload.php';
// The candidate fixture can provide its own root while retaining the real locked tool.
$root          = $argv[1] ?? $root;
$maintained    = in_array( '--maintained', $argv ?? array(), true );
$configuration = $maintained ? 'phpstan-maintained.neon' : 'phpstan.neon';
// @phpstan-ignore phpstanApi.constructor, phpstanApi.method (Locked NeonAdapter reads the exact configuration consumed by this coverage contract.)
$config              = ( new PHPStan\DependencyInjection\NeonAdapter( array() ) )->load( $root . '/' . $configuration );
$exemptions          = $maintained ? array( 'vendor', 'node_modules', '.git' ) : array( 'tests', 'scripts', 'vendor', 'node_modules', '.git' );
$expected_exclusions = array_map( static fn( string $path ): string => $path . '/*', $exemptions );
if ( 8 !== ( $config['parameters']['level'] ?? null )
	|| array( '.' ) !== ( $config['parameters']['paths'] ?? null )
	|| array( 'analyseAndScan' => $expected_exclusions ) !== ( $config['parameters']['excludePaths'] ?? null )
	|| array( 'vendor/szepeviktor/phpstan-wordpress/extension.neon' ) !== ( $config['includes'] ?? null )
	|| isset( $config['parameters']['fileExtensions'] )
	|| isset( $config['parameters']['ignoreErrors'] )
	|| 80200 !== ( $config['parameters']['phpVersion'] ?? null ) ) {
	throw new RuntimeException( 'Review inclusive analysis scope and its explicit role exemptions.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local canonical command, never runtime or remote state.
$composer_source = file_get_contents( $root . '/composer.json' );
if ( false === $composer_source ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Standalone coverage failure is not HTML output.
	throw new RuntimeException( 'Cannot read the canonical Composer commands.' );
}
$composer = json_decode( $composer_source, true, 512, JSON_THROW_ON_ERROR );
if ( array( '@analyze:production', '@analyze:maintained' ) !== $composer['scripts']['analyze']
	|| 'phpstan analyse --configuration=phpstan.neon --no-progress --memory-limit=1G' !== $composer['scripts']['analyze:production']
	|| 'phpstan analyse --configuration=phpstan-maintained.neon --no-progress --memory-limit=1G' !== $composer['scripts']['analyze:maintained'] ) {
	throw new RuntimeException( 'Review analysis command overrides.' );
}
$iterator = new RecursiveCallbackFilterIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
	static function ( SplFileInfo $entry ) use ( $root, $exemptions ): bool {
		return ! $entry->isDir() || ! in_array( substr( $entry->getPathname(), strlen( $root ) + 1 ), $exemptions, true );
	}
);
$expected = array();
// Only these reviewed locked-tool API notifications are retained; no broad ignores.
$analysis_exceptions = array(
	'tests/analysis-coverage.php'     => array(
		'// @phpstan-ignore phpstanApi.constructor, phpstanApi.method (Locked NeonAdapter reads the exact configuration consumed by this coverage contract.)',
		'// @phpstan-ignore phpstanApi.constructor (Locked FileExcluder mirrors the actual CLI post-finder stub removal.)',
		'// @phpstan-ignore phpstanApi.method (Use the locked CLI exclusion predicate rather than an approximation.)',
	),
	'src/Archive/PreparedArchive.php' => array(
		'// @phpstan-ignore booleanOr.leftAlwaysFalse (retain symlink recheck at the custody boundary)',
	),
);
$exception_targets   = array(
	'// @phpstan-ignore phpstanApi.constructor, phpstanApi.method (Locked NeonAdapter reads the exact configuration consumed by this coverage contract.)' => '$config              = ( new PHPStan\\DependencyInjection\\NeonAdapter( array() ) )->load( $root . \'/\' . $configuration );',
	'// @phpstan-ignore phpstanApi.constructor (Locked FileExcluder mirrors the actual CLI post-finder stub removal.)' => '$stub_excluder = new PHPStan\\File\\FileExcluder( $container->getByType( PHPStan\\File\\FileHelper::class ), $container->getParameter( \'stubFiles\' ) );',
	'// @phpstan-ignore phpstanApi.method (Use the locked CLI exclusion predicate rather than an approximation.)' => '$actual = array_filter( $actual, static fn( string $file ): bool => ! $stub_excluder->isExcludedFromAnalysing( $file ) );',
	'// @phpstan-ignore booleanOr.leftAlwaysFalse (retain symlink recheck at the custody boundary)' => 'if ( is_link( $directory ) || ( fileperms( $directory ) & 0777 ) !== 0700 ) {',
);
$found_exceptions    = array();
foreach ( new RecursiveIteratorIterator( $iterator ) as $entry ) {
	if ( ! $entry->isFile() ) {
		continue;
	}
	if ( 0 === strcasecmp( $entry->getExtension(), 'php' ) ) {
		if ( 'php' !== $entry->getExtension() ) {
			throw new RuntimeException( 'Unsupported PHP extension must not evade analysis.' );
		}
		$expected[] = $entry->getPathname();
		if ( $maintained ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect maintained comments without executing the source.
			$source = file_get_contents( $entry->getPathname() );
			if ( false === $source ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Standalone coverage failure is not HTML output.
				throw new RuntimeException( 'Cannot inspect maintained analysis annotations.' );
			}
			foreach ( token_get_all( $source ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) && preg_match( '/@phpstan-ignore/i', $token[1] ) ) {
					$relative = substr( $entry->getPathname(), strlen( $root ) + 1 );
					if ( ! in_array( $token[1], $analysis_exceptions[ $relative ] ?? array(), true )
						|| trim( explode( "\n", $source )[ $token[2] ] ?? '' ) !== $exception_targets[ $token[1] ] ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Standalone coverage failure is not HTML output.
						throw new RuntimeException( 'Review new or changed analysis exemptions.' );
					}
					$found_exceptions[ $relative ][] = $token[1];
				}
			}
		}
	} else {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect local maintained content without executing it; inert formats retain a leading-tag check.
		$header = file_get_contents( $entry->getPathname() );
		if ( false === $header ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Standalone coverage failure is not HTML output.
			throw new RuntimeException( 'Cannot inspect a maintained file header.' );
		}
		// Markdown examples and declared Bash test drivers are inert; a shell suffix alone is not evidence.
		$inert = 'md' === strtolower( $entry->getExtension() )
			|| ( 'sh' === strtolower( $entry->getExtension() ) && 1 === preg_match( '~\A#!(?:/usr/bin/env[ \t]+bash|/bin/bash)(?:[ \t][^\r\n]*)?\r?\n~', $header ) );
		// Generated archive proofs contain PHP payloads in ZIP bytes; do not exempt other source in this directory.
		if ( str_starts_with( substr( $entry->getPathname(), strlen( $root ) + 1 ), 'tests/build/' ) && str_starts_with( $header, "PK\x03\x04" ) ) {
			$archive = new ZipArchive();
			if ( true === $archive->open( $entry->getPathname(), ZipArchive::RDONLY ) ) {
				$archive->close();
				continue;
			}
		}
		// A genuine leading XML declaration is data, not a short PHP opening tag.
		$xml_declaration = '~\A(?:\xEF\xBB\xBF)?<\?xml[ \t\r\n]+version[ \t\r\n]*=[ \t\r\n]*(?:"1\.[01]"|\'1\.[01]\')(?:[ \t\r\n]+encoding[ \t\r\n]*=[ \t\r\n]*(?:"[A-Za-z][A-Za-z0-9._-]*"|\'[A-Za-z][A-Za-z0-9._-]*\'))?(?:[ \t\r\n]+standalone[ \t\r\n]*=[ \t\r\n]*(?:"(?:yes|no)"|\'(?:yes|no)\'))?[ \t\r\n]*\?>~';
		$header          = preg_replace( $xml_declaration, '', $header ) ?? $header;
		if ( preg_match( $inert ? '/^(?:\xEF\xBB\xBF)?(?:#![^\n]*\n)?\s*<\?/' : '/<\?/', $header ) ) {
			throw new RuntimeException( 'PHP outside lowercase .php needs an explicit reviewed analysis decision.' );
		}
	}
}
ksort( $analysis_exceptions );
ksort( $found_exceptions );
if ( $maintained && is_file( $root . '/tests/analysis-coverage.php' ) && $analysis_exceptions !== $found_exceptions ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Standalone coverage failure is not HTML output.
	throw new RuntimeException( 'Review the exact locked-tool analysis exemption inventory.' );
}
if ( array() === $expected ) {
	throw new RuntimeException( 'No PHP discovered for this analysis profile.' );
}
$temp = sys_get_temp_dir() . '/ran-branch-analysis-' . bin2hex( random_bytes( 12 ) );
try {
	$container = ( new PHPStan\DependencyInjection\ContainerFactory( $root ) )->create( $temp, array( $root . '/' . $configuration ), array() );
	$actual    = $container->getService( 'fileFinderAnalyse' )->findFiles( $container->getParameter( 'paths' ) )->getFiles();
	// Match the locked CLI's post-discovery removal of stub files.
	// @phpstan-ignore phpstanApi.constructor (Locked FileExcluder mirrors the actual CLI post-finder stub removal.)
	$stub_excluder = new PHPStan\File\FileExcluder( $container->getByType( PHPStan\File\FileHelper::class ), $container->getParameter( 'stubFiles' ) );
	// @phpstan-ignore phpstanApi.method (Use the locked CLI exclusion predicate rather than an approximation.)
	$actual = array_filter( $actual, static fn( string $file ): bool => ! $stub_excluder->isExcludedFromAnalysing( $file ) );
	if ( array() !== array_diff( $expected, $actual ) || array() !== array_diff( $actual, $expected ) ) {
		throw new RuntimeException( 'Effective PHPStan selection differs from independently discovered PHP.' );
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
printf( "Effective %s analysis covers %d independently discovered PHP files.\n", $maintained ? 'maintained' : 'production', count( $expected ) );
