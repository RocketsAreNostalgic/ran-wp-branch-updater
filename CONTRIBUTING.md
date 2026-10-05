# Contributing

This package is in a pre-release development line. Keep every change suitable for public review. Use a Conventional Commit pull-request title so an ordinary squash merge uses that title as the subject consumed by Release Please, rather than the individual branch commit subjects. Release-significant changes must use a visible type from this repository's release configuration (including `deps:` for its production-dependency policy), or a supported breaking classification; see [RELEASING.md](RELEASING.md). A deliberately approved merge commit preserves individual commits, so their Conventional Commit subjects remain release inputs.

Use PHP 8.2 and Node.js 24.11.0. Install Composer dependencies, then run the repository gate:

```sh
composer install --no-interaction --prefer-dist
composer check
```

Focused commands use the shared PHP command contract:

| Command | Scope |
| --- | --- |
| `composer lint:syntax` | Parser checks for `src/`, `tests/`, `scripts/` and `bootstrap.php` |
| `composer standards` | Common PHPCS/WPCS/PHPCompatibility over all first-party PHP; full PHPCompatibility additionally over standalone `tests/` and `scripts/` |
| `composer standards:fix` | PHPCBF with both standards rulesets and their matching source paths |
| `composer analyze` | Blocking PHPStan level 5 over `src/` and installed `bootstrap.php` |
| `composer test` | All ordinary package and release-control contract tests |

`composer check` retains syntax, standards, analysis and test checks, plus strict
manifest validation. `standards:fix` is a manual source edit. The former
`composer lint` command is now `composer lint:syntax`.
`composer test:analysis-bootstrap` runs the actual analysis command against an
isolated bootstrap copy: the original must pass and an inserted undefined
function must produce the expected diagnostic in that file. This prevents the
installed bootstrap silently dropping out of analysis scope.
Individual `test:*` commands remain available for focused work. The installed
consumer proof below stays outside this aggregate because it creates and
resolves a separate Composer project; CI still requires it.

For changes to runtime PHP or Composer metadata, also prove a separate installed Composer consumer:

```sh
consumer="$(mktemp -d)"
trap 'rm -rf "$consumer"' EXIT
php scripts/prepare-consumer-fixture.php "$consumer" "$PWD"
composer --working-dir="$consumer" update --no-dev --no-interaction --prefer-dist --no-progress
BRANCH_UPDATER_CONSUMER_ROOT="$consumer" php tests/consumer-install.php
```

The external consumer proof verifies the installed PSR-4 package and bootstrap without relying on a package-private `vendor` directory.

## Architecture rules

Read [ARCHITECTURE.md](ARCHITECTURE.md) before changing production structure. Use the updater-family vocabulary according to responsibility:

- immutable requested/admitted facts are a **Declaration**;
- source-specific implementations are **Providers**;
- acquired controlled bytes are **Artifacts**;
- one synchronous ordered execution is a **Runner**;
- state spanning separate callbacks/events is a **Coordinator**;
- durable transition/history interfaces are **Journals**;
- durable object persistence is a **Store**.

Do not rename components merely to make the branch and release packages appear symmetrical. `deploy()` is the branch package's public terminal action; the release package's native-registration flow is different.

The branch updater's production namespaces are organised by responsibility beneath `RAN\WPBranchUpdater\V1`: `Archive`, `Contract`, `Persistence`, `Runtime`, and `WordPress`. Prefer one significant production object or interface per file and keep paths PSR-4 aligned.

The `AdmittedBranchRunner` sequence, mutation fence, provider/source-head authority, archive custody, persistence representation, terminal outcome strings, and WordPress ownership of installation are architectural invariants. A presentation refactor must not change them incidentally.

Production provider clients and host policies remain outside this package. Do not turn fixture transports into runtime providers or introduce a generic updater facade across release and branch domains.

Move code into `ran/updater-support` only when both updater packages implement the same non-trivial stable semantic rule and drift would create meaningful correctness/security risk without coupling domain-specific policy. Small conveniences stay local.

Add focused proof coverage when changing archive custody, mutation admission/fencing, recovery, persistence, provider head checks, or WordPress execution.

Do not commit credentials, tokens, private repository/site information, ZIPs, temporary files, logs, `vendor`, `node_modules`, or dependency caches. Use ordinary issues for non-sensitive work; follow [SECURITY.md](SECURITY.md) for vulnerabilities and [SUPPORT.md](SUPPORT.md) for support. See [RELEASING.md](RELEASING.md) before changing release automation or preparing a release.


## Naming migration boundary

All shipped owned methods enter `RANOwnedMethods`, including declarations
upstream WPCS skips because of inheritance. The connected cohort migrates the
last 32 method declarations, 10 properties and 26 parameters with all owned
callers. No owned-method naming deferrals remain. Variable naming is enforced
across all 36 source files and `bootstrap.php`; three precise external
`ZipArchive::$numFiles` exceptions preserve the PHP extension API.

`composer test:naming`, included in `composer test` and `composer check`, runs
unchanged/injected pairs through the actual repository rules without rewriting
tracked files. It covers inherited owned methods, public properties/parameters,
connected property uses and retained external exceptions. Check and fix use the
same scope. Tests and maintenance scripts also receive common standards and
full standalone PHP compatibility checks.

This is an intentional beta PHP API change, with no coexistence aliases.
MIGRATING.md records the direct replacements. Core #167 accepted the exact
producer map and delegated six consumer files for paired preparation. Branch
owns producer implementation; Core retains shared configuration/generated state,
combined qualification, released dependency adoption and landing order. Qualify
exact producer/consumer revisions together; a source overlay or producer PR is
not package publication or installed adoption. Old/new mixed tuples are not
supported. Persisted keys, wire fields, protocol strings, error semantics,
defaults/types/visibility, runtime behavior and support floors remain intact.

Yoda conditions, unused parameters, useless overrides, reserved parameter names
and exception-output checks are enforced across the shipped scope. Strict
comparisons use the shared standard's Yoda order. Line-specific exceptions retain
the required VCS callback signature, the stage-failure constructor's promoted
public property, and raw diagnostic messages, codes and chained exceptions.
These are diagnostic values, not rendered HTML; escaping them would change the
error contract. `composer test:naming` exercises the real PHPCS gate with clean
source and deliberately regressed copies, including every newly enabled rule.
UI/manual acceptance stays deferred; this source qualification does not
substitute for it.

## Standalone development PHP coverage

Common standards now select all **51 tracked PHP files**, up from the former
37 runtime files: 36 `src/` files, `bootstrap.php`, 13 test/fixture files and one
consumer-fixture script. Root discovery includes future maintained PHP; vendor,
node dependencies and generated `tests/build/` proof artifacts are excluded.
The standalone compatibility ruleset selects tests/scripts at the same PHP 8.2
floor with full PHPCompatibility, because these commands do not load WordPress
polyfills. Both `standards` and `standards:fix` execute both rulesets; the fixer
accepts PHPCBF's fixed-file exit status but propagates actual failures.

Standalone runner global-prefix exceptions are file-local. Native file/pipe and
process operations, CLI diagnostics, intentional empty rejection catches and
multiple fault-injection doubles have exact-code explanations at their relevant
lines. Ordinary owned helpers and fixture members use snake_case. Existing wire
keys, archive payloads, external reflection names and negative-control strings
remain unchanged; this is not another production/API migration.

The existing naming test compares the real checker's file report with Git's
tracked PHP population, including a temporary new root file. Excluding that
probe must produce the expected missing-file result. Standalone selection must
match all tracked tests/scripts; omitting the scripts root is a negative control.
Token-based line/block/doc-comment checks
reject blanket/legacy suppressions without mistaking fixture strings for active
directives. An `array_find` probe must fail standalone compatibility in each CLI
root while retaining the existing WordPress-profile distinction. Production
PHPStan remains blocking level 5; installed consumer and all behavioral proofs
remain required. This coverage pass does not claim acceptance of the separately
reviewed production native-operation exceptions.
