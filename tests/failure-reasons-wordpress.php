<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.
declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/Support/WordPressErrorStub.php';

use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\GitHubFixtureProvider;
use RAN\WPBranchUpdater\V1\Persistence\FileMutationLock;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockReleaseFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockStorageFailure;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionFailure;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RAN\WPBranchUpdater\V1\Runtime\FailureReason;
use RAN\WPBranchUpdater\V1\Runtime\ReasonedFailure;
use RAN\WPBranchUpdater\V1\WordPress\WordPressCorePackageExecutor;
use RAN\WPBranchUpdater\V1\WordPress\WordPressPackageExecutor;
use RAN\WPBranchUpdater\V1\WordPress\WordPressUpdaterLock;

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This test-only state holder and the WordPress stubs below share one standalone proof.
final class RAN_WP_Branch_Updater_WordPressState {
	public static bool $multisite     = false;
	public static bool $file_mods     = true;
	public static string $filesystem  = 'direct';
	public static int $hook_calls     = 0;
	public static int|false $priority = false;
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This test-only database double shares one standalone proof.
final class RAN_WP_Branch_Updater_WordPressDatabase {
	public string $options    = 'wp_options';
	public string $last_error = '';
	/** @var list<int|false> */
	public array $inserts = array();
	/** @var list<int|false> */
	public array $deletes = array();
	public mixed $stored  = null;
	/** @var list<string> */
	public array $queries = array();
	public function prepare( string $query, mixed ...$arguments ): string {
		return $query . ' ' . implode( ' ', array_map( 'strval', $arguments ) );
	}
	public function query( string $sql ): int|false {
		$this->queries[] = $sql;
		return ( str_starts_with( $sql, 'INSERT' ) ? array_shift( $this->inserts ) : array_shift( $this->deletes ) ) ?? false;
	}
	public function get_var( string $sql ): mixed {
		$this->queries[] = $sql;
		return $this->stored;
	}
}

// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function stubs must retain their native global names and signatures in this standalone proof.
function is_multisite(): bool {
	return RAN_WP_Branch_Updater_WordPressState::$multisite;
}
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress function stubs must retain their native global names and signatures in this standalone proof.
function wp_is_file_mod_allowed( string $context ): bool {
	return RAN_WP_Branch_Updater_WordPressState::$file_mods && '' !== $context;
}
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function stubs must retain their native global names and signatures in this standalone proof.
function get_filesystem_method(): string {
	return RAN_WP_Branch_Updater_WordPressState::$filesystem;
}
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress function stubs must retain their native global names and signatures in this standalone proof.
function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	++RAN_WP_Branch_Updater_WordPressState::$hook_calls;
	return true;
}
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress function stubs must retain their native global names and signatures in this standalone proof.
function remove_filter( string $hook_name, callable $callback, int $priority = 10 ): bool {
	++RAN_WP_Branch_Updater_WordPressState::$hook_calls;
	return true;
}
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress function stubs must retain their native global names and signatures in this standalone proof.
function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	++RAN_WP_Branch_Updater_WordPressState::$hook_calls;
	return true;
}
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress function stubs must retain their native global names and signatures in this standalone proof.
function remove_action( string $hook_name, callable $callback, int $priority = 10 ): bool {
	++RAN_WP_Branch_Updater_WordPressState::$hook_calls;
	return true;
}
/** @param callable|string|array<int,mixed>|false $callback */
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress function stubs must retain their native global names and signatures in this standalone proof.
function has_action( string $hook_name, callable|string|array|false $callback = false ): int|false {
	++RAN_WP_Branch_Updater_WordPressState::$hook_calls;
	return RAN_WP_Branch_Updater_WordPressState::$priority;
}
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function stubs must retain their native global names and signatures in this standalone proof.
function trailingslashit( string $value ): string {
	return rtrim( $value, '/' ) . '/';
}

$assert = static function ( bool $actual, string $message ): void {
	if ( ! $actual ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion diagnostics are not HTML; preserve the actual failing fixture detail.
		throw new RuntimeException( 'FAIL: ' . $message );
	}
};

$root = __DIR__ . '/build/failure-reasons-wordpress-' . bin2hex( random_bytes( 4 ) );
foreach ( array( '/wp/wp-admin/includes', '/custody' ) as $directory ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- This standalone fixture owns its isolated temporary directory.
	if ( ! mkdir( $root . $directory, 0700, true ) ) {
		throw new RuntimeException( 'Cannot create WordPress failure reason fixture directory.' );
	}
}
foreach ( array( 'file', 'plugin', 'theme' ) as $include ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Create empty include stubs in this isolated temporary directory.
	file_put_contents( $root . '/wp/wp-admin/includes/' . $include . '.php', "<?php\n" );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress defines this exact constant; the real default adapter requires it.
define( 'ABSPATH', $root . '/wp/' );

$sequence   = 0;
$prepared   = static function ( string $type, array $entries, string $operation = 'install' ) use ( $root, &$sequence ): array {
	$archive_path = sprintf( '%s/fixture-%03d.zip', $root, ++$sequence );
	$zip          = new ZipArchive();
	$zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( $entries as $name => $contents ) {
		$zip->addFromString( $name, $contents );
	}
	$zip->close();
	$declaration = new BranchDeploymentDeclaration( 'wp-reason-' . $sequence, $type, 'demo', 'acme/demo', 'fixture-1', 'main', 'abc123', $operation, 'demo' );
	$artifact    = PreparedArchive::download_and_validate( ( new GitHubFixtureProvider( $archive_path, 'abc123' ) )->prepare( $declaration ), $declaration, $root . '/custody' );
	return array( $declaration, $artifact );
};
$plugin_zip = array( 'repository/demo/demo.php' => "<?php\n/*\nPlugin Name: Demo\nVersion: 1.2.3\n*/\n" );
$theme_zip  = static fn( string $extra = '' ): array => array( 'repository/demo/style.css' => "/*\nTheme Name: Demo\nVersion: 1.2.3\n" . $extra . "*/\n" );

// Preflight causes keep their fixed messages and add a closed reason.
[$declaration, $artifact] = $prepared( 'plugin', $plugin_zip );
$executor                 = new WordPressPackageExecutor();
$assert( array() === $executor->preflight( $declaration, $artifact ), 'preflight passes when WordPress allows the operation' );
foreach ( array(
	array( 'multisite', FailureReason::PREFLIGHT_MULTISITE, 'Branch deployment supports single-site WordPress only.' ),
	array( 'file_mods', FailureReason::PREFLIGHT_FILE_MODS_DISABLED, 'WordPress file modifications are disabled.' ),
	array( 'filesystem', FailureReason::PREFLIGHT_FILESYSTEM_NOT_DIRECT, 'The WordPress direct filesystem method is required.' ),
	array( 'maintenance', FailureReason::PREFLIGHT_MAINTENANCE_ACTIVE, 'WordPress maintenance mode is active.' ),
) as [$condition, $expected_reason, $message] ) {
	RAN_WP_Branch_Updater_WordPressState::$multisite  = 'multisite' === $condition;
	RAN_WP_Branch_Updater_WordPressState::$file_mods  = 'file_mods' !== $condition;
	RAN_WP_Branch_Updater_WordPressState::$filesystem = 'filesystem' === $condition ? 'ftp' : 'direct';
	if ( 'maintenance' === $condition ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Create the maintenance marker in this isolated temporary directory.
		file_put_contents( ABSPATH . '.maintenance', '' );
	}
	try {
		$executor->preflight( $declaration, $artifact );
		$assert( false, 'preflight must refuse: ' . $condition );
	} catch ( ReasonedFailure $failure ) {
		$assert( $expected_reason->value === $failure->reason && $message === $failure->getMessage(), 'preflight ' . $condition . ' carries ' . $expected_reason->value . ' and its unchanged message' );
	}
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove the fixture's maintenance marker.
unlink( ABSPATH . '.maintenance' );
RAN_WP_Branch_Updater_WordPressState::$multisite  = false;
RAN_WP_Branch_Updater_WordPressState::$file_mods  = true;
RAN_WP_Branch_Updater_WordPressState::$filesystem = 'direct';

// WordPress Core execution results.
$secret       = '/var/www/private/wp-content/upgrade/' . hash( 'sha256', 'x' ) . ' https://user:token@example.test';
$run          = static function ( mixed $outcome, string $type = 'plugin', string $method = 'install_plugin' ) use ( $prepared, $plugin_zip, $theme_zip ): CorePackageExecutionResult {
	[, $artifact] = $prepared( $type, 'plugin' === $type ? $plugin_zip : $theme_zip() );
	$executor     = new WordPressCorePackageExecutor(
		static function () use ( $outcome ): mixed {
			if ( $outcome instanceof Throwable ) {
				throw $outcome;
			}
			return $outcome;
		}
	);
	return $executor->{$method}( $artifact, 'demo', 'demo' );
};
$expectations = array(
	'allow-listed upgrader code'     => array( new WP_Error( 'copy_failed', $secret ), CorePackageExecutionFailure::WORDPRESS_FAILED, 'copy_failed' ),
	'foreign upgrader code'          => array( new WP_Error( 'vendor_specific_code', $secret ), CorePackageExecutionFailure::WORDPRESS_FAILED, null ),
	'code that looks like a secret'  => array( new WP_Error( 'api_key_rejected', $secret ), CorePackageExecutionFailure::WORDPRESS_FAILED, null ),
	'upgrader code with a path'      => array( new WP_Error( '/var/www/copy_failed', $secret ), CorePackageExecutionFailure::WORDPRESS_FAILED, null ),
	'own source failure'             => array( new WP_Error( 'ran_branch_deployment_invalid_package_source', '', 'move_failed' ), CorePackageExecutionFailure::WORDPRESS_FAILED, 'move_failed' ),
	'own source failure with a path' => array( new WP_Error( 'ran_branch_deployment_invalid_package_source', '', '/etc/passwd' ), CorePackageExecutionFailure::WORDPRESS_FAILED, null ),
	'refusal'                        => array( false, CorePackageExecutionFailure::WORDPRESS_REFUSED, null ),
	'exception'                      => array( new RuntimeException( $secret ), CorePackageExecutionFailure::WORDPRESS_UNCERTAIN, 'exception' ),
	'fatal error'                    => array( new Error( $secret ), CorePackageExecutionFailure::WORDPRESS_UNCERTAIN, 'php_error' ),
);
foreach ( $expectations as $label => [$outcome, $failure, $detail] ) {
	$result = $run( $outcome );
	$assert( $failure === $result->get_failure() && $detail === $result->get_detail(), $label . ': core failure ' . $failure->value . ' keeps only its closed detail' );
}

// Request validation and theme-parent causes.
[, $artifact] = $prepared( 'plugin', $plugin_zip );
$core         = new WordPressCorePackageExecutor( static fn(): bool => true );
$result       = $core->install_plugin( $artifact, 'bad/slug', 'demo' );
$assert( CorePackageExecutionFailure::INVALID_REQUEST === $result->get_failure() && 'input_invalid' === $result->get_detail(), 'an invalid slug is input_invalid' );
$result = $core->update_plugin( $artifact, 'demo', 'demo', 'other/demo.php' );
$assert( CorePackageExecutionFailure::INVALID_REQUEST === $result->get_failure() && 'identifier_mismatch' === $result->get_detail(), 'a foreign installed identifier is identifier_mismatch' );
$archive_path = $artifact->get_path();
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Change the fixture archive mode to break custody.
chmod( $archive_path, 0644 );
$result = $core->install_plugin( $artifact, 'demo', 'demo' );
$assert( CorePackageExecutionFailure::INVALID_REQUEST === $result->get_failure() && 'archive_changed' === $result->get_detail(), 'a changed archive is archive_changed' );
try {
	$artifact->assert_unchanged();
	$assert( false, 'changed custody must be refused' );
} catch ( ReasonedFailure $failure ) {
	$assert( FailureReason::ARCHIVE_CUSTODY_CHANGED->value === $failure->reason && 'identity' === $failure->detail && 'Prepared archive changed before use.' === $failure->getMessage(), 'a changed archive identity is archive_custody_changed with an unchanged message' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restore the fixture archive mode so cleanup can prove the digest cause.
chmod( $archive_path, 0600 );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Rewrite the fixture archive bytes in place, keeping size and inode.
file_put_contents( $archive_path, str_repeat( 'x', (int) filesize( $archive_path ) ) );
try {
	$artifact->assert_unchanged();
	$assert( false, 'changed bytes must be refused' );
} catch ( ReasonedFailure $failure ) {
	$assert( 'digest' === $failure->detail, 'changed archive bytes are the digest cause' );
}
[, $artifact] = $prepared( 'plugin', $plugin_zip );
$artifact->cleanup();
try {
	$artifact->assert_unchanged();
	$assert( false, 'a cleaned archive must be refused' );
} catch ( ReasonedFailure $failure ) {
	$assert( 'cleaned' === $failure->detail, 'a cleaned archive is the cleaned cause' );
}

// wp_get_theme() is not stubbed: a parent theme cannot be looked up here, so theme_parent_missing is covered by the installed WordPress lane.
foreach ( array(
	'parent is itself' => array( "Template: demo\n", 'theme_parent_invalid' ),
	'parent unknown'   => array( "Template: parent\n", 'theme_parent_invalid' ),
	'invalid parent'   => array( "Template: bad/parent\n", 'theme_archive_invalid' ),
) as $label => [$template, $expected_detail] ) {
	[, $artifact] = $prepared( 'theme', $theme_zip( $template ) );
	$result       = ( new WordPressCorePackageExecutor( static fn(): bool => true ) )->install_theme( $artifact, 'demo', 'demo' );
	$assert( CorePackageExecutionFailure::INVALID_REQUEST === $result->get_failure() && $expected_detail === $result->get_detail(), 'theme ' . $label . ': ' . $expected_detail );
}

// Source selection causes.
$remote = $root . '/remote';
foreach ( array( '/remote/source/sub', '/remote/blocked', '/remote/other' ) as $directory ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- This standalone fixture owns its isolated temporary directory.
	mkdir( $root . $directory, 0700, true );
}
$selection = new ReflectionMethod( WordPressCorePackageExecutor::class, 'source_selection_filter' );
$extra     = array(
	'type'   => 'plugin',
	'action' => 'install',
);
$cases     = array(
	'non-string source'    => array( 'demo', null, array( 1, $remote ), 'source_invalid' ),
	'source outside'       => array( 'demo', null, array( $root . '/wp', $remote ), 'source_invalid' ),
	'subdirectory missing' => array( 'demo', 'nope', array( $remote . '/source', $remote ), 'subdirectory_missing' ),
	'destination exists'   => array( 'blocked', null, array( $remote . '/source', $remote ), 'destination_exists' ),
	'move failed'          => array( 'fresh', null, array( $remote . '/source', $remote ), 'move_failed' ),
);
foreach ( $cases as $label => [$slug, $subdirectory, $arguments, $expected_detail] ) {
	$filter       = $selection->invoke( new WordPressCorePackageExecutor(), $slug, $subdirectory, 'plugin', 'install', null );
	$source_error = $filter( $arguments[0], $arguments[1], null, $extra );
	$assert( $source_error instanceof WP_Error && 'ran_branch_deployment_invalid_package_source' === $source_error->get_error_code() && $expected_detail === $source_error->get_error_data() && '' === $source_error->get_error_message(), 'source selection ' . $label . ': ' . $expected_detail );
}

// Mutation lock causes.
$lock_path = $root . '/mutation.lock';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- flock requires the native descriptor that holds the competing lock.
$holder = fopen( $lock_path, 'c+' );
$assert( false !== $holder && flock( $holder, LOCK_EX | LOCK_NB ), 'the competing lock is acquired' );
try {
	( new FileMutationLock( $lock_path ) )->run( static fn(): string => 'unreachable' );
	$assert( false, 'a held file lock must refuse' );
} catch ( ReasonedFailure $failure ) {
	$assert( FailureReason::LOCK_HELD->value === $failure->reason && 'Mutation lock is held.' === $failure->getMessage(), 'a held file lock is lock_held with its unchanged message' );
}
$database = new RAN_WP_Branch_Updater_WordPressDatabase();
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone CLI fixture models the WordPress database global.
$wpdb  = $database;
$stale = (string) ( time() - 7200 );
foreach ( array(
	'live token'            => array( (string) time(), array( 0 ), array(), FailureReason::LOCK_HELD ),
	'malformed token'       => array( 'abc', array( 0 ), array(), FailureReason::LOCK_TOKEN_INVALID ),
	'missing token'         => array( null, array( 0 ), array(), FailureReason::LOCK_TOKEN_INVALID ),
	'stale release failed'  => array( $stale, array( 0 ), array( 0 ), FailureReason::LOCK_STALE_RELEASE_FAIL ),
	'stale reinsert failed' => array( $stale, array( 0, 0 ), array( 1 ), FailureReason::LOCK_STALE_REINSERT_FAIL ),
) as $label => [$stored, $inserts, $deletes, $expected_reason] ) {
	$database->stored  = $stored;
	$database->inserts = $inserts;
	$database->deletes = $deletes;
	try {
		( new WordPressUpdaterLock() )->acquire();
		$assert( false, 'contention must refuse: ' . $label );
	} catch ( ReasonedFailure $failure ) {
		$assert( $expected_reason->value === $failure->reason && 'WordPress updater is already running.' === $failure->getMessage(), 'updater lock ' . $label . ': ' . $expected_reason->value );
	}
}
$database->inserts = array( false );
try {
	( new WordPressUpdaterLock() )->acquire();
	$assert( false, 'database failure must escape' );
} catch ( BranchDeploymentLockStorageFailure $failure ) {
	$assert( true, 'a lock storage failure keeps its own type; the runner maps it to lock_storage' );
}
$database->inserts = array( 1 );
$database->deletes = array( 0 );
try {
	( new WordPressUpdaterLock() )->run( static fn(): string => 'ran' );
	$assert( false, 'a failed lock release must escape' );
} catch ( BranchDeploymentLockReleaseFailure ) {
	$assert( true, 'a failed lock release keeps its own type; the runner maps it to lock_release' );
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

echo "PASS WordPress failure reasons\n";
