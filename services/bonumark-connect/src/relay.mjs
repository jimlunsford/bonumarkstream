import { randomBytes } from 'node:crypto';
import { baseURL, digest, endpoint, equal, isUUID, pkce, sameBinding, scopes, secret, uuid, SafeError } from './security.mjs';

const now = () => Math.floor(Date.now() / 1000);
const context = (row, purpose) => JSON.stringify([purpose, row.connection_id, row.account_id, row.site_id, row.canonical_origin, row.base_path, row.client_id]);
const summary = row => ({ connection_id: row.connection_id, site_id: row.site_id, canonical_origin: row.canonical_origin, base_path: row.base_path, state: row.state, scopes: JSON.parse(row.scopes), expires_at: row.credential_expires_at ? Number(row.credential_expires_at) : null, last_health_at: row.last_health_at ? Number(row.last_health_at) : null });
const target = (row, name) => endpoint(JSON.parse(row.endpoints)[name], row);

export class Relay {
  constructor(db, transport, keys, config) { this.db = db; this.transport = transport; this.keys = keys; this.config = config; }
  async provisionAccount() {
    const account_id = uuid(); const token = `bmca_${secret()}`;
    await this.db.query('INSERT INTO accounts (account_id, authentication_hash, status, created_at) VALUES (?, ?, ?, ?)', [account_id, digest('account', token), 'active', now()]);
    return { account_id, token };
  }
  async login(token) {
    if (typeof token !== 'string' || !/^bmca_[a-f0-9]{64}$/.test(token)) throw new SafeError('authentication_required', 401);
    const [account] = await this.db.query('SELECT account_id FROM accounts WHERE authentication_hash = ? AND status = ?', [digest('account', token), 'active']);
    if (!account) throw new SafeError('authentication_required', 401);
    return this.db.transaction(async q => {
      await q('SELECT account_id FROM accounts WHERE account_id = ? FOR UPDATE', [account.account_id]);
      await q('DELETE FROM account_sessions WHERE account_id = ? AND expires_at <= ?', [account.account_id, now()]);
      const [count] = await q('SELECT COUNT(*) AS n FROM account_sessions WHERE account_id = ?', [account.account_id]);
      if (count.n >= 10) throw new SafeError('rate_limited', 429);
      const token = secret(); const session_id = uuid();
      await q('INSERT INTO account_sessions (session_id, account_id, secret_hash, expires_at) VALUES (?, ?, ?, ?)', [session_id, account.account_id, digest('session', token), now() + 28800]);
      return { token, session_id, account_id: account.account_id };
    });
  }
  async authenticate(token) {
    if (!/^[a-f0-9]{64}$/.test(token ?? '')) throw new SafeError('authentication_required', 401);
    const [session] = await this.db.query('SELECT s.session_id, s.account_id FROM account_sessions s JOIN accounts a ON a.account_id = s.account_id WHERE s.secret_hash = ? AND s.expires_at > ? AND a.status = ?', [digest('session', token), now(), 'active']);
    if (!session) throw new SafeError('authentication_required', 401);
    await this.db.limit(`account:${session.account_id}`, this.config.accountPerMinute);
    return session;
  }
  async start(session, url, requested = ['status:read']) {
    requested = scopes(requested);
    await this.db.limit(`discovery:${session.account_id}`, this.config.discoveryPerMinute);
    const site = await this.transport.discover(url);
    if (requested.some(s => !site.supported_scopes.includes(s))) throw new SafeError('invalid_scope');
    const [binding] = await this.db.query('SELECT * FROM site_bindings WHERE site_id = ?', [site.site_id]);
    if (binding && !sameBinding(site, binding)) throw new SafeError('site_identity_conflict', 409);
    const connection_id = uuid(); const state = secret(); const verifier = randomBytes(32).toString('base64url');
    const row = { connection_id, account_id: session.account_id, ...site, client_id: this.config.clientId };
    await this.db.transaction(async q => {
      const [account] = await q('SELECT status FROM accounts WHERE account_id = ? FOR UPDATE', [session.account_id]);
      if (account?.status !== 'active') throw new SafeError('authentication_required', 401);
      const [count] = await q('SELECT COUNT(*) AS n FROM connections WHERE account_id = ?', [session.account_id]);
      if (count.n >= 100) throw new SafeError('connection_limit', 409);
      const existing = await q('SELECT connection_id FROM connections WHERE account_id = ? AND site_id = ? AND state IN (?, ?, ?, ?) FOR UPDATE', [session.account_id, site.site_id, 'pending', 'exchanging', 'awaiting_confirmation', 'active']);
      if (existing.length) throw new SafeError('disconnect_or_finish_existing_connection', 409);
      await q('INSERT INTO connections (connection_id, account_id, session_id, site_id, canonical_origin, base_path, client_id, endpoints, scopes, state_hash, verifier_ciphertext, state, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        connection_id, session.account_id, session.session_id, site.site_id, site.canonical_origin, site.base_path, this.config.clientId, JSON.stringify(site.endpoints), JSON.stringify(requested), digest('state', state), this.keys.seal(verifier, context(row, 'verifier')), 'pending', now(), now() + 600,
      ]);
    });
    const auth = new URL(site.endpoints.authorize);
    auth.search = new URLSearchParams({ client_id: this.config.clientId, redirect_uri: this.config.callbackUrl, site_id: site.site_id, canonical_origin: site.canonical_origin, base_path: site.base_path, state, code_challenge: pkce(verifier), code_challenge_method: 'S256', scope: requested.join(' ') }).toString();
    return { connection_id, authorization_url: auth.href, site: baseURL(site) };
  }
  async callback(session, input) {
    if (!/^[a-f0-9]{64}$/.test(input.state ?? '')) throw new SafeError('authorization_state_invalid');
    const row = await this.db.transaction(async q => {
      const [row] = await q('SELECT * FROM connections WHERE state_hash = ? FOR UPDATE', [digest('state', input.state)]);
      if (!row || row.account_id !== session.account_id || row.session_id !== session.session_id || row.state !== 'pending' || Number(row.expires_at) <= now()
        || input.iss !== baseURL(row) || input.site_id !== row.site_id) throw new SafeError('authorization_state_invalid');
      if (input.error === 'access_denied') {
        await q('UPDATE connections SET state = ?, verifier_ciphertext = NULL WHERE connection_id = ?', ['denied', row.connection_id]);
        return { denied: true };
      }
      if (typeof input.code !== 'string' || !/^[a-f0-9]{64}$/.test(input.code)) throw new SafeError('authorization_state_invalid');
      // Claim once before network dispatch. A lost exchange is never replayed.
      await q('UPDATE connections SET state = ?, verifier_ciphertext = NULL WHERE connection_id = ?', ['exchanging', row.connection_id]);
      return row;
    });
    if (row.denied) return { denied: true };
    let credential;
    try {
      const verifier = this.keys.open(row.verifier_ciphertext, context(row, 'verifier'));
      await this.db.limit(`outbound:${session.account_id}`, this.config.outboundPerMinute);
      const data = await this.transport.json(target(row, 'token'), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code: input.code, code_verifier: verifier, client_id: row.client_id, redirect_uri: this.config.callbackUrl, site_id: row.site_id, canonical_origin: row.canonical_origin, base_path: row.base_path }) });
      this.validateGrant(data, row, false);
      if (typeof data.access_token !== 'string' || !/^bmsc_[a-f0-9]{64}$/.test(data.access_token) || data.token_type !== 'Bearer') throw new SafeError('target_site_response_invalid', 502, 'relay', 'unknown');
      credential = data.access_token;
      await this.db.transaction(async q => {
        const [current] = await q('SELECT state FROM connections WHERE connection_id = ? FOR UPDATE', [row.connection_id]);
        if (current?.state !== 'exchanging' || Number(row.expires_at) <= now()) throw new SafeError('authorization_state_invalid');
        // Only a successful exact-bound exchange may reserve a global site identity.
        await q('INSERT INTO site_bindings (site_id, canonical_origin, base_path) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE site_id = VALUES(site_id)', [row.site_id, row.canonical_origin, row.base_path]);
        const [binding] = await q('SELECT * FROM site_bindings WHERE site_id = ? FOR UPDATE', [row.site_id]);
        if (!sameBinding(binding, row)) throw new SafeError('site_identity_conflict', 409);
        await q('UPDATE connections SET state = ?, grant_id = ?, scopes = ?, credential_ciphertext = ?, credential_expires_at = ? WHERE connection_id = ?', ['awaiting_confirmation', data.grant_id, JSON.stringify(data.scopes), this.keys.seal(credential, context(row, 'credential')), data.expires_at, row.connection_id]);
      });
      return { connection_id: row.connection_id, awaiting_confirmation: true };
    } catch (error) {
      // A committed site grant can outlive response loss. Never claim it revoked
      // without a site response. If we received the credential, attempt once.
      if (credential) {
        try { await this.transport.json(target(row, 'revoke'), { method: 'POST', headers: { Authorization: `Bearer ${credential}`, 'X-Bonumark-Client': row.client_id, 'Content-Type': 'application/json' }, body: '{}' }); } catch { /* Owner can revoke locally. */ }
      }
      await this.db.query('UPDATE connections SET state = ?, credential_ciphertext = NULL WHERE connection_id = ? AND state = ?', ['failed', row.connection_id, 'exchanging']);
      throw error instanceof SafeError ? error : new SafeError('server_error', 500, 'relay', 'unknown');
    }
  }
  validateGrant(data, row, existing = true) {
    if (!sameBinding(data, row) || data.client_id !== row.client_id || !isUUID(data.grant_id) || (existing && data.grant_id !== row.grant_id)) throw new SafeError('site_origin_changed', 409, 'relay', 'unknown');
    const granted = scopes(data.scopes);
    if (granted.some(s => !JSON.parse(row.scopes).includes(s)) || !Number.isSafeInteger(data.expires_at) || data.expires_at <= now() || data.expires_at > now() + 7776000) throw new SafeError('target_site_response_invalid', 502, 'relay', 'unknown');
  }
  async get(session, id) {
    if (!isUUID(id)) throw new SafeError('connection_not_found', 404);
    const [row] = await this.db.query('SELECT * FROM connections WHERE connection_id = ? AND account_id = ?', [id, session.account_id]);
    if (!row) throw new SafeError('connection_not_found', 404);
    return row;
  }
  async confirm(session, id) {
    await this.db.transaction(async q => {
      const [row] = await q('SELECT * FROM connections WHERE connection_id = ? AND account_id = ? FOR UPDATE', [id, session.account_id]);
      if (!row || row.session_id !== session.session_id || row.state !== 'awaiting_confirmation' || Number(row.expires_at) <= now()) throw new SafeError('authorization_state_invalid');
      await q('UPDATE connections SET state = ?, confirmed_at = ? WHERE connection_id = ?', ['active', now(), id]);
    });
    return { connection_id: id, confirmed: true };
  }
  async health(session, id) {
    const row = await this.get(session, id);
    if (row.state !== 'active' || Number(row.credential_expires_at) <= now()) throw new SafeError('connection_unavailable', 409);
    if (Number(row.next_attempt_at) > now()) throw new SafeError('rate_limited', 429);
    await this.db.limit(`outbound:${session.account_id}`, this.config.outboundPerMinute);
    try {
      const credential = this.keys.open(row.credential_ciphertext, context(row, 'credential'));
      const data = await this.transport.json(target(row, 'status'), { headers: { Authorization: `Bearer ${credential}`, 'X-Bonumark-Client': row.client_id } });
      this.validateGrant(data, row);
      if (data.connected !== true) throw new SafeError('target_site_response_invalid', 502);
      await this.db.query('UPDATE connections SET scopes = ?, last_health_at = ?, failures = 0, next_attempt_at = 0 WHERE connection_id = ? AND state = ?', [JSON.stringify(data.scopes), now(), id, 'active']);
      return { connection_id: id, connected: true, site_id: row.site_id, scopes: data.scopes };
    } catch (error) {
      const code = error instanceof SafeError ? error.code : 'target_site_unavailable';
      const state = code === 'invalid_bearer_token' ? 'revoked_or_expired' : code === 'site_origin_changed' ? 'suspended' : 'active';
      await this.db.query('UPDATE connections SET state = ?, failures = failures + 1, next_attempt_at = ? WHERE connection_id = ? AND state = ?', [state, now() + Math.min(60, 2 ** Math.min(row.failures + 1, 6)), id, 'active']);
      throw error instanceof SafeError ? error : new SafeError('target_site_unavailable', 503);
    }
  }
  async disconnect(session, id) {
    const row = await this.get(session, id);
    // Disable routing before dispatch. It stays disabled during an outage.
    await this.db.query('UPDATE connections SET state = ?, verifier_ciphertext = NULL WHERE connection_id = ?', ['disconnect_pending', id]);
    let revoked = false;
    if (row.credential_ciphertext) {
      await this.db.limit(`outbound:${session.account_id}`, this.config.outboundPerMinute);
      try {
        const credential = this.keys.open(row.credential_ciphertext, context(row, 'credential'));
        const result = await this.transport.json(target(row, 'revoke'), { method: 'POST', headers: { Authorization: `Bearer ${credential}`, 'X-Bonumark-Client': row.client_id, 'Content-Type': 'application/json' }, body: '{}' });
        revoked = result.revoked === true;
      } catch (error) { revoked = error instanceof SafeError && error.code === 'invalid_bearer_token'; }
    }
    if (revoked || !row.credential_ciphertext) await this.db.query('UPDATE connections SET state = ?, credential_ciphertext = NULL WHERE connection_id = ?', ['disconnected', id]);
    return { connection_id: id, routing_disabled: true, site_revocation_confirmed: revoked, owner_review_required: !revoked };
  }
  async list(session) {
    const rows = await this.db.query('SELECT * FROM connections WHERE account_id = ? ORDER BY created_at DESC, connection_id DESC LIMIT 100', [session.account_id]);
    return rows.map(row => summary({ ...row, state: ['pending', 'exchanging', 'awaiting_confirmation'].includes(row.state) && Number(row.expires_at) <= now() ? 'abandoned' : row.state }));
  }
  async maintenance() {
    // Bounded maintenance, no automatic retry of authorization or credential calls.
    await this.db.query('DELETE FROM account_sessions WHERE expires_at <= ? LIMIT 100', [now()]);
    await this.db.query('UPDATE connections SET state = ?, verifier_ciphertext = NULL WHERE state IN (?, ?) AND expires_at <= ? LIMIT 100', ['abandoned', 'pending', 'exchanging', now()]);
    const rows = await this.db.query('SELECT * FROM connections WHERE state = ? AND expires_at <= ? LIMIT 10', ['awaiting_confirmation', now()]);
    for (const row of rows) await this.disconnect({ account_id: row.account_id }, row.connection_id);
    // Historical summaries retained for 90 days; global site bindings survive.
    await this.db.query('DELETE FROM connections WHERE state IN (?, ?, ?, ?) AND created_at < ? LIMIT 100', ['disconnected', 'denied', 'failed', 'abandoned', now() - 90 * 86400]);
  }
  async rotateKeys() {
    let last = '';
    for (;;) {
      const rows = await this.db.query('SELECT connection_id FROM connections WHERE connection_id > ? ORDER BY connection_id LIMIT 100', [last]);
      if (!rows.length) return;
      for (const { connection_id } of rows) {
        await this.db.transaction(async q => {
          const [row] = await q('SELECT * FROM connections WHERE connection_id = ? FOR UPDATE', [connection_id]);
          if (!row) return;
          for (const [column, purpose] of [['verifier_ciphertext', 'verifier'], ['credential_ciphertext', 'credential']]) {
            if (row[column]) await q(`UPDATE connections SET ${column} = ? WHERE connection_id = ?`, [this.keys.seal(this.keys.open(row[column], context(row, purpose)), context(row, purpose)), connection_id]);
          }
        });
        last = connection_id;
      }
    }
  }
}

export function csrf(cookie) { return digest('csrf', cookie); }
export function verifyCSRF(cookie, value) { if (!equal(csrf(cookie), value)) throw new SafeError('csrf_invalid', 403); }
