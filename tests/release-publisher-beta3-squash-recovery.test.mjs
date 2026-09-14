import assert from "node:assert/strict";
import test from "node:test";

import { decidePublication } from "../scripts/release-publisher-decision.mjs";
import { normalizeRecoveryDecisionInput } from "../scripts/release-publisher-recovery.mjs";

const REPOSITORY = "RocketsAreNostalgic/ran-wp-branch-updater";
const REPOSITORY_ID = 1360288890;
const CANDIDATE = "d07237618f4ae836920098e00061f728c2af8879";
const BASE = "89da8d311df577b80686d618c5b9aaaa99ef5470";
const HEAD = "0e17152b94c0fa846d2ca05e4da27794619542a5";
const TREE = "200bd265af8afb10279399161be1eadef8a3fc5b";
const VERSION = "1.0.0-beta.3";
const RELEASE_BRANCH = "release-please--branches--main--components--ran/wp-branch-updater";

function pull(changes = {}) {
  return {
    state: "closed",
    merged_at: "2026-09-14T11:33:58Z",
    draft: false,
    merge_commit_sha: CANDIDATE,
    base: { ref: "main", sha: BASE, repo: { id: REPOSITORY_ID, full_name: REPOSITORY } },
    head: { ref: RELEASE_BRANCH, sha: HEAD, repo: { id: REPOSITORY_ID, full_name: REPOSITORY } },
    head_tree_sha: TREE,
    user: { login: "github-actions[bot]" },
    title: `chore(main): release ${VERSION}`,
    number: 38,
    labels: [{ name: "autorelease: pending" }],
    ...changes,
  };
}

function input(changes = {}) {
  return {
    event: {
      event: "push",
      conclusion: "success",
      head_branch: "main",
      head_sha: CANDIDATE,
      head_repository: { id: REPOSITORY_ID, full_name: REPOSITORY },
    },
    candidateSha: CANDIDATE,
    mainSha: CANDIDATE,
    identity: { candidateSha: CANDIDATE, version: VERSION },
    pulls: [pull()],
    repository: REPOSITORY,
    repositoryId: REPOSITORY_ID,
    tagRef: null,
    release: null,
    immutableReleasesEnabled: true,
    commit: {
      sha: CANDIDATE,
      parents: [{ sha: BASE }],
      tree: { sha: TREE },
      parentVersion: "1.0.0-beta.2",
      changedPaths: [".release-please-manifest.json", "CHANGELOG.md"],
    },
    ...changes,
  };
}

test("beta.3 recovery admits only the exact historical squash candidate", () => {
  const normalized = normalizeRecoveryDecisionInput(input(), pull());
  assert.deepEqual(normalized.commit.parents, [{ sha: BASE }, { sha: HEAD }]);
  assert.deepEqual(decidePublication(normalized), { action: "create_release", pullNumber: 38 });
});

test("beta.3 recovery refuses drift in its exact historical proof", () => {
  const cases = [
    [input({ commit: { ...input().commit, tree: { sha: "f".repeat(40) } } }), pull()],
    [input(), pull({ head_tree_sha: "f".repeat(40) })],
    [input(), pull({ number: 39 })],
    [input({ identity: { candidateSha: CANDIDATE, version: "1.0.0-beta.4" } }), pull()],
  ];

  for (const [candidate, releasePull] of cases) {
    assert.throws(
      () => normalizeRecoveryDecisionInput(candidate, releasePull),
      (error) => error.code === "recovery_beta3_squash_invalid",
    );
  }
});

test("unrelated squash candidates remain rejected by normal publication rules", () => {
  const other = "a".repeat(40);
  const candidate = input({
    candidateSha: other,
    mainSha: other,
    event: {
      ...input().event,
      head_sha: other,
    },
    identity: { candidateSha: other, version: VERSION },
    commit: {
      ...input().commit,
      sha: other,
    },
  });
  const releasePull = pull({ merge_commit_sha: other });
  const unchanged = normalizeRecoveryDecisionInput(candidate, releasePull);

  assert.equal(unchanged, candidate);
  assert.throws(
    () => decidePublication({ ...unchanged, pulls: [releasePull] }),
    (error) => error.code === "release_pr_not_normal_merge",
  );
});
