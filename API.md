# API

Load the consumer's Composer autoloader before requiring package bootstrap. Production classes use the `RAN\WPBranchUpdater\V1` PSR-4 namespace.

`Runtime\BranchUpdater` is the public declaration boundary. Its `plugin()` and `theme()` methods return a `Runtime\BranchDeployment` handle so a target can be declared before the terminal `deploy()` call.

```php
$plugin = $branches->plugin(
    repository: 'acme/example-plugin',
    repository_id: '123456789',
    branch: 'main',
    plugin_file: 'example-plugin/example-plugin.php',
);

$outcome = $plugin->deploy(expected_commit: $commit);
```

`deploy()` accepts an optional expected commit and operation (`install` or `update`). A deployment created for an already-admitted host attempt cannot override its bound declaration. `attempt_id()` exposes the durable journal key used by host recovery.

## Core concepts

`Runtime\BranchDeploymentDeclaration` contains immutable requested/admitted facts: attempt ID, package type and slug, repository identity, branch, expected head, operation, optional package subdirectory, and installed identifier.

`Contract\BranchProvider` owns source-specific access. It resolves the requested branch and returns an `Archive\ArchiveOffer`. Providers must reject a stale expected head and support a fresh head verification immediately before mutation. The `ArchiveOffer` acquisition callback receives the validated `maximum_artifact_bytes` ceiling and must stop transport before writing more than that number of bytes. `PreparedArchive` rechecks the acquired file size afterward as defense in depth.

`Archive\PreparedArchive` owns the exact local ZIP from acquisition until cleanup. Validation covers path safety, entry metadata/type, collision rules, one package root, size limits, selected subdirectory, package identity, headers, version syntax, and PHP/WordPress compatibility.

The same `PreparedArchive` implements `Contract\AdmittedBranchArtifact` and `Contract\PreparedPackageArtifact`. `ProviderArchiveSource::prepare()` returns that validated owner directly; standalone executors receive the same object. `resolved_ref()` and `expected_version()` expose immutable metadata alongside `resolved_ref`, `version`, and `get_expected_version()`, including after cleanup. Byte access, capacity checks and mutation still require intact custody. Reuse this owner rather than cloning it: copies do not share cleanup state, and cleaning one invalidates the other's file custody.

`Runtime\AdmittedBranchRunner` owns the ordered execution of one already-admitted attempt. `Runtime\StandaloneBranchRunner` is the package's standalone composition around the same admitted runner.

`WordPress\WordPressPackageExecutor` adapts the package-level execution contract to WordPress. `WordPress\WordPressCorePackageExecutor` scopes the direct Core upgrader interaction. WordPress remains the installation owner.

The WordPress executor's `preflight()` checks runtime policy, loads the plugin and theme helpers and verifies archive custody, returning an empty array. It does not query installed metadata. The deployment lifecycle still acquires fresh installed facts before acquisition, under the mutation lock, after execution and when checking restoration. Custom `PackageExecutor` implementations retain the two-argument array-returning contract.

The default WordPress executor rejects multisite in preflight. Preflight follows
baseline acquisition and artifact preparation, so hosts must reject multisite
at admission if they need to avoid that work. This guard precedes the mutation
lock, mutation fence, and WordPress execution; custom adapters must enforce their
own policy. Multisite deployment remains unsupported.

Target facts expose one `active: bool`. The default adapter observes plugin
activation or the current stylesheet/template in the current WordPress context;
that value cannot describe network-wide and per-site activation or theme state.

## Outcomes and exceptions

`deploy()` returns a terminal outcome only after the configured journal has finished. Existing outcome strings are intentionally stable, including examples such as:

- `deployed`
- `downgrade_blocked`
- `provider_failed`
- `archive_integrity_failed`
- `upgrader_failed`
- `interrupted`
- `restoration_uncertain`

Invalid declarations throw. Durability failures from the attempt journal or mutation lock also throw because execution state cannot then be safely reduced to an ordinary terminal outcome.

Archive-integrity and cleanup failures are closed outcomes when their state is known. A fenced interruption remains conservative. Do not retry an uncertain outcome automatically; use the host's durable attempt record and recovery policy.

## Standalone composition

The package bootstrap composes a `BranchUpdater` using a host-supplied provider, attempt store, private archive directory, package executor, mutation lock, and optional `maximum_artifact_bytes`. The former named arguments `archiveDirectory` and `maximumArtifactBytes` are now `archive_directory` and `maximum_artifact_bytes`; positional calls are unchanged and no legacy aliases are provided. The artifact limit defaults to 52,428,800 bytes (50 MiB). Defaults use the WordPress package executor and WordPress updater lock.

```php
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore;

$configure = require '/path/to/vendor/ran/wp-branch-updater/bootstrap.php';

$branches = $configure(
    provider: $provider,
    attempts: new FileAttemptStore('/srv/private/branch-attempts.json'),
    archive_directory: '/srv/private/branch-archives',
    maximum_artifact_bytes: 134217728,
);
```

`Persistence\FileAttemptStore` provides locked atomic file replacement, readback, and process-stop recovery. It does not claim power-loss durability on filesystems where data or directory metadata has not reached stable storage, and it does not issue explicit `fsync` calls. Hosts needing stronger guarantees should supply an `AdmittedAttemptJournal` backed by storage with those semantics.

The host chooses the file journal path and therefore its sharing and ownership
scope. The store does not derive a network or site identity for that path.

## Themes and repository subdirectories

```php
$outcome = $branches->theme(
    repository: 'acme/site-packages',
    repository_id: '987654321',
    branch: 'production',
    stylesheet: 'example-theme',
    subdirectory: 'themes/example-theme',
)->deploy(operation: 'install');
```

Subdirectories are normalized as repository-relative package paths. A new install does not activate the package.

## Execute an admitted host attempt

Hosts with their own durable admission, archive custody, target facts, and operator history can construct the same execution path directly:

```php
use RAN\WPBranchUpdater\V1\Runtime\BranchUpdater;

$branches = BranchUpdater::for_admitted_attempt(
    deployment: $admittedDeclaration,
    journal: $journal,
    archives: $archives,
    target: $targetFacts,
    executor: $executor,
    lock: $lock,
);

$outcome = $branches->plugin(
    repository: $admittedDeclaration->repository,
    repository_id: $admittedDeclaration->repository_id,
    branch: $admittedDeclaration->branch,
    package_slug: $admittedDeclaration->slug,
    subdirectory: $admittedDeclaration->subdirectory,
)->deploy();
```

The collaborators implement `Contract\AdmittedAttemptJournal`, `Contract\AdmittedArchiveSource`, `Contract\AdmittedTargetFacts`, `Contract\AdmittedPackageExecutor`, and `Contract\MutationLock`. Conflicting terminal arguments throw before execution; omitted terminal arguments retain the admitted values. `package_slug` supports an admitted plugin install before a main file is known, after which host/WordPress adapters resolve the installed identity.

## Provider boundary

Production providers are host-supplied. The `GitHubFixtureProvider` and `BitbucketFixtureProvider` classes shipped in test support are local ZIP fixtures only; they are not production HTTP clients. A provider implementation must treat the `maximum_artifact_bytes` value passed to `ArchiveOffer::acquire()` as a transport ceiling, not merely as a post-download validation hint.

A host remains responsible for credential handling, webhook authentication, scheduling, durable admission/deduplication, and recovery decisions. The branch package is responsible only for its declared deployment lifecycle.

See [ARCHITECTURE.md](ARCHITECTURE.md) for the updater-family grammar and [MIGRATING.md](MIGRATING.md) for beta class/namespace changes.

## Non-reclaiming WordPress mutation barrier

`WordPress\WordPressUpdaterLock::try_acquire_without_reclaim(): ?string`
atomically inserts the existing non-autoloaded `auto_updater.lock` row only when
absent. A token means ownership; `null` means an occupied row, including an expired
or malformed token. Database failure throws `BranchDeploymentLockStorageFailure`.
The method never deletes or reclaims an existing row. Release a successful claim
through the unchanged exact-token `release($token)` method in `finally` and handle
failed release as uncertainty.

Use this barrier for host authority transitions that must not steal an interrupted
operation's lock. `current_token()` followed by `acquire()` is not equivalent:
ordinary `acquire()` retains its existing expired-lock takeover policy. The new
method does not establish that an earlier operation stopped, clear durable mutation
journals, or authorize recovery. Those remain host responsibilities. The existing
timestamp token format and standalone deployment behavior are unchanged.

Timestamp tokens are not globally unique receipts. Do not reuse a token after its
release or infer another process's lifetime from its age. A host's durable
mutation/recovery ownership must be established separately; this barrier does not
provide that evidence.

The adapter uses `$wpdb->options` and the current context's `options` cache
group. On multisite these are not a network-wide mutex or cache invalidation
scheme. The host must not treat this lock or a file journal path as multisite
admission or coordination.
