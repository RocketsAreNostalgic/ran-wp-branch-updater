# API

Load the consumer's Composer autoloader. Production classes use the `RAN\WPBranchUpdater\V1` PSR-4 namespace.

`Runtime\BranchUpdater` is the public declaration boundary. Its `plugin()` and `theme()` methods return a `Runtime\BranchDeployment` handle so a target can be declared before the terminal `deploy()` call.

```php
$plugin = $branches->plugin(
    repository: 'acme/example-plugin',
    repository_id: '123456789',
    branch: 'main',
    plugin_file: 'example-plugin/example-plugin.php',
);

$outcome = $plugin->deploy();
```

`deploy()` uses the bound declaration. Any arguments supplied to the terminal call must match it. `attempt_id()` exposes the durable journal key used by host recovery.

## Core concepts

`Runtime\BranchDeploymentDeclaration` contains the host's immutable admission facts. They include the attempt ID, package type and slug, repository identity, tracking branch, operation, optional subdirectory, installed or expected package identifiers and deployment policy flags.

`Contract\AdmittedArchiveSource` is the host's boundary for source access. Its adapter resolves the requested revision and revalidates source authority under the mutation lock. The `ArchiveOffer` acquisition callback receives the validated `maximum_artifact_bytes` ceiling and must stop transport before writing more than that number of bytes. `PreparedArchive` rechecks the acquired file size.

`Archive\PreparedArchive` owns the exact local ZIP from acquisition until cleanup. Validation covers path safety, entry metadata/type, collision rules, one package root, size limits, selected subdirectory, package identity, headers, version syntax, and PHP/WordPress compatibility.

`PreparedArchive` implements `Contract\AdmittedBranchArtifact` and `Contract\PreparedPackageArtifact`. Pass the validated owner from `AdmittedArchiveSource::prepare()` to the runner and Core executor. `resolved_ref()` and `expected_version()` expose immutable metadata, including after cleanup. Reuse the same owner for byte access, capacity checks and mutation. Cleaning a clone would invalidate the original owner's custody.

`Runtime\AdmittedBranchRunner` owns the ordered execution of one already-admitted attempt.

The host's `AdmittedPackageExecutor` dispatches to `WordPress\WordPressCorePackageExecutor`, which scopes the direct Core upgrader interaction. WordPress remains the installation owner.

The host supplies runtime policy and target facts. The runner requests fresh installed facts before acquisition, under the mutation lock, after execution and when checking restoration. Admit deployments only on single-site WordPress. The `active` target fact describes activation in that site's context.

## Selected-commit rollbacks

Branch-head deployments return `downgrade_blocked` if the archive version is
lower than the installed version at initial admission or under the mutation lock.
This applies to unattended requests, including those pinned with `expected_head`.

An exact, operator-confirmed historical selection in `selected_commit` allows an
older version, matching the release updater's confirmed `$older_intended` rule
(PU-037). The host owns confirmation and leaves `selected_commit` unset for
automatic requests. The declaration accepts a full lowercase 40- or 64-character
hex commit and requires `expected_head` to be null. The runner checks the resolved
ref for exact equality. The host adapter must reverify repository access and the
selected revision before mutation. Archive custody, target policy and installed
checks still apply. The selected commit applies to one deployment of package
files. Future deployments track the configured branch. Database recovery is a
separate operator action.

## Outcomes and exceptions

`deploy()` returns a terminal outcome only after the configured journal has finished. Existing outcome strings are intentionally stable, including examples such as:

- `deployed`
- `downgrade_blocked`
- `provider_failed`
- `archive_integrity_failed`
- `upgrader_failed`
- `interrupted`
- `restoration_uncertain`

Invalid declarations throw. Durability failures from the attempt journal or mutation lock also throw because execution state cannot then be safely reduced to an ordinary terminal outcome. The documented escaping failure set from `AdmittedBranchRunner::run()` / `BranchDeployment::deploy()` is:

- `Runtime\AdmittedBranchDurabilityFailure` (`reason(): FailureReason::DURABILITY_UNCERTAIN`)
- `Persistence\BranchDeploymentJournalFailure` (`reason(): FailureReason::JOURNAL_UNCERTAIN`)
- `Runtime\BranchDeploymentLockReleaseFailure` (`reason(): FailureReason::LOCK_RELEASE`)
- `Runtime\BranchDeploymentLockStorageFailure` (`reason(): FailureReason::LOCK_STORAGE`)
- `RuntimeException` when reusing an already-consumed runner, plus any unexpected `Throwable` raised by the host journal during terminal `finish()` / `finish_with_reason()`

Each of the four durability failure classes exposes `reason(): FailureReason` and `as_reason(): AdmittedFailureReason` without reading exception messages or chained causes, and `last_reason()` is populated before they escape.

Archive-integrity and cleanup failures are closed outcomes when their state is known. A failing archive cleanup never overwrites a primary failure outcome or downgrades a verified `deployed` outcome; the cleanup failure is recorded on `last_reason()` (`cleanup_failed: true`). For fresh installs without a baseline, a definite WordPress refusal or failure returns `upgrader_failed` only when the target directory remains absent, and stays `restoration_uncertain` otherwise. When WordPress confirms baseline restoration (`WORDPRESS_RESTORED`), the runner returns `upgrader_failed` with `FailureReason::CORE_WORDPRESS_RESTORED`. A fenced interruption remains conservative. Do not retry an uncertain outcome automatically; use the host's durable attempt record and recovery policy.

## Failure reasons

`BranchDeployment::last_reason()` returns `?AdmittedFailureReason` with a closed `reason`, an optional screened `detail` (untrusted text: escape it), `primary_outcome` and `cleanup_failed`. A journal may implement `Contract\AdmittedAttemptReasonJournal` to receive it from `finish_with_reason()`; one that does not is called exactly as before. See [docs/error-detail-inventory.md](docs/error-detail-inventory.md).

## Themes and repository subdirectories

```php
$outcome = $branches->theme(
    repository: 'acme/site-packages',
    repository_id: '987654321',
    branch: 'production',
    stylesheet: 'example-theme',
    subdirectory: 'themes/example-theme',
)->deploy();
```

For an initial install, bind `operation: 'install'` in the declaration. Subdirectories are normalized as repository-relative package paths. A new install leaves the package inactive.

## Execute an admitted host attempt

Bind the admitted declaration to the host's durable journal, archive source, target facts, executor and lock:

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

Production providers are host-supplied. The `GitHubFixtureProvider` in development
test support reads a local ZIP. Provider implementations must enforce the
`maximum_artifact_bytes` ceiling passed to `ArchiveOffer::acquire()` during transfer.

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
journals, or authorize recovery. Those remain host responsibilities.

Timestamp tokens are not globally unique receipts. Do not reuse a token after its
release or infer another process's lifetime from its age. A host's durable
mutation/recovery ownership must be established separately; this barrier does not
provide that evidence.

The adapter uses `$wpdb->options` and the current context's `options` cache
group. On multisite these are not a network-wide mutex or cache invalidation
scheme. The lock is for the current site's admitted operations.
