CREATE TABLE IF NOT EXISTS accounts (
  account_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  authentication_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  status VARCHAR(16) NOT NULL, created_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS account_sessions (
  session_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  account_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  secret_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  expires_at BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (account_id) REFERENCES accounts(account_id), INDEX expires (expires_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS site_bindings (
  site_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  canonical_origin VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  base_path VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS connections (
  connection_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  account_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  session_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  site_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  canonical_origin VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  base_path VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  client_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  endpoints TEXT NOT NULL, scopes TEXT NOT NULL, state_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  verifier_ciphertext TEXT NULL, credential_ciphertext TEXT NULL,
  grant_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  state VARCHAR(32) NOT NULL, created_at BIGINT UNSIGNED NOT NULL, expires_at BIGINT UNSIGNED NOT NULL,
  credential_expires_at BIGINT UNSIGNED NULL, confirmed_at BIGINT UNSIGNED NULL,
  last_health_at BIGINT UNSIGNED NULL, failures INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (account_id) REFERENCES accounts(account_id), INDEX account_connections (account_id, created_at), INDEX pending_expiry (state, expires_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  window_id BIGINT UNSIGNED NOT NULL, attempts INT UNSIGNED NOT NULL, INDEX expired (window_id)
) ENGINE=InnoDB;
