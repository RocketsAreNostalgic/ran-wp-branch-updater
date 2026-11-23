<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\Persistence\BranchDeploymentJournalFailure;
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore;
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptJournal;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;

$root = __DIR__ . '/build/journal-invariants-' . bin2hex( random_bytes( 4 ) );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
if ( ! mkdir( $root, 0700, true ) ) {
	throw new RuntimeException( 'Cannot create journal invariant fixture directory.' );
}
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone CLI fixture variable does not share WordPress runtime globals.
$path  = $root . '/attempts.json';
$store = new FileAttemptStore( $path );

$base = array(
	'id'                  => 'attempt',
	'state'               => 'running',
	'package_type'        => 'plugin',
	'slug'                => 'demo',
	'repository'          => 'acme/demo',
	'branch'              => 'main',
	'expected_head'       => 'abc123',
	'selected_commit'     => null,
	'expected_identifier' => null,
	'resolved_ref'        => null,
	'mutation_started_at' => null,
	'outcome'             => null,
	'finished_at'         => null,
);

$write = static function ( array $record ) use ( $path ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone CLI fixture preserves explicit JSON flags and throws before writing its test manifest or journal.
	$json = json_encode( array( 'attempt' => $record ), JSON_THROW_ON_ERROR );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CLI proof creates or cleans only its isolated local fixture files with native filesystem semantics.
	if ( false === file_put_contents( $path, $json, LOCK_EX ) ) {
		throw new RuntimeException( 'Journal invariant fixture could not be written.' );
	}
};

$reject = static function ( array $record ) use ( $write, $store ): void {
	$write( $record );
	try {
		$store->get( 'attempt' );
		throw new RuntimeException( 'Malformed journal state was accepted.' );
	// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Reaching this catch proves the expected rejection; no mutation is needed.
	} catch ( BranchDeploymentJournalFailure ) { // Expected rejection is the assertion; execution continues only for this exception.
	}
};

foreach ( array( '', 'demo/other/main.php', 'other/main.php', ' demo/main.php', 123 ) as $identifier ) {
	$reject( array_merge( $base, array( 'expected_identifier' => $identifier ) ) );
}

foreach ( array( 'abc', str_repeat( 'A', 40 ), 123 ) as $selected ) {
	$reject(
		array_merge(
			$base,
			array(
				'expected_head'   => null,
				'selected_commit' => $selected,
			)
		)
	);
}
$reject( array_merge( $base, array( 'selected_commit' => str_repeat( 'a', 40 ) ) ) );
$reject(
	array_merge(
		$base,
		array(
			'expected_head'   => null,
			'selected_commit' => str_repeat( 'a', 40 ),
			'resolved_ref'    => str_repeat( 'b', 40 ),
		)
	)
);

$reject(
	array_merge(
		$base,
		array(
			'state'       => 'succeeded',
			'outcome'     => 'deployed',
			'finished_at' => '2026-09-08T22:00:00+00:00',
		)
	)
);

$reject(
	array_merge(
		$base,
		array(
			'mutation_started_at' => '2026-09-08T21:59:00+00:00',
		)
	)
);

$reject(
	array_merge(
		$base,
		array(
			'resolved_ref'        => '',
			'mutation_started_at' => '2026-09-08T21:59:00+00:00',
		)
	)
);

$reject(
	array_merge(
		$base,
		array(
			'resolved_ref'        => 'abc123',
			'mutation_started_at' => '',
		)
	)
);

$write(
	array_merge(
		$base,
		array(
			'state'       => 'failed',
			'outcome'     => 'provider_failed',
			'finished_at' => '2026-09-08T22:00:00+00:00',
		)
	)
);
if ( 'failed' !== $store->get( 'attempt' )['state'] ) {
	throw new RuntimeException( 'Valid pre-fence failed state was rejected.' );
}

$reject(
	array_merge(
		$base,
		array(
			'state'       => 'needs_attention',
			'outcome'     => 'legacy_attention',
			'finished_at' => '2026-09-08T22:00:00+00:00',
		)
	)
);

$reject(
	array_merge(
		$base,
		array(
			'state'        => 'needs_attention',
			'resolved_ref' => 'abc123',
			'outcome'      => 'legacy_attention',
			'finished_at'  => '2026-09-08T22:00:00+00:00',
		)
	)
);

$write(
	array_merge(
		$base,
		array(
			'state'               => 'needs_attention',
			'resolved_ref'        => 'abc123',
			'mutation_started_at' => '2026-09-08T21:59:00+00:00',
			'outcome'             => 'interrupted',
			'finished_at'         => '2026-09-08T22:00:00+00:00',
		)
	)
);
if ( 'needs_attention' !== $store->get( 'attempt' )['state'] ) {
	throw new RuntimeException( 'Valid fenced attention state was rejected.' );
}

$transition_store = new FileAttemptStore( $root . '/transition.json' );
$transition       = new BranchDeploymentDeclaration(
	'transition',
	'plugin',
	'demo',
	'acme/demo',
	'repository-id',
	'main',
	'abc123',
	'update',
	'demo',
	'demo/demo.php'
);
$transition_store->begin( $transition );
try {
	$transition_store->finish( 'transition', 'needs_attention', 'interrupted' );
	throw new RuntimeException( 'Unfenced attention transition was accepted.' );
} catch ( RuntimeException $expected ) {
	if ( 'An unfenced attempt cannot enter this terminal state.' !== $expected->getMessage() ) {
		throw $expected;
	}
}
if ( 'running' !== $transition_store->get( 'transition' )['state'] ) {
	throw new RuntimeException( 'Rejected terminal transition mutated the journal.' );
}

$journal = new FileAttemptJournal( store: $transition_store, attempt_id: 'transition' );
$journal->record_resolved_ref( ref: 'abc123' );
$journal->mark_mutation_started();
$journal->finish( code: 'deployed' );
$record = $transition_store->get( 'transition' );
if ( 'abc123' !== $record['resolved_ref'] || null === $record['mutation_started_at']
	|| 'succeeded' !== $record['state'] || 'deployed' !== $record['outcome'] ) {
	throw new RuntimeException( 'Named-argument journal transitions did not preserve persisted state.' );
}

$id_path  = $root . '/custom-ids.json';
$id_store = new FileAttemptStore( $id_path );
$id_store->begin( new BranchDeploymentDeclaration( 'safe-id', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', null ) );
$id_store->finish( 'safe-id', 'failed', 'provider_failed' );
$safe_record = $id_store->get( 'safe-id' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Compare exact synthetic journal bytes before and after rejected input.
$before = file_get_contents( $id_path );
if ( ! is_string( $before ) ) {
	throw new RuntimeException( 'Cannot read the custom ID fixture journal.' );
}
$reject_id = static function ( FileAttemptStore $target_store, BranchDeploymentDeclaration $declaration ): void {
	try {
		$target_store->begin( $declaration );
		throw new RuntimeException( 'Integer-coercible attempt ID was accepted.' );
	} catch ( RuntimeException $expected ) {
		if ( 'Attempt ID cannot be stored as a string journal key.' !== $expected->getMessage() ) {
			throw $expected;
		}
	}
};
foreach ( array( '0', '1', '123', '-1', (string) PHP_INT_MAX, (string) PHP_INT_MIN ) as $attempt_id ) {
	// The general declaration still accepts IDs that custom journals may support.
	$declaration = new BranchDeploymentDeclaration( $attempt_id, 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', null );
	if ( $attempt_id !== $declaration->attempt_id ) {
		throw new RuntimeException( 'General declaration changed a custom attempt ID.' );
	}
	$reject_id( $id_store, $declaration );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Refusal must leave the complete existing synthetic journal byte-identical.
	if ( file_get_contents( $id_path ) !== $before || ( new FileAttemptStore( $id_path ) )->get( 'safe-id' ) !== $safe_record ) {
		throw new RuntimeException( 'Rejected attempt ID changed prior bytes or made a valid record unreadable.' );
	}
	$fresh_path = $root . '/refused-' . $attempt_id . '/attempts.json';
	$reject_id( new FileAttemptStore( $fresh_path ), $declaration );
	if ( file_exists( dirname( $fresh_path ) ) || file_exists( $fresh_path ) || file_exists( $fresh_path . '.lock' ) || file_exists( $fresh_path . '.new' ) ) {
		throw new RuntimeException( 'Rejected attempt ID created journal state on a fresh path.' );
	}
}
foreach ( array( '01', '00', '-01', '-0', '+1', '1.0', '1e3', (string) PHP_INT_MAX . '0', (string) PHP_INT_MIN . '0', str_repeat( '1', 32 ), bin2hex( random_bytes( 16 ) ), 'safe-custom' ) as $attempt_id ) {
	$declaration = new BranchDeploymentDeclaration( $attempt_id, 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', null );
	if ( $id_store->begin( $declaration )['id'] !== $attempt_id || ( new FileAttemptStore( $id_path ) )->get( $attempt_id )['id'] !== $attempt_id ) {
		throw new RuntimeException( 'Supported custom attempt ID did not round-trip.' );
	}
	$id_store->resolved( $attempt_id, 'abc123' );
	$id_store->fence( $attempt_id );
	$id_store->finish( $attempt_id, 'succeeded', 'deployed' );
	if ( 'succeeded' !== ( new FileAttemptStore( $id_path ) )->get( $attempt_id )['state'] || $id_store->get( 'safe-id' ) !== $safe_record ) {
		throw new RuntimeException( 'Supported custom attempt ID did not preserve transitions or existing records.' );
	}
}

// One unresolved run per target follows the shared collision rule: a case-only spelling is the same folder.
$collision_store = new FileAttemptStore( $root . '/collision.json' );
$collision_store->begin( new BranchDeploymentDeclaration( 'first', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', null ) );
foreach ( array( 'Demo', 'demo' ) as $target_slug ) {
	try {
		$collision_store->begin( new BranchDeploymentDeclaration( 'second-' . $target_slug, 'plugin', $target_slug, 'acme/demo', 'repository-id', 'main', null ) );
		throw new RuntimeException( 'Case-only target collision was admitted.' );
	} catch ( RuntimeException $expected ) {
		if ( 'Target already has an unresolved execution.' !== $expected->getMessage() ) {
			throw $expected;
		}
	}
}
$collision_store->begin( new BranchDeploymentDeclaration( 'other-type', 'theme', 'demo', 'acme/demo', 'repository-id', 'main', null ) );

// Journal and lock files must enforce 0600 permissions and reject symlinked or hard-linked paths.
clearstatcache( true, $root . '/collision.json' );
clearstatcache( true, $root . '/collision.json.lock' );
$journal_perms = fileperms( $root . '/collision.json' );
$lock_perms    = fileperms( $root . '/collision.json.lock' );
if ( false === $journal_perms || ( $journal_perms & 0777 ) !== 0600 || false === $lock_perms || ( $lock_perms & 0777 ) !== 0600 ) {
	throw new RuntimeException( 'Journal and lock files must enforce 0600 permissions.' );
}

$symlink_target = $root . '/external-valid-journal.json';
if ( ! copy( $root . '/collision.json', $symlink_target ) ) {
	throw new RuntimeException( 'Could not create valid journal fixture for symlink checks.' );
}

$symlink_journal = $root . '/symlink-journal.json';
symlink( $symlink_target, $symlink_journal );
$symlink_journal_error = null;
try {
	( new FileAttemptStore( $symlink_journal ) )->begin( new BranchDeploymentDeclaration( 'sym-target', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', null ) );
} catch ( BranchDeploymentJournalFailure $expected ) {
	$symlink_journal_error = $expected;
}
if ( ! $symlink_journal_error instanceof BranchDeploymentJournalFailure || 'Journal path is unsafe.' !== $symlink_journal_error->getMessage() ) {
	throw new RuntimeException( 'Symlinked journal path must fail with exact unsafe-path refusal.' );
}

$symlink_lock_base = $root . '/symlink-lock.json';
symlink( $symlink_target, $symlink_lock_base . '.lock' );
$symlink_lock_error = null;
try {
	( new FileAttemptStore( $symlink_lock_base ) )->begin( new BranchDeploymentDeclaration( 'sym-lock', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', null ) );
} catch ( BranchDeploymentJournalFailure $expected ) {
	$symlink_lock_error = $expected;
}
if ( ! $symlink_lock_error instanceof BranchDeploymentJournalFailure || 'Journal lock path is unsafe.' !== $symlink_lock_error->getMessage() ) {
	throw new RuntimeException( 'Symlinked lock path must fail with exact unsafe-lock-path refusal.' );
}

$hardlink_other = $root . '/unrelated-file.txt';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Create fixture file to prove hard-linked lock rejection preserves external file mode.
file_put_contents( $hardlink_other, 'preserve-mode' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set distinct non-0600 mode to prove hard-linked lock rejection does not chmod the shared inode.
chmod( $hardlink_other, 0644 );
$hardlink_lock_base = $root . '/hardlink-lock.json';
link( $hardlink_other, $hardlink_lock_base . '.lock' );
$hardlink_lock_error = null;
try {
	( new FileAttemptStore( $hardlink_lock_base ) )->begin( new BranchDeploymentDeclaration( 'hardlink-lock', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', null ) );
} catch ( BranchDeploymentJournalFailure $expected ) {
	$hardlink_lock_error = $expected;
}
clearstatcache( true, $hardlink_other );
$preserved_perms = fileperms( $hardlink_other );
if ( ! $hardlink_lock_error instanceof BranchDeploymentJournalFailure
	|| 'Journal lock path is unsafe.' !== $hardlink_lock_error->getMessage()
	|| false === $preserved_perms
	|| ( $preserved_perms & 0777 ) !== 0644
) {
	throw new RuntimeException( 'Hard-linked lock path must be rejected without modifying the linked file permissions.' );
}

// Entropy failure during temporary journal allocation must classify as BranchDeploymentJournalFailure (journal_uncertain).
$autoload_path = dirname( __DIR__ ) . '/vendor/autoload.php';
$entropy_path  = $root . '/entropy-journal.json';
$descriptors   = array(
	0 => array( 'pipe', 'r' ),
	1 => array( 'pipe', 'w' ),
	2 => array( 'pipe', 'w' ),
);
$child_code    = <<<'PHP'
namespace RAN\WPBranchUpdater\V1\Persistence {
	function random_bytes( int $length ): string {
		throw new \Random\RandomException( 'Entropy unavailable.' );
	}
}
namespace {
	require $argv[1];
	$store = new \RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore( $argv[2] );
	$decl  = new \RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration( 'entropy-journal', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', null );
	$error = null;
	try {
		$store->begin( $decl );
	} catch ( \RAN\WPBranchUpdater\V1\Persistence\BranchDeploymentJournalFailure $e ) {
		$error = $e;
	}
	if ( ! $error instanceof \RAN\WPBranchUpdater\V1\Persistence\BranchDeploymentJournalFailure
		|| ! $error->getPrevious() instanceof \Random\RandomException
	) {
		fwrite( STDERR, 'Unexpected journal entropy failure classification' );
		exit( 1 );
	}
	echo 'OK';
}
PHP;
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Run an isolated PHP process to test namespace-scoped entropy failure classification.
$process = proc_open( array( PHP_BINARY, '-r', $child_code, '--', $autoload_path, $entropy_path ), $descriptors, $pipes );
if ( ! is_resource( $process ) ) {
	throw new RuntimeException( 'Could not start isolated journal entropy proof.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close unused child stdin pipe.
fclose( $pipes[0] );
$stdout = trim( (string) stream_get_contents( $pipes[1] ) );
$stderr = trim( (string) stream_get_contents( $pipes[2] ) );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close child stdout pipe.
fclose( $pipes[1] );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close child stderr pipe.
fclose( $pipes[2] );
if ( 0 !== proc_close( $process ) || 'OK' !== $stdout ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI test diagnostic is not rendered output.
	throw new RuntimeException( 'Isolated journal entropy proof failed: ' . $stderr );
}

// Long previously supported basenames (e.g. 240 bytes) must continue to update without hitting NAME_MAX.
$long_journal_path = $root . '/' . str_repeat( 'j', 235 ) . '.json';
$long_store        = new FileAttemptStore( $long_journal_path );
$long_store->begin( new BranchDeploymentDeclaration( 'long-name', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', null ) );
if ( 'long-name' !== $long_store->get( 'long-name' )['id'] ) {
	throw new RuntimeException( 'Long journal basename did not round-trip.' );
}

// Read-only lookup of a legacy 0644 journal file must tighten its permissions to 0600 under lock.
$legacy_journal_path = $root . '/legacy-mode.json';
if ( ! copy( $root . '/collision.json', $legacy_journal_path ) ) {
	throw new RuntimeException( 'Could not create legacy-mode journal fixture.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Simulate a legacy 0644 journal created under a permissive umask.
chmod( $legacy_journal_path, 0644 );
$legacy_record = ( new FileAttemptStore( $legacy_journal_path ) )->get( 'first' );
clearstatcache( true, $legacy_journal_path );
$legacy_perms = fileperms( $legacy_journal_path );
if ( 'first' !== $legacy_record['id'] || false === $legacy_perms || ( $legacy_perms & 0777 ) !== 0600 ) {
	throw new RuntimeException( 'Read-only lookup must tighten legacy journal permissions to 0600.' );
}

echo "PASS persisted journal state invariants and custom attempt ID admission\n";
