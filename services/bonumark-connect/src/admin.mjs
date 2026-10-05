import { loadConfig } from './config.mjs';
import { Database } from './database.mjs';
import { Relay } from './relay.mjs';
import { Transport } from './transport.mjs';

let db;
try {
  const config = await loadConfig(process.env.BMC_CONFIG_FILE); db = new Database(config.database);
  const relay = new Relay(db, new Transport(), config.keys, config);
  const command = process.argv[2];
  if (command === 'migrate') await db.migrate();
  else if (command === 'create-account') {
    // Operator-only, shown once. Never use in HTTP logs or test artifacts.
    const result = await relay.provisionAccount();
    process.stdout.write(JSON.stringify(result) + '\n');
  } else if (command === 'rotate-keys') await relay.rotateKeys();
  else if (command === 'maintenance') await relay.maintenance();
  else throw new Error();
} catch { process.stderr.write('Connect administration failed. Check configuration and command.\n'); process.exitCode = 1; }
finally { if (db) await db.close(); }
