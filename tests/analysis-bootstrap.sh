#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT

# Keep configuration paths relative to an isolated copy; never mutate runtime PHP.
cp "$root/composer.json" "$root/composer.lock" "$root/phpstan.neon" "$root/bootstrap.php" "$fixture/"
ln -s "$root/vendor" "$fixture/vendor"
ln -s "$root/src" "$fixture/src"

analyze() {
    composer --no-plugins --no-interaction --working-dir="$fixture" analyze -- --error-format=json
}

if ! analyze > "$fixture/clean.json" 2> "$fixture/clean.log"; then
    cat "$fixture/clean.log" "$fixture/clean.json" >&2
    echo 'Unmodified bootstrap analysis failed.' >&2
    exit 1
fi

# Insert before the returned closure so the probe is reachable to the analyzer.
php -r '
$path = $argv[1];
$source = file_get_contents($path);
$source = str_replace(
    "return static function (",
    "ran_branch_analysis_probe_missing();\n\nreturn static function (",
    $source,
    $count
);
if ($count !== 1 || file_put_contents($path, $source) === false) {
    fwrite(STDERR, "Cannot prepare bootstrap analysis probe.\n");
    exit(1);
}
' "$fixture/bootstrap.php"

status=0
analyze > "$fixture/invalid.json" 2> "$fixture/invalid.log" || status=$?
if [[ "$status" -ne 1 ]]; then
    cat "$fixture/invalid.log" "$fixture/invalid.json" >&2
    echo "Expected bootstrap analysis failure, received $status." >&2
    exit 1
fi

# A tooling/configuration failure is not evidence that bootstrap.php was analyzed.
php -r '
$report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$path = realpath($argv[2]);
foreach ($report["files"][$path]["messages"] ?? [] as $message) {
    if (
        ($message["identifier"] ?? "") === "function.notFound"
        && str_contains($message["message"], "ran_branch_analysis_probe_missing")
    ) {
        exit(0);
    }
}
fwrite(STDERR, "Missing expected bootstrap analysis diagnostic.\n");
exit(1);
' "$fixture/invalid.json" "$fixture/bootstrap.php"

echo 'Bootstrap analysis positive/negative contract passed.'
