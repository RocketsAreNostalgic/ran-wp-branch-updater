import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workflow = readFileSync(
	new URL('../.github/workflows/release-please.yml', import.meta.url),
	'utf8'
);
const ciWorkflow = readFileSync(
	new URL('../.github/workflows/ci.yml', import.meta.url),
	'utf8'
);
const releaseConfig = JSON.parse(
	readFileSync(new URL('../release-please-config.json', import.meta.url), 'utf8')
);

test('release workflow is a thin pinned Profile A caller', () => {
	assert.match(workflow, /workflow_run:/);
	assert.match(workflow, /workflows: \[CI\]/);
	assert.match(workflow, /permissions: \{\}/);
	assert.match(
		workflow,
		/^\s{4}uses: RocketsAreNostalgic\/\.github\/\.github\/workflows\/release-profile-a\.yml@289352e08cdf10b15d07c4e1c890f385afc3d3f5$/m
	);
	assert.match(workflow, /expected-workflow-path: \.github\/workflows\/ci\.yml/);
	assert.match(
		workflow,
		/release-pr-head: release-please--branches--main--components--ran\/wp-branch-updater/
	);
	assert.match(workflow, /actions: write/);
	assert.doesNotMatch(workflow, /release-publisher/);
	assert.doesNotMatch(workflow, /release-please-action/);
	assert.doesNotMatch(workflow, /RAN_RELEASE_PUBLISHER/);
	assert.equal(releaseConfig.packages['.']['skip-github-release'], undefined);
});

test('canonical CI authenticates Profile A candidate dispatch before classification', () => {
	assert.match(ciWorkflow, /^\s*workflow_dispatch:/m);
	assert.match(ciWorkflow, /pull-requests: read/);
	assert.match(ciWorkflow, /Resolve exact Release Please PR for dispatch/);
	assert.match(
		ciWorkflow,
		/expected_head='release-please--branches--main--components--ran\/wp-branch-updater'/
	);
	assert.match(ciWorkflow, /\.user\.login == \$bot/);
	assert.match(ciWorkflow, /\.head\.sha == \$sha/);
	assert.match(
		ciWorkflow,
		/Expected exactly one canonical Release Please pull request for dispatch/
	);
	assert.match(
		ciWorkflow,
		/github\.event_name == 'pull_request' \|\| github\.event_name == 'workflow_dispatch'/
	);
	assert.match(ciWorkflow, /steps\.dispatch-pr\.outputs\.base_sha/);
	assert.match(ciWorkflow, /steps\.dispatch-pr\.outputs\.head_sha/);
	assert.match(ciWorkflow, /steps\.dispatch-pr\.outputs\.title/);
	assert.match(ciWorkflow, /run: node scripts\/release-classification\.mjs/);
});

test('package-specific dependency classification remains in terminal quality topology', () => {
	assert.match(
		ciWorkflow,
		/name: Require release-driving squash title for production dependency changes/
	);
	assert.match(
		ciWorkflow,
		/quality:\n\s+if: \$\{\{ always\(\) \}\}[\s\S]*needs:[\s\S]*- consumer-install/
	);
});
