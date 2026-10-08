import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { once } from 'node:events';
import { readFile } from 'node:fs/promises';
import { createServer } from '../src/server.mjs';
import { csrf } from '../src/relay.mjs';
import { secret, SafeError } from '../src/security.mjs';

const baseCSP = "default-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";
test('presentation asset allowlist and response policy boundaries', async () => {
  const token = secret(); let rows = []; let failList = false; let authentications = 0;
  const relay = {
    config: { baseUrl: 'https://relay.example.com', maxConcurrent: 16 },
    db: { limit: async () => {}, query: async () => [] },
    authenticate: async value => { authentications++; if (value !== token) throw new SafeError('authentication_required', 401); return {}; },
    list: async () => { if (failList) throw new Error('private'); return rows; },
    callback: async () => ({ denied: true }),
    start: async () => ({ site: 'https://site.example.com', authorization_url: 'https://site.example.com/approve?state=fixture&code_challenge=fixture' })
  };
  const server = createServer(relay, { log: () => {} });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  const request = (path, { method = 'GET', auth = false, host = 'relay.example.com' } = {}) => new Promise((resolve, reject) => {
    const req = http.request({ host: '127.0.0.1', port: server.address().port, path, method, headers: {
      Host: host, Origin: relay.config.baseUrl, 'Content-Type': 'application/x-www-form-urlencoded',
      ...(auth ? { Cookie: `__Host-bmc_session=${token}` } : {})
    } }, res => { let text = ''; res.on('data', part => text += part); res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, text })); });
    req.on('error', reject); req.end(method === 'POST' ? new URLSearchParams({ csrf: csrf(token), site: 'https://site.example.com' }).toString() : undefined);
  });
  const styled = res => {
    assert.equal(res.status, 200);
    assert.equal(res.headers['content-type'], 'text/html; charset=utf-8');
    assert.equal(res.headers['content-security-policy'], baseCSP + "; style-src 'self'");
    assert.equal(res.headers['cache-control'], 'no-store');
    assert.equal((res.text.match(/<link /g) ?? []).length, 1);
    assert.ok(res.text.includes('rel="stylesheet" href="/assets/connect.css"'));
    assert.doesNotMatch(res.text, /<script\b|<style\b|<[^>]+\sstyle=|<[^>]+\son\w+=/i);
  };
  try {
    const css = await readFile(new URL('../assets/connect.css', import.meta.url), 'utf8');
    const res = await request('/assets/connect.css');
    assert.equal(res.status, 200); assert.equal(res.text, css); assert.equal(authentications, 0);
    assert.equal(res.headers['content-type'], 'text/css; charset=utf-8');
    assert.equal(res.headers['cache-control'], 'no-cache');
    assert.equal(res.headers['content-security-policy'], baseCSP);
    assert.equal(res.headers['referrer-policy'], 'no-referrer');
    assert.equal(res.headers['x-content-type-options'], 'nosniff');
    assert.equal(res.headers['set-cookie'], undefined);
    assert.doesNotMatch(css, /url\s*\(|@import|https?:|data:|bmca_|bmsc_|__Host-/i);
    for (const path of ['/assets/', '/assets/server.mjs', '/src/server.mjs', '/package.json', '/config.example.json', '/node_modules/mysql2/package.json', '/assets/../assets/connect.css', '/assets/%2e%2e/assets/connect.css', '/assets/%63onnect.css', '/assets/connect.css/extra', '/assets/connect.css?secret=fixture', '/assets/../../config.json', '/assets/%2fconnect.css']) {
      const denied = await request(path, { auth: true });
      assert.equal(denied.status, 404, path); assert.equal(denied.headers['content-security-policy'], baseCSP);
      assert.notEqual(denied.text, css);
    }
    for (const method of ['HEAD', 'POST']) assert.notEqual((await request('/assets/connect.css', { method, auth: true })).status, 200);
    assert.equal((await request('/assets/connect.css', { host: 'foreign.example' })).status, 400);
    styled(await request('/login'));
    const empty = await request('/', { auth: true }); styled(empty); assert.ok(empty.text.includes('No sites connected yet'));
    const approval = await request('/connections/start', { method: 'POST', auth: true }); styled(approval);
    assert.equal(approval.headers['referrer-policy'], 'no-referrer');
    assert.ok(approval.text.includes('href="https://site.example.com/approve?state=fixture&amp;code_challenge=fixture" rel="noreferrer"'));
    rows = [{ connection_id: '00000000-0000-4000-8000-000000000001', canonical_origin: 'https://site.example.com', base_path: '/<script>fixture</script>', state: 'disconnect_pending', scopes: ['status:read', '<img src=x onerror=fixture>'] }];
    const warning = await request('/?notice=review', { auth: true }); styled(warning);
    assert.ok(warning.text.includes('Owner review required.')); assert.ok(warning.text.includes('Disconnect pending'));
    assert.ok(warning.text.includes('class="notice tone-warning" role="status"'));
    assert.ok(warning.text.includes('&lt;script&gt;fixture&lt;/script&gt;')); assert.ok(warning.text.includes('&lt;img'));
    assert.ok(!warning.text.includes(rows[0].connection_id), 'No unnecessary UUID when no action exists');
    rows[0].state = 'active'; rows[0].scopes = [];
    const success = await request('/?notice=healthy', { auth: true }); styled(success);
    assert.ok(success.text.includes('class="notice tone-success" role="status"')); assert.ok(success.text.includes('No permissions granted.'));
    for (const result of [await request('/healthz'), await request('/callback', { auth: true }), await request('/callback'), await request('/missing', { auth: true }), await request('/')]) {
      assert.equal(result.headers['content-security-policy'], baseCSP);
      assert.equal(result.headers['cache-control'], 'no-store');
    }
    failList = true;
    const error = await request('/', { auth: true }); assert.equal(error.status, 500); assert.equal(error.headers['content-security-policy'], baseCSP);
  } finally { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});
