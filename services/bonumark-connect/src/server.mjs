import http from 'node:http';
import { csrf, verifyCSRF } from './relay.mjs';
import { createLoginCSRF } from './login-csrf.mjs';
import { safeLog, uuid, SafeError } from './security.mjs';

const escape = value => String(value).replace(/[&<>"']/g, x => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[x]);
const page = (title, body) => `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${escape(title)}</title></head><body><main><h1>${escape(title)}</h1>${body}</main></body></html>`;
// Presentation only: these values never select an authority path or an endpoint.
const notices = Object.freeze({
  confirmed: 'Connection confirmed.',
  healthy: 'Connection health check passed.',
  disconnected: 'Disconnected. Site revocation confirmed.',
  review: 'Routing disabled. Site revocation is not confirmed; owner review required.',
  confirm_failed: 'Confirmation could not be completed. Review the current connection state before taking further action.',
  health_failed: 'Connection health check did not pass. Review the current connection state.',
  disconnect_failed: 'Disconnect could not be confirmed. Routing and site revocation may be incomplete; owner review required.'
});
const actions = Object.freeze({
  pending: ['disconnect'], awaiting_confirmation: ['confirm', 'disconnect'],
  active: ['health', 'disconnect'], suspended: ['disconnect'], revoked_or_expired: ['disconnect']
});
const labels = { confirm: 'Confirm connection', health: 'Check connection', disconnect: 'Disconnect' };
// Native navigation explicitly accepts HTML. JSON stays the default, and an
// explicit application/json preference wins. Fetch metadata is not authority.
const browserResponse = request => {
  const accepted = (request.headers.accept ?? '').toLowerCase().split(',').map(part => {
    const [type, ...params] = part.trim().split(';');
    const q = params.map(p => p.trim()).find(p => p.startsWith('q='));
    return { type: type.trim(), quality: q === undefined ? 1 : Number(q.slice(2)) };
  }).filter(item => item.quality > 0 && item.quality <= 1);
  return accepted.some(item => item.type === 'text/html') && !accepted.some(item => item.type === 'application/json');
};
async function body(request) {
  let size = 0; const chunks = [];
  for await (const chunk of request) { size += chunk.length; if (size > 8192) throw new SafeError('request_too_large', 413); chunks.push(chunk); }
  const raw = Buffer.concat(chunks).toString('utf8');
  if (request.headers['content-type']?.split(';')[0] === 'application/x-www-form-urlencoded') return Object.fromEntries(new URLSearchParams(raw));
  throw new SafeError('invalid_request');
}
export function createServer(relay, { log = line => process.stdout.write(line) } = {}) {
  const loginCSRF = createLoginCSRF(relay.config.baseUrl);
  let concurrent = 0;
  const server = http.createServer({ maxHeaderSize: 8192, requestTimeout: 10000, headersTimeout: 5000, keepAliveTimeout: 2000 }, async (request, response) => {
    const requestId = uuid();
    response.setHeader('Cache-Control', 'no-store'); response.setHeader('Referrer-Policy', 'no-referrer');
    response.setHeader('X-Content-Type-Options', 'nosniff'); response.setHeader('X-Frame-Options', 'DENY');
    response.setHeader('Content-Security-Policy', "default-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    let counted = false;
    const send = (value, html = false) => { response.setHeader('Content-Type', html ? 'text/html; charset=utf-8' : 'application/json'); response.end(html ? value : JSON.stringify({ ok: true, request_id: requestId, ...value })); };
    const redirect = path => { response.writeHead(303, { Location: path }); response.end(); };
    try {
      if (concurrent >= relay.config.maxConcurrent) throw new SafeError('rate_limited', 429);
      concurrent++; counted = true;
      if (request.headers.host !== new URL(relay.config.baseUrl).host) throw new SafeError('invalid_request');
      const url = new URL(request.url, relay.config.baseUrl);
      if (url.origin !== relay.config.baseUrl || url.href.length > 4096) throw new SafeError('invalid_request');
      if (url.pathname === '/healthz' && request.method === 'GET') {
        await relay.db.query('SELECT 1'); send({ ready: true, database_ready: true, build: relay.config.buildId }); return;
      }
      await relay.db.limit('service', 300);
      // Only login may omit Origin, and only with its independent cookie/form
      // proof below. Explicit null/foreign/malformed Origins always fail closed.
      if (request.method === 'POST' && request.headers.origin !== relay.config.baseUrl
        && !(url.pathname === '/session' && request.headers.origin === undefined)) throw new SafeError('csrf_invalid', 403);
      if (url.pathname === '/login' && request.method === 'GET') {
        const login = loginCSRF.issue();
        const html = page('Sign in to Bonumark Connect', `<p>Use your separately provisioned development account credential. Do not enter your Bonumark site password.</p><form method="post" action="/session"><input type="hidden" name="login_csrf" value="${login.proof}"><label for="credential">Development account credential</label><input id="credential" name="credential" type="password" autocomplete="current-password" required><button>Sign in</button></form>`);
        response.setHeader('Set-Cookie', login.cookie);
        response.setHeader('Referrer-Policy', 'same-origin');
        send(html, true); return;
      }
      if (url.pathname === '/session' && request.method === 'POST') {
        // No forwarded-IP trust is needed for this development service. Global
        // and account limits supplement this conservative local proxy bucket.
        await relay.db.limit(`login:${request.socket.remoteAddress}`, 10);
        const input = await body(request); loginCSRF.verify(request.headers.cookie, input.login_csrf);
        const session = await relay.login(input.credential);
        response.setHeader('Set-Cookie', [`__Host-bmc_session=${session.token}; Secure; HttpOnly; SameSite=Lax; Path=/; Max-Age=28800`, loginCSRF.clear()]);
        redirect('/'); return;
      }
      const cookie = /(?:^|;\s*)__Host-bmc_session=([a-f0-9]{64})(?:;|$)/.exec(request.headers.cookie ?? '')?.[1];
      let session;
      try { session = await relay.authenticate(cookie); }
      catch (error) { if (request.method === 'GET' && url.pathname === '/') { redirect('/login'); return; } throw error; }
      const field = `<input type="hidden" name="csrf" value="${csrf(cookie)}">`;
      if (request.method === 'GET' && url.pathname === '/') {
        const rows = await relay.list(session);
        const notice = Object.hasOwn(notices, url.searchParams.get('notice')) ? notices[url.searchParams.get('notice')] : '';
        const html = page('Connected sites', `${notice ? `<p role="status">${notice}</p>` : ''}<form method="post" action="/connections/start">${field}<label for="site">Bonumark site URL</label><input id="site" type="url" name="site" required><button>Connect site</button></form><ul>${rows.map(r => `<li>${escape(r.canonical_origin + r.base_path)}: ${escape(r.state)} (${escape(r.scopes.join(', '))})${(Object.hasOwn(actions, r.state) ? actions[r.state] : []).map(action => `<form method="post" action="/connections/${escape(r.connection_id)}/${action}">${field}<button>${labels[action]}</button></form>`).join('')}</li>`).join('')}</ul>`);
        response.setHeader('Referrer-Policy', 'same-origin');
        send(html, true); return;
      }
      if (request.method === 'GET' && url.pathname === '/callback') {
        const result = await relay.callback(session, Object.fromEntries(url.searchParams));
        // Strip code and state from the next displayed URL and all logs.
        if (result.denied) { redirect('/'); return; }
        redirect('/'); return;
      }
      if (request.method === 'POST') {
        const input = await body(request); verifyCSRF(cookie, input.csrf);
        if (url.pathname === '/connections/start') {
          if (Object.keys(input).some(k => !['csrf', 'site'].includes(k))) throw new SafeError('invalid_request');
          const result = await relay.start(session, input.site);
          send(page('Approve on your Bonumark site', `<p>Continue to ${escape(result.site)} to sign in and approve.</p><p><a href="${escape(result.authorization_url)}" rel="noreferrer">Open site approval</a></p><p>Return here to confirm the connection afterward.</p>`), true); return;
        }
        if (Object.keys(input).some(k => k !== 'csrf')) throw new SafeError('invalid_request');
        const match = /^\/connections\/([a-f0-9-]{36})\/(confirm|health|disconnect)$/.exec(url.pathname);
        if (match) {
          const html = browserResponse(request);
          let result;
          try { result = await relay[match[2]](session, match[1]); }
          catch (error) {
            if (!html) throw error;
            redirect('/?notice=' + { confirm: 'confirm_failed', health: 'health_failed', disconnect: 'disconnect_failed' }[match[2]]);
            return;
          }
          if (!html) { send(result); return; }
          const notice = match[2] === 'confirm' ? 'confirmed' : match[2] === 'health' ? 'healthy'
            : result.site_revocation_confirmed === true ? 'disconnected' : 'review';
          redirect('/?notice=' + notice); return;
        }
      }
      throw new SafeError('not_found', 404);
    } catch (error) {
      response.setHeader('Referrer-Policy', 'no-referrer');
      const safe = error instanceof SafeError ? error : new SafeError('server_error', 500, 'relay', 'unknown');
      response.statusCode = safe.status;
      response.setHeader('Content-Type', 'application/json');
      response.end(JSON.stringify({ ok: false, request_id: requestId, error: { origin: safe.origin, code: safe.code, message: 'The connection request could not be completed.', http_status: safe.status, outcome_certainty: safe.certainty } }));
    } finally {
      if (counted) concurrent--;
      safeLog(log, 'request', requestId, response.statusCode);
    }
  });
  server.maxConnections = relay.config.maxConcurrent + 8;
  return server;
}
