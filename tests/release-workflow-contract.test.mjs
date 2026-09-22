import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workflow = readFileSync(
	new URL('../.github/workflows/release-please.yml', import.meta.url),
	'utf8'
);
const releaseConfig = JSON.parse(
	readFileSync(new URL('../release-please-config.json', import.meta.url), 'utf8')
);
const classificationWorkflow = readFileSync(
	new URL('../.github/workflows/release-classification.yml', import.meta.url),
	'utf8'
);

test('release workflow is a thin pinned Profile A caller', () => {
	assert.match(workflow, /workflow_run:/);
	assert.match(workflow, /workflows: \[CI\]/);
	assert.match(workflow, /permissions: \{\}/);
	assert.match(
		workflow,
		/uses: RocketsAreNostalgic\/\.github\/\.github\/workflows\/release-profile-a\.yml@289352e08cdf10b15d07c4e1c890f385afc3d3f5/
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

test('trusted release classification workflow stays on protected base', () => {
	assert.match(classificationWorkflow, /^\s*pull_request_target:/m);
	assert.match(
		classificationWorkflow,
		/pull_request_target:\n\s+branches: \[main\]/
	);
	assert.match(classificationWorkflow, /test "\$base_ref" = main/);
	assert.match(
		classificationWorkflow,
		/test "\$base_repo" = "\$GITHUB_REPOSITORY"/
	);
	assert.match(
		classificationWorkflow,
		/ref: \$\{\{ steps\.pr\.outputs\.base_sha \}\}/
	);
	assert.match(
		classificationWorkflow,
		/git fetch --no-tags origin "\+refs\/pull\/\$\{RAN_PR_NUMBER\}\/head:refs\/remotes\/origin\/pr-head"/
	);
	assert.match(
		classificationWorkflow,
		/test "\$\(git rev-parse refs\/remotes\/origin\/pr-head\)" = "\$RAN_HEAD_SHA"/
	);
	assert.match(
		classificationWorkflow,
		/run: node scripts\/trusted-release-classification\.mjs/
	);
	assert.doesNotMatch(
		classificationWorkflow,
		/ref: \$\{\{ github\.event\.pull_request\.head\.sha \}\}/
	);
});
