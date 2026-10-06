<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.
declare(strict_types=1);
require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Contract\PackageExecutor;
use RAN\WPBranchUpdater\V1\GitHubFixtureProvider;
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore;
use RAN\WPBranchUpdater\V1\Persistence\FileMutationLock;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\StandaloneBranchRunner;

final class RAN_WP_Branch_Updater_RAN_BranchDeploymentHardStopExecutor implements PackageExecutor {
	public function preflight( BranchDeploymentDeclaration $deployment, PreparedArchive $archive ): array {
		$archive->assert_unchanged();
		return array();
	}
	public function execute( BranchDeploymentDeclaration $deployment, PreparedArchive $archive ): void {
		$archive->assert_unchanged();
		if ( ! function_exists( 'posix_kill' ) ) {
			throw new RuntimeException( 'The hard-stop proof requires posix_kill.' );
		}
		posix_kill( posix_getpid(), SIGKILL );
	}
}

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone CLI fixture variable does not share WordPress runtime globals.
$mode = $argv[1] ?? 'parent';
if ( 'child' === $mode ) {
	[$journal, $archive, $root] = array_slice( $argv, 2, 3 );
	$deployment                 = new BranchDeploymentDeclaration( 'hard-stop', 'plugin', 'hard-stop-target', 'fixture/one', 'fixture-id', 'main', 'head', 'install' );
	( new StandaloneBranchRunner( new GitHubFixtureProvider( $archive, 'head' ), new FileAttemptStore( $journal ), new RAN_WP_Branch_Updater_RAN_BranchDeploymentHardStopExecutor(), $root . '/archives', new FileMutationLock( $root . '/mutation.lock' ) ) )->execute( $deployment );
	exit( 99 );
}
if ( 'recover' === $mode ) {
	$store = new FileAttemptStore( $argv[2] );
	$store->recover_stopped( 'hard-stop' );
	if ( 'needs_attention' !== $store->get( 'hard-stop' )['state'] ) {
		throw new RuntimeException( 'Fenced hard stop was not retained.' );
	}
	exit( 0 );
}

$root = $argv[1] ?? __DIR__ . '/build/branch-updater-proof/run-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
if ( ! mkdir( $root, 0700, true ) ) {
	throw new RuntimeException( 'Cannot create retained proof directory.' );
}
$archive = $root . '/fixture.zip';
$zip     = new ZipArchive();
if ( true !== $zip->open( $archive, ZipArchive::CREATE ) ) {
	throw new RuntimeException( 'Cannot create fixture ZIP.' );
}
$zip->addFromString( 'repository/main.php', "<?php\n/*\nPlugin Name: Hard Stop\nVersion: 1.0.0\n*/\n" );
$zip->close();
$journal = $root . '/journal/attempts.json';
$php     = escapeshellarg( PHP_BINARY );
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone CLI fixture variable does not share WordPress runtime globals.
$self = escapeshellarg( __FILE__ );
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- The standalone proof must launch a real checker or fresh worker and observe its process status.
$child = proc_open(
	array( PHP_BINARY, __FILE__, 'child', $journal, $archive, $root ),
	array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	),
	$pipes
);
if ( ! is_resource( $child ) ) {
	throw new RuntimeException( 'Cannot start hard-stop child.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone checker/child-process proof uses the exact native pipe descriptor.
fclose( $pipes[0] );
do {
	$child_status = proc_get_status( $child );
	if ( $child_status['running'] ) {
		usleep( 10000 );
	}
} while ( $child_status['running'] );
stream_get_contents( $pipes[1] );
stream_get_contents( $pipes[2] );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone checker/child-process proof uses the exact native pipe descriptor.
fclose( $pipes[1] );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone checker/child-process proof uses the exact native pipe descriptor.
fclose( $pipes[2] );
proc_close( $child );
$normalised_status = $child_status['signaled'] ? 128 + $child_status['termsig'] : $child_status['exitcode'];
if ( 137 !== $normalised_status || ! $child_status['signaled'] || SIGKILL !== $child_status['termsig'] ) {
	throw new RuntimeException( 'Child did not terminate by SIGKILL.' );
}
$before_recovery = ( new FileAttemptStore( $journal ) )->get( 'hard-stop' );
if ( 'running' !== $before_recovery['state'] || null === $before_recovery['mutation_started_at'] ) {
	throw new RuntimeException( 'Hard stop did not retain the durable mutation fence.' );
}
// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- glob returns an array or false; both no matches and failure retain the existing empty-corpus fallback.
if ( 1 !== count( glob( $root . '/archives/*' ) ?: array() ) ) {
	throw new RuntimeException( 'Hard stop did not retain the prepared archive.' );
}
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- The standalone proof must launch a real checker or fresh worker and observe its process status.
exec( $php . ' ' . $self . ' recover ' . escapeshellarg( $journal ), $ignored, $recover_status );
if ( 0 !== $recover_status ) {
	throw new RuntimeException( 'Fresh-process recovery failed.' );
}
$store = new FileAttemptStore( $journal );
try {
	$store->begin( new BranchDeploymentDeclaration( 'retry', 'plugin', 'hard-stop-target', 'fixture/two', 'other-id', 'other', 'head', 'install' ) );
	throw new RuntimeException( 'Retry was admitted.' );
} catch ( RuntimeException $expected ) {
	if ( 'Target already has an unresolved execution.' !== $expected->getMessage() ) {
		throw $expected;
	}
}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only proof output includes its retained local evidence path, not HTML.
echo "PASS coordinator SIGKILL, fresh recovery, and retained target block: $root\n";
