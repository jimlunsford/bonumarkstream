import https from 'node:https';
import { Resolver } from 'node:dns/promises';
import { address, baseURL, endpoint, isUUID, publicIP, scopes, SafeError } from './security.mjs';

export async function resolvePublic(host) {
  const resolver = new Resolver({ timeout: 2000, tries: 1 });
  try {
    const results = await Promise.allSettled([resolver.resolve4(host), resolver.resolve6(host)]);
    const ips = [];
    for (const result of results) {
      if (result.status === 'fulfilled') ips.push(...result.value);
      else if (!['ENODATA', 'ENOTFOUND'].includes(result.reason?.code)) throw new SafeError('target_site_unavailable', 503);
    }
    if (!ips.length || ips.length > 32 || ips.some(ip => !publicIP(ip))) throw new SafeError('unsafe_destination');
    return ips;
  } finally { resolver.cancel(); }
}

export function pinnedRequest(url, ips, { method = 'GET', body, headers = {}, timeout = 5000, maxBytes = 65536 } = {}) {
  return new Promise((resolve, reject) => {
    const target = new URL(url);
    const ip = ips[0]; const family = ip.includes(':') ? 6 : 4;
    let settled = false;
    const finish = (error, value) => { if (settled) return; settled = true; clearTimeout(timer); error ? reject(error) : resolve(value); };
    const request = https.request(target, {
      method, headers: { Accept: 'application/json', 'Accept-Encoding': 'identity', ...headers }, agent: false,
      rejectUnauthorized: true, servername: target.hostname,
      lookup: (_host, options, callback) => options.all ? callback(null, [{ address: ip, family }]) : callback(null, ip, family),
      maxHeaderSize: 8192,
    }, response => {
      let length = 0; const chunks = [];
      response.on('data', chunk => {
        length += chunk.length;
        if (length > maxBytes) { request.destroy(); finish(new SafeError('target_site_response_invalid', 502, 'relay', method === 'POST' ? 'unknown' : 'not_dispatched')); }
        else chunks.push(chunk);
      });
      response.on('end', () => finish(null, { status: response.statusCode, headers: response.headers, body: Buffer.concat(chunks).toString('utf8') }));
      response.on('error', () => finish(new SafeError('target_site_unavailable', 503, 'relay', 'unknown')));
    });
    const timer = setTimeout(() => { request.destroy(); finish(new SafeError('target_site_timeout', 504, 'relay', 'unknown')); }, timeout);
    request.on('error', () => finish(new SafeError('target_site_unavailable', 503, 'relay', method === 'POST' ? 'unknown' : 'not_dispatched')));
    if (body) request.write(body);
    request.end();
  });
}

export class Transport {
  constructor({ resolve = resolvePublic, request = pinnedRequest, maxConcurrent = 8, timeout = 5000, maxBytes = 65536 } = {}) {
    this.resolve = resolve; this.request = request; this.maxConcurrent = maxConcurrent; this.timeout = timeout; this.maxBytes = maxBytes; this.inFlight = 0;
  }
  async fetch(url, options = {}) {
    const a = address(url);
    if (url !== baseURL(a)) throw new SafeError('invalid_site_url');
    if (this.inFlight >= this.maxConcurrent) throw new SafeError('rate_limited', 429);
    this.inFlight++;
    let timer;
    try {
      const ips = await Promise.race([this.resolve(new URL(url).hostname), new Promise((_, reject) => { timer = setTimeout(() => reject(new SafeError('target_site_timeout', 504)), 2500); })]);
      if (!Array.isArray(ips) || !ips.length || ips.some(ip => !publicIP(ip))) throw new SafeError('unsafe_destination');
      return await this.request(url, ips, { ...options, timeout: this.timeout, maxBytes: this.maxBytes });
    } finally { clearTimeout(timer); this.inFlight--; }
  }
  async json(url, options = {}) {
    const response = await this.fetch(url, options);
    if (response.status >= 300 && response.status < 400) throw new SafeError('site_origin_changed', 409, 'relay', options.method === 'POST' ? 'unknown' : 'not_dispatched');
    let data;
    try {
      if (!/^application\/json(?:;|$)/i.test(response.headers['content-type'] ?? '')) throw new Error();
      data = JSON.parse(response.body);
      if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error();
    } catch { throw new SafeError('target_site_response_invalid', 502, 'relay', 'unknown'); }
    if (response.status !== 200 || data.ok !== true) {
      const safeCodes = ['invalid_grant', 'invalid_bearer_token', 'missing_scope', 'connect_unavailable', 'site_origin_changed', 'rate_limited'];
      const code = safeCodes.includes(data.error?.code) ? data.error.code : 'target_site_unavailable';
      throw new SafeError(code, response.status >= 400 && response.status <= 599 ? response.status : 502, 'site', 'rejected');
    }
    return data;
  }
  async discover(input) {
    let url = baseURL(address(input)) + '/api/connect/v1/discovery.php';
    for (let redirects = 0; ; redirects++) {
      const response = await this.fetch(url);
      if ([301, 302, 303, 307, 308].includes(response.status)) {
        if (redirects >= 3) throw new SafeError('redirect_limit');
        const location = response.headers.location;
        if (typeof location !== 'string' || /[\\%?#\x00-\x20\x7f]/.test(location) || /(?:^|\/)\.{1,2}(?:\/|$)/.test(location)) throw new SafeError('invalid_site_url');
        url = new URL(location, url).href;
        address(url);
        continue;
      }
      let data;
      try {
        if (response.status !== 200 || !/^application\/json(?:;|$)/i.test(response.headers['content-type'] ?? '')) throw new Error();
        data = JSON.parse(response.body);
      } catch { throw new SafeError('target_site_response_invalid', 502); }
      if (!url.endsWith('/api/connect/v1/discovery.php')) throw new SafeError('site_origin_changed', 409);
      const final = address(url.slice(0, -'/api/connect/v1/discovery.php'.length));
      if (data.ok !== true || !isUUID(data.site_id) || data.canonical_origin !== final.canonical_origin || data.base_path !== final.base_path
        || !Array.isArray(data.protocol_versions) || !data.protocol_versions.includes(1)) throw new SafeError('site_origin_changed', 409);
      const implemented = scopes(data.supported_scopes);
      const endpoints = {};
      for (const key of ['authorize', 'token', 'status', 'revoke']) endpoints[key] = endpoint(data.endpoints?.[key], final);
      return { site_id: data.site_id, ...final, supported_scopes: implemented, endpoints };
    }
  }
}
