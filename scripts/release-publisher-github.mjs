import { refuse } from "./release-publisher-content.mjs";

const API_VERSION = "2022-11-28";
const IMMUTABLE_RELEASES_API_VERSION = "2026-03-10";

export async function api(path, options = {}) {
  if (!process.env.GITHUB_TOKEN) {
    refuse("token_missing", "GITHUB_TOKEN is required");
  }

  const response = await fetch(`https://api.github.com${path}`, {
    method: options.method ?? "GET",
    headers: {
      Accept: "application/vnd.github+json",
      Authorization: `Bearer ${process.env.GITHUB_TOKEN}`,
      "User-Agent": "ran-wp-branch-updater-exact-publisher",
      "X-GitHub-Api-Version": options.apiVersion ?? API_VERSION,
    },
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
    redirect: "error",
  });

  if (options.allow404 && response.status === 404) {
    return { data: null, headers: response.headers };
  }
  if (!response.ok) {
    refuse("github_api_failed", `${options.method ?? "GET"} ${path} returned ${response.status}`);
  }

  return {
    data: response.status === 204 ? null : await response.json(),
    headers: response.headers,
  };
}

export async function associatedPulls(repository, sha) {
  const pulls = [];
  for (let page = 1; page <= 10; page += 1) {
    const response = await api(`/repos/${repository}/commits/${sha}/pulls?per_page=100&page=${page}`);
    if (!Array.isArray(response.data)) {
      refuse("pull_readback_invalid", "commit pull request response is not a list");
    }
    pulls.push(...response.data);
    if (!/<[^>]+>;\s*rel="next"/.test(response.headers.get("link") ?? "")) {
      return pulls;
    }
  }
  refuse("pull_readback_unbounded", "commit pull request response exceeded ten pages");
}

export async function remoteState(repository, tag) {
  const encoded = encodeURIComponent(tag);
  const [tagRef, release] = await Promise.all([
    api(`/repos/${repository}/git/ref/tags/${encoded}`, { allow404: true }),
    api(`/repos/${repository}/releases/tags/${encoded}`, {
      allow404: true,
      apiVersion: IMMUTABLE_RELEASES_API_VERSION,
    }),
  ]);
  return { tagRef: tagRef.data, release: release.data };
}

export async function createImmutableRelease(repository, identity) {
  return api(`/repos/${repository}/releases`, {
    method: "POST",
    apiVersion: IMMUTABLE_RELEASES_API_VERSION,
    body: {
      tag_name: identity.tag,
      target_commitish: identity.candidateSha,
      name: identity.tag,
      body: identity.notes,
      draft: false,
      prerelease: true,
      generate_release_notes: false,
    },
  });
}
