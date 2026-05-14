#!/usr/bin/env node
const fs = require('node:fs');

const packageFiles = [
  'symbol-engine/package.json',
];

const forbiddenSpecs = new Set(['latest', '*']);
let failed = false;

for (const packageFile of packageFiles) {
  const manifest = JSON.parse(fs.readFileSync(packageFile, 'utf8'));
  const dependencySections = [
    'dependencies',
    'devDependencies',
    'optionalDependencies',
    'peerDependencies',
  ];

  for (const section of dependencySections) {
    const dependencies = manifest[section] ?? {};
    for (const [dependency, spec] of Object.entries(dependencies)) {
      if (forbiddenSpecs.has(spec)) {
        console.error(`${packageFile}: ${section}.${dependency} must not use "${spec}"`);
        failed = true;
      }
    }
  }
}

if (failed) {
  process.exit(1);
}

console.log('Node dependency policy passed.');
