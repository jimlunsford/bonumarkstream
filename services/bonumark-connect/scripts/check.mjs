import { readdir, readFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
for (const directory of ['src', 'scripts', 'test']) {
  for (const file of await readdir(directory)) {
    if (file.endsWith('.mjs') && spawnSync(process.execPath, ['--check', `${directory}/${file}`], { stdio: 'inherit' }).status !== 0) process.exit(1);
  }
}
const manifest = JSON.parse(await readFile('package.json', 'utf8'));
const lock = JSON.parse(await readFile('package-lock.json', 'utf8'));
if (JSON.stringify(manifest.dependencies) !== JSON.stringify(lock.packages[''].dependencies) || JSON.stringify(manifest.devDependencies) !== JSON.stringify(lock.packages[''].devDependencies)) throw new Error('Dependency lock mismatch');
for (const [path, entry] of Object.entries(lock.packages)) {
  if (path && (!entry.integrity?.startsWith('sha512-') || !entry.resolved?.startsWith('https://registry.npmjs.org/'))) throw new Error('Dependency integrity missing');
}
process.stdout.write('Relay source and dependency integrity checks passed.\n');
