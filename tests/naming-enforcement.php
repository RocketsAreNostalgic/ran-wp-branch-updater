<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$method_code = 'RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase';
$variable_code = 'WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase';
$cases = [
    ['src/WordPress/WordPressUpdaterLock.php', 'phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'reviewed-exception WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'WordPress.Security.EscapeOutput.ExceptionNotEscaped'],
    ['src/WordPress/WordPressPackageExecutor.php', 'phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'reviewed-exception WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'WordPress.Security.EscapeOutput.ExceptionNotEscaped'],
    ['src/Persistence/FileAttemptStore.php', 'phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'reviewed-exception WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'WordPress.Security.EscapeOutput.ExceptionNotEscaped'],
    ['src/Archive/ArchiveValidator.php', 'phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'reviewed-exception WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'WordPress.Security.EscapeOutput.ExceptionNotEscaped'],
    ['src/Archive/PreparedArchive.php', 'phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'reviewed-exception WordPress.Security.EscapeOutput.ExceptionNotEscaped', 'WordPress.Security.EscapeOutput.ExceptionNotEscaped'],
    ['src/Archive/PreparedArchive.php', '1 !== $s[\'nlink\']', '$s[\'nlink\'] !== 1', 'WordPress.PHP.YodaConditions.NotYoda'],
    ['src/Persistence/FileAttemptStore.php', '( $r[\'id\'] ?? null ) !== $id', '$id !== ( $r[\'id\'] ?? null )', 'WordPress.PHP.YodaConditions.NotYoda'],

    ['src/WordPress/WordPressCorePackageExecutor.php', 'phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed', 'reviewed-exception Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed', 'Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed'],
    ['src/Runtime/AdmittedBranchStageFailure.php', 'phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found', 'reviewed-exception Generic.CodeAnalysis.UselessOverridingMethod.Found', 'Generic.CodeAnalysis.UselessOverridingMethod.Found'],
    ['src/WordPress/WordPressCorePackageExecutor.php', '$parent_path', '$parent', 'Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound'],

    ['bootstrap.php', '$archive_directory', '$archiveDirectory', $variable_code],
    ['bootstrap.php', '$maximum_artifact_bytes', '$maximumArtifactBytes', $variable_code],
    ['src/Archive/ArchiveValidator.php', 'function verify_entry_contents(', 'function verifyEntryContents(', $method_code],
    ['src/Persistence/FileAttemptStore.php', 'function recover_stopped(', 'function recoverStopped(', $method_code],
    ['src/Runtime/CorePackageExecutionResult.php', 'function is_successful(', 'function isSuccessful(', $method_code],
    ['src/WordPress/WordPressCorePackageExecutor.php', 'function execute_install(', 'function executeInstall(', $method_code],
    ['src/WordPress/WordPressUpdaterLock.php', 'function contention_failure(', 'function contentionFailure(', $method_code],
    ['src/Persistence/FileAttemptJournal.php', 'function record_resolved_ref(', 'function recordResolvedRef(', $method_code],
    ['src/Persistence/FileMutationLock.php', 'function run(', 'function regressionProbe(', $method_code],
    ['src/Archive/PackageSubdirectory.php', '$provider_slug', '$providerSlug', $variable_code],
    ['src/Persistence/FileAttemptJournal.php', '$attempt_id', '$attemptId', $variable_code],
];

/** Run the real repository rules against an in-memory source copy at its actual path. */
function naming_report(string $root, string $path, string $source): array {
    $process = proc_open(
        [PHP_BINARY, $root . '/vendor/bin/phpcs', '--standard=' . $root . '/.phpcs.xml', '--report=json', '-q', '--no-colors', '--stdin-path=' . $root . '/' . $path, '-'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not run the repository naming gate.');
    }
    fwrite($pipes[0], $source);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    if (!isset($report['files'], $report['totals'])) {
        throw new RuntimeException('Invalid PHPCS report: ' . $error);
    }
    return [$status, $report];
}

foreach ($cases as [$path, $before, $after, $expected]) {
    $source = file_get_contents($root . '/' . $path);
    if (false === $source || !str_contains($source, $before)) {
        throw new RuntimeException('Naming fixture anchor missing: ' . $path);
    }
    [$status, $report] = naming_report($root, $path, $source);
    if (0 !== $status || 0 !== $report['totals']['errors'] || 0 !== $report['totals']['warnings']) {
        throw new RuntimeException('Unchanged source did not pass: ' . $path);
    }
    [$status, $report] = naming_report($root, $path, str_replace($before, $after, $source));
    $caught = false;
    foreach ($report['files'] as $file) {
        foreach ($file['messages'] as $message) {
            $caught = $caught || $message['source'] === $expected;
        }
    }
    if (0 === $status || !$caught) {
        throw new RuntimeException('Naming regression escaped its expected check: ' . $path);
    }
}

echo 'PASS naming and standards enforcement: ' . count($cases) . " positive/negative pairs, including inherited owned methods\n";
