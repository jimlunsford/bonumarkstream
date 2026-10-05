import { readFile, stat } from 'node:fs/promises';
import { isAbsolute } from 'node:path';
import { address, baseURL, keyring, SafeError } from './security.mjs';

async function privateJSON(path) {
  if (typeof path !== 'string' || !isAbsolute(path)) throw new SafeError('invalid_configuration', 503);
  const info = await stat(path);
  if (!info.isFile() || (info.mode & 0o077) || info.size > 16384) throw new SafeError('invalid_configuration', 503);
  return JSON.parse(await readFile(path, 'utf8'));
}
export async function loadConfig(path) {
  const c = await privateJSON(path);
  const a = address(c.baseUrl);
  if (a.base_path || baseURL(a) !== c.baseUrl || c.callbackUrl !== `${c.baseUrl}/callback` || !/^[a-zA-Z0-9_-]{16,80}$/.test(c.clientId) || process.env.NODE_TLS_REJECT_UNAUTHORIZED === '0') throw new SafeError('invalid_configuration', 503);
  const positive = (name, fallback, max) => {
    const n = c[name] ?? fallback;
    if (!Number.isInteger(n) || n < 1 || n > max) throw new SafeError('invalid_configuration', 503);
    return n;
  };
  if (!c.database || !['127.0.0.1', 'localhost', '::1'].includes(c.database.host) || typeof c.database.database !== 'string' || !/^bmc_[a-z0-9_]{1,40}$/.test(c.database.database) || typeof c.database.user !== 'string' || typeof c.database.password !== 'string') throw new SafeError('invalid_configuration', 503);
  const k = await privateJSON(c.keyFile);
  return { baseUrl: c.baseUrl, callbackUrl: c.callbackUrl, clientId: c.clientId, keys: keyring(k.keys, k.active),
    database: { host: c.database.host, port: c.database.port ?? 3306, database: c.database.database, user: c.database.user, password: c.database.password },
    port: positive('port', 8088, 65535), accountPerMinute: positive('accountPerMinute', 60, 120), discoveryPerMinute: positive('discoveryPerMinute', 5, 20), outboundPerMinute: positive('outboundPerMinute', 20, 60), maxConcurrent: positive('maxConcurrent', 16, 64),
    buildId: typeof c.buildId === 'string' && /^[a-f0-9]{40}$/.test(c.buildId) ? c.buildId : 'development' };
}
