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
| `composer standards` | PHPCS/WPCS/PHPCompatibility over shipped `src/` and `bootstrap.php` |
| `composer standards:fix` | PHPCBF with the same rules and source paths |
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

All shipped owned methods now enter `RANOwnedMethods`, including declarations
upstream WPCS skips because of inheritance. The 32 remaining camelCase methods
have individual, temporary `phpcs:ignore` annotations linked to #59 / Core #167.
They are connected migration debt, not public-visibility exceptions. Variable
naming is enforced on the 21 fully audited files listed in `.phpcs.xml`.
`composer test:naming`, included in `composer test` and `composer check`, runs
nine unchanged/injected pairs through the actual repository rules without
rewriting tracked files. It covers private/public/protected and inherited owned
methods plus variable regressions. Both check and fix retain identical scope.

The completed package-local cohort includes all 40 previously camelCase private
methods, 14 public/protected methods with no consumers in the audited Core,
GitHub provider, Bitbucket, Release, Support and Migrator source trees, private
members and local variables, plus their callers, reflection target and API guide.
Public method replacements use snake_case with no coexistence aliases:
`for_standalone`, `attempt_id`, `is_successful`, `was_restored_by_wordpress`,
`get_failure`, `record_installed`, `execute_core`, `installed_facts`,
`current_token`, `contention_failure`, `recover_stopped`, `normalize_slug`,
`installation_slug`, and `deployment_slug`.

Remaining connected methods:

| Producer | Deferred declarations |
| --- | --- |
| `ArchiveOffer` | `verifyCurrentHead` |
| `PreparedArchive` | `downloadAndValidate`, `getPath`, `getExpectedVersion`, `expandedBytes`, `assertUnchanged` |
| `PreparedArchiveArtifact`, `AdmittedBranchArtifact` | `resolvedRef`, `expectedVersion`, `assertUnchanged` |
| `AdmittedArchiveSource`, `ProviderArchiveSource` | `verifyCurrentHead` |
| `AdmittedTargetFacts`, `StandaloneTargetFacts` | `assertMutationAllowed`, `frozenTarget`, `maintenanceActive`, `recheckManaged`, `baselineNow` |
| `PreparedPackageArtifact` | `getPath`, `getExpectedVersion`, `assertUnchanged` |
| `BranchUpdater` | `forAdmittedAttempt` |
| `WordPressCorePackageExecutor` | `installPlugin`, `installTheme`, `updatePlugin`, `updateTheme` |

The same boundary includes public declaration/offer properties, stage-failure
`outcomeCode`, `ArchiveOffer` constructor parameters (including promoted
`verifyHead`), archive preparation's public arguments, `frozenTarget`'s
`deferExisting`, WordPress executor arguments, `BranchUpdater::plugin/theme`
arguments and `BranchDeployment::deploy`'s `expectedCommit`. Bootstrap's
`archiveDirectory` and `maximumArtifactBytes` named arguments remain an explicit
standalone entry-point handoff. Internal users of those property spellings remain
outside whole-file variable enforcement until the connected declarations move.

Branch #59 owns the producer cohort; Core #167 owns host adapters, coordinator,
overrides and test doubles. Agree ownership and integration order before editing
that boundary; certify exact producer/consumer revisions together, then adopt a
real reviewed released dependency tuple and root lock. The producer PR alone is
not consumer adoption. Persisted keys, wire fields, protocol strings, external
WordPress/PHP names, runtime behavior, support floors and dependency versions must
remain intact. No camelCase declaration in this inventory is externally imposed.

The separate condition, unused-parameter, overriding-method, reserved-parameter
and exception-output suppressions in `.phpcs.xml` are not removed by naming work.
Their retained compatibility/runtime reasons require an individual standards
review under #59 before broader standards acceptance. UI/manual acceptance stays
deferred; this source qualification does not substitute for it.
