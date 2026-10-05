import { cp, mkdtemp, readdir, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const root = await mkdtemp(path.join(tmpdir(), 'bmc-package-'));
const output = path.resolve(process.env.BMC_PACKAGE_PATH ?? path.join(tmpdir(), 'bonumark-connect-source.tar.gz'));
const files = ['src', 'schema.sql', 'README.md', 'config.example.json', 'package.json', 'package-lock.json'];
const run = (command, args, cwd = root) => {
  const result = spawnSync(command, args, { cwd, stdio: 'inherit' });
  if (result.status !== 0) throw new Error('Clean package validation failed');
};
try {
  const stage = path.join(root, 'bonumark-connect');
  for (const file of files) await cp(file, path.join(stage, file), { recursive: true });
  // Validate the production-only install from the exact committed lockfile.
  run('npm', ['ci', '--omit=dev', '--ignore-scripts', '--no-audit', '--no-fund'], stage);
  run(process.execPath, ['--input-type=module', '-e', "await import('./src/relay.mjs'); await import('./src/transport.mjs'); await import('./src/server.mjs')"], stage);
  await rm(path.join(stage, 'node_modules'), { recursive: true });
  const names = (await readdir(stage)).sort();
  if (JSON.stringify(names) !== JSON.stringify([...files].sort())) throw new Error('Unexpected package content');
  for (const file of ['config.example.json', 'schema.sql', ...((await readdir('src')).map(f => `src/${f}`))]) {
    const text = await readFile(path.join(stage, file), 'utf8');
    if (/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|\bbmsc_[a-f0-9]{64}\b|\bbmca_[a-f0-9]{64}\b/.test(text)) throw new Error('Potential secret in package');
  }
  run('tar', ['--sort=name', '--mtime=@0', '--owner=0', '--group=0', '--numeric-owner', '-czf', output, 'bonumark-connect']);
  process.stdout.write('Relay clean install and isolated source package passed.\n');
} finally { await rm(root, { recursive: true, force: true }); }
