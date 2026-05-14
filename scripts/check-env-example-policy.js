#!/usr/bin/env node
const fs = require('node:fs');

const envExamplePath = '.env.example';
const composeFiles = [
  'docker-compose.yml',
  'docker-compose.production-smoke.yml',
].filter((file) => fs.existsSync(file));

const envExample = fs.readFileSync(envExamplePath, 'utf8');
const documentedVariables = new Set();

for (const line of envExample.split(/\r?\n/)) {
  const match = line.match(/^([A-Z0-9_]+)=/);
  if (match) {
    documentedVariables.add(match[1]);
  }
}

const referencedVariables = new Set();
const variableReferencePattern = /\$\{([A-Z0-9_]+)(?::-[^}]*)?\}/g;

for (const composeFile of composeFiles) {
  const content = fs.readFileSync(composeFile, 'utf8');
  for (const match of content.matchAll(variableReferencePattern)) {
    referencedVariables.add(match[1]);
  }
}

const missingVariables = [...referencedVariables]
  .filter((variable) => !documentedVariables.has(variable))
  .sort();

if (missingVariables.length > 0) {
  console.error(`${envExamplePath} is missing Docker Compose variables:`);
  for (const variable of missingVariables) {
    console.error(`- ${variable}`);
  }
  process.exit(1);
}

console.log('Environment example policy passed.');
