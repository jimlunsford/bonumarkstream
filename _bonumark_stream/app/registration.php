<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail.php';

function bms_registration_modes(): array
{
    return [
        'disabled' => 'Disabled',
        'open' => 'Open registration',
        'invite' => 'Invite only',
    ];
}

function bms_registration_role_options(): array
{
    return [
        'commenter' => 'Commenter',
    ];
}

function bms_registration_mode(): string
{
    $mode = strtolower(trim((string)bms_setting_or_config('registration_mode', 'disabled')));
    return array_key_exists($mode, bms_registration_modes()) ? $mode : 'disabled';
}

function bms_public_registration_enabled(): bool
{
    return in_array(bms_registration_mode(), ['open', 'invite'], true);
}

function bms_registration_invite_required(): bool
{
    return bms_registration_mode() === 'invite';
}

function bms_registration_default_role(): string
{
    return 'commenter';
}

function bms_registration_require_email_verification(): bool
{
    return (string)bms_setting_or_config('registration_require_email_verification', '1') === '1';
}

function bms_registration_require_admin_approval(): bool
{
    return (string)bms_setting_or_config('registration_require_admin_approval', '0') === '1';
}

function bms_registration_account_requires_admin_approval(string $role = 'commenter'): bool
{
    return bms_registration_require_admin_approval();
}

function bms_registration_honeypot_enabled(): bool
{
    return (string)bms_setting_or_config('registration_honeypot_enabled', '1') === '1';
}

function bms_registration_mail_ready(): bool
{
    if (!bms_registration_require_email_verification()) {
        return true;
    }

    $settings = bms_mail_settings();
    return (string)($settings['mail_transport'] ?? 'disabled') !== 'disabled'
        && trim((string)($settings['mail_from_email'] ?? '')) !== '';
}

function bms_registration_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $ip . '|registration|' . (string)(bms_config()['security_salt'] ?? 'bonumark'));
}

function bms_registration_attempts_path(): string
{
    return bms_root_path('tmp/registration-attempts.json');
}

function bms_registration_attempts_load(): array
{
    $path = bms_registration_attempts_path();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function bms_registration_attempts_save(array $attempts): void
{
    $cutoff = time() - 86400;
    $attempts = array_values(array_filter($attempts, static function ($attempt) use ($cutoff) {
        return is_array($attempt) && (int)($attempt['time'] ?? 0) >= $cutoff;
    }));
    bms_write_file(bms_registration_attempts_path(), json_encode($attempts, JSON_PRETTY_PRINT));
}

function bms_registration_record_attempt(string $username, string $email, bool $success): void
{
    try {
        $attempts = bms_registration_attempts_load();
        $attempts[] = [
            'username' => bms_normalize_username($username),
            'email_hash' => $email !== '' ? hash('sha256', strtolower(trim($email))) : '',
            'ip_hash' => bms_registration_ip_hash(),
            'success' => $success,
            'time' => time(),
        ];
        bms_registration_attempts_save($attempts);
    } catch (Throwable $e) {
        // Registration throttling should not expose filesystem errors.
    }
}

function bms_registration_rate_limited(string $username, string $email): bool
{
    try {
        $cutoff = time() - 3600;
        $username = bms_normalize_username($username);
        $emailHash = $email !== '' ? hash('sha256', strtolower(trim($email))) : '';
        $ipHash = bms_registration_ip_hash();
        $count = 0;

        foreach (bms_registration_attempts_load() as $attempt) {
            if (!is_array($attempt) || (int)($attempt['time'] ?? 0) < $cutoff) {
                continue;
            }
            if (!empty($attempt['success'])) {
                continue;
            }
            if (($attempt['username'] ?? '') === $username || ($attempt['ip_hash'] ?? '') === $ipHash || ($emailHash !== '' && ($attempt['email_hash'] ?? '') === $emailHash)) {
                $count++;
            }
        }
        return $count >= 8;
    } catch (Throwable $e) {
        return true;
    }
}

function bms_registration_validate_honeypot(string $value): void
{
    if (bms_registration_honeypot_enabled() && trim($value) !== '') {
        throw new RuntimeException('Registration could not be completed.');
    }
}

function bms_registration_verification_token(): string
{
    return bin2hex(random_bytes(32));
}

function bms_registration_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function bms_registration_verification_url(string $token): string
{
    return bms_url_path('account.php?action=verify&token=' . rawurlencode($token));
}

function bms_registration_resend_url(): string
{
    return bms_url_path('account.php');
}

function bms_registration_resend_identifier_hash(string $identifier): string
{
    $identifier = strtolower(trim($identifier));
    return hash('sha256', $identifier . '|verification-resend|' . (string)(bms_config()['security_salt'] ?? 'bonumark'));
}

function bms_registration_resend_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $ip . '|verification-resend|' . (string)(bms_config()['security_salt'] ?? 'bonumark'));
}

function bms_registration_resend_rate_limited(string $identifier): bool
{
    try {
        $stmt = bms_db()->prepare('SELECT COUNT(*) FROM ' . bms_table('email_verification_attempts') . ' WHERE attempted_at > (NOW() - INTERVAL 30 MINUTE) AND (identifier_hash = :identifier_hash OR ip_hash = :ip_hash)');
        $stmt->execute([
            'identifier_hash' => bms_registration_resend_identifier_hash($identifier),
            'ip_hash' => bms_registration_resend_ip_hash(),
        ]);
        return (int)$stmt->fetchColumn() >= 6;
    } catch (Throwable $e) {
        return false;
    }
}

function bms_registration_record_resend_attempt(string $identifier, bool $mailSent): void
{
    try {
        $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('email_verification_attempts') . ' (identifier_hash, ip_hash, mail_sent, attempted_at) VALUES (:identifier_hash, :ip_hash, :mail_sent, NOW())');
        $stmt->execute([
            'identifier_hash' => bms_registration_resend_identifier_hash($identifier),
            'ip_hash' => bms_registration_resend_ip_hash(),
            'mail_sent' => $mailSent ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        // Verification resend logging should not expose storage failures.
    }
}

function bms_registration_resend_generic_message(): string
{
    return 'If that account is pending email verification, a fresh verification link has been sent.';
}

function bms_registration_find_user_by_verification_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || strlen($token) < 32) {
        return null;
    }

    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('users') . ' WHERE verification_token_hash = :hash AND verification_token_expires_at >= NOW() LIMIT 1');
    $stmt->execute(['hash' => bms_registration_token_hash($token)]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function bms_registration_store_verification_token(int $userId, string $token): void
{
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('users') . ' SET verification_token_hash = :hash, verification_token_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR), updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'hash' => bms_registration_token_hash($token),
        'id' => $userId,
    ]);
}

function bms_registration_verify_token(string $token): array
{
    $user = bms_registration_find_user_by_verification_token($token);
    if (!$user) {
        throw new RuntimeException('Verification link is invalid or expired.');
    }

    $requiresApproval = bms_registration_account_requires_admin_approval((string)($user['role'] ?? 'commenter'));
    $newStatus = $requiresApproval ? 'pending' : 'active';

    $stmt = bms_db()->prepare('UPDATE ' . bms_table('users') . ' SET status = :status, email_verified_at = NOW(), verification_token_hash = NULL, verification_token_expires_at = NULL, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'status' => $newStatus,
        'id' => (int)$user['id'],
    ]);

    return bms_find_user_by_id_any((int)$user['id']) ?? $user;
}

function bms_registration_send_verification_email(array $user, string $token): void
{
    $settings = bms_mail_settings();
    $siteName = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
    $url = bms_registration_verification_url($token);
    $displayName = trim((string)($user['display_name'] ?? $user['username'] ?? 'there'));
    $body = "Hi {$displayName},\n\n"
        . "Confirm your email address to continue activating your {$siteName} account.\n\n"
        . $url . "\n\n"
        . "This link expires in 24 hours. If admin approval is required, your account will still need approval after email verification. If you did not request this account, you can ignore this email.\n";

    $message = bms_mail_message_from_settings(
        $settings,
        (string)($user['email'] ?? ''),
        'Confirm your ' . $siteName . ' account',
        $body,
        'plain_text'
    );

    bms_mail_send($settings, $message);
}

function bms_registration_normalize_invite_code(string $code): string
{
    return strtolower(trim(preg_replace('/\s+/', '', $code) ?? ''));
}

function bms_registration_invite_code_hash(string $code): string
{
    return hash('sha256', bms_registration_normalize_invite_code($code));
}

function bms_registration_generate_invite_code(): string
{
    return 'bm-' . strtolower(bin2hex(random_bytes(3))) . '-' . strtolower(bin2hex(random_bytes(3))) . '-' . strtolower(bin2hex(random_bytes(3)));
}

function bms_registration_invite_hint(string $code): string
{
    $normalized = bms_registration_normalize_invite_code($code);
    if (strlen($normalized) <= 10) {
        return $normalized;
    }
    return substr($normalized, 0, 5) . '...' . substr($normalized, -5);
}

function bms_registration_create_invite(string $label, int $maxUses, string $expiresAt = ''): array
{
    bms_require_capability('manage_settings');

    $label = trim($label);
    $maxUses = max(0, $maxUses);
    $expiresAt = trim($expiresAt);
    $expiresSql = null;

    if ($expiresAt !== '') {
        $timestamp = strtotime($expiresAt);
        if ($timestamp === false) {
            throw new RuntimeException('Invite expiration date is not valid.');
        }
        $expiresSql = date('Y-m-d H:i:s', $timestamp);
    }

    $code = bms_registration_generate_invite_code();
    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('registration_invites') . ' (code_hash, code_hint, label, max_uses, used_count, expires_at, status, created_by, created_at, updated_at) VALUES (:code_hash, :code_hint, :label, :max_uses, 0, :expires_at, :status, :created_by, NOW(), NOW())');
    $stmt->execute([
        'code_hash' => bms_registration_invite_code_hash($code),
        'code_hint' => bms_registration_invite_hint($code),
        'label' => $label,
        'max_uses' => $maxUses,
        'expires_at' => $expiresSql,
        'status' => 'active',
        'created_by' => (int)(bms_current_user()['id'] ?? 0) ?: null,
    ]);

    return [
        'id' => (int)bms_db()->lastInsertId(),
        'code' => $code,
        'hint' => bms_registration_invite_hint($code),
    ];
}

function bms_registration_list_invites(): array
{
    try {
        $stmt = bms_db()->query('SELECT * FROM ' . bms_table('registration_invites') . ' ORDER BY status ASC, created_at DESC, id DESC');
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function bms_registration_revoke_invite(int $id): void
{
    bms_require_capability('manage_settings');
    if ($id < 1) {
        throw new RuntimeException('Invite code was not found.');
    }
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('registration_invites') . ' SET status = :status, updated_at = NOW() WHERE id = :id');
    $stmt->execute(['status' => 'revoked', 'id' => $id]);
}

function bms_registration_find_invite_by_code(string $code): ?array
{
    $normalized = bms_registration_normalize_invite_code($code);
    if ($normalized === '') {
        return null;
    }
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('registration_invites') . ' WHERE code_hash = :hash LIMIT 1');
    $stmt->execute(['hash' => bms_registration_invite_code_hash($normalized)]);
    $invite = $stmt->fetch();
    return is_array($invite) ? $invite : null;
}

function bms_registration_invite_is_expired(array $invite): bool
{
    $expiresAt = trim((string)($invite['expires_at'] ?? ''));
    return $expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time();
}

function bms_registration_validate_invite_code(string $code): array
{
    $invite = bms_registration_find_invite_by_code($code);
    if (!$invite) {
        throw new RuntimeException('Invite code is invalid.');
    }
    if ((string)($invite['status'] ?? 'active') !== 'active') {
        throw new RuntimeException('Invite code is no longer active.');
    }
    if (bms_registration_invite_is_expired($invite)) {
        throw new RuntimeException('Invite code has expired.');
    }
    $maxUses = (int)($invite['max_uses'] ?? 1);
    $usedCount = (int)($invite['used_count'] ?? 0);
    if ($maxUses > 0 && $usedCount >= $maxUses) {
        throw new RuntimeException('Invite code has already been used.');
    }
    return $invite;
}

function bms_registration_mark_invite_used(array $invite): void
{
    $id = (int)($invite['id'] ?? 0);
    if ($id < 1) {
        return;
    }
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('registration_invites') . ' SET used_count = used_count + 1, last_used_at = NOW(), updated_at = NOW() WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

function bms_registration_create_public_account(string $username, string $displayName, string $email, string $password, string $confirmPassword, string $honeypot = '', string $inviteCode = ''): array
{
    if (!bms_public_registration_enabled()) {
        throw new RuntimeException('Public registration is currently closed.');
    }

    bms_registration_validate_honeypot($honeypot);

    if (bms_registration_rate_limited($username, $email)) {
        throw new RuntimeException('Too many registration attempts. Try again later.');
    }

    try {
        if ($password !== $confirmPassword) {
            throw new RuntimeException('Password and confirmation do not match.');
        }

        $username = bms_normalize_username($username);
        $displayName = trim($displayName);
        $email = strtolower(trim($email));
        $invite = null;

        if (bms_registration_invite_required() || trim($inviteCode) !== '') {
            $invite = bms_registration_validate_invite_code($inviteCode);
        }

        $role = 'commenter';
        $requiresVerification = bms_registration_require_email_verification();
        $requiresApproval = bms_registration_account_requires_admin_approval($role);

        if ($requiresVerification && $email === '') {
            throw new RuntimeException('Email is required so the account can be verified.');
        }

        if ($requiresVerification && !bms_registration_mail_ready()) {
            throw new RuntimeException('Registration requires email verification, but mail is not configured yet.');
        }

        $status = ($requiresVerification || $requiresApproval) ? 'pending' : 'active';
        $markEmailVerified = !$requiresVerification;
        $user = bms_create_user($username, $displayName, $email, $role, $password, $status, $markEmailVerified);
        $token = '';
        $mailError = '';

        if ($invite) {
            bms_registration_mark_invite_used($invite);
        }

        if ($requiresVerification) {
            $token = bms_registration_verification_token();
            bms_registration_store_verification_token((int)$user['id'], $token);
            $user = bms_find_user_by_id_any((int)$user['id']) ?? $user;
            try {
                bms_registration_send_verification_email($user, $token);
            } catch (Throwable $e) {
                $mailError = $e->getMessage();
            }
        }

        bms_registration_record_attempt($username, $email, true);

        return [
            'user' => $user,
            'requires_verification' => $requiresVerification,
            'requires_approval' => $requiresApproval,
            'mail_error' => $mailError,
        ];
    } catch (Throwable $e) {
        bms_registration_record_attempt($username, $email, false);
        throw $e;
    }
}

function bms_registration_resend_verification(string $usernameOrEmail): string
{
    if (!bms_registration_require_email_verification()) {
        return bms_registration_resend_generic_message();
    }

    if (!bms_registration_mail_ready()) {
        throw new RuntimeException('Mail is not configured for verification emails yet.');
    }

    $needle = trim($usernameOrEmail);
    if ($needle === '') {
        throw new RuntimeException('Enter the username or email address for the pending account.');
    }

    if (bms_registration_resend_rate_limited($needle)) {
        throw new RuntimeException('Too many verification email requests. Try again later.');
    }

    $mailSent = false;
    try {
        $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('users') . ' WHERE status = :status AND (username = :username OR email = :email) LIMIT 1');
        $stmt->execute([
            'status' => 'pending',
            'username' => bms_normalize_username($needle),
            'email' => strtolower($needle),
        ]);
        $user = $stmt->fetch();

        if (is_array($user) && trim((string)($user['email_verified_at'] ?? '')) === '') {
            $token = bms_registration_verification_token();
            bms_registration_store_verification_token((int)$user['id'], $token);
            $user = bms_find_user_by_id_any((int)$user['id']) ?? $user;
            bms_registration_send_verification_email($user, $token);
            $mailSent = true;
        }
    } finally {
        bms_registration_record_resend_attempt($needle, $mailSent);
    }

    return bms_registration_resend_generic_message();
}
