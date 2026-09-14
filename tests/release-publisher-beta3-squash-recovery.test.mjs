import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";

import { decidePublication } from "../scripts/release-publisher-decision.mjs";
import { normalizeRecoveryDecisionInput, runRecovery } from "../scripts/release-publisher-recovery.mjs";

const REPOSITORY = "RocketsAreNostalgic/ran-wp-branch-updater";
const REPOSITORY_ID = 1360288890;
const CANDIDATE = "d07237618f4ae836920098e00061f728c2af8879";
const BASE = "89da8d311df577b80686d618c5b9aaaa99ef5470";
const HEAD = "0e17152b94c0fa846d2ca05e4da27794619542a5";
const TREE = "200bd265af8afb10279399161be1eadef8a3fc5b";
const VERSION = "1.0.0-beta.3";
const RELEASE_BRANCH = "release-please--branches--main--components--ran/wp-branch-updater";

function git(root, args) {
  return execFileSync("git", args, { cwd: root, encoding: "utf8" }).trim();
}

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

function runRecoveryTransport(current) {
  const calls = [];
  const state = {
    tag: null,
    release: null,
    labels: ["autorelease: pending"],
  };
  const response = (data, status = 200) => new Response(
    data === null ? null : JSON.stringify(data),
    { status, headers: { link: "" } },
  );
  const canonicalPull = () => pull({
    labels: state.labels.map((name) => ({ name })),
  });

  const fetch = async (url, init = {}) => {
    const parsed = new URL(url);
    const method = init.method ?? "GET";
    calls.push({ path: parsed.pathname + parsed.search, method });

    if (parsed.pathname.endsWith("/git/ref/heads/main")) {
      return response({ object: { sha: current } });
    }
    if (parsed.pathname.endsWith("/pulls") && parsed.searchParams.get("state") === "closed") {
      return response([canonicalPull()]);
    }
    if (parsed.pathname.endsWith("/actions/workflows/ci.yml/runs")) {
      return response({
        workflow_runs: [{
          event: "push",
          conclusion: "success",
          head_branch: "main",
          head_sha: CANDIDATE,
          head_repository: { id: REPOSITORY_ID, full_name: REPOSITORY },
        }],
      });
    }
    if (parsed.pathname.endsWith(`/commits/${CANDIDATE}/pulls`)) {
      return response([canonicalPull()]);
    }
    if (parsed.pathname.endsWith(`/git/commits/${HEAD}`)) {
      return response({ sha: HEAD, tree: { sha: TREE } });
    }
    if (parsed.pathname.includes("/git/ref/tags/")) {
      return state.tag ? response(state.tag) : response(null, 404);
    }
    if (parsed.pathname.includes("/releases/tags/")) {
      return state.release ? response(state.release) : response(null, 404);
    }
    if (parsed.pathname.endsWith("/releases") && method === "POST") {
      const body = JSON.parse(init.body);
      state.tag = { object: { type: "commit", sha: CANDIDATE } };
      state.release = { ...body, id: 99, immutable: true, assets: [] };
      return response(state.release, 201);
    }
    if (parsed.pathname.endsWith("/issues/38/labels") && method === "POST") {
      state.labels = ["autorelease: tagged"];
      return response(null, 204);
    }
    if (parsed.pathname.includes("/issues/38/labels/autorelease%3A%20pending") && method === "DELETE") {
      state.labels = ["autorelease: tagged"];
      return response(null, 204);
    }
    if (parsed.pathname.endsWith("/pulls/38")) {
      return response(canonicalPull());
    }
    throw new Error(`unexpected ${method} ${parsed.pathname}${parsed.search}`);
  };

  return { calls, fetch, state };
}

function recoveryEnvironment(eventPath, fetch) {
  const names = [
    "GITHUB_REPOSITORY",
    "GITHUB_EVENT_PATH",
    "GITHUB_TOKEN",
    "RAN_RELEASE_PUBLISHER_MUTATE",
    "RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID",
  ];
  const before = Object.fromEntries(names.map((name) => [name, process.env[name]]));
  const previousFetch = globalThis.fetch;
  process.env.GITHUB_REPOSITORY = REPOSITORY;
  process.env.GITHUB_EVENT_PATH = eventPath;
  process.env.GITHUB_TOKEN = "test";
  process.env.RAN_RELEASE_PUBLISHER_MUTATE = "1";
  process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID = String(REPOSITORY_ID);
  globalThis.fetch = fetch;

  return () => {
    for (const name of names) {
      if (before[name] === undefined) delete process.env[name];
      else process.env[name] = before[name];
    }
    globalThis.fetch = previousFetch;
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

test("runRecovery publishes the exact historical beta.3 candidate and reconciles its lifecycle", async (context) => {
  const root = process.cwd();
  const current = git(root, ["rev-parse", "HEAD"]);
  assert.equal(git(root, ["show", "-s", "--format=%P", CANDIDATE]), BASE);
  assert.equal(git(root, ["show", "-s", "--format=%T", CANDIDATE]), TREE);
  execFileSync("git", ["merge-base", "--is-ancestor", CANDIDATE, current], { cwd: root, stdio: "ignore" });

  const temp = mkdtempSync(join(tmpdir(), "branch-updater-beta3-recovery-"));
  const eventPath = join(temp, "event.json");
  writeFileSync(eventPath, JSON.stringify({
    repository: { id: REPOSITORY_ID },
    workflow_run: {
      event: "push",
      conclusion: "success",
      head_branch: "main",
      head_sha: current,
      head_repository: { id: REPOSITORY_ID, full_name: REPOSITORY },
    },
  }));

  const mocked = runRecoveryTransport(current);
  const restore = recoveryEnvironment(eventPath, mocked.fetch);
  context.after(() => {
    restore();
    rmSync(temp, { recursive: true, force: true });
  });

  const result = await runRecovery(root);
  assert.equal(result.action, "create_release");
  assert.equal(result.recoveredCandidateSha, CANDIDATE);
  assert.equal(mocked.state.tag.object.sha, CANDIDATE);
  assert.equal(mocked.state.release.tag_name, `v${VERSION}`);
  assert.equal(mocked.state.release.target_commitish, CANDIDATE);
  assert.equal(mocked.state.release.immutable, true);
  assert.deepEqual(mocked.state.release.assets, []);
  assert.deepEqual(mocked.state.labels, ["autorelease: tagged"]);

  const publicationIndex = mocked.calls.findIndex((call) => call.method === "POST" && call.path.endsWith("/releases"));
  assert.notEqual(publicationIndex, -1);
  assert.equal(mocked.calls.filter((call) => call.method === "POST" && call.path.endsWith("/releases")).length, 1);
  assert.equal(
    mocked.calls.slice(publicationIndex + 1).some((call) => call.method === "GET" && call.path.includes("/git/ref/tags/")),
    true,
  );
  assert.equal(
    mocked.calls.slice(publicationIndex + 1).some((call) => call.method === "GET" && call.path.includes("/releases/tags/")),
    true,
  );
  assert.equal(mocked.calls.some((call) => call.method === "POST" && call.path.endsWith("/issues/38/labels")), true);
  assert.equal(
    mocked.calls.some((call) => call.method === "DELETE" && call.path.includes("/issues/38/labels/autorelease%3A%20pending")),
    true,
  );
});
