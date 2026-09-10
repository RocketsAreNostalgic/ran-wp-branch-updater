# API

Load the consumer's Composer autoloader before requiring package bootstrap. Production classes use the `RAN\WPBranchUpdater\V1` PSR-4 namespace.

`Runtime\BranchUpdater` is the public declaration boundary. Its `plugin()` and `theme()` methods return a `Runtime\BranchDeployment` handle so a target can be declared before the terminal `deploy()` call.

```php
$plugin = $branches->plugin(
    repository: 'acme/example-plugin',
    repositoryId: '123456789',
    branch: 'main',
    pluginFile: 'example-plugin/example-plugin.php',
);

$outcome = $plugin->deploy(expectedCommit: $commit);
```

`deploy()` accepts an optional expected commit and operation (`install` or `update`). A deployment created for an already-admitted host attempt cannot override its bound declaration. `attemptId()` exposes the durable journal key used by host recovery.

## Core concepts

`Runtime\BranchDeploymentDeclaration` contains immutable requested/admitted facts: attempt ID, package type and slug, repository identity, branch, expected head, operation, optional package subdirectory, and installed identifier.

`Contract\BranchProvider` owns source-specific access. It resolves the requested branch and returns an `Archive\ArchiveOffer`. Providers must reject a stale expected head and support a fresh head verification immediately before mutation.

`Archive\PreparedArchive` owns the exact local ZIP from acquisition until cleanup. Validation covers path safety, entry metadata/type, collision rules, one package root, size limits, selected subdirectory, package identity, headers, version syntax, and PHP/WordPress compatibility.

`Runtime\AdmittedBranchRunner` owns the ordered execution of one already-admitted attempt. `Runtime\StandaloneBranchRunner` is the package's standalone composition around the same admitted runner.

`WordPress\WordPressPackageExecutor` adapts the package-level execution contract to WordPress. `WordPress\WordPressCorePackageExecutor` scopes the direct Core upgrader interaction. WordPress remains the installation owner.

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

The package bootstrap composes a `BranchUpdater` using a host-supplied provider, attempt store, private archive directory, package executor, and mutation lock. Defaults use the WordPress package executor and WordPress updater lock.

```php
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore;

$configure = require '/path/to/vendor/ran/wp-branch-updater/bootstrap.php';

$branches = $configure(
    provider: $provider,
    attempts: new FileAttemptStore('/srv/private/branch-attempts.json'),
    archiveDirectory: '/srv/private/branch-archives',
);
```

`Persistence\FileAttemptStore` provides locked atomic file replacement, readback, and process-stop recovery. It does not claim power-loss durability on filesystems where data or directory metadata has not reached stable storage, and it does not issue explicit `fsync` calls. Hosts needing stronger guarantees should supply an `AdmittedAttemptJournal` backed by storage with those semantics.

## Themes and repository subdirectories

```php
$outcome = $branches->theme(
    repository: 'acme/site-packages',
    repositoryId: '987654321',
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

$branches = BranchUpdater::forAdmittedAttempt(
    deployment: $admittedDeclaration,
    journal: $journal,
    archives: $archives,
    target: $targetFacts,
    executor: $executor,
    lock: $lock,
);

$outcome = $branches->plugin(
    repository: $admittedDeclaration->repository,
    repositoryId: $admittedDeclaration->repositoryId,
    branch: $admittedDeclaration->branch,
    packageSlug: $admittedDeclaration->slug,
    subdirectory: $admittedDeclaration->subdirectory,
)->deploy();
```

The collaborators implement `Contract\AdmittedAttemptJournal`, `Contract\AdmittedArchiveSource`, `Contract\AdmittedTargetFacts`, `Contract\AdmittedPackageExecutor`, and `Contract\MutationLock`. Conflicting terminal arguments throw before execution; omitted terminal arguments retain the admitted values. `packageSlug` supports an admitted plugin install before a main file is known, after which host/WordPress adapters resolve the installed identity.

## Provider boundary

Production providers are host-supplied. The `GitHubFixtureProvider` and `BitbucketFixtureProvider` classes shipped in test support are local ZIP fixtures only; they are not production HTTP clients.

A host remains responsible for credential handling, webhook authentication, scheduling, durable admission/deduplication, and recovery decisions. The branch package is responsible only for its declared deployment lifecycle.

See [ARCHITECTURE.md](ARCHITECTURE.md) for the updater-family grammar and [MIGRATING.md](MIGRATING.md) for beta class/namespace changes.
