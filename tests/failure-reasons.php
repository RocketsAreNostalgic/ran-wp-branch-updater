<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.
declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Archive\ArchiveValidator;
use RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptReasonJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\AdmittedPackageExecutor;
use RAN\WPBranchUpdater\V1\Contract\AdmittedTargetFacts;
use RAN\WPBranchUpdater\V1\Contract\BranchProvider;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\GitHubFixtureProvider;
use RAN\WPBranchUpdater\V1\Persistence\BranchDeploymentJournalFailure;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchDurabilityFailure;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchStageFailure;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedFailureReason;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockReleaseFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockStorageFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchUpdater;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionFailure;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RAN\WPBranchUpdater\V1\Runtime\FailureReason;
use RAN\WPBranchUpdater\V1\Runtime\ProviderArchiveSource;
use RAN\WPBranchUpdater\V1\Runtime\ReasonedFailure;

$assert = static function ( bool $actual, string $message ): void {
	if ( ! $actual ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion diagnostics are not HTML; preserve the actual failing fixture detail.
		throw new RuntimeException( 'FAIL: ' . $message );
	}
};

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
class RAN_WP_Branch_Updater_ReasonJournal implements AdmittedAttemptJournal {
	/** @var list<string> */
	public array $finished = array();
	public function record_resolved_ref( string $ref ): void {}
	public function mark_mutation_started(): void {}
	public function finish( string $code ): void {
		$this->finished[] = $code;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonAwareJournal extends RAN_WP_Branch_Updater_ReasonJournal implements AdmittedAttemptReasonJournal {
	/** @var list<array{string,AdmittedFailureReason}> */
	public array $reasoned = array();
	public function finish_with_reason( string $code, AdmittedFailureReason $reason ): void {
		$this->reasoned[] = array( $code, $reason );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonArtifact implements AdmittedBranchArtifact {
	public int $cleanups = 0;
	public function __construct( private ?Throwable $cleanup_failure = null, private ?Throwable $custody_failure = null ) {}
	public function resolved_ref(): string {
		return 'abc123'; }
	public function expected_version(): string {
		return '1.2.3'; }
	public function assert_unchanged(): void {
		if ( null !== $this->custody_failure ) {
			throw $this->custody_failure;
		}
	}
	public function cleanup(): void {
		++$this->cleanups;
		if ( null !== $this->cleanup_failure ) {
			throw $this->cleanup_failure;
		}
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonProvider implements BranchProvider {
	public function __construct( private RuntimeException $failure ) {}
	public function supports_selected_commit(): bool {
		return false;
	}
	public function prepare( BranchDeploymentDeclaration $d ): ArchiveOffer {
		throw $this->failure;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonArchives implements AdmittedArchiveSource {
	public function __construct( private RAN_WP_Branch_Updater_ReasonArtifact $artifact, private ?Throwable $failure ) {}
	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact {
		if ( null !== $this->failure ) {
			throw $this->failure;
		}
		return $this->artifact;
	}
	public function verify_current_head(): void {}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonTarget implements AdmittedTargetFacts {
	/**
	 * @param array{identifier:string,version:string,active:bool}|null $baseline
	 * @param array<string,mixed> $o
	 */
	public function __construct( private ?array $baseline, private array $o ) {}
	public function assert_mutation_allowed(): void {}
	public function frozen_target( BranchDeploymentDeclaration $deployment, bool $defer_existing ): ?array {
		return $this->baseline;
	}
	public function maintenance_active(): bool {
		return false;
	}
	public function recheck_managed( BranchDeploymentDeclaration $deployment ): void {}
	public function installed( BranchDeploymentDeclaration $deployment ): array {
		return array(
			'identifier' => $this->o['installed_identifier'] ?? 'demo/demo.php',
			'version'    => '1.2.3',
			'active'     => false,
		);
	}
	public function baseline_now( BranchDeploymentDeclaration $deployment, array $baseline ): ?array {
		return array_key_exists( 'baseline_now', $this->o ) ? $this->o['baseline_now'] : $baseline;
	}
	public function adopt( BranchDeploymentDeclaration $deployment ): bool {
		return $this->o['adopt'] ?? true;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonExecutor implements AdmittedPackageExecutor {
	public function __construct( private CorePackageExecutionResult $result, private ?Throwable $preflight ) {}
	public function preflight( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): void {
		if ( null !== $this->preflight ) {
			throw $this->preflight;
		}
	}
	public function execute( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
		return $this->result;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonLock implements MutationLock {
	public function __construct( private ?Throwable $failure ) {}
	public function run( callable $operation ): mixed {
		if ( null !== $this->failure ) {
			throw $this->failure;
		}
		return $operation();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonFixtures {
	public const BASELINE = array(
		'identifier' => 'demo/demo.php',
		'version'    => '1.0.0',
		'active'     => false,
	);

	/**
	 * Run one admitted attempt through the public BranchUpdater path.
	 *
	 * @param array<string,mixed> $o Fixture options.
	 * @return array{string,?AdmittedFailureReason,RAN_WP_Branch_Updater_ReasonJournal,RAN_WP_Branch_Updater_ReasonArtifact,?Throwable}
	 */
	public static function run( array $o = array() ): array {
		$journal  = $o['journal'] ?? new RAN_WP_Branch_Updater_ReasonJournal();
		$artifact = new RAN_WP_Branch_Updater_ReasonArtifact( $o['cleanup_failure'] ?? null, $o['custody_failure'] ?? null );
		$baseline = array_key_exists( 'baseline', $o ) ? $o['baseline'] : ( 'install' === ( $o['operation'] ?? 'update' ) ? null : self::BASELINE );
		$theme    = 'theme' === ( $o['package_type'] ?? 'plugin' );
		$declared = new BranchDeploymentDeclaration( 'reason-attempt', $theme ? 'theme' : 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', isset( $o['selected_commit'] ) ? null : 'abc123', $o['operation'] ?? 'update', $o['subdirectory'] ?? null, $o['declared_identifier'] ?? ( $theme ? 'demo' : 'demo/demo.php' ), $o['selected_commit'] ?? null, $o['expected_identifier'] ?? null );
		$bound    = BranchUpdater::for_admitted_attempt(
			$declared,
			$journal,
			$o['archives'] ?? new RAN_WP_Branch_Updater_ReasonArchives( $artifact, $o['prepare_failure'] ?? null ),
			new RAN_WP_Branch_Updater_ReasonTarget( $baseline, $o ),
			new RAN_WP_Branch_Updater_ReasonExecutor( $o['result'] ?? CorePackageExecutionResult::succeeded(), $o['preflight_failure'] ?? null ),
			new RAN_WP_Branch_Updater_ReasonLock( $o['lock_failure'] ?? null )
		);
		$bound    = $theme ? $bound->theme( 'acme/demo', 'fixture-1', 'main', 'demo', $o['subdirectory'] ?? null ) : $bound->plugin( 'acme/demo', 'fixture-1', 'main', package_slug: 'demo', subdirectory: $o['subdirectory'] ?? null );
		$thrown   = null;
		try {
			$outcome = $bound->deploy();
		} catch ( Throwable $thrown ) {
			$outcome = 'thrown';
		}
		return array( $outcome, $bound->last_reason(), $journal, $artifact, $thrown );
	}
}

$check = static function ( string $label, array $options, string $outcome, FailureReason $reason, ?string $detail = null ) use ( $assert ): void {
	[$actual, $record, $journal] = RAN_WP_Branch_Updater_ReasonFixtures::run( $options );
	$assert( $outcome === $actual, $label . ': outcome code is unchanged (' . $actual . ')' );
	$assert( null !== $record && $reason === $record->reason && $detail === $record->detail && ! $record->cleanup_failed, $label . ': reason ' . $reason->value . ' is recorded' );
	$assert( array( $outcome ) === $journal->finished, $label . ': an old journal still receives only the closed code' );
};

// Old-journal compatibility: ignoring the new capability is exactly today's behaviour.
[$outcome, , $old] = RAN_WP_Branch_Updater_ReasonFixtures::run( array( 'result' => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED ) ) );
$assert( 'upgrader_failed' === $outcome && array( 'upgrader_failed' ) === $old->finished, 'an old journal finishes with the unchanged code' );
$aware              = new RAN_WP_Branch_Updater_ReasonAwareJournal();
[$outcome, $record] = RAN_WP_Branch_Updater_ReasonFixtures::run(
	array(
		'journal' => $aware,
		'result'  => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED, 'incompatible_archive' ),
	)
);
$assert( 'upgrader_failed' === $outcome && array() === $aware->finished && 1 === count( $aware->reasoned ), 'a reason-aware journal gets finish_with_reason instead of finish' );
$assert( 'upgrader_failed' === $aware->reasoned[0][0] && $record === $aware->reasoned[0][1], 'the journal receives the same code and the runner record' );
$aware_success = new RAN_WP_Branch_Updater_ReasonAwareJournal();
RAN_WP_Branch_Updater_ReasonFixtures::run( array( 'journal' => $aware_success ) );
$assert( array( 'deployed' ) === $aware_success->finished && array() === $aware_success->reasoned, 'success and reason-less outcomes use finish() even for a reason-aware journal' );
[$outcome, $record] = RAN_WP_Branch_Updater_ReasonFixtures::run();
$assert( 'deployed' === $outcome && null === $record, 'success carries no reason' );

// Core results: one reason per CorePackageExecutionFailure, detail passes through the screen.
$core_outcomes = array(
	'invalid_request'     => 'restoration_uncertain',
	'runtime_unsupported' => 'restoration_uncertain',
	'wordpress_refused'   => 'upgrader_failed',
	'wordpress_failed'    => 'upgrader_failed',
	'wordpress_restored'  => 'activation_failed',
	'wordpress_uncertain' => 'restoration_uncertain',
	'operation_mismatch'  => 'restoration_uncertain',
);
foreach ( CorePackageExecutionFailure::cases() as $failure ) {
	$check(
		'core ' . $failure->value,
		array(
			'baseline' => RAN_WP_Branch_Updater_ReasonFixtures::BASELINE,
			'result'   => CorePackageExecutionResult::failed( $failure ),
		),
		$core_outcomes[ $failure->value ],
		FailureReason::for_core( $failure )
	);
}
$check(
	'core failed with an upgrader code',
	array(
		'baseline' => RAN_WP_Branch_Updater_ReasonFixtures::BASELINE,
		'result'   => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED, 'copy_failed' ),
	),
	'upgrader_failed',
	FailureReason::CORE_WORDPRESS_FAILED,
	'copy_failed'
);
$check(
	'core detail is screened again by the record',
	array(
		'baseline' => RAN_WP_Branch_Updater_ReasonFixtures::BASELINE,
		'result'   => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED, '/var/www/secret/path' ),
	),
	'upgrader_failed',
	FailureReason::CORE_WORDPRESS_FAILED
);
$check(
	'install failure without a baseline keeps the core reason',
	array(
		'operation' => 'install',
		'result'    => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED ),
	),
	'restoration_uncertain',
	FailureReason::CORE_WORDPRESS_FAILED
);

// Restoration and postcondition checks.
$check(
	'unreadable baseline',
	array(
		'baseline'     => RAN_WP_Branch_Updater_ReasonFixtures::BASELINE,
		'baseline_now' => null,
		'result'       => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED ),
	),
	'restoration_uncertain',
	FailureReason::BASELINE_UNREADABLE
);
$check(
	'changed baseline',
	array(
		'baseline'     => RAN_WP_Branch_Updater_ReasonFixtures::BASELINE,
		'baseline_now' => array_merge( RAN_WP_Branch_Updater_ReasonFixtures::BASELINE, array( 'version' => '9.9.9' ) ),
		'result'       => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED ),
	),
	'restoration_uncertain',
	FailureReason::BASELINE_CHANGED
);
$check(
	'identifier mismatch',
	array(
		'operation'            => 'install',
		'expected_identifier'  => 'demo/demo.php',
		'installed_identifier' => 'demo/other.php',
	),
	'persistence_uncertain',
	FailureReason::IDENTIFIER_MISMATCH
);
$check(
	'adoption refused',
	array(
		'operation' => 'install',
		'adopt'     => false,
	),
	'persistence_uncertain',
	FailureReason::ADOPT_FAILED
);
$check( 'selected commit differs from the resolved ref', array( 'selected_commit' => str_repeat( 'b', 40 ) ), 'provider_failed', FailureReason::REF_MISMATCH );

// Lock and preflight reasons reach the record through the runner; a plain failure stays reason-less.
$check( 'held lock', array( 'lock_failure' => new ReasonedFailure( 'Mutation lock is held.', 'lock_held' ) ), 'lock_unavailable', FailureReason::LOCK_HELD );
$check( 'multisite preflight', array( 'preflight_failure' => new ReasonedFailure( 'private text', 'preflight_multisite' ) ), 'preflight_failed', FailureReason::PREFLIGHT_MULTISITE );
$check( 'unsafe detail on a reasoned failure', array( 'preflight_failure' => new ReasonedFailure( 'private text', 'preflight_multisite', '/var/www/private' ) ), 'preflight_failed', FailureReason::PREFLIGHT_MULTISITE );
[$outcome, $record] = RAN_WP_Branch_Updater_ReasonFixtures::run( array( 'preflight_failure' => new RuntimeException( 'plain failure with /var/www/private' ) ) );
$assert( 'preflight_failed' === $outcome && null === $record, 'a plain RuntimeException keeps its outcome and records no reason or message' );
[$outcome, $record] = RAN_WP_Branch_Updater_ReasonFixtures::run( array( 'lock_failure' => new RuntimeException( 'plain lock failure' ) ) );
$assert( 'lock_unavailable' === $outcome && null === $record, 'a plain lock RuntimeException keeps its outcome and records no reason' );

// Archive validation causes through the real ProviderArchiveSource and ArchiveValidator.
$root = __DIR__ . '/build/failure-reasons-' . bin2hex( random_bytes( 4 ) );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- This standalone fixture owns its isolated temporary directory.
if ( ! mkdir( $root, 0700, true ) ) {
	throw new RuntimeException( 'Cannot create failure reason fixture directory.' );
}
$sequence      = 0;
$make_zip      = static function ( array $entries ) use ( $root, &$sequence ): string {
	$path = sprintf( '%s/fixture-%03d.zip', $root, ++$sequence );
	$zip  = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'Cannot create fixture ZIP.' );
	}
	foreach ( $entries as $name => $contents ) {
		if ( str_ends_with( (string) $name, '/' ) ? ! $zip->addEmptyDir( (string) $name ) : ! $zip->addFromString( (string) $name, (string) $contents ) ) {
			throw new RuntimeException( 'Cannot add fixture ZIP entry.' );
		}
	}
	$zip->close();
	return $path;
};
$plugin_source = static fn( string $headers = "Plugin Name: Demo\nVersion: 1.2.3" ): string => "<?php\n/*\n{$headers}\n*/\n";
$style         = static fn( string $headers = "Theme Name: Demo\nVersion: 1.2.3" ): string => "/*\n{$headers}\n*/\n";
$via_zip       = static function ( string $archive, array $options = array(), int $limit = 10485760, ?BranchProvider $provider = null ) use ( $root ): array {
	$provider ??= new GitHubFixtureProvider( $archive, 'abc123' );
	return RAN_WP_Branch_Updater_ReasonFixtures::run(
		array_merge(
			array(
				'operation'    => 'install',
				'subdirectory' => 'demo',
				'archives'     => new ProviderArchiveSource( $provider, $root . '/custody', $limit ),
			),
			$options
		)
	);
};
$case          = static fn( array $entries, FailureReason $reason, ?string $detail = null, string $kind = 'zip', array $options = array() ): array => array(
	'entries' => $entries,
	'reason'  => $reason,
	'detail'  => $detail,
	'kind'    => $kind,
	'options' => $options,
);
$zip_cases     = array(
	'not a zip'                  => $case( array(), FailureReason::ZIP_INVALID, null, 'raw' ),
	'unsafe path'                => $case( array( 'repository/../demo/demo.php' => $plugin_source() ), FailureReason::PATH_UNSAFE ),
	'case collision'             => $case(
		array(
			'repository/demo/Demo.php' => $plugin_source(),
			'repository/demo/demo.PHP' => $plugin_source(),
		),
		FailureReason::PATH_COLLISION,
	),
	'two roots'                  => $case(
		array(
			'one/demo/demo.php' => $plugin_source(),
			'two/demo/demo.php' => $plugin_source(),
		),
		FailureReason::LAYOUT_INVALID,
	),
	'missing subdirectory'       => $case( array( 'repository/other/demo.php' => $plugin_source() ), FailureReason::SUBDIRECTORY_MISSING ),
	'missing package directory'  => $case( array( 'repository/' => '' ), FailureReason::PACKAGE_DIR_MISSING, null, 'zip', array( 'subdirectory' => null ) ),
	'no plugin header'           => $case( array( 'repository/demo/demo.php' => "<?php\n" ), FailureReason::PLUGIN_MISSING ),
	'two plugins'                => $case(
		array(
			'repository/demo/one.php' => $plugin_source(),
			'repository/demo/two.php' => $plugin_source(),
		),
		FailureReason::MULTIPLE_PLUGINS,
	),
	'unreadable plugin name'     => $case( array( 'repository/demo/demo.php' => $plugin_source( 'Plugin Name: ' . str_repeat( 'a', 70 ) ) ), FailureReason::HEADER_UNREADABLE, 'Plugin Name' ),
	'missing version'            => $case( array( 'repository/demo/demo.php' => $plugin_source( 'Plugin Name: Demo' ) ), FailureReason::VERSION_MISSING, 'Version' ),
	'invalid version'            => $case( array( 'repository/demo/demo.php' => $plugin_source( "Plugin Name: Demo\nVersion: not a version" ) ), FailureReason::VERSION_INVALID, 'Version' ),
	'oversized version'          => $case( array( 'repository/demo/demo.php' => $plugin_source( "Plugin Name: Demo\nVersion: " . str_repeat( '1', 70 ) ) ), FailureReason::VERSION_INVALID, 'Version' ),
	'invalid requires php'       => $case( array( 'repository/demo/demo.php' => $plugin_source( "Plugin Name: Demo\nVersion: 1.2.3\nRequires PHP: abc" ) ), FailureReason::COMPAT_INVALID, 'Requires PHP' ),
	'invalid requires wordpress' => $case( array( 'repository/demo/demo.php' => $plugin_source( "Plugin Name: Demo\nVersion: 1.2.3\nRequires at least: abc" ) ), FailureReason::COMPAT_INVALID, 'Requires at least' ),
	'requires newer php'         => $case( array( 'repository/demo/demo.php' => $plugin_source( "Plugin Name: Demo\nVersion: 1.2.3\nRequires PHP: 99.0" ) ), FailureReason::REQUIRES_NEWER_PHP, 'Requires PHP' ),
	'requires newer wordpress'   => $case( array( 'repository/demo/demo.php' => $plugin_source( "Plugin Name: Demo\nVersion: 1.2.3\nRequires at least: 99.0" ) ), FailureReason::REQUIRES_NEWER_WP, 'Requires at least' ),
	'theme without style'        => $case( array( 'repository/demo/index.php' => '<?php' ), FailureReason::THEME_MISSING, null, 'zip', array( 'package_type' => 'theme' ) ),
	'theme without a name'       => $case( array( 'repository/demo/style.css' => $style( 'Version: 1.2.3' ) ), FailureReason::HEADER_MISSING, 'Theme Name', 'zip', array( 'package_type' => 'theme' ) ),
	'update identity mismatch'   => $case(
		array( 'repository/demo/demo.php' => $plugin_source() ),
		FailureReason::IDENTITY_MISMATCH,
		null,
		'zip',
		array(
			'operation'           => 'update',
			'declared_identifier' => 'other/other.php',
		),
	),
);
foreach ( $zip_cases as $label => $row ) {
	$archive = 'raw' === $row['kind'] ? $root . '/not-a-zip.zip' : $make_zip( $row['entries'] );
	if ( 'raw' === $row['kind'] ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write a deliberately invalid fixture archive in this isolated temporary directory.
		file_put_contents( $archive, 'this is not a zip archive' );
	}
	[$outcome, $record, $journal] = $via_zip( $archive, $row['options'] );
	$assert( 'archive_integrity_failed' === $outcome && array( 'archive_integrity_failed' ) === $journal->finished, $label . ': the outcome code stays archive_integrity_failed' );
	$assert( null !== $record && $row['reason'] === $record->reason && $row['detail'] === $record->detail, $label . ': reason ' . $row['reason']->value . ' and its closed detail are recorded' );
}
$zip = new ZipArchive();
$zip->open( $root . '/many.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
for ( $entry = 0; $entry <= 10000; ++$entry ) {
	$zip->addFromString( 'repository/demo/f' . $entry . '.txt', '' );
}
$zip->close();
[, $record] = $via_zip( $root . '/many.zip' );
$assert( null !== $record && FailureReason::ENTRY_LIMIT === $record->reason, 'entry limit: reason entry_limit is recorded' );
[, $record] = $via_zip( $make_zip( array( 'repository/demo/demo.php' => $plugin_source() . str_repeat( ' ', 200000 ) ) ), array(), 3000 );
$assert( null !== $record && FailureReason::EXPANDED_TOO_LARGE === $record->reason, 'expanded limit: reason expanded_too_large is recorded' );
[, $record] = $via_zip( $make_zip( array( 'repository/demo/demo.php' => $plugin_source() ) ), array(), 10 );
$assert( null !== $record && FailureReason::ARCHIVE_PREPARE_FAILED === $record->reason && null === $record->detail, 'a non-validator archive failure is archive_prepare_failed without its message' );

// Every numeric validator cause maps into the closed vocabulary and keeps its code and fixed message.
$validator_failure = new ReflectionMethod( ArchiveValidator::class, 'fail' );
foreach ( ( new ReflectionClass( ArchiveValidator::class ) )->getConstants() as $name => $code ) {
	if ( ! str_starts_with( $name, 'CODE_' ) ) {
		continue;
	}
	try {
		$validator_failure->invoke( new ArchiveValidator(), $code, 'The fixed message.' );
		$assert( false, 'fail() returned for ' . $name );
	} catch ( ReasonedFailure $failure ) {
		$assert( $code === $failure->getCode() && 'The fixed message.' === $failure->getMessage() && null !== $failure->reason, $name . ' keeps its code, message and a closed reason' );
	}
}

// Provider causes.
foreach ( array(
	'selected commit unsupported' => array( new GitHubFixtureProvider( $make_zip( array( 'repository/demo/demo.php' => $plugin_source() ) ), 'abc123', null, false ), array( 'selected_commit' => str_repeat( 'a', 40 ) ), FailureReason::SELECTED_COMMIT_UNSUPPORTED ),
	'repository mismatch'         => array( new GitHubFixtureProvider( $make_zip( array( 'repository/demo/demo.php' => $plugin_source() ) ), 'abc123', 'another-repository' ), array(), FailureReason::REPOSITORY_MISMATCH ),
) as $label => [$provider, $options, $expected_reason] ) {
	[$outcome, $record] = $via_zip( '', $options, 10485760, $provider );
	$assert( 'provider_failed' === $outcome && null !== $record && $expected_reason === $record->reason, $label . ': provider_failed records ' . $expected_reason->value );
}
$provider             = new GitHubFixtureProvider( $make_zip( array( 'repository/demo/demo.php' => $plugin_source() ) ), 'abc123' );
$provider->forced_ref = 'def456';
[$outcome, $record]   = $via_zip( '', array(), 10485760, $provider );
$assert( 'provider_failed' === $outcome && null !== $record && FailureReason::REF_MISMATCH === $record->reason, 'a provider ref that differs from the expected head is ref_mismatch' );
foreach ( array(
	404 => 'http_404',
	503 => 'http_503',
	0   => null,
	999 => null,
) as $http_status => $expected_detail ) {
	$failing            = new RAN_WP_Branch_Updater_ReasonProvider( new RuntimeException( 'https://user:token@example.test/leak /var/www/secret ' . hash( 'sha256', 'x' ), $http_status ) );
	[$outcome, $record] = $via_zip( '', array(), 10485760, $failing );
	$assert( 'provider_failed' === $outcome && null !== $record && FailureReason::PROVIDER_ERROR === $record->reason && $expected_detail === $record->detail, 'provider status ' . $http_status . ' passes only a bounded status code' );
}

// Ambiguous durability failures still escape, and their reason is available from last_reason().
$ambiguous = array(
	'lock storage' => array( new BranchDeploymentLockStorageFailure( 'private storage text' ), FailureReason::LOCK_STORAGE ),
	'lock release' => array( new BranchDeploymentLockReleaseFailure( 'private release text' ), FailureReason::LOCK_RELEASE ),
	'journal'      => array( new BranchDeploymentJournalFailure( 'private journal text' ), FailureReason::JOURNAL_UNCERTAIN ),
	'durability'   => array( new AdmittedBranchDurabilityFailure( 'private durability text' ), FailureReason::DURABILITY_UNCERTAIN ),
);
foreach ( $ambiguous as $label => [$thrown_failure, $expected_reason] ) {
	[$outcome, $record, $journal, , $thrown] = RAN_WP_Branch_Updater_ReasonFixtures::run( array( 'lock_failure' => $thrown_failure ) );
	$assert( 'thrown' === $outcome && $thrown === $thrown_failure && array() === $journal->finished, $label . ': the ambiguous failure still escapes and nothing is finished' );
	$assert( null !== $record && $expected_reason === $record->reason && null === $record->detail, $label . ': last_reason names the ambiguous cause without its message' );
}

// Remove only this fixture's temporary tree.
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $entry ) {
	if ( $entry->isDir() ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only this fixture's empty directories.
		rmdir( $entry->getPathname() );
	} else {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete only this fixture's temporary files.
		unlink( $entry->getPathname() );
	}
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only this fixture's empty root.
rmdir( $root );

echo "PASS admitted failure reasons\n";
