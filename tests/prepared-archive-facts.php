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
// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
unlink( $path );
// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
unlink( $source );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
rmdir( $root . '/archives' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
rmdir( $root );

echo "PASS prepared archive validated expanded-byte facts and custody binding\n";
