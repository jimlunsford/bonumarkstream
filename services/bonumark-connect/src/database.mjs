import mysql from 'mysql2/promise';
import { readFile } from 'node:fs/promises';
import { digest, SafeError } from './security.mjs';

export class Database {
  constructor(config) {
    this.pool = mysql.createPool({ ...config, connectionLimit: 8, waitForConnections: false, multipleStatements: false, namedPlaceholders: false, supportBigNumbers: true, bigNumberStrings: true, connectTimeout: 3000 });
  }
  async query(sql, params = []) { const [rows] = await this.pool.execute({ sql, timeout: 5000 }, params); return rows; }
  async transaction(work) {
    const connection = await this.pool.getConnection();
    try {
      await connection.query('SET SESSION innodb_lock_wait_timeout = 3');
      await connection.beginTransaction();
      const q = async (sql, values = []) => (await connection.execute({ sql, timeout: 5000 }, values))[0];
      const result = await work(q);
      await connection.commit(); return result;
    } catch (error) { await connection.rollback(); throw error; }
    finally { connection.release(); }
  }
  async migrate() {
    const sql = await readFile(new URL('../schema.sql', import.meta.url), 'utf8');
    for (const statement of sql.split(';').map(s => s.trim()).filter(Boolean)) await this.query(statement);
  }
  async limit(subject, max, now = Math.floor(Date.now() / 1000)) {
    const window = Math.floor(now / 60); const bucket = digest('rate', subject);
    await this.transaction(async q => {
      await q('DELETE FROM rate_limits WHERE window_id < ? LIMIT 100', [window - 2]);
      // Upsert acquires the row lock before the current read, including first use.
      await q('INSERT INTO rate_limits (bucket, window_id, attempts) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE bucket = VALUES(bucket)', [bucket, window]);
      const [row] = await q('SELECT * FROM rate_limits WHERE bucket = ? FOR UPDATE', [bucket]);
      const attempts = Number(row.window_id) === window ? row.attempts : 0;
      if (attempts >= max) throw new SafeError('rate_limited', 429);
      await q('UPDATE rate_limits SET window_id = ?, attempts = ? WHERE bucket = ?', [window, attempts + 1, bucket]);
    });
  }
  async close() { await this.pool.end(); }
}
