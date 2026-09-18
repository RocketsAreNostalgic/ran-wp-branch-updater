import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";

import {
  assertCanonicalReleasePull,
  assertReleaseClassification,
  classifyTitle,
  productionComposerMetadataChanged,
  runCli,
  visibleReleaseTypes,
} from "../scripts/release-classification.mjs";

const releaseConfig = {
  packages: {
    ".": {
      "changelog-sections": [
        { type: "feat", section: "Features" },
        { type: "fix", section: "Bug Fixes" },
        { type: "deps", section: "Dependencies" },
        { type: "perf", section: "Performance" },
        { type: "revert", section: "Reverts" },
        { type: "refactor", section: "Code Refactoring", hidden: true },
        { type: "chore", section: "Miscellaneous Chores", hidden: true },
      ],
    },
  },
};

const baseComposer = {
  name: "ran/wp-branch-updater",
  type: "library",
  require: {
    php: "^8.2",
    "ran/updater-support": "0.1.0-beta.3",
  },
  autoload: {
    "psr-4": {
      "RAN\\WPBranchUpdater\\V1\\": "src/",
    },
  },
  "require-dev": {
    "phpstan/phpstan": "^2.1",
  },
};

const manifest = { ".": "1.0.0-beta.6" };

function git(root, args) {
  return execFileSync("git", args, {
    cwd: root,
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
  }).trim();
}

function writeJson(path, value) {
  writeFileSync(path, `${JSON.stringify(value, null, 2)}\n`, "utf8");
}

test("derives visible release-driving types from trusted config", () => {
  assert.deepEqual([...visibleReleaseTypes(releaseConfig)], [
    "feat",
    "fix",
    "deps",
    "perf",
    "revert",
  ]);
});

test("parses scoped and breaking Conventional Commit titles", () => {
  assert.deepEqual(classifyTitle("fix(release): tighten updater"), {
    type: "fix",
    breaking: false,
  });
  assert.deepEqual(classifyTitle("refactor!: replace updater contract"), {
    type: "refactor",
    breaking: true,
  });
});

test("consumer-facing Composer metadata comparison is recursive", () => {
  assert.equal(
    productionComposerMetadataChanged(baseComposer, {
      ...baseComposer,
      require: {
        "ran/updater-support": "0.1.0-beta.3",
        php: "^8.2",
      },
      "require-dev": {
        "phpstan/phpstan": "^3.0",
      },
    }),
    false,
  );
  assert.equal(
    productionComposerMetadataChanged(baseComposer, {
      ...baseComposer,
      autoload: {
        "psr-4": {
          "RAN\\WPBranchUpdater\\V1\\": "lib/",
        },
      },
    }),
    true,
  );
});

test("canonical Release Please pull title must exactly match manifest version", () => {
  assert.equal(
    assertCanonicalReleasePull({
      author: "github-actions[bot]",
      headRef: "release-please--branches--main--components--ran/wp-branch-updater",
      manifest,
      title: "chore(main): release 1.0.0-beta.6",
    }),
    true,
  );
  assert.throws(
    () =>
      assertCanonicalReleasePull({
        author: "github-actions[bot]",
        headRef:
          "release-please--branches--main--components--ran/wp-branch-updater",
        manifest,
        title: "chore: release 1.0.0-beta.6",
      }),
    /must be exactly/,
  );
});

test("production metadata changes reject hidden classification", () => {
  assert.throws(
    () =>
      assertReleaseClassification({
        baseComposer,
        headComposer: {
          ...baseComposer,
          require: {
            ...baseComposer.require,
            "ran/updater-support": "0.1.0-beta.4",
          },
        },
        releaseConfig,
        title: "refactor: update support dependency",
        manifest,
      }),
    /not release-driving/,
  );
});

test("visible and explicit breaking classifications admit production metadata changes", () => {
  assert.equal(
    assertReleaseClassification({
      baseComposer,
      headComposer: {
        ...baseComposer,
        require: {
          ...baseComposer.require,
          "ran/updater-support": "0.1.0-beta.4",
        },
      },
      releaseConfig,
      title: "deps: update support dependency",
      manifest,
    }).classification.type,
    "deps",
  );
  assert.equal(
    assertReleaseClassification({
      baseComposer,
      headComposer: {
        ...baseComposer,
        autoload: {
          "psr-4": {
            "RAN\\WPBranchUpdater\\V2\\": "src/",
          },
        },
      },
      releaseConfig,
      title: "refactor!: replace namespace contract",
      manifest,
    }).classification.breaking,
    true,
  );
});

test("CLI executes from protected base and reads head metadata as Git data", () => {
  const root = mkdtempSync(join(tmpdir(), "branch-updater-classification-"));
  try {
    git(root, ["init", "--initial-branch=main"]);
    git(root, ["config", "user.name", "Release Test"]);
    git(root, ["config", "user.email", "release@example.invalid"]);
    writeJson(join(root, "composer.json"), baseComposer);
    writeJson(join(root, "release-please-config.json"), releaseConfig);
    writeJson(join(root, ".release-please-manifest.json"), manifest);
    git(root, ["add", "."]);
    git(root, ["commit", "-m", "chore: base"]);
    const baseSha = git(root, ["rev-parse", "HEAD"]);

    writeJson(join(root, "composer.json"), {
      ...baseComposer,
      require: {
        ...baseComposer.require,
        "ran/updater-support": "0.1.0-beta.4",
      },
    });
    git(root, ["add", "composer.json"]);
    git(root, ["commit", "-m", "deps: update support"]);
    const headSha = git(root, ["rev-parse", "HEAD"]);
    git(root, ["checkout", baseSha]);

    const result = runCli(root, {
      RAN_RELEASE_BASE_SHA: baseSha,
      RAN_RELEASE_HEAD_SHA: headSha,
      RAN_RELEASE_PR_TITLE: "deps: update support",
      RAN_RELEASE_PR_HEAD_REF: "feature",
      RAN_RELEASE_PR_AUTHOR: "contributor",
    });
    assert.equal(result.required, true);
    assert.equal(result.classification.type, "deps");
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});
