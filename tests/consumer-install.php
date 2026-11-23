<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.

declare(strict_types=1);

use RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\AdmittedPackageExecutor;
use RAN\WPBranchUpdater\V1\Contract\AdmittedTargetFacts;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\BranchUpdater;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;

$consumer = getenv( 'BRANCH_UPDATER_CONSUMER_ROOT' );
if ( ! is_string( $consumer ) || '' === $consumer || ! is_file( $consumer . '/vendor/autoload.php' ) ) {
	throw new RuntimeException( 'Consumer installation is incomplete.' );
}
$consumer = realpath( $consumer );
if ( false === $consumer ) {
	throw new RuntimeException( 'Consumer root cannot be resolved.' );
}
require $consumer . '/vendor/autoload.php';

foreach ( array( BranchUpdater::class, RAN\UpdaterSupport\V1\ArchiveSafety::class, RAN\UpdaterSupport\V1\TargetWorkingCopy::class, RAN\UpdaterSupport\V1\PackageDirectory::class ) as $class ) {
	$file = ( new ReflectionClass( $class ) )->getFileName();
	if ( ! is_string( $file ) || ! str_starts_with( $file, $consumer . '/vendor/' ) || is_link( $file ) ) {
		throw new RuntimeException( 'Composer did not load an installed regular package file.' );
	}
}
foreach ( array( 'Contract\\BranchProvider', 'Contract\\PackageExecutor', 'Persistence\\FileAttemptStore', 'Persistence\\FileAttemptJournal', 'Persistence\\FileMutationLock', 'Runtime\\StandaloneBranchRunner', 'Runtime\\StandalonePackageExecutor', 'Runtime\\StandaloneTargetFacts', 'Runtime\\ProviderArchiveSource', 'WordPress\\WordPressPackageExecutor', 'Archive\\PreparedArchiveArtifact', 'GitHubFixtureProvider', 'BitbucketFixtureProvider', 'RecordingExecutor', 'HeaderFormattingFixtures' ) as $retired ) {
	$name = 'RAN\\WPBranchUpdater\\V1\\' . $retired;
	if ( class_exists( $name ) || interface_exists( $name ) ) {
		throw new RuntimeException( 'Installed package contains a retired or development-only declaration.' );
	}
}
if ( in_array( 'for_standalone', array_map( static fn( ReflectionMethod $method ): string => $method->getName(), ( new ReflectionClass( BranchUpdater::class ) )->getMethods() ), true ) || is_file( $consumer . '/vendor/ran/wp-branch-updater/bootstrap.php' ) || class_exists( 'PHPUnit\\Framework\\TestCase' ) ) {
	throw new RuntimeException( 'Installed package contains retired or development-only code.' );
}
foreach ( array( 'phpcompatibility/php-compatibility', 'phpcompatibility/phpcompatibility-paragonie', 'phpcompatibility/phpcompatibility-wp', 'phpstan/phpstan', 'ran/coding-standards', 'szepeviktor/phpstan-wordpress' ) as $dev_package ) {
	if ( Composer\InstalledVersions::isInstalled( $dev_package ) ) {
		throw new RuntimeException( 'Consumer installation includes a development dependency.' );
	}
}
$archive_contract = new ReflectionClass( RAN\WPBranchUpdater\V1\Archive\PreparedArchive::class );
if ( ! $archive_contract->implementsInterface( AdmittedBranchArtifact::class ) || ! $archive_contract->implementsInterface( RAN\WPBranchUpdater\V1\Contract\PreparedPackageArtifact::class ) ) {
	throw new RuntimeException( 'Installed prepared archive must implement both archive contracts.' );
}
$journal     = new class() implements AdmittedAttemptJournal {
	public string $outcome = '';
	public function record_resolved_ref( string $ref ): void {}
	public function mark_mutation_started(): void {}
	public function finish( string $code ): void {
		$this->outcome = $code; }
};
$archives    = new class() implements AdmittedArchiveSource {
	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact {
		unset( $deployment, $baseline );
		throw new RuntimeException( 'Unexpected archive access.' ); }
	public function verify_current_head(): void {
		throw new RuntimeException( 'Unexpected archive access.' ); }
};
$target      = new class() implements AdmittedTargetFacts {
	public int $policy_checks = 0;
	public function target_directory( BranchDeploymentDeclaration $deployment ): string {
		unset( $deployment );
		throw new RuntimeException( 'Unexpected target access.' ); }
	public function assert_mutation_allowed(): void {
		++$this->policy_checks;
		throw new RuntimeException( 'Synthetic policy rejection.' ); }
	public function frozen_target( BranchDeploymentDeclaration $deployment, bool $defer_existing ): ?array {
		unset( $deployment, $defer_existing );
		throw new RuntimeException( 'Unexpected target access.' ); }
	public function maintenance_active(): bool {
		throw new RuntimeException( 'Unexpected target access.' ); }
	public function recheck_managed( BranchDeploymentDeclaration $deployment ): void {
		unset( $deployment );
		throw new RuntimeException( 'Unexpected target access.' ); }
	public function installed( BranchDeploymentDeclaration $deployment ): array {
		unset( $deployment );
		throw new RuntimeException( 'Unexpected target access.' ); }
	public function baseline_now( BranchDeploymentDeclaration $deployment, array $baseline ): ?array {
		unset( $deployment, $baseline );
		throw new RuntimeException( 'Unexpected target access.' ); }
	public function adopt( BranchDeploymentDeclaration $deployment ): bool {
		unset( $deployment );
		throw new RuntimeException( 'Unexpected target access.' ); }
};
$executor    = new class() implements AdmittedPackageExecutor {
	public function preflight( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): void {
		unset( $deployment, $artifact );
		throw new RuntimeException( 'Unexpected executor access.' ); }
	public function execute( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
		unset( $deployment, $baseline, $artifact );
		throw new RuntimeException( 'Unexpected executor access.' ); }
};
$lock        = new class() implements MutationLock {
	public function run( callable $operation ): mixed {
		unset( $operation );
		throw new RuntimeException( 'Unexpected lock access.' ); }
};
$declaration = new BranchDeploymentDeclaration( 'consumer-attempt', 'plugin', 'demo', 'acme/demo', 'repository-id', 'main', null, 'update', null, 'demo/demo.php' );
$updater     = BranchUpdater::for_admitted_attempt( $declaration, $journal, $archives, $target, $executor, $lock );
$deployment  = $updater->plugin( 'acme/demo', 'repository-id', 'main', 'demo/demo.php' );
if ( 'consumer-attempt' !== $deployment->attempt_id() || 'policy_blocked' !== $deployment->deploy() || 'policy_blocked' !== $journal->outcome || 1 !== $target->policy_checks ) {
	throw new RuntimeException( 'Installed admitted API did not journal its terminal policy outcome.' );
}
echo "PASS installed Composer consumer admitted API\n";
