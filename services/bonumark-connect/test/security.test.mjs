import test from 'node:test';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { address, endpoint, keyring, pkce, publicIP, safeLog, scopes, secret, uuid } from '../src/security.mjs';
import { Transport } from '../src/transport.mjs';
import { Relay, verifyCSRF, csrf } from '../src/relay.mjs';

test('canonical origin and IDNA retain a separate case-sensitive base path', () => {
  assert.deepEqual(address('https://EXAMPLE.com.:443/Blog/'), { canonical_origin: 'https://example.com', base_path: '/Blog' });
  assert.equal(address('https://bücher.example').canonical_origin, 'https://xn--bcher-kva.example');
});
for (const input of ['http://example.com', 'https://user:password@example.com', 'https://example.com#x', 'https://example.com?x', 'https://example.com/a/../b', 'https://example.com/%2f', 'https://example.com/%252e', 'https://example.com/a\\b', 'https://example.com//b', 'https://example.com:444', 'https://127.0.0.1', 'https://2130706433', 'https://[::1]', 'https://localhost', 'https://site.local', 'https://example.com..', ' https://example.com']) {
  test(`reject ambiguous URL ${input}`, () => assert.throws(() => address(input)));
}
for (const ip of ['0.0.0.0', '10.0.0.1', '100.64.0.1', '127.0.0.1', '169.254.169.254', '172.16.1.1', '192.168.0.1', '192.0.0.8', '192.0.2.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255', '::', '::1', '::ffff:127.0.0.1', 'fc00::1', 'fe80::1', 'ff02::1', '2001:db8::1', '2001::1', '2002::1', '3fff::1']) {
  test(`reject unsafe address ${ip}`, () => assert.equal(publicIP(ip), false));
}
test('ordinary public IPv4 and IPv6 are accepted', () => { assert.ok(publicIP('8.8.8.8')); assert.ok(publicIP('2606:4700:4700::1111')); });
test('endpoint boundary cannot confuse a path prefix', () => {
  const site = address('https://example.com/blog');
  assert.equal(endpoint('https://example.com/blog/api/status.php', site), 'https://example.com/blog/api/status.php');
  for (const url of ['https://example.com/blogger/status', 'https://elsewhere.com/blog/status', 'https://example.com/status']) assert.throws(() => endpoint(url, site));
});
test('scope validation preserves empty grants and rejects publish, future and unknown scopes', () => {
  assert.deepEqual(scopes([]), []);
  for (const s of ['stream:publish', 'media:read', 'unknown']) assert.throws(() => scopes([s]));
});
test('S256 known standard vector and CSRF proof', () => {
  assert.equal(pkce('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'), 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
  const cookie = secret(); verifyCSRF(cookie, csrf(cookie)); assert.throws(() => verifyCSRF(cookie, secret()));
});
test('authenticated encryption rejects wrong keys, context, ciphertext and tag; rotates key versions', () => {
  const keys = { first: secret(), second: secret() }; const first = keyring(keys, 'first'); const second = keyring(keys, 'second');
  const credential = `bmsc_${secret()}`; const sealed = first.seal(credential, 'account:site:connection');
  assert.ok(!sealed.includes(credential)); assert.ok(second.open(sealed, 'account:site:connection') === credential);
  assert.throws(() => keyring({ first: secret() }, 'first').open(sealed, 'account:site:connection'));
  assert.throws(() => first.open(sealed, 'other-account'));
  for (const key of ['c', 't', 'n']) { const edited = JSON.parse(sealed); const bytes = Buffer.from(edited[key], 'base64'); bytes[0] ^= 1; edited[key] = bytes.toString('base64'); assert.throws(() => first.open(JSON.stringify(edited), 'account:site:connection')); }
  const rotated = second.seal(second.open(sealed, 'account:site:connection'), 'account:site:connection');
  assert.equal(JSON.parse(rotated).v, 'second'); assert.ok(second.open(rotated, 'account:site:connection') === credential);
});
test('logs project only safe fixed fields', () => {
  const sensitive = `bmsc_${secret()}`; let output = ''; safeLog(x => output += x, sensitive, sensitive, 500);
  assert.ok(!output.includes(sensitive)); assert.deepEqual(Object.keys(JSON.parse(output)), ['time', 'event', 'request_id', 'status']);
});

const site = { site_id: uuid(), canonical_origin: 'https://site.example.com', base_path: '/blog' };
const discovery = () => ({ ok: true, ...site, protocol_versions: [1], supported_scopes: ['status:read'], endpoints: Object.fromEntries(['authorize', 'token', 'status', 'revoke'].map(k => [k, `${site.canonical_origin}${site.base_path}/${k}.php`])) });
const jsonResponse = data => ({ status: 200, headers: { 'content-type': 'application/json' }, body: JSON.stringify(data) });
test('discovery validates every A/AAAA answer and passes the pinned addresses to connection', async () => {
  let lookup = 0; let called = 0;
  const transport = new Transport({ resolve: async () => { lookup++; return lookup === 1 ? ['8.8.8.8', '2606:4700::1111'] : ['127.0.0.1']; }, request: async (_url, ips) => { called++; assert.equal(ips[0], '8.8.8.8'); return jsonResponse(discovery()); } });
  assert.equal((await transport.discover('https://site.example.com/blog')).site_id, site.site_id);
  await assert.rejects(transport.discover('https://site.example.com/blog'), { code: 'unsafe_destination' }); assert.equal(called, 1);
  const mixed = new Transport({ resolve: async () => ['8.8.8.8', '::1'], request: () => { throw new Error('must never connect'); } });
  await assert.rejects(mixed.discover('https://site.example.com'), { code: 'unsafe_destination' });
});
test('redirect limit and redirect destination protection', async () => {
  let calls = 0;
  const t = new Transport({ resolve: async host => host === 'private.example.com' ? ['10.0.0.1'] : ['8.8.8.8'], request: async () => { calls++; return { status: 302, headers: { location: 'https://site.example.com/blog/api/connect/v1/discovery.php' }, body: '' }; } });
  await assert.rejects(t.discover('https://site.example.com/blog'), { code: 'redirect_limit' }); assert.equal(calls, 4);
  t.request = async () => ({ status: 302, headers: { location: 'https://private.example.com/api/connect/v1/discovery.php' }, body: '' });
  await assert.rejects(t.discover('https://site.example.com'), { code: 'unsafe_destination' });
});
test('site-reported address mismatch fails closed; code and bearer requests never redirect', async () => {
  const t = new Transport({ resolve: async () => ['8.8.8.8'], request: async () => jsonResponse({ ...discovery(), canonical_origin: 'https://wrong.example.com' }) });
  await assert.rejects(t.discover('https://site.example.com/blog'), { code: 'site_origin_changed' });
  t.request = async () => ({ status: 302, headers: { location: 'https://other.example.com' }, body: '' });
  for (const options of [{ method: 'POST', body: '{}' }, { headers: { Authorization: `Bearer bmsc_${secret()}` } }]) await assert.rejects(t.json('https://site.example.com/token.php', options), { code: 'site_origin_changed' });
});
test('non-JSON proxy bodies and arbitrary upstream messages never escape', async () => {
  const sensitive = secret(); const t = new Transport({ resolve: async () => ['8.8.8.8'], request: async () => ({ status: 500, headers: {}, body: sensitive }) });
  await assert.rejects(t.json('https://site.example.com/status'), e => e.code === 'target_site_response_invalid' && !e.message.includes(sensitive));
});
test('concurrency is bounded without an unbounded queue', async () => {
  let release; const wait = new Promise(r => release = r);
  const t = new Transport({ maxConcurrent: 1, resolve: async () => ['8.8.8.8'], request: async () => { await wait; return jsonResponse(discovery()); } });
  const first = t.discover('https://site.example.com/blog');
  await assert.rejects(t.discover('https://site.example.com/blog'), { code: 'rate_limited' }); release(); await first;
});

test('returned grants cannot change binding, broaden scopes or extend the credential lifetime', () => {
  const relay = new Relay(null, null, null, {});
  const row = { site_id: uuid(), canonical_origin: 'https://site.example.com', base_path: '/blog', client_id: 'bmc_test_client_0123456789', grant_id: uuid(), scopes: JSON.stringify(['status:read']) };
  const data = { ...row, scopes: ['status:read'], expires_at: Math.floor(Date.now() / 1000) + 60 };
  relay.validateGrant(data, row);
  for (const changed of [{ site_id: uuid() }, { canonical_origin: 'https://other.example.com' }, { base_path: '/blogger' }, { client_id: 'other_client' }, { grant_id: uuid() }, { scopes: ['status:read', 'stream:read'] }, { expires_at: 0 }, { expires_at: data.expires_at + 7776000 }]) {
    assert.throws(() => relay.validateGrant({ ...data, ...changed }, row));
  }
});
