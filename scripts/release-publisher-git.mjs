import { execFileSync } from "node:child_process";
import { candidateIdentity, refuse } from "./release-publisher-content.mjs";

function git(root, args) {
  return execFileSync("git", args, { cwd: root, encoding: "utf8" }).trim();
}

function blob(root, sha, file) {
  const entry = git(root, ["ls-tree", sha, "--", file]);
  if (!/^100644 blob [a-f0-9]{40}\t/.test(entry)) {
    refuse("release_content_drift", `${file} must be an ordinary non-executable Git blob`);
  }
  return execFileSync("git", ["show", `${sha}:${file}`], { cwd: root, encoding: "utf8" });
}

export function releaseContents(root, sha) {
  return {
    manifest: blob(root, sha, ".release-please-manifest.json"),
    composer: blob(root, sha, "composer.json"),
    changelog: blob(root, sha, "CHANGELOG.md"),
  };
}

function treeEntry(root, sha, file) {
  const match = /^(\d+) (\w+) ([a-f0-9]{40})\t/.exec(git(root, ["ls-tree", sha, "--", file]));
  return match ? { mode: match[1], type: match[2], sha: match[3] } : null;
}

export function classifyParentReleaseMetadata(entries) {
  const { manifest, changelog } = entries;
  if (manifest === null && changelog === null) {
    return "absent";
  }
  if (manifest === null || changelog === null) {
    refuse("release_content_drift", "parent release metadata is incomplete");
  }
  return "complete";
}

export function parentReleaseContents(root, sha) {
  if (sha === null) {
    return null;
  }
  const state = classifyParentReleaseMetadata({
    manifest: treeEntry(root, sha, ".release-please-manifest.json"),
    changelog: treeEntry(root, sha, "CHANGELOG.md"),
  });
  return state === "complete" ? releaseContents(root, sha) : null;
}

export function validateCandidate(root, sha) {
  return candidateIdentity(releaseContents(root, sha), sha);
}

export function currentSha(root) {
  return git(root, ["rev-parse", "HEAD"]);
}

export function commitParents(root, sha) {
  return git(root, ["show", "-s", "--format=%P", sha])
    .split(" ")
    .filter(Boolean)
    .map((value) => ({ sha: value }));
}

export function changedPaths(root, parentSha, sha) {
  if (parentSha === null) {
    return [];
  }
  return git(root, ["diff", "--name-only", parentSha, sha])
    .split("\n")
    .filter(Boolean)
    .sort();
}

export function treeSha(root, sha) {
  return git(root, ["show", "-s", "--format=%T", sha]);
}
