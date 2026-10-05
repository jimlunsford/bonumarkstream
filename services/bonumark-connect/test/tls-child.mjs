import https from 'node:https';
import { readFile } from 'node:fs/promises';
import { once } from 'node:events';
import assert from 'node:assert/strict';
import { pinnedRequest } from '../src/transport.mjs';
const directory = process.argv[2];
const server = https.createServer({ key: await readFile(directory + '/key.pem'), cert: await readFile(directory + '/cert.pem') }, (request, response) => {
  if (request.url === '/timeout') return;
  response.setHeader('Content-Type', 'application/json');
  response.end(request.url === '/large' ? 'x'.repeat(70000) : '{"ok":true}');
});
server.listen(0, '127.0.0.1'); await once(server, 'listening');
try {
  const base = `https://localhost:${server.address().port}`;
  const normal = await pinnedRequest(base + '/ok', ['127.0.0.1']); assert.equal(normal.status, 200);
  await assert.rejects(pinnedRequest(base + '/timeout', ['127.0.0.1'], { timeout: 40 }), { code: 'target_site_timeout' });
  await assert.rejects(pinnedRequest(base + '/large', ['127.0.0.1'], { maxBytes: 4096 }), { code: 'target_site_response_invalid' });
  await assert.rejects(pinnedRequest(`https://wrong.example.com:${server.address().port}/ok`, ['127.0.0.1']), { code: 'target_site_unavailable' });
  process.stdout.write('Pinned TLS, hostname verification, response size and deadline passed.\n');
} finally { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
