'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const failures = [];

function walk(directory) {
  const files = [];
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    if (entry.name === '.git') {
      continue;
    }
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      files.push(...walk(absolute));
    } else if (entry.isFile() && entry.name.toLowerCase().endsWith('.md')) {
      files.push(absolute);
    }
  }
  return files;
}

function relativeTarget(raw) {
  let target = raw.trim();
  if (target.startsWith('<') && target.endsWith('>')) {
    target = target.slice(1, -1).trim();
  } else {
    target = target.split(/\s+["']/u, 1)[0];
  }
  if (target === ''
      || target.startsWith('#')
      || target.startsWith('/')
      || target.startsWith('{')
      || /^[a-z][a-z0-9+.-]*:/iu.test(target)) {
    return '';
  }
  target = target.split('#', 1)[0].split('?', 1)[0];
  try {
    return decodeURIComponent(target);
  } catch {
    return target;
  }
}

for (const file of walk(root)) {
  const relativeFile = path.relative(root, file).split(path.sep).join('/');
  const markdown = fs.readFileSync(file, 'utf8').replace(/```[\s\S]*?```/gu, '');
  const patterns = [
    /!?\[[^\]]*\]\(([^)]+)\)/gu,
    /^\s*\[[^\]]+\]:\s*(\S+)/gmu,
  ];
  for (const pattern of patterns) {
    for (const match of markdown.matchAll(pattern)) {
      const target = relativeTarget(match[1]);
      if (target === '') {
        continue;
      }
      const resolved = path.resolve(path.dirname(file), target);
      if (!resolved.startsWith(root + path.sep) || !fs.existsSync(resolved)) {
        failures.push(`${relativeFile}: ${match[1]}`);
      }
    }
  }
}

if (failures.length > 0) {
  console.error('Markdown relative-link validation failed:');
  for (const failure of failures) {
    console.error(`- ${failure}`);
  }
  process.exit(1);
}

console.log('Markdown relative-link validation passed.');
