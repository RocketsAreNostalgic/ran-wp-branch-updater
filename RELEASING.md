# Releases

This package uses the organisation-owned **Profile A** release lifecycle.

Release Please owns semantic version selection, changelog generation, the managed release PR, tag creation, and the GitHub Release. The repository-local workflow is only a thin caller of the pinned shared Profile A contract in `RocketsAreNostalgic/.github`.

## Repository setup

The repository uses protected `main` with required terminal `quality`. Production releases are immutable. `CI` supports input-free `workflow_dispatch` so the shared Profile A contract can qualify the exact bot-created Release Please PR head when GitHub suppresses recursive `pull_request` events from `GITHUB_TOKEN`.

Ordinary pull requests follow the repository's approved merge policy. Publication no longer depends on a local two-parent merge proof, lifecycle-label reconciler, repository-ID acknowledgement variable, or historical publisher recovery path.

## Prepare and publish

1. Merge reviewed changes through the protected PR process. Exact `main` CI must succeed.
2. Shared Profile A admits only the canonical successful same-repository `main` CI revision and runs Release Please against current `main`.
3. If Release Please creates or updates its bot-owned release PR, Profile A binds the configured release branch to its exact head and dispatches this repository's existing read-only `CI` only when that head lacks successful or in-flight qualification.
4. Review the generated version/changelog proposal and merge only after required checks and review complete.
5. Exact `main` CI for the merged release revision admits Release Please again; Release Please creates the version tag and GitHub Release under the organisation immutable-release baseline.

Release Please is authoritative for prerelease progression, including legitimate SemVer-core changes. The repository does not maintain a second version engine, publication state machine, or standing historical replay path.

If publication fails, repair the cause through reviewed source/configuration and fresh qualification. Do not manually create or move tags, rewrite the manifest backwards, bypass failed checks, or reintroduce repository-local recovery authority.

## Release classification

The repository retains its package-specific release-classification checks for production dependency/significance policy. That Quality concern is separate from generic publication authority and may be simplified independently if it later fails the programme deletion test.

## Prerelease consumption

A consuming root project can declare the GitHub VCS repository and require `ran/wp-branch-updater` using a reviewed prerelease or, for development only, `dev-main`. Commit the root project's lockfile and verify the selected source identity.

`ran/updater-support` is a versioned production dependency. Until it leaves its beta line, source-based consumers must admit beta packages and declare the updater-support VCS repository so Composer can discover the published tag.
