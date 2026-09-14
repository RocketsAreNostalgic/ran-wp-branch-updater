import assert from "node:assert/strict";
import test from "node:test";

import { decidePublication } from "../scripts/release-publisher-decision.mjs";
import { normalizeRecoveryDecisionInput } from "../scripts/release-publisher-recovery.mjs";

const REPOSITORY = "RocketsAreNostalgic/ran-wp-branch-updater";
const REPOSITORY_ID = 1360288890;
const CANDIDATE = "e325811348cc5e24ec2364483698533ca061cb59";
const BASE = "e4e9af33b449740d5f513aaf5760e7f04dc2cf7f";
const HEAD = "5e719f5695543bf31dfbf41efcfce8b1838dd703";
const TREE = "961d8ac6b81d4641c424990b24719e1c46ecc750";
const VERSION = "1.0.0-beta.4";
const RELEASE_BRANCH = "release-please--branches--main--components--ran/wp-branch-updater";

function pull(changes = {}) {
  return {
    state: "closed",
    merged_at: "2026-09-14T18:12:40Z",
    draft: false,
    merge_commit_sha: CANDIDATE,
    base: { ref: "main", sha: BASE, repo: { id: REPOSITORY_ID, full_name: REPOSITORY } },
    head: { ref: RELEASE_BRANCH, sha: HEAD, repo: { id: REPOSITORY_ID, full_name: REPOSITORY } },
    head_tree_sha: TREE,
    user: { login: "github-actions[bot]" },
    title: `chore(main): release ${VERSION}`,
    number: 44,
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
      parentVersion: "1.0.0-beta.3",
      changedPaths: [".release-please-manifest.json", "CHANGELOG.md"],
    },
    ...changes,
  };
}

test("beta.4 recovery admits only the exact historical squash candidate", () => {
  const normalized = normalizeRecoveryDecisionInput(input(), pull());
  assert.deepEqual(normalized.commit.parents, [{ sha: BASE }, { sha: HEAD }]);
  assert.deepEqual(decidePublication(normalized), { action: "create_release", pullNumber: 44 });
});

test("beta.4 recovery refuses drift in its exact historical proof", () => {
  const cases = [
    [input({ commit: { ...input().commit, tree: { sha: "f".repeat(40) } } }), pull()],
    [input(), pull({ head_tree_sha: "f".repeat(40) })],
    [input(), pull({ number: 45 })],
    [input({ identity: { candidateSha: CANDIDATE, version: "1.0.0-beta.5" } }), pull()],
  ];

  for (const [candidate, releasePull] of cases) {
    assert.throws(
      () => normalizeRecoveryDecisionInput(candidate, releasePull),
      (error) => error.code === "recovery_beta4_squash_invalid",
    );
  }
});

test("unrelated squash candidates remain rejected by normal publication rules", () => {
  const other = "a".repeat(40);
  const candidate = input({
    candidateSha: other,
    mainSha: other,
    event: { ...input().event, head_sha: other },
    identity: { candidateSha: other, version: VERSION },
    commit: { ...input().commit, sha: other },
  });
  const releasePull = pull({ merge_commit_sha: other });
  const unchanged = normalizeRecoveryDecisionInput(candidate, releasePull);

  assert.equal(unchanged, candidate);
  assert.throws(
    () => decidePublication({ ...unchanged, pulls: [releasePull] }),
    (error) => error.code === "release_pr_not_normal_merge",
  );
});
