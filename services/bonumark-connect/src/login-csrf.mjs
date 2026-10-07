import { createHmac, randomBytes } from 'node:crypto';
import { digest, equal, secret, SafeError } from './security.mjs';

const name = '__Host-bmc_login';
const lifetime = 600;
const attributes = 'Secure; HttpOnly; SameSite=Strict; Path=/';
const now = () => Math.floor(Date.now() / 1000);

export function createLoginCSRF(origin) {
  // Process-local signing invalidates open login forms on restart. No anonymous
  // database/session allocation, persistent key or unbounded nonce store needed.
  const key = randomBytes(32);
  const sign = value => createHmac('sha256', key).update(`bonumark-connect:v1:login-cookie\0${origin}\0${value}`).digest('hex');
  const proof = value => digest('login-form', value);
  return {
    issue() {
      const value = `${secret()}.${now() + lifetime}`;
      const cookie = `${value}.${sign(value)}`;
      return { cookie: `${name}=${cookie}; ${attributes}; Max-Age=${lifetime}`, proof: proof(cookie) };
    },
    verify(header, supplied) {
      const cookies = (header ?? '').split(';').map(part => part.trim()).filter(part => part.startsWith(`${name}=`));
      const cookie = cookies.length === 1 ? cookies[0].slice(name.length + 1) : '';
      const match = /^([a-f0-9]{64})\.([0-9]{10})\.([a-f0-9]{64})$/.exec(cookie);
      if (!match || Number(match[2]) <= now() || Number(match[2]) > now() + lifetime
        || !equal(match[3], sign(`${match[1]}.${match[2]}`)) || !equal(supplied, proof(cookie))) {
        throw new SafeError('csrf_invalid', 403);
      }
    },
    clear: () => `${name}=; ${attributes}; Max-Age=0`
  };
}
