import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";

import {
  assertReleaseClassification,
  classifyTitle,
  productionRequirementsChanged,
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
        { type: "refactor", section: "Code Refactoring", hidden: true },
        { type: "chore", section: "Miscellaneous Chores", hidden: true },
      ],
    },
  },
};

const baseComposer = {
  require: {
    php: "^8.2",
    "ran/updater-support": "0.1.0-beta.2",
  },
  "require-dev": {
    "phpstan/phpstan": "^2.1",
  },
};

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

function withTemporaryRepository(callback) {
  const root = mkdtempSync(join(tmpdir(), "ran-release-classification-"));

  try {
    git(root, ["init"]);
    git(root, ["config", "user.name", "Release Classification Test"]);
    git(root, ["config", "user.email", "release-classification@example.invalid"]);

    writeJson(join(root, "composer.json"), baseComposer);
    writeJson(join(root, "release-please-config.json"), releaseConfig);
    git(root, ["add", "composer.json", "release-please-config.json"]);
    git(root, ["commit", "-m", "chore: establish test base"]);
    const baseSha = git(root, ["rev-parse", "HEAD"]);

    writeJson(join(root, "composer.json"), {
      ...baseComposer,
      require: {
        php: "^8.2",
        "ran/updater-support": "0.1.0-beta.3",
      },
    });
    git(root, ["add", "composer.json"]);
    git(root, ["commit", "-m", "deps: update production dependency"]);
    const headSha = git(root, ["rev-parse", "HEAD"]);

    return callback({ root, baseSha, headSha });
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
}

test("derives visible release-driving types from Release Please config", () => {
  assert.deepEqual([...visibleReleaseTypes(releaseConfig)], ["feat", "fix", "deps"]);
});

test("parses scoped and breaking Conventional Commit titles", () => {
  assert.deepEqual(classifyTitle("fix(release): enforce release classification"), {
    type: "fix",
    breaking: false,
  });
  assert.deepEqual(classifyTitle("refactor!: replace runtime contract"), {
    type: "refactor",
    breaking: true,
  });
});

test("rejects non-Conventional pull request titles when classification is required", () => {
  assert.throws(
    () => classifyTitle("Update release classification"),
    /Conventional Commit syntax/,
  );
});

test("detects production requirement changes independent of key order", () => {
  assert.equal(
    productionRequirementsChanged(baseComposer, {
      ...baseComposer,
      require: {
        "ran/updater-support": "0.1.0-beta.2",
        php: "^8.2",
      },
    }),
    false,
  );

  assert.equal(
    productionRequirementsChanged(baseComposer, {
      ...baseComposer,
      require: {
        php: "^8.2",
        "ran/updater-support": "0.1.0-beta.3",
      },
    }),
    true,
  );
});

test("does not escalate require-dev-only changes or lint unrelated titles", () => {
  const result = assertReleaseClassification({
    baseComposer,
    headComposer: {
      ...baseComposer,
      "require-dev": {
        "phpstan/phpstan": "^3.0",
      },
    },
    releaseConfig,
    title: "Update development tooling",
  });

  assert.equal(result.required, false);
  assert.equal(result.classification, null);
});

test("accepts a visible dependency classification for production requirement changes", () => {
  const result = assertReleaseClassification({
    baseComposer,
    headComposer: {
      ...baseComposer,
      require: {
        php: "^8.2",
        "ran/updater-support": "0.1.0-beta.3",
      },
    },
    releaseConfig,
    title: "deps: adopt updater support beta.3",
  });

  assert.equal(result.required, true);
  assert.equal(result.classification.type, "deps");
});

test("accepts another visible release-driving type when repository policy allows it", () => {
  const result = assertReleaseClassification({
    baseComposer,
    headComposer: {
      ...baseComposer,
      require: {
        php: "^8.3",
        "ran/updater-support": "0.1.0-beta.2",
      },
    },
    releaseConfig,
    title: "fix: raise the supported runtime floor",
  });

  assert.equal(result.required, true);
  assert.equal(result.classification.type, "fix");
});

test("rejects hidden classification when production requirements changed", () => {
  assert.throws(
    () => assertReleaseClassification({
      baseComposer,
      headComposer: {
        ...baseComposer,
        require: {
          php: "^8.2",
          "ran/updater-support": "0.1.0-beta.3",
        },
      },
      releaseConfig,
      title: "refactor: consume shared repository path safety",
    }),
    /production composer requirements changed, but refactor: is not release-driving/,
  );
});

test("accepts explicit breaking classification even when the base type is hidden", () => {
  const result = assertReleaseClassification({
    baseComposer,
    headComposer: {
      ...baseComposer,
      require: {
        php: "^9.0",
        "ran/updater-support": "0.1.0-beta.2",
      },
    },
    releaseConfig,
    title: "refactor!: require PHP 9",
  });

  assert.equal(result.required, true);
  assert.equal(result.classification.breaking, true);
});

test("CLI accepts exact base and checked-out head revisions", () => {
  withTemporaryRepository(({ root, baseSha, headSha }) => {
    const result = runCli(root, {
      RAN_RELEASE_BASE_SHA: baseSha,
      RAN_RELEASE_HEAD_SHA: headSha,
      RAN_RELEASE_PR_TITLE: "deps: adopt updater support beta.3",
    });

    assert.equal(result.required, true);
    assert.equal(result.classification.type, "deps");
  });
});

test("CLI rejects a head SHA that is not the checked-out revision", () => {
  withTemporaryRepository(({ root, baseSha }) => {
    assert.throws(
      () => runCli(root, {
        RAN_RELEASE_BASE_SHA: baseSha,
        RAN_RELEASE_HEAD_SHA: baseSha,
        RAN_RELEASE_PR_TITLE: "deps: adopt updater support beta.3",
      }),
      /does not match pull request head/,
    );
  });
});

test("CLI rejects an unavailable exact base revision", () => {
  withTemporaryRepository(({ root, headSha }) => {
    assert.throws(
      () => runCli(root, {
        RAN_RELEASE_BASE_SHA: "a".repeat(40),
        RAN_RELEASE_HEAD_SHA: headSha,
        RAN_RELEASE_PR_TITLE: "deps: adopt updater support beta.3",
      }),
    );
  });
});
