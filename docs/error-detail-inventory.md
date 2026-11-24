# Error detail inventory and failure reasons

Refs [#111](https://github.com/RocketsAreNostalgic/ran-wp-branch-updater/issues/111).

Every terminal outcome stays one stable, closed string code. A failure can now
also carry an optional **reason** (a closed id) and an optional **detail** (a
short screened string). They refine the code; they never replace it, and a
consumer that ignores them sees exactly the behaviour it saw before.

## How a consumer reads them

| Surface | Use |
| --- | --- |
| `BranchDeployment::last_reason()` | `?AdmittedFailureReason` for the last `deploy()`. Also set before an ambiguous durability failure escapes `deploy()`. |
| `AdmittedBranchRunner::last_reason()` | The same record from the runner. |
| `Contract\AdmittedAttemptReasonJournal` | Optional journal capability. The runner calls `finish_with_reason( $code, $reason )` when the journal implements it and a reason exists. Otherwise it calls `finish( $code )`. |
| `ReasonedFailure::find( Throwable )` | The nearest reason-carrying failure in an exception chain. Archive adapters can use it when handling exceptions from `PreparedArchive::download_and_validate()`. |
| Escaping durability failure `reason()` / `as_reason()` | Each documented durability failure that may escape `run()` (`AdmittedBranchDurabilityFailure`, `BranchDeploymentJournalFailure`, `BranchDeploymentLockReleaseFailure`, `BranchDeploymentLockStorageFailure`) exposes a closed `reason(): FailureReason` and `as_reason(): AdmittedFailureReason` without reading exception messages or chained causes. |

`finish_with_reason()` evolves by adding fields to the final readonly `AdmittedFailureReason`; changing its signature needs a new interface, not an edit of this one.

`AdmittedFailureReason` has `reason` (`FailureReason`), `detail` (`?string`),
`primary_outcome` (`?string`) and `cleanup_failed` (`bool`).
Failure reasons alone do not change the admitted API. The target-directory
contract now requires admitted API `4`; see [MIGRATING.md](../MIGRATING.md).

## Guarantees

* `reason` is always one value of the closed `FailureReason` enum, each matching
  `/\A[a-z][a-z0-9_]{0,47}\z/`. Values are stable. A new value is additive; removing
  or renaming one is a breaking change.
* `detail` is `null` or at most 64 characters of `[A-Za-z0-9 ._-]`. It is **untrusted
  text**: a consumer must still escape it for its output context.
* **What the screener is, and is not.** `screen_detail()` is the shared
  `RAN\UpdaterSupport\V1\FailureText::strict( $detail, 64 )` shape screen plus this
  package's own caller-side charset allow-list (`[A-Za-z0-9 ._-]`, ASCII). The whole
  input must be inside the allow-list, else the detail is dropped whole; the shared
  screener then screens the whole input and only afterwards bounds the result to 64
  characters. It is a safety net by shape, not a proof that text is safe. The real
  control is that every call site passes only a closed literal or an allow-listed value,
  never an exception, WP_Error or provider message; `tests/failure-detail-screening.php`
  fails if a call site passes any other expression. The shared rules and their dropped
  benign shapes are documented in the ran-updater-support README (FailureText), and
  the shared hostile/benign corpus from the pinned package is asserted through the
  record and the reasoned failure in the same test.
* Every detail passes through the single function
  `AdmittedFailureReason::screen_detail()`, whichever way it was supplied. Unsafe input
  is dropped whole, never redacted or partly kept. Dropped by the shared screener:
  markup, paths and URL shapes, credential words followed by text, token and key
  prefixes, digest and hex shapes, long mixed or encoded runs, and non-printable or
  non-ASCII text.
* No exception message, WP_Error message, provider message, response body, path, URL, token or
  digest is ever read into a reason or detail. Only closed ids, allow-listed WordPress
  upgrader error codes, header names, an HTTP status in 100-599 and this package's own
  closed causes are passed on.
* Trade-off: screening is by shape, so harmless text that looks like a credential or an
  application password is dropped too (for example six or more four-character words in
  a row). Words that merely contain a credential word, such as `tokenizer_failed`, are
  kept by the shared screener. Every closed source in this package passes unchanged:
  the five header names, `http_100` to `http_599`, the allow-listed upgrader codes, the
  source failures, the closed theme and archive causes, `php_error`, `exception` and the
  custody causes.
* A failing cleanup beside another cause or after a verified `deployed` outcome never
  replaces that outcome: the primary outcome (including `deployed`) is returned and
  journaled, and the cleanup failure is recorded on `last_reason()`. `reason` is the
  primary reason, or when the primary had none, the cleanup exception's `ReasonedFailure`
  (for example `archive_custody_changed` with its closed detail) before falling back to
  `cleanup_also_failed`; `primary_outcome` is `null` (because nothing was superseded),
  and `cleanup_failed` is `true`. `archive_cleanup_failed` remains in the closed outcome
  vocabulary for compatibility, though the runner no longer emits it in place of a
  primary failure or verified `deployed` outcome.

## Reason vocabulary

| Group | Reasons | Detail |
| --- | --- | --- |
| Provider | `selected_commit_unsupported`, `repository_mismatch`, `ref_mismatch`, `provider_error` | `provider_error`: `http_<status>` when the provider's exception code is 100-599, else none |
| Archive | `archive_prepare_failed` (a failure that is not an archive validation), `archive_custody_changed` | custody: `cleaned`, `identity` or `digest` |
| Validator | `zip_unavailable`, `zip_invalid`, `entry_limit`, `entry_invalid`, `path_unsafe`, `path_collision`, `layout_invalid`, `entry_unsupported`, `entry_encrypted`, `compressed_too_large`, `expanded_too_large`, `archive_corrupt`, `subdirectory_missing`, `package_dir_missing`, `identity_mismatch`, `header_missing`, `header_unreadable`, `version_missing`, `version_invalid`, `compat_invalid`, `requires_newer_php`, `requires_newer_wp`, `plugin_missing`, `theme_missing`, `multiple_plugins` | header failures: `Plugin Name`, `Theme Name`, `Version`, `Requires PHP` or `Requires at least` |
| Preflight | `preflight_multisite`, `preflight_file_mods_disabled`, `preflight_filesystem_not_direct`, `preflight_maintenance_active` | none |
| Target policy | `target_is_working_copy` | none |
| Lock | `lock_held`, `lock_token_invalid` (missing or malformed token), `lock_stale_release_failed`, `lock_stale_reinsert_failed`, `lock_storage`, `lock_release` | none |
| WordPress Core | `core_invalid_request`, `core_wordpress_refused`, `core_wordpress_failed`, `core_wordpress_restored`, `core_wordpress_uncertain`, `core_operation_mismatch` | an allow-listed upgrader WP_Error code, or a closed cause (see below) |
| Postcondition | `baseline_unreadable`, `baseline_changed`, `identifier_mismatch`, `adopt_failed` | none |
| Ambiguous | `durability_uncertain`, `journal_uncertain` | none |
| Cleanup | `cleanup_also_failed` | none |

The 25 validator reasons group the 28 numeric `ArchiveValidator::CODE_*` causes:
`CODE_FILE_PARENT_COLLISION` is `path_collision`, `CODE_MULTIPLE_ROOTS` is `layout_invalid`,
`CODE_THEME_IDENTITY_MISMATCH` is `identity_mismatch`. The numeric codes and fixed messages
are unchanged.

Closed Core details: `exception`, `php_error` (a thrown Throwable; its class, code and message are
withheld), `hook_removal_failed`, `input_invalid`, `archive_changed`, `identifier_mismatch`,
`artifact_unsupported`, `theme_archive_invalid`, `theme_parent_invalid`, `theme_parent_missing`,
and the source-selection causes `source_invalid`, `subdirectory_missing`, `destination_exists`,
`move_failed`.

## Inventory

Line numbers describe this branch and drift; the ID is the stable key. Status: **fixed** = the
reason is recorded and tested; **documented** = deliberately unchanged, rationale given;
**consumer** = the remaining work is in the consuming host.

| ID | File:line | Stage | Known at site | Leaves site | Class | Reason id | Redaction rationale | Fix / test | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| E02 | Host `AdmittedArchiveSource` adapter | validate | archive preparation or validation failure | host stage outcome | B | validator reason or `archive_prepare_failed` | closed id and allow-listed detail | `ReasonedFailure::find()` in the admitted archive test fixture in `tests/failure-reasons.php` | consumer |
| E03 | `Archive/ArchiveValidator.php:293-336` | validate | which header failed | collapsed code | B | `header_*`, `version_*`, `compat_invalid`, `requires_newer_*` | closed header names only | detail header name; `tests/failure-reasons.php` | fixed |
| E04 | `WordPress/WordPressCorePackageExecutor.php:361` | replace | WP_Error code and message | `WORDPRESS_FAILED` | B and A | `core_wordpress_failed` | message can hold paths: always dropped; code only if allow-listed | `error_detail()`; `tests/failure-reasons-wordpress.php` | fixed |
| E05 | `WordPressCorePackageExecutor.php:74,114` | replace | Throwable class, code, message | `WORDPRESS_UNCERTAIN` | B and A | `core_wordpress_uncertain` | only `exception` or `php_error`; class and message may be foreign or hold paths | `tests/failure-reasons-wordpress.php` | fixed (coarse by design) |
| E06 | `WordPressCorePackageExecutor.php:221-244` | replace | which of 5 source-selection failures | one WP_Error code | B | `core_wordpress_failed` | 4 closed causes in the WP_Error data | `invalid_package_source( $cause )`; `tests/failure-reasons-wordpress.php`, `tests/core-package-error-code.php` | fixed |
| E07 | `Runtime/AdmittedBranchRunner.php` | cleanup | primary cause and cleanup failure | primary outcome preserved; cleanup recorded on `last_reason()` | C | primary reason, cleanup `ReasonedFailure`, or `cleanup_also_failed`, plus `cleanup_failed` | custody cause only; generic cleanup message never read | `tests/failure-reasons.php` class C cases, `tests/admitted-runner.php` | fixed |
| E08 | `AdmittedBranchRunner.php` | postcondition | Core failure; fresh-install target absence or baseline restored | `upgrader_failed` for definite failure/restoration, `restoration_uncertain` when uncertain | B | `core_*`, `baseline_unreadable`, `baseline_changed` | closed ids | `tests/failure-reasons.php`, `tests/architecture-normalisation-baseline.php` | fixed |
| E09 | Host `AdmittedPackageExecutor::preflight()` | preflight | host policy refusal | `preflight_failed` or stage | B | host-supplied typed reason | generic exceptions supply no reason | `tests/failure-reasons.php` | consumer |
| E10 | `WordPress/WordPressUpdaterLock.php` | lock | held or malformed | `lock_unavailable` | B | `lock_held`, `lock_token_invalid` | closed ids | `tests/failure-reasons-wordpress.php`, `tests/installed-wordpress.php` | acquisition retries races, then reports current contention. Historical `lock_stale_release_failed` and `lock_stale_reinsert_failed` ids remain valid |
| E12 | `AdmittedBranchRunner.php:98,111` | postcondition | identifier mismatch vs refused adoption | `persistence_uncertain` | B | `identifier_mismatch`, `adopt_failed` | closed ids | `tests/failure-reasons.php` | fixed |
| E14 | `Archive/PreparedArchive.php:88-98` | validate | validation failure plus cleanup result | rethrown with the primary chained | none | the chained validator reason | host can read the chain as in E02 | `ReasonedFailure::find()` | fixed |
| E15 | `Archive/PreparedArchive.php:132` | custody | cleaned, identity or digest changed | one message | B | `archive_custody_changed` | closed details only; digest never shown | `tests/failure-reasons-wordpress.php`, `tests/failure-reasons.php` | fixed |
| E17 | `WordPressCorePackageExecutor.php:265` | replace | theme archive, parent invalid or missing | `INVALID_REQUEST` | B | `core_invalid_request` | closed causes only; the parent name is not passed | `tests/failure-reasons-wordpress.php`; `theme_parent_missing` needs real WordPress and is covered only by the installed lane | fixed |
| E18 | `Runtime/BranchUpdater.php:92-93`, `RAN\UpdaterSupport\V1\PackageDirectory::normalize()` | input | exact invalid field | fixed text | A | none | caller-input validation text is already specific enough | none | documented |
| E19 | `Runtime/Admitted*Failure.php`, `Branch*Failure.php`, `Persistence/BranchDeploymentJournalFailure.php` | durability | previous chain | thrown out of `run()` with closed `reason()` / `as_reason()` | C | `durability_uncertain`, `journal_uncertain`, `lock_storage`, `lock_release` | closed ids; message and `previous` never read | `reason()`, `as_reason()`, and `last_reason()`; `tests/failure-reasons.php` | fixed here; the host must log class and `reason()` (consumer) |
| E20 | `AdmittedBranchRunner.php` | cleanup | verified `deployed` outcome plus cleanup failure | `deployed` preserved; cleanup recorded on `last_reason()` | C | cleanup `ReasonedFailure` or `cleanup_also_failed`, plus `cleanup_failed` | custody cause only; generic cleanup message never read | `tests/failure-reasons.php`, `tests/admitted-runner.php` | fixed |
| E21 | `Runtime/AdmittedBranchRunner.php:91` | locked policy | target has a VCS marker | `policy_blocked` | B | `target_is_working_copy` | no path, marker contents or other detail leaves the check | `tests/failure-reasons.php` admitted marker matrix and late-lock marker, `tests/installed-wordpress.php` refusal and override | fixed |

For E10, the adapter reclaims expired rows through its exact-token release and
retries races with other adapter callers. WordPress Core's
`WP_Upgrader::create_lock()` handles expiry through `release_lock()`, which
deletes the option by name. A Core caller that already observed the expired
row can therefore delete a newly acquired token. The installed WordPress proof
covers live mutual exclusion and this upstream expiry boundary.

## What a host must change to consume this

1. Where it reads the outcome, also read `$deployment->last_reason()` (or implement
   `AdmittedAttemptReasonJournal::finish_with_reason()` to receive it with the terminal write).
2. Where it catches an exception from `PreparedArchive::download_and_validate()`, call
   `ReasonedFailure::find( $exception )` and use its `reason` and `detail`.
3. Treat `detail` as untrusted text and escape it.
4. Catch and log the documented durability failures that escape `deploy()`; each exposes
   its closed `reason()` / `as_reason()`, and `last_reason()` is also set before escape.
