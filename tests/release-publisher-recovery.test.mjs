import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";

import { runRecovery } from "../scripts/release-publisher-recovery.mjs";

const REPOSITORY = "RocketsAreNostalgic/ran-wp-branch-updater";
const ID = 42;
const BEFORE = "0.1.0-beta.1";
const AFTER = "1.0.0-beta.1";
const RELEASE_BRANCH = "release-please--branches--main--components--ran/wp-branch-updater";

function git(root, args) {
  return execFileSync("git", args, { cwd: root, encoding: "utf8" }).trim();
}

function fixture() {
  const root = mkdtempSync(join(tmpdir(), "branch-updater-recovery-"));
  git(root, ["init", "--initial-branch=main"]);
  git(root, ["config", "user.email", "test@example.test"]);
  git(root, ["config", "user.name", "Test"]);

  const composer = JSON.stringify({ name: "ran/wp-branch-updater", type: "library" });
  const parentHistory = `## ${BEFORE} (2026-09-01)\n\n### Features\n\n* prior beta\n`;
  writeFileSync(join(root, "composer.json"), composer);
  writeFileSync(join(root, ".release-please-manifest.json"), JSON.stringify({ ".": BEFORE }));
  writeFileSync(join(root, "CHANGELOG.md"), `# Changelog\n\n${parentHistory}`);
  git(root, ["add", "."]);
  git(root, ["commit", "-m", "feat: prior beta"]);
  const base = git(root, ["rev-parse", "HEAD"]);

  git(root, ["checkout", "-b", RELEASE_BRANCH]);
  writeFileSync(join(root, ".release-please-manifest.json"), JSON.stringify({ ".": AFTER }));
  writeFileSync(
    join(root, "CHANGELOG.md"),
    `# Changelog\n\n## [${AFTER}](https://github.com/RocketsAreNostalgic/ran-wp-branch-updater/compare/v${BEFORE}...v${AFTER}) (2026-09-10)\n\n### ⚠ BREAKING CHANGES\n\n* normalize public architecture\n\n${parentHistory}`,
  );
  git(root, ["add", ".release-please-manifest.json", "CHANGELOG.md"]);
  git(root, ["commit", "-m", "chore(main): release 1.0.0-beta.1"]);
  const head = git(root, ["rev-parse", "HEAD"]);
  const headTree = git(root, ["show", "-s", "--format=%T", head]);

  git(root, ["checkout", "main"]);
  git(root, ["merge", "--no-ff", "--no-edit", head]);
  const candidate = git(root, ["rev-parse", "HEAD"]);

  writeFileSync(join(root, "ordinary.txt"), "later main\n");
  git(root, ["add", "ordinary.txt"]);
  git(root, ["commit", "-m", "fix: later main work"]);
  const current = git(root, ["rev-parse", "HEAD"]);

  const eventPath = join(root, "event.json");
  writeFileSync(eventPath, JSON.stringify({
    repository: { id: ID },
    workflow_run: {
      event: "push",
      conclusion: "success",
      head_branch: "main",
      head_sha: current,
      head_repository: { id: ID, full_name: REPOSITORY },
    },
  }));

  return { root, base, head, headTree, candidate, current, eventPath };
}

function releasePull(value, changes = {}) {
  return {
    state: "closed",
    merged_at: "2026-09-10T00:00:00Z",
    draft: false,
    merge_commit_sha: value.candidate,
    base: { ref: "main", sha: value.base, repo: { id: ID, full_name: REPOSITORY } },
    head: { ref: RELEASE_BRANCH, sha: value.head, repo: { id: ID, full_name: REPOSITORY } },
    user: { login: "github-actions[bot]" },
    title: `chore(main): release ${AFTER}`,
    number: 25,
    labels: [{ name: "autorelease: pending" }],
    ...changes,
  };
}

function mockedTransport(value, options = {}) {
  const calls = [];
  const state = {
    tag: options.tag ?? null,
    release: options.release ?? null,
    labels: ["autorelease: pending"],
  };
  const response = (data, status = 200) => new Response(
    data === null ? null : JSON.stringify(data),
    { status, headers: { link: "" } },
  );
  const canonicalPull = () => releasePull(value, {
    ...(options.invalidPull ? { user: { login: "someone-else" } } : {}),
    labels: state.labels.map((name) => ({ name })),
  });

  const fetch = async (url, init = {}) => {
    const parsed = new URL(url);
    const method = init.method ?? "GET";
    calls.push({ path: parsed.pathname + parsed.search, method });

    if (parsed.pathname.endsWith("/git/ref/heads/main")) {
      return response({ object: { sha: value.current } });
    }
    if (parsed.pathname.endsWith("/pulls") && parsed.searchParams.get("state") === "closed") {
      if (options.noPending) return response([]);
      if (options.ambiguous) return response([canonicalPull(), releasePull(value, { number: 26 })]);
      if (options.nonAncestor) return response([releasePull(value, { merge_commit_sha: "f".repeat(40) })]);
      return response([canonicalPull()]);
    }
    if (parsed.pathname.endsWith("/actions/workflows/ci.yml/runs")) {
      return response({
        workflow_runs: options.missingCi ? [] : [{
          event: "push",
          conclusion: "success",
          head_branch: "main",
          head_sha: value.candidate,
          head_repository: { id: ID, full_name: REPOSITORY },
        }],
      });
    }
    if (parsed.pathname.endsWith(`/commits/${value.candidate}/pulls`)) {
      return response([canonicalPull()]);
    }
    if (parsed.pathname.endsWith(`/git/commits/${value.head}`)) {
      return response({ sha: value.head, tree: { sha: value.headTree } });
    }
    if (parsed.pathname.includes("/git/ref/tags/")) {
      return state.tag ? response(state.tag) : response(null, 404);
    }
    if (parsed.pathname.includes("/releases/tags/")) {
      return state.release ? response(state.release) : response(null, 404);
    }
    if (parsed.pathname.endsWith("/releases") && method === "POST") {
      const body = JSON.parse(init.body);
      state.tag = { object: { type: "commit", sha: value.candidate } };
      state.release = { ...body, id: 99, immutable: true, assets: [] };
      return response(state.release, 201);
    }
    if (parsed.pathname.endsWith("/issues/25/labels") && method === "POST") {
      state.labels = ["autorelease: tagged"];
      return response(null, 204);
    }
    if (parsed.pathname.includes("/issues/25/labels/autorelease%3A%20pending") && method === "DELETE") {
      state.labels = ["autorelease: tagged"];
      return response(null, 204);
    }
    if (parsed.pathname.endsWith("/pulls/25")) {
      return response(canonicalPull());
    }
    throw new Error(`unexpected ${method} ${parsed.pathname}${parsed.search}`);
  };

  return { calls, fetch, state };
}

function environment(value, fetch) {
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
  process.env.GITHUB_EVENT_PATH = value.eventPath;
  process.env.GITHUB_TOKEN = "test";
  process.env.RAN_RELEASE_PUBLISHER_MUTATE = "1";
  process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID = String(ID);
  globalThis.fetch = fetch;
  return () => {
    for (const name of names) {
      if (before[name] === undefined) delete process.env[name];
      else process.env[name] = before[name];
    }
    globalThis.fetch = previousFetch;
    rmSync(value.root, { recursive: true, force: true });
  };
}

function writes(calls) {
  return calls.filter((call) => call.method !== "GET");
}

test("recovery publishes one exact historical Release Please merge and reconciles labels", async (context) => {
  const value = fixture();
  const mocked = mockedTransport(value);
  context.after(environment(value, mocked.fetch));

  const result = await runRecovery(value.root);
  assert.equal(result.action, "create_release");
  assert.equal(result.recoveredCandidateSha, value.candidate);
  assert.equal(mocked.state.tag.object.sha, value.candidate);
  assert.equal(mocked.state.release.tag_name, `v${AFTER}`);
  assert.deepEqual(mocked.state.labels, ["autorelease: tagged"]);
  assert.equal(mocked.calls.filter((call) => call.method === "POST" && call.path.endsWith("/releases")).length, 1);
});

test("recovery retries a dual lifecycle-label state without republishing", async (context) => {
  const value = fixture();
  const mocked = mockedTransport(value);
  context.after(environment(value, mocked.fetch));

  await runRecovery(value.root);
  const releasesBefore = mocked.calls.filter((call) => call.method === "POST" && call.path.endsWith("/releases")).length;
  mocked.state.labels = ["autorelease: pending", "autorelease: tagged"];

  const result = await runRecovery(value.root);
  assert.equal(result.action, "already_published");
  assert.deepEqual(mocked.state.labels, ["autorelease: tagged"]);
  assert.equal(
    mocked.calls.filter((call) => call.method === "POST" && call.path.endsWith("/releases")).length,
    releasesBefore,
  );
});

test("recovery no-ops when there is no pending merged release", async (context) => {
  const value = fixture();
  const mocked = mockedTransport(value, { noPending: true });
  context.after(environment(value, mocked.fetch));
  assert.deepEqual(await runRecovery(value.root), { action: "none", reason: "no_pending_release" });
  assert.equal(writes(mocked.calls).length, 0);
});

test("recovery fails closed without exact historical CI", async (context) => {
  const value = fixture();
  const mocked = mockedTransport(value, { missingCi: true });
  context.after(environment(value, mocked.fetch));
  await assert.rejects(runRecovery(value.root), (error) => error.code === "recovery_historical_ci_missing");
  assert.equal(writes(mocked.calls).length, 0);
});

test("recovery refuses a non-ancestor or ambiguous pending candidate", async () => {
  for (const options of [{ nonAncestor: true }, { ambiguous: true }]) {
    const value = fixture();
    const mocked = mockedTransport(value, options);
    const restore = environment(value, mocked.fetch);
    try {
      await assert.rejects(
        runRecovery(value.root),
        (error) => options.nonAncestor
          ? error.code === "recovery_release_not_ancestor"
          : error.code === "recovery_release_ambiguous",
      );
      assert.equal(writes(mocked.calls).length, 0);
    } finally {
      restore();
    }
  }
});

test("recovery reuses normal PR geometry validation", async (context) => {
  const value = fixture();
  const mocked = mockedTransport(value, { invalidPull: true });
  context.after(environment(value, mocked.fetch));
  await assert.rejects(runRecovery(value.root), (error) => error.code === "release_pr_invalid");
  assert.equal(writes(mocked.calls).length, 0);
});

test("recovery refuses partial remote publication state before writes", async (context) => {
  const value = fixture();
  const mocked = mockedTransport(value, {
    tag: { object: { type: "commit", sha: value.candidate } },
    release: null,
  });
  context.after(environment(value, mocked.fetch));
  await assert.rejects(runRecovery(value.root), (error) => error.code === "partial_publication_state");
  assert.equal(writes(mocked.calls).length, 0);
});
