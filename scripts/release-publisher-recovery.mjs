#!/usr/bin/env node
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { refuse, verifyReleaseDelta } from "./release-publisher-content.mjs";
import {
  decidePublication,
  hydrateExactReleasePullTree,
  labels,
  PENDING_LABEL,
  TAGGED_LABEL,
  verifyPublishedState,
} from "./release-publisher-decision.mjs";
import {
  changedPaths,
  commitParents,
  currentSha,
  parentReleaseContents,
  releaseContents,
  treeSha,
  validateCandidate,
} from "./release-publisher-git.mjs";
import {
  api,
  associatedPulls,
  createImmutableRelease,
  remoteState,
} from "./release-publisher-github.mjs";

const FULL_SHA = /^[a-f0-9]{40}$/;
const REPOSITORY = "RocketsAreNostalgic/ran-wp-branch-updater";
const RELEASE_BRANCH = "release-please--branches--main--components--ran/wp-branch-updater";

function isAncestor(root, ancestor, descendant) {
  try {
    execFileSync("git", ["merge-base", "--is-ancestor", ancestor, descendant], {
      cwd: root,
      stdio: "ignore",
    });
    return true;
  } catch {
    return false;
  }
}

function currentQualityEvent(payload, checkoutSha, mainSha) {
  const event = payload.workflow_run;
  const repositoryId = payload.repository?.id;
  const valid = event?.event === "push"
    && event?.conclusion === "success"
    && event?.head_branch === "main"
    && event?.head_sha === checkoutSha
    && mainSha === checkoutSha
    && Number.isInteger(repositoryId)
    && event?.head_repository?.id === repositoryId
    && event?.head_repository?.full_name === REPOSITORY;
  if (!valid) {
    refuse("recovery_quality_identity_invalid", "recovery requires the exact successful current main CI candidate");
  }
  return { event, repositoryId };
}

async function mergedReleasePulls(repository) {
  const pulls = [];
  const head = encodeURIComponent(`RocketsAreNostalgic:${RELEASE_BRANCH}`);
  for (let page = 1; page <= 10; page += 1) {
    const response = await api(`/repos/${repository}/pulls?state=closed&head=${head}&per_page=100&page=${page}`);
    if (!Array.isArray(response.data)) {
      refuse("recovery_pull_readback_invalid", "release pull request response is not a list");
    }
    pulls.push(...response.data);
    if (!/<[^>]+>;\s*rel="next"/.test(response.headers.get("link") ?? "")) {
      return pulls.filter((pull) =>
        pull?.state === "closed"
        && typeof pull?.merged_at === "string"
      );
    }
  }
  refuse("recovery_pull_readback_unbounded", "release pull request response exceeded ten pages");
}

function refuseConflictingSuccessor(root, releasePulls, candidatePull, candidateSha) {
  for (const pull of releasePulls) {
    if (pull?.number === candidatePull.number) {
      continue;
    }
    const otherSha = pull?.merge_commit_sha;
    if (!FULL_SHA.test(otherSha ?? "")) {
      refuse("recovery_release_history_invalid", "another merged Release Please candidate has an invalid merge SHA");
    }
    if (isAncestor(root, candidateSha, otherSha)) {
      refuse("recovery_release_successor_conflict", "a later merged Release Please candidate already succeeds the pending release");
    }
    if (!isAncestor(root, otherSha, candidateSha)) {
      refuse("recovery_release_history_conflict", "merged Release Please history is not linearly ordered with the pending release");
    }
  }
}

async function historicalMainCiSucceeded(repository, repositoryId, candidateSha) {
  const response = await api(
    `/repos/${repository}/actions/workflows/ci.yml/runs?event=push&status=completed&head_sha=${candidateSha}&per_page=100`,
  );
  const runs = response.data?.workflow_runs;
  if (!Array.isArray(runs)) {
    refuse("recovery_ci_readback_invalid", "historical CI response is not a workflow-run list");
  }
  return runs.some((run) =>
    run?.event === "push"
    && run?.conclusion === "success"
    && run?.head_branch === "main"
    && run?.head_sha === candidateSha
    && run?.head_repository?.id === repositoryId
    && run?.head_repository?.full_name === repository
  );
}

async function reconcileLabels(repository, number, value) {
  if (!value.includes(TAGGED_LABEL)) {
    await api(`/repos/${repository}/issues/${number}/labels`, {
      method: "POST",
      body: { labels: [TAGGED_LABEL] },
    });
  }
  if (value.includes(PENDING_LABEL)) {
    await api(`/repos/${repository}/issues/${number}/labels/${encodeURIComponent(PENDING_LABEL)}`, {
      method: "DELETE",
      allow404: true,
    });
  }
}

function historicalInput(root, payload, repositoryId, candidateSha, pulls, state) {
  const candidateContents = releaseContents(root, candidateSha);
  const identity = validateCandidate(root, candidateSha);
  const parents = commitParents(root, candidateSha);
  const parent = parents[0]?.sha ?? null;
  const parentState = parentReleaseContents(root, parent);
  if (!parentState) {
    refuse("recovery_release_content_drift", "historical release candidate has no complete release parent");
  }
  const delta = verifyReleaseDelta(parentState, candidateContents);
  const event = {
    event: "push",
    conclusion: "success",
    head_branch: "main",
    head_sha: candidateSha,
    head_repository: { id: repositoryId, full_name: REPOSITORY },
  };
  return {
    event,
    candidateSha,
    mainSha: candidateSha,
    identity,
    pulls,
    commit: {
      sha: candidateSha,
      parents,
      changedPaths: changedPaths(root, parent, candidateSha),
      parentVersion: delta.parentVersion,
      tree: { sha: treeSha(root, candidateSha) },
    },
    repository: REPOSITORY,
    repositoryId,
    tagRef: state.tagRef,
    release: state.release,
    immutableReleasesEnabled: state.release === null
      ? process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID === String(payload.repository?.id)
      : undefined,
  };
}

export async function runRecovery(root = process.cwd()) {
  const eventPath = process.env.GITHUB_EVENT_PATH;
  const repository = process.env.GITHUB_REPOSITORY;
  if (repository !== REPOSITORY || !eventPath || !process.env.GITHUB_TOKEN) {
    refuse("recovery_environment_invalid", "recovery environment is incomplete");
  }

  const payload = JSON.parse(readFileSync(eventPath, "utf8"));
  const checkoutSha = currentSha(root);
  const currentMain = await api(`/repos/${repository}/git/ref/heads/main`);
  const mainSha = currentMain.data?.object?.sha;
  const { repositoryId } = currentQualityEvent(payload, checkoutSha, mainSha);

  const releasePulls = await mergedReleasePulls(repository);
  const pending = releasePulls.filter((pull) => labels(pull).includes(PENDING_LABEL));
  if (pending.length === 0) {
    return { action: "none", reason: "no_pending_release" };
  }
  if (pending.length !== 1) {
    refuse("recovery_release_ambiguous", "recovery requires exactly one merged pending Release Please candidate");
  }

  const listedPull = pending[0];
  const candidateSha = listedPull?.merge_commit_sha;
  if (!FULL_SHA.test(candidateSha ?? "")) {
    refuse("recovery_release_invalid", "pending release merge SHA is invalid");
  }
  if (candidateSha === checkoutSha) {
    return { action: "none", reason: "current_release_candidate" };
  }
  if (!isAncestor(root, candidateSha, checkoutSha)) {
    refuse("recovery_release_not_ancestor", "pending release merge is not an ancestor of current main");
  }
  refuseConflictingSuccessor(root, releasePulls, listedPull, candidateSha);
  if (!await historicalMainCiSucceeded(repository, repositoryId, candidateSha)) {
    refuse("recovery_historical_ci_missing", "pending release merge has no exact successful same-repository main CI run");
  }

  const associated = await associatedPulls(repository, candidateSha);
  if (!associated.some((pull) => pull?.number === listedPull.number)) {
    refuse("recovery_release_association_invalid", "pending release PR is not associated with its merge commit");
  }
  const hydrated = await hydrateExactReleasePullTree(repository, candidateSha, associated, api);
  const identity = validateCandidate(root, candidateSha);
  let state = await remoteState(repository, identity.tag);
  let input = historicalInput(root, payload, repositoryId, candidateSha, hydrated, state);
  let result = decidePublication(input);
  if (process.env.RAN_RELEASE_PUBLISHER_MUTATE !== "1") {
    refuse("mutation_disabled", "publisher mutation requires RAN_RELEASE_PUBLISHER_MUTATE=1");
  }

  const [freshMain, freshAssociated, freshState] = await Promise.all([
    api(`/repos/${repository}/git/ref/heads/main`),
    associatedPulls(repository, candidateSha),
    remoteState(repository, identity.tag),
  ]);
  const freshMainSha = freshMain.data?.object?.sha;
  if (freshMainSha !== checkoutSha || !isAncestor(root, candidateSha, freshMainSha)) {
    refuse("main_moved", "main moved or no longer contains the historical release candidate");
  }
  if (!await historicalMainCiSucceeded(repository, repositoryId, candidateSha)) {
    refuse("recovery_historical_ci_missing", "historical CI proof disappeared before mutation");
  }
  const freshHydrated = await hydrateExactReleasePullTree(repository, candidateSha, freshAssociated, api);
  input = historicalInput(root, payload, repositoryId, candidateSha, freshHydrated, freshState);
  result = decidePublication(input);

  if (result.action === "create_release") {
    await createImmutableRelease(repository, identity);
  }

  state = await remoteState(repository, identity.tag);
  verifyPublishedState(state.tagRef, state.release, identity);

  const original = freshHydrated.find((pull) => pull.number === result.pullNumber);
  await reconcileLabels(repository, result.pullNumber, labels(original));

  const finalPull = (await api(`/repos/${repository}/pulls/${result.pullNumber}`)).data;
  const finalWithTree = (await hydrateExactReleasePullTree(repository, candidateSha, [finalPull], api))[0];
  decidePublication({
    ...input,
    pulls: [finalWithTree],
    tagRef: state.tagRef,
    release: state.release,
  });
  if (!labels(finalPull).includes(TAGGED_LABEL) || labels(finalPull).includes(PENDING_LABEL)) {
    refuse("release_label_readback_failed", "release PR labels did not reconcile to tagged");
  }

  return { ...result, releaseId: state.release.id, recoveredCandidateSha: candidateSha };
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  runRecovery().catch((error) => {
    console.error(error);
    process.exitCode = 1;
  });
}
