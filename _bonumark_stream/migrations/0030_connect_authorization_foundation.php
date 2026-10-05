<?php
// DDL is resumable. Identity generation is an explicit owner operation, not SQL UUID().
$ascii = 'CHARACTER SET ascii COLLATE ascii_bin';
$engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
    "CREATE TABLE IF NOT EXISTS `{{prefix}}connect_control` (id TINYINT UNSIGNED PRIMARY KEY) $engine",
    "INSERT IGNORE INTO `{{prefix}}connect_control` (id) VALUES (1)",
    "CREATE TABLE IF NOT EXISTS `{{prefix}}connect_clients` (
        client_id VARCHAR(80) $ascii PRIMARY KEY, display_name VARCHAR(120) NOT NULL,
        callbacks LONGTEXT NOT NULL, status VARCHAR(16) NOT NULL, created_at BIGINT UNSIGNED NOT NULL
    ) $engine",
    "CREATE TABLE IF NOT EXISTS `{{prefix}}connect_sessions` (
        session_id CHAR(36) $ascii PRIMARY KEY, owner_id BIGINT UNSIGNED NOT NULL,
        request_data LONGTEXT NOT NULL, status VARCHAR(16) NOT NULL,
        created_at BIGINT UNSIGNED NOT NULL, expires_at BIGINT UNSIGNED NOT NULL,
        INDEX session_expiry (status, expires_at)
    ) $engine",
    "CREATE TABLE IF NOT EXISTS `{{prefix}}connect_grants` (
        grant_id CHAR(36) $ascii PRIMARY KEY, session_id CHAR(36) $ascii NOT NULL UNIQUE,
        client_id VARCHAR(80) $ascii NOT NULL, display_name VARCHAR(120) NOT NULL,
        owner_id BIGINT UNSIGNED NOT NULL, site_id CHAR(36) $ascii NOT NULL,
        canonical_origin VARCHAR(255) $ascii NOT NULL, base_path VARCHAR(512) $ascii NOT NULL,
        requested_scopes TEXT NOT NULL, scopes TEXT NOT NULL, state VARCHAR(16) NOT NULL,
        created_at BIGINT UNSIGNED NOT NULL, approved_at BIGINT UNSIGNED NOT NULL,
        updated_at BIGINT UNSIGNED NOT NULL, expires_at BIGINT UNSIGNED NOT NULL,
        last_used_at BIGINT UNSIGNED NULL, last_network_hash CHAR(64) $ascii NULL,
        revoked_at BIGINT UNSIGNED NULL, revocation_reason VARCHAR(32) NULL,
        INDEX owner_grants (owner_id, created_at), INDEX client_grants (client_id, state)
    ) $engine",
    "CREATE TABLE IF NOT EXISTS `{{prefix}}connect_codes` (
        code_hash CHAR(64) $ascii PRIMARY KEY, session_id CHAR(36) $ascii NOT NULL UNIQUE,
        grant_id CHAR(36) $ascii NOT NULL UNIQUE, expires_at BIGINT UNSIGNED NOT NULL,
        consumed_at BIGINT UNSIGNED NULL
    ) $engine",
    "CREATE TABLE IF NOT EXISTS `{{prefix}}connect_credentials` (
        credential_id CHAR(36) $ascii PRIMARY KEY, credential_hash CHAR(64) $ascii NOT NULL UNIQUE,
        grant_id CHAR(36) $ascii NOT NULL UNIQUE, created_at BIGINT UNSIGNED NOT NULL,
        expires_at BIGINT UNSIGNED NOT NULL, revoked_at BIGINT UNSIGNED NULL
    ) $engine",
    "CREATE TABLE IF NOT EXISTS `{{prefix}}connect_audit` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, request_id CHAR(36) $ascii NOT NULL,
        grant_id CHAR(36) $ascii NULL, operation VARCHAR(40) NOT NULL,
        outcome VARCHAR(16) NOT NULL, result_code VARCHAR(48) NOT NULL,
        network_hash CHAR(64) $ascii NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
        INDEX audit_grant (grant_id, id), INDEX audit_time (created_at)
    ) $engine",
    "CREATE TABLE IF NOT EXISTS `{{prefix}}connect_limits` (
        bucket CHAR(64) $ascii PRIMARY KEY, window_id BIGINT UNSIGNED NOT NULL,
        attempts INT UNSIGNED NOT NULL, INDEX limit_window (window_id)
    ) $engine",
];
