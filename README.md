# RAN WordPress Branch Updater

`ran/wp-branch-updater` performs an explicitly requested deployment from a declared repository branch. It acquires one branch ZIP, validates the exact local artifact, and, when the deployment is bound to an expected commit, rechecks that branch head immediately before mutation. WordPress Core then receives the admitted package for installation or update.

It is intentionally not a native update-registration service. It has no polling loop, webhook route, scheduler, or provider credential store. Hosts own source credentials and admission; WordPress Core owns installation.

The production namespace is `RAN\WPBranchUpdater\V1`, loaded through PSR-4. The public flow is:

```text
configure
→ declare plugin or theme
→ receive BranchDeployment
→ deploy()
```

`BranchUpdater` is the declaration boundary. `BranchDeployment` is the target-specific handle. `deploy()` is the public mutation verb. Normal failures return closed terminal outcome strings; durability failures throw when mutation state cannot be proved.

## Install and configure

At the current source-based beta, a consuming root must explicitly configure VCS repositories for both this package and `ran/updater-support`, and require both development branches. Composer does not inherit repository declarations from dependencies, and its default `stable` minimum stability will not admit a transitive development constraint on its own.

```json
{
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
    "ran/wp-branch-updater": "dev-main",
    "ran/updater-support": "dev-main"
  }
}
```

Pin the reviewed package and support revisions in the consuming release's lock file. Do not relax global `minimum-stability` solely to admit these dependencies. Load the consumer's Composer autoloader before requiring `bootstrap.php`; this package does not load a private autoloader. Configure the updater only after WordPress has loaded, and do not call `deploy()` before WordPress is available.

```php
use RAN\WPBranchUpdater\V1\Persistence\FileAttemptStore;

require '/path/to/consumer/vendor/autoload.php';

$configure = require '/path/to/consumer/vendor/ran/wp-branch-updater/bootstrap.php';

$branches = $configure(
    provider: $provider,
    attempts: new FileAttemptStore('/srv/private/branch-attempts.json'),
    archiveDirectory: '/srv/private/branch-archives',
);
```

State and archive directories must be private and durable. Do not use WordPress's upgrade directory for either.

## Declare and deploy

```php
$outcome = $branches->plugin(
    repository: 'acme/example-plugin',
    repositoryId: '123456789',
    branch: 'main',
    pluginFile: 'example-plugin/example-plugin.php',
)->deploy(expectedCommit: $verifiedCommit);
```

Pass an expected commit only after the host has authenticated and admitted it. The provider resolves and acquires the branch artifact; when `expectedCommit` is supplied, the runner verifies that branch head again immediately before the mutation fence. The archive is always rechecked before the fence. WordPress Core performs the installation/update, and postconditions are then verified before the attempt is finished.

New installs remain inactive. Do not retry an uncertain outcome automatically.

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

## Verification

The repository gate runs behavioral characterization for declaration validation, provider/source-head checks, archive safety and identity, downgrade prevention, mutation locking, journal transitions, WordPress execution, restoration/postcondition handling, cleanup, and hard-stop recovery.

CI also installs the package into a separate no-dev Composer consumer and runs `tests/consumer-install.php`. That proves production classes resolve through the installed PSR-4 package and that bootstrap does not depend on a package-private `vendor` directory.

## Community

Read [CONTRIBUTING.md](CONTRIBUTING.md) before proposing a change. Use [SUPPORT.md](SUPPORT.md) for non-sensitive support and [SECURITY.md](SECURITY.md) for confidential vulnerability reports. Participation is governed by [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).
