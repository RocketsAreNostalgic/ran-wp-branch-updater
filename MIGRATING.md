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
| `RAN\BranchDeployment\PreparedArchiveArtifact` | `RAN\WPBranchUpdater\V1\Archive\PreparedArchiveArtifact` |
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
