# Shared Updater Support Assessment

This document records the bounded shared-support review performed after the branch updater architectural normalisation.

The extraction rule is intentionally strict. A rule belongs in `ran/updater-support` only when both updater packages implement the same semantic rule, the implementation is non-trivial, drift would create meaningful correctness or security risk, the contract is stable enough to version, and extraction does not couple branch-specific or release-specific policy.

## Result

No additional extraction is justified at this point.

`RAN\UpdaterSupport\V1\ArchiveSafety` remains the correct shared boundary. It centralizes the low-level archive rules that are genuinely identical across the updater family: ZIP path normalization, entry-type classification, and path collision/file-parent collision detection.

The remaining apparent similarities do not meet the full extraction threshold.

| Candidate | Decision | Reason |
| --- | --- | --- |
| Full archive scanning / validation | Keep domain-local | Both packages use shared `ArchiveSafety`, but their surrounding archive policies differ. The release scanner owns release-specific inventory/root/compression-limit projection; the branch validator also owns branch package identity, header/version compatibility, CRC/content verification and different size/admission semantics. |
| Repository package-subdirectory normalization | Keep in branch `Archive` | This is a branch declaration/install-shape rule. The release updater does not implement the same repository-subdirectory contract. |
| Installed-package identity | Keep in each `WordPress` domain | Branch normalization validates its installed identifier contract. Release resolution verifies an installed absolute WordPress target across configured roots, symlink/race conditions, headers and compatibility. These are not the same semantic operation. |
| Temporary artifact/file identity custody | Keep domain-local | Both are security-sensitive, but lifecycle ownership differs materially. Branch `PreparedArchive` creates/acquires and retains exact downloaded-byte custody through deployment. Release temporary-artifact custody includes selected-runtime liveness, inspection/busy state, destructor/discard semantics and richer identity evidence. A common abstraction would either weaken one contract or encode domain-specific policy. |
| Mutation locking | Keep domain-local | Branch locking protects one synchronous deployment and includes the WordPress updater lock adapter. Release state/locking participates in a callback-spanning binding/CAS model. |
| Journals / persistence | Keep domain-local | Branch attempt journaling and release binding/coordinator persistence have different state machines and durability semantics. |
| Providers | Keep domain-local | Branch providers are host-supplied source adapters; release providers operate inside a sealed provider/runtime trust model. |
| Runner/coordinator/result hierarchy | Do not extract | Similar lifecycle positions do not imply equivalent responsibility. A generic updater framework would obscure the intentional synchronous-runner versus callback-coordinator distinction. |

## Why file identity is not extracted yet

Regular-file, symlink, mode, link-count, inode/device and digest checks are individually familiar on both sides, but the objects using them do not currently expose one stable shared semantic contract. The branch updater needs identity continuity from provider download through the WordPress boundary. The release updater has multiple custody contexts with additional runtime-liveness and state-transition requirements.

If future work demonstrates one identical pure file-identity rule used unchanged in both packages, it can be reconsidered independently. The current similarity is not sufficient to introduce a shared API.

## Hygiene result

The normalized branch-updater tree was checked against the architectural constraints documented here:

- production classes are under `RAN\WPBranchUpdater\V1` and PSR-4-aligned domain paths;
- the previous beta namespace and class vocabulary are retained only in `MIGRATING.md`;
- there is no production Composer classmap fallback;
- the remaining classmap is development-only test support;
- there are no compatibility aliases or migration shims;
- old flat production source files removed by the domain split are absent;
- significant production objects/interfaces are independently addressable rather than hidden in structural multi-object files;
- remaining PHPCS suppressions are tied to actual filesystem, test-fixture, or justified diagnostic-boundary behavior rather than obsolete production file-structure exceptions;
- no temporary transformation script or rollout artifact is required by the runtime tree.

Accordingly, no `ran/updater-support` code change or further production refactor is justified by this review.
