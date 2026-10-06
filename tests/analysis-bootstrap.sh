#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php "$root/tests/analysis-coverage.php"
php "$root/tests/analysis-coverage.php" "$root" --maintained
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT

# Keep configuration paths relative to an isolated copy; never mutate runtime PHP.
cp "$root/composer.json" "$root/composer.lock" "$root/phpstan.neon" "$root/phpstan-maintained.neon" "$root/bootstrap.php" "$fixture/"
ln -s "$root/vendor" "$fixture/vendor"
cp -R "$root/src" "$fixture/src"

analyze() {
    composer --no-plugins --no-interaction --working-dir="$fixture" "${analysis_command:-analyze:production}" -- --error-format=json
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

# Root-inclusive selection must pick up new files without editing a named file list.
cp "$root/bootstrap.php" "$fixture/bootstrap.php"
mkdir -p "$fixture/new product/contracts" "$fixture/src/tests"
for path in root-contract.php 'new product/contracts/split.php' src/tests/runtime-contract.php; do
    printf '<?php\nran_branch_new_contract_missing();\n' > "$fixture/$path"
    php "$root/tests/analysis-coverage.php" "$fixture"
    if analyze > "$fixture/new.json" 2> "$fixture/new.log"; then
        echo 'New production PHP escaped actual analysis.' >&2; exit 1
    fi
    php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);foreach($r["files"][realpath($argv[2])]["messages"]??[] as $m){if(($m["identifier"]??"")==="function.notFound"&&str_contains($m["message"],"ran_branch_new_contract_missing")){exit(0);}}exit(1);' "$fixture/new.json" "$fixture/$path"
    rm "$fixture/$path"
done
# Configuration reductions fail even when today's source would still be covered.
sed -i 's/- \.$/- src/' "$fixture/phpstan.neon"
if php "$root/tests/analysis-coverage.php" "$fixture" > "$fixture/guard.log" 2>&1; then
    echo 'Narrowed analysis scope escaped.' >&2; exit 1
fi
grep -q 'Review inclusive analysis scope' "$fixture/guard.log"
cp "$root/phpstan.neon" "$fixture/phpstan.neon"
printf '<?php\n' > "$fixture/NewContract.PHP"
if php "$root/tests/analysis-coverage.php" "$fixture" > "$fixture/guard.log" 2>&1; then exit 1; fi
grep -q 'Unsupported PHP extension' "$fixture/guard.log"
rm "$fixture/NewContract.PHP"
for header in '<?php' '<?PHP' '<?='; do
    for path in contract-tool alternate-contract.inc; do
        printf '#!/usr/bin/env php\n%s\n' "$header" > "$fixture/$path"
        if php "$root/tests/analysis-coverage.php" "$fixture" > "$fixture/guard.log" 2>&1; then exit 1; fi
        grep -q 'PHP outside lowercase .php' "$fixture/guard.log"
        rm "$fixture/$path"
    done
done
# Explicit role exemptions exclude only repository-root development directories.
mkdir "$fixture/tests" "$fixture/scripts"
printf '<?php\n' > "$fixture/tests/development.php"
printf '<?php\n' > "$fixture/scripts/development.php"
php "$root/tests/analysis-coverage.php" "$fixture"
printf '\tstubFiles:\n\t\t- bootstrap.php\n' >> "$fixture/phpstan.neon"
if php "$root/tests/analysis-coverage.php" "$fixture" > "$fixture/guard.log" 2>&1; then
    echo 'Production source reclassified as stub escaped effective selection.' >&2; exit 1
fi
grep -q 'Effective PHPStan selection differs' "$fixture/guard.log"
cp "$root/phpstan.neon" "$fixture/phpstan.neon"
echo 'Inclusive production analysis, untracked split-file, anchored-role and unsupported-extension controls passed.'

# Maintained analysis must cover future development files as well as product roots.
analysis_command=analyze:maintained
cp -R "$root/tests/." "$fixture/tests/"
cp -R "$root/scripts/." "$fixture/scripts/"
php "$root/tests/analysis-coverage.php" "$fixture" --maintained
analyze > "$fixture/maintained-clean.json" 2> "$fixture/maintained-clean.log" || {
    cat "$fixture/maintained-clean.log" "$fixture/maintained-clean.json" >&2; exit 1;
}
for path in tests/new-contract.php scripts/new-tool.php root-contract.php; do
    printf '<?php\nran_branch_maintained_probe_missing();\n' > "$fixture/$path"
    php "$root/tests/analysis-coverage.php" "$fixture" --maintained
    status=0
    analyze > "$fixture/maintained-invalid.json" 2> "$fixture/maintained-invalid.log" || status=$?
    test "$status" -eq 1
    php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);foreach($r["files"][realpath($argv[2])]["messages"]??[] as $m){if(($m["identifier"]??"")==="function.notFound"&&str_contains($m["message"],"ran_branch_maintained_probe_missing")){exit(0);}}exit(1);' "$fixture/maintained-invalid.json" "$fixture/$path"
    rm "$fixture/$path"
done
# The locked internal-tool exception must not cover a neighbouring API call.
printf '<?php\n(new PHPStan\\DependencyInjection\\NeonAdapter(array()))->load("missing");\n' > "$fixture/tests/outside-exception.php"
status=0
analyze > "$fixture/outside.json" 2> "$fixture/outside.log" || status=$?
test "$status" -eq 1
php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);foreach($r["files"][realpath($argv[2])]["messages"]??[] as $m){if(($m["identifier"]??"")==="phpstanApi.constructor"){exit(0);}}exit(1);' "$fixture/outside.json" "$fixture/tests/outside-exception.php"
rm "$fixture/tests/outside-exception.php"
for directive in '@phpstan-ignore-next-line' '@PHPSTAN-IGNORE phpstanApi.constructor'; do
    printf '<?php\n// %s\n' "$directive" > "$fixture/tests/unreviewed.php"
    if php "$root/tests/analysis-coverage.php" "$fixture" --maintained > "$fixture/guard.log" 2>&1; then exit 1; fi
    grep -q 'Review new or changed analysis exemptions' "$fixture/guard.log"
    rm "$fixture/tests/unreviewed.php"
done
# An unchanged reason cannot be moved onto another occurrence in the same file.
cp "$fixture/tests/analysis-coverage.php" "$fixture/coverage-original.txt"
php -r '$p=$argv[1];$s=file_get_contents($p);$s=str_replace("// @phpstan-ignore phpstanApi.method (Use the locked CLI exclusion predicate rather than an approximation.)\n", "// @phpstan-ignore phpstanApi.method (Use the locked CLI exclusion predicate rather than an approximation.)\n\t(new PHPStan\\DependencyInjection\\NeonAdapter(array()))->load(null);\n",$s);file_put_contents($p,$s);' "$fixture/tests/analysis-coverage.php"
if php "$root/tests/analysis-coverage.php" "$fixture" --maintained > "$fixture/guard.log" 2>&1; then exit 1; fi
grep -q 'Review new or changed analysis exemptions' "$fixture/guard.log"
mv "$fixture/coverage-original.txt" "$fixture/tests/analysis-coverage.php"
for change in level exclusion ignore; do
    cp "$root/phpstan-maintained.neon" "$fixture/phpstan-maintained.neon"
    case "$change" in
        level) sed -i 's/level: 5/level: 4/' "$fixture/phpstan-maintained.neon" ;;
        exclusion) printf '\t\t\t- tests/*\n' >> "$fixture/phpstan-maintained.neon" ;;
        ignore) printf '\tignoreErrors: []\n' >> "$fixture/phpstan-maintained.neon" ;;
    esac
    if php "$root/tests/analysis-coverage.php" "$fixture" --maintained > "$fixture/guard.log" 2>&1; then exit 1; fi
    grep -q 'Review inclusive analysis scope' "$fixture/guard.log"
done
echo 'Maintained root, test/script discovery, minimum level and exact internal-API exemption controls passed.'
