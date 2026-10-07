# Agent guidance

This is the independent `ran/wp-branch-updater` Composer library. Keep committed files suitable for public distribution and preserve the `RAN\WPBranchUpdater\V1` production namespace and PSR-4 path alignment.

## RAN quality profile

This repository uses the RAN `php-library` quality profile. PHP coding and compatibility ancestry comes from `ran/coding-standards` through `RANWordPressLibrary`; the tracked Composer lock binds the published v1.0.0 release
under the `^1.0` development constraint. The additional `RANOwnedMethods`
check covers every shipped declaration, including inherited classes, with no
remaining owned-method migration deferrals. Variable naming covers all shipped
source and `bootstrap.php`; precise ZipArchive::$numFiles exceptions preserve
that external API. `composer test:naming` proves method, property, parameter,
variable and standards enforcement. The connected beta API is intentionally
breaking: prepare and qualify matching Core consumers before released adoption.
See MIGRATING.md and the accepted #59 / Core #167 handoff.
Yoda conditions, unused parameters, useless overrides, reserved parameter names
and exception-output checks are enforced. Keep justified callback, promoted-property
and diagnostic exceptions restricted to their annotated lines.

Keep the package's actual contract local: PHP `^8.2`, the `RAN\WPBranchUpdater\V1` namespace, source paths, updater-specific tests, and justified runtime/security exceptions. Do not add a WordPress-version floor unless this package explicitly claims one, and do not copy shared rules back into local configuration.

`composer check` is the ordinary deterministic PHP quality contract. It must retain strict Composer validation, shared PHPCS/PHPCompatibility checks, PHPStan, the existing architecture/archive/runner/journal/error-code and release workflow/classification tests, and the PHP syntax sweep. PHPCS governs all 52 tracked first-party PHP files, including the 14 test/fixture files and one maintenance script. The root ruleset discovers future PHP outside the earlier shipped roots; dependencies and generated proof output remain excluded. Standalone tests/scripts additionally use full PHPCompatibility through `.phpcs-cli-compat.xml`, without WordPress polyfill allowances. PHPStan checks both isolated production and all-maintained root profiles at level 5. Only root dependency directories are exempt from the maintained profile; tests and scripts are analyzed automatically. The effective file finder must match independently discovered PHP for each profile. New untracked root and nested product PHP are included automatically. The ordinary
`test:analysis-bootstrap` regression exercises the real Composer analysis command
with clean and deliberately invalid isolated bootstrap copies. PHPStan starts at level 5 so adoption adds semantic analysis without redefining the package's existing public array contracts; raising the level and adding array-shape/generic contracts is a separate reviewed API-quality change. The installed no-dev consumer proof remains a separate required CI lane.

`composer lint:syntax` is the focused parser sweep; `composer standards` / `composer standards:fix` run PHPCS/PHPCBF; `composer analyze` retains blocking level 5; `composer test` aggregates the existing ordinary tests. Analysis runs both production and maintained profiles; both standards commands apply the common and standalone-compatibility rulesets.

Read [ARCHITECTURE.md](ARCHITECTURE.md) before changing production structure. Preserve the updater-family vocabulary by responsibility: Declaration, Provider, Adapter, Artifact, Runner, Coordinator, Journal, Store, State, Archive, Contract, Runtime, and WordPress. Do not create cosmetic symmetry with the release updater where runtime responsibilities differ.

- `BranchUpdater` declares targets; `BranchDeployment` is the target handle; `BranchDeploymentDeclaration` is immutable execution data; `deploy()` remains the public mutation verb.
- `AdmittedBranchRunner` owns the security-sensitive synchronous sequence. Do not reorder baseline acquisition, source/artifact rechecks, the mutation fence, WordPress Core execution, postcondition verification, cleanup, or terminal journaling.
- Providers own source-specific access; hosts own credentials, admission/deduplication, scheduling/webhooks, and recovery policy; WordPress Core owns installation.
- Keep archive custody/validation under `Archive`, stable interfaces under `Contract`, durable local state under `Persistence`, lifecycle orchestration under `Runtime`, and WordPress-specific adapters under `WordPress`.
- Move a rule into `ran/updater-support` only when it is semantically identical across updater packages, non-trivial, stable, drift-sensitive, and domain-neutral.
- Run `composer check` and the installed no-dev consumer proof before committing runtime or Composer changes. Preserve mutation-fence, custody, durability, terminal outcome, and diagnostic behavior.
- Keep fake transports and executors in development-only test support.
- Before changing release automation or preparing a release, read `RELEASING.md`.
- Every PR needs independent review against its exact base and head SHAs.
- Merging requires explicit owner authorization of the exact PR and merge method.
- Keep credentials, local logs, vendor files, temporary transformation artifacts, and internal planning out of commits.

## Development PHP profile

Keep CLI fixtures under the common WordPress-derived formatting and owned naming
rules. Source-local global-prefix exceptions apply only to standalone runners;
precise operation/diagnostic exceptions preserve native fixture behavior. Fixture
wire keys, embedded archive PHP, foreign reflection APIs and deliberate invalid
source strings are contracts, not automatic rename targets. `test:naming` checks
actual PHPCS discovery against independently discovered maintained PHP, a new root-file control and its
excluded negative, comment-only blanket/legacy suppression controls, and both
standalone compatibility roots. Do not recreate syntax-only developer coverage.
Native operation exemptions are exact-code and occurrence-local. Suppression selectors must name the full diagnostic with a reason. Persistent disables are limited to the process-variable prefix code at line 2 in root tests/scripts; owned declarations remain checked. Configuration changes and new reasons require review, not merely green checks.

## Blacksmith AI prohibition

Blacksmith is approved only as GitHub Actions runner infrastructure where a
repository workflow explicitly selects a Blacksmith runner.

- Never invoke, delegate work to, tag, enable, or otherwise use Blacksmith
  [code]smith, `@codesmith-bot`, Blacksmith Autofix, Blacksmith CI Tuning,
  Blacksmith Testbox agents, or any other Blacksmith AI/agent feature.
- Do not trigger "Enable autofix", ask [code]smith to investigate or repair CI,
  or call Blacksmith agent/MCP/CLI/API features that perform AI inference.
- If CI fails, inspect GitHub Actions logs directly and diagnose or fix the
  failure without delegating it to Blacksmith AI.
- This is a cost-control requirement. Do not override it for convenience, CI
  failures, review comments, or suggestions presented by GitHub or Blacksmith
  UI.

## Maintained analysis exceptions

`phpstan-maintained.neon` discovers all maintained PHP, including new test and
maintenance-script roots. `test:analysis-bootstrap` proves actual diagnostics in
new root, test and script files, rejects level/scope reductions and new blanket or
case-variant ignores, and checks an unexcepted neighbouring PHPStan API call.
The coverage guard inventories exact source-local analysis ignores. Three lines
use locked PHPStan configuration/file-discovery internals to mirror CLI selection;
these are tooling-version warnings, not semantic error suppressions. Preserve the
existing PreparedArchive custody-boundary symlink recheck exception. Changes to
this inventory require review; green checks alone do not authorize exemptions.
