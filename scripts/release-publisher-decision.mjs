import { BETA, refuse } from "./release-publisher-content.mjs";

const FULL_SHA = /^[a-f0-9]{40}$/;
const RELEASE_BRANCH = "release-please--branches--main--components--ran/wp-branch-updater";
const RELEASE_PATHS = [".release-please-manifest.json", "CHANGELOG.md"];
export const PENDING_LABEL = "autorelease: pending";
export const TAGGED_LABEL = "autorelease: tagged";
const BOT_LOGIN = "github-actions[bot]";

export function labels(pull) {
  return Array.isArray(pull?.labels)
    ? pull.labels.map((label) => typeof label === "string" ? label : label?.name)
    : [];
}

function shaped(pull) {
  const pullLabels = labels(pull);
  return pull?.head?.ref === RELEASE_BRANCH
    || pullLabels.includes(PENDING_LABEL)
    || pullLabels.includes(TAGGED_LABEL);
}

function exactPulls(pulls, candidateSha) {
  const releasePulls = pulls.filter(shaped);
  return {
    exact: releasePulls.filter((pull) =>
      pull?.state === "closed"
      && typeof pull?.merged_at === "string"
      && pull?.merge_commit_sha === candidateSha
    ),
    stale: releasePulls.filter((pull) =>
      pull?.state === "closed"
      && typeof pull?.merged_at === "string"
      && pull?.merge_commit_sha !== candidateSha
    ),
  };
}

function validateQualityIdentity({ event, candidateSha, mainSha, identity, repository, repositoryId }) {
  if (identity?.candidateSha !== candidateSha) {
    refuse("candidate_identity_drift", "checked-out candidate identity differs from CI");
  }

  const exactSuccessfulMainPush = event?.event === "push"
    && event?.conclusion === "success"
    && event?.head_branch === "main"
    && event?.head_sha === candidateSha
    && Number.isInteger(repositoryId)
    && event?.head_repository?.id === repositoryId
    && event?.head_repository?.full_name === repository;

  if (!exactSuccessfulMainPush) {
    refuse("quality_identity_invalid", "publisher requires the exact successful same-repository main CI candidate");
  }
  if (mainSha !== candidateSha) {
    refuse("main_moved", "main no longer points at the successful candidate");
  }
}

function resolveReleasePull(pulls, candidateSha, identity, commit) {
  const { exact, stale } = exactPulls(pulls, candidateSha);
  if (!exact.length && !stale.length) {
    if (commit?.parentVersion !== identity.version) {
      refuse("unrecognized_release_commit", "manifest changed without an exact Release Please merge");
    }
    return null;
  }
  if (exact.length !== 1 || stale.length) {
    refuse("release_pr_ambiguous", "candidate has no single exact Release Please merge");
  }
  return exact[0];
}

function validateReleasePullIdentity(pull, identity, candidateSha, repository, repositoryId) {
  const valid = pull?.state === "closed"
    && typeof pull?.merged_at === "string"
    && pull?.draft === false
    && pull?.head?.ref === RELEASE_BRANCH
    && pull?.head?.repo?.id === repositoryId
    && pull?.head?.repo?.full_name === repository
    && pull?.base?.ref === "main"
    && FULL_SHA.test(pull?.base?.sha ?? "")
    && pull?.base?.repo?.id === repositoryId
    && pull?.base?.repo?.full_name === repository
    && pull?.user?.login === BOT_LOGIN
    && pull?.title === `chore(main): release ${identity.version}`
    && Number.isInteger(pull?.number)
    && pull.number > 0
    && FULL_SHA.test(pull?.head?.sha ?? "")
    && pull.head.sha !== candidateSha;

  if (!valid) {
    refuse("release_pr_invalid", "release PR identity is invalid");
  }
}

function validateReleaseMerge(commit, pull, candidateSha) {
  const normalMerge = commit?.sha === candidateSha
    && commit.parents?.length === 2
    && commit.parents[0]?.sha === pull.base.sha
    && commit.parents[1]?.sha === pull.head.sha
    && commit.tree?.sha === pull.head_tree_sha;

  if (!normalMerge) {
    refuse("release_pr_not_normal_merge", "candidate must be the normal two-parent merge of the exact Release Please head");
  }
}

function validateReleaseVersion(commit, identity) {
  if (typeof commit.parentVersion !== "string" || (commit.parentVersion !== "0.0.0" && !BETA.test(commit.parentVersion))) {
    refuse("release_parent_version_invalid", "release parent version is invalid");
  }
  if (!Array.isArray(commit.changedPaths) || JSON.stringify(commit.changedPaths) !== JSON.stringify(RELEASE_PATHS)) {
    refuse("release_paths_invalid", "release changed paths are not exact");
  }
  if (!BETA.test(identity.version) || (commit.parentVersion === "0.0.0" && identity.version !== "0.1.0-beta.1")) {
    refuse("release_version_invalid", "release must use a canonical beta version; the initial release must be 0.1.0-beta.1");
  }
  if (commit.parentVersion === identity.version) {
    refuse("release_version_unchanged", "release did not advance the manifest");
  }
}

function decideReleaseState(input, pull) {
  const pending = labels(pull).includes(PENDING_LABEL);
  const tagged = labels(pull).includes(TAGGED_LABEL);

  if (input.release !== null && input.tagRef === null) {
    refuse("release_without_tag", "release exists without its tag");
  }
  if (input.release !== null) {
    verifyPublishedState(input.tagRef, input.release, input.identity);
    if (!pending && !tagged) {
      refuse("release_pr_label_conflict", "published candidate has no lifecycle label");
    }
    return { action: tagged ? "already_published" : "reconcile_labels", pullNumber: pull.number };
  }
  if (!pending || tagged) {
    refuse("release_pr_label_conflict", "unpublished candidate must have only pending label");
  }
  if (input.tagRef !== null) {
    refuse("partial_publication_state", "tag exists without release");
  }
  if (input.immutableReleasesEnabled === false) {
    refuse("immutable_releases_disabled", "immutable-release acknowledgement is missing or mismatched");
  }
  return { action: "create_release", pullNumber: pull.number };
}

export async function hydrateExactReleasePullTree(repository, candidateSha, pulls, request) {
  const { exact, stale } = exactPulls(pulls, candidateSha);
  if (exact.length !== 1 || stale.length) {
    return pulls;
  }

  const pull = exact[0];
  if (!FULL_SHA.test(pull?.head?.sha ?? "")) {
    refuse("release_pr_invalid", "Release Please head identity is invalid");
  }

  const response = await request(`/repos/${repository}/git/commits/${pull.head.sha}`);
  const headCommit = response?.data ?? response;
  if (headCommit?.sha !== pull.head.sha || !FULL_SHA.test(headCommit?.tree?.sha ?? "")) {
    refuse("release_pr_head_tree_invalid", "Release Please head tree readback is invalid");
  }

  return pulls.map((value) => value === pull ? { ...value, head_tree_sha: headCommit.tree.sha } : value);
}

export function decidePublication(input) {
  const { event, candidateSha, mainSha, identity, pulls, commit, repository, repositoryId } = input;
  validateQualityIdentity({ event, candidateSha, mainSha, identity, repository, repositoryId });

  const pull = resolveReleasePull(pulls, candidateSha, identity, commit);
  if (pull === null) {
    return { action: "none", reason: "ordinary_main" };
  }

  validateReleasePullIdentity(pull, identity, candidateSha, repository, repositoryId);
  validateReleaseMerge(commit, pull, candidateSha);
  validateReleaseVersion(commit, identity);
  return decideReleaseState(input, pull);
}

export function verifyPublishedState(tagRef, release, identity) {
  const exactState = tagRef?.object?.type === "commit"
    && tagRef.object.sha === identity.candidateSha
    && release?.tag_name === identity.tag
    && release?.target_commitish === identity.candidateSha
    && release?.name === identity.tag
    && release?.body === identity.notes
    && release?.draft === false
    && release?.prerelease === true
    && release?.immutable === true
    && Number.isInteger(release?.id)
    && release.id > 0;

  if (!exactState) {
    refuse("release_state_conflict", "tag or immutable release readback is not exact");
  }
  if (!Array.isArray(release.assets) || release.assets.length) {
    refuse("release_asset_conflict", "release must have no assets");
  }
  return true;
}
