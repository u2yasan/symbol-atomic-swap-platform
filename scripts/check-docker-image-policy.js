#!/usr/bin/env node
const fs = require('node:fs');

const dockerfiles = [
  'symbol-engine/Dockerfile',
  'infrastructure/docker/drupal/Dockerfile',
];

const composeFiles = [
  'docker-compose.yml',
  'docker-compose.production-smoke.yml',
];

const commandFiles = [
  'Makefile',
  '.github/workflows/symbol-platform-test.yml',
];

let failed = false;

function fail(message) {
  console.error(message);
  failed = true;
}

function readLines(file) {
  return fs.readFileSync(file, 'utf8').split(/\r?\n/);
}

function hasDigest(imageRef) {
  return imageRef.includes('@sha256:');
}

function stripInlineComment(line) {
  const commentStart = line.indexOf('#');
  return commentStart === -1 ? line : line.slice(0, commentStart);
}

function checkDockerfile(file) {
  const stages = new Set();

  readLines(file).forEach((line, index) => {
    const match = line.match(/^\s*FROM\s+(.+)$/i);
    if (!match) {
      return;
    }

    const parts = stripInlineComment(match[1]).trim().split(/\s+/);
    while (parts[0]?.startsWith('--')) {
      parts.shift();
    }

    const imageRef = parts[0];
    if (!imageRef) {
      return;
    }

    if (!stages.has(imageRef) && !hasDigest(imageRef)) {
      fail(`${file}:${index + 1}: Dockerfile FROM image must be pinned by sha256 digest: ${imageRef}`);
    }

    const asIndex = parts.findIndex((part) => part.toUpperCase() === 'AS');
    if (asIndex !== -1 && parts[asIndex + 1]) {
      stages.add(parts[asIndex + 1]);
    }
  });
}

function checkComposeFile(file) {
  readLines(file).forEach((line, index) => {
    const match = line.match(/^\s*image:\s*["']?([^"'\s]+)["']?\s*$/);
    if (!match) {
      return;
    }

    const imageRef = match[1];
    if (!hasDigest(imageRef)) {
      fail(`${file}:${index + 1}: Compose image must be pinned by sha256 digest: ${imageRef}`);
    }
  });
}

function tokenizeShellish(text) {
  return text.match(/"[^"]*"|'[^']*'|\S+/g) ?? [];
}

function dockerRunImageRef(tokens, startIndex) {
  const optionsWithValue = new Set([
    '-e',
    '--env',
    '--env-file',
    '--name',
    '-u',
    '--user',
    '-v',
    '--volume',
    '-w',
    '--workdir',
    '--network',
    '-p',
    '--publish',
    '--entrypoint',
    '--platform',
  ]);

  for (let index = startIndex; index < tokens.length; index += 1) {
    const token = tokens[index];

    if (token === '--') {
      return tokens[index + 1];
    }

    if (token.startsWith('-')) {
      if (optionsWithValue.has(token)) {
        index += 1;
      }
      continue;
    }

    return token;
  }

  return undefined;
}

function checkDockerRunCommands(file) {
  readLines(file).forEach((line, index) => {
    const commandIndex = line.indexOf('docker run');
    if (commandIndex === -1) {
      return;
    }

    const tokens = tokenizeShellish(line.slice(commandIndex));
    const imageRef = dockerRunImageRef(tokens, 2);
    if (!imageRef) {
      return;
    }

    if (!hasDigest(imageRef)) {
      fail(`${file}:${index + 1}: docker run image must be pinned by sha256 digest: ${imageRef}`);
    }
  });
}

for (const file of dockerfiles) {
  checkDockerfile(file);
}

for (const file of composeFiles) {
  checkComposeFile(file);
}

for (const file of commandFiles) {
  checkDockerRunCommands(file);
}

if (failed) {
  process.exit(1);
}

console.log('Docker image policy passed.');
