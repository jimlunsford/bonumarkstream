import test from 'node:test';
import assert from 'node:assert/strict';
import { once } from 'node:events';
import { cp, mkdtemp, rm, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn, spawnSync } from 'node:child_process';
import http from 'node:http';
import { Database } from '../src/database.mjs';
import { Transport } from '../src/transport.mjs';
import { Relay, csrf } from '../src/relay.mjs';
import { browserChecks } from './browser.mjs';
import { createServer } from '../src/server.mjs';
import { keyring, secret, uuid, digest, SafeError } from '../src/security.mjs';

test('real database and PHP authorization end-to-end', { timeout: 90000 }, async t => {
  assert.equal(process.env.BMS_DB_DANGER_RESET, '1', 'Disposable database opt-in required');
  const db = new Database({ host: '127.0.0.1', port: Number(process.env.BMC_TEST_DB_PORT ?? 3306), database: 'bmc_test', user: process.env.BMC_TEST_DB_USER ?? 'root', password: process.env.BMC_TEST_DB_PASSWORD ?? '' });
  const tables = ['connections', 'account_sessions', 'site_bindings', 'rate_limits', 'accounts'];
  const cleanDB = async () => { for (const table of tables) await db.query(`DROP TABLE IF EXISTS ${table}`); };
  const root = await mkdtemp(path.join(tmpdir(), 'bmc-e2e-'));
  const source = fileURLToPath(new URL('../../../', import.meta.url));
  const driver = fileURLToPath(new URL('./site-fixture.php', import.meta.url));
  let php; let server; let siteReady = false; let logs = '';
  const driverCall = (action, input = {}) => {
    const r = spawnSync(process.env.PHP_BINARY ?? 'php', [driver, root, action], { input: JSON.stringify(input), encoding: 'utf8' });
    assert.ok(r.status === 0 && r.stdout.trim() === 'ok', `PHP fixture ${action} failed`);
  };
  try {
    await cleanDB(); await db.migrate(); await db.migrate();
    await cp(source, root, { recursive: true, filter: p => !path.relative(source, p).split(path.sep).some(part => ['.git', 'services', 'config.php', 'installed.lock', 'tmp', 'backups'].includes(part)) });
    const password = `Fixture-${secret()}`; driverCall('setup', { password }); siteReady = true;
    const port = 41000 + Math.floor(Math.random() * 5000);
    php = spawn(process.env.PHP_BINARY ?? 'php', ['-S', `127.0.0.1:${port}`, '-t', root], { stdio: ['ignore', 'ignore', 'ignore'] });
    const siteBase = `http://127.0.0.1:${port}`;
    for (let i = 0; i < 60; i++) {
      try { if ((await fetch(siteBase + '/admin/login.php')).status === 200) break; } catch { /* Bounded startup wait. */ }
      await new Promise(resolve => setTimeout(resolve, 50));
    }
    let siteCookie = '';
    const siteFetch = async (p, options = {}) => {
      const r = await fetch(siteBase + p, { ...options, redirect: 'manual', headers: { Cookie: siteCookie, ...options.headers } });
      const cookie = r.headers.get('set-cookie'); if (cookie) siteCookie = cookie.split(';')[0];
      return { status: r.status, headers: r.headers, text: await r.text() };
    };
    const form = input => new URLSearchParams(input).toString();
    const htmlToken = text => /name="csrf_token" value="([a-f0-9]+)"/.exec(text)?.[1];
    const login = await siteFetch('/admin/login.php');
    const authenticated = await siteFetch('/admin/login.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form({ csrf_token: htmlToken(login.text), username: 'connectowner', password }) });
    assert.equal(authenticated.status, 302);
    // The production resolver/pinned TLS transport is tested separately. This
    // explicit test-only adapter connects the real site HTTP fixture locally.
    const transport = new Transport({ resolve: async () => ['8.8.8.8'], request: async (url, _ips, options) => {
      const u = new URL(url);
      assert.equal(u.origin, 'https://site.example.com');
      const r = await fetch(siteBase + u.pathname, { method: options.method ?? 'GET', headers: options.headers, body: options.body, redirect: 'manual' });
      return { status: r.status, headers: Object.fromEntries(r.headers), body: await r.text() };
    } });
    const keys = { first: secret(), second: secret() };
    const config = { baseUrl: 'https://relay.example.com', callbackUrl: 'https://relay.example.com/callback', clientId: 'bmc_test_client_0123456789', accountPerMinute: 120, discoveryPerMinute: 20, outboundPerMinute: 60, maxConcurrent: 16, buildId: 'development' };
    const relay = new Relay(db, transport, keyring(keys, 'first'), config);
    const account = await relay.provisionAccount(); const otherAccount = await relay.provisionAccount();
    const session = await relay.login(account.token); const other = await relay.login(otherAccount.token);
    server = createServer(relay, { log: line => logs += line }); server.listen(0, '127.0.0.1'); await once(server, 'listening');
    const relayBase = `http://127.0.0.1:${server.address().port}`;
    const relayFetch = async (p, options = {}, token = session.token) => {
      return new Promise((resolve, reject) => {
        const request = http.request(relayBase + p, { method: options.method ?? 'GET', headers: { Host: 'relay.example.com', Cookie: `__Host-bmc_session=${token}`, ...options.headers } }, response => {
          let text = ''; response.on('data', part => text += part); response.on('end', () => {
            let data; try { data = JSON.parse(text); } catch { /* Browser HTML. */ }
            resolve({ status: response.statusCode, headers: new Headers(response.headers), text, data });
          });
        });
        request.on('error', reject); request.end(options.body);
      });
    };
    const relayPost = (p, input = {}, token = session.token) => relayFetch(p, { method: 'POST', headers: { Origin: config.baseUrl, 'Content-Type': 'application/x-www-form-urlencoded' }, body: form({ csrf: csrf(token), ...input }) }, token);
    let browserChecked = false;
    async function startAndApprove() {
      const start = await relayPost('/connections/start', { site: 'https://site.example.com' });
      assert.equal(start.status, 200);
      const href = /href="(https:[^"]+)"/.exec(start.text)?.[1]?.replaceAll('&amp;', '&'); assert.ok(href);
      const u = new URL(href);
      const requested = await siteFetch(u.pathname + u.search); assert.equal(requested.status, 302);
      const target = new URL(requested.headers.get('location'), 'https://site.example.com');
      const approval = await siteFetch(target.pathname + target.search);
      assert.equal(approval.status, 200);
      if (process.env.BMC_BROWSER_TEST === '1' && !browserChecked) {
        await browserChecks(siteBase, siteCookie, target.pathname + target.search, 'pending'); browserChecked = true;
      }
      const requestId = /name="request" value="([a-f0-9-]+)"/.exec(approval.text)?.[1];
      const granted = await siteFetch('/admin/connect-authorize.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form({ csrf_token: htmlToken(approval.text), request: requestId, decision: 'approve', 'scopes[]': 'status:read', lifetime: '86400' }) });
      assert.equal(granted.status, 303); return new URL(granted.headers.get('location'));
    }
    let callback;
    await t.test('account authentication, CSRF and anonymous enrollment rejection', async () => {
      await assert.rejects(relay.login('incorrect'), { code: 'authentication_required' });
      await assert.rejects(relay.authenticate(secret()), { code: 'authentication_required' });
      const noOrigin = await relayFetch('/connections/start', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form({ csrf: csrf(session.token), site: 'https://site.example.com' }) });
      assert.equal(noOrigin.status, 403);
      assert.equal((await relayPost('/connections/start', { site: 'https://site.example.com', csrf: 'bad' })).status, 403);
      assert.equal((await relayFetch('/healthz', {}, '')).data.ready, true);
      callback = await startAndApprove();
    });
    let id;
    await t.test('callback cannot switch account or issuer; no connection before confirmation', async () => {
      const wrong = await relayFetch(callback.pathname + callback.search, {}, other.token); assert.equal(wrong.status, 400);
      const bad = new URL(callback); bad.searchParams.set('iss', 'https://other.example.com'); assert.equal((await relayFetch(bad.pathname + bad.search)).status, 400);
      const good = await relayFetch(callback.pathname + callback.search); assert.equal(good.status, 303);
      const [row] = await db.query('SELECT * FROM connections WHERE account_id = ?', [session.account_id]); id = row.connection_id;
      assert.equal(row.state, 'awaiting_confirmation'); assert.equal(row.verifier_ciphertext, null);
      assert.equal((await relayPost(`/connections/${id}/health`)).status, 409);
      assert.equal((await relayPost(`/connections/${id}/confirm`, {}, other.token)).status, 400);
      const fresh = await relay.login(account.token);
      assert.equal((await relayPost(`/connections/${id}/confirm`, {}, fresh.token)).status, 400);
      assert.equal((await relayFetch(callback.pathname + callback.search)).status, 400);
    });
    await t.test('confirmation, encrypted durable credential, rotation and real site health', async () => {
      assert.equal((await relayPost(`/connections/${id}/confirm`)).status, 200);
      assert.equal((await relayPost(`/connections/${id}/health`)).data.connected, true);
      if (process.env.BMC_BROWSER_TEST === '1') await browserChecks(siteBase, siteCookie, null, 'active');
      const [row] = await db.query('SELECT * FROM connections WHERE connection_id = ?', [id]);
      assert.ok(!JSON.stringify(row).includes('bmsc_')); assert.equal(JSON.parse(row.credential_ciphertext).v, 'first');
      relay.keys = keyring(keys, 'second'); await relay.rotateKeys();
      assert.equal(JSON.parse((await relay.get(session, id)).credential_ciphertext).v, 'second');
      assert.equal((await relayPost(`/connections/${id}/health`)).data.connected, true);
      assert.equal((await relayPost(`/connections/${id}/health`, { site: 'https://other.example.com' })).status, 400);
      assert.equal((await relayPost(`/connections/${id}/health`, {}, other.token)).status, 404);
    });
    await t.test('ciphertext tampering fails safely and never logs bearer, code, password or account secret', async () => {
      const row = await relay.get(session, id); const edited = JSON.parse(row.credential_ciphertext); edited.t = Buffer.alloc(16).toString('base64');
      await db.query('UPDATE connections SET credential_ciphertext = ? WHERE connection_id = ?', [JSON.stringify(edited), id]);
      const result = await relayPost(`/connections/${id}/health`); assert.equal(result.data.error.code, 'credential_unavailable');
      await db.query('UPDATE connections SET credential_ciphertext = ?, next_attempt_at = 0 WHERE connection_id = ?', [row.credential_ciphertext, id]);
      for (const value of [account.token, session.token, password, callback.searchParams.get('code'), callback.searchParams.get('state')]) assert.ok(!logs.includes(value), 'Secret absent from logs');
      assert.ok(!logs.includes('bmsc_')); assert.ok(!JSON.stringify(await relay.list(session)).includes('ciphertext'));
    });
    await t.test('stored endpoint tampering suspends the connection before forwarding a credential', async () => {
      const row = await relay.get(session, id); const endpoints = JSON.parse(row.endpoints);
      endpoints.status = 'https://other.example.com/status';
      await db.query('UPDATE connections SET endpoints = ? WHERE connection_id = ?', [JSON.stringify(endpoints), id]);
      const original = transport.json.bind(transport); let forwarded = false;
      transport.json = async () => { forwarded = true; throw new Error('must not dispatch'); };
      try {
        await assert.rejects(relay.health(session, id), { code: 'site_origin_changed' });
        assert.equal(forwarded, false); assert.equal((await relay.get(session, id)).state, 'suspended');
      } finally {
        transport.json = original;
        await db.query('UPDATE connections SET endpoints = ?, state = ?, next_attempt_at = 0 WHERE connection_id = ?', [row.endpoints, 'active', id]);
      }
    });
    await t.test('duplicate site identity at another address cannot disable or reroute the good connection', async () => {
      const actual = transport.discover.bind(transport);
      transport.discover = async () => ({ ...(await actual('https://site.example.com')), canonical_origin: 'https://clone.example.com' });
      await assert.rejects(relay.start(other, 'https://clone.example.com'), { code: 'site_identity_conflict' });
      assert.equal((await relay.get(session, id)).state, 'active'); transport.discover = actual;
    });
    await t.test('site owner revokes locally; unchanged credential fails immediately and relay detects it', async () => {
      const row = await relay.get(session, id);
      const manage = await siteFetch('/admin/connected-applications.php');
      const revoked = await siteFetch('/admin/connected-applications.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form({ csrf_token: htmlToken(manage.text), action: 'revoke', grant_id: row.grant_id }) });
      assert.equal(revoked.status, 302);
      const health = await relayPost(`/connections/${id}/health`); assert.equal(health.status, 401); assert.equal(health.data.error.code, 'invalid_bearer_token');
      assert.equal((await relay.get(session, id)).state, 'revoked_or_expired');
      assert.equal((await siteFetch('/index.php')).status, 200);
      assert.equal((await siteFetch('/admin/connected-applications.php')).status, 200);
    });
    await t.test('reconnect creates new authority; abandoned confirmation is never active and is revoked', async () => {
      const next = await startAndApprove(); assert.equal((await relayFetch(next.pathname + next.search)).status, 303);
      const [pending] = await db.query('SELECT * FROM connections WHERE account_id = ? AND state = ?', [session.account_id, 'awaiting_confirmation']);
      assert.notEqual(pending.connection_id, id); assert.notEqual(pending.grant_id, (await relay.get(session, id)).grant_id);
      await db.query('UPDATE connections SET expires_at = 0 WHERE connection_id = ?', [pending.connection_id]);
      assert.equal((await relayPost(`/connections/${pending.connection_id}/confirm`)).status, 400);
      await relay.maintenance(); assert.equal((await relay.get(session, pending.connection_id)).state, 'disconnected');
    });
    await t.test('multiple site connections under one account and durable atomic rate quotas', async () => {
      const original = transport.discover.bind(transport);
      transport.discover = async () => ({ ...(await original('https://site.example.com')), site_id: uuid(), canonical_origin: 'https://second.example.com', endpoints: Object.fromEntries(['authorize', 'token', 'status', 'revoke'].map(k => [k, `https://second.example.com/${k}.php`])) });
      const second = await relay.start(session, 'https://second.example.com'); assert.ok(second.connection_id);
      assert.ok((await relay.list(session)).length >= 3); transport.discover = original;
      const outcomes = await Promise.allSettled(Array.from({ length: 12 }, () => db.limit('test-quota', 3)));
      assert.equal(outcomes.filter(o => o.status === 'fulfilled').length, 3);
    });
    await t.test('origin-change redirect suspends routing without following it', async () => {
      const fake = (await db.query('SELECT * FROM connections WHERE state = ?', ['pending']))[0];
      // Exercise state transition on a previously active connection using its
      // exact stored encrypted credential and a controlled redirect response.
      await db.query('UPDATE connections SET state = ?, next_attempt_at = 0 WHERE connection_id = ?', ['active', id]);
      const original = transport.json.bind(transport); transport.json = async () => { throw new SafeError('site_origin_changed', 409); };
      await assert.rejects(relay.health(session, id), { code: 'site_origin_changed' });
      assert.equal((await relay.get(session, id)).state, 'suspended'); transport.json = original;
      assert.ok(fake.connection_id);
    });
    await t.test('denied and expired relay requests never gain authority; lost exchange is dispatched once', async () => {
      const inputFor = result => {
        const auth = new URL(result.authorization_url);
        return { state: auth.searchParams.get('state'), iss: 'https://site.example.com', site_id: auth.searchParams.get('site_id') };
      };
      const denied = await relay.start(other, 'https://site.example.com');
      assert.equal((await relay.callback(other, { ...inputFor(denied), error: 'access_denied' })).denied, true);
      assert.equal((await relay.get(other, denied.connection_id)).credential_ciphertext, null);
      const expired = await relay.start(other, 'https://site.example.com');
      await db.query('UPDATE connections SET expires_at = 0 WHERE connection_id = ?', [expired.connection_id]);
      await assert.rejects(relay.callback(other, { ...inputFor(expired), code: secret() }), { code: 'authorization_state_invalid' });
      await relay.maintenance();
      assert.equal((await relay.get(other, expired.connection_id)).state, 'abandoned');
      const lost = await relay.start(other, 'https://site.example.com');
      const original = transport.json.bind(transport); let dispatched = 0;
      transport.json = async () => { dispatched++; throw new SafeError('target_site_timeout', 504, 'relay', 'unknown'); };
      const input = { ...inputFor(lost), code: secret() };
      try {
        await assert.rejects(relay.callback(other, input), { code: 'target_site_timeout' });
        await assert.rejects(relay.callback(other, input), { code: 'authorization_state_invalid' });
        assert.equal(dispatched, 1); assert.equal((await relay.get(other, lost.connection_id)).state, 'failed');
      } finally { transport.json = original; }
    });
  } finally {
    if (server) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
    if (php) { php.kill(); await once(php, 'exit'); }
    if (siteReady) driverCall('cleanup');
    await cleanDB(); await db.close(); await rm(root, { recursive: true, force: true });
  }
});
