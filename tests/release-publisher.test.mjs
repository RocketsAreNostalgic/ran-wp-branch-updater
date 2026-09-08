import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";

import { runPublisher } from "../scripts/release-publisher.mjs";

const SHA = "a".repeat(40);
const REPOSITORY = "RocketsAreNostalgic/ran-wp-branch-updater";
const ID = 42;
const VERSION = "0.1.0-beta.1";

function contents(version = VERSION) {
  return {
    manifest: JSON.stringify({ ".": version }),
    composer: JSON.stringify({ name: "ran/wp-branch-updater", type: "library" }),
    changelog: `# Changelog\n\n## ${version} (2026-09-01)\n\n### Features\n\n* first release\n\n# prior\n`,
  };
}

function pull() {
  return {
    state: "closed",
    merged_at: "2026-09-01T00:00:00Z",
    draft: false,
    merge_commit_sha: SHA,
    base: {
      ref: "main",
      sha: "c".repeat(40),
      repo: { id: ID, full_name: REPOSITORY },
    },
    head: {
      ref: "release-please--branches--main--components--ran/wp-branch-updater",
      sha: "d".repeat(40),
      repo: { id: ID, full_name: REPOSITORY },
    },
    head_tree_sha: "e".repeat(40),
    user: { login: "github-actions[bot]" },
    title: `chore(main): release ${VERSION}`,
    number: 7,
    labels: [{ name: "autorelease: pending" }],
  };
}

function git(root, args) {
  return execFileSync("git", args, { cwd: root, encoding: "utf8" }).trim();
}

function publisherFixture(rootOnly = false) {
  const root = mkdtempSync(join(tmpdir(), "branch-updater-publisher-"));
  git(root, ["init", "--initial-branch=main"]);
  git(root, ["config", "user.email", "test@example.test"]);
  git(root, ["config", "user.name", "Test"]);

  const write = (version, changelog) => {
    writeFileSync(join(root, ".release-please-manifest.json"), JSON.stringify({ ".": version }));
    writeFileSync(join(root, "composer.json"), JSON.stringify({ name: "ran/wp-branch-updater", type: "library" }));
    writeFileSync(join(root, "CHANGELOG.md"), changelog);
  };

  write("0.0.0", "# Changelog\n\n## [Unreleased]\n\nBootstrap\n");
  git(root, ["add", "."]);
  git(root, ["commit", "-m", "chore: bootstrap"]);
  const base = git(root, ["rev-parse", "HEAD"]);
  let head = base;

  if (!rootOnly) {
    git(root, ["checkout", "-b", "release-please--branches--main--components--ran/wp-branch-updater"]);
    write(
      VERSION,
      `# Changelog\n\n## ${VERSION} (2026-09-01)\n\n### Features\n\n* release\n\n## [Unreleased]\n\nBootstrap\n`,
    );
    git(root, ["add", "."]);
    git(root, ["commit", "-m", "chore(main): release"]);
    head = git(root, ["rev-parse", "HEAD"]);
    git(root, ["checkout", "main"]);
    git(root, ["merge", "--no-ff", "--no-edit", head]);
  }

  const candidate = git(root, ["rev-parse", "HEAD"]);
  const tree = git(root, ["show", "-s", "--format=%T", candidate]);
  const fixture = {
    root,
    base,
    head,
    candidate,
    tree,
    eventPath: join(root, "event.json"),
  };
  writeEvent(fixture);
  return fixture;
}

function writeEvent(fixture, changes = {}) {
  writeFileSync(
    fixture.eventPath,
    JSON.stringify({
      repository: { id: ID },
      workflow_run: {
        event: "push",
        conclusion: "success",
        head_branch: "main",
        head_sha: fixture.candidate,
        head_repository: { id: ID, full_name: REPOSITORY },
        ...changes,
      },
    }),
  );
}

function transport(fixture, options = {}) {
  const calls = [];
  const state = { tag: null, release: null, labels: ["autorelease: pending"] };
  const response = (data, status = 200) =>
    new Response(data === null ? null : JSON.stringify(data), {
      status,
      headers: { link: "" },
    });

  const fetch = async (url, init = {}) => {
    const value = new URL(url);
    const method = init.method ?? "GET";
    calls.push({ path: value.pathname + value.search, method });
    const pr = {
      ...pull(),
      merge_commit_sha: fixture.candidate,
      base: {
        ref: "main",
        sha: fixture.base,
        repo: { id: ID, full_name: REPOSITORY },
      },
      head: {
        ref: "release-please--branches--main--components--ran/wp-branch-updater",
        sha: fixture.head,
        repo: { id: ID, full_name: REPOSITORY },
      },
      labels: state.labels.map((name) => ({ name })),
    };

    if (value.pathname.endsWith(`/commits/${fixture.candidate}/pulls`)) {
      return response(options.ordinary ? [] : [pr]);
    }
    if (value.pathname.endsWith(`/git/commits/${fixture.head}`)) {
      return response({
        sha: fixture.head,
        tree: { sha: options.badTree ? "bad" : fixture.tree },
      });
    }
    if (value.pathname.endsWith("/git/ref/heads/main")) {
      return response({ object: { sha: fixture.candidate } });
    }
    if (value.pathname.includes("/git/ref/tags/")) {
      return state.tag ? response(state.tag) : response(null, 404);
    }
    if (value.pathname.includes("/releases/tags/")) {
      return state.release ? response(state.release) : response(null, 404);
    }
    if (value.pathname.endsWith("/releases") && method === "POST") {
      state.tag = { object: { type: "commit", sha: fixture.candidate } };
      const body = JSON.parse(init.body);
      state.release = { ...body, id: 99, immutable: true, assets: [] };
      return response(state.release, 201);
    }
    if (value.pathname.endsWith("/labels") && method === "POST") {
      if (options.failLabel) {
        options.failLabel = false;
        return response({ message: "lost acknowledgement" }, 500);
      }
      state.labels = ["autorelease: tagged"];
      return response(null, 204);
    }
    if (value.pathname.includes("/labels/autorelease%3A%20pending") && method === "DELETE") {
      state.labels = ["autorelease: tagged"];
      return response(null, 204);
    }
    if (value.pathname.endsWith("/pulls/7")) {
      return response(pr);
    }
    throw new Error(`unexpected ${method} ${value.pathname}`);
  };

  return { calls, fetch, state };
}

function publisherEnvironment(fixture, fetch) {
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
  process.env.GITHUB_EVENT_PATH = fixture.eventPath;
  process.env.GITHUB_TOKEN = "test";
  process.env.RAN_RELEASE_PUBLISHER_MUTATE = "1";
  process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID = String(ID);
  globalThis.fetch = fetch;

  return () => {
    for (const name of names) {
      if (before[name] === undefined) {
        delete process.env[name];
      } else {
        process.env[name] = before[name];
      }
    }
    globalThis.fetch = previousFetch;
    rmSync(fixture.root, { recursive: true, force: true });
  };
}

test("runPublisher uses a temporary Git merge and exact mocked publication sequence", async (context) => {
  const fixture = publisherFixture();
  const mocked = transport(fixture);
  context.after(publisherEnvironment(fixture, mocked.fetch));

  const result = await runPublisher(fixture.root);
  assert.equal(result.action, "create_release");
  assert.equal(
    mocked.calls.filter((call) => call.method === "POST" && call.path.endsWith("/releases")).length,
    1,
  );

  const release = mocked.calls.findIndex((call) =>
    call.method === "POST" && call.path.endsWith("/releases")
  );
  const label = mocked.calls.findIndex((call) =>
    call.method !== "GET" && call.path.includes("/labels")
  );
  assert.ok(
    mocked.calls.findIndex((call, index) =>
      index > release && call.path.includes("/releases/tags/")
    ) < label,
  );
  assert.ok(mocked.calls.some((call) => call.path.endsWith("/pulls/7")));
  assert.equal((await runPublisher(fixture.root)).action, "already_published");
  assert.equal(
    mocked.calls.filter((call) => call.method === "POST" && call.path.endsWith("/releases")).length,
    1,
  );
});

test("runPublisher ordinary, malformed, and disabled mutation paths never write", async () => {
  for (const options of [{ ordinary: true }, { badTree: true }, {}]) {
    const fixture = publisherFixture();
    if (options.ordinary) {
      writeFileSync(join(fixture.root, "ordinary.txt"), "ordinary\n");
      git(fixture.root, ["add", "ordinary.txt"]);
      git(fixture.root, ["commit", "-m", "fix: ordinary"]);
      fixture.candidate = git(fixture.root, ["rev-parse", "HEAD"]);
      fixture.tree = git(fixture.root, ["show", "-s", "--format=%T", "HEAD"]);
      writeEvent(fixture);
    }

    const mocked = transport(fixture, options);
    const restore = publisherEnvironment(fixture, mocked.fetch);
    if (!options.ordinary && !options.badTree) {
      delete process.env.RAN_RELEASE_PUBLISHER_MUTATE;
    }

    try {
      if (options.ordinary) {
        assert.equal((await runPublisher(fixture.root)).action, "none");
      } else {
        await assert.rejects(runPublisher(fixture.root));
      }
      assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0);
      if (options.ordinary) {
        assert.equal(mocked.calls.filter((call) => call.path.includes("/git/commits/")).length, 0);
      }
    } finally {
      restore();
    }
  }
});

test("missing or mismatched immutable acknowledgement refuses before any write", async () => {
  for (const acknowledgement of [undefined, String(ID + 1)]) {
    const fixture = publisherFixture();
    const mocked = transport(fixture);
    const restore = publisherEnvironment(fixture, mocked.fetch);
    if (acknowledgement === undefined) {
      delete process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID;
    } else {
      process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID = acknowledgement;
    }

    try {
      await assert.rejects(
        runPublisher(fixture.root),
        (error) => error.code === "immutable_releases_disabled",
      );
      assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0);
    } finally {
      restore();
    }
  }
});

test("post-create pending-label interruption reconciles without a second release", async (context) => {
  const fixture = publisherFixture();
  const mocked = transport(fixture, { failLabel: true });
  context.after(publisherEnvironment(fixture, mocked.fetch));

  await assert.rejects(runPublisher(fixture.root));
  assert.equal(mocked.state.labels[0], "autorelease: pending");
  const result = await runPublisher(fixture.root);
  assert.equal(result.action, "reconcile_labels");
  assert.equal(mocked.state.labels[0], "autorelease: tagged");
  assert.equal(
    mocked.calls.filter((call) => call.method === "POST" && call.path.endsWith("/releases")).length,
    1,
  );
});

test("complete unreleased root commit is ordinary and makes no writes", async (context) => {
  const fixture = publisherFixture(true);
  const mocked = transport(fixture, { ordinary: true });
  context.after(publisherEnvironment(fixture, mocked.fetch));

  assert.deepEqual(
    await runPublisher(fixture.root),
    { action: "none", reason: "ordinary_main" },
  );
  assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0);
});

test("wrong CI identity fails without writes", async () => {
  for (const changes of [
    { event: "workflow_dispatch" },
    { conclusion: "failure" },
    { head_branch: "other" },
    { head_repository: { id: ID + 1, full_name: REPOSITORY } },
    { head_repository: { id: ID, full_name: "other/repo" } },
  ]) {
    const fixture = publisherFixture();
    writeEvent(fixture, changes);
    const mocked = transport(fixture);
    const restore = publisherEnvironment(fixture, mocked.fetch);

    try {
      await assert.rejects(
        runPublisher(fixture.root),
        (error) => error.code === "quality_identity_invalid",
      );
      assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0);
    } finally {
      restore();
    }
  }
});

test("root commit with released metadata cannot publish", async (context) => {
  const fixture = publisherFixture(true);
  writeFileSync(join(fixture.root, ".release-please-manifest.json"), contents().manifest);
  writeFileSync(join(fixture.root, "CHANGELOG.md"), contents().changelog);
  git(fixture.root, ["add", ".release-please-manifest.json", "CHANGELOG.md"]);
  git(fixture.root, ["commit", "--amend", "--no-edit"]);
  fixture.candidate = git(fixture.root, ["rev-parse", "HEAD"]);
  writeEvent(fixture);

  const mocked = transport(fixture, { ordinary: true });
  context.after(publisherEnvironment(fixture, mocked.fetch));
  await assert.rejects(
    runPublisher(fixture.root),
    (error) => error.code === "release_content_drift",
  );
  assert.equal(mocked.calls.filter((call) => call.method !== "GET").length, 0);
});

test("partial parent metadata and executable candidate metadata refuse before network writes", async () => {
  for (const change of ["partial", "executable"]) {
    const fixture = publisherFixture(true);
    if (change === "partial") {
      git(fixture.root, ["rm", "CHANGELOG.md"]);
    } else {
      git(fixture.root, ["update-index", "--chmod=+x", ".release-please-manifest.json"]);
    }
    git(fixture.root, ["commit", "--amend", "--no-edit"]);

    if (change === "partial") {
      writeFileSync(join(fixture.root, "CHANGELOG.md"), "# Changelog\n\n## [Unreleased]\n\nBootstrap\n");
      git(fixture.root, ["add", "CHANGELOG.md"]);
      git(fixture.root, ["commit", "-m", "chore: setup"]);
    }

    fixture.candidate = git(fixture.root, ["rev-parse", "HEAD"]);
    writeEvent(fixture);
    const mocked = transport(fixture, { ordinary: true });
    const restore = publisherEnvironment(fixture, mocked.fetch);

    try {
      await assert.rejects(
        runPublisher(fixture.root),
        (error) => error.code === "release_content_drift",
      );
      assert.equal(mocked.calls.length, 0);
    } finally {
      restore();
    }
  }
});
