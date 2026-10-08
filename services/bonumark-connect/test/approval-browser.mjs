import assert from 'node:assert/strict';
import http from 'node:http';
import https from 'node:https';
import net from 'node:net';
import { once } from 'node:events';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { createHash, X509Certificate } from 'node:crypto';
import { chromium } from 'playwright';
import { createServer } from '../src/server.mjs';
import { digest, SafeError } from '../src/security.mjs';

// Native HTTPS navigation through the real PHP and relay handlers. DNS/port
// mapping and ephemeral TLS terminate locally; no response or Location rewrite,
// manual callback, screenshots, trace, HAR, video or storage-state artifacts.
export async function approvalBrowserChecks(t, relay, siteBase, root, driver, password) {
  const dir = await mkdtemp(path.join(tmpdir(), 'bmc-approval-browser-'));
  const site = 'https://site.example.com'; const origin = relay.config.baseUrl;
  const records = []; const sensitive = [password]; let logs = '';
  let browser; let proxy; let tunnel; let server; let phase = 'setup';
  const sockets = new Set();
  const calls = { confirm: 0, health: 0, disconnect: 0, status: 0, revoke: 0 };
  const originals = Object.fromEntries(['confirm', 'health', 'disconnect'].map(method => [method, relay[method]]));
  const originalTransport = relay.transport.json;
  let revokeOutage = false; let healthOutage = false;
  for (const method of Object.keys(originals)) relay[method] = async function (...args) {
    calls[method]++; return originals[method].apply(this, args);
  };
  relay.transport.json = async function (url, options) {
    if (new URL(url).pathname.endsWith('/status.php')) {
      calls.status++;
      if (healthOutage) throw new SafeError('target_site_unavailable', 503);
    }
    if (new URL(url).pathname.endsWith('/revoke.php')) {
      calls.revoke++;
      const [row] = await relay.db.query('SELECT state FROM connections WHERE connection_id = ?', [actionConnection]);
      assert.equal(row.state, 'disconnect_pending', 'Routing disabled before outbound revocation');
      if (revokeOutage) throw new SafeError('target_site_unavailable', 503);
    }
    return originalTransport.call(this, url, options);
  };
  let actionConnection;

  const snapshot = request => {
    const result = spawnSync(process.env.PHP_BINARY ?? 'php', [driver, root, 'inspect-request'], { input: JSON.stringify({ request }), encoding: 'utf8' });
    assert.equal(result.status, 0, 'Safe lifecycle query succeeded');
    return JSON.parse(result.stdout);
  };
  try {
    const cert = spawnSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', dir + '/key.pem', '-out', dir + '/cert.pem', '-days', '1', '-subj', '/CN=relay.example.com', '-addext', 'subjectAltName=DNS:relay.example.com,DNS:site.example.com'], { stdio: 'ignore' });
    assert.equal(cert.status, 0, 'Ephemeral TLS fixture created');
    const certificate = await readFile(dir + '/cert.pem');
    const spki = createHash('sha256').update(new X509Certificate(certificate).publicKey.export({ type: 'spki', format: 'der' })).digest('base64');
    server = createServer(relay, { log: line => logs += line });
    server.listen(0, '127.0.0.1'); await once(server, 'listening');
    proxy = https.createServer({ key: await readFile(dir + '/key.pem'), cert: certificate }, (req, res) => {
      const host = req.headers.host;
      if (!['site.example.com', 'relay.example.com'].includes(host)) { res.writeHead(421); res.end(); return; }
      const pathname = new URL(req.url, `https://${host}`).pathname;
      const record = { host, path: pathname, method: req.method, origin: req.headers.origin === undefined ? 'absent' : req.headers.origin === 'null' ? 'null' : req.headers.origin === site ? 'site' : req.headers.origin === origin ? 'relay' : 'foreign' };
      if (pathname === '/callback') {
        record.session_cookie = /(?:^|;\s*)__Host-bmc_session=/.test(req.headers.cookie ?? '');
        record.referrer = req.headers.referer === undefined ? 'absent' : 'present';
        // Values live only in memory to prove they never reach logs.
        for (const value of new URL(req.url, origin).searchParams.values()) sensitive.push(value);
      }
      const port = host === 'site.example.com' ? Number(new URL(siteBase).port) : server.address().port;
      const upstream = http.request({ host: '127.0.0.1', port, method: req.method, path: req.url, headers: { ...req.headers, 'x-forwarded-proto': 'https' } }, response => {
        record.status = response.statusCode;
        record.csp = response.headers['content-security-policy'];
        record.referrer_policy = response.headers['referrer-policy'];
        record.no_store = response.headers['cache-control'] === 'no-store';
        record.frame_denial = response.headers['x-frame-options'] === 'DENY';
        record.csp_headers = response.rawHeaders.filter((h, i) => i % 2 === 0 && h.toLowerCase() === 'content-security-policy').length;
        records.push(record);
        res.writeHead(response.statusCode, response.headers); response.pipe(res);
      });
      upstream.on('error', () => { res.writeHead(502); res.end(); }); req.pipe(upstream);
    });
    proxy.listen(0, '127.0.0.1'); await once(proxy, 'listening');
    tunnel = http.createServer();
    tunnel.on('connect', (req, client, head) => {
      if (!['site.example.com:443', 'relay.example.com:443'].includes(req.url)) { client.end('HTTP/1.1 403 Forbidden\r\n\r\n'); return; }
      const upstream = net.connect(proxy.address().port, '127.0.0.1', () => {
        client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
        if (head.length) upstream.write(head);
        client.pipe(upstream); upstream.pipe(client);
      });
      for (const socket of [client, upstream]) { sockets.add(socket); socket.on('error', () => socket.destroy()); socket.on('close', () => sockets.delete(socket)); }
    });
    tunnel.listen(0, '127.0.0.1'); await once(tunnel, 'listening');
    browser = await chromium.launch({ executablePath: process.env.BMC_BROWSER_EXECUTABLE || undefined, proxy: { server: `http://127.0.0.1:${tunnel.address().port}` }, args: ['--no-sandbox', `--ignore-certificate-errors-spki-list=${spki}`] });
    const context = await browser.newContext(); const page = await context.newPage(); page.setDefaultTimeout(10000);
    const violations = [];
    await page.exposeFunction('recordPolicyViolation', directive => violations.push(directive));
    await page.addInitScript(() => document.addEventListener('securitypolicyviolation', e => window.recordPolicyViolation(e.effectiveDirective)));
    const submit = async (label, pathname) => {
      const response = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === pathname);
      await page.getByRole('button', { name: label, exact: true }).click();
      return await response;
    };
    phase = 'native site Admin login';
    await page.goto(site + '/admin/login.php');
    await page.getByLabel('Username', { exact: true }).fill('connectowner');
    await page.getByLabel('Password', { exact: true }).fill(password);
    assert.equal((await submit('Log in', '/admin/login.php')).status(), 302);
    await page.waitForURL(url => url.origin === site && ['/admin', '/admin/', '/admin/index.php'].includes(url.pathname));
    const browserAccount = await relay.provisionAccount(); sensitive.push(browserAccount.token);
    let loggedIn = false; let previousConnection;
    const startApproval = async (foreignCallback = false) => {
      if (!loggedIn) {
        await page.goto(origin + '/login');
        await page.getByLabel('Development account credential').fill(browserAccount.token);
        assert.equal((await submit('Sign in', '/session')).status(), 303);
        await page.waitForURL(origin + '/'); loggedIn = true;
      } else await page.goto(origin + '/');
      if (previousConnection) {
        const endpoint = `/connections/${previousConnection}/disconnect`;
        if (await page.locator(`form[action="${endpoint}"]`).count()) {
          const response = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === endpoint);
          await page.locator(`form[action="${endpoint}"]`).getByRole('button', { name: 'Disconnect', exact: true }).click();
          assert.equal((await response).status(), 303, 'Normal disconnect isolates browser scenarios');
          await page.waitForURL(origin + '/?notice=review');
          await page.goto(origin + '/');
        }
      }
      await page.getByLabel('Bonumark site URL').fill(site);
      assert.equal((await submit('Connect site', '/connections/start')).status(), 200);
      const link = page.getByRole('link', { name: 'Open site approval', exact: true });
      const state = new URL(await link.getAttribute('href')).searchParams.get('state'); sensitive.push(state);
      const [connection] = await relay.db.query('SELECT connection_id FROM connections WHERE state_hash = ?', [digest('state', state)]);
      previousConnection = connection.connection_id; actionConnection = previousConnection;
      if (foreignCallback) await link.evaluate(a => { const u = new URL(a.href); u.searchParams.set('redirect_uri', 'https://foreign.example/callback'); a.href = u.href; });
      await link.click();
      if (foreignCallback) return { account: browserAccount, connection: connection.connection_id };
      await page.getByRole('button', { name: 'Approve connection', exact: true }).waitFor();
      const request = await page.locator('input[name=request]').inputValue(); sensitive.push(request);
      return { account: browserAccount, request, connection: connection.connection_id };
    };
    phase = 'native relay login, discovery and site approval';
    const { account, request } = await startApproval();
    const before = snapshot(request);
    assert.equal(before.session, 'pending'); assert.equal(before.credentials, 0);
    const approvalPage = records.findLast(r => r.path === '/admin/connect-authorize.php' && r.method === 'GET' && r.status === 200);
    phase = 'native approval to callback';
    await page.getByRole('button', { name: 'Approve connection', exact: true }).click({ noWaitAfter: true });
    for (let i = 0; i < 100 && !records.some(r => r.path === '/admin/connect-authorize.php' && r.method === 'POST'); i++) await new Promise(resolve => setTimeout(resolve, 25));
    assert.equal(records.find(r => r.path === '/admin/connect-authorize.php' && r.method === 'POST')?.status, 303);
    // A blocked redirect may restore the approval document. Poll only safe
    // metadata rather than allowing a Playwright timeout to print its URL.
    for (let i = 0; i < 100 && new URL(page.url()).origin !== origin; i++) await new Promise(resolve => setTimeout(resolve, 25));
    await page.waitForLoadState('load');
    const after = snapshot(request);
    const callbacks = records.filter(r => r.path === '/callback');
    const posts = records.filter(r => r.path === '/admin/connect-authorize.php' && r.method === 'POST');
    const [connection] = await relay.db.query('SELECT state FROM connections WHERE account_id = ?', [account.account_id]);
    const final = new URL(page.url());
    t.diagnostic(JSON.stringify({ chromium: browser.version(), approval_page: approvalPage, approval_posts: posts, callbacks, violations, final: final.origin + final.pathname, lifecycle: after, connection: connection.state }));
    assert.equal(posts.length, 1, 'One click produces exactly one approval POST');
    assert.equal(callbacks.length, 1, 'Chromium reaches the relay callback');
    assert.equal(callbacks[0].host, 'relay.example.com');
    assert.equal(callbacks[0].status, 303); assert.equal(callbacks[0].session_cookie, true);
    assert.equal(callbacks[0].referrer, 'absent');
    assert.equal(callbacks[0].referrer_policy, 'no-referrer');
    assert.equal(final.origin, origin); assert.equal(final.pathname, '/'); assert.equal(final.search, '');
    assert.equal(connection.state, 'awaiting_confirmation');
    assert.equal(after.session, 'exchanged'); assert.deepEqual(after.grants, ['active']);
    assert.deepEqual(after.codes, [1]); assert.equal(after.credentials, 1);
    assert.equal(after.audit.filter(x => x === 'approval').length, 1);
    assert.equal(after.audit.filter(x => x === 'exchange').length, 1);
    assert.equal(await page.getByText('authorization_expired', { exact: false }).count(), 0);
    assert.ok(!violations.includes('form-action'));
    const formPolicy = "default-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self' https://relay.example.com/callback; frame-ancestors 'none'; object-src 'none'";
    assert.equal(approvalPage.csp, formPolicy);
    for (const record of [approvalPage, ...posts, ...callbacks]) {
      assert.equal(record.csp_headers, 1); assert.equal(record.no_store, true); assert.equal(record.frame_denial, true);
    }
    const action = async (method, label, notice, message, state) => {
      phase = `native ${method} final page`;
      const endpoint = `/connections/${actionConnection}/${method}`;
      const before = { ...calls }; const begin = records.length;
      const res = await submit(label, endpoint);
      assert.equal(res.status(), 303); assert.equal(res.headers()['location'], '/?notice=' + notice);
      assert.equal(res.headers()['referrer-policy'], 'no-referrer');
      assert.equal(res.headers()['cache-control'], 'no-store');
      assert.equal(res.headers()['x-frame-options'], 'DENY');
      assert.equal(res.headers()['content-security-policy'], "default-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
      await page.waitForURL(origin + '/?notice=' + notice);
      assert.equal(await page.getByRole('heading', { name: 'Connected sites', exact: true }).count(), 1);
      assert.equal(await page.getByRole('status').textContent(), message);
      assert.equal(await page.locator('pre').count(), 0, 'Browser never lands on a JSON document');
      const posts = records.slice(begin).filter(r => r.method === 'POST');
      assert.equal(posts.length, 1); assert.equal(posts[0].path, endpoint); assert.equal(posts[0].origin, 'relay');
      assert.equal((await res.request().allHeaders()).origin, origin);
      assert.equal(calls[method], before[method] + 1, 'One underlying operation, valid session CSRF');
      if (method === 'health') assert.equal(calls.status, before.status + 1, 'One live site health call');
      if (method === 'disconnect') assert.equal(calls.revoke, before.revoke + 1, 'One outbound revocation attempt');
      assert.equal((await relay.db.query('SELECT state FROM connections WHERE connection_id = ?', [actionConnection]))[0].state, state);
      const row = page.locator(`.connection-card[data-state="${state}"]`).filter({ hasText: 'https://site.example.com' }).first();
      assert.equal(await row.getAttribute('data-state'), state);
      assert.equal(await row.getByRole('button', { name: 'Confirm connection', exact: true }).count(), 0);
      assert.equal(await row.getByRole('button', { name: 'Check connection', exact: true }).count(), state === 'active' ? 1 : 0);
      assert.equal(await row.getByRole('button', { name: 'Disconnect', exact: true }).count(), state === 'active' ? 1 : 0);
      const afterCalls = { ...calls };
      await page.reload(); assert.deepEqual(calls, afterCalls, 'Reload never replays a POST');
      t.diagnostic(JSON.stringify({ action: method, post_count: posts.length, origin: posts[0].origin, status: res.status(), final: new URL(page.url()).pathname, notice, state }));
    };
    assert.equal(await page.getByRole('button', { name: 'Confirm connection', exact: true }).count(), 1);
    assert.equal(await page.getByRole('button', { name: 'Check connection', exact: true }).count(), 0);
    assert.equal(await page.getByRole('button', { name: 'Disconnect', exact: true }).count(), 1);
    await action('confirm', 'Confirm connection', 'confirmed', 'Connection confirmed.', 'active');
    await action('health', 'Check connection', 'healthy', 'Connection health check passed.', 'active');
    healthOutage = true;
    await action('health', 'Check connection', 'health_failed', 'Connection health check did not pass. Review the current connection state.', 'active');
    healthOutage = false;
    await action('disconnect', 'Disconnect', 'disconnected', 'Disconnected. Site revocation confirmed.', 'disconnected');
    previousConnection = undefined;
    phase = 'completed request cannot issue another code';
    const completed = snapshot(request);
    const used = await page.goto(site + '/admin/connect-authorize.php?request=' + request);
    assert.equal(used.status(), 400);
    assert.equal(await page.getByRole('button', { name: 'Approve connection', exact: true }).count(), 0);
    assert.deepEqual(snapshot(request), completed);
    const defaultPolicy = formPolicy.replace(' https://relay.example.com/callback', '');
    assert.equal(used.headers()['content-security-policy'], defaultPolicy);

    phase = 'owner review disconnect setup';
    await startApproval();
    await page.getByRole('button', { name: 'Approve connection', exact: true }).click();
    await page.waitForURL(origin + '/');
    await action('confirm', 'Confirm connection', 'confirmed', 'Connection confirmed.', 'active');
    revokeOutage = true;
    await action('disconnect', 'Disconnect', 'review', 'Routing disabled. Site revocation is not confirmed; owner review required.', 'disconnect_pending');
    revokeOutage = false;
    // Dispose only this test grant through the existing operation after asserting
    // no browser retry/action is exposed for the uncertain disconnect.
    const token = (await context.cookies()).find(c => c.name === '__Host-bmc_session').value;
    await relay.disconnect(await relay.authenticate(token), actionConnection);
    previousConnection = undefined;

    for (const scenario of ['foreign callback', 'bad CSRF', 'foreign form target', 'denial', 'missing relay session']) {
      phase = scenario;
      const begin = records.length;
      const pending = await startApproval(scenario === 'foreign callback');
      if (scenario === 'foreign callback') {
        assert.equal(await page.getByText('invalid_redirect_uri', { exact: false }).count(), 1);
        assert.equal(records.findLast(r => r.path === '/admin/connect-authorize.php').csp, defaultPolicy);
        assert.equal(await page.getByRole('button', { name: 'Approve connection', exact: true }).count(), 0);
      } else {
        if (scenario === 'bad CSRF') await page.locator('form:has(input[name=request]) input[name=csrf_token]').evaluate(input => { input.value = 'invalid'; });
        if (scenario === 'foreign form target') await page.locator('form:has(input[name=request])').evaluate(form => { form.action = 'https://foreign.example/callback'; });
        if (scenario === 'missing relay session') await context.clearCookies({ name: '__Host-bmc_session' });
        const label = scenario === 'denial' ? 'Deny' : 'Approve connection';
        const violationCount = violations.length;
        await page.getByRole('button', { name: label, exact: true }).click({ noWaitAfter: true });
        for (let i = 0; i < 100; i++) {
          const seen = records.slice(begin);
          if (scenario === 'foreign form target' ? violations.length > violationCount : scenario === 'bad CSRF' ? seen.some(r => r.method === 'POST' && r.path === '/admin/connect-authorize.php') : seen.some(r => r.path === '/callback')) break;
          await new Promise(resolve => setTimeout(resolve, 25));
        }
        const state = snapshot(pending.request);
        const cb = records.slice(begin).filter(r => r.path === '/callback');
        assert.equal(state.credentials, 0);
        if (scenario === 'missing relay session') {
          assert.equal(cb.length, 1); assert.equal(cb[0].status, 401); assert.equal(cb[0].session_cookie, false);
          assert.equal(state.session, 'approved'); assert.deepEqual(state.grants, ['pending']); assert.deepEqual(state.codes, [0]);
        } else if (scenario === 'denial') {
          assert.equal(cb.length, 1); assert.equal(cb[0].status, 303);
          assert.equal(state.session, 'denied'); assert.deepEqual(state.grants, []); assert.deepEqual(state.codes, []);
        } else {
          assert.equal(cb.length, 0); assert.equal(state.session, 'pending'); assert.deepEqual(state.codes, []);
          if (scenario === 'bad CSRF') assert.equal(records.findLast(r => r.method === 'POST' && r.path === '/admin/connect-authorize.php').status, 403);
          else { assert.ok(violations.slice(violationCount).includes('form-action')); assert.equal(records.slice(begin).filter(r => r.method === 'POST' && r.path === '/admin/connect-authorize.php').length, 0); }
        }
        // The proxy records response headers before Chromium commits navigation.
        // Finish that navigation before the next scenario opens the relay, or
        // the pending rejected POST can interrupt the next page.goto(). The
        // foreign-target case intentionally has no navigation to wait for.
        if (scenario === 'bad CSRF') {
          await page.getByText('Invalid request token.', { exact: true }).waitFor();
          await page.waitForLoadState('load');
        } else if (scenario === 'denial') {
          await page.waitForURL(origin + '/');
        } else if (scenario === 'missing relay session') {
          await page.waitForURL(url => url.origin === origin && url.pathname === '/callback');
          await page.waitForLoadState('load');
        }
      }
      const [row] = await relay.db.query('SELECT state FROM connections WHERE connection_id = ?', [pending.connection]);
      assert.equal(row.state, scenario === 'denial' ? 'denied' : 'pending');
      t.diagnostic(`Native browser negative case passed: ${scenario}; no credential issued.`);
    }
    for (const cookie of await context.cookies()) sensitive.push(cookie.value);
    for (const value of sensitive) assert.ok(!logs.includes(value), 'Secrets absent from relay logs');
    for (const line of logs.trim().split('\n')) assert.deepEqual(Object.keys(JSON.parse(line)), ['time', 'event', 'request_id', 'status']);
    await context.close();
  } catch (error) {
    // Browser errors can embed protocol URLs and form values. Assertions here
    // expose only fixed labels; never persist the browser's raw message/cause.
    t.diagnostic(JSON.stringify({ phase, records: records.filter(r => !r.path.startsWith('/assets/')), network_error: /net::[A-Z_]+/.exec(error.message)?.[0] ?? null }));
    throw new Error(`Browser handoff failed during ${phase}${error.code === 'ERR_ASSERTION' ? ': ' + error.message.split('\n')[0] : ''}`);
  } finally {
    for (const [method, original] of Object.entries(originals)) relay[method] = original;
    relay.transport.json = originalTransport;
    if (browser) await browser.close();
    for (const socket of sockets) socket.destroy();
    for (const listener of [tunnel, proxy, server]) if (listener) { listener.closeAllConnections(); await new Promise(resolve => listener.close(resolve)); }
    await rm(dir, { recursive: true, force: true });
  }
}
