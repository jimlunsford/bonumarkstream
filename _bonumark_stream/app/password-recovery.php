<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail.php';

function mp_password_recovery_mail_ready(): bool
{
    $settings = mp_mail_settings();
    return (string)($settings['mail_transport'] ?? 'disabled') !== 'disabled'
        && trim((string)($settings['mail_from_email'] ?? '')) !== '';
}

function mp_password_recovery_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $ip . '|password-recovery|' . (string)(mp_config()['security_salt'] ?? 'bonumark'));
}

function mp_password_recovery_identifier_hash(string $identifier): string
{
    $identifier = strtolower(trim($identifier));
    return hash('sha256', $identifier);
}

function mp_password_recovery_public_message(): string
{
    return 'If an account matches that information, a password reset email has been sent.';
}

function mp_password_recovery_cleanup(): void
{
    try {
        mp_db()->exec('DELETE FROM ' . mp_table('password_reset_attempts') . ' WHERE attempted_at < (NOW() - INTERVAL 24 HOUR)');
        mp_db()->exec('DELETE FROM ' . mp_table('password_reset_tokens') . ' WHERE (used_at IS NOT NULL AND used_at < (NOW() - INTERVAL 24 HOUR)) OR expires_at < (NOW() - INTERVAL 24 HOUR)');
    } catch (Throwable $e) {
        // Cleanup should never block account recovery.
    }
}

function mp_password_recovery_record_attempt(string $identifier, bool $mailSent): void
{
    try {
        $stmt = mp_db()->prepare('INSERT INTO ' . mp_table('password_reset_attempts') . ' (identifier_hash, ip_hash, mail_sent, attempted_at) VALUES (:identifier_hash, :ip_hash, :mail_sent, NOW())');
        $stmt->execute([
            'identifier_hash' => mp_password_recovery_identifier_hash($identifier),
            'ip_hash' => mp_password_recovery_ip_hash(),
            'mail_sent' => $mailSent ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        // Rate-limit logging failures should not expose internals.
    }
}

function mp_password_recovery_rate_limited(string $identifier): bool
{
    try {
        $identifierHash = mp_password_recovery_identifier_hash($identifier);
        $ipHash = mp_password_recovery_ip_hash();
        $stmt = mp_db()->prepare('SELECT COUNT(*) FROM ' . mp_table('password_reset_attempts') . ' WHERE attempted_at > (NOW() - INTERVAL 1 HOUR) AND (identifier_hash = :identifier_hash OR ip_hash = :ip_hash)');
        $stmt->execute([
            'identifier_hash' => $identifierHash,
            'ip_hash' => $ipHash,
        ]);
        return (int)$stmt->fetchColumn() >= 5;
    } catch (Throwable $e) {
        return true;
    }
}

function mp_password_recovery_find_user(string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('users') . ' WHERE status = :status AND (username = :username OR email = :email) LIMIT 1');
    $stmt->execute([
        'status' => 'active',
        'username' => mp_normalize_username($identifier),
        'email' => strtolower($identifier),
    ]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function mp_password_recovery_token(): string
{
    return bin2hex(random_bytes(32));
}

function mp_password_recovery_token_hash(string $token): string
{
    return hash('sha256', trim($token));
}

function mp_password_recovery_url(string $token): string
{
    return mp_site_url('account.php?action=reset&token=' . rawurlencode($token));
}

function mp_password_recovery_store_token(int $userId, string $token): void
{
    $pdo = mp_db();
    $stmt = $pdo->prepare('UPDATE ' . mp_table('password_reset_tokens') . ' SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL');
    $stmt->execute(['user_id' => $userId]);

    $stmt = $pdo->prepare('INSERT INTO ' . mp_table('password_reset_tokens') . ' (user_id, token_hash, requested_ip_hash, expires_at, used_at, created_at) VALUES (:user_id, :token_hash, :requested_ip_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR), NULL, NOW())');
    $stmt->execute([
        'user_id' => $userId,
        'token_hash' => mp_password_recovery_token_hash($token),
        'requested_ip_hash' => mp_password_recovery_ip_hash(),
    ]);
}

function mp_password_recovery_send_email(array $user, string $token): void
{
    $settings = mp_mail_settings();
    $siteName = (string)mp_setting_or_config('site_name', 'Bonumark Stream');
    $url = mp_password_recovery_url($token);
    $displayName = trim((string)($user['display_name'] ?? $user['username'] ?? 'there'));
    $body = "Hi {$displayName},\n\n"
        . "A password reset was requested for your {$siteName} account.\n\n"
        . $url . "\n\n"
        . "This link expires in 1 hour. If you did not request this reset, you can ignore this email and your password will stay the same.\n";

    $message = mp_mail_message_from_settings(
        $settings,
        (string)($user['email'] ?? ''),
        'Reset your ' . $siteName . ' password',
        $body,
        'plain_text'
    );

    mp_mail_send($settings, $message);
}

function mp_password_recovery_request_reset(string $identifier): string
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        throw new RuntimeException('Enter your username or email address.');
    }

    if (!mp_password_recovery_mail_ready()) {
        throw new RuntimeException('Password recovery is not configured because mail is disabled. Contact the site admin.');
    }

    mp_password_recovery_cleanup();

    if (mp_password_recovery_rate_limited($identifier)) {
        mp_password_recovery_record_attempt($identifier, false);
        return mp_password_recovery_public_message();
    }

    $mailSent = false;

    try {
        $user = mp_password_recovery_find_user($identifier);
        $email = is_array($user) ? trim((string)($user['email'] ?? '')) : '';
        if ($user && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $token = mp_password_recovery_token();
            mp_password_recovery_store_token((int)$user['id'], $token);
            mp_password_recovery_send_email($user, $token);
            $mailSent = true;
        }
    } catch (Throwable $e) {
        $mailSent = false;
    }

    mp_password_recovery_record_attempt($identifier, $mailSent);
    return mp_password_recovery_public_message();
}

function mp_password_recovery_find_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || strlen($token) < 32) {
        return null;
    }

    try {
        $stmt = mp_db()->prepare('SELECT t.*, u.username, u.display_name, u.email, u.status, u.password_hash FROM ' . mp_table('password_reset_tokens') . ' t INNER JOIN ' . mp_table('users') . ' u ON u.id = t.user_id WHERE t.token_hash = :token_hash AND t.used_at IS NULL AND t.expires_at >= NOW() AND u.status = :status LIMIT 1');
        $stmt->execute([
            'token_hash' => mp_password_recovery_token_hash($token),
            'status' => 'active',
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function mp_password_recovery_token_is_valid(string $token): bool
{
    return mp_password_recovery_find_token($token) !== null;
}

function mp_password_recovery_reset_password(string $token, string $newPassword, string $confirmPassword): void
{
    $row = mp_password_recovery_find_token($token);
    if (!$row) {
        throw new RuntimeException('Password reset link is invalid or expired.');
    }

    if ($newPassword !== $confirmPassword) {
        throw new RuntimeException('New password and confirmation do not match.');
    }

    mp_validate_password_policy($newPassword, (string)($row['username'] ?? ''), (string)($row['email'] ?? ''));

    $pdo = mp_db();
    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $stmt = $pdo->prepare('UPDATE ' . mp_table('users') . ' SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id AND status = :status');
        $stmt->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => (int)$row['user_id'],
            'status' => 'active',
        ]);

        $stmt = $pdo->prepare('UPDATE ' . mp_table('password_reset_tokens') . ' SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL');
        $stmt->execute(['user_id' => (int)$row['user_id']]);

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
