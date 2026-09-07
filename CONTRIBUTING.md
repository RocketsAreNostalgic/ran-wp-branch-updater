# Contributing

This package is in a fresh pre-release development line. Keep every change safe
for public review and use Conventional Commits (`feat:`, `fix:`, `docs:`,
`test:`, or `chore:`) so Release Please can prepare version proposals.

Use PHP 8.2 and Node.js 24.11.0. Install Composer dependencies, then run the
repository gate:

```sh
composer install --no-interaction --prefer-dist
composer check
```

`composer check` validates Composer metadata, runs the package, admitted-runner,
and hard-stop contracts, runs release-publisher tests, and lints PHP. The CI
gate also proves a separate installed Composer consumer; see the installed
consumer proof in [README.md](README.md). Add focused proof coverage when
changing archive custody, a mutation fence, recovery, or WordPress execution.

Production provider clients and host policies remain outside this package. Do
not add fixture transports as runtime providers, and do not commit credentials,
tokens, private repository or site information, ZIPs, temporary files, logs,
`vendor`, or dependency caches. Use ordinary issues for non-sensitive work;
follow [SECURITY.md](SECURITY.md) for vulnerabilities and [SUPPORT.md](SUPPORT.md)
for support. See [RELEASING.md](RELEASING.md) before changing release automation
or preparing a release.
