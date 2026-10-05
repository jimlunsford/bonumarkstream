import { loadConfig } from './config.mjs';
import { Database } from './database.mjs';
import { Transport } from './transport.mjs';
import { Relay } from './relay.mjs';
import { createServer } from './server.mjs';
import { safeLog, uuid } from './security.mjs';

try {
  const config = await loadConfig(process.env.BMC_CONFIG_FILE);
  const db = new Database(config.database);
  await db.query('SELECT account_id FROM accounts LIMIT 1'); // Startup never creates schema.
  const relay = new Relay(db, new Transport(), config.keys, config);
  const server = createServer(relay);
  let maintaining = false;
  const interval = setInterval(async () => {
    if (maintaining) return;
    maintaining = true;
    try { await relay.maintenance(); } catch { safeLog(x => process.stdout.write(x), 'server_error', uuid(), 500); }
    finally { maintaining = false; }
  }, 60000).unref();
  let stopping = false;
  const stop = () => {
    if (stopping) return; stopping = true; clearInterval(interval);
    const deadline = setTimeout(() => { server.closeAllConnections(); process.exitCode = 1; }, 10000).unref();
    server.close(async () => { clearTimeout(deadline); await db.close(); });
  };
  process.on('SIGTERM', stop); process.on('SIGINT', stop);
  server.on('error', () => { safeLog(x => process.stderr.write(x), 'server_error', uuid(), 500); stop(); process.exitCode = 1; });
  server.listen(config.port, '127.0.0.1');
} catch {
  safeLog(x => process.stderr.write(x), 'server_error', uuid(), 500);
  process.exitCode = 1;
}
