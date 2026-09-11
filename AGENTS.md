# Agent guidance

This is the independent `ran/wp-branch-updater` Composer library. Keep committed files suitable for public distribution and preserve the `RAN\WPBranchUpdater\V1` production namespace and PSR-4 path alignment.

Read [ARCHITECTURE.md](ARCHITECTURE.md) before changing production structure. Preserve the updater-family vocabulary by responsibility: Declaration, Provider, Adapter, Artifact, Runner, Coordinator, Journal, Store, State, Archive, Contract, Runtime, and WordPress. Do not create cosmetic symmetry with the release updater where runtime responsibilities differ.

- `BranchUpdater` declares targets; `BranchDeployment` is the target handle; `BranchDeploymentDeclaration` is immutable execution data; `deploy()` remains the public mutation verb.
- `AdmittedBranchRunner` owns the security-sensitive synchronous sequence. Do not reorder baseline acquisition, source/artifact rechecks, the mutation fence, WordPress Core execution, postcondition verification, cleanup, or terminal journaling.
- Providers own source-specific access; hosts own credentials, admission/deduplication, scheduling/webhooks, and recovery policy; WordPress Core owns installation.
- Keep archive custody/validation under `Archive`, stable interfaces under `Contract`, durable local state under `Persistence`, lifecycle orchestration under `Runtime`, and WordPress-specific adapters under `WordPress`.
- Move a rule into `ran/updater-support` only when it is semantically identical across updater packages, non-trivial, stable, drift-sensitive, and domain-neutral.
- Run `composer check` and the installed no-dev consumer proof before committing runtime or Composer changes. Preserve mutation-fence, custody, durability, terminal outcome, and diagnostic behavior.
- Keep fake transports and executors in development-only test support.
- Before changing release automation or preparing a release, read `RELEASING.md`.
- Every PR needs independent review against its exact base and head SHAs.
- Merging requires explicit owner authorization of the exact PR and merge method.
- Keep credentials, local logs, vendor files, temporary transformation artifacts, and internal planning out of commits.

## Blacksmith AI prohibition

Blacksmith is approved only as GitHub Actions runner infrastructure where a
repository workflow explicitly selects a Blacksmith runner.

- Never invoke, delegate work to, tag, enable, or otherwise use Blacksmith
  [code]smith, `@codesmith-bot`, Blacksmith Autofix, Blacksmith CI Tuning,
  Blacksmith Testbox agents, or any other Blacksmith AI/agent feature.
- Do not trigger "Enable autofix", ask [code]smith to investigate or repair CI,
  or call Blacksmith agent/MCP/CLI/API features that perform AI inference.
- If CI fails, inspect GitHub Actions logs directly and diagnose or fix the
  failure without delegating it to Blacksmith AI.
- This is a cost-control requirement. Do not override it for convenience, CI
  failures, review comments, or suggestions presented by GitHub or Blacksmith
  UI.
