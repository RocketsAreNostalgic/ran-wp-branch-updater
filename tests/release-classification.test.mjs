import assert from "node:assert/strict";
import test from "node:test";

import {
  assertReleaseClassification,
  classifyTitle,
  productionRequirementsChanged,
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

test("rejects non-Conventional pull request titles", () => {
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

test("does not escalate require-dev-only changes", () => {
  const result = assertReleaseClassification({
    baseComposer,
    headComposer: {
      ...baseComposer,
      "require-dev": {
        "phpstan/phpstan": "^3.0",
      },
    },
    releaseConfig,
    title: "chore: update development tooling",
  });

  assert.equal(result.required, false);
  assert.equal(result.classification.type, "chore");
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
