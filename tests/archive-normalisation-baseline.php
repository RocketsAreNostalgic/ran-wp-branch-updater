<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.
declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\Archive\ArchiveValidator;
use RAN\UpdaterSupport\V1\PackageDirectory;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\GitHubFixtureProvider;
use RAN\WPBranchUpdater\V1\HeaderFormattingFixtures;
use RAN\WPBranchUpdater\V1\WordPress\WordPressCorePackageExecutor;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;

$assert = static function ( bool $actual, string $message ): void {
	if ( ! $actual ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion diagnostics are not HTML; preserve the actual failing fixture detail.
		throw new RuntimeException( 'FAIL: ' . $message );
	}
};

$assert( null === PackageDirectory::normalize( null ), 'optional package subdirectory remains nullable' );
$assert( null === PackageDirectory::normalize( '   ' ), 'blank package subdirectory remains nullable' );
$assert( 'packages/demo' === PackageDirectory::normalize( '  packages/demo/  ' ), 'shared path primitive preserves normalization behavior' );
$assert( 'demo' === PackageDirectory::slug( 'packages/demo' ), 'package subdirectory slug remains the final path segment' );
$assert( 'demo' === PackageDirectory::normalize_slug( 'demo' ), 'single-segment slug normalization remains local' );
$assert( 'demo' === PackageDirectory::installation_slug( 'provider-name', 'packages/demo' ), 'subdirectory remains installation-slug authority when present' );
$assert( 'provider-name' === PackageDirectory::installation_slug( 'provider-name', null ), 'provider slug remains installation fallback' );
$assert( 'mixed' === PackageDirectory::deployment_slug( 'MiXeD', null ), 'deployment slug remains lowercase' );
$assert( 'packages/C%3A-name' === PackageDirectory::normalize( 'packages/C%3A-name' ), 'encoded colon outside the first segment remains valid' );
$assert(
	'packages/%' . str_repeat( '25', 7 ) . '41' === PackageDirectory::normalize( 'packages/%' . str_repeat( '25', 7 ) . '41' ),
	'shared bounded decode depth remains accepted at the supported boundary'
);

$invalid_subdirectories = array(
	'control only'             => "\n",
	'raw control'              => "packages/demo\n",
	'encoded drive prefix'     => 'C%3A/packages/demo',
	'encoded drive letter'     => '%43:/packages/demo',
	'double encoded traversal' => 'packages/%252e%252e/demo',
	'decode depth exceeded'    => 'packages/%' . str_repeat( '25', 8 ) . '41',
);
foreach ( $invalid_subdirectories as $label => $value ) {
	try {
		PackageDirectory::normalize( $value );
		$assert( false, 'unsafe package subdirectory unexpectedly normalized: ' . $label );
	} catch ( InvalidArgumentException $expected ) {
		$assert(
			'The package subdirectory must be a normalized relative path.' === $expected->getMessage(),
			'branch-updater exception mapping remains stable for ' . $label
		);
	}
}

try {
	PackageDirectory::normalize_slug( 'packages/demo' );
	$assert( false, 'nested path must not become a provider slug' );
} catch ( InvalidArgumentException $expected ) {
	$assert( 'The package subdirectory must be a normalized relative path.' === $expected->getMessage(), 'slug exception mapping remains stable' );
}

$root = __DIR__ . '/build/archive-normalisation-' . bin2hex( random_bytes( 4 ) );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
if ( ! mkdir( $root, 0700, true ) ) {
	throw new RuntimeException( 'Cannot create archive baseline fixture directory.' );
}
$sequence = 0;
$zip      = static function ( array $entries ) use ( $root, &$sequence ): string {
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
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone CLI fixture variable does not share WordPress runtime globals.
$plugin     = static fn( string $headers = "Plugin Name: Demo\nVersion: 1.2.3" ): string => "<?php\n/*\n{$headers}\n*/\n";
$deployment = static fn(
	string $id,
	string $operation = 'install',
	?string $subdirectory = 'demo',
	?string $installed_identifier = null
): BranchDeploymentDeclaration => new BranchDeploymentDeclaration(
	$id,
	'plugin',
	'demo',
	'acme/demo',
	'repository-id',
	'main',
	'abc123',
	$operation,
	$subdirectory,
	$installed_identifier
);
$validator = new ArchiveValidator();
$validate  = static function ( string $path, BranchDeploymentDeclaration $deployment, ?string $installed = null, string $wordpress_version = '6.5', string $php_version = PHP_VERSION ) use ( $validator ): array {
	return $validator->validate( $path, $deployment, $installed, 10485760, 20971520, $wordpress_version, $php_version );
};
$reject    = static function ( int $code, string $path, BranchDeploymentDeclaration $deployment, ?string $installed = null, string $wordpress_version = '6.5', string $php_version = PHP_VERSION ) use ( $validate, $assert ): void {
	try {
		$validate( $path, $deployment, $installed, $wordpress_version, $php_version );
		$assert( false, 'archive validation unexpectedly succeeded for code ' . $code );
	} catch ( RuntimeException $expected ) {
		$assert( $code === $expected->getCode(), 'archive validation code remains ' . $code . ', got ' . $expected->getCode() );
	}
};

$valid      = $zip( array( 'repository/demo/demo.php' => $plugin() ) );
$inspection = $validate( $valid, $deployment( 'valid' ) );
$assert( '1.2.3' === $inspection['expected_version'], 'valid plugin version remains discoverable' );

// Formatting matches WordPress extraction; package value/identity policy remains stricter.
$parent_method = new ReflectionMethod( WordPressCorePackageExecutor::class, 'theme_parent_from_archive' );
foreach ( array(
	'plugin' => 'Plugin Name',
	'theme'  => 'Theme Name',
) as $package_type => $name ) {
	foreach ( HeaderFormattingFixtures::variants( $name ) as $format => $contents ) {
		$file        = 'plugin' === $package_type ? 'demo.php' : 'style.css';
		$header_path = $zip( array( 'repository/demo/' . $file => $contents ) );
		$d           = new BranchDeploymentDeclaration( $package_type . '-' . $format, $package_type, 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', 'install', 'demo' );
		$assert( '1.2.3' === $validate( $header_path, $d )['expected_version'], $package_type . ' accepts ' . $format );
		if ( 'theme' === $package_type ) {
			$parent_path = $zip( array( 'repository/demo/style.css' => str_replace( 'Requires at least: 6.5', 'Requires at least:', $contents ) ) );
			$artifact    = PreparedArchive::download_and_validate( ( new GitHubFixtureProvider( $parent_path, 'abc123' ) )->prepare( $d ), $d, $root . '/custody' );
			try {
				$assert( 'parent' === $parent_method->invoke( new WordPressCorePackageExecutor(), $artifact, 'demo' ), 'Template is cleaned for ' . $format );
			} finally {
				$artifact->cleanup();
			}
		}
	}
}

foreach ( array(
	'missing' => '',
	'empty'   => 'Template: */',
	'late'    => str_repeat( ' ', 8192 ) . 'Template: parent',
	'invalid' => 'Template: ../parent */',
) as $case => $template ) {
	$contents    = "/* Theme Name: Demo */\nVersion: 1.2.3\n" . $template;
	$header_path = $zip( array( 'repository/demo/style.css' => $contents ) );
	$d           = new BranchDeploymentDeclaration( 'parent-' . $case, 'theme', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', 'install', 'demo' );
	$artifact    = PreparedArchive::download_and_validate( ( new GitHubFixtureProvider( $header_path, 'abc123' ) )->prepare( $d ), $d, $root . '/custody' );
	try {
		$assert( ( 'invalid' === $case ? false : null ) === $parent_method->invoke( new WordPressCorePackageExecutor(), $artifact, 'demo' ), 'Template retains ' . $case . ' boundary' );
	} finally {
		$artifact->cleanup();
	}
}

$late_headers = $zip( array( 'repository/demo/demo.php' => str_repeat( ' ', 8192 ) . $plugin() ) );
$reject( ArchiveValidator::CODE_PLUGIN_MISSING, $late_headers, $deployment( 'late-header' ) );
$empty_version = $zip( array( 'repository/demo/demo.php' => "/* Plugin Name: Demo */\n/* Version: */\nRequires PHP: 8.2" ) );
$reject( ArchiveValidator::CODE_VERSION_MISSING, $empty_version, $deployment( 'empty-version' ) );
$invalid_compatibility = $zip( array( 'repository/demo/demo.php' => "<?php /* Plugin Name: Demo */\rVersion: 1.2.3 ?>\rRequires PHP: future */" ) );
$reject( ArchiveValidator::CODE_COMPATIBILITY_INVALID, $invalid_compatibility, $deployment( 'invalid-compatibility' ) );
$long_name_65 = $zip( array( 'repository/demo/demo.php' => $plugin( 'Plugin Name: ' . str_repeat( 'A', 65 ) . " */\nVersion: 1.2.3" ) ) );
$assert( '1.2.3' === $validate( $long_name_65, $deployment( 'long-name-65' ) )['expected_version'], '65-byte plugin header value is accepted within the 500-byte bound' );
$long_name_500 = $zip( array( 'repository/demo/demo.php' => '<?php /* Plugin Name: ' . str_repeat( 'A', 500 ) . " */\n/* Version: 1.2.3 ?>\n" ) );
$assert( '1.2.3' === $validate( $long_name_500, $deployment( 'long-name-500' ) )['expected_version'], '500-byte plugin header value with stripped comment closer is accepted' );
$long_theme_500  = $zip( array( 'repository/demo/style.css' => '/* Theme Name: ' . str_repeat( 'T', 500 ) . " */\nVersion: 1.2.3\n" ) );
$long_theme_decl = new BranchDeploymentDeclaration( 'long-theme-500', 'theme', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', 'install', 'demo' );
$assert( '1.2.3' === $validate( $long_theme_500, $long_theme_decl )['expected_version'], '500-byte theme header value is accepted' );
$long_name_501 = $zip( array( 'repository/demo/demo.php' => $plugin( 'Plugin Name: ' . str_repeat( 'A', 501 ) . " */\nVersion: 1.2.3" ) ) );
$reject( ArchiveValidator::CODE_HEADER_UNREADABLE, $long_name_501, $deployment( 'long-name-501' ) );
$long_theme_501 = $zip( array( 'repository/demo/style.css' => '/* Theme Name: ' . str_repeat( 'T', 501 ) . " */\nVersion: 1.2.3\n" ) );
$reject( ArchiveValidator::CODE_HEADER_UNREADABLE, $long_theme_501, $long_theme_decl );
$long_version_500 = $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: " . str_repeat( '1', 500 ) ) ) );
$assert( str_repeat( '1', 500 ) === $validate( $long_version_500, $deployment( 'long-version-500' ) )['expected_version'], '500-byte version value is accepted' );
$long_version_501 = $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: " . str_repeat( '1', 501 ) ) ) );
$reject( ArchiveValidator::CODE_VERSION_INVALID, $long_version_501, $deployment( 'long-version-501' ) );
$long_compat_501 = $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires PHP: 8.2-" . str_repeat( 'a', 497 ) ) ) );
$reject( ArchiveValidator::CODE_COMPATIBILITY_INVALID, $long_compat_501, $deployment( 'long-compat-501' ) );

$unsafe = $zip(
	array(
		'repository/demo/demo.php' => $plugin(),
		'repository/../escape.php' => '<?php',
	)
);
$reject( ArchiveValidator::CODE_PATH_UNSAFE, $unsafe, $deployment( 'unsafe-path' ) );

$file_parent = $zip(
	array(
		'repository/demo'          => 'not a directory',
		'repository/demo/demo.php' => $plugin(),
	)
);
$reject( ArchiveValidator::CODE_FILE_PARENT_COLLISION, $file_parent, $deployment( 'file-parent' ) );

$multiple_roots = $zip(
	array(
		'repository/demo/demo.php' => $plugin(),
		'other/readme.txt'         => 'second root',
	)
);
$reject( ArchiveValidator::CODE_MULTIPLE_ROOTS, $multiple_roots, $deployment( 'multiple-roots' ) );

$missing_subdirectory = $zip( array( 'repository/demo/demo.php' => $plugin() ) );
$reject( ArchiveValidator::CODE_SUBDIRECTORY_MISSING, $missing_subdirectory, $deployment( 'missing-subdirectory', 'install', 'plugin' ) );

$multiple_plugins = $zip(
	array(
		'repository/demo/demo.php'   => $plugin(),
		'repository/demo/second.php' => $plugin( "Plugin Name: Second\nVersion: 1.2.3" ),
	)
);
$reject( ArchiveValidator::CODE_MULTIPLE_PLUGINS, $multiple_plugins, $deployment( 'multiple-plugins' ) );

$missing_version = $zip( array( 'repository/demo/demo.php' => $plugin( 'Plugin Name: Demo' ) ) );
$reject( ArchiveValidator::CODE_VERSION_MISSING, $missing_version, $deployment( 'missing-version' ) );

$invalid_version = $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3 beta!" ) ) );
$reject( ArchiveValidator::CODE_VERSION_INVALID, $invalid_version, $deployment( 'invalid-version' ) );

$newer_php = $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires PHP: 999.0" ) ) );
$reject( ArchiveValidator::CODE_REQUIRES_NEWER_PHP, $newer_php, $deployment( 'newer-php' ) );

$newer_word_press = $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires at least: 999.0" ) ) );
$reject( ArchiveValidator::CODE_REQUIRES_NEWER_WP, $newer_word_press, $deployment( 'newer-wordpress' ), null, '6.5' );

$wrong_identity = $zip( array( 'repository/demo/demo.php' => $plugin() ) );
$reject( ArchiveValidator::CODE_PACKAGE_IDENTITY, $wrong_identity, $deployment( 'wrong-identity', 'update', 'demo', 'other/other.php' ), 'other/other.php' );

$missing_main = $zip( array( 'repository/demo/demo.php' => $plugin() ) );
$reject( ArchiveValidator::CODE_PLUGIN_MISSING, $missing_main, $deployment( 'missing-main', 'update', 'demo', 'demo/missing.php' ), 'demo/missing.php' );

// Issue #135: Runtime WP/PHP version compatibility normalization and +build rejection.
$compat_php = $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires PHP: 8.2.0" ) ) );
foreach ( array( '8.2.0-ubuntu1', '8.2.15-1+ubuntu22.04.1+deb.sury.org+1', '8.2.20-1~deb12u1', '8.2.0+build1', '8.2.0-alpine1' ) as $distro_php ) {
	$assert( '1.2.3' === $validate( $compat_php, $deployment( 'distro-php' ), null, '6.8', $distro_php )['expected_version'], 'distro-suffixed PHP satisfies Requires PHP: ' . $distro_php );
}
$reject( ArchiveValidator::CODE_REQUIRES_NEWER_PHP, $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires PHP: 8.2.1" ) ) ), $deployment( 'newer-distro-php' ), null, '6.8', '8.2.0-ubuntu1' );
$reject( ArchiveValidator::CODE_REQUIRES_NEWER_PHP, $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires PHP: 8.3.0" ) ) ), $deployment( 'prerelease-php-rc' ), null, '6.8', '8.3.0-RC1' );
$assert( '1.2.3' === $validate( $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires PHP: 8.3.0-beta1" ) ) ), $deployment( 'prerelease-php-ok' ), null, '6.8', '8.3.0-RC1' )['expected_version'], 'PHP RC satisfies lower prerelease requirement' );
foreach ( array(
	'8.3.0~rc1'                                  => '8.3.0',
	'8.4.0~rc1-1+ubuntu22.04.1+deb.sury.org+1'   => '8.4.0',
	'8.4.0~beta4-1+ubuntu24.04.1+deb.sury.org+1' => '8.4.0',
	'8.4.0~alpha1-1+deb12u1'                     => '8.4.0',
) as $tilde_prerelease_php => $stable_required_php ) {
	$reject(
		ArchiveValidator::CODE_REQUIRES_NEWER_PHP,
		$zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires PHP: " . $stable_required_php ) ) ),
		$deployment( 'tilde-prerelease-php' ),
		null,
		'6.8',
		$tilde_prerelease_php
	);
}
$assert( '1.2.3' === $validate( $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires PHP: 8.4.0-beta1" ) ) ), $deployment( 'tilde-prerelease-php-ok' ), null, '6.8', '8.4.0~rc1-1+ubuntu22.04.1+deb.sury.org+1' )['expected_version'], 'tilde-separated PHP RC still satisfies lower prerelease requirement' );
foreach ( array( '8.invalid', '8.2.x', '8.2.0abc', '8.2.15abc', '8.2.0beta1foo', '8.2.0.1.2' ) as $malformed_php ) {
	$reject(
		ArchiveValidator::CODE_REQUIRES_NEWER_PHP,
		$zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires PHP: 8.0" ) ) ),
		$deployment( 'malformed-runtime-php' ),
		null,
		'6.8',
		$malformed_php
	);
}

foreach ( array( '6.8', '6.8.0' ) as $required_wp ) {
	$compat_wp = $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires at least: " . $required_wp ) ) );
	foreach ( array( '6.8-RC1', '6.8-beta1-59800', '6.8-beta2-59800-src', '6.8-alpha-59820', '6.8-src' ) as $runtime_wp ) {
		$assert( '1.2.3' === $validate( $compat_wp, $deployment( 'wp-prerelease' ), null, $runtime_wp )['expected_version'], 'WordPress prerelease ' . $runtime_wp . ' satisfies ' . $required_wp );
	}
}
$reject( ArchiveValidator::CODE_REQUIRES_NEWER_WP, $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires at least: 6.8.1" ) ) ), $deployment( 'wp-prerelease-older' ), null, '6.8-RC1' );
foreach ( array( 'invalid-wp', '6.8+build1', '6.8.0-ubuntu1' ) as $invalid_runtime_wp ) {
	$reject( ArchiveValidator::CODE_REQUIRES_NEWER_WP, $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires at least: 6.5" ) ) ), $deployment( 'wp-invalid-runtime' ), null, $invalid_runtime_wp );
}
$reject( ArchiveValidator::CODE_VERSION_INVALID, $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3+build1" ) ) ), $deployment( 'version-build-meta' ) );
$reject( ArchiveValidator::CODE_COMPATIBILITY_INVALID, $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires PHP: 8.2+build1" ) ) ), $deployment( 'php-build-meta' ) );
$reject( ArchiveValidator::CODE_COMPATIBILITY_INVALID, $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires at least: 6.8+build1" ) ) ), $deployment( 'wp-build-meta' ) );

// Issue #135: Header parsing edge cases ('0' values, duplicates, Unicode control/format, lowercase .php).
$zero_plugin = $zip( array( 'repository/demo/demo.php' => "<?php\n/*\nPlugin Name:0\nVersion:0\n*/\n" ) );
$assert( '0' === $validate( $zero_plugin, $deployment( 'zero-plugin' ) )['expected_version'], 'Plugin Name:0 and Version:0 are preserved' );
$zero_theme = $zip( array( 'repository/demo/style.css' => "/*\nTheme Name:0\nVersion:0\nTemplate:0\n*/\n" ) );
$zero_decl  = new BranchDeploymentDeclaration( 'zero-theme', 'theme', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', 'install', 'demo' );
$assert( '0' === $validate( $zero_theme, $zero_decl )['expected_version'], 'Theme Name:0 and Version:0 are preserved' );
$zero_artifact = PreparedArchive::download_and_validate( ( new GitHubFixtureProvider( $zero_theme, 'abc123' ) )->prepare( $zero_decl ), $zero_decl, $root . '/custody' );
try {
	$assert( '0' === $parent_method->invoke( new WordPressCorePackageExecutor(), $zero_artifact, 'demo' ), 'Template:0 is preserved' );
} finally {
	$zero_artifact->cleanup();
}

$reject( ArchiveValidator::CODE_HEADER_UNREADABLE, $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: First\nPlugin Name: Second\nVersion: 1.2.3" ) ) ), $deployment( 'dup-plugin-name' ) );
$reject( ArchiveValidator::CODE_VERSION_INVALID, $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nversion: 2.0.0" ) ) ), $deployment( 'dup-version' ) );
$reject( ArchiveValidator::CODE_COMPATIBILITY_INVALID, $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires PHP: 8.2\nRequires PHP: 8.3" ) ) ), $deployment( 'dup-requires-php' ) );
$reject( ArchiveValidator::CODE_COMPATIBILITY_INVALID, $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires at least: 6.5\nRequires at least: 6.6" ) ) ), $deployment( 'dup-requires-wp' ) );
$helper_dup = $zip(
	array(
		'repository/demo/demo.php'   => $plugin(),
		'repository/demo/helper.php' => "<?php\n/*\nVersion: 1.0\nVersion: 2.0\n*/\n",
	)
);
$assert( '1.2.3' === $validate( $helper_dup, $deployment( 'helper-dup-version' ) )['expected_version'], 'non-plugin helper files with duplicate Version comments do not fail plugin discovery' );

foreach ( array( "\x00", "\x0B", "\u{0085}", "\u{200B}", "\u{202E}", "\u{FEFF}", "\xFF" ) as $bad_char ) {
	$reject( ArchiveValidator::CODE_HEADER_UNREADABLE, $zip( array( 'repository/demo/demo.php' => $plugin( 'Plugin Name: Demo' . $bad_char . "\nVersion: 1.2.3" ) ) ), $deployment( 'unicode-name' ) );
	$reject( ArchiveValidator::CODE_HEADER_UNREADABLE, $zip( array( 'repository/demo/demo.php' => $plugin( 'Plugin Name: ' . $bad_char . "Demo\nVersion: 1.2.3" ) ) ), $deployment( 'unicode-name-leading' ) );
	$reject( ArchiveValidator::CODE_VERSION_INVALID, $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3" . $bad_char ) ) ), $deployment( 'unicode-version' ) );
	$reject( ArchiveValidator::CODE_COMPATIBILITY_INVALID, $zip( array( 'repository/demo/demo.php' => $plugin( "Plugin Name: Demo\nVersion: 1.2.3\nRequires PHP: 8.2" . $bad_char ) ) ), $deployment( 'unicode-php' ) );
}

foreach ( array(
	'duplicate template' => "/*\nTheme Name: Demo\nVersion: 1.2.3\nTemplate: parent-a\nTemplate: parent-b\n*/\n",
	'trailing nul'       => "/*\nTheme Name: Demo\nVersion: 1.2.3\nTemplate: parent\x00\n*/\n",
	'trailing vtab'      => "/*\nTheme Name: Demo\nVersion: 1.2.3\nTemplate: parent\x0B\n*/\n",
) as $template_label => $template_css ) {
	$bad_template_theme = $zip( array( 'repository/demo/style.css' => $template_css ) );
	$bad_template_decl  = new BranchDeploymentDeclaration( 'bad-template', 'theme', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', 'install', 'demo' );
	$bad_template_art   = PreparedArchive::download_and_validate( ( new GitHubFixtureProvider( $bad_template_theme, 'abc123' ) )->prepare( $bad_template_decl ), $bad_template_decl, $root . '/custody' );
	try {
		$assert( false === $parent_method->invoke( new WordPressCorePackageExecutor(), $bad_template_art, 'demo' ), 'invalid Template header is rejected: ' . $template_label );
	} finally {
		$bad_template_art->cleanup();
	}
}

$uppercase_php = $zip( array( 'repository/demo/demo.PHP' => $plugin() ) );
$reject( ArchiveValidator::CODE_PLUGIN_MISSING, $uppercase_php, $deployment( 'uppercase-php' ) );
$mixed_php_ext = $zip(
	array(
		'repository/demo/demo.php'  => $plugin(),
		'repository/demo/other.PHP' => $plugin( "Plugin Name: Other\nVersion: 1.2.3" ),
	)
);
$assert( '1.2.3' === $validate( $mixed_php_ext, $deployment( 'mixed-php-ext' ) )['expected_version'], 'uppercase .PHP files are ignored during plugin discovery' );

// Issue #135: Nested installed identifier rejection and separate subdirectory configuration validation.
$nested_id_zip = $zip( array( 'repository/demo/demo.php' => $plugin() ) );
$reject( ArchiveValidator::CODE_PACKAGE_IDENTITY, $nested_id_zip, $deployment( 'nested-id', 'update', 'demo', 'other/demo/demo.php' ), 'other/demo/demo.php' );
$reject( ArchiveValidator::CODE_PACKAGE_IDENTITY, $nested_id_zip, $deployment( 'non-php-id', 'update', 'demo', 'demo/demo.txt' ), 'demo/demo.txt' );

// Issue #140: Adopted beta.11 ArchiveSafety origin_os (OS X 19 / NTFS 10) and path/reserved-name rules.
$origin_zip = static function ( int $origin_os, int $dir_attr, int $file_attr, ?int $extra_attr = null ) use ( $root, &$sequence, $plugin ): string {
	$path = sprintf( '%s/fixture-%02d.zip', $root, ++$sequence );
	$arch = new ZipArchive();
	if ( true !== $arch->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'Cannot create origin fixture ZIP.' );
	}
	$arch->addEmptyDir( 'repository/demo' );
	$arch->setExternalAttributesIndex( 0, $origin_os, $dir_attr );
	$arch->addFromString( 'repository/demo/demo.php', $plugin() );
	$arch->setExternalAttributesIndex( 1, $origin_os, $file_attr );
	if ( null !== $extra_attr ) {
		$arch->addFromString( 'repository/demo/special', 'target' );
		$arch->setExternalAttributesIndex( 2, $origin_os, $extra_attr );
	}
	$arch->close();
	return $path;
};
$assert(
	'1.2.3' === $validate( $origin_zip( ZipArchive::OPSYS_OS_X, ( 0040755 << 16 ) | 0x10, 0100644 << 16 ), $deployment( 'macos-origin' ) )['expected_version'],
	'macOS / OS X (origin_os 19) regular files and directories are accepted'
);
$reject(
	ArchiveValidator::CODE_ENTRY_UNSUPPORTED,
	$origin_zip( ZipArchive::OPSYS_OS_X, ( 0040755 << 16 ) | 0x10, 0100644 << 16, 0120777 << 16 ),
	$deployment( 'macos-symlink' )
);
$assert(
	'1.2.3' === $validate( $origin_zip( ZipArchive::OPSYS_WINDOWS_NTFS, 0x2010, 0x2020 ), $deployment( 'ntfs-origin' ) )['expected_version'],
	'Windows NTFS (origin_os 10) regular files and directories are accepted'
);
foreach ( array( 0x0420, 0x0040 ) as $ntfs_special_attr ) {
	$reject(
		ArchiveValidator::CODE_ENTRY_UNSUPPORTED,
		$origin_zip( ZipArchive::OPSYS_WINDOWS_NTFS, 0x2010, 0x2020, $ntfs_special_attr ),
		$deployment( 'ntfs-special' )
	);
}
foreach ( array( 'repository/demo/com¹.php', 'repository/demo/lpt³.txt', "repository/demo/bid\u{202E}.php" ) as $unsafe_unicode_path ) {
	$reject(
		ArchiveValidator::CODE_PATH_UNSAFE,
		$zip(
			array(
				'repository/demo/demo.php' => $plugin(),
				$unsafe_unicode_path       => 'x',
			)
		),
		$deployment( 'unsafe-unicode-path' )
	);
}

foreach ( array( '../escape', '/demo', 'demo\\sub', 'packages//demo', "demo\n", 'packages/%2e%2e/demo' ) as $bad_subdir ) {
	try {
		$validate( $valid, $deployment( 'bad-subdir', 'install', $bad_subdir ) );
		$assert( false, 'invalid subdirectory configuration unexpectedly succeeded: ' . $bad_subdir );
	} catch ( InvalidArgumentException $expected ) {
		$assert( 'The package subdirectory must be a normalized relative path.' === $expected->getMessage(), 'invalid subdirectory throws configuration error rather than archive CODE_PATH_UNSAFE: ' . $bad_subdir );
	}
}
$assert( '1.2.3' === $validate( $valid, $deployment( 'padded-subdir', 'install', '  demo/  ' ) )['expected_version'], 'subdirectory configuration normalizes whitespace and trailing slash' );
$root_plugin = $zip( array( 'repository/demo.php' => $plugin() ) );
$assert( '1.2.3' === $validate( $root_plugin, $deployment( 'blank-subdir', 'install', '   ' ) )['expected_version'], 'whitespace-only subdirectory normalizes to root package' );

// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- glob returns an array or false; both no matches and failure retain the existing empty-corpus fallback.
// phpcs:ignore Universal.Operators.DisallowShortTernary.Found,WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone CLI fixture variable does not share WordPress runtime globals.
foreach ( glob( $root . '/*.zip' ) ?: array() as $path ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
	unlink( $path );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the empty synthetic custody directory after every artifact was cleaned.
rmdir( $root . '/custody' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
rmdir( $root );

echo "PASS archive safety, shared package-subdirectory policy, identity, header, and compatibility baseline\n";
