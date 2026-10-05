import { createHash, createCipheriv, createDecipheriv, randomBytes, randomUUID, timingSafeEqual } from 'node:crypto';
import { domainToASCII } from 'node:url';
import ipaddr from 'ipaddr.js';

export class SafeError extends Error {
  constructor(code, status = 400, origin = 'relay', certainty = 'not_dispatched') {
    super(code); this.code = code; this.status = status; this.origin = origin; this.certainty = certainty;
  }
}
export const uuid = () => randomUUID();
export const secret = () => randomBytes(32).toString('hex');
export const digest = (purpose, value) => createHash('sha256').update(`bonumark-connect:v1:${purpose}\0${value}`).digest('hex');
export const pkce = verifier => createHash('sha256').update(verifier).digest('base64url');
export const equal = (a, b) => typeof a === 'string' && typeof b === 'string' && Buffer.byteLength(a) === Buffer.byteLength(b) && timingSafeEqual(Buffer.from(a), Buffer.from(b));
export const isUUID = value => typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(value);
export const allowedScopes = ['media:upload', 'status:read', 'stream:draft', 'stream:read'];
export function scopes(input) {
  if (!Array.isArray(input) || input.length > 4 || input.some(s => !allowedScopes.includes(s))) throw new SafeError('invalid_scope');
  return [...new Set(input)].sort();
}
export function address(input) {
  if (typeof input !== 'string' || input.length > 768 || /[\x00-\x20\x7f\\%?#]/.test(input)) throw new SafeError('invalid_site_url');
  const match = /^https:\/\/([^/]+)(\/.*)?$/.exec(input);
  if (!match) throw new SafeError('invalid_site_url');
  let host = match[1].replace(/:443$/, '').replace(/\.$/, '');
  if (/[:@\[\]]/.test(host)) throw new SafeError('invalid_site_url');
  host = domainToASCII(host).toLowerCase();
  if (host.length > 253 || !/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(host)
    || /\.(localhost|local|internal|home|lan|onion)$/.test(host) || ipaddr.isValid(host)) throw new SafeError('invalid_site_url');
  const base_path = (match[2] ?? '').replace(/\/$/, '');
  if (base_path.length > 512 || (base_path && !/^(?:\/[A-Za-z0-9_~.!$&'()*+,;=:@-]+)+$/.test(base_path)) || /(?:^|\/)\.{1,2}(?:\/|$)/.test(base_path)) throw new SafeError('invalid_site_url');
  return { canonical_origin: `https://${host}`, base_path };
}
export const baseURL = binding => binding.canonical_origin + binding.base_path;
export const sameBinding = (a, b) => ['site_id', 'canonical_origin', 'base_path'].every(k => typeof a?.[k] === 'string' && a[k] === b?.[k]);
export function endpoint(input, binding) {
  const parsed = address(input);
  if (input !== baseURL(parsed) || parsed.canonical_origin !== binding.canonical_origin || !parsed.base_path.startsWith(`${binding.base_path}/`)) throw new SafeError('site_origin_changed', 409);
  return input;
}
export function publicIP(value) {
  try {
    const ip = ipaddr.parse(value);
    if (ip.range() !== 'unicast') return false;
    if (ip.kind() === 'ipv4') {
      return !['192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24'].some(c => ip.match(ipaddr.parseCIDR(c)));
    }
    return ip.match(ipaddr.parseCIDR('2000::/3')) && !['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'].some(c => ip.match(ipaddr.parseCIDR(c)));
  } catch { return false; }
}
export function keyring(keys, active) {
  if (!keys || typeof keys !== 'object' || !/^[a-z0-9_-]{1,32}$/.test(active) || !Object.hasOwn(keys, active)) throw new SafeError('invalid_configuration', 503);
  const ring = new Map(Object.entries(keys).map(([version, key]) => {
    if (!/^[a-z0-9_-]{1,32}$/.test(version) || typeof key !== 'string' || !/^[a-f0-9]{64}$/.test(key)) throw new SafeError('invalid_configuration', 503);
    return [version, Buffer.from(key, 'hex')];
  }));
  return {
    seal(plaintext, context) {
      const nonce = randomBytes(12);
      const cipher = createCipheriv('aes-256-gcm', ring.get(active), nonce, { authTagLength: 16 });
      cipher.setAAD(Buffer.from(`bonumark-connect:v1:${active}:${context}`));
      const ciphertext = Buffer.concat([cipher.update(plaintext, 'utf8'), cipher.final()]);
      return JSON.stringify({ v: active, n: nonce.toString('base64'), c: ciphertext.toString('base64'), t: cipher.getAuthTag().toString('base64') });
    },
    open(envelope, context) {
      try {
        const e = JSON.parse(envelope);
        if (!ring.has(e.v)) throw new Error();
        const nonce = Buffer.from(e.n, 'base64'); const tag = Buffer.from(e.t, 'base64');
        if (nonce.length !== 12 || tag.length !== 16) throw new Error();
        const cipher = createDecipheriv('aes-256-gcm', ring.get(e.v), nonce, { authTagLength: 16 });
        cipher.setAAD(Buffer.from(`bonumark-connect:v1:${e.v}:${context}`)); cipher.setAuthTag(tag);
        return Buffer.concat([cipher.update(Buffer.from(e.c, 'base64')), cipher.final()]).toString('utf8');
      } catch { throw new SafeError('credential_unavailable', 503); }
    }
  };
}

const logCodes = new Set(['request', 'startup', 'shutdown', 'server_error', 'rate_limited', 'invalid_grant', 'authorization_state_invalid', 'site_identity_conflict', 'site_origin_changed', 'target_site_unavailable', 'target_site_timeout', 'target_site_response_invalid', 'credential_unavailable']);
export function safeLog(write, event, requestId, status) {
  // Allowlisted projection, never a raw request/exception/object serialization.
  write(JSON.stringify({ time: new Date().toISOString(), event: logCodes.has(event) ? event : 'request', request_id: isUUID(requestId) ? requestId : uuid(), status: Number.isInteger(status) ? status : 500 }) + '\n');
}
