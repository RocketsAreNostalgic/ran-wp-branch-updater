<?php

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\Persistence\BranchDeploymentJournalFailure;
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;

$root = __DIR__ . '/build/journal-invariants-' . bin2hex( random_bytes( 4 ) );
if ( ! mkdir( $root, 0700, true ) ) {
	throw new RuntimeException( 'Cannot create journal invariant fixture directory.' );
}
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
	'resolved_ref'        => null,
	'mutation_started_at' => null,
	'outcome'             => null,
	'finished_at'         => null,
);

$write = static function ( array $record ) use ( $path ): void {
	$json = json_encode( array( 'attempt' => $record ), JSON_THROW_ON_ERROR );
	if ( false === file_put_contents( $path, $json, LOCK_EX ) ) {
		throw new RuntimeException( 'Journal invariant fixture could not be written.' );
	}
};

$reject = static function ( array $record ) use ( $write, $store ): void {
	$write( $record );
	try {
		$store->get( 'attempt' );
		throw new RuntimeException( 'Malformed journal state was accepted.' );
	} catch ( BranchDeploymentJournalFailure ) {
	}
};

$reject( array_merge( $base, array(
	'state'       => 'succeeded',
	'outcome'     => 'deployed',
	'finished_at' => '2026-09-08T22:00:00+00:00',
) ) );

$reject( array_merge( $base, array(
	'mutation_started_at' => '2026-09-08T21:59:00+00:00',
) ) );

$reject( array_merge( $base, array(
	'resolved_ref'        => '',
	'mutation_started_at' => '2026-09-08T21:59:00+00:00',
) ) );

$reject( array_merge( $base, array(
	'resolved_ref'        => 'abc123',
	'mutation_started_at' => '',
) ) );

$write( array_merge( $base, array(
	'state'       => 'failed',
	'outcome'     => 'provider_failed',
	'finished_at' => '2026-09-08T22:00:00+00:00',
) ) );
if ( 'failed' !== $store->get( 'attempt' )['state'] ) {
	throw new RuntimeException( 'Valid pre-fence failed state was rejected.' );
}

$write( array_merge( $base, array(
	'state'       => 'needs_attention',
	'outcome'     => 'legacy_attention',
	'finished_at' => '2026-09-08T22:00:00+00:00',
) ) );
if ( 'needs_attention' !== $store->get( 'attempt' )['state'] ) {
	throw new RuntimeException( 'Legacy pre-fence attention state was rejected.' );
}

$write( array_merge( $base, array(
	'state'        => 'needs_attention',
	'resolved_ref' => 'abc123',
	'outcome'      => 'legacy_attention',
	'finished_at'  => '2026-09-08T22:00:00+00:00',
) ) );
if ( 'needs_attention' !== $store->get( 'attempt' )['state'] ) {
	throw new RuntimeException( 'Legacy resolved pre-fence attention state was rejected.' );
}

$write( array_merge( $base, array(
	'state'               => 'needs_attention',
	'resolved_ref'        => 'abc123',
	'mutation_started_at' => '2026-09-08T21:59:00+00:00',
	'outcome'             => 'interrupted',
	'finished_at'         => '2026-09-08T22:00:00+00:00',
) ) );
if ( 'needs_attention' !== $store->get( 'attempt' )['state'] ) {
	throw new RuntimeException( 'Valid fenced attention state was rejected.' );
}

$transitionStore = new FileAttemptStore( $root . '/transition.json' );
$transition      = new BranchDeploymentDeclaration(
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
$transitionStore->begin( $transition );
try {
	$transitionStore->finish( 'transition', 'needs_attention', 'interrupted' );
	throw new RuntimeException( 'Unfenced attention transition was accepted.' );
} catch ( RuntimeException $expected ) {
	if ( 'An unfenced attempt cannot enter this terminal state.' !== $expected->getMessage() ) {
		throw $expected;
	}
}
if ( 'running' !== $transitionStore->get( 'transition' )['state'] ) {
	throw new RuntimeException( 'Rejected terminal transition mutated the journal.' );
}

echo "PASS persisted journal state invariants and legacy compatibility\n";
