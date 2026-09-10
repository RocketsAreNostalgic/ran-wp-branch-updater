# Agent guidance

This is the independent `ran/wp-branch-updater` Composer library. Keep committed
files suitable for public distribution and preserve the `RAN\WPBranchUpdater\V1`
public namespace. Providers own network credentials and source-specific access;
WordPress Core owns installation.

- Run `composer check` and the installed consumer proof before committing runtime
  or Composer changes. Preserve mutation-fence, custody and durability failures.
- Keep fake transports and executors in development-only test support.
- Before changing release automation or preparing a release, read `RELEASING.md`.
- Every PR needs independent review against its exact base and head SHAs.
- Merging requires explicit owner authorization of the exact PR and merge method.
- Keep credentials, local logs, vendor files and internal planning out of commits.
