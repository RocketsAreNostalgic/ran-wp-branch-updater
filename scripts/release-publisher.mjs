#!/usr/bin/env node
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { PublisherRefusal, candidateIdentity, manifestVersion, refuse, verifyReleaseDelta } from "./release-publisher-content.mjs";
import {
  decidePublication,
  hydrateExactReleasePullTree as hydrateReleasePullTree,
  labels,
  PENDING_LABEL,
  TAGGED_LABEL,
  verifyPublishedState,
} from "./release-publisher-decision.mjs";
import {
  changedPaths,
  classifyParentReleaseMetadata,
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

export {
  PublisherRefusal,
  candidateIdentity,
  classifyParentReleaseMetadata,
  decidePublication,
  validateCandidate,
  verifyPublishedState,
  verifyReleaseDelta,
};

const FULL_SHA = /^[a-f0-9]{40}$/;
const REPOSITORY = "RocketsAreNostalgic/ran-wp-branch-updater";

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

export async function hydrateExactReleasePullTree(repository, candidateSha, pulls, request = api) {
  return hydrateReleasePullTree(repository, candidateSha, pulls, request);
}

export async function runPublisher(root = process.cwd()) {
  const eventPath = process.env.GITHUB_EVENT_PATH;
  const repository = process.env.GITHUB_REPOSITORY;
  if (repository !== REPOSITORY || !eventPath || !process.env.GITHUB_TOKEN) {
    refuse("environment_invalid", "publisher environment is incomplete");
  }

  const payload = JSON.parse(readFileSync(eventPath, "utf8"));
  const event = payload.workflow_run;
  const sha = event?.head_sha;
  if (!FULL_SHA.test(sha ?? "") || currentSha(root) !== sha) {
    refuse("checkout_drift", "checkout is not the CI candidate");
  }

  const candidateContents = releaseContents(root, sha);
  const parents = commitParents(root, sha);
  const parent = parents[0]?.sha ?? null;
  const parentState = parentReleaseContents(root, parent);
  const unreleased = manifestVersion(candidateContents.manifest, "candidate", true) === "0.0.0";
  const identity = unreleased
    ? { candidateSha: sha, version: "0.0.0", tag: "v0.0.0", notes: "unreleased bootstrap" }
    : validateCandidate(root, sha);

  if (!parentState && !unreleased) {
    refuse("release_content_drift", "released metadata requires a complete unreleased or released parent");
  }
  const parentVersion = parentState ? manifestVersion(parentState.manifest, "parent", true) : "0.0.0";
  if (unreleased && parentState && parentVersion !== "0.0.0") {
    refuse("release_version_regression", "released beta metadata may not return to 0.0.0");
  }

  const unchangedVersion = parentState?.manifest === candidateContents.manifest;
  const delta = !parentState || unreleased
    ? { parentVersion }
    : unchangedVersion
      ? { parentVersion: identity.version }
      : verifyReleaseDelta(parentState, candidateContents);

  const [main, pulls, state] = await Promise.all([
    api(`/repos/${repository}/git/ref/heads/main`),
    associatedPulls(repository, sha),
    remoteState(repository, identity.tag),
  ]);
  const hydratedPulls = await hydrateExactReleasePullTree(repository, sha, pulls);
  let input = {
    event,
    candidateSha: sha,
    mainSha: main.data?.object?.sha,
    identity,
    pulls: hydratedPulls,
    commit: {
      sha,
      parents,
      changedPaths: changedPaths(root, parent, sha),
      parentVersion: delta.parentVersion,
      tree: { sha: treeSha(root, sha) },
    },
    repository,
    repositoryId: payload.repository?.id,
    tagRef: state.tagRef,
    release: state.release,
  };

  let result = decidePublication(input);
  if (result.action === "none") {
    return result;
  }
  if (process.env.RAN_RELEASE_PUBLISHER_MUTATE !== "1") {
    refuse("mutation_disabled", "publisher mutation requires RAN_RELEASE_PUBLISHER_MUTATE=1");
  }

  const [freshMain, freshPulls, freshState] = await Promise.all([
    api(`/repos/${repository}/git/ref/heads/main`),
    associatedPulls(repository, sha),
    remoteState(repository, identity.tag),
  ]);
  input = {
    ...input,
    mainSha: freshMain.data?.object?.sha,
    pulls: await hydrateExactReleasePullTree(repository, sha, freshPulls),
    tagRef: freshState.tagRef,
    release: freshState.release,
    immutableReleasesEnabled: freshState.release === null
      ? process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID === String(payload.repository?.id)
      : undefined,
  };
  result = decidePublication(input);

  if (result.action === "create_release") {
    await createImmutableRelease(repository, identity);
  }

  const checked = await remoteState(repository, identity.tag);
  verifyPublishedState(checked.tagRef, checked.release, identity);

  const original = input.pulls.find((pull) => pull.number === result.pullNumber);
  await reconcileLabels(repository, result.pullNumber, labels(original));

  const finalPull = (await api(`/repos/${repository}/pulls/${result.pullNumber}`)).data;
  const finalWithTree = (await hydrateExactReleasePullTree(repository, sha, [finalPull]))[0];
  decidePublication({ ...input, pulls: [finalWithTree], tagRef: checked.tagRef, release: checked.release });
  if (!labels(finalPull).includes(TAGGED_LABEL) || labels(finalPull).includes(PENDING_LABEL)) {
    refuse("release_label_readback_failed", "release PR labels did not reconcile to tagged");
  }

  return { ...result, releaseId: checked.release.id };
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  runPublisher().catch((error) => {
    console.error(error);
    process.exitCode = 1;
  });
}
