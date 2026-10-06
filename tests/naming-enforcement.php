<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone process-local variables never enter WordPress runtime; declarations remain checked.

declare(strict_types=1);

$root          = dirname( __DIR__ );
$method_code   = 'RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase';
$variable_code = 'WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase';
$cases         = array(
	array( 'src/Archive/PreparedArchive.php', 'final class PreparedArchive implements PreparedPackageArtifact {', 'final class PreparedArchive implements PreparedPackageArtifact { public function silence_probe(): void { @strlen( "probe" ); }', 'WordPress.PHP.NoSilencedErrors.Discouraged' ),

	array( 'src/Archive/ArchiveValidator.php', 'final class ArchiveValidator {', 'final class ArchiveValidator { public function native_operation_probe(): void { json_encode( array() ); }', 'WordPress.WP.AlternativeFunctions.json_encode_json_encode' ),
	array( 'src/Archive/PreparedArchive.php', 'final class PreparedArchive implements PreparedPackageArtifact {', 'final class PreparedArchive implements PreparedPackageArtifact { public function native_operation_probe(): void { json_encode( array() ); }', 'WordPress.WP.AlternativeFunctions.json_encode_json_encode' ),
	array( 'src/Persistence/FileAttemptStore.php', 'final class FileAttemptStore {', 'final class FileAttemptStore { public function native_operation_probe(): void { json_encode( array() ); }', 'WordPress.WP.AlternativeFunctions.json_encode_json_encode' ),
	array( 'src/Persistence/FileMutationLock.php', 'final class FileMutationLock implements MutationLock {', 'final class FileMutationLock implements MutationLock { public function native_operation_probe(): void { json_encode( array() ); }', 'WordPress.WP.AlternativeFunctions.json_encode_json_encode' ),
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
function ran_wp_branch_updater_naming_report( string $root, string $path, ?string $source, string $standard = '.phpcs.xml', array $extra = array() ): array {
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
	[$status, $report] = ran_wp_branch_updater_naming_report( $root, $path, $source );
	if ( 0 !== $status || 0 !== $report['totals']['errors'] || 0 !== $report['totals']['warnings'] ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion diagnostics are not HTML; preserve the actual failing fixture detail.
		throw new RuntimeException( 'Unchanged source did not pass: ' . $path );
	}
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone CLI fixture variable does not share WordPress runtime globals.
	[$status, $report] = ran_wp_branch_updater_naming_report( $root, $path, str_replace( $before, $after, $source ) );
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
function ran_wp_branch_updater_has_blanket_directive( string $source, string $path = '' ): bool {
	foreach ( token_get_all( $source ) as $token ) {
		if ( ! is_array( $token ) || ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		if ( preg_match( '/@codingStandards(?:Ignore|ChangeSetting)|@phpcs:/i', $token[1] ) ) {
			return true;
		}
		preg_match_all( '/phpcs:(ignorefile\S*|disable\S*|ignore\S*|set\S*)([^\r\n]*)/i', $token[1], $directives, PREG_SET_ORDER );
		foreach ( $directives as $directive ) {
			$operation = strtolower( $directive[1] );
			if ( ! in_array( $operation, array( 'ignore', 'disable' ), true ) ) {
				return true;
			}
			$parts = explode( ' -- ', trim( $directive[2], ' 	*/' ), 2 );
			if ( 2 !== count( $parts ) || '' === trim( $parts[1] ) ) {
				return true;
			}
			foreach ( explode( ',', $parts[0] ) as $code ) {
				if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*){3}$/D', trim( $code ) ) ) {
					return true;
				}
			}
			// Only standalone process variables retain a persistent exemption.
			if ( 'disable' === $operation && ( 2 !== $token[2]
				|| ! preg_match( '~^(?:tests|scripts)/~', $path )
				|| 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound' !== trim( $parts[0] ) ) ) {
				return true;
			}
		}
	}
	return false;
}

foreach ( array( 'phpcs:disable', 'phpcs:ignore -- blanket', 'phpcs:ignoreFile', 'PHPCS:IGNOREfileXYZ', 'phpcs:ignore WordPress -- Too broad', 'phpcs:disable WordPress.WP.AlternativeFunctions -- Too broad', 'phpcs:ignore WordPress.PHP.YodaConditions.NotYoda', 'phpcs:set WordPress.PHP.YodaConditions check true', '@codingStandardsIgnoreStart', '@codingStandardsChangeSetting WordPress.NamingConventions.PrefixAllGlobals prefixes rogue', '@CODINGSTANDARDSCHANGESETTING WordPress.NamingConventions.PrefixAllGlobals prefixes rogue' ) as $directive ) {
	if ( ! ran_wp_branch_updater_has_blanket_directive( "<?php /**\n * " . $directive . "\n * Reason\n */" ) || ! ran_wp_branch_updater_has_blanket_directive( "<?php\n// " . $directive . "\n" ) || ! ran_wp_branch_updater_has_blanket_directive( '<?php /* ' . $directive . ' */' ) || ! ran_wp_branch_updater_has_blanket_directive( '<?php /** ' . $directive . "\n */" ) || ran_wp_branch_updater_has_blanket_directive( "<?php\n\$literal = '" . $directive . "';\n" ) ) {
		throw new RuntimeException( 'Blanket-directive comment/string discrimination failed.' );
	}
}
// The legacy setting directive must not replace the reviewed global prefixes.
$legacy_prefix      = "<?php\n// @codingStandardsChangeSetting WordPress.NamingConventions.PrefixAllGlobals prefixes rogue\nfunction rogue_function() {}\n";
[, $legacy_report]  = ran_wp_branch_updater_naming_report( $root, 'tests/LegacyPrefix.php', $legacy_prefix, '.phpcs.xml', array( '--sniffs=WordPress.NamingConventions.PrefixAllGlobals' ) );
[, $outside_report] = ran_wp_branch_updater_naming_report( $root, 'tests/LegacyPrefix.php', "<?php\nfunction rogue_function() {}\n", '.phpcs.xml', array( '--sniffs=WordPress.NamingConventions.PrefixAllGlobals' ) );
if ( 0 !== $legacy_report['totals']['errors'] || 1 !== $outside_report['totals']['errors'] || ! ran_wp_branch_updater_has_blanket_directive( $legacy_prefix, 'tests/LegacyPrefix.php' ) ) {
	throw new RuntimeException( 'Legacy prefix setting bypass escaped its independent guard.' );
}
// Prove the locked checker honours each bypass which the separate guard rejects.
foreach ( array( 'PHPCS:DISABLE', 'PHPCS:IGNOREfileXYZ', 'phpcs:ignore RANOwnedMethods -- Broad standard', 'phpcs:disable RANOwnedMethods.NamingConventions -- Broad category', 'phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName -- Broad sniff', 'phpcs:disable RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Persistent waiver', 'phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase' ) as $directive ) {
	$source     = "<?php\n// " . $directive . "\nclass NamingProbe { public function hiddenBadName() {} }\n";
	[, $report] = ran_wp_branch_updater_naming_report( $root, 'tests/Probe.php', $source, '.phpcs.xml', array( '--sniffs=RANOwnedMethods.NamingConventions.ValidMethodName' ) );
	if ( 0 !== $report['totals']['errors'] || ! ran_wp_branch_updater_has_blanket_directive( $source, 'tests/Probe.php' ) ) {
		throw new RuntimeException( 'Real suppression bypass did not meet its rejection contract.' );
	}
}
$source     = "<?php\n// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Synthetic external signature.\nclass NamingProbe { public function hiddenBadName() {} }\nclass OtherNamingProbe { public function visibleBadName() {} }\n";
[, $report] = ran_wp_branch_updater_naming_report( $root, 'tests/Probe.php', $source, '.phpcs.xml', array( '--sniffs=RANOwnedMethods.NamingConventions.ValidMethodName' ) );
if ( 1 !== $report['totals']['errors'] || ran_wp_branch_updater_has_blanket_directive( $source, 'tests/Probe.php' ) ) {
	throw new RuntimeException( 'Exact local annotation must preserve the adjacent diagnostic.' );
}
foreach ( array( 'tests/admitted-runner.php', 'tests/naming-enforcement.php' ) as $fixture_path ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect the existing fixture in memory without executing or editing it.
	$source     = file_get_contents( $root . '/' . $fixture_path ) . "\nfunction unprefixed_future_declaration() {}\n";
	[, $report] = ran_wp_branch_updater_naming_report( $root, $fixture_path, $source, '.phpcs.xml', array( '--sniffs=WordPress.NamingConventions.PrefixAllGlobals' ) );
	if ( 1 !== $report['totals']['errors'] ) {
		throw new RuntimeException( 'Process-variable exemption must not cover future declarations.' );
	}
}
/** Check the reviewed ruleset arguments, which otherwise can silently disable sniffs. */
function ran_wp_branch_updater_has_unreviewed_arguments( string $source, bool $standalone ): bool {
	$xml = new DOMDocument();
	if ( ! $xml->loadXML( $source, LIBXML_NONET ) ) {
		return true;
	}
	$arguments = array();
	foreach ( ( new DOMXPath( $xml ) )->query( '//arg' ) as $node ) {
		if ( ! $node instanceof DOMElement ) {
			return true;
		}
		$arguments[] = array( $node->getAttribute( 'name' ), $node->getAttribute( 'value' ) );
	}
	$expected = $standalone ? array( array( 'extensions', 'php' ) ) : array( array( 'basepath', '.' ), array( 'colors', '' ), array( 'extensions', 'php' ), array( 'parallel', '4' ), array( '', 'sp' ) );
	return $expected !== $arguments;
}
foreach ( array(
	'.phpcs.xml'            => false,
	'.phpcs-cli-compat.xml' => true,
) as $ruleset => $is_standalone ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect the reviewed local ruleset command arguments without executing it.
	if ( ran_wp_branch_updater_has_unreviewed_arguments( file_get_contents( $root . '/' . $ruleset ), $is_standalone ) ) {
		throw new RuntimeException( 'Review PHPCS command arguments before certifying standards.' );
	}
}
$argument_probe = 'standards-argument-' . bin2hex( random_bytes( 8 ) ) . '.xml';
try {
	foreach ( array( '<arg name="exclude" value="RANOwnedMethods.NamingConventions.ValidMethodName"/>', '<arg name="sniffs" value="WordPress.PHP.YodaConditions"/>' ) as $argument ) {
		$xml = '<ruleset><rule ref="RANOwnedMethods"/>' . $argument . '</ruleset>';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Create only this unique inert ruleset to demonstrate the actual command-argument bypass.
		file_put_contents( $root . '/' . $argument_probe, $xml );
		[, $report] = ran_wp_branch_updater_naming_report( $root, 'tests/ArgumentProbe.php', '<?php class Probe { public function badName() {} }', $argument_probe );
		if ( 0 !== $report['totals']['errors'] || ! ran_wp_branch_updater_has_unreviewed_arguments( $xml, false ) || ! ran_wp_branch_updater_has_unreviewed_arguments( $xml, true ) ) {
			throw new RuntimeException( 'Ruleset argument bypass escaped its independent guard.' );
		}
	}
} finally {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this unique ruleset-control file.
	unlink( $root . '/' . $argument_probe );
}
/** Discover maintained PHP independently, including untracked files and extension-case variants. */
function ran_wp_branch_updater_maintained_php( string $root ): array {
	$iterator = new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		static function ( SplFileInfo $entry ) use ( $root ): bool {
			return ! $entry->isDir() || ! in_array( substr( $entry->getPathname(), strlen( $root ) + 1 ), array( '.git', 'vendor', 'node_modules', 'tests/build' ), true );
		}
	);
	$paths    = array();
	foreach ( new RecursiveIteratorIterator( $iterator ) as $entry ) {
		if ( $entry->isFile() && 0 === strcasecmp( $entry->getExtension(), 'php' ) ) {
			if ( 'php' !== $entry->getExtension() ) {
				throw new RuntimeException( 'Unsupported maintained PHP extension must not evade standards.' );
			}
			$paths[] = substr( $entry->getPathname(), strlen( $root ) + 1 );
		}
	}
	if ( array() === $paths ) {
		throw new RuntimeException( 'Maintained PHP discovery found no files.' );
	}
	return $paths;
}
$tracked = ran_wp_branch_updater_maintained_php( $root );
foreach ( $tracked as $tracked_path ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect maintained source comments without executing fixture bytes.
	if ( ran_wp_branch_updater_has_blanket_directive( (string) file_get_contents( $root . '/' . $tracked_path ), $tracked_path ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI failure identifies the exact local source path; this diagnostic is not rendered HTML.
		throw new RuntimeException( 'Maintained PHP contains a broad or unexplained suppression: ' . $tracked_path );
	}
}
$probe_path = 'standards-discovery-' . bin2hex( random_bytes( 8 ) ) . '.php';
try {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary first-party-root fixture proves the checker's real file discovery.
	if ( false === file_put_contents( $root . '/' . $probe_path, "<?php\n" ) ) {
		throw new RuntimeException( 'Could not create discovery probe.' );
	}
	foreach ( array( false, true ) as $exclude_probe ) {
		[, $discovery] = ran_wp_branch_updater_naming_report( $root, '', null, '.phpcs.xml', $exclude_probe ? array( '--ignore=' . $probe_path ) : array() );
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
// The real checker omits uppercase development extensions; independent discovery must reject them.
$case_probe = 'tests/standards-case-' . bin2hex( random_bytes( 8 ) ) . '.PHP';
try {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Create only this uniquely named inert development-discovery control.
	file_put_contents( $root . '/' . $case_probe, "<?php\n" );
	[, $discovery] = ran_wp_branch_updater_naming_report( $root, '', null );
	if ( isset( $discovery['files'][ $root . '/' . $case_probe ] ) ) {
		throw new RuntimeException( 'Reassess the uppercase-extension control after checker selection changes.' );
	}
	$caught = false;
	try {
		ran_wp_branch_updater_maintained_php( $root );
	} catch ( RuntimeException $error ) {
		$caught = 'Unsupported maintained PHP extension must not evade standards.' === $error->getMessage();
	}
	if ( ! $caught ) {
		throw new RuntimeException( 'Uppercase development PHP escaped independent discovery.' );
	}
} finally {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this uniquely named development-discovery control.
	unlink( $root . '/' . $case_probe );
}
$standalone = array_values( array_filter( $tracked, static fn( string $path ): bool => str_starts_with( $path, 'tests/' ) || str_starts_with( $path, 'scripts/' ) ) );
foreach ( array( false, true ) as $exclude_scripts ) {
	[, $discovery] = ran_wp_branch_updater_naming_report( $root, '', null, '.phpcs-cli-compat.xml', $exclude_scripts ? array( '--ignore=*/scripts/*' ) : array() );
	$selected      = array_map( static fn( string $file ): string => str_replace( $root . '/', '', $file ), array_keys( $discovery['files'] ) );
	$expected      = $exclude_scripts ? array_filter( $standalone, static fn( string $path ): bool => ! str_starts_with( $path, 'scripts/' ) ) : $standalone;
	if ( array() !== array_diff( $expected, $selected ) || array() !== array_diff( $selected, $expected ) || ( $exclude_scripts && count( $selected ) === count( $standalone ) ) ) {
		throw new RuntimeException( 'Standalone file discovery failed its positive or omitted-root control.' );
	}
}
foreach ( array( 'tests/compatibility-probe.php', 'scripts/compatibility-probe.php' ) as $compatibility_path ) {
	foreach ( array( '.phpcs.xml', '.phpcs-cli-compat.xml' ) as $compatibility_standard ) {
		[, $compatibility_report] = ran_wp_branch_updater_naming_report( $root, $compatibility_path, "<?php\narray_find( array(), static fn() => true );\n", $compatibility_standard );
		$diagnostics              = array_merge( ...array_column( array_values( $compatibility_report['files'] ), 'messages' ) );
		$caught_compatibility     = in_array( 'PHPCompatibility.FunctionUse.NewFunctions.array_findFound', array_column( $diagnostics, 'source' ), true );
		if ( ( '.phpcs-cli-compat.xml' === $compatibility_standard ) !== $caught_compatibility ) {
			throw new RuntimeException( 'Standalone compatibility must reject a WordPress-polyfilled future function.' );
		}
	}
}
echo "PASS maintained PHP discovery, excluded-root control, comment-only suppression checks and standalone compatibility\n";
