# RAN WordPress Branch Updater

`ran/wp-branch-updater` prepares one declared branch ZIP, validates it, and
passes that exact local archive to WordPress Core for installation or update.
It has no polling loop, native update registration, webhook route, scheduler,
or provider credential store.

The public namespace remains `RAN\BranchDeployment`. A host creates a
`BranchDeploymentPackage`, declares one plugin or theme target, and calls
`deploy()`. A normal failure returns a terminal outcome; a journal or lock
durability failure throws because mutation state is then uncertain.

## Status and limits

This package has executable archive-custody, runner, recovery, and WordPress
executor code. Its `GitHubFixtureProvider` and `BitbucketFixtureProvider` are
test-only local ZIP transports. They are not HTTP clients and must never be
configured as production GitHub or Bitbucket access.

Production adoption still needs authenticated provider clients, host-owned
delivery admission and deduplication, durable site-specific attempt storage,
operator recovery policy, and isolated WordPress compatibility proof for the
intended site. A release branch is not an immutable release artifact.

## Install and configure

Install through Composer after the required `ran/updater-support` dependency
is pinned by the consuming release. Load the consumer's Composer autoloader
before requiring `bootstrap.php`; the package does not load a private autoloader.
Then configure it after WordPress has loaded:

```php
use RAN\BranchDeployment\FileAttemptStore;

require '/path/to/consumer/vendor/autoload.php';
$configure = require '/path/to/consumer/vendor/ran/wp-branch-updater/bootstrap.php';
$branches = $configure(
    provider: $provider,
    attempts: new FileAttemptStore('/srv/private/branch-attempts.json'),
    archiveDirectory: '/srv/private/branch-archives',
);
```

The state and archive directories must be private and durable. Do not use
WordPress's upgrade directory for either one.

## Declare and deploy

```php
$outcome = $branches->plugin(
    repository: 'acme/example-plugin',
    repositoryId: '123456789',
    branch: 'main',
    pluginFile: 'example-plugin/example-plugin.php',
)->deploy(expectedCommit: $verifiedCommit);
```

Pass an expected commit only after the host has authenticated and admitted that
commit. The provider prepares the archive, the runner verifies its head again
before mutation, and WordPress Core owns installation. New targets remain
inactive. Do not retry an uncertain outcome automatically.

For API details, including themes, subdirectories, admitted host attempts, and
outcome handling, see [API.md](API.md).

The release gate also installs the package into a separate Composer consumer
and runs `tests/consumer-install.php` with `BRANCH_UPDATER_CONSUMER_ROOT` set
to that consumer root. This proves the installed class map and bootstrap do
not depend on a package-private `vendor` directory.
