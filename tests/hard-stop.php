<?php
declare(strict_types=1);
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Standalone CLI proof locals are never WordPress globals.
// phpcs:disable WordPress.WP.GlobalVariablesOverride -- Standalone CLI proof locals do not override a WordPress runtime.
// phpcs:disable WordPress.WP.AlternativeFunctions -- The proof retains exact local filesystem evidence.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- The proof requires fresh child processes.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- The proof requires exact child signal status.
// phpcs:disable Universal.Operators.DisallowShortTernary.Found -- The empty glob fallback is deterministic proof code.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- This is CLI-only evidence output.
require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\BranchDeployment\{BranchDeploymentOperation, Deployment, FileAttemptStore, FileMutationLock, GitHubFixtureProvider, PackageExecutor, PreparedArchive};

final class RAN_BranchDeploymentHardStopExecutor implements PackageExecutor {
	public function preflight( Deployment $deployment, PreparedArchive $archive ): array {
		$archive->assertUnchanged();
		return array(); }
	public function execute( Deployment $deployment, PreparedArchive $archive ): void {
		$archive->assertUnchanged();
		if ( ! function_exists( 'posix_kill' ) ) {
			throw new RuntimeException( 'The hard-stop proof requires posix_kill.' );
		}
		posix_kill( posix_getpid(), SIGKILL );
	}
}

$mode = $argv[1] ?? 'parent';
if ( 'child' === $mode ) {
	[$journal, $archive, $root] = array_slice( $argv, 2, 3 );
	$deployment                 = new Deployment( 'hard-stop', 'plugin', 'hard-stop-target', 'fixture/one', 'fixture-id', 'main', 'head', 'install' );
	( new BranchDeploymentOperation( new GitHubFixtureProvider( $archive, 'head' ), new FileAttemptStore( $journal ), new RAN_BranchDeploymentHardStopExecutor(), $root . '/archives', new FileMutationLock( $root . '/mutation.lock' ) ) )->execute( $deployment );
	exit( 99 );
}
if ( 'recover' === $mode ) {
	$store = new FileAttemptStore( $argv[2] );
	$store->recoverStopped( 'hard-stop' );
	if ( 'needs_attention' !== $store->get( 'hard-stop' )['state'] ) {
		throw new RuntimeException( 'Fenced hard stop was not retained.' );
	}
	exit( 0 );
}

$root = $argv[1] ?? __DIR__ . '/build/branch-updater-proof/run-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) );
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
$self    = escapeshellarg( __FILE__ );
// proc_get_status exposes the signal directly on macOS, where exec's numeric
// status is not consistently shell-normalised to 128 + signal.
$child = proc_open(
	$php . ' ' . $self . ' child ' . escapeshellarg( $journal ) . ' ' . escapeshellarg( $archive ) . ' ' . escapeshellarg( $root ),
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
fclose( $pipes[0] );
do {
	$childStatus = proc_get_status( $child );
	if ( $childStatus['running'] ) {
		usleep( 10000 );
	}
} while ( $childStatus['running'] );
stream_get_contents( $pipes[1] );
stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
proc_close( $child );
$normalisedStatus = $childStatus['signaled'] ? 128 + $childStatus['termsig'] : $childStatus['exitcode'];
if ( 137 !== $normalisedStatus || ! $childStatus['signaled'] || SIGKILL !== $childStatus['termsig'] ) {
	throw new RuntimeException( 'Child did not terminate by SIGKILL.' );
}
$beforeRecovery = ( new FileAttemptStore( $journal ) )->get( 'hard-stop' );
if ( 'running' !== $beforeRecovery['state'] || null === $beforeRecovery['mutation_started_at'] ) {
	throw new RuntimeException( 'Hard stop did not retain the durable mutation fence.' );
}
if ( 1 !== count( glob( $root . '/archives/*' ) ?: array() ) ) {
	throw new RuntimeException( 'Hard stop did not retain the prepared archive.' );
}
exec( $php . ' ' . $self . ' recover ' . escapeshellarg( $journal ), $ignored, $recoverStatus );
if ( 0 !== $recoverStatus ) {
	throw new RuntimeException( 'Fresh-process recovery failed.' );
}
$store = new FileAttemptStore( $journal );
try {
	$store->begin( new Deployment( 'retry', 'plugin', 'hard-stop-target', 'fixture/two', 'other-id', 'other', 'head', 'install' ) );
	throw new RuntimeException( 'Retry was admitted.' );
} catch ( RuntimeException $expected ) {
	if ( 'Target already has an unresolved execution.' !== $expected->getMessage() ) {
		throw $expected;
	}
}
echo "PASS coordinator SIGKILL, fresh recovery, and retained target block: $root\n";
