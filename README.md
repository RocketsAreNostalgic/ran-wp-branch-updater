# RAN WordPress Branch Updater

`ran/wp-branch-updater` performs an explicitly requested deployment from a declared repository branch. It acquires one branch ZIP, validates the exact local artifact, and, when the deployment is bound to an expected commit, rechecks that branch head immediately before mutation. WordPress Core then receives the admitted package for installation or update.

It is intentionally not a native update-registration service. It has no polling loop, webhook route, scheduler, or provider credential store. Hosts own source credentials and admission; WordPress Core owns installation.

The production namespace is `RAN\WPBranchUpdater\V1`, loaded through PSR-4. The public flow is:

```text
admit attempt
→ declare plugin or theme
→ receive BranchDeployment
→ deploy()
```

`BranchUpdater` is the declaration boundary. `BranchDeployment` is the target-specific handle. `deploy()` is the public mutation verb. Normal failures return closed terminal outcome strings; durability failures throw when mutation state cannot be proved.

## Install and configure

While consuming the current prerelease line from GitHub, a root project must explicitly configure VCS repositories for both this package and `ran/updater-support`. Composer does not inherit repository declarations from dependencies. The root requires the branch updater and resolves its `ran/updater-support` dependency transitively under `^1.0.0-beta.10`. The root must admit beta packages while preferring stable packages where available.

```json
{
  "minimum-stability": "beta",
  "prefer-stable": true,
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/RocketsAreNostalgic/ran-wp-branch-updater"
    },
    {
      "type": "vcs",
      "url": "https://github.com/RocketsAreNostalgic/ran-updater-support"
    }
  ],
  "require": {
    "ran/wp-branch-updater": "dev-main"
  }
}
```

Commit the consuming root project's `composer.lock` to pin the reviewed package and transitive support revisions. A first install without a lock file, or an update that includes Support, can select a different compatible Support 1.x release subject to the root project's constraints and stability settings. Subsequent `composer install` runs use the root lock's selected versions. Load the consumer's Composer autoloader and WordPress before executing a deployment.

The host supplies the admitted declaration, durable journal, archive source,
target facts, executor and mutation lock. Use private storage for the journal and
archive custody. Admit only single-site deployments.

## Declare and deploy

```php
use RAN\WPBranchUpdater\V1\Runtime\BranchUpdater;

$branches = BranchUpdater::for_admitted_attempt(
    $declaration, $journal, $archives, $target, $executor, $lock,
);

$outcome = $branches->plugin(
    repository: 'acme/example-plugin',
    repository_id: '123456789',
    branch: 'main',
    plugin_file: 'example-plugin/example-plugin.php',
)->deploy();
```

For a branch-head deployment, the host can bind an authenticated expected head
in the declaration. The host's archive adapter rechecks source authority before
mutation, and the runner rechecks archive custody. WordPress Core performs the
installation or update, then the runner verifies the postconditions.

New installs remain inactive. Do not retry an uncertain outcome automatically.

### Roll back to a selected commit

Branch-head deployments refuse an archive with an older version than the
installed package. An operator can choose a historical commit to install an older
version. The host records that confirmed choice as `selected_commit` on the
admitted declaration, and the acquired archive must match the exact commit.
See [API.md](API.md#selected-commit-rollbacks) for the host contract.

## Updater-family architecture

The branch and release updaters share a common architectural grammar without pretending their runtime models are identical:

```text
Declaration
→ Provider
→ Artifact acquisition
→ Validation
→ Mutation admission
→ WordPress Core
→ Postcondition verification
```

The branch updater owns one synchronous ordered deployment through a **Runner** and exposes `deploy()`. The release updater joins WordPress's future native update lifecycle, so persistent state can span callbacks and is coordinated rather than represented as the same runner shape; its public terminal action is `register()`.

Likewise, dependency mechanics intentionally differ. The branch package consumes `ran/updater-support` as a normal production Composer dependency. The release package may consume the same helper during development/build and seal the required runtime implementation into its verified runtime copy where its physical-runtime selection model requires that.

See [ARCHITECTURE.md](ARCHITECTURE.md) for the family model and ownership boundaries, [API.md](API.md) for the package API, and [MIGRATING.md](MIGRATING.md) for the beta namespace/class migration.

### Expanded-size refusal

After validating each ZIP entry's metadata and type, archive validation admits
its declared size against both the aggregate expanded-byte limit and a per-entry
compression-ratio ceiling (`100:1` for entries with declared decompressed size at
or above `131072` bytes / 128 KiB) before opening its stream. Admitted entries
still require bounded per-entry stream-length and CRC verification, including
directory entries (which must declare zero size and match their declared CRC on
the empty stream). An entry whose declared size exceeds the remaining aggregate
allowance or the compression-ratio ceiling reports expanded-size error 1018
before stream inspection, even if its data is corrupt. Admitted corrupt entries
still report integrity error 1010. This avoids inflating an already-over-limit
entry without claiming a measured deployment speedup.

## Working-copy protection

The runner refuses `policy_blocked` with reason `target_is_working_copy` when the
actual target contains `.git`, `.svn`, `.hg` or `.bzr`, including files,
directories and dangling links. It checks only the target, under the mutation
lock after final preflight and before the journal's mutation fence. Missing
install targets, ancestor markers and Composer metadata do not trigger it.

Hosts authorize an override by setting `allow_working_copy: true` on the
`BranchDeploymentDeclaration` for that attempt. See [MIGRATING.md](MIGRATING.md)
for admitted API 4 and the required destination fact.

## Failure reasons

Outcome codes are unchanged. A failure can also carry a closed reason and a screened, bounded detail, read from `BranchDeployment::last_reason()`. The vocabulary, its precise guarantees, and the inventory of every audited site are in [docs/error-detail-inventory.md](docs/error-detail-inventory.md).

## Verification

The repository gate verifies declaration validation, source rechecks, archive
safety and identity, rollback policy, mutation fencing, terminal journaling,
WordPress execution, restoration, postconditions and cleanup.

CI also exercises the admitted entry point in a separate Composer installation
with development dependencies omitted.

### Package header policy

Archive metadata and child-theme `Template` values follow WordPress
[`get_file_data()`](https://developer.wordpress.org/reference/functions/get_file_data/)
and [`_cleanup_header_comment()`](https://developer.wordpress.org/reference/functions/_cleanup_header_comment/)
formatting: inspect the first 8 KiB, replace carriage returns with newlines,
allow the optional opening `<?php` prefix, and trim closing comments or PHP tags.
LF, CRLF and CR-only headers are supported. A cleaned empty `Template` means no
parent; a nonempty parent must remain a safe slug, differ from the target and
exist in WordPress.

Package admission intentionally remains stricter than header extraction:
required name/version values, the 64-byte field limit, control-character refusal,
version/compatibility syntax and requirements, and exact package identity still
apply. Formatting compatibility does not waive archive custody or installation
eligibility checks.

## Community

Read [CONTRIBUTING.md](CONTRIBUTING.md) before proposing a change. Use [SUPPORT.md](SUPPORT.md) for non-sensitive support and [SECURITY.md](SECURITY.md) for confidential vulnerability reports. Participation is governed by [RAN Code of Conduct](https://github.com/RocketsAreNostalgic/.github/blob/main/CODE_OF_CONDUCT.md).

### Journal naming boundary

`AdmittedAttemptJournal` implementations use `record_resolved_ref()` and
`mark_mutation_started()` and `finish()`. Hosts must update implementations,
callers and named arguments together when adopting this breaking beta contract.

Owned-method and variable naming checks cover all shipped source.
The remaining connected API migration uses direct snake_case replacements; see
[MIGRATING.md](MIGRATING.md). Hosts must adopt matching declarations, callers,
named arguments and overrides together. Mixed old/new tuples are unsupported;
source preparation does not imply a published package or installed Core adoption.
