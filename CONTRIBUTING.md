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
| `composer analyze` | Blocking PHPStan level 5 over both isolated production and all-maintained PHP profiles |
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

Common standards now select all **52 tracked PHP files**, up from the former
37 runtime files: 36 `src/` files, `bootstrap.php`, 14 test/fixture files and one
consumer-fixture script. Root discovery includes future maintained PHP; vendor,
node dependencies and generated `tests/build/` proof artifacts are excluded.
The standalone compatibility ruleset selects tests/scripts at the same PHP 8.2
floor with full PHPCompatibility, because these commands do not load WordPress
polyfills. Both `standards` and `standards:fix` execute both rulesets; the fixer
accepts PHPCBF's fixed-file exit status but propagates actual failures.

Standalone runner variable-prefix exemptions are file-local; owned fixture classes and functions use the repository prefix. Native file/pipe and
process operations, CLI diagnostics, intentional empty rejection catches and
multiple fault-injection doubles have exact-code explanations at their relevant
lines. Ordinary owned helpers and fixture members use snake_case. Existing wire
keys, archive payloads, external reflection names and negative-control strings
remain unchanged; this is not another production/API migration.

The existing naming test compares the real checker's file report with independently discovered
maintained PHP, including untracked files and extension-case rejection, including a temporary new root file. Excluding that
probe must produce the expected missing-file result. Standalone selection must
match all tracked tests/scripts; omitting the scripts root is a negative control.
Token-based line/block/doc-comment checks
reject blanket/legacy suppressions without mistaking fixture strings for active
directives. An `array_find` probe must fail standalone compatibility in each CLI
root while retaining the existing WordPress-profile distinction. Production
PHPStan remains blocking level 5; installed consumer and all behavioral proofs
remain required. Native operation exemptions are narrowed below.

## Native-operation exceptions and remaining profile scope

The retained native operations use exact diagnostic codes on their operation
lines, so unrelated calls in the same file remain checked:

| Source | Required native behavior | Existing evidence |
| --- | --- | --- |
| `Archive/ArchiveValidator.php` | Bounded ZIP-entry reads, CRC/expanded-size validation and stream closure | Archive and prepared-archive contracts |
| `Archive/PreparedArchive.php` | Private native permissions, device/inode custody, identity-checked deletion; quiet missing-path `lstat` rejection | Prepared-archive, archive and runner contracts |
| `Persistence/FileAttemptStore.php` | Descriptor locking, JSON throwing serialization, sibling-file atomic replacement and validated readback | Journal invariants, runner and hard-stop/recovery contracts |
| `Persistence/FileMutationLock.php` | Keep the exact `flock` descriptor through the callback and release it in order | Runner and hard-stop contracts |

`test:naming` also injects an unrelated native JSON call into each of these four
source files and requires the real checker to reject it. A separate suppressed
expression probe in `PreparedArchive` must fail NoSilencedErrors. These controls
protect against restoring either former file-wide exemption. Executable
operations, error suppression and failure ordering are unchanged.


## Inclusive analysis and suppression boundaries

Production PHP is included by default from the repository root. Its isolated
profile exempts root `tests/`, `scripts/`, `vendor/`, `node_modules/` and Git metadata:
development fixtures, maintenance tooling, external dependencies and generated
repository metadata are distinct roles, not an inventory of product files.
A production `src/tests/` directory remains included. The independent filesystem
inventory is compared with locked PHPStan's effective file finder. The existing
bootstrap regression also proves that new untracked root/nested/split contracts
produce real analysis diagnostics. Uppercase extensions and PHP entrypoints outside lowercase `.php` fail for an explicit scope decision rather than disappearing.
The 37 current production PHP files remain clean at required level 5. The second,
all-maintained profile also includes tests and scripts automatically, covering all
52 maintained PHP files at level 5. Its only exclusions are root `vendor/`,
`node_modules/` and Git metadata. Both profiles run through `composer analyze`;
the maintained controls additionally prove diagnostics in new root, test and
script PHP files and protect the reviewed analysis-exemption inventory.

The comment-token guard rejects standards/categories/sniffs, unexplained ignores,
case variants, file-ignore prefixes, legacy directives and inline configuration
changes. Exceptions must name an exact diagnostic and explain the invariant.
The sole persistent development exemption is the process-local variable prefix
code at line 2; new functions/classes/constants remain checked. A foreign
`WP_Error` declaration retains one exact annotation. The real checker controls
prove both the formerly accepted bypass and the adjacent unsuppressed diagnostic.
These checks enforce scope, not human acceptance of an arbitrary new explanation:
new exemptions still require independent review and accepted #65/#128 disposition.

The standards inventory also discovers untracked PHP recursively and rejects
uppercase development extensions which the locked checker otherwise omits.
Its dependency/generated-role exemptions are rooted, so nested product paths
cannot inherit them.

Both standards rulesets retain their exact reviewed command arguments. Unknown
arguments fail closed: locked-checker controls demonstrate that XML `exclude`
and `sniffs` arguments can otherwise hide required diagnostics.

Effective analysis coverage mirrors PHPStan's post-discovery stub-file filtering;
reclassifying maintained production PHP as a stub fails the coverage gate.

The bounded header check recognizes ordinary/uppercase PHP open tags and short
echo tags, with optional shebang, including alternate extensions such as `.inc`.
