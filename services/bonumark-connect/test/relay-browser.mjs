import assert from 'node:assert/strict';
import { historyBrowserChecks } from './history-browser.mjs';
import { presentationChecks } from './presentation-browser.mjs';
import http from 'node:http';
import https from 'node:https';
import { once } from 'node:events';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { createHash, X509Certificate } from 'node:crypto';
import { chromium } from 'playwright';
import { Relay } from '../src/relay.mjs';
import { createServer } from '../src/server.mjs';
import { secret } from '../src/security.mjs';

// Real Chromium -> local HTTPS proxy -> production HTTP handler -> real Relay/SQL.
// Only the ephemeral fixture certificate's public key is trusted by this browser.
// Positive requests/response headers are never rewritten. Negative Origin cases
// inject only that header at the proxy: Chromium regenerates it on navigation,
// even when Playwright route.continue requests an override. No browser artifacts,
// storage state, screenshots, traces, videos, bodies or network logs are saved.
export async function relayBrowserChecks(t, fixture) {
  const dir = await mkdtemp(path.join(tmpdir(), 'bmc-login-browser-'));
  let server; let proxy; let browser; let logs = ''; let observed; let urlLeak = false; let rewriteOrigin;
  const sensitive = []; const calls = { login: 0, start: 0, confirm: 0, health: 0, disconnect: 0 };
  try {
    const cert = spawnSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', dir + '/key.pem', '-out', dir + '/cert.pem', '-days', '1', '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost'], { stdio: 'ignore' });
    assert.equal(cert.status, 0, 'Ephemeral TLS fixture created');
    const certificate = await readFile(dir + '/cert.pem');
    const spki = createHash('sha256').update(new X509Certificate(certificate).publicKey.export({ type: 'spki', format: 'der' })).digest('base64');
    proxy = https.createServer({ key: await readFile(dir + '/key.pem'), cert: certificate }, (req, res) => {
      const headers = { ...req.headers };
      if (req.method === 'POST' && rewriteOrigin) rewriteOrigin(headers);
      const upstream = http.request({ host: '127.0.0.1', port: server.address().port, method: req.method, path: req.url, headers }, response => {
        res.writeHead(response.statusCode, response.headers); response.pipe(res);
      });
      upstream.on('error', () => { res.writeHead(502); res.end(); }); req.pipe(upstream);
    });
    proxy.listen(0, '127.0.0.1'); await once(proxy, 'listening');
    const origin = `https://localhost:${proxy.address().port}`;
    const relay = new Relay(fixture.db, fixture.transport, fixture.keys, { ...fixture.config, baseUrl: origin, callbackUrl: origin + '/callback' });
    // This account exists only in the disposable bmc_test database.
    const account = await relay.provisionAccount(); sensitive.push(account.token);
    for (const method of Object.keys(calls)) {
      const original = relay[method].bind(relay);
      relay[method] = async (...args) => { calls[method]++; return original(...args); };
    }
    server = createServer(relay, { log: line => logs += line });
    server.on('request', req => {
      if (req.method === 'POST') observed = { origin: req.headers.origin, path: req.url };
      if (sensitive.some(value => req.url.includes(value))) urlLeak = true;
    });
    server.listen(0, '127.0.0.1'); await once(server, 'listening');
    browser = await chromium.launch({ executablePath: process.env.BMC_BROWSER_EXECUTABLE || undefined, args: ['--no-sandbox', '--no-proxy-server', `--ignore-certificate-errors-spki-list=${spki}`] });
    const context = await browser.newContext(); const page = await context.newPage(); page.setDefaultTimeout(10000);
    let styleViolations = 0; let externalRequests = 0; let stylesheetResponses = 0;
    page.on('console', message => { if (/style.*(?:Content Security Policy|violates)|Refused to load the stylesheet/i.test(message.text())) styleViolations++; });
    page.on('request', request => { if (new URL(request.url()).origin !== origin) externalRequests++; });
    page.on('response', response => { if (new URL(response.url()).pathname === '/assets/connect.css' && response.status() === 200) stylesheetResponses++; });
    const remember = async () => {
      for (const cookie of await context.cookies()) if (cookie.value) sensitive.push(cookie.value);
      for (const input of await page.locator('input[type=hidden]').all()) { const value = await input.inputValue(); if (value) sensitive.push(value); }
    };
    const openLogin = async () => {
      const res = await page.goto(origin + '/login');
      assert.equal(res.status(), 200); assert.equal(res.headers()['referrer-policy'], 'same-origin');
      assert.equal(res.headers()['cache-control'], 'no-store'); assert.equal(res.headers()['x-frame-options'], 'DENY');
      assert.equal(res.headers()['content-security-policy'], "default-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; style-src 'self'");
      const cookie = (await context.cookies()).find(c => c.name === '__Host-bmc_login');
      assert.ok(cookie && cookie.secure && cookie.httpOnly && cookie.sameSite === 'Strict' && cookie.domain === 'localhost' && cookie.path === '/', 'Browser accepts the protected host-only login cookie');
      assert.ok(cookie.expires > Date.now() / 1000 && cookie.expires <= Date.now() / 1000 + 601);
      assert.equal(await page.locator('input[name=login_csrf]').count(), 1);
      assert.equal(await page.evaluate(() => document.cookie), '', 'Proof/session cookies are HttpOnly');
      await remember();
    };
    const submit = async (label, expectedPath) => {
      const response = page.waitForResponse(res => res.request().method() === 'POST' && new URL(res.url()).pathname === expectedPath);
      await page.getByRole('button', { name: label, exact: true }).click();
      const res = await response; await page.waitForLoadState('load'); return res;
    };
    const originOverride = value => headers => {
      if (value === undefined) delete headers.origin; else headers.origin = value;
    };
    await t.test('native HTTPS login sends exact Origin and a fake credential reaches authentication', async () => {
      await openLogin(); await presentationChecks(page, 'login'); await page.getByLabel('Development account credential').fill('deliberately-invalid-fixture');
      const before = calls.login; const res = await submit('Sign in', '/session');
      assert.equal(observed.origin, origin); assert.equal((await res.request().allHeaders()).origin, origin);
      assert.equal(res.status(), 401); assert.equal((await res.json()).error.code, 'authentication_required');
      assert.equal(res.headers()['referrer-policy'], 'no-referrer'); assert.equal(calls.login, before + 1);
      t.diagnostic(`Chromium ${browser.version()}; configured origin ${origin}; native POST /session Origin: ${observed.origin}; fake credential returns 401 authentication_required.`);
    });
    for (const scenario of ['missing proof', 'wrong proof', 'missing cookie', 'null Origin', 'foreign Origin', 'malformed Origin']) {
      await t.test(`browser login rejects ${scenario} before authentication`, async () => {
        await openLogin(); await page.getByLabel('Development account credential').fill(account.token);
        if (scenario === 'missing proof') await page.locator('[name=login_csrf]').evaluate(input => input.remove());
        if (scenario === 'wrong proof') await page.locator('[name=login_csrf]').evaluate(input => { input.value = 'bad'; });
        if (scenario === 'missing cookie') await context.clearCookies({ name: '__Host-bmc_login' });
        const value = { 'null Origin': 'null', 'foreign Origin': 'https://foreign.example', 'malformed Origin': origin + '/' }[scenario];
        if (value !== undefined) rewriteOrigin = originOverride(value);
        try {
          const before = calls.login; const res = await submit('Sign in', '/session');
          assert.equal(res.status(), 403); assert.equal((await res.json()).error.code, 'csrf_invalid');
          if (value !== undefined) assert.equal(observed.origin, value);
          assert.equal(res.headers()['referrer-policy'], 'no-referrer'); assert.equal(calls.login, before);
        } finally { rewriteOrigin = undefined; }
      });
    }
    for (const omit of [false, true]) {
      await t.test(`valid browser login ${omit ? 'with deliberately omitted Origin' : 'with native exact Origin'} creates a session and clears the temporary cookie`, async () => {
        await openLogin(); await page.getByLabel('Development account credential').fill(account.token);
        if (omit) rewriteOrigin = originOverride(undefined);
        try {
          const res = await submit('Sign in', '/session');
          assert.equal(res.status(), 303); assert.equal(observed.origin, omit ? undefined : origin);
          assert.equal(res.headers()['referrer-policy'], 'no-referrer');
          await page.waitForURL(origin + '/'); await remember();
          assert.ok(!(await context.cookies()).some(c => c.name === '__Host-bmc_login'), 'Temporary proof cookie cleared');
          const session = (await context.cookies()).find(c => c.name === '__Host-bmc_session');
          assert.ok(session && session.secure && session.httpOnly && session.sameSite === 'Lax');
          assert.equal(await page.getByRole('heading', { name: 'Connected sites', exact: true }).count(), 1);
          assert.equal(await page.evaluate(() => document.cookie), '');
          if (!omit) await presentationChecks(page, 'empty connections');
        } finally { rewriteOrigin = undefined; }
      });
    }
    const openRoot = async () => {
      const res = await page.goto(origin + '/'); assert.equal(res.status(), 200);
      assert.equal(res.headers()['referrer-policy'], 'same-origin'); await remember();
      await page.getByLabel('Bonumark site URL').fill('https://site.example.com');
    };
    for (const scenario of ['missing Origin', 'null Origin', 'foreign Origin', 'bad session CSRF']) {
      await t.test(`authenticated native Connect form rejects ${scenario}`, async () => {
        await openRoot();
        if (scenario === 'bad session CSRF') await page.locator('form[action="/connections/start"] [name=csrf]').evaluate(input => { input.value = 'bad'; });
        else rewriteOrigin = originOverride({ 'null Origin': 'null', 'foreign Origin': 'https://foreign.example' }[scenario]);
        try {
          const before = calls.start; const res = await submit('Connect site', '/connections/start');
          assert.equal(res.status(), 403); assert.equal((await res.json()).error.code, 'csrf_invalid');
          assert.equal(res.headers()['referrer-policy'], 'no-referrer'); assert.equal(calls.start, before);
          if (scenario !== 'bad session CSRF') assert.equal(observed.origin, { 'null Origin': 'null', 'foreign Origin': 'https://foreign.example' }[scenario]);
        } finally { rewriteOrigin = undefined; }
      });
    }
    await t.test('native exact-Origin Connect form reaches real Relay discovery and SQL with session CSRF', async () => {
      await openRoot(); const before = calls.start; const res = await submit('Connect site', '/connections/start');
      assert.equal(res.status(), 200); assert.equal(observed.origin, origin);
      assert.equal((await res.request().allHeaders()).origin, origin); assert.equal(calls.start, before + 1);
      assert.equal(res.headers()['referrer-policy'], 'no-referrer');
      assert.equal(await page.getByRole('heading', { name: 'Approve on your Bonumark site', exact: true }).count(), 1);
      await presentationChecks(page, 'approval start');
      t.diagnostic(`Native POST /connections/start Origin: ${observed.origin}; real Relay discovery and SQL succeed (200).`);
    });
    // The new row has not been site-approved: confirm and health must reach the
    // real operation and reject that state, not silently create site authority.
    for (const [method, label, notice] of [['confirm', 'Confirm connection', 'confirm_failed'], ['health', 'Check connection', 'health_failed'], ['disconnect', 'Disconnect', 'review']]) {
      await t.test(`native ${method} form sends exact Origin and reaches the real operation`, async () => {
        await openRoot(); const [row] = await relay.db.query('SELECT connection_id FROM connections WHERE account_id = ?', [account.account_id]);
        assert.ok(row);
        if (method !== 'disconnect') {
          assert.equal(await page.getByRole('button', { name: label, exact: true }).count(), 0, 'Invalid pending-state action is hidden');
          // A stale/tampered native form still reaches unchanged state authority.
          await page.locator('form[action="/connections/start"]').evaluate((form, { id, method, label }) => {
            const stale = form.cloneNode(true); stale.action = `/connections/${id}/${method}`;
            stale.querySelector('[name=site]').remove(); stale.querySelector('button').textContent = label;
            document.querySelector('main').append(stale);
          }, { id: row.connection_id, method, label });
        }
        const before = calls[method]; const res = await submit(label, `/connections/${row.connection_id}/${method}`);
        assert.equal(observed.origin, origin); assert.equal(res.status(), 303); assert.equal(calls[method], before + 1);
        assert.equal(res.headers()['referrer-policy'], 'no-referrer');
        await page.waitForURL(origin + '/?notice=' + notice);
        assert.equal(await page.getByRole('heading', { name: 'Connected sites', exact: true }).count(), 1);
        assert.equal(await page.getByRole('status').count(), 1);
        t.diagnostic(`Native ${method} POST Origin: ${observed.origin}; real Relay operation reached (303, ${notice}).`);
      });
    }
    await t.test('connection cards safely wrap long values and preserve active-state actions', async () => {
      const originalList = relay.list;
      try {
        relay.list = async () => [{ connection_id: '00000000-0000-4000-8000-000000000001', canonical_origin: 'https://' + 'long'.repeat(14) + '.example.com', base_path: '/' + 'path'.repeat(30), scopes: ['status:read', 'fixture:' + 'scope'.repeat(30)], state: 'active' }];
        await page.goto(origin + '/?notice=healthy');
        await presentationChecks(page, 'long active connection and success notice');
        assert.equal(await page.locator('.state-pill').textContent(), 'Active');
        assert.equal(await page.getByRole('button', { name: 'Confirm connection', exact: true }).count(), 0);
        assert.equal(await page.getByRole('button', { name: 'Check connection', exact: true }).count(), 1);
      } finally { relay.list = originalList; }
      await page.goto(origin + '/?notice=review');
      await presentationChecks(page, 'owner review warning');
      await page.goto(origin + '/?notice=confirm_failed');
      await presentationChecks(page, 'confirmation failure notice');
      assert.equal(styleViolations, 0, 'No stylesheet CSP console violations');
      assert.equal(externalRequests, 0, 'No external browser network requests');
      assert.ok(stylesheetResponses > 0, 'Stylesheet requests succeeded');
      t.diagnostic('Connect login, empty, approval, active, success and warning layouts passed at 1280x900, 768x1024, 390x844 and 360x800; text/control contrast, focus, labels, targets, local CSS and no external requests passed.');
    });
    const historyContext = await browser.newContext({ javaScriptEnabled: false });
    try {
      await historyContext.addCookies(await context.cookies());
      const historyPage = await historyContext.newPage();
      await historyBrowserChecks(t, historyPage, relay, origin);
    } finally { await historyContext.close(); }
    await t.test('callback and auth errors keep no-referrer; browser and relay logs persist no secret artifacts', async () => {
      assert.equal(urlLeak, false, 'Login credentials, proof cookies and form CSRF never enter URLs');
      const code = secret(); sensitive.push(code);
      const res = await page.goto(origin + `/callback?code=${code}`);
      assert.equal(res.status(), 400); assert.equal(res.headers()['referrer-policy'], 'no-referrer');
      // A callback code is intentionally in its protocol URL, unlike login
      // credentials/proofs; it must still remain absent from request logs.
      const cookieValues = (await context.cookies()).map(c => c.value); sensitive.push(...cookieValues);
      for (const value of sensitive) assert.ok(!logs.includes(value), 'Secret absent from relay logs');
      for (const line of logs.trim().split('\n')) assert.deepEqual(Object.keys(JSON.parse(line)), ['time', 'event', 'request_id', 'status']);
      await context.clearCookies();
      const denied = await page.goto(origin + '/callback');
      assert.equal(denied.status(), 401); assert.equal(denied.headers()['referrer-policy'], 'no-referrer');
    });
    await context.close();
  } finally {
    if (browser) await browser.close();
    for (const listener of [proxy, server]) if (listener) { listener.closeAllConnections(); await new Promise(resolve => listener.close(resolve)); }
    await rm(dir, { recursive: true, force: true });
  }
}
