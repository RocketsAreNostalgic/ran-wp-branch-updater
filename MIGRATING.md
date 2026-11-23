# Migrating the beta API

## Admitted execution only

The standalone composition has been retired. Load the consumer's Composer
autoloader and use `BranchUpdater::for_admitted_attempt()` with a host-owned
declaration, journal, archive source, target facts, executor and lock. The
[admitted API example](API.md#execute-an-admitted-host-attempt) shows the entry
point. Reconcile unfinished file-journal attempts with the version that wrote
them before switching. The host owns journal storage and recovery.

Removed surfaces include `bootstrap.php`, `BranchUpdater::for_standalone()`,
`BranchProvider`, `PackageExecutor`, `FileAttemptStore`, `FileAttemptJournal`,
`FileMutationLock`, `ProviderArchiveSource`, `StandaloneBranchRunner`,
`StandalonePackageExecutor`, `StandaloneTargetFacts` and `WordPressPackageExecutor`.
Use `CorePackageExecutionResult::get_failure()` to inspect `WORDPRESS_RESTORED`.
The unused `RUNTIME_UNSUPPORTED` and `CORE_RUNTIME_UNSUPPORTED` cases have been
retired.

Admitted API `4` now treats the host's exact `selected_commit` as rollback intent.
See [selected-commit rollbacks](API.md#selected-commit-rollbacks).

## Namespace and class migration

The architectural presentation normalisation makes a hard beta cut. There are no compatibility aliases for the previous namespace or class names.

## Namespace

| Before | After |
| --- | --- |
| `RAN\BranchDeployment` | `RAN\WPBranchUpdater\V1` |

Production classes are now organised beneath domain subnamespaces: `Runtime`, `Archive`, `Contract`, `Persistence`, and `WordPress`. The root change alone is therefore not sufficient for types that kept their class/interface name but moved into a domain namespace.

## Renamed public classes

| Before | After |
| --- | --- |
| `RAN\BranchDeployment\BranchDeploymentPackage` | `RAN\WPBranchUpdater\V1\Runtime\BranchUpdater` |
| `RAN\BranchDeployment\PendingDeployment` | `RAN\WPBranchUpdater\V1\Runtime\BranchDeployment` |
| `RAN\BranchDeployment\Deployment` | `RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration` |
| `RAN\BranchDeployment\CorePackageExecutor` | `RAN\WPBranchUpdater\V1\WordPress\WordPressCorePackageExecutor` |

## Unchanged names that moved into domain namespaces

If a host imported one of the previous flat public types, update the full FQCN rather than replacing only the namespace root.

| Before | After |
| --- | --- |
| `RAN\BranchDeployment\ArchiveOffer` | `RAN\WPBranchUpdater\V1\Archive\ArchiveOffer` |
| `RAN\BranchDeployment\ArchiveValidator` | `RAN\WPBranchUpdater\V1\Archive\ArchiveValidator` |
| `RAN\BranchDeployment\PackageSubdirectory` | `RAN\WPBranchUpdater\V1\Archive\PackageSubdirectory` |
| `RAN\BranchDeployment\PreparedArchive` | `RAN\WPBranchUpdater\V1\Archive\PreparedArchive` |
| `RAN\BranchDeployment\AdmittedArchiveSource` | `RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource` |
| `RAN\BranchDeployment\AdmittedAttemptJournal` | `RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal` |
| `RAN\BranchDeployment\AdmittedBranchArtifact` | `RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact` |
| `RAN\BranchDeployment\AdmittedPackageExecutor` | `RAN\WPBranchUpdater\V1\Contract\AdmittedPackageExecutor` |
| `RAN\BranchDeployment\AdmittedTargetFacts` | `RAN\WPBranchUpdater\V1\Contract\AdmittedTargetFacts` |
| `RAN\BranchDeployment\MutationLock` | `RAN\WPBranchUpdater\V1\Contract\MutationLock` |
| `RAN\BranchDeployment\PreparedPackageArtifact` | `RAN\WPBranchUpdater\V1\Contract\PreparedPackageArtifact` |
| `RAN\BranchDeployment\BranchDeploymentJournalFailure` | `RAN\WPBranchUpdater\V1\Persistence\BranchDeploymentJournalFailure` |
| `RAN\BranchDeployment\AdmittedBranchDurabilityFailure` | `RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchDurabilityFailure` |
| `RAN\BranchDeployment\AdmittedBranchRunner` | `RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchRunner` |
| `RAN\BranchDeployment\AdmittedBranchStageFailure` | `RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchStageFailure` |
| `RAN\BranchDeployment\BranchDeploymentLockReleaseFailure` | `RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockReleaseFailure` |
| `RAN\BranchDeployment\BranchDeploymentLockStorageFailure` | `RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockStorageFailure` |
| `RAN\BranchDeployment\CorePackageExecutionFailure` | `RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionFailure` |
| `RAN\BranchDeployment\CorePackageExecutionResult` | `RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult` |
| `RAN\BranchDeployment\InstalledPackageIdentifier` | `RAN\WPBranchUpdater\V1\WordPress\InstalledPackageIdentifier` |
| `RAN\BranchDeployment\WordPressUpdaterLock` | `RAN\WPBranchUpdater\V1\WordPress\WordPressUpdaterLock` |

These are naming and source-organisation changes. `deploy()` remains the public terminal action, and existing terminal outcome strings remain unchanged.

## Direct admitted archive owner

`Archive\PreparedArchive` implements `Contract\AdmittedBranchArtifact` directly. Return the validated archive owner from the host's `AdmittedArchiveSource::prepare()` and pass that same owner to the Core executor.

`resolved_ref()` and `expected_version()` return the existing immutable metadata; they remain readable after cleanup. Custody checks, local byte access and cleanup stay on the same object. A cloned owner does not share cleanup state: after either copy deletes the file, the other's custody checks fail. Hosts must retain one owner per acquired archive rather than relying on cloned wrappers sharing cleanup. Update concrete type checks and reflection expectations alongside callers.

Core hosts that constructed or consumed the wrapper must update their archive adapter and adopt the matching immutable released Composer dependency as one qualified tuple. The direct adapter cannot run against the earlier package whose `PreparedArchive` lacks the admitted interface. Publishing this library alone does not qualify an unchanged Core installation; coordinate the host's released dependency adoption before upgrading controlled sites.

## Autoloading

Production loading now follows PSR-4:

```text
RAN\WPBranchUpdater\V1\ → src/
```

Consumers should load their normal Composer autoloader. The package does not provide or require a private production autoloader.

## No compatibility layer

The previous beta namespace and class identities are intentionally unsupported after this cut. Update imports and type hints as one migration rather than relying on aliases or shims.

## Connected snake_case API cut

This candidate completes the owned PHP naming migration. It deliberately breaks
old method, property and named-argument spellings during beta. Update host
implementations, overrides, callbacks, callers and named arguments as one
coordinated tuple. There are no aliases; mixed old/new packages are unsupported.
Publication and Core dependency-lock adoption remain separately reviewed steps.

The following mappings apply only to this package's owned declarations; do not
rename similarly spelled WordPress, provider or Core-owned receiver contracts.

| Previous PHP identifier | Replacement |
| --- | --- |
| `verifyCurrentHead` | `verify_current_head` |
| `downloadAndValidate` | `download_and_validate` |
| `getPath` | `get_path` |
| `getExpectedVersion` | `get_expected_version` |
| `expandedBytes` | `expanded_bytes` |
| `assertUnchanged` | `assert_unchanged` |
| `resolvedRef` | `resolved_ref` |
| `expectedVersion` | `expected_version` |
| `assertMutationAllowed` | `assert_mutation_allowed` |
| `frozenTarget` | `frozen_target` |
| `maintenanceActive` | `maintenance_active` |
| `recheckManaged` | `recheck_managed` |
| `baselineNow` | `baseline_now` |
| `forAdmittedAttempt` | `for_admitted_attempt` |
| `installPlugin` | `install_plugin` |
| `installTheme` | `install_theme` |
| `updatePlugin` | `update_plugin` |
| `updateTheme` | `update_theme` |
| `repositoryId` | `repository_id` |
| `verifyHead` | `verify_head` |
| `outcomeCode` | `outcome_code` |
| `attemptId` | `attempt_id` |
| `packageType` | `package_type` |
| `expectedHead` | `expected_head` |
| `installedIdentifier` | `installed_identifier` |
| `copyTo` | `copy_to` |
| `maximumArtifactBytes` | `maximum_artifact_bytes` |
| `deferExisting` | `defer_existing` |
| `expectedCommit` | `expected_commit` |
| `pluginFile` | `plugin_file` |
| `packageSlug` | `package_slug` |
| `coreOperation` | `core_operation` |
| `offerNamespace` | `offer_namespace` |

Promoted property names also change constructor named arguments. Persisted and
wire keys, journal outcomes, protocol strings, defaults/types/visibility and
mutation ordering do not change. `ZipArchive::$numFiles` remains external.

## Explicit historical commit acquisition

Admitted API `2` introduced historical commit acquisition.
`BranchDeploymentDeclaration` appends nullable `selected_commit`, and
`BranchDeployment::deploy()` appends the same named argument. It accepts only
full lowercase 40- or 64-character hexadecimal identities and cannot coexist
with `expected_head` (the `deploy()` argument `expected_commit`). The configured
branch remains the future tracking branch; this one acquisition does not pin
subsequent deployments or prove installed-file equality.

Hosts pass the exact commit through admission and archive acquisition and
provide a final verifier for repository access and revision. Reject the selection
if the provider lacks exact-commit support. Expected-head deployments retain
their branch movement refusal.

## Exact initial-install targets (admitted API 3)

`BranchDeploymentDeclaration` appends nullable `expected_identifier`. For an
initial install, pass the exact canonical `slug/main.php` plugin identifier or
`slug` theme identifier. It must match the declared slug; update declarations
reject this field. `installed_identifier` continues to describe an existing
installation and does not replace this initial-install intent.

Archive validation requires the unique plugin main file to match before exposing
a prepared archive. Subdirectory packages and nonstandard main filenames are
supported. The runner checks the installed identity before adoption; mismatch
retains an uncertain outcome. The target remains part of the immutable admitted
declaration.

## Working-copy protection (admitted API 4)

`AdmittedTargetFacts` implementations must
provide `target_directory(BranchDeploymentDeclaration $deployment): string`.
Return the absolute directory that execution will overwrite, including the
intended path for an absent install target. The runner reads it inside the
mutation lock, after final preflight and before the mutation fence; it must match
the executor's actual destination. For the WordPress Core executor, return
`WP_PLUGIN_DIR/slug` or `get_theme_root(installed_identifier ?? slug)/slug`.
This required interface
addition advances `BranchUpdater::ADMITTED_API_VERSION` to `4`; the additive
failure reason alone does not require a version change. Qualify matching host
implementations before released adoption.

`BranchDeploymentDeclaration` appends `bool $allow_working_copy = false`.
`BranchDeployment::deploy()` appends nullable `allow_working_copy`; omission
preserves the declaration. Hosts authorize the flag on the immutable admitted
declaration. A terminal call that changes it is rejected before execution.

Without the override, a target working copy returns `policy_blocked` with
`FailureReason::TARGET_IS_WORKING_COPY`, no detail, no mutation fence and no
executor call. Existing cleanup, terminal journaling and lock release apply.

## Shared package directory rules

Replace `RAN\WPBranchUpdater\V1\Archive\PackageSubdirectory` with
`RAN\UpdaterSupport\V1\PackageDirectory` from
`ran/updater-support` under `^1.0.0-beta.9`. The local class is removed without an alias;
its five methods and fixed-message `InvalidArgumentException` behavior remain
available on the shared class. Direct callers must update their imports.
The manifest requires the minimum compatible version that provides the class.
This repository's lock qualifies published beta.9; consuming roots select and
qualify their own exact compatible helper version through their lock files.

Declarations now admit mixed-case folder names, retaining the 191-byte limit.
Installed folders remain case-sensitive identities; update execution still
requires the exact plugin `dirname()` or theme stylesheet to match the slug,
including refusal of nested plugin identifiers. New-install callers continue
to use `deployment_slug()` for lower-case destinations. The expected initial
identifier retains its exact folder binding, one-level PHP filename syntax and
191-byte total limit.

`BranchUpdater::ADMITTED_API_VERSION` remains `4`: no admitted interface, field,
wire shape or outcome changes. Core must adopt a published branch-updater release
and the shared support dependency in its coordinated consumer update.
