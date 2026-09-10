# Contributing

This package is in a pre-release development line. Keep every change suitable for public review and use Conventional Commits (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`, or `chore:`) so Release Please can prepare version proposals.

Use PHP 8.2 and Node.js 24.11.0. Install Composer dependencies, then run the repository gate:

```sh
composer install --no-interaction --prefer-dist
composer check
```

For changes to runtime PHP or Composer metadata, also prove a separate installed Composer consumer:

```sh
consumer="$(mktemp -d)"
trap 'rm -rf "$consumer"' EXIT
php scripts/prepare-consumer-fixture.php "$consumer" "$PWD"
composer --working-dir="$consumer" update --no-dev --no-interaction --prefer-dist --no-progress
BRANCH_UPDATER_CONSUMER_ROOT="$consumer" php tests/consumer-install.php
```

The external consumer proof verifies the installed PSR-4 package and bootstrap without relying on a package-private `vendor` directory.

## Architecture rules

Read [ARCHITECTURE.md](ARCHITECTURE.md) before changing production structure. Use the updater-family vocabulary according to responsibility:

- immutable requested/admitted facts are a **Declaration**;
- source-specific implementations are **Providers**;
- acquired controlled bytes are **Artifacts**;
- one synchronous ordered execution is a **Runner**;
- state spanning separate callbacks/events is a **Coordinator**;
- durable transition/history interfaces are **Journals**;
- durable object persistence is a **Store**.

Do not rename components merely to make the branch and release packages appear symmetrical. `deploy()` is the branch package's public terminal action; the release package's native-registration flow is different.

The branch updater's production namespaces are organised by responsibility beneath `RAN\WPBranchUpdater\V1`: `Archive`, `Contract`, `Persistence`, `Runtime`, and `WordPress`. Prefer one significant production object or interface per file and keep paths PSR-4 aligned.

The `AdmittedBranchRunner` sequence, mutation fence, provider/source-head authority, archive custody, persistence representation, terminal outcome strings, and WordPress ownership of installation are architectural invariants. A presentation refactor must not change them incidentally.

Production provider clients and host policies remain outside this package. Do not turn fixture transports into runtime providers or introduce a generic updater facade across release and branch domains.

Move code into `ran/updater-support` only when both updater packages implement the same non-trivial stable semantic rule and drift would create meaningful correctness/security risk without coupling domain-specific policy. Small conveniences stay local.

Add focused proof coverage when changing archive custody, mutation admission/fencing, recovery, persistence, provider head checks, or WordPress execution.

Do not commit credentials, tokens, private repository/site information, ZIPs, temporary files, logs, `vendor`, `node_modules`, or dependency caches. Use ordinary issues for non-sensitive work; follow [SECURITY.md](SECURITY.md) for vulnerabilities and [SUPPORT.md](SUPPORT.md) for support. See [RELEASING.md](RELEASING.md) before changing release automation or preparing a release.
