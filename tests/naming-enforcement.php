<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- This standalone CLI runner and its test doubles never load into WordPress global scope.

declare(strict_types=1);

$root          = dirname( __DIR__ );
$method_code   = 'RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase';
$variable_code = 'WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase';
$cases         = array(
	array( 'src/Contract/AdmittedTargetFacts.php', 'function frozen_target(', 'function frozenTarget(', $method_code ),
	array( 'src/Archive/PreparedArchive.php', 'function download_and_validate(', 'function downloadAndValidate(', $method_code ),
	array( 'src/Runtime/BranchUpdater.php', 'function for_admitted_attempt(', 'function forAdmittedAttempt(', $method_code ),
	array( 'src/WordPress/WordPressCorePackageExecutor.php', 'function update_plugin(', 'function updatePlugin(', $method_code ),
	array( 'src/Runtime/BranchDeploymentDeclaration.php', '$attempt_id', '$attemptId', $variable_code ),
	array( 'src/Archive/ArchiveOffer.php', '$verify_head', '$verifyHead', $variable_code ),
	array( 'src/Runtime/BranchDeployment.php', '$expected_commit', '$expectedCommit', $variable_code ),
	array( 'src/Runtime/AdmittedBranchRunner.php', '->outcome_code', '->outcomeCode', 'WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase' ),
	array( 'src/Archive/ArchiveValidator.php', 'phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase', 'external-contract WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase', 'WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase' ),
	array( 'src/WordPress/WordPressCorePackageExecutor.php', 'phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase', 'external-contract WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase', 'WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase' ),
	array( 'src/WordPress/WordPressUpdaterLock.php', 'phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'reviewed-exception WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'WordPress.Security.EscapeOutput.ExceptionNotEscaped' ),
	array( 'src/WordPress/WordPressPackageExecutor.php', 'phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'reviewed-exception WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'WordPress.Security.EscapeOutput.ExceptionNotEscaped' ),
	array( 'src/Persistence/FileAttemptStore.php', 'phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'reviewed-exception WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'WordPress.Security.EscapeOutput.ExceptionNotEscaped' ),
	array( 'src/Archive/ArchiveValidator.php', 'phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'reviewed-exception WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'WordPress.Security.EscapeOutput.ExceptionNotEscaped' ),
	array( 'src/Archive/PreparedArchive.php', 'phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'reviewed-exception WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'WordPress.Security.EscapeOutput.ExceptionNotEscaped' ),
	array( 'src/Archive/PreparedArchive.php', '1 !== $s[\'nlink\']', '$s[\'nlink\'] !== 1', 'WordPress.PHP.YodaConditions.NotYoda' ),
	array( 'src/Persistence/FileAttemptStore.php', '( $r[\'id\'] ?? null ) !== $id', '$id !== ( $r[\'id\'] ?? null )', 'WordPress.PHP.YodaConditions.NotYoda' ),

	array( 'src/WordPress/WordPressCorePackageExecutor.php', 'phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed', 'reviewed-exception Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed', 'Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed' ),
	array( 'src/Runtime/AdmittedBranchStageFailure.php', 'phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found', 'reviewed-exception Generic.CodeAnalysis.UselessOverridingMethod.Found', 'Generic.CodeAnalysis.UselessOverridingMethod.Found' ),
	array( 'src/WordPress/WordPressCorePackageExecutor.php', '$parent_path', '$parent', 'Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound' ),

	array( 'bootstrap.php', '$archive_directory', '$archiveDirectory', $variable_code ),
	array( 'bootstrap.php', '$maximum_artifact_bytes', '$maximumArtifactBytes', $variable_code ),
	array( 'src/Archive/ArchiveValidator.php', 'function verify_entry_contents(', 'function verifyEntryContents(', $method_code ),
	array( 'src/Persistence/FileAttemptStore.php', 'function recover_stopped(', 'function recoverStopped(', $method_code ),
	array( 'src/Runtime/CorePackageExecutionResult.php', 'function is_successful(', 'function isSuccessful(', $method_code ),
	array( 'src/WordPress/WordPressCorePackageExecutor.php', 'function execute_install(', 'function executeInstall(', $method_code ),
	array( 'src/WordPress/WordPressUpdaterLock.php', 'function contention_failure(', 'function contentionFailure(', $method_code ),
	array( 'src/Persistence/FileAttemptJournal.php', 'function record_resolved_ref(', 'function recordResolvedRef(', $method_code ),
	array( 'src/Persistence/FileMutationLock.php', 'function run(', 'function regressionProbe(', $method_code ),
	array( 'src/Archive/PackageSubdirectory.php', '$provider_slug', '$providerSlug', $variable_code ),
	array( 'src/Persistence/FileAttemptJournal.php', '$attempt_id', '$attemptId', $variable_code ),
);

/** Run the real repository rules against an in-memory source copy at its actual path. */
function naming_report( string $root, string $path, ?string $source, string $standard = '.phpcs.xml', array $extra = array() ): array {
	$command = array_merge( array( PHP_BINARY, $root . '/vendor/bin/phpcs', '--standard=' . $root . '/' . $standard, '--report=json', '-q', '--no-colors' ), $extra );
	if ( null !== $source ) {
		$command[] = '--stdin-path=' . $root . '/' . $path;
		$command[] = '-';
	}
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- The standalone proof must launch a real checker or fresh worker and observe its process status.
	$process = proc_open(
		$command,
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$root
	);
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Could not run the repository naming gate.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone checker/child-process proof uses the exact native pipe descriptor.
	fwrite( $pipes[0], $source ?? '' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone checker/child-process proof uses the exact native pipe descriptor.
	fclose( $pipes[0] );
	$output = stream_get_contents( $pipes[1] );
	$error  = stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone checker/child-process proof uses the exact native pipe descriptor.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone checker/child-process proof uses the exact native pipe descriptor.
	fclose( $pipes[2] );
	$status = proc_close( $process );
	$report = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	if ( ! isset( $report['files'], $report['totals'] ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion diagnostics are not HTML; preserve the actual failing fixture detail.
		throw new RuntimeException( 'Invalid PHPCS report: ' . $error );
	}
	return array( $status, $report );
}

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone CLI fixture variable does not share WordPress runtime globals.
foreach ( $cases as [$path, $before, $after, $expected] ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the local installed fixture or checked source bytes; no WordPress runtime is loaded.
	$source = file_get_contents( $root . '/' . $path );
	if ( false === $source || ! str_contains( $source, $before ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion diagnostics are not HTML; preserve the actual failing fixture detail.
		throw new RuntimeException( 'Naming fixture anchor missing: ' . $path );
	}
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone CLI fixture variable does not share WordPress runtime globals.
	[$status, $report] = naming_report( $root, $path, $source );
	if ( 0 !== $status || 0 !== $report['totals']['errors'] || 0 !== $report['totals']['warnings'] ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion diagnostics are not HTML; preserve the actual failing fixture detail.
		throw new RuntimeException( 'Unchanged source did not pass: ' . $path );
	}
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone CLI fixture variable does not share WordPress runtime globals.
	[$status, $report] = naming_report( $root, $path, str_replace( $before, $after, $source ) );
	$caught            = false;
	foreach ( $report['files'] as $file ) {
		foreach ( $file['messages'] as $message ) {
			$caught = $caught || $message['source'] === $expected;
		}
	}
	if ( 0 === $status || ! $caught ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion diagnostics are not HTML; preserve the actual failing fixture detail.
		throw new RuntimeException( 'Naming regression escaped its expected check: ' . $path );
	}
}

echo 'PASS naming and standards enforcement: ' . count( $cases ) . " positive/negative pairs, including inherited owned methods\n";

/** Inspect comments only: intentional malformed PHP strings remain inert test inputs. */
function has_blanket_directive( string $source ): bool {
	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true )
			&& preg_match( '/phpcs:(?:ignoreFile|(?:disable|ignore)(?=\s*(?:--|\*\/|$)))|codingStandardsIgnore|@phpcs/im', $token[1] ) ) {
			return true;
		}
	}
	return false;
}

foreach ( array( 'phpcs:disable', 'phpcs:ignore -- blanket', 'phpcs:ignoreFile', '@codingStandardsIgnoreStart' ) as $directive ) {
	if ( ! has_blanket_directive( "<?php /**\n * " . $directive . "\n * Reason\n */" ) || ! has_blanket_directive( "<?php\n// " . $directive . "\n" ) || ! has_blanket_directive( '<?php /* ' . $directive . ' */' ) || ! has_blanket_directive( '<?php /** ' . $directive . "\n */" ) || has_blanket_directive( "<?php\n\$literal = '" . $directive . "';\n" ) ) {
		throw new RuntimeException( 'Blanket-directive comment/string discrimination failed.' );
	}
}
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Read the Git-maintained PHP population using a fixed local command.
$tracked_output = shell_exec( 'git ls-files -z -- "*.php"' );
if ( ! is_string( $tracked_output ) || '' === $tracked_output ) {
	throw new RuntimeException( 'Tracked PHP discovery failed.' );
}
$tracked = array_filter( explode( "\0", $tracked_output ) );
foreach ( $tracked as $tracked_path ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect tracked source comments without executing fixture bytes.
	if ( has_blanket_directive( (string) file_get_contents( $root . '/' . $tracked_path ) ) ) {
		throw new RuntimeException( 'Tracked PHP contains a blanket or legacy suppression.' );
	}
}
$probe_path = 'standards-discovery-' . bin2hex( random_bytes( 8 ) ) . '.php';
try {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary first-party-root fixture proves the checker's real file discovery.
	if ( false === file_put_contents( $root . '/' . $probe_path, "<?php\n" ) ) {
		throw new RuntimeException( 'Could not create discovery probe.' );
	}
	foreach ( array( false, true ) as $exclude_probe ) {
		[, $discovery] = naming_report( $root, '', null, '.phpcs.xml', $exclude_probe ? array( '--ignore=' . $probe_path ) : array() );
		$selected      = array_map( static fn( string $file ): string => str_replace( $root . '/', '', $file ), array_keys( $discovery['files'] ) );
		$missing       = array_diff( array_merge( $tracked, array( $probe_path ) ), $selected );
		if ( ( ! $exclude_probe && array() !== $missing ) || ( $exclude_probe && array( $probe_path ) !== array_values( $missing ) ) ) {
			throw new RuntimeException( 'Actual PHPCS discovery failed its positive or excluded-root control.' );
		}
	}
} finally {
	if ( is_file( $root . '/' . $probe_path ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this uniquely named discovery fixture.
		unlink( $root . '/' . $probe_path );
	}
}
$standalone = array_values( array_filter( $tracked, static fn( string $path ): bool => str_starts_with( $path, 'tests/' ) || str_starts_with( $path, 'scripts/' ) ) );
foreach ( array( false, true ) as $exclude_scripts ) {
	[, $discovery] = naming_report( $root, '', null, '.phpcs-cli-compat.xml', $exclude_scripts ? array( '--ignore=*/scripts/*' ) : array() );
	$selected      = array_map( static fn( string $file ): string => str_replace( $root . '/', '', $file ), array_keys( $discovery['files'] ) );
	$expected      = $exclude_scripts ? array_filter( $standalone, static fn( string $path ): bool => ! str_starts_with( $path, 'scripts/' ) ) : $standalone;
	if ( array() !== array_diff( $expected, $selected ) || array() !== array_diff( $selected, $expected ) || ( $exclude_scripts && count( $selected ) === count( $standalone ) ) ) {
		throw new RuntimeException( 'Standalone file discovery failed its positive or omitted-root control.' );
	}
}
foreach ( array( 'tests/compatibility-probe.php', 'scripts/compatibility-probe.php' ) as $compatibility_path ) {
	foreach ( array( '.phpcs.xml', '.phpcs-cli-compat.xml' ) as $compatibility_standard ) {
		[, $compatibility_report] = naming_report( $root, $compatibility_path, "<?php\narray_find( array(), static fn() => true );\n", $compatibility_standard );
		$diagnostics              = array_merge( ...array_column( array_values( $compatibility_report['files'] ), 'messages' ) );
		$caught_compatibility     = in_array( 'PHPCompatibility.FunctionUse.NewFunctions.array_findFound', array_column( $diagnostics, 'source' ), true );
		if ( ( '.phpcs-cli-compat.xml' === $compatibility_standard ) !== $caught_compatibility ) {
			throw new RuntimeException( 'Standalone compatibility must reject a WordPress-polyfilled future function.' );
		}
	}
}
echo "PASS tracked PHP discovery, excluded-root control, comment-only suppression checks and standalone compatibility\n";
