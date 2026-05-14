#!/usr/bin/env node
const fs = require('node:fs');

const workflowFiles = [
  '.github/workflows/symbol-platform-test.yml',
];

const fullCommitSha = /^[0-9a-f]{40}$/;
const floatingRunnerLabel = /\b[a-z]+-latest\b/;
let failed = false;

function fail(message) {
  console.error(message);
  failed = true;
}

for (const file of workflowFiles) {
  const lines = fs.readFileSync(file, 'utf8').split(/\r?\n/);

  lines.forEach((line, index) => {
    const runnerMatch = line.match(/^\s*runs-on:\s*([^\s#]+)/);
    if (runnerMatch && floatingRunnerLabel.test(runnerMatch[1])) {
      fail(`${file}:${index + 1}: GitHub Actions runner must not use a floating latest label: ${runnerMatch[1]}`);
    }

    const match = line.match(/^\s*uses:\s*([^@\s]+\/[^@\s]+)@([^\s#]+)/);
    if (!match) {
      return;
    }

    const action = match[1];
    const ref = match[2];

    if (!fullCommitSha.test(ref)) {
      fail(`${file}:${index + 1}: GitHub Action must be pinned to a full commit SHA: ${action}@${ref}`);
    }
  });
}

if (failed) {
  process.exit(1);
}

console.log('GitHub Actions policy passed.');
