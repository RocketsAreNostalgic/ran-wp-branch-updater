<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.
declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\PackageExecutor;
use RAN\WPBranchUpdater\V1\Contract\PreparedPackageArtifact;
use RAN\WPBranchUpdater\V1\GitHubFixtureProvider;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionFailure;
use RAN\WPBranchUpdater\V1\Runtime\ProviderArchiveSource;
use RAN\WPBranchUpdater\V1\Runtime\StandalonePackageExecutor;
use RAN\WPBranchUpdater\V1\Runtime\StandaloneTargetFacts;

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

$prepared = ( new ProviderArchiveSource( new GitHubFixtureProvider( $source, 'abc123', 'repository-id' ), $root . '/archives' ) )->prepare( $deployment, null );
$assert( $prepared instanceof PreparedArchive, 'provider source returns its validated archive owner directly' );
$executor = new class() implements PackageExecutor {
	public function target_directory( BranchDeploymentDeclaration $deployment ): string {
		return __DIR__ . '/build/absent-target/' . $deployment->slug;
	}

	public ?PreparedArchive $preflight_archive = null;
	public ?PreparedArchive $executed_archive  = null;

	/** @return array{} */
	public function preflight( BranchDeploymentDeclaration $deployment, PreparedArchive $archive ): array {
		$archive->assert_unchanged();
		$this->preflight_archive = $archive;
		return array();
	}

	public function execute( BranchDeploymentDeclaration $deployment, PreparedArchive $archive ): void {
		$archive->assert_unchanged();
		$this->executed_archive = $archive;
	}
};
$target   = new StandaloneTargetFacts( $executor );
$admitted = new StandalonePackageExecutor( $executor, $target );
$admitted->preflight( $deployment, $prepared );
$assert( $admitted->execute( $deployment, null, $prepared )->is_successful(), 'custom standalone executor completes with direct archive owner' );
$assert( $prepared === $executor->preflight_archive && $prepared === $executor->executed_archive, 'custom executor receives the same custody owner at both boundaries' );
$prepared->cleanup();
$assert(
	array(
		'identifier' => 'demo',
		'version'    => '1.2.3',
		'active'     => false,
	) === $target->installed( $deployment ),
	'standalone postconditions retain validated metadata after cleanup'
);

$fake = new class() implements AdmittedBranchArtifact {
	public bool $touched = false;

	public function resolved_ref(): string {
		return 'abc123';
	}

	public function expected_version(): string {
		return '1.2.3';
	}

	public function assert_unchanged(): void {
		$this->touched = true;
	}

	public function cleanup(): void {
		$this->touched = true;
	}
};
try {
	$admitted->preflight( $deployment, $fake );
	$assert( false, 'standalone preflight must reject an arbitrary admitted artifact' );
} catch ( RuntimeException $expected ) {
	$assert( 'The standalone executor requires the admitted archive artifact.' === $expected->getMessage(), 'preflight keeps its concrete custody-owner boundary' );
}
$assert( CorePackageExecutionFailure::INVALID_REQUEST === $admitted->execute( $deployment, null, $fake )->get_failure(), 'standalone execution rejects a fake admitted artifact' );
$assert( ! $fake->touched, 'fake artifact cannot authorize itself through its custody method' );

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

$readonly_parent = $root . '/readonly-parent';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create a read-only parent directory to test directory creation refusal.
mkdir( $readonly_parent, 0700, true );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restrict parent permissions to prove directory creation fails closed.
chmod( $readonly_parent, 0500 );
$readonly_acquired = false;
$readonly_offer    = new ArchiveOffer(
	provider: 'fixture',
	repository_id: 'repository-id',
	resolved_ref: 'abc123',
	copy_to: static function ( string $destination, int $maximum_artifact_bytes ) use ( &$readonly_acquired ): void {
		$readonly_acquired = '' !== $destination || $maximum_artifact_bytes >= 0;
	},
	verify_head: static function (): void {}
);
$readonly_error    = null;
try {
	PreparedArchive::download_and_validate( $readonly_offer, $deployment, $readonly_parent . '/nested' );
} catch ( RuntimeException $expected ) {
	$readonly_error = $expected;
} finally {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restore permissions for cleanup.
	chmod( $readonly_parent, 0700 );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove read-only parent fixture directory.
	rmdir( $readonly_parent );
}
$assert(
	$readonly_error instanceof RuntimeException
		&& 'Cannot create private archive directory.' === $readonly_error->getMessage()
		&& ! $readonly_acquired,
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
	$provider = new class( $acquired ) implements \RAN\WPBranchUpdater\V1\Contract\BranchProvider {
		public function __construct( public bool &$acquired ) {}
		public function supports_selected_commit(): bool { return false; }
		public function prepare( \RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration $d ): \RAN\WPBranchUpdater\V1\Archive\ArchiveOffer {
			$acquired =& $this->acquired;
			return new \RAN\WPBranchUpdater\V1\Archive\ArchiveOffer(
				provider: 'fixture',
				repository_id: 'repository-id',
				resolved_ref: 'abc123',
				copy_to: static function ( string $destination, int $max ) use ( &$acquired ): void {
					$acquired = '' !== $destination || $max >= 0;
				},
				verify_head: static function (): void {}
			);
		}
	};
	$deployment = new \RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration(
		'entropy-failure', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', 'abc123', 'install', 'demo', null
	);
	$source = new \RAN\WPBranchUpdater\V1\Runtime\ProviderArchiveSource( $provider, $argv[2] );
	$stage = null;
	try {
		$source->prepare( $deployment, null );
	} catch ( \RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchStageFailure $e ) {
		$stage = $e;
	}
	if ( ! $stage instanceof \RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchStageFailure
		|| 'archive_integrity_failed' !== $stage->outcome_code
		|| \RAN\WPBranchUpdater\V1\Runtime\FailureReason::ARCHIVE_PREPARE_FAILED->value !== $stage->reason
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
$assert( 'OK' === $entropy_output, 'archive staging entropy failure must classify as archive_integrity_failed / archive_prepare_failed' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove entropy-failure fixture directory.
rmdir( $entropy_dir );

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
