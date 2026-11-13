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
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore;
use RAN\WPBranchUpdater\V1\Persistence\FileMutationLock;
use RAN\WPBranchUpdater\V1\RecordingExecutor;
use RAN\WPBranchUpdater\V1\Runtime\StandaloneBranchRunner;
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
class RAN_WP_Branch_Updater_ReasonFailingJournal extends RAN_WP_Branch_Updater_ReasonJournal {
	public function finish( string $code ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only fixed fault text; the failure is never rendered.
		throw new BranchDeploymentJournalFailure( 'private terminal journal text ' . strlen( $code ) );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonPlainFailingJournal extends RAN_WP_Branch_Updater_ReasonJournal {
	public function finish( string $code ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only fixed fault text; the failure is never rendered.
		throw new RuntimeException( 'plain terminal journal text ' . strlen( $code ) );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- These test-only fault-injection doubles share one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonAwareFailingJournal extends RAN_WP_Branch_Updater_ReasonFailingJournal implements AdmittedAttemptReasonJournal {
	public function finish_with_reason( string $code, AdmittedFailureReason $reason ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only fixed fault text; the failure is never rendered.
		throw new AdmittedBranchDurabilityFailure( 'private terminal durability text ' . strlen( $code ) . strlen( $reason->reason->value ) );
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
	public function __construct( private CorePackageExecutionResult $result, private ?Throwable $preflight, private ?Throwable $execution = null ) {}
	public function preflight( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): void {
		if ( null !== $this->preflight ) {
			throw $this->preflight;
		}
	}
	public function execute( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
		if ( null !== $this->execution ) {
			throw $this->execution;
		}
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
			new RAN_WP_Branch_Updater_ReasonExecutor( $o['result'] ?? CorePackageExecutionResult::succeeded(), $o['preflight_failure'] ?? null, $o['execute_failure'] ?? null ),
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

// Every numeric validator cause maps to its expected reason, and the table covers every CODE_* constant.
$expected_reasons  = array(
	'CODE_ZIP_UNAVAILABLE'           => FailureReason::ZIP_UNAVAILABLE,
	'CODE_ZIP_INVALID'               => FailureReason::ZIP_INVALID,
	'CODE_ENTRY_LIMIT'               => FailureReason::ENTRY_LIMIT,
	'CODE_ENTRY_INVALID'             => FailureReason::ENTRY_INVALID,
	'CODE_PATH_UNSAFE'               => FailureReason::PATH_UNSAFE,
	'CODE_PATH_COLLISION'            => FailureReason::PATH_COLLISION,
	'CODE_LAYOUT_INVALID'            => FailureReason::LAYOUT_INVALID,
	'CODE_ENTRY_UNSUPPORTED'         => FailureReason::ENTRY_UNSUPPORTED,
	'CODE_ENTRY_ENCRYPTED'           => FailureReason::ENTRY_ENCRYPTED,
	'CODE_COMPRESSED_TOO_LARGE'      => FailureReason::COMPRESSED_TOO_LARGE,
	'CODE_ARCHIVE_INTEGRITY'         => FailureReason::ARCHIVE_CORRUPT,
	'CODE_SUBDIRECTORY_MISSING'      => FailureReason::SUBDIRECTORY_MISSING,
	'CODE_PACKAGE_IDENTITY'          => FailureReason::IDENTITY_MISMATCH,
	'CODE_HEADER_UNREADABLE'         => FailureReason::HEADER_UNREADABLE,
	'CODE_VERSION_INVALID'           => FailureReason::VERSION_INVALID,
	'CODE_COMPATIBILITY_INVALID'     => FailureReason::COMPAT_INVALID,
	'CODE_REQUIRES_NEWER_PHP'        => FailureReason::REQUIRES_NEWER_PHP,
	'CODE_REQUIRES_NEWER_WP'         => FailureReason::REQUIRES_NEWER_WP,
	'CODE_EXPANDED_TOO_LARGE'        => FailureReason::EXPANDED_TOO_LARGE,
	'CODE_PLUGIN_MISSING'            => FailureReason::PLUGIN_MISSING,
	'CODE_THEME_MISSING'             => FailureReason::THEME_MISSING,
	'CODE_MULTIPLE_PLUGINS'          => FailureReason::MULTIPLE_PLUGINS,
	'CODE_HEADER_MISSING'            => FailureReason::HEADER_MISSING,
	'CODE_VERSION_MISSING'           => FailureReason::VERSION_MISSING,
	'CODE_MULTIPLE_ROOTS'            => FailureReason::LAYOUT_INVALID,
	'CODE_PACKAGE_DIRECTORY_MISSING' => FailureReason::PACKAGE_DIR_MISSING,
	'CODE_THEME_IDENTITY_MISMATCH'   => FailureReason::IDENTITY_MISMATCH,
	'CODE_FILE_PARENT_COLLISION'     => FailureReason::PATH_COLLISION,
);
$validator_failure = new ReflectionMethod( ArchiveValidator::class, 'fail' );
$validator_codes   = array_filter( ( new ReflectionClass( ArchiveValidator::class ) )->getConstants(), static fn( string $name ): bool => str_starts_with( $name, 'CODE_' ), ARRAY_FILTER_USE_KEY );
$assert( array_keys( $validator_codes ) === array_keys( $expected_reasons ), 'the expected-reason table covers exactly every validator CODE_ constant' );
foreach ( $validator_codes as $name => $code ) {
	try {
		$validator_failure->invoke( new ArchiveValidator(), $code, 'The fixed message.' );
		$assert( false, 'fail() returned for ' . $name );
	} catch ( ReasonedFailure $failure ) {
		$assert( $code === $failure->getCode() && 'The fixed message.' === $failure->getMessage(), $name . ' keeps its code and message' );
		$assert( $expected_reasons[ $name ]->value === $failure->reason, $name . ' maps to ' . $expected_reasons[ $name ]->value );
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

// Class C: a failing cleanup beside another cause records both and never replaces the primary cause.
$cleanup = new RuntimeException( 'cleanup failed at /var/www/private ' . hash( 'sha256', 'x' ) );
$both    = static function ( string $label, array $options, string $outcome, FailureReason $reason, ?string $detail, string $primary ) use ( $assert, $cleanup ): void {
	$aware                      = new RAN_WP_Branch_Updater_ReasonAwareJournal();
	[$actual, $record, $legacy] = RAN_WP_Branch_Updater_ReasonFixtures::run( array_merge( array( 'cleanup_failure' => $cleanup ), $options ) );
	$assert( $outcome === $actual && array( $outcome ) === $legacy->finished, $label . ': the outcome code is unchanged (' . $actual . ') for a journal that ignores reasons' );
	$assert( null !== $record && $reason === $record->reason && $detail === $record->detail && $primary === $record->primary_outcome && $record->cleanup_failed, $label . ': the primary reason ' . $reason->value . ', superseded outcome ' . $primary . ' and the cleanup failure are all recorded' );
	RAN_WP_Branch_Updater_ReasonFixtures::run(
		array_merge(
			array(
				'cleanup_failure' => $cleanup,
				'journal'         => $aware,
			),
			$options
		)
	);
	$assert( 1 === count( $aware->reasoned ) && $outcome === $aware->reasoned[0][0] && $primary === $aware->reasoned[0][1]->primary_outcome, $label . ': a reason-aware journal receives the same record' );
};
$both( 'cleanup after a reasoned stage failure', array( 'preflight_failure' => new ReasonedFailure( 'private text', 'preflight_multisite' ) ), 'archive_cleanup_failed', FailureReason::PREFLIGHT_MULTISITE, null, 'preflight_failed' );
$both( 'cleanup after a plain stage failure', array( 'preflight_failure' => new RuntimeException( 'plain' ) ), 'archive_cleanup_failed', FailureReason::CLEANUP_ALSO_FAILED, null, 'preflight_failed' );
$both( 'cleanup after a held lock', array( 'lock_failure' => new ReasonedFailure( 'held', 'lock_held' ) ), 'archive_cleanup_failed', FailureReason::LOCK_HELD, null, 'lock_unavailable' );
$both( 'cleanup after a core failure', array( 'result' => CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED, 'copy_failed' ) ), 'interrupted', FailureReason::CORE_WORDPRESS_FAILED, 'copy_failed', 'upgrader_failed' );
$both( 'cleanup after a successful deployment', array(), 'interrupted', FailureReason::CLEANUP_ALSO_FAILED, null, 'deployed' );
$both( 'cleanup after a pre-fence policy failure', array( 'selected_commit' => str_repeat( 'b', 40 ) ), 'archive_cleanup_failed', FailureReason::REF_MISMATCH, null, 'provider_failed' );
[$actual, $record, $journal, , $thrown] = RAN_WP_Branch_Updater_ReasonFixtures::run(
	array(
		'cleanup_failure' => $cleanup,
		'lock_failure'    => new BranchDeploymentLockReleaseFailure( 'release' ),
	)
);
$assert( 'thrown' === $actual && array() === $journal->finished && $thrown instanceof BranchDeploymentLockReleaseFailure, 'an ambiguous failure still escapes when cleanup also fails' );
$assert( null !== $record && FailureReason::LOCK_RELEASE === $record->reason && $record->cleanup_failed, 'the ambiguous cause and the cleanup failure are both recorded' );
$dump = null === $record ? '' : $record->reason->value . '|' . $record->detail . '|' . $record->primary_outcome;
$assert( ! str_contains( $dump, 'private' ) && ! str_contains( $dump, 'var/www' ), 'the cleanup failure message never enters the record' );

// The replaced outcome of a fenced failure is interrupted, which is what the runner would have returned.
[$actual, $record] = RAN_WP_Branch_Updater_ReasonFixtures::run(
	array(
		'cleanup_failure' => $cleanup,
		'execute_failure' => new RuntimeException( 'private execution text' ),
	)
);
$assert( 'interrupted' === $actual && null !== $record && 'interrupted' === $record->primary_outcome && FailureReason::CLEANUP_ALSO_FAILED === $record->reason && $record->cleanup_failed, 'a fenced failure with a failing cleanup records interrupted as the replaced outcome' );

// A prepared archive whose custody breaks fails both the check and its cleanup: the custody cause is kept.
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This test-only fault-injection double shares one standalone behavioral proof.
final class RAN_WP_Branch_Updater_ReasonTamperingArchives implements AdmittedArchiveSource {
	public function __construct( private ProviderArchiveSource $source ) {}
	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact {
		$artifact = $this->source->prepare( $deployment, $baseline );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Break the prepared archive's custody identity for the proof.
		chmod( ( new ReflectionProperty( $artifact, 'path' ) )->getValue( $artifact ), 0644 );
		return $artifact;
	}
	public function verify_current_head(): void {
		$this->source->verify_current_head();
	}
}
$tampered                    = $make_zip( array( 'repository/demo/demo.php' => $plugin_source() ) );
[$actual, $record, $journal] = RAN_WP_Branch_Updater_ReasonFixtures::run(
	array(
		'operation'    => 'install',
		'subdirectory' => 'demo',
		'archives'     => new RAN_WP_Branch_Updater_ReasonTamperingArchives( new ProviderArchiveSource( new GitHubFixtureProvider( $tampered, 'abc123' ), $root . '/custody' ) ),
	)
);
$assert( 'archive_cleanup_failed' === $actual && array( 'archive_cleanup_failed' ) === $journal->finished, 'broken custody keeps the archive_cleanup_failed outcome for an old journal' );
$assert( null !== $record && FailureReason::ARCHIVE_CUSTODY_CHANGED === $record->reason && 'identity' === $record->detail && 'archive_integrity_failed' === $record->primary_outcome && $record->cleanup_failed, 'broken custody records the custody cause, its closed detail and the superseded outcome' );

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

// A terminal journal write that fails ambiguously still exposes its cause, on both finish paths.
[$outcome, $record, , , $thrown] = RAN_WP_Branch_Updater_ReasonFixtures::run( array( 'journal' => new RAN_WP_Branch_Updater_ReasonFailingJournal() ) );
$assert( 'thrown' === $outcome && $thrown instanceof BranchDeploymentJournalFailure && null !== $record && FailureReason::JOURNAL_UNCERTAIN === $record->reason, 'a terminal finish() journal failure is recorded and still escapes' );
[$outcome, $record, , , $thrown] = RAN_WP_Branch_Updater_ReasonFixtures::run(
	array(
		'journal'         => new RAN_WP_Branch_Updater_ReasonAwareFailingJournal(),
		'prepare_failure' => new ReasonedFailure( 'x', 'zip_invalid' ),
	)
);
$assert( 'thrown' === $outcome && $thrown instanceof AdmittedBranchDurabilityFailure && null !== $record && FailureReason::DURABILITY_UNCERTAIN === $record->reason && null === $record->detail, 'a terminal finish_with_reason() durability failure is recorded and still escapes' );

// Two handles from one standalone updater keep their own reasons.
$shared_updater = BranchUpdater::for_standalone( new StandaloneBranchRunner( new GitHubFixtureProvider( '', 'abc123' ), new FileAttemptStore( $root . '/handles' ), new RecordingExecutor(), $root . '/handle-custody', new FileMutationLock( $root . '/handles.lock' ) ) );
$handle_a       = $shared_updater->plugin( 'acme/demo', 'fixture-1', 'main', 'demo/demo.php' );
$handle_b       = $shared_updater->theme( 'acme/other', 'fixture-2', 'main', 'other' );
$handle_a->deploy( 'abc123' );
$a_reason = $handle_a->last_reason();
$assert( null !== $a_reason && null === $handle_b->last_reason(), 'a handle that has not deployed has no reason, whatever another handle did' );
$handle_b->deploy( 'abc123' );
$assert( $handle_a->last_reason() === $a_reason, 'a later deploy of another handle does not change this handle\'s reason' );

// A non-ambiguous terminal journal failure leaves the run's own reason in place.
$wordpress_failed                = CorePackageExecutionResult::failed( CorePackageExecutionFailure::WORDPRESS_FAILED, 'incompatible_archive' );
[$outcome, $record, , , $thrown] = RAN_WP_Branch_Updater_ReasonFixtures::run(
	array(
		'journal' => new RAN_WP_Branch_Updater_ReasonPlainFailingJournal(),
		'result'  => $wordpress_failed,
	)
);
$assert( 'thrown' === $outcome && $thrown instanceof RuntimeException && null !== $record && FailureReason::CORE_WORDPRESS_FAILED === $record->reason, 'a plain terminal journal failure keeps the run reason' );

// A durability failure whose cleanup also fails records both, and the failure still escapes.
[$outcome, $record, , , $thrown] = RAN_WP_Branch_Updater_ReasonFixtures::run(
	array(
		'lock_failure'    => new AdmittedBranchDurabilityFailure( 'private durability text' ),
		'cleanup_failure' => new RuntimeException( 'private cleanup text' ),
	)
);
$assert( 'thrown' === $outcome && $thrown instanceof AdmittedBranchDurabilityFailure && null !== $record && FailureReason::DURABILITY_UNCERTAIN === $record->reason && $record->cleanup_failed, 'a durability failure with a failing cleanup records the cause and the cleanup failure' );

// Handles of one admitted updater: only the handle whose call actually ran has a reason.
$admitted_updater = static function () use ( $wordpress_failed ): BranchUpdater {
	$o = array();
	return BranchUpdater::for_admitted_attempt(
		new BranchDeploymentDeclaration( 'reason-handles', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', 'abc123', 'update', null, 'demo/demo.php' ),
		new RAN_WP_Branch_Updater_ReasonJournal(),
		new RAN_WP_Branch_Updater_ReasonArchives( new RAN_WP_Branch_Updater_ReasonArtifact(), null ),
		new RAN_WP_Branch_Updater_ReasonTarget( RAN_WP_Branch_Updater_ReasonFixtures::BASELINE, $o ),
		new RAN_WP_Branch_Updater_ReasonExecutor( $wordpress_failed, null, null ),
		new RAN_WP_Branch_Updater_ReasonLock( null )
	);
};
$admitted_shared  = $admitted_updater();
$admitted_a       = $admitted_shared->plugin( 'acme/demo', 'fixture-1', 'main', package_slug: 'demo' );
$admitted_b       = $admitted_shared->plugin( 'acme/demo', 'fixture-1', 'main', package_slug: 'demo' );
$admitted_a->deploy();
$a_reason = $admitted_a->last_reason();
$assert( null !== $a_reason && FailureReason::CORE_WORDPRESS_FAILED === $a_reason->reason && null === $admitted_b->last_reason(), 'an admitted handle that never ran has no reason' );
try {
	$admitted_b->deploy();
	$assert( false, 'a consumed admitted runner must refuse another handle' );
} catch ( RuntimeException ) {
	$assert( null === $admitted_b->last_reason(), 'a refused admitted deploy() does not inherit another handle\'s reason' );
}
$assert( $admitted_a->last_reason() === $a_reason, 'the refused call did not change the first handle' );
try {
	$admitted_a->deploy();
	$assert( false, 'a consumed admitted runner must refuse a reused handle' );
} catch ( RuntimeException ) {
	$assert( null === $admitted_a->last_reason(), 'a reused handle drops the reason of the call that no longer applies' );
}
$admitted_c = $admitted_updater()->plugin( 'acme/demo', 'fixture-1', 'main', package_slug: 'demo' );
$admitted_c->deploy();
$assert( null !== $admitted_c->last_reason(), 'the control handle ran and has a reason' );
try {
	$admitted_c->deploy( 'zzz' );
	$assert( false, 'a mismatched terminal declaration must be rejected' );
} catch ( InvalidArgumentException ) {
	$assert( null === $admitted_c->last_reason(), 'a rejected terminal declaration leaves no stale reason' );
}

// A failure before the runner exists (the journal cannot begin) must not leave a stale reason behind.
$standalone = new StandaloneBranchRunner( new GitHubFixtureProvider( '', 'abc123' ), new FileAttemptStore( $root ), new RecordingExecutor(), $root . '/custody', new FileMutationLock( $root . '/standalone.lock' ) );
( new ReflectionProperty( $standalone, 'last_reason' ) )->setValue( $standalone, new AdmittedFailureReason( FailureReason::LOCK_HELD ) );
try {
	$standalone->execute( new BranchDeploymentDeclaration( 'standalone-begin', 'plugin', 'demo', 'acme/demo', 'fixture-1', 'main', 'abc123', 'update', null, 'demo/demo.php' ) );
	$assert( false, 'an unusable journal must refuse to begin' );
} catch ( RuntimeException ) {
	$assert( null === $standalone->last_reason(), 'a stale reason is cleared when the journal cannot begin' );
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
