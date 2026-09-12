import assert from "node:assert/strict";
import test from "node:test";

import {
  manifestVersion,
  verifyReleaseDelta,
} from "../scripts/release-publisher-content.mjs";

test("release metadata accepts a canonical beta major bump", () => {
  const before = "0.1.0-beta.1";
  const after = "1.0.0-beta.1";
  const prefix = "# Changelog\n\n";
  const parent = {
    manifest: JSON.stringify({ ".": before }),
    composer: JSON.stringify({ name: "ran/wp-branch-updater", type: "library" }),
    changelog: `${prefix}## ${before} (2026-09-09)\n\n### Features\n\n* prior beta\n`,
  };
  const candidate = {
    manifest: JSON.stringify({ ".": after }),
    composer: parent.composer,
    changelog: `${prefix}## [${after}](https://github.com/RocketsAreNostalgic/ran-wp-branch-updater/compare/v${before}...v${after}) (2026-09-11)\n\n### ⚠ BREAKING CHANGES\n\n* normalize public architecture\n\n${parent.changelog.slice(prefix.length)}`,
  };

  assert.equal(manifestVersion(candidate.manifest, "candidate"), after);
  assert.deepEqual(verifyReleaseDelta(parent, candidate), {
    parentVersion: before,
    candidateVersion: after,
  });
});

test("release metadata compares large beta components without precision loss", () => {
  const before = "1.0.0-beta.9007199254740992";
  const after = "1.0.0-beta.9007199254740993";
  const prefix = "# Changelog\n\n";
  const parent = {
    manifest: JSON.stringify({ ".": before }),
    composer: JSON.stringify({ name: "ran/wp-branch-updater", type: "library" }),
    changelog: `${prefix}## ${before} (2026-09-09)\n\n### Features\n\n* prior beta\n`,
  };
  const candidate = {
    manifest: JSON.stringify({ ".": after }),
    composer: parent.composer,
    changelog: `${prefix}## [${after}](https://github.com/RocketsAreNostalgic/ran-wp-branch-updater/compare/v${before}...v${after}) (2026-09-11)\n\n### Bug Fixes\n\n* exact beta ordering\n\n${parent.changelog.slice(prefix.length)}`,
  };

  assert.deepEqual(verifyReleaseDelta(parent, candidate), {
    parentVersion: before,
    candidateVersion: after,
  });
});

test("release metadata rejects a large major regression despite a later minor bump", () => {
  const before = "9007199254740993.0.0-beta.1";
  const after = "9007199254740992.1.0-beta.1";
  const prefix = "# Changelog\n\n";
  const parent = {
    manifest: JSON.stringify({ ".": before }),
    composer: JSON.stringify({ name: "ran/wp-branch-updater", type: "library" }),
    changelog: `${prefix}## ${before} (2026-09-09)\n\n### Features\n\n* prior beta\n`,
  };
  const candidate = {
    manifest: JSON.stringify({ ".": after }),
    composer: parent.composer,
    changelog: `${prefix}## [${after}](https://github.com/RocketsAreNostalgic/ran-wp-branch-updater/compare/v${before}...v${after}) (2026-09-11)\n\n### Bug Fixes\n\n* invalid regression\n\n${parent.changelog.slice(prefix.length)}`,
  };

  assert.throws(
    () => verifyReleaseDelta(parent, candidate),
    (error) => error.code === "release_version_not_advanced",
  );
});

test("release metadata still rejects noncanonical beta numbers", () => {
  assert.throws(
    () => manifestVersion(JSON.stringify({ ".": "1.0.0-beta.01" }), "candidate"),
    (error) => error.code === "release_manifest_invalid",
  );
});
