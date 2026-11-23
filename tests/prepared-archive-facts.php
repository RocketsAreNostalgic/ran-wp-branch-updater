<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.
declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Archive\ArchiveValidator;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\PreparedPackageArtifact;
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

$offer = static fn( ?Closure $verify_head = null ): ArchiveOffer => new ArchiveOffer(
	provider: 'fixture',
	repository_id: 'repository-id',
	resolved_ref: 'abc123',
	copy_to: static function ( string $destination, int $maximum_artifact_bytes ) use ( $source ): void {
		$size = filesize( $source );
		if ( false === $size || $size < 1 || $size > $maximum_artifact_bytes || ! copy( $source, $destination ) ) {
			throw new RuntimeException( 'Cannot acquire prepared archive fixture.' );
		}
	},
	verify_head: $verify_head ?? static function (): void {}
);

$verification_calls = 0;
$offer(
	static function () use ( &$verification_calls ): void {
		++$verification_calls;
	}
)->verify_current_head();
$assert( 1 === $verification_calls, 'archive offer invokes the verifier once' );

$verification_failure = new RuntimeException( 'Source verification failed.' );
try {
	$offer(
		static function () use ( $verification_failure ): void {
			throw $verification_failure;
		}
	)->verify_current_head();
	throw new LogicException( 'Archive offer suppressed the verification failure.' );
} catch ( RuntimeException $actual ) {
	$assert( $verification_failure === $actual, 'archive offer propagates the verifier exception unchanged' );
}

foreach ( array(
	'plugin' => 'custom-main.php',
	'theme'  => 'style.css',
) as $package_type => $main ) {
	$zip->open( $source, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'repository/packages/demo/' . $main, 'plugin' === $package_type ? $contents : "/*\nTheme Name: Demo\nVersion: 1.2.3\n*/" );
	$zip->close();
	$exact         = new BranchDeploymentDeclaration( 'exact-target', $package_type, 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', operation: 'install', subdirectory: 'packages/demo', expected_identifier: 'plugin' === $package_type ? 'demo/' . $main : 'demo' );
	$exact_archive = PreparedArchive::download_and_validate( $offer(), $exact, $root . '/archives' );
	$assert( '1.2.3' === $exact_archive->expected_version(), 'exact target supports nonstandard plugin main and subdirectory theme' );
	$exact_archive->cleanup();
}
$zip->open( $source, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( 'repository/demo/demo.php', $contents );
$zip->close();

$transport = $root . '/transport.zip';
$assert( true === $zip->open( $transport, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'transport-limit fixture ZIP opens' );
$zip->addFromString( 'repository/demo/demo.php', "<?php\n/* Plugin Name: Demo\nVersion: 1.2.3 */\n" );
$zip->close();
$transport_bytes = filesize( $transport );
$compressed      = filesize( $source );
if ( false === $transport_bytes || $transport_bytes < 2 || false === $compressed || $compressed < 1 || strlen( $contents ) <= 4 * $compressed ) {
	throw new RuntimeException( 'Archive fixture sizes do not exercise their admission limits.' );
}
$limits        = array();
$bounded_offer = static function ( string $fixture ) use ( &$limits ): ArchiveOffer {
	return new ArchiveOffer(
		'fixture',
		'repository-id',
		'abc123',
		static function ( string $destination, int $maximum_artifact_bytes ) use ( $fixture, &$limits ): void {
			$limits[] = $maximum_artifact_bytes;
			$size     = filesize( $fixture );
			if ( false === $size || $size > $maximum_artifact_bytes ) {
				throw new RuntimeException( 'Synthetic transport reached its byte ceiling.' );
			}
			if ( ! copy( $fixture, $destination ) ) {
				throw new RuntimeException( 'Cannot copy bounded fixture ZIP.' );
			}
		},
		static function (): void {}
	);
};
$bounded       = PreparedArchive::download_and_validate( $bounded_offer( $transport ), $deployment, $root . '/archives', $transport_bytes );
$bounded->cleanup();

try {
	PreparedArchive::download_and_validate( $bounded_offer( $transport ), $deployment, $root . '/archives', $transport_bytes - 1 );
	throw new LogicException( 'Over-limit transport was admitted.' );
} catch ( RuntimeException $expected ) {
	$assert( 'Synthetic transport reached its byte ceiling.' === $expected->getMessage(), 'a one-byte-short ceiling fails at acquisition' );
}

foreach (
	array(
		array( 0, 'Maximum artifact bytes is invalid.' ),
		array( '536870912', 'Maximum artifact bytes is invalid.' ),
		array( intdiv( PHP_INT_MAX, 4 ) + 1, 'Maximum artifact bytes is invalid for expanded archive validation.' ),
	) as [$invalid_limit, $message]
) {
	try {
		PreparedArchive::download_and_validate( $bounded_offer( $transport ), $deployment, $root . '/archives', $invalid_limit );
		throw new LogicException( 'Invalid configured ceiling was admitted.' );
	} catch ( RuntimeException $expected ) {
		$assert( $expected->getMessage() === $message, 'invalid byte ceilings fail validation' );
	}
}
$assert( array( $transport_bytes, $transport_bytes - 1 ) === $limits, 'the exact ceiling reaches acquisition once and invalid ceilings never reach it' );

try {
	PreparedArchive::download_and_validate( $bounded_offer( $source ), $deployment, $root . '/archives', $compressed );
	throw new LogicException( 'Archive exceeding its expanded ceiling was admitted.' );
} catch ( RuntimeException $expected ) {
	$assert( ArchiveValidator::CODE_EXPANDED_TOO_LARGE === $expected->getCode(), 'the configured compressed ceiling also binds expanded size' );
}
$assert( array( $transport_bytes, $transport_bytes - 1, $compressed ) === $limits && array() === glob( $root . '/archives/*' ), 'expanded refusal follows acquisition and failed admissions leave no staged files' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the transport-limit fixture archive.
unlink( $transport );

$archive_type = new ReflectionClass( PreparedArchive::class );
$constructor  = $archive_type->getConstructor();
$assert( null !== $constructor && $constructor->isPrivate(), 'prepared archive facts cannot be supplied through public construction' );

$artifact = PreparedArchive::download_and_validate(
	offer: $offer(),
	d: $deployment,
	directory: $root . '/archives',
	maximum_artifact_bytes: PreparedArchive::DEFAULT_MAXIMUM_ARTIFACT_BYTES
);
$assert( strlen( $contents ) === $artifact->expanded_bytes(), 'prepared archive retains the expanded byte count from validation' );
$assert( $archive_type->implementsInterface( AdmittedBranchArtifact::class ) && $archive_type->implementsInterface( PreparedPackageArtifact::class ), 'one validated owner supplies both archive contracts' );
$assert( 'abc123' === $artifact->resolved_ref() && $artifact->resolved_ref === $artifact->resolved_ref(), 'resolved reference accessor retains immutable metadata' );
$assert( '1.2.3' === $artifact->expected_version() && $artifact->version === $artifact->expected_version() && $artifact->get_expected_version() === $artifact->expected_version(), 'version accessors agree on validated metadata' );
$clone = clone $artifact;
$artifact->cleanup();
$artifact->cleanup();
$assert( 'abc123' === $artifact->resolved_ref() && '1.2.3' === $artifact->expected_version(), 'immutable journal metadata survives idempotent cleanup' );
try {
	$artifact->get_path();
	$assert( false, 'cleaned archive must not expose a usable path' );
} catch ( RuntimeException $expected ) {
	$assert( 'Prepared archive changed before use.' === $expected->getMessage(), 'path access still checks custody after cleanup' );
}
try {
	$clone->cleanup();
	$assert( false, 'cloned owner must not share the original cleanup state' );
} catch ( RuntimeException $expected ) {
	$assert( 'Prepared archive changed before use.' === $expected->getMessage(), 'clone fails custody after its shared file is removed' );
}
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
try {
	$tampered->cleanup();
	$assert( false, 'changed bytes must not be silently removed by cleanup' );
} catch ( RuntimeException $expected ) {
	$assert( 'Prepared archive changed before use.' === $expected->getMessage() && is_file( $path ), 'cleanup preserves the changed archive for host recovery' );
}
// Unwritable / non-private staging directory and exclusive creation collisions must fail closed without falling back to system temp.
$before_tmp = glob( rtrim( sys_get_temp_dir(), '/\\' ) . '/ran-branch-*' );
$before_tmp = is_array( $before_tmp ) ? $before_tmp : array();
$non_dir    = $root . '/not-a-directory';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Create a regular file to verify non-directory staging target refusal.
file_put_contents( $non_dir, 'x' );
$non_dir_acquired = false;
$non_dir_offer    = new ArchiveOffer(
	provider: 'fixture',
	repository_id: 'repository-id',
	resolved_ref: 'abc123',
	copy_to: static function ( string $destination, int $maximum_artifact_bytes ) use ( &$non_dir_acquired ): void {
		$non_dir_acquired = '' !== $destination || $maximum_artifact_bytes >= 0;
	},
	verify_head: static function (): void {}
);
$non_dir_error    = null;
try {
	PreparedArchive::download_and_validate( $non_dir_offer, $deployment, $non_dir );
} catch ( RuntimeException $expected ) {
	$non_dir_error = $expected;
}
$assert(
	$non_dir_error instanceof RuntimeException
		&& 'Archive directory is unsafe.' === $non_dir_error->getMessage()
		&& ! $non_dir_acquired,
	'non-directory staging path must fail before file creation or acquisition'
);
// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove non-directory fixture file.
unlink( $non_dir );

$blocked_parent = $root . '/file-parent';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Create a regular file parent so nested mkdir() fails deterministically even under privileged workers.
file_put_contents( $blocked_parent, 'not-a-directory' );
$blocked_acquired = false;
$blocked_offer    = new ArchiveOffer(
	provider: 'fixture',
	repository_id: 'repository-id',
	resolved_ref: 'abc123',
	copy_to: static function ( string $destination, int $maximum_artifact_bytes ) use ( &$blocked_acquired ): void {
		$blocked_acquired = '' !== $destination || $maximum_artifact_bytes >= 0;
	},
	verify_head: static function (): void {}
);
$blocked_error    = null;
try {
	PreparedArchive::download_and_validate( $blocked_offer, $deployment, $blocked_parent . '/nested' );
} catch ( RuntimeException $expected ) {
	$blocked_error = $expected;
} finally {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove regular-file parent fixture.
	unlink( $blocked_parent );
}
$assert(
	$blocked_error instanceof RuntimeException
		&& 'Cannot create private archive directory.' === $blocked_error->getMessage()
		&& ! $blocked_acquired,
	'uncreatable staging directory must fail closed without invoking acquisition'
);

// Deterministic exclusive file-creation failure (EEXIST) and RandomException classification must fail closed without invoking acquisition.
$run_child = static function ( string $code, string ...$arguments ) use ( $assert ): string {
	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Run an isolated PHP process to test namespace-scoped entropy failure and exclusive creation collisions.
	$process = proc_open( array_values( array_merge( array( PHP_BINARY, '-r', $code, '--' ), $arguments ) ), $descriptors, $pipes );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'FAIL: isolated staging proof subprocess must start' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close unused child stdin pipe.
	fclose( $pipes[0] );
	$stdout = (string) stream_get_contents( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close child output pipes before proc_close.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close child error pipe before proc_close.
	fclose( $pipes[2] );
	$exit_code = proc_close( $process );
	$assert( 0 === $exit_code, 'isolated staging proof failed: ' . trim( $stderr . "\n" . $stdout ) );
	return trim( $stdout );
};

$autoload_path = dirname( __DIR__ ) . '/vendor/autoload.php';
$collision_dir = $root . '/exclusive-collision';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create private staging directory for deterministic exclusive-create refusal proof.
mkdir( $collision_dir, 0700, true );
$collision_file = $collision_dir . '/ran-branch-' . str_repeat( '00', 16 );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Pre-create colliding staging path to force fopen('x+b') failure.
file_put_contents( $collision_file, 'sentinel' );
$collision_output = $run_child(
	<<<'PHP'
namespace RAN\WPBranchUpdater\V1\Archive {
	function random_bytes( int $length ): string {
		return str_repeat( "\0", $length );
	}
}
namespace {
	require $argv[1];
	$acquired = false;
	$offer = new \RAN\WPBranchUpdater\V1\Archive\ArchiveOffer(
		provider: 'fixture',
		repository_id: 'repository-id',
		resolved_ref: 'abc123',
		copy_to: static function ( string $destination, int $max ) use ( &$acquired ): void {
			$acquired = '' !== $destination || $max >= 0;
		},
		verify_head: static function (): void {}
	);
	$deployment = new \RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration(
		'exclusive-collision', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', 'install', 'demo', null
	);
	$error = null;
	try {
		\RAN\WPBranchUpdater\V1\Archive\PreparedArchive::download_and_validate( $offer, $deployment, $argv[2] );
	} catch ( \RuntimeException $e ) {
		$error = $e;
	}
	if ( ! $error instanceof \RuntimeException || 'Cannot create private archive file.' !== $error->getMessage() || $acquired ) {
		fwrite( STDERR, 'Unexpected exclusive-create result' );
		exit( 1 );
	}
	echo 'OK';
}
PHP,
	$autoload_path,
	$collision_dir
);
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verify pre-existing colliding file was untouched.
$assert( 'OK' === $collision_output && 'sentinel' === file_get_contents( $collision_file ), 'exclusive file creation collision must fail closed and leave pre-existing files untouched' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove colliding fixture file.
unlink( $collision_file );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove colliding fixture directory.
rmdir( $collision_dir );

$entropy_dir = $root . '/entropy-failure';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create private staging directory for entropy-failure classification proof.
mkdir( $entropy_dir, 0700, true );
$entropy_output = $run_child(
	<<<'PHP'
namespace RAN\WPBranchUpdater\V1\Archive {
	function random_bytes( int $length ): string {
		throw new \Random\RandomException( 'Entropy unavailable.' );
	}
}
namespace {
	require $argv[1];
	$acquired = false;
	$offer = new \RAN\WPBranchUpdater\V1\Archive\ArchiveOffer(
		'fixture', 'repository-id', 'abc123',
		static function ( string $destination, int $max ) use ( &$acquired ): void { $acquired = '' !== $destination || $max >= 0; },
		static function (): void {}
	);
	$deployment = new \RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration(
		'entropy-failure', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', 'install', 'demo', null
	);
	$stage = null;
	try {
		\RAN\WPBranchUpdater\V1\Archive\PreparedArchive::download_and_validate( $offer, $deployment, $argv[2] );
	} catch ( \RuntimeException $e ) {
		$stage = $e;
	}
	if ( ! $stage instanceof \RuntimeException
		|| $acquired
	) {
		fwrite( STDERR, 'Unexpected entropy failure classification' );
		exit( 1 );
	}
	echo 'OK';
}
PHP,
	$autoload_path,
	$entropy_dir
);
$assert( 'OK' === $entropy_output, 'archive staging entropy failure must fail before acquisition' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove entropy-failure fixture directory.
rmdir( $entropy_dir );

// Issue #135: chmod(0600) failure and null identity must clean up the created temporary file without leaking it.
$chmod_dir = $root . '/chmod-failure';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create private staging directory for chmod-failure cleanup proof.
mkdir( $chmod_dir, 0700, true );
$chmod_output = $run_child(
	<<<'PHP'
namespace RAN\WPBranchUpdater\V1\Archive {
	function chmod( string $filename, int $permissions ): bool {
		if ( 0600 === $permissions ) {
			return false;
		}
		return \chmod( $filename, $permissions );
	}
}
namespace {
	require $argv[1];
	$acquired = false;
	$offer = new \RAN\WPBranchUpdater\V1\Archive\ArchiveOffer(
		'fixture', 'repository-id', 'abc123',
		static function ( string $destination, int $max ) use ( &$acquired ): void { $acquired = '' !== $destination || $max >= 0; },
		static function (): void {}
	);
	$deployment = new \RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration(
		'chmod-failure', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', 'install', 'demo', null
	);
	$error = null;
	try {
		\RAN\WPBranchUpdater\V1\Archive\PreparedArchive::download_and_validate( $offer, $deployment, $argv[2] );
	} catch ( \RuntimeException $e ) {
		$error = $e;
	}
	$leftovers = glob( $argv[2] . '/ran-branch-*' );
	if ( ! $error instanceof \RuntimeException || 'Cannot create private archive file.' !== $error->getMessage() || $acquired || array() !== ( is_array( $leftovers ) ? $leftovers : array() ) ) {
		fwrite( STDERR, 'Unexpected chmod-failure cleanup result' );
		exit( 1 );
	}
	echo 'OK';
}
PHP,
	$autoload_path,
	$chmod_dir
);
$assert( 'OK' === $chmod_output && array() === glob( $chmod_dir . '/*' ), 'chmod(0600) failure must remove the temporary file without invoking acquisition' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove chmod-failure fixture directory.
rmdir( $chmod_dir );

$identity_dir = $root . '/identity-null';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create private staging directory for null-identity cleanup proof.
mkdir( $identity_dir, 0700, true );
$identity_output = $run_child(
	<<<'PHP'
namespace RAN\WPBranchUpdater\V1\Archive {
	function chmod( string $filename, int $permissions ): bool {
		if ( 0600 === $permissions ) {
			\chmod( $filename, 0644 );
			return true;
		}
		return \chmod( $filename, $permissions );
	}
}
namespace {
	require $argv[1];
	$acquired = false;
	$offer = new \RAN\WPBranchUpdater\V1\Archive\ArchiveOffer(
		'fixture', 'repository-id', 'abc123',
		static function ( string $destination, int $max ) use ( &$acquired ): void { $acquired = '' !== $destination || $max >= 0; },
		static function (): void {}
	);
	$deployment = new \RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration(
		'identity-null', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', 'install', 'demo', null
	);
	$error = null;
	try {
		\RAN\WPBranchUpdater\V1\Archive\PreparedArchive::download_and_validate( $offer, $deployment, $argv[2] );
	} catch ( \RuntimeException $e ) {
		$error = $e;
	}
	$leftovers = glob( $argv[2] . '/ran-branch-*' );
	if ( ! $error instanceof \RuntimeException || 'Private archive identity is invalid.' !== $error->getMessage() || $acquired || array() !== ( is_array( $leftovers ) ? $leftovers : array() ) ) {
		fwrite( STDERR, 'Unexpected identity-null cleanup result' );
		exit( 1 );
	}
	echo 'OK';
}
PHP,
	$autoload_path,
	$identity_dir
);
$assert( 'OK' === $identity_output && array() === glob( $identity_dir . '/*' ), 'null identity after chmod must remove the temporary file without invoking acquisition' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove identity-null fixture directory.
rmdir( $identity_dir );

// Issue #135: Post-validate identity/digest recheck, guarded hash_file(), and unfiltered WordPress runtime version resolution.
$wp_ver_zip = $root . '/wp-requires.zip';
$zip->open( $wp_ver_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( 'repository/demo/demo.php', "<?php\n/*\nPlugin Name: Demo\nVersion: 1.2.3\nRequires at least: 6.8\n*/\n" );
$zip->close();
$wp_ver_dir = $root . '/wp-version';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create private staging directory for runtime WordPress version resolution proof.
mkdir( $wp_ver_dir, 0700, true );
$wp_global_output = $run_child(
	<<<'PHP'
require $argv[1];
function get_bloginfo( string $show = '' ): string {
	return '1.0';
}
$GLOBALS['wp_version'] = '6.8-RC1';
$source = $argv[3];
$offer = new \RAN\WPBranchUpdater\V1\Archive\ArchiveOffer(
	'fixture', 'repository-id', 'abc123',
	static function ( string $destination, int $max ) use ( $source ): void {
		if ( ! copy( $source, $destination ) ) {
			throw new \RuntimeException( 'copy failed' );
		}
	},
	static function (): void {}
);
$deployment = new \RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration(
	'wp-global', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', 'install', 'demo', null
);
$artifact = \RAN\WPBranchUpdater\V1\Archive\PreparedArchive::download_and_validate( $offer, $deployment, $argv[2] );
$version  = $artifact->expected_version();
$artifact->cleanup();
echo '1.2.3' === $version ? 'OK' : 'FAIL';
PHP,
	$autoload_path,
	$wp_ver_dir,
	$wp_ver_zip
);
$assert( 'OK' === $wp_global_output, 'PreparedArchive must read $GLOBALS[wp_version] instead of filterable get_bloginfo(version)' );

$wp_fn_output = $run_child(
	<<<'PHP'
require $argv[1];
function get_bloginfo( string $show = '' ): string {
	return '1.0';
}
function wp_get_wp_version(): string {
	return '6.8-RC1';
}
$GLOBALS['wp_version'] = '5.0';
$source = $argv[3];
$offer = new \RAN\WPBranchUpdater\V1\Archive\ArchiveOffer(
	'fixture', 'repository-id', 'abc123',
	static function ( string $destination, int $max ) use ( $source ): void {
		if ( ! copy( $source, $destination ) ) {
			throw new \RuntimeException( 'copy failed' );
		}
	},
	static function (): void {}
);
$deployment = new \RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration(
	'wp-fn', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', 'install', 'demo', null
);
$artifact = \RAN\WPBranchUpdater\V1\Archive\PreparedArchive::download_and_validate( $offer, $deployment, $argv[2] );
$version  = $artifact->expected_version();
$artifact->cleanup();
echo '1.2.3' === $version ? 'OK' : 'FAIL';
PHP,
	$autoload_path,
	$wp_ver_dir,
	$wp_ver_zip
);
$assert( 'OK' === $wp_fn_output, 'PreparedArchive must prefer wp_get_wp_version() over both get_bloginfo() and $GLOBALS[wp_version]' );

$post_validate_identity_output = $run_child(
	<<<'PHP'
require $argv[1];
$staged_path = '';
function wp_get_wp_version(): string {
	global $staged_path;
	if ( '' !== $staged_path ) {
		chmod( $staged_path, 0644 );
	}
	return '6.8';
}
$source = $argv[3];
$offer = new \RAN\WPBranchUpdater\V1\Archive\ArchiveOffer(
	'fixture', 'repository-id', 'abc123',
	static function ( string $destination, int $max ) use ( $source, &$staged_path ): void {
		$staged_path = $destination;
		if ( ! copy( $source, $destination ) ) {
			throw new \RuntimeException( 'copy failed' );
		}
	},
	static function (): void {}
);
$deployment = new \RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration(
	'post-validate-identity', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', 'install', 'demo', null
);
$error = null;
try {
	\RAN\WPBranchUpdater\V1\Archive\PreparedArchive::download_and_validate( $offer, $deployment, $argv[2] );
} catch ( \RAN\WPBranchUpdater\V1\Runtime\ReasonedFailure $e ) {
	$error = $e;
}
$leftovers = glob( $argv[2] . '/ran-branch-*' );
if ( ! $error instanceof \RAN\WPBranchUpdater\V1\Runtime\ReasonedFailure
	|| \RAN\WPBranchUpdater\V1\Runtime\FailureReason::ARCHIVE_CUSTODY_CHANGED->value !== $error->reason
	|| 'identity' !== $error->detail
	|| array() !== ( is_array( $leftovers ) ? $leftovers : array() ) ) {
	fwrite( STDERR, 'Post-validate identity recheck failed' );
	exit( 1 );
}
echo 'OK';
PHP,
	$autoload_path,
	$wp_ver_dir,
	$wp_ver_zip
);
$assert( 'OK' === $post_validate_identity_output, 'post-validate custody recheck must detect identity drift and clean up the rejected file' );

$guarded_hash_output = $run_child(
	<<<'PHP'
namespace RAN\WPBranchUpdater\V1\Archive {
	function hash_file( string $algo, string $filename, bool $binary = false ): string|false {
		static $calls = 0;
		++$calls;
		if ( 2 === $calls ) {
			return false;
		}
		return \hash_file( $algo, $filename, $binary );
	}
}
namespace {
	set_error_handler(
		static function ( int $severity, string $message ): bool {
			throw new \ErrorException( $message, 0, $severity );
		}
	);
	require $argv[1];
	$source = $argv[3];
	$offer = new \RAN\WPBranchUpdater\V1\Archive\ArchiveOffer(
		'fixture', 'repository-id', 'abc123',
		static function ( string $destination, int $max ) use ( $source ): void {
			if ( ! copy( $source, $destination ) ) {
				throw new \RuntimeException( 'copy failed' );
			}
		},
		static function (): void {}
	);
	$deployment = new \RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration(
		'post-validate-hash', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', 'install', 'demo', null
	);
	$GLOBALS['wp_version'] = '6.8';
	$error = null;
	try {
		\RAN\WPBranchUpdater\V1\Archive\PreparedArchive::download_and_validate( $offer, $deployment, $argv[2] );
	} catch ( \RAN\WPBranchUpdater\V1\Runtime\ReasonedFailure $e ) {
		$error = $e;
	}
	$leftovers = glob( $argv[2] . '/ran-branch-*' );
	if ( ! $error instanceof \RAN\WPBranchUpdater\V1\Runtime\ReasonedFailure
		|| \RAN\WPBranchUpdater\V1\Runtime\FailureReason::ARCHIVE_CUSTODY_CHANGED->value !== $error->reason
		|| 'digest' !== $error->detail
		|| array() !== ( is_array( $leftovers ) ? $leftovers : array() ) ) {
		fwrite( STDERR, 'Guarded post-validate hash_file failure check failed' );
		exit( 1 );
	}
	echo 'OK';
}
PHP,
	$autoload_path,
	$wp_ver_dir,
	$wp_ver_zip
);
$assert( 'OK' === $guarded_hash_output, 'guarded hash_file() failure must fail closed as digest custody change without warning and clean up' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove WordPress-version fixture archive.
unlink( $wp_ver_zip );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove WordPress-version fixture directory.
rmdir( $wp_ver_dir );

$after_tmp = glob( rtrim( sys_get_temp_dir(), '/\\' ) . '/ran-branch-*' );
$after_tmp = is_array( $after_tmp ) ? $after_tmp : array();
$assert( $before_tmp === $after_tmp, 'failed archive staging must never fall back to the system temporary directory' );

// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
unlink( $path );
// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
unlink( $source );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
rmdir( $root . '/archives' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
rmdir( $root );

echo "PASS prepared archive validated expanded-byte facts and custody binding\n";
