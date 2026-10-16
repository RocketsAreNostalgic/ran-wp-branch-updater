# Migrating the beta API

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
| `RAN\BranchDeployment\BranchDeploymentOperation` | `RAN\WPBranchUpdater\V1\Runtime\StandaloneBranchRunner` |
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
| `RAN\BranchDeployment\BranchProvider` | `RAN\WPBranchUpdater\V1\Contract\BranchProvider` |
| `RAN\BranchDeployment\MutationLock` | `RAN\WPBranchUpdater\V1\Contract\MutationLock` |
| `RAN\BranchDeployment\PackageExecutor` | `RAN\WPBranchUpdater\V1\Contract\PackageExecutor` |
| `RAN\BranchDeployment\PreparedPackageArtifact` | `RAN\WPBranchUpdater\V1\Contract\PreparedPackageArtifact` |
| `RAN\BranchDeployment\BranchDeploymentJournalFailure` | `RAN\WPBranchUpdater\V1\Persistence\BranchDeploymentJournalFailure` |
| `RAN\BranchDeployment\FileAttemptJournal` | `RAN\WPBranchUpdater\V1\Persistence\FileAttemptJournal` |
| `RAN\BranchDeployment\FileAttemptStore` | `RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore` |
| `RAN\BranchDeployment\FileMutationLock` | `RAN\WPBranchUpdater\V1\Persistence\FileMutationLock` |
| `RAN\BranchDeployment\AdmittedBranchDurabilityFailure` | `RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchDurabilityFailure` |
| `RAN\BranchDeployment\AdmittedBranchRunner` | `RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchRunner` |
| `RAN\BranchDeployment\AdmittedBranchStageFailure` | `RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchStageFailure` |
| `RAN\BranchDeployment\BranchDeploymentLockReleaseFailure` | `RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockReleaseFailure` |
| `RAN\BranchDeployment\BranchDeploymentLockStorageFailure` | `RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockStorageFailure` |
| `RAN\BranchDeployment\CorePackageExecutionFailure` | `RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionFailure` |
| `RAN\BranchDeployment\CorePackageExecutionResult` | `RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult` |
| `RAN\BranchDeployment\ProviderArchiveSource` | `RAN\WPBranchUpdater\V1\Runtime\ProviderArchiveSource` |
| `RAN\BranchDeployment\StandalonePackageExecutor` | `RAN\WPBranchUpdater\V1\Runtime\StandalonePackageExecutor` |
| `RAN\BranchDeployment\StandaloneTargetFacts` | `RAN\WPBranchUpdater\V1\Runtime\StandaloneTargetFacts` |
| `RAN\BranchDeployment\InstalledPackageIdentifier` | `RAN\WPBranchUpdater\V1\WordPress\InstalledPackageIdentifier` |
| `RAN\BranchDeployment\WordPressPackageExecutor` | `RAN\WPBranchUpdater\V1\WordPress\WordPressPackageExecutor` |
| `RAN\BranchDeployment\WordPressUpdaterLock` | `RAN\WPBranchUpdater\V1\WordPress\WordPressUpdaterLock` |

These are naming and source-organisation changes. `deploy()` remains the public terminal action, and existing terminal outcome strings remain unchanged.

## Direct admitted archive owner

The forwarding `Archive\PreparedArchiveArtifact` class is retired in this beta cut. `Archive\PreparedArchive` now implements `Contract\AdmittedBranchArtifact` directly. Replace wrapper construction with the validated archive owner, remove `archive()` unwrapping, and pass that same owner to `StandaloneTargetFacts::record_installed()`. `ProviderArchiveSource::prepare()` now returns the owner directly. There is no legacy wrapper or dual-representation dispatch.

`resolved_ref()` and `expected_version()` return the existing immutable metadata; they remain readable after cleanup. Custody checks, local byte access and cleanup stay on the same object. A cloned owner does not share cleanup state: after either copy deletes the file, the other's custody checks fail. Hosts must retain one owner per acquired archive rather than relying on cloned wrappers sharing cleanup. Update concrete type checks and reflection expectations alongside callers.

Core hosts that constructed or consumed the wrapper must update their archive adapter and adopt the matching immutable released Composer dependency as one qualified tuple. The direct adapter cannot run against the earlier package whose `PreparedArchive` lacks the admitted interface. Publishing this library alone does not qualify an unchanged Core installation; coordinate the host's released dependency adoption before upgrading controlled sites.

## Typical host update

Before:

```php
use RAN\BranchDeployment\FileAttemptStore;
use RAN\BranchDeployment\BranchProvider;
```

After:

```php
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore;
use RAN\WPBranchUpdater\V1\Contract\BranchProvider;
```

A configured package now returns `Runtime\BranchUpdater`, and calling `plugin()` or `theme()` returns a `Runtime\BranchDeployment` handle:

```php
$deployment = $branches->plugin(
    repository: 'acme/example-plugin',
    repository_id: '123456789',
    branch: 'main',
    plugin_file: 'example-plugin/example-plugin.php',
);

$outcome = $deployment->deploy(expected_commit: $verifiedCommit);
```

Hosts that construct an admitted execution directly should type their immutable declaration as `Runtime\BranchDeploymentDeclaration`, use the admitted collaborators under `Contract`, and continue using `Runtime\AdmittedBranchRunner` through `Runtime\BranchUpdater::for_admitted_attempt()`.

## Autoloading

Production loading now follows PSR-4:

```text
RAN\WPBranchUpdater\V1\ → src/
```

Consumers should load their normal Composer autoloader. The package does not provide or require a private production autoloader.

## No compatibility layer

The previous beta namespace and class identities are intentionally unsupported after this cut. Update imports and type hints as one migration rather than relying on aliases or shims.

The same hard cut applies to standalone `FileAttemptStore` journal data. The public v1 baseline accepts only states that satisfy the current transition invariants; beta-era pre-fence `needs_attention` records are rejected as malformed rather than translated. Historical beta journals must be reconciled by the host before adopting the v1 baseline. No migration helper or schema adapter is loaded at runtime.

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

## WordPress preflight observations

`WordPress\WordPressPackageExecutor::preflight()` now returns an empty array instead of installed version and activation observations. The standalone runner already discarded those observations. Use `installed_facts()` when explicitly querying the installed package; deployment target checks continue to acquire fresh facts at their existing authority boundaries. Preflight retains dependency loading, runtime policy and archive custody checks. The `PackageExecutor` interface and custom executor calls are unchanged.

## Explicit historical commit acquisition

The admitted contract is now `BranchUpdater::ADMITTED_API_VERSION = 2`.
`BranchDeploymentDeclaration` appends nullable `selected_commit`, and
`BranchDeployment::deploy()` appends the same named argument. It accepts only
full lowercase 40- or 64-character hexadecimal identities and cannot coexist
with `expected_head` (the `deploy()` argument `expected_commit`). The configured
branch remains the future tracking branch; this one acquisition does not pin
subsequent deployments or prove installed-file equality.

Standalone `BranchProvider` implementations must now implement
`supports_selected_commit(): bool`. Return false until the provider can resolve
the exact requested commit, verify repository identity and access, and supply a
fresh final verifier that rechecks those facts without requiring branch-head
equality. Unsupported mode refuses before `prepare()`. A prepared reference
must equal `selected_commit`; the normal final verifier, custody, mutation lock,
downgrade protection and cleanup still apply. Providers may explicitly reject
hash formats they cannot support. Expected-head deployments retain their branch
movement refusal.

The existing attempt journal now records nullable `selected_commit` and validates
its syntax, exclusive mode and resolved-reference equality. Older journal shapes
are not migrated; resolve interrupted work on its originating package before
adopting this changed prerelease contract. Core's admitted host must pass the
field unchanged through its own declaration and archive contracts and qualify
that exact connected dependency tuple before enabling historical acquisition.
