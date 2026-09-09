import assert from "node:assert/strict";
import test from "node:test";

import {
  PublisherRefusal,
  candidateIdentity,
  classifyParentReleaseMetadata,
  decidePublication,
  hydrateExactReleasePullTree,
  verifyReleaseDelta,
  verifyPublishedState,
} from "../scripts/release-publisher.mjs";

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

function refusal(code, callback) {
  assert.throws(
    callback,
    (error) => error instanceof PublisherRefusal && error.code === code,
  );
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

function publicationInput() {
  return {
    event: {
      event: "push",
      conclusion: "success",
      head_branch: "main",
      head_sha: SHA,
      head_repository: { full_name: REPOSITORY, id: ID },
    },
    candidateSha: SHA,
    mainSha: SHA,
    identity: candidateIdentity(contents(), SHA),
    pulls: [pull()],
    repository: REPOSITORY,
    repositoryId: ID,
    tagRef: null,
    release: null,
    immutableReleasesEnabled: true,
    commit: {
      sha: SHA,
      parents: [{ sha: "c".repeat(40) }, { sha: "d".repeat(40) }],
      tree: { sha: "e".repeat(40) },
      parentVersion: "0.0.0",
      changedPaths: [".release-please-manifest.json", "CHANGELOG.md"],
    },
  };
}

test("candidate binds manifest, Composer identity and release notes", () => {
  const identity = candidateIdentity(contents(), SHA);
  assert.equal(identity.version, VERSION);
  assert.equal(identity.tag, `v${VERSION}`);
});

test("candidate accepts dated linked headings only on the independent beta line", () => {
  const next = "0.1.0-beta.2";
  const linked = {
    ...contents(next),
    changelog: `# Changelog\n\n## [${next}](https://github.com/RocketsAreNostalgic/ran-wp-branch-updater/compare/v${VERSION}...v${next}) (2026-09-02)\n\n### Bug Fixes\n\n* second release\n`,
  };
  assert.equal(candidateIdentity(linked, SHA).version, next);
  for (const version of ["0.2.0-beta.1", "1.0.0-beta.1"]) {
    refusal("release_manifest_invalid", () => candidateIdentity(contents(version), SHA));
  }
});

test("release delta permits only manifest version and a changelog prepend", () => {
  const parent = {
    ...contents("0.0.0"),
    changelog: "# Changelog\n\n## [Unreleased]\n\nAll notable changes.\n",
  };
  const candidate = {
    ...contents(),
    changelog: `# Changelog\n\n## ${VERSION} (2026-09-01)\n\n### Features\n\n* first release\n\n## [Unreleased]\n\nAll notable changes.\n`,
  };
  assert.deepEqual(
    verifyReleaseDelta(parent, candidate),
    { parentVersion: "0.0.0", candidateVersion: VERSION },
  );

  const next = "0.1.0-beta.2";
  const later = {
    ...contents(next),
    changelog: `# Changelog\n\n## [${next}](https://github.com/RocketsAreNostalgic/ran-wp-branch-updater/compare/v${VERSION}...v${next}) (2026-09-02)\n\n### Bug Fixes\n\n* second release\n\n${candidate.changelog.slice("# Changelog\n\n".length)}`,
  };
  assert.deepEqual(
    verifyReleaseDelta(candidate, later),
    { parentVersion: VERSION, candidateVersion: next },
  );
  refusal("release_content_drift", () =>
    verifyReleaseDelta(parent, {
      ...candidate,
      manifest: JSON.stringify({ ".": VERSION }, null, 2),
    })
  );
});

test("only exact green CI normal merge and changed paths can publish", () => {
  const input = publicationInput();
  assert.deepEqual(decidePublication(input), { action: "create_release", pullNumber: 7 });
  refusal("release_paths_invalid", () =>
    decidePublication({
      ...input,
      commit: {
        ...input.commit,
        changedPaths: [...input.commit.changedPaths, "src/ArchiveValidator.php"],
      },
    })
  );
  refusal("main_moved", () => decidePublication({ ...input, mainSha: undefined }));
  for (const parentVersion of ["0.2.0-beta.1", "1.0.0-beta.1"]) {
    refusal("release_parent_version_invalid", () =>
      decidePublication({ ...input, commit: { ...input.commit, parentVersion } })
    );
  }
});

test("exact merged Release Please pull hydrates its head tree", async () => {
  const releasePull = { ...pull(), head_tree_sha: undefined };
  const calls = [];
  const pulls = await hydrateExactReleasePullTree(
    REPOSITORY,
    SHA,
    [releasePull],
    async (path, options) => {
      calls.push({ path, options });
      return { sha: releasePull.head.sha, tree: { sha: "e".repeat(40) } };
    },
  );
  assert.equal(pulls[0].head_tree_sha, "e".repeat(40));
  assert.deepEqual(calls, [{
    path: `/repos/${REPOSITORY}/git/commits/${releasePull.head.sha}`,
    options: undefined,
  }]);
});

test("malformed hydrated head tree fails closed without writes", async () => {
  const releasePull = { ...pull(), head_tree_sha: undefined };
  const calls = [];
  await assert.rejects(
    hydrateExactReleasePullTree(
      REPOSITORY,
      SHA,
      [releasePull],
      async (path, options) => {
        calls.push({ path, options });
        return { sha: releasePull.head.sha, tree: { sha: "not-a-sha" } };
      },
    ),
    (error) => error.code === "release_pr_head_tree_invalid",
  );
  assert.deepEqual(calls, [{
    path: `/repos/${REPOSITORY}/git/commits/${releasePull.head.sha}`,
    options: undefined,
  }]);
});

test("ordinary unreleased main state has no publication side effect", () => {
  const identity = { candidateSha: SHA, version: "0.0.0" };
  assert.deepEqual(
    decidePublication({
      event: {
        event: "push",
        conclusion: "success",
        head_branch: "main",
        head_sha: SHA,
        head_repository: { full_name: REPOSITORY, id: ID },
      },
      candidateSha: SHA,
      mainSha: SHA,
      identity,
      pulls: [],
      repository: REPOSITORY,
      repositoryId: ID,
      commit: { parentVersion: "0.0.0" },
    }),
    { action: "none", reason: "ordinary_main" },
  );
});

test("missing or partial parent metadata has no legacy exceptions", () => {
  assert.equal(classifyParentReleaseMetadata({ manifest: null, changelog: null }), "absent");
  const blob = { mode: "100644", type: "blob", sha: SHA };
  assert.equal(classifyParentReleaseMetadata({ manifest: blob, changelog: blob }), "complete");
  for (const entries of [
    { manifest: blob, changelog: null },
    { manifest: null, changelog: blob },
  ]) {
    refusal("release_content_drift", () => classifyParentReleaseMetadata(entries));
  }
});

test("immutable release readback rejects mutable or asset-bearing releases", () => {
  const identity = candidateIdentity(contents(), SHA);
  const tag = { object: { type: "commit", sha: SHA } };
  const release = {
    id: 1,
    tag_name: identity.tag,
    target_commitish: SHA,
    name: identity.tag,
    body: identity.notes,
    draft: false,
    prerelease: true,
    immutable: true,
    assets: [],
  };
  assert.equal(verifyPublishedState(tag, release, identity), true);
  refusal("release_state_conflict", () => verifyPublishedState(tag, { ...release, immutable: false }, identity));
  refusal("release_asset_conflict", () => verifyPublishedState(tag, { ...release, assets: [{ id: 1 }] }, identity));
  refusal("release_state_conflict", () => verifyPublishedState({ object: { type: "tag", sha: SHA } }, release, identity));
});

test("initial release must be exactly beta.1 and later releases must advance", () => {
  const parent = {
    ...contents("0.0.0"),
    changelog: "# Changelog\n\n## [Unreleased]\n\nBootstrap\n",
  };
  for (const version of ["0.1.0-beta.0", "0.1.0-beta.2"]) {
    refusal("release_version_not_advanced", () => verifyReleaseDelta(parent, contents(version)));
  }
  refusal("release_version_not_advanced", () => verifyReleaseDelta(contents(), contents()));
  refusal("release_version_not_advanced", () =>
    verifyReleaseDelta(contents("0.1.0-beta.2"), contents())
  );
});

test("squash, rebase, extra parents, reversed parents and merge tree drift refuse publication", () => {
  const input = publicationInput();
  for (const commit of [
    { ...input.commit, parents: [input.commit.parents[0]] },
    { ...input.commit, parents: [] },
    { ...input.commit, parents: [...input.commit.parents, { sha: "f".repeat(40) }] },
    { ...input.commit, parents: input.commit.parents.toReversed() },
    { ...input.commit, tree: { sha: "f".repeat(40) } },
  ]) {
    refusal("release_pr_not_normal_merge", () => decidePublication({ ...input, commit }));
  }
});

test("fork, wrong bot, title or branch and ambiguous release associations refuse", () => {
  const input = publicationInput();
  const pr = input.pulls[0];
  for (const candidate of [
    { ...pr, head: { ...pr.head, repo: { ...pr.head.repo, id: ID + 1 } } },
    { ...pr, base: { ...pr.base, repo: { ...pr.base.repo, full_name: "other/repo" } } },
    { ...pr, user: { login: "someone" } },
    { ...pr, title: "chore: release" },
    { ...pr, head: { ...pr.head, ref: "another-branch" } },
  ]) {
    refusal("release_pr_invalid", () => decidePublication({ ...input, pulls: [candidate] }));
  }
  refusal("release_pr_ambiguous", () => decidePublication({ ...input, pulls: [pr, pr] }));
  refusal("release_pr_ambiguous", () =>
    decidePublication({ ...input, pulls: [pr, { ...pr, merge_commit_sha: "f".repeat(40) }] })
  );
});

test("partial publication and incompatible lifecycle labels fail closed", () => {
  const input = publicationInput();
  refusal("partial_publication_state", () =>
    decidePublication({ ...input, tagRef: { object: { type: "commit", sha: SHA } } })
  );
  refusal("release_without_tag", () => decidePublication({ ...input, release: { id: 1 } }));

  for (const releaseLabels of [
    [],
    [{ name: "autorelease: tagged" }],
    [{ name: "autorelease: tagged" }, { name: "autorelease: pending" }],
  ]) {
    refusal("release_pr_label_conflict", () =>
      decidePublication({ ...input, pulls: [{ ...pull(), labels: releaseLabels }] })
    );
  }

  for (const version of ["0.1.0-beta.0", "0.1.0-beta.2", "1.0.0"]) {
    const identity = { ...input.identity, version };
    refusal("release_version_invalid", () =>
      decidePublication({
        ...input,
        identity,
        pulls: [{ ...pull(), title: `chore(main): release ${version}` }],
      })
    );
  }
});
