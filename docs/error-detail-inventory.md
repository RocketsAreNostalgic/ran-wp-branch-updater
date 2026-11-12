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
| `AdmittedBranchRunner::last_reason()` / `StandaloneBranchRunner::last_reason()` | The same record from the runner. |
| `Contract\AdmittedAttemptReasonJournal` | Optional journal capability. The runner calls `finish_with_reason( $code, $reason )` instead of `finish( $code )` only when the journal implements it and a reason exists. The code passed is the same code `finish()` would receive. `FileAttemptJournal` does not implement it and its durable record is unchanged. |
| `ReasonedFailure::find( Throwable )` | The nearest reason-carrying failure in an exception chain. A host that calls `PreparedArchive::download_and_validate()` itself (as Booster does) uses this on the exception it catches. |

`finish_with_reason()` evolves by adding fields to the final readonly `AdmittedFailureReason`; changing its signature needs a new interface, not an edit of this one.

`AdmittedFailureReason` has `reason` (`FailureReason`), `detail` (`?string`),
`primary_outcome` (`?string`) and `cleanup_failed` (`bool`).
`ADMITTED_API_VERSION` stays `3`: no existing signature changed.

## Guarantees

* `reason` is always one value of the closed `FailureReason` enum, each matching
  `/\A[a-z][a-z0-9_]{0,47}\z/`. Values are stable. A new value is additive; removing
  or renaming one is a breaking change.
* `detail` is `null` or at most 64 characters of `[A-Za-z0-9 ._-]`. It is **untrusted
  text**: a consumer must still escape it for its output context.
* **What the screener is, and is not.** `screen_detail()` is a charset allow-list
  (`[A-Za-z0-9 ._-]`, ASCII) plus a best-effort, heuristic deny-list. It is not a proof that
  arbitrary text is safe. Safety rests on callers passing only closed literals or allow-listed
  values, never an exception, WP_Error or provider message; `tests/failure-detail-screening.php`
  fails if a call site passes any other expression. The function is a stopgap that a shared
  failure-text screener is to replace.
* Every detail passes through the single function
  `AdmittedFailureReason::screen_detail()`, whichever way it was supplied. It screens the
  **complete** input first and bounds the length only afterwards. Unsafe input is dropped
  whole, never redacted or partly kept. Dropped: non-printable and non-ASCII text, markup
  (`<`, `>`, `&`), paths (`/`, `\`, `~`, `..`, drive letters), URLs and userinfo (`:`, `@`),
  credential-looking words (token, secret, password, credential, bearer, authorization,
  cookie, session, signature, api/private key, `key` as a word, common key prefixes),
  digest-shaped text (`sha`/`md` prefixes, any 16 or more hex characters with or without
  separators, short hex ids that mix digits and letters, long mixed alphanumeric tokens)
  and input over 1024 characters.
* No exception message, WP_Error message, provider message, response body, path, URL, token or
  digest is ever read into a reason or detail. Only closed ids, allow-listed WordPress
  upgrader error codes, header names, an HTTP status in 100-599 and this package's own
  closed causes are passed on.
* Trade-off: screening is by shape, so harmless text that looks like a credential is
  dropped too (for example `tokenizer_failed` or `session_expired`). A short hex-looking
  word under 7 characters, or hex without a digit, is not recognised as an id; none of the
  closed sources produces one.
* A failing cleanup beside another cause is **recorded, not acted on**: the outcome code is
  unchanged (`archive_cleanup_failed`, or `interrupted` once fenced) and the reason is recorded.
  `reason` is the primary reason (or `cleanup_also_failed` when the primary had none),
  `primary_outcome` is the outcome the cleanup replaced (`interrupted` when fenced, because
  that is what the runner would have returned), and `cleanup_failed` is `true`. The runner
  still returns `restoration_uncertain` or `interrupted` where it did before; this change
  does not fix those outcomes, it only records why.

## Reason vocabulary

| Group | Reasons | Detail |
| --- | --- | --- |
| Provider | `selected_commit_unsupported`, `repository_mismatch`, `ref_mismatch`, `provider_error` | `provider_error`: `http_<status>` when the provider's exception code is 100-599, else none |
| Archive | `archive_prepare_failed` (a failure that is not an archive validation), `archive_custody_changed` | custody: `cleaned`, `identity` or `digest` |
| Validator | `zip_unavailable`, `zip_invalid`, `entry_limit`, `entry_invalid`, `path_unsafe`, `path_collision`, `layout_invalid`, `entry_unsupported`, `entry_encrypted`, `compressed_too_large`, `expanded_too_large`, `archive_corrupt`, `subdirectory_missing`, `package_dir_missing`, `identity_mismatch`, `header_missing`, `header_unreadable`, `version_missing`, `version_invalid`, `compat_invalid`, `requires_newer_php`, `requires_newer_wp`, `plugin_missing`, `theme_missing`, `multiple_plugins` | header failures: `Plugin Name`, `Theme Name`, `Version`, `Requires PHP` or `Requires at least` |
| Preflight | `preflight_multisite`, `preflight_file_mods_disabled`, `preflight_filesystem_not_direct`, `preflight_maintenance_active` | none |
| Lock | `lock_held`, `lock_token_invalid` (missing or malformed token), `lock_stale_release_failed`, `lock_stale_reinsert_failed`, `lock_storage`, `lock_release` | none |
| WordPress Core | `core_invalid_request`, `core_runtime_unsupported`, `core_wordpress_refused`, `core_wordpress_failed`, `core_wordpress_restored`, `core_wordpress_uncertain`, `core_operation_mismatch` | an allow-listed upgrader WP_Error code, or a closed cause (see below) |
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
| E01 | `Runtime/ProviderArchiveSource.php:55` | validate | validator cause and bounded message | `archive_integrity_failed` | B | the 25 validator reasons, or `archive_prepare_failed` | message dropped; only the closed id and header name pass | `ReasonedFailure::find()`; `tests/failure-reasons.php` zip cases | fixed |
| E02 | Booster `AdmittedBranchHostAdapter` (not in this repo) | validate | same as E01 | `archive_integrity_failed`, no log | B | as E01 | as E01 | Booster reads `ReasonedFailure::find()` on its own catch | consumer |
| E03 | `Archive/ArchiveValidator.php:293-336` | validate | which header failed | collapsed code | B | `header_*`, `version_*`, `compat_invalid`, `requires_newer_*` | closed header names only | detail header name; `tests/failure-reasons.php` | fixed |
| E04 | `WordPress/WordPressCorePackageExecutor.php:361` | replace | WP_Error code and message | `WORDPRESS_FAILED` | B and A | `core_wordpress_failed` | message can hold paths: always dropped; code only if allow-listed | `error_detail()`; `tests/failure-reasons-wordpress.php` | fixed |
| E05 | `WordPressCorePackageExecutor.php:74,114` | replace | Throwable class, code, message | `WORDPRESS_UNCERTAIN` | B and A | `core_wordpress_uncertain` | only `exception` or `php_error`; class and message may be foreign or hold paths | `tests/failure-reasons-wordpress.php` | fixed (coarse by design) |
| E06 | `WordPressCorePackageExecutor.php:221-244` | replace | which of 5 source-selection failures | one WP_Error code | B | `core_wordpress_failed` | 4 closed causes in the WP_Error data | `invalid_package_source( $cause )`; `tests/failure-reasons-wordpress.php`, `tests/core-package-error-code.php` | fixed |
| E07 | `Runtime/AdmittedBranchRunner.php:139,159` | cleanup | primary cause and cleanup failure | `archive_cleanup_failed` replaced the cause | C | primary reason or `cleanup_also_failed`, plus `primary_outcome`, `cleanup_failed` | cleanup message never read | `tests/failure-reasons.php` class C cases | reason recorded; outcome code unchanged |
| E08 | `AdmittedBranchRunner.php:112-128` | postcondition | Core failure; baseline readable or changed | `restoration_uncertain` and others | B | `core_*`, `baseline_unreadable`, `baseline_changed` | closed ids | `tests/failure-reasons.php` | fixed |
| E09 | `WordPress/WordPressPackageExecutor.php:41-57`, `AdmittedBranchRunner.php:153` | preflight | which of 4 preflight conditions | `preflight_failed` or stage | B | `preflight_*` | fixed internal messages, closed ids | `tests/failure-reasons-wordpress.php`, `tests/failure-reasons.php` | fixed; a plain `RuntimeException` from a foreign executor stays reason-less (text is untrusted) |
| E10 | `Persistence/FileMutationLock.php:20`, `WordPress/WordPressUpdaterLock.php:66-95` | lock | held, malformed, release failed, re-insert failed | `lock_unavailable` | B | `lock_held`, `lock_token_invalid`, `lock_stale_release_failed`, `lock_stale_reinsert_failed` | closed ids | `tests/failure-reasons-wordpress.php`; an overriding `contention_failure()` keeps today's behaviour | fixed |
| E11 | `Runtime/ProviderArchiveSource.php:43` | provider | provider exception code and message | `provider_failed` | B and A | `provider_error` | only an HTTP status 100-599; message dropped | `tests/failure-reasons.php` | fixed |
| E12 | `AdmittedBranchRunner.php:98,111` | postcondition | identifier mismatch vs refused adoption | `persistence_uncertain` | B | `identifier_mismatch`, `adopt_failed` | closed ids | `tests/failure-reasons.php` | fixed |
| E13 | `Runtime/StandalonePackageExecutor.php:40` | replace | legacy `PackageExecutor` exception | `WORDPRESS_FAILED` | A | `core_wordpress_failed`, no detail | consumer-supplied text is untrusted | none | documented |
| E14 | `Archive/PreparedArchive.php:88-98` | validate | validation failure plus cleanup result | rethrown with the primary chained | none | the chained validator reason | no loss at this site; the chain is now read at E01 | `ReasonedFailure::find()` | fixed |
| E15 | `Archive/PreparedArchive.php:132` | custody | cleaned, identity or digest changed | one message | B | `archive_custody_changed` | closed details only; digest never shown | `tests/failure-reasons-wordpress.php`, `tests/failure-reasons.php` | fixed |
| E16 | `Persistence/FileAttemptStore.php:165-179` | journal | six journal corruption causes | `Journal is malformed.` | B (low) | `journal_uncertain` | file contents and paths are not exposed | the exception chain keeps the JSON error; per-cause ids not added | documented |
| E17 | `WordPressCorePackageExecutor.php:265` | replace | theme archive, parent invalid or missing | `INVALID_REQUEST` | B | `core_invalid_request` | closed causes only; the parent name is not passed | `tests/failure-reasons-wordpress.php`; `theme_parent_missing` needs real WordPress and is covered only by the installed lane | fixed |
| E18 | `Runtime/BranchUpdater.php:92-93`, `Archive/PackageSubdirectory.php:28` | input | exact invalid field | fixed text | A | none | caller-input validation text is already specific enough | none | documented |
| E19 | `Runtime/Admitted*Failure.php`, `Branch*Failure.php` | durability | previous chain | thrown out of `run()` | C | `durability_uncertain`, `journal_uncertain`, `lock_storage`, `lock_release` | closed ids | `last_reason()` is set before the escape; `tests/failure-reasons.php` | fixed here; the host must read it and log (consumer) |
| E20 | `AdmittedBranchRunner.php:139` | cleanup | an outcome returned normally (for example `deployed`) | replaced by `interrupted` or `archive_cleanup_failed` | C | `cleanup_also_failed` and `primary_outcome` | none | `tests/failure-reasons.php` | reason recorded; outcome code unchanged |

## What a host must change to consume this

1. Where it reads the outcome, also read `$deployment->last_reason()` (or implement
   `AdmittedAttemptReasonJournal::finish_with_reason()` to receive it with the terminal write).
2. Where it catches an exception from `PreparedArchive::download_and_validate()`, call
   `ReasonedFailure::find( $exception )` and use its `reason` and `detail`.
3. Treat `detail` as untrusted text and escape it.
4. Catch and log the ambiguous failures that escape `deploy()`; their reason is on `last_reason()`.
