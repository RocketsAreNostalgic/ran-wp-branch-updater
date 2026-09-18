#!/usr/bin/env node

import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

const FULL_SHA = /^[a-f0-9]{40}$/;
const TITLE = /^([a-z][a-z0-9-]*)(?:\([^)]+\))?(!)?:\s+\S/;

function objectRecord(value, label) {
  if (value === null || typeof value !== "object" || Array.isArray(value)) {
    throw new Error(`${label} must be a JSON object`);
  }
  return value;
}

function canonicalRecord(value) {
  return Object.fromEntries(
    Object.entries(value).sort(([left], [right]) => left.localeCompare(right)),
  );
}

export function productionRequirements(composer) {
  const document = objectRecord(composer, "composer.json");
  const requirements = document.require ?? {};
  return canonicalRecord(objectRecord(requirements, "composer.json require"));
}

export function visibleReleaseTypes(config) {
  const document = objectRecord(config, "release-please-config.json");
  const packages = objectRecord(document.packages, "release-please-config.json packages");
  const root = objectRecord(packages["."], "release-please-config.json root package");
  const sections = root["changelog-sections"];

  if (!Array.isArray(sections) || sections.length === 0) {
    throw new Error("release-please-config.json must declare changelog-sections");
  }

  const types = new Set();
  for (const section of sections) {
    const entry = objectRecord(section, "release-please changelog section");
    if (entry.hidden === true) {
      continue;
    }
    if (typeof entry.type !== "string" || entry.type.length === 0) {
      throw new Error("visible release-please changelog sections must declare a type");
    }
    types.add(entry.type);
  }

  if (types.size === 0) {
    throw new Error("release-please-config.json declares no visible release-driving types");
  }

  return types;
}

export function classifyTitle(title) {
  if (typeof title !== "string") {
    throw new Error("pull request title is required");
  }

  const match = title.match(TITLE);
  if (!match) {
    throw new Error("pull request title must use Conventional Commit syntax");
  }

  return {
    type: match[1],
    breaking: match[2] === "!",
  };
}

export function productionRequirementsChanged(baseComposer, headComposer) {
  return JSON.stringify(productionRequirements(baseComposer))
    !== JSON.stringify(productionRequirements(headComposer));
}

export function assertReleaseClassification({
  baseComposer,
  headComposer,
  releaseConfig,
  title,
}) {
  if (!productionRequirementsChanged(baseComposer, headComposer)) {
    return { required: false, classification: null };
  }

  const classification = classifyTitle(title);
  const visible = visibleReleaseTypes(releaseConfig);
  if (!classification.breaking && !visible.has(classification.type)) {
    throw new Error(
      `production composer requirements changed, but ${classification.type}: is not release-driving; use one of ${[...visible].join(", ")} or an explicit breaking ! classification`,
    );
  }

  return { required: true, classification };
}

function readJson(path) {
  return JSON.parse(readFileSync(path, "utf8"));
}

function git(root, args) {
  return execFileSync("git", args, {
    cwd: root,
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
  }).trim();
}

function readBaseComposer(root, baseSha) {
  return JSON.parse(git(root, ["show", `${baseSha}:composer.json`]));
}

export function runCli(root = process.cwd(), env = process.env) {
  const baseSha = env.RAN_RELEASE_BASE_SHA;
  const headSha = env.RAN_RELEASE_HEAD_SHA;
  const title = env.RAN_RELEASE_PR_TITLE;

  if (!FULL_SHA.test(baseSha ?? "") || !FULL_SHA.test(headSha ?? "")) {
    throw new Error("exact pull request base and head SHAs are required");
  }

  const checkoutSha = git(root, ["rev-parse", "HEAD"]);
  if (checkoutSha !== headSha) {
    throw new Error(`checked out revision ${checkoutSha} does not match pull request head ${headSha}`);
  }

  const result = assertReleaseClassification({
    baseComposer: readBaseComposer(root, baseSha),
    headComposer: readJson(`${root}/composer.json`),
    releaseConfig: readJson(`${root}/release-please-config.json`),
    title,
  });

  if (result.required) {
    console.log(`production requirements changed; ${result.classification.type}: is release-driving`);
  } else {
    console.log("production requirements unchanged; no release-classification escalation required");
  }

  return result;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  try {
    runCli();
  } catch (error) {
    console.error(error instanceof Error ? error.message : error);
    process.exitCode = 1;
  }
}
