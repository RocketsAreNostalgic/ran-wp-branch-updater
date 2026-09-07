# API

Load the consumer's Composer autoloader before requiring the package bootstrap.
`BranchDeploymentPackage` is the public declaration boundary. It has separate
`plugin()` and `theme()` methods so a plugin main file is never invented from a
slug.

```php
$plugin = $branches->plugin(
    repository: 'acme/example-plugin',
    repositoryId: '123456789',
    branch: 'main',
    pluginFile: 'example-plugin/example-plugin.php',
);

$outcome = $plugin->deploy(expectedCommit: $commit);
```

`deploy()` accepts an optional expected commit and operation (`install` or
`update`). A target declared for an admitted host attempt cannot be overridden.
`attemptId()` provides the journal key for host recovery.

## Outcomes and exceptions

`deploy()` returns a terminal outcome string only after the configured journal
has finished, for example `deployed`, `downgrade_blocked`, `provider_failed`,
`upgrader_failed`, `interrupted`, or `restoration_uncertain`. Hosts should store
and present their own structured attempt record around that outcome.

Invalid declarations and durability failures from the journal or lock throw.
Archive-integrity and cleanup failures are terminal outcomes, and a post-fence
interruption returns `interrupted`. Do not retry an uncertain outcome
automatically; consult the host's durable attempt record and recovery policy.

Themes may be declared from a repository subdirectory:

```php
$outcome = $branches->theme(
    repository: 'acme/site-packages',
    repositoryId: '987654321',
    branch: 'production',
    stylesheet: 'example-theme',
    subdirectory: 'themes/example-theme',
)->deploy(operation: 'install');
```

## Execute an admitted host attempt

Hosts with their own durable admission, archive custody, target facts, and
operator history can use the same runner without adopting `FileAttemptStore`:

```php
$branches = BranchDeploymentPackage::forAdmittedAttempt(
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

The collaborators implement `AdmittedAttemptJournal`, `AdmittedArchiveSource`,
`AdmittedTargetFacts`, `AdmittedPackageExecutor`, and `MutationLock`. The full
declaration is bound: attempt ID, type, slug, repository, branch, expected
commit, operation, subdirectory, and installed identifier. Conflicting terminal
arguments throw before execution. Omitted terminal arguments keep the admitted
values. `packageSlug` permits an admitted plugin install before a main file is
known; the host adapters then resolve the installed identity.

The package validates ZIP entry paths, metadata, archive identity, header
identity, size limits, and the selected package subdirectory before handing
the ZIP to WordPress. It checks a prepared archive again immediately before
the mutation fence. An installed update must preserve its package identity;
an install does not activate a new plugin or theme.

`GitHubFixtureProvider` and `BitbucketFixtureProvider` exist only to run the
included contract tests against local ZIP fixtures. A production host must
implement `BranchProvider` with authenticated archive retrieval and an exact
head recheck. The host also owns webhook authentication, scheduling, durable
admission, and recovery decisions.
