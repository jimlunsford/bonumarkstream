import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { once } from 'node:events';
import { createServer } from '../src/server.mjs';
import { csrf } from '../src/relay.mjs';
import { secret, uuid, SafeError } from '../src/security.mjs';

test('HTTP login and mutation security boundaries', async t => {
  const origin = 'https://relay.example.com';
  const credential = `bmca_${secret()}`; const sessionToken = secret(); const id = uuid();
  let logs = ''; let authentications = 0; let mutations = 0; let limitLogin = false;
  const secrets = [credential, sessionToken, csrf(sessionToken)]; const limits = [];
  const relay = {
    config: { baseUrl: origin, maxConcurrent: 16, buildId: 'development' },
    db: { query: async () => [], limit: async (key, n) => { limits.push([key, n]); if (limitLogin && key.startsWith('login:')) throw new SafeError('rate_limited', 429); } },
    login: async value => { authentications++; if (value !== credential) throw new SafeError('authentication_required', 401); return { token: sessionToken }; },
    authenticate: async value => { if (value !== sessionToken) throw new SafeError('authentication_required', 401); return {}; },
    list: async () => [], callback: async () => ({ denied: true }),
    start: async () => { mutations++; return { site: 'https://site.example.com', authorization_url: 'https://site.example.com/approve' }; },
    confirm: async () => { mutations++; return {}; }, health: async () => { mutations++; return {}; }, disconnect: async () => { mutations++; return {}; }
  };
  const server = createServer(relay, { log: line => logs += line });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  const request = (path, { method = 'GET', headers = {}, input } = {}) => new Promise((resolve, reject) => {
    const req = http.request({ host: '127.0.0.1', port: server.address().port, path, method,
      headers: { Host: 'relay.example.com', ...(input ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}), ...headers } }, res => {
      let text = ''; res.on('data', part => text += part); res.on('end', () => {
        let data; try { data = JSON.parse(text); } catch { /* HTML. */ }
        resolve({ status: res.statusCode, headers: res.headers, text, data });
      });
    }); req.on('error', reject); req.end(input ? new URLSearchParams(input).toString() : undefined);
  });
  const loginPage = async () => {
    const res = await request('/login');
    const cookie = res.headers['set-cookie'][0].split(';')[0];
    const proof = /name="login_csrf" value="([a-f0-9]{64})"/.exec(res.text)?.[1];
    assert.ok(proof, 'Login contains a hidden proof'); secrets.push(cookie, cookie.slice(cookie.indexOf('=') + 1), proof);
    return { res, cookie, proof };
  };
  const rejected = res => { assert.equal(res.status, 403); assert.equal(res.data.error.code, 'csrf_invalid'); assert.equal(res.headers['referrer-policy'], 'no-referrer'); };
  try {
    await t.test('login page sets independent secure cookie, form proof and only the approved policy exception', async () => {
      const { res } = await loginPage();
      assert.equal(res.status, 200); assert.equal(res.headers['referrer-policy'], 'same-origin');
      assert.equal(res.headers['cache-control'], 'no-store'); assert.equal(res.headers['x-frame-options'], 'DENY');
      assert.equal(res.headers['x-content-type-options'], 'nosniff');
      assert.equal(res.headers['content-security-policy'], "default-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; style-src 'self'");
      assert.ok(res.headers['set-cookie'][0].includes('Secure; HttpOnly; SameSite=Strict; Path=/; Max-Age=600'));
      assert.ok(!res.headers['set-cookie'][0].includes('Domain='));
      assert.ok(!res.text.includes(credential)); assert.ok(res.text.includes('method="post" action="/session"'));
    });
    await t.test('exact and omitted Origin require both valid login proofs before authentication', async () => {
      const { cookie, proof } = await loginPage(); const before = authentications;
      for (const headers of [{}, { Origin: origin }]) {
        for (const [cookieValue, proofValue] of [[undefined, proof], [cookie, undefined], [cookie, 'bad']]) {
          rejected(await request('/session', { method: 'POST', headers: { ...headers, ...(cookieValue ? { Cookie: cookieValue } : {}) }, input: { credential, ...(proofValue ? { login_csrf: proofValue } : {}) } }));
        }
      }
      assert.equal(authentications, before);
      for (const headers of [{}, { Origin: origin }]) {
        const res = await request('/session', { method: 'POST', headers: { ...headers, Cookie: cookie }, input: { credential, login_csrf: proof } });
        assert.equal(res.status, 303); assert.equal(res.headers.location, '/');
        assert.equal(res.headers['referrer-policy'], 'no-referrer');
        assert.ok(res.headers['set-cookie'].some(c => c.startsWith('__Host-bmc_session=') && c.includes('Secure; HttpOnly; SameSite=Lax; Path=/; Max-Age=28800')));
        assert.ok(res.headers['set-cookie'].some(c => c.startsWith('__Host-bmc_login=;') && c.endsWith('Max-Age=0')));
      }
      assert.equal(authentications, before + 2);
    });
    await t.test('explicit null, foreign, empty and malformed login Origins cannot use valid proofs', async () => {
      const { cookie, proof } = await loginPage(); const before = authentications;
      for (const value of ['null', 'https://foreign.example', origin + '/', '', 'not-an-origin', origin + ', ' + origin]) {
        rejected(await request('/session', { method: 'POST', headers: { Origin: value, Cookie: cookie, 'Sec-Fetch-Site': 'same-origin' }, input: { credential, login_csrf: proof } }));
      }
      assert.equal(authentications, before);
    });
    await t.test('fake credentials reach authentication with valid proofs; login throttle remains before authentication', async () => {
      const { cookie, proof } = await loginPage(); const before = authentications;
      const options = { method: 'POST', headers: { Cookie: cookie, Origin: origin }, input: { credential: 'deliberately-invalid-fixture', login_csrf: proof } };
      const res = await request('/session', options);
      assert.equal(res.status, 401); assert.equal(res.data.error.code, 'authentication_required');
      assert.equal(res.headers['referrer-policy'], 'no-referrer'); assert.equal(authentications, before + 1);
      limitLogin = true;
      try { assert.equal((await request('/session', options)).status, 429); assert.equal(authentications, before + 1); }
      finally { limitLogin = false; }
      assert.ok(limits.some(([key, n]) => key.startsWith('login:') && n === 10));
    });
    await t.test('all authenticated mutations still require exact Origin, session and session-bound CSRF', async () => {
      for (const path of ['/connections/start', ...['confirm', 'health', 'disconnect'].map(action => `/connections/${id}/${action}`)]) {
        const input = { csrf: csrf(sessionToken), ...(path.endsWith('/start') ? { site: 'https://site.example.com' } : {}) };
        const cookie = `__Host-bmc_session=${sessionToken}`; const before = mutations;
        for (const value of [undefined, 'null', 'https://foreign.example', origin + '/']) {
          rejected(await request(path, { method: 'POST', headers: { Cookie: cookie, ...(value === undefined ? {} : { Origin: value }) }, input }));
        }
        rejected(await request(path, { method: 'POST', headers: { Cookie: cookie, Origin: origin }, input: { ...input, csrf: 'bad' } }));
        assert.equal((await request(path, { method: 'POST', headers: { Origin: origin }, input })).status, 401);
        assert.equal(mutations, before);
        assert.equal((await request(path, { method: 'POST', headers: { Cookie: cookie, Origin: origin }, input })).status, 200);
        assert.equal(mutations, before + 1);
      }
    });
    await t.test('only successful login and authenticated root HTML use same-origin; errors, callback and JSON keep no-referrer', async () => {
      const headers = { Cookie: `__Host-bmc_session=${sessionToken}` };
      assert.equal((await request('/', { headers })).headers['referrer-policy'], 'same-origin');
      for (const res of [await request('/'), await request('/callback', { headers }), await request('/callback'), await request('/healthz'), await request('/missing', { headers }), await request('/login', { headers: { Host: 'foreign.example' } })]) {
        assert.equal(res.headers['referrer-policy'], 'no-referrer');
      }
      relay.list = async () => { throw new Error('fixture failure'); };
      const res = await request('/', { headers }); assert.equal(res.status, 500); assert.equal(res.headers['referrer-policy'], 'no-referrer');
    });
    await t.test('request logs exclude credentials, cookies, CSRF and query secrets on all tested paths', async () => {
      const code = secret(); const verifier = secret(); secrets.push(code, verifier);
      await request(`/callback?code=${code}&code_verifier=${verifier}`, { headers: { Cookie: `__Host-bmc_session=${sessionToken}` } });
      for (const value of secrets) assert.ok(!logs.includes(value), 'Secret absent from logs');
      for (const line of logs.trim().split('\n')) assert.deepEqual(Object.keys(JSON.parse(line)), ['time', 'event', 'request_id', 'status']);
    });
  } finally { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});

test('browser action presentation preserves JSON authority and safe notices', async t => {
  const token = secret(); const id = uuid(); const origin = 'https://relay.example.com';
  let state = 'awaiting_confirmation'; let calls = 0; let failure; let revoked = true;
  const relay = {
    config: { baseUrl: origin, maxConcurrent: 16 }, db: { limit: async () => {} },
    authenticate: async value => { if (value !== token) throw new SafeError('authentication_required', 401); return {}; },
    list: async () => [{ connection_id: id, canonical_origin: 'https://site.example.com', base_path: '', state, scopes: ['status:read'] }]
  };
  for (const method of ['confirm', 'health', 'disconnect']) relay[method] = async () => {
    calls++; if (failure) throw failure;
    return { connection_id: id, site_revocation_confirmed: revoked, private_fixture: 'NEVER_IN_HTML' };
  };
  const server = createServer(relay, { log: () => {} });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  const request = (path, { accept, post = false, proof = csrf(token), originHeader = origin, host = 'relay.example.com', cookie = token } = {}) => new Promise((resolve, reject) => {
    const req = http.request({ host: '127.0.0.1', port: server.address().port, path, method: post ? 'POST' : 'GET', headers: {
      Host: host, Cookie: `__Host-bmc_session=${cookie}`, Origin: originHeader,
      'Content-Type': 'application/x-www-form-urlencoded', ...(accept === undefined ? {} : { Accept: accept })
    } }, res => { let text = ''; res.on('data', part => text += part); res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, text })); });
    req.on('error', reject); req.end(post ? new URLSearchParams({ csrf: proof }).toString() : undefined);
  });
  try {
    await t.test('all existing states render the narrow action set', async () => {
      const expected = {
        awaiting_confirmation: ['confirm', 'disconnect'], active: ['health', 'disconnect'],
        pending: ['disconnect'], suspended: ['disconnect'], revoked_or_expired: ['disconnect'],
        exchanging: [], disconnect_pending: [], disconnected: [], denied: [], failed: [], abandoned: [],
        unknown: [], constructor: []
      };
      for (const [value, actions] of Object.entries(expected)) {
        state = value; const res = await request('/');
        const specialLabels = { awaiting_confirmation: 'Awaiting confirmation', revoked_or_expired: 'Revoked or expired', disconnect_pending: 'Disconnect pending' };
        const readable = Object.hasOwn(specialLabels, value) ? specialLabels[value] : (['unknown', 'constructor'].includes(value) ? value : value[0].toUpperCase() + value.slice(1));
        assert.ok(res.text.includes(`>${readable}</span>`), `${value}: truthful state label`);
        assert.ok(!res.text.replace(/<[^>]*>/g, '').includes(id), 'UUID is not displayed');
        for (const action of ['confirm', 'health', 'disconnect']) assert.equal(res.text.includes(`/connections/${id}/${action}`), actions.includes(action), `${value}: ${action}`);
      }
    });
    await t.test('HTML redirects once, JSON/default retain result and security headers', async () => {
      for (const [method, notice] of [['confirm', 'confirmed'], ['health', 'healthy'], ['disconnect', 'disconnected']]) {
        for (const accept of ['text/html,application/xhtml+xml,*/*;q=0.8', 'text/html;q=0.5', undefined, '*/*', 'application/json', 'text/html,application/json', 'text/html;q=0', 'text/html;q=garbage']) {
          const before = calls; const res = await request(`/connections/${id}/${method}`, { post: true, accept });
          assert.equal(calls, before + 1);
          const html = accept?.startsWith('text/html') && !accept.includes('application/json') && !accept.endsWith('q=0') && !accept.endsWith('q=garbage');
          assert.equal(res.status, html ? 303 : 200);
          assert.equal(res.headers['referrer-policy'], 'no-referrer'); assert.equal(res.headers['cache-control'], 'no-store');
          assert.equal(res.headers['x-frame-options'], 'DENY');
          assert.equal(res.headers['content-security-policy'], "default-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
          if (html) { assert.equal(res.headers.location, '/?notice=' + notice); assert.equal(res.text, ''); }
          else assert.equal(JSON.parse(res.text).private_fixture, 'NEVER_IN_HTML');
        }
      }
      revoked = false;
      const res = await request(`/connections/${id}/disconnect`, { post: true, accept: 'text/html' });
      assert.equal(res.headers.location, '/?notice=review');
      const root = await request(res.headers.location);
      assert.ok(root.text.includes('Site revocation is not confirmed; owner review required.'));
      assert.ok(!root.text.includes('NEVER_IN_HTML'));
    });
    await t.test('operation errors are fixed notices, JSON keeps HTTP classification and certainty', async () => {
      for (const method of ['confirm', 'health', 'disconnect']) {
        for (const error of [new SafeError('fixture_failure', 409, 'site', 'unknown'), new Error('PRIVATE_UPSTREAM_MESSAGE')]) {
          failure = error;
          const before = calls; const res = await request(`/connections/${id}/${method}`, { post: true, accept: 'text/html' });
          assert.equal(calls, before + 1); assert.equal(res.status, 303);
          assert.equal(res.headers.location, `/?notice=${method}_failed`);
          const root = await request(res.headers.location);
          assert.ok(root.text.includes('role="status"')); assert.ok(!root.text.includes('PRIVATE_UPSTREAM_MESSAGE'));
          const json = await request(`/connections/${id}/${method}`, { post: true, accept: 'application/json' });
          assert.equal(json.status, error instanceof SafeError ? 409 : 500);
          assert.equal(JSON.parse(json.text).error.outcome_certainty, 'unknown');
        }
      }
      failure = undefined;
    });
    await t.test('HTML preference cannot bypass security or reflect untrusted notices', async () => {
      for (const options of [{ proof: 'bad' }, { originHeader: 'null' }, { originHeader: 'https://foreign.example' }, { cookie: 'bad' }, { host: 'foreign.example' }]) {
        const before = calls; const res = await request(`/connections/${id}/confirm`, { post: true, accept: 'text/html', ...options });
        assert.ok(res.status >= 400); assert.equal(calls, before); assert.equal(res.headers.location, undefined);
      }
      for (const value of ['<script>untrusted</script>', 'constructor', '__proto__', 'toString']) {
        const res = await request('/?notice=' + encodeURIComponent(value));
        assert.ok(!res.text.includes('role="status"')); assert.ok(!res.text.includes('untrusted'));
      }
    });
  } finally { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});
