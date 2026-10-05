import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { Transport } from '../src/transport.mjs';

test('real pinned TLS socket verifies hostname and bounds response and time', async () => {
  const dir = await mkdtemp(path.join(tmpdir(), 'bmc-tls-'));
  try {
    const cert = spawnSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', dir + '/key.pem', '-out', dir + '/cert.pem', '-days', '1', '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost'], { stdio: 'ignore' });
    assert.equal(cert.status, 0);
    const child = spawnSync(process.execPath, ['test/tls-child.mjs', dir], { env: { ...process.env, NODE_EXTRA_CA_CERTS: dir + '/cert.pem' }, encoding: 'utf8', timeout: 10000 });
    assert.ok(child.status === 0, 'Trusted fixture TLS/hostname/size/deadline checks failed');
    assert.ok(child.stdout.includes('passed'));
  } finally { await rm(dir, { recursive: true, force: true }); }
});
test('unresponsive DNS has a bounded deadline', async () => {
  const transport = new Transport({ resolve: () => new Promise(() => {}), request: () => { throw new Error('must not connect'); } });
  await assert.rejects(transport.discover('https://site.example.com'), { code: 'target_site_timeout' });
  assert.equal(transport.inFlight, 0);
});
