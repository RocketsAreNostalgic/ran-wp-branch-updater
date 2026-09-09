#!/usr/bin/env node
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { PublisherRefusal, candidateIdentity, manifestVersion, refuse, verifyReleaseDelta } from "./release-publisher-content.mjs";
import {
  decidePublication,
  hydrateExactReleasePullTree as hydrateReleasePullTree,
  labels,
  PENDING_LABEL,
  TAGGED_LABEL,
  verifyPublishedState,
} from "./release-publisher-decision.mjs";

export { PublisherRefusal, candidateIdentity, decidePublication, verifyPublishedState, verifyReleaseDelta };
const FULL_SHA = /^[a-f0-9]{40}$/;
const REPOSITORY = "RocketsAreNostalgic/ran-wp-branch-updater";
const API_VERSION = "2022-11-28";
const IMMUTABLE_RELEASES_API_VERSION = "2026-03-10";

function git(root, args) { return execFileSync("git", args, { cwd: root, encoding: "utf8" }).trim(); }
function blob(root, sha, file) { const entry = git(root, ["ls-tree", sha, "--", file]); if (!/^100644 blob [a-f0-9]{40}\t/.test(entry)) refuse("release_content_drift", `${file} must be an ordinary non-executable Git blob`); return execFileSync("git", ["show", `${sha}:${file}`], { cwd: root, encoding: "utf8" }); }
function contents(root, sha) { return { manifest: blob(root, sha, ".release-please-manifest.json"), composer: blob(root, sha, "composer.json"), changelog: blob(root, sha, "CHANGELOG.md") }; }
function treeEntry(root, sha, file) { const match = /^(\d+) (\w+) ([a-f0-9]{40})\t/.exec(git(root, ["ls-tree", sha, "--", file])); return match ? { mode: match[1], type: match[2], sha: match[3] } : null; }
export function classifyParentReleaseMetadata(entries) {
  const { manifest, changelog } = entries;
  if (manifest === null && changelog === null) return "absent";
  if (manifest === null || changelog === null) refuse("release_content_drift", "parent release metadata is incomplete");
  return "complete";
}
function parentContents(root, sha) {
  if (sha === null) return null;
  const state = classifyParentReleaseMetadata({ manifest: treeEntry(root, sha, ".release-please-manifest.json"), changelog: treeEntry(root, sha, "CHANGELOG.md") });
  return state === "complete" ? contents(root, sha) : null;
}
export function validateCandidate(root, sha) { return candidateIdentity(contents(root, sha), sha); }

async function api(path, options = {}) { if (!process.env.GITHUB_TOKEN) refuse("token_missing", "GITHUB_TOKEN is required"); const response = await fetch(`https://api.github.com${path}`, { method: options.method ?? "GET", headers: { Accept: "application/vnd.github+json", Authorization: `Bearer ${process.env.GITHUB_TOKEN}`, "User-Agent": "ran-wp-branch-updater-exact-publisher", "X-GitHub-Api-Version": options.apiVersion ?? API_VERSION }, body: options.body === undefined ? undefined : JSON.stringify(options.body), redirect: "error" }); if (options.allow404 && response.status === 404) return { data: null, headers: response.headers }; if (!response.ok) refuse("github_api_failed", `${options.method ?? "GET"} ${path} returned ${response.status}`); return { data: response.status === 204 ? null : await response.json(), headers: response.headers }; }
async function associatedPulls(repository, sha) { const pulls = []; for (let page = 1; page <= 10; page += 1) { const response = await api(`/repos/${repository}/commits/${sha}/pulls?per_page=100&page=${page}`); if (!Array.isArray(response.data)) refuse("pull_readback_invalid", "commit pull request response is not a list"); pulls.push(...response.data); if (!/<[^>]+>;\s*rel="next"/.test(response.headers.get("link") ?? "")) return pulls; } refuse("pull_readback_unbounded", "commit pull request response exceeded ten pages"); }
async function remoteState(repository, tag) { const encoded = encodeURIComponent(tag); const [tagRef, release] = await Promise.all([api(`/repos/${repository}/git/ref/tags/${encoded}`, { allow404: true }), api(`/repos/${repository}/releases/tags/${encoded}`, { allow404: true, apiVersion: IMMUTABLE_RELEASES_API_VERSION })]); return { tagRef: tagRef.data, release: release.data }; }
async function reconcileLabels(repository, number, value) { if (!value.includes(TAGGED_LABEL)) await api(`/repos/${repository}/issues/${number}/labels`, { method: "POST", body: { labels: [TAGGED_LABEL] } }); if (value.includes(PENDING_LABEL)) await api(`/repos/${repository}/issues/${number}/labels/${encodeURIComponent(PENDING_LABEL)}`, { method: "DELETE", allow404: true }); }
export async function hydrateExactReleasePullTree(repository, candidateSha, pulls, request = api) {
  return hydrateReleasePullTree(repository, candidateSha, pulls, request);
}
export async function runPublisher(root = process.cwd()) {
  const eventPath = process.env.GITHUB_EVENT_PATH; const repository = process.env.GITHUB_REPOSITORY;
  if (repository !== REPOSITORY || !eventPath || !process.env.GITHUB_TOKEN) refuse("environment_invalid", "publisher environment is incomplete");
  const payload = JSON.parse(readFileSync(eventPath, "utf8")); const event = payload.workflow_run; const sha = event?.head_sha; if (!FULL_SHA.test(sha ?? "") || git(root, ["rev-parse", "HEAD"]) !== sha) refuse("checkout_drift", "checkout is not the CI candidate");
  const candidateContents = contents(root, sha);
  const parents = git(root, ["show", "-s", "--format=%P", sha]).split(" ").filter(Boolean).map((value) => ({ sha: value }));
  const parent = parents[0]?.sha ?? null;
  const parentState = parentContents(root, parent);
  const unreleased = manifestVersion(candidateContents.manifest, "candidate", true) === "0.0.0";
  const identity = unreleased ? { candidateSha: sha, version: "0.0.0", tag: "v0.0.0", notes: "unreleased bootstrap" } : validateCandidate(root, sha);
  if (!parentState && !unreleased) refuse("release_content_drift", "released metadata requires a complete unreleased or released parent");
  const parentVersion = parentState ? manifestVersion(parentState.manifest, "parent", true) : "0.0.0";
  if (unreleased && parentState && parentVersion !== "0.0.0") refuse("release_version_regression", "released beta metadata may not return to 0.0.0");
  const unchangedVersion = parentState?.manifest === candidateContents.manifest;
  const delta = !parentState || unreleased ? { parentVersion } : unchangedVersion ? { parentVersion: identity.version } : verifyReleaseDelta(parentState, candidateContents);
  const [main, pulls, state] = await Promise.all([api(`/repos/${repository}/git/ref/heads/main`), associatedPulls(repository, sha), remoteState(repository, identity.tag)]);
  const hydratedPulls = await hydrateExactReleasePullTree(repository, sha, pulls);
  const changedPaths = parent === null ? [] : git(root, ["diff", "--name-only", parent, sha]).split("\n").filter(Boolean).sort();
  let input = { event, candidateSha: sha, mainSha: main.data?.object?.sha, identity, pulls: hydratedPulls, commit: { sha, parents, changedPaths, parentVersion: delta.parentVersion, tree: { sha: git(root, ["show", "-s", "--format=%T", sha]) } }, repository, repositoryId: payload.repository?.id, tagRef: state.tagRef, release: state.release };
  let result = decidePublication(input);
  if (result.action === "none") return result;
  if (process.env.RAN_RELEASE_PUBLISHER_MUTATE !== "1") refuse("mutation_disabled", "publisher mutation requires RAN_RELEASE_PUBLISHER_MUTATE=1");
  const [freshMain, freshPulls, freshState] = await Promise.all([api(`/repos/${repository}/git/ref/heads/main`), associatedPulls(repository, sha), remoteState(repository, identity.tag)]);
  input = { ...input, mainSha: freshMain.data?.object?.sha, pulls: await hydrateExactReleasePullTree(repository, sha, freshPulls), tagRef: freshState.tagRef, release: freshState.release, immutableReleasesEnabled: freshState.release === null ? process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID === String(payload.repository?.id) : undefined };
  result = decidePublication(input);
  if (result.action === "create_release") await api(`/repos/${repository}/releases`, { method: "POST", apiVersion: IMMUTABLE_RELEASES_API_VERSION, body: { tag_name: identity.tag, target_commitish: sha, name: identity.tag, body: identity.notes, draft: false, prerelease: true, generate_release_notes: false } });
  const checked = await remoteState(repository, identity.tag); verifyPublishedState(checked.tagRef, checked.release, identity);
  const original = input.pulls.find((pull) => pull.number === result.pullNumber); await reconcileLabels(repository, result.pullNumber, labels(original));
  const finalPull = (await api(`/repos/${repository}/pulls/${result.pullNumber}`)).data;
  const finalWithTree = (await hydrateExactReleasePullTree(repository, sha, [finalPull]))[0];
  decidePublication({ ...input, pulls: [finalWithTree], tagRef: checked.tagRef, release: checked.release });
  if (!labels(finalPull).includes(TAGGED_LABEL) || labels(finalPull).includes(PENDING_LABEL)) refuse("release_label_readback_failed", "release PR labels did not reconcile to tagged");
  return { ...result, releaseId: checked.release.id };
}
if (process.argv[1] === fileURLToPath(import.meta.url)) runPublisher().catch((error) => { console.error(error); process.exitCode = 1; });