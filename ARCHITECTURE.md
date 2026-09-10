# Updater Family Architecture

The RAN WordPress updater packages share architectural language where responsibilities genuinely match. They do not share a generic updater facade, and they do not force identical runtime shapes onto different domains.

## Shared grammar

Both updater domains can be read using the same broad flow:

```text
Declaration
→ Provider
→ Artifact acquisition
→ Validation
→ Mutation admission
→ WordPress Core
→ Postcondition verification
```

House terms describe responsibility:

| Term | Responsibility |
| --- | --- |
| Declaration | immutable requested or admitted facts |
| Provider | source-specific remote access and source authority |
| Adapter | translation into a neutral lifecycle contract |
| Artifact | acquired bytes held under controlled custody |
| Runner | one synchronous ordered execution |
| Coordinator | state management spanning separate callbacks/events |
| Journal | durable transition/history interface |
| Store | durable object persistence |
| State | bounded lifecycle data |
| Archive | ZIP, layout, identity and custody concerns |
| Contract | stable interfaces and value contracts |
| Runtime | package lifecycle and orchestration |
| WordPress | WordPress-specific integration |

Parallel names imply parallel responsibilities. Similar-looking positions in two workflows are not enough to justify identical names.

## Branch updater

The branch updater performs an explicitly requested deployment. Its consumer flow is:

```text
bootstrap/configure
→ declare plugin or theme
→ receive BranchDeployment
→ deploy()
```

`Runtime\BranchUpdater` creates target-specific declarations. `Runtime\BranchDeployment` is the deployment handle. `Runtime\BranchDeploymentDeclaration` holds immutable execution facts. `Runtime\AdmittedBranchRunner` owns the ordered execution of one admitted attempt; `Runtime\StandaloneBranchRunner` composes the standalone package around it.

The runner preserves this security-sensitive ordering:

```text
target admission
→ baseline
→ artifact preparation
→ preflight
→ resolved-ref journal entry
→ mutation lock
→ baseline recheck
→ source-head recheck
→ artifact recheck
→ second policy/preflight
→ mutation fence
→ WordPress Core mutation
→ postcondition verification
→ cleanup
→ terminal journal result
```

Providers are supplied by the host. The package does not own long-lived provider credentials, webhook authentication, scheduling, or admission/deduplication policy.

## Release updater

The release updater joins WordPress's native future update lifecycle rather than immediately deploying a requested branch. Its consumer grammar therefore ends in `register()` rather than `deploy()`.

Release state can span discovery and later WordPress callbacks. A **Coordinator** is therefore appropriate where durable state spans events; renaming such a component to `Runner` merely for symmetry would misdescribe its responsibility.

Its release domain also has a sealed provider/runtime trust model. Providers and selected runtime copies are not equivalent to the branch package's host-supplied provider contract, even when both sides use the word **Provider** for source-specific work.

## WordPress ownership

Both packages treat WordPress Core as the installation owner. Package code may validate, admit, scope and verify the operation, but it does not replace the WordPress upgrader with a parallel installer.

In the branch updater, `WordPress\WordPressPackageExecutor` adapts the deployment contract and `WordPress\WordPressCorePackageExecutor` owns the narrowly scoped Core call. `WordPress\WordPressUpdaterLock` participates in mutation exclusion without changing Core's installation ownership.

## Artifact and validation ownership

The branch package's `Archive` namespace owns branch ZIP acquisition/custody and package validation. Security-sensitive archive path/type/collision primitives that have genuinely identical semantics across packages may live in `ran/updater-support`.

The release updater may wrap those same low-level rules inside its own archive scanner, sealed-runtime and release-identity policies. Similar implementations are not automatically shared when their surrounding contracts or trust models differ.

## Dependency model

The dependency models intentionally differ:

- `ran/wp-branch-updater` may consume `ran/updater-support` as a normal production Composer dependency.
- `ran/wp-release-updater` may consume the helper during development/build and package a generated/sealed runtime copy when required by physical-runtime selection and trust-boundary rules.

This is an architectural distinction, not dependency drift to normalize away.

## Boundary rule for shared support

Move a rule into `ran/updater-support` only when all of these are true:

1. both updater packages implement the same semantic rule;
2. the implementation is non-trivial;
3. drift would create meaningful correctness or security risk;
4. the contract is stable enough to version deliberately;
5. extracting it does not couple branch-specific or release-specific policy.

Small conveniences should remain local. Shared support exists to centralize stable security/correctness policy, not to maximize line-count deduplication.

The completed bounded review and candidate-by-candidate decisions are recorded in [SHARED-SUPPORT.md](SHARED-SUPPORT.md).
