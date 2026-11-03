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

echo "PASS persisted journal state invariants and custom attempt ID admission\n";
