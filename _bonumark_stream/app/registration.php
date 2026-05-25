<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail.php';

function mp_registration_modes(): array
{
    return [
        'disabled' => 'Disabled',
        'open' => 'Open registration',
        'invite' => 'Invite only',
    ];
}

function mp_registration_role_options(): array
{
    return [
        'commenter' => 'Commenter',
        'user' => 'User',
    ];
}

function mp_registration_mode(): string
{
    $mode = strtolower(trim((string)mp_setting_or_config('registration_mode', 'disabled')));
    return array_key_exists($mode, mp_registration_modes()) ? $mode : 'disabled';
}

function mp_public_registration_enabled(): bool
{
    return in_array(mp_registration_mode(), ['open', 'invite'], true);
}

function mp_registration_invite_required(): bool
{
    return mp_registration_mode() === 'invite';
}

function mp_registration_default_role(): string
{
    $role = mp_normalize_role((string)mp_setting_or_config('registration_default_role', 'commenter'));
    return in_array($role, ['commenter', 'user'], true) ? $role : 'commenter';
}

function mp_registration_require_email_verification(): bool
{
    return (string)mp_setting_or_config('registration_require_email_verification', '1') === '1';
}

function mp_registration_require_admin_approval(): bool
{
    return (string)mp_setting_or_config('registration_require_admin_approval', '0') === '1';
}

function mp_registration_user_role_requires_approval(): bool
{
    return (string)mp_setting_or_config('registration_user_role_requires_approval', '1') === '1';
}

function mp_registration_account_requires_admin_approval(string $role): bool
{
    $role = mp_normalize_role($role);
    return mp_registration_require_admin_approval() || ($role === 'user' && mp_registration_user_role_requires_approval());
}

function mp_registration_honeypot_enabled(): bool
{
    return (string)mp_setting_or_config('registration_honeypot_enabled', '1') === '1';
}

function mp_registration_mail_ready(): bool
{
    if (!mp_registration_require_email_verification()) {
        return true;
    }

    $settings = mp_mail_settings();
    return (string)($settings['mail_transport'] ?? 'disabled') !== 'disabled'
        && trim((string)($settings['mail_from_email'] ?? '')) !== '';
}

function mp_registration_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $ip . '|registration|' . (string)(mp_config()['security_salt'] ?? 'bonumark'));
}

function mp_registration_attempts_path(): string
{
    return mp_root_path('tmp/registration-attempts.json');
}

function mp_registration_attempts_load(): array
{
    $path = mp_registration_attempts_path();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function mp_registration_attempts_save(array $attempts): void
{
    $cutoff = time() - 86400;
    $attempts = array_values(array_filter($attempts, static function ($attempt) use ($cutoff) {
        return is_array($attempt) && (int)($attempt['time'] ?? 0) >= $cutoff;
    }));
    mp_write_file(mp_registration_attempts_path(), json_encode($attempts, JSON_PRETTY_PRINT));
}

function mp_registration_record_attempt(string $username, string $email, bool $success): void
{
    try {
        $attempts = mp_registration_attempts_load();
        $attempts[] = [
            'username' => mp_normalize_username($username),
            'email_hash' => $email !== '' ? hash('sha256', strtolower(trim($email))) : '',
            'ip_hash' => mp_registration_ip_hash(),
            'success' => $success,
            'time' => time(),
        ];
        mp_registration_attempts_save($attempts);
    } catch (Throwable $e) {
        // Registration throttling should not expose filesystem errors.
    }
}

function mp_registration_rate_limited(string $username, string $email): bool
{
    try {
        $cutoff = time() - 3600;
        $username = mp_normalize_username($username);
        $emailHash = $email !== '' ? hash('sha256', strtolower(trim($email))) : '';
        $ipHash = mp_registration_ip_hash();
        $count = 0;

        foreach (mp_registration_attempts_load() as $attempt) {
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

function mp_registration_validate_honeypot(string $value): void
{
    if (mp_registration_honeypot_enabled() && trim($value) !== '') {
        throw new RuntimeException('Registration could not be completed.');
    }
}

function mp_registration_verification_token(): string
{
    return bin2hex(random_bytes(32));
}

function mp_registration_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function mp_registration_verification_url(string $token): string
{
    return mp_url_path('account.php?action=verify&token=' . rawurlencode($token));
}

function mp_registration_resend_url(): string
{
    return mp_url_path('account.php');
}

function mp_registration_resend_identifier_hash(string $identifier): string
{
    $identifier = strtolower(trim($identifier));
    return hash('sha256', $identifier . '|verification-resend|' . (string)(mp_config()['security_salt'] ?? 'bonumark'));
}

function mp_registration_resend_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $ip . '|verification-resend|' . (string)(mp_config()['security_salt'] ?? 'bonumark'));
}

function mp_registration_resend_rate_limited(string $identifier): bool
{
    try {
        $stmt = mp_db()->prepare('SELECT COUNT(*) FROM ' . mp_table('email_verification_attempts') . ' WHERE attempted_at > (NOW() - INTERVAL 30 MINUTE) AND (identifier_hash = :identifier_hash OR ip_hash = :ip_hash)');
        $stmt->execute([
            'identifier_hash' => mp_registration_resend_identifier_hash($identifier),
            'ip_hash' => mp_registration_resend_ip_hash(),
        ]);
        return (int)$stmt->fetchColumn() >= 6;
    } catch (Throwable $e) {
        return false;
    }
}

function mp_registration_record_resend_attempt(string $identifier, bool $mailSent): void
{
    try {
        $stmt = mp_db()->prepare('INSERT INTO ' . mp_table('email_verification_attempts') . ' (identifier_hash, ip_hash, mail_sent, attempted_at) VALUES (:identifier_hash, :ip_hash, :mail_sent, NOW())');
        $stmt->execute([
            'identifier_hash' => mp_registration_resend_identifier_hash($identifier),
            'ip_hash' => mp_registration_resend_ip_hash(),
            'mail_sent' => $mailSent ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        // Verification resend logging should not expose storage failures.
    }
}

function mp_registration_resend_generic_message(): string
{
    return 'If that account is pending email verification, a fresh verification link has been sent.';
}

function mp_registration_find_user_by_verification_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || strlen($token) < 32) {
        return null;
    }

    $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('users') . ' WHERE verification_token_hash = :hash AND verification_token_expires_at >= NOW() LIMIT 1');
    $stmt->execute(['hash' => mp_registration_token_hash($token)]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function mp_registration_store_verification_token(int $userId, string $token): void
{
    $stmt = mp_db()->prepare('UPDATE ' . mp_table('users') . ' SET verification_token_hash = :hash, verification_token_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR), updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'hash' => mp_registration_token_hash($token),
        'id' => $userId,
    ]);
}

function mp_registration_verify_token(string $token): array
{
    $user = mp_registration_find_user_by_verification_token($token);
    if (!$user) {
        throw new RuntimeException('Verification link is invalid or expired.');
    }

    $requiresApproval = mp_registration_account_requires_admin_approval((string)($user['role'] ?? 'commenter'));
    $newStatus = $requiresApproval ? 'pending' : 'active';

    $stmt = mp_db()->prepare('UPDATE ' . mp_table('users') . ' SET status = :status, email_verified_at = NOW(), verification_token_hash = NULL, verification_token_expires_at = NULL, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'status' => $newStatus,
        'id' => (int)$user['id'],
    ]);

    return mp_find_user_by_id_any((int)$user['id']) ?? $user;
}

function mp_registration_send_verification_email(array $user, string $token): void
{
    $settings = mp_mail_settings();
    $siteName = (string)mp_setting_or_config('site_name', 'Bonumark Stream');
    $url = mp_registration_verification_url($token);
    $displayName = trim((string)($user['display_name'] ?? $user['username'] ?? 'there'));
    $body = "Hi {$displayName},\n\n"
        . "Confirm your email address to continue activating your {$siteName} account.\n\n"
        . $url . "\n\n"
        . "This link expires in 24 hours. If admin approval is required, your account will still need approval after email verification. If you did not request this account, you can ignore this email.\n";

    $message = mp_mail_message_from_settings(
        $settings,
        (string)($user['email'] ?? ''),
        'Confirm your ' . $siteName . ' account',
        $body,
        'plain_text'
    );

    mp_mail_send($settings, $message);
}

function mp_registration_normalize_invite_code(string $code): string
{
    return strtolower(trim(preg_replace('/\s+/', '', $code) ?? ''));
}

function mp_registration_invite_code_hash(string $code): string
{
    return hash('sha256', mp_registration_normalize_invite_code($code));
}

function mp_registration_generate_invite_code(): string
{
    return 'bm-' . strtolower(bin2hex(random_bytes(3))) . '-' . strtolower(bin2hex(random_bytes(3))) . '-' . strtolower(bin2hex(random_bytes(3)));
}

function mp_registration_invite_hint(string $code): string
{
    $normalized = mp_registration_normalize_invite_code($code);
    if (strlen($normalized) <= 10) {
        return $normalized;
    }
    return substr($normalized, 0, 5) . '...' . substr($normalized, -5);
}

function mp_registration_normalize_invite_role(string $role): string
{
    $role = strtolower(trim($role));
    if ($role === '') {
        return '';
    }
    $role = mp_normalize_role($role);
    return in_array($role, ['commenter', 'user'], true) ? $role : '';
}

function mp_registration_create_invite(string $label, string $role, int $maxUses, string $expiresAt = ''): array
{
    mp_require_capability('manage_settings');

    $label = trim($label);
    $role = mp_registration_normalize_invite_role($role);
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

    $code = mp_registration_generate_invite_code();
    $stmt = mp_db()->prepare('INSERT INTO ' . mp_table('registration_invites') . ' (code_hash, code_hint, label, role, max_uses, used_count, expires_at, status, created_by, created_at, updated_at) VALUES (:code_hash, :code_hint, :label, :role, :max_uses, 0, :expires_at, :status, :created_by, NOW(), NOW())');
    $stmt->execute([
        'code_hash' => mp_registration_invite_code_hash($code),
        'code_hint' => mp_registration_invite_hint($code),
        'label' => $label,
        'role' => $role,
        'max_uses' => $maxUses,
        'expires_at' => $expiresSql,
        'status' => 'active',
        'created_by' => (int)(mp_current_user()['id'] ?? 0) ?: null,
    ]);

    return [
        'id' => (int)mp_db()->lastInsertId(),
        'code' => $code,
        'hint' => mp_registration_invite_hint($code),
    ];
}

function mp_registration_list_invites(): array
{
    try {
        $stmt = mp_db()->query('SELECT * FROM ' . mp_table('registration_invites') . ' ORDER BY status ASC, created_at DESC, id DESC');
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function mp_registration_revoke_invite(int $id): void
{
    mp_require_capability('manage_settings');
    if ($id < 1) {
        throw new RuntimeException('Invite code was not found.');
    }
    $stmt = mp_db()->prepare('UPDATE ' . mp_table('registration_invites') . ' SET status = :status, updated_at = NOW() WHERE id = :id');
    $stmt->execute(['status' => 'revoked', 'id' => $id]);
}

function mp_registration_find_invite_by_code(string $code): ?array
{
    $normalized = mp_registration_normalize_invite_code($code);
    if ($normalized === '') {
        return null;
    }
    $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('registration_invites') . ' WHERE code_hash = :hash LIMIT 1');
    $stmt->execute(['hash' => mp_registration_invite_code_hash($normalized)]);
    $invite = $stmt->fetch();
    return is_array($invite) ? $invite : null;
}

function mp_registration_invite_is_expired(array $invite): bool
{
    $expiresAt = trim((string)($invite['expires_at'] ?? ''));
    return $expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time();
}

function mp_registration_validate_invite_code(string $code): array
{
    $invite = mp_registration_find_invite_by_code($code);
    if (!$invite) {
        throw new RuntimeException('Invite code is invalid.');
    }
    if ((string)($invite['status'] ?? 'active') !== 'active') {
        throw new RuntimeException('Invite code is no longer active.');
    }
    if (mp_registration_invite_is_expired($invite)) {
        throw new RuntimeException('Invite code has expired.');
    }
    $maxUses = (int)($invite['max_uses'] ?? 1);
    $usedCount = (int)($invite['used_count'] ?? 0);
    if ($maxUses > 0 && $usedCount >= $maxUses) {
        throw new RuntimeException('Invite code has already been used.');
    }
    return $invite;
}

function mp_registration_mark_invite_used(array $invite): void
{
    $id = (int)($invite['id'] ?? 0);
    if ($id < 1) {
        return;
    }
    $stmt = mp_db()->prepare('UPDATE ' . mp_table('registration_invites') . ' SET used_count = used_count + 1, last_used_at = NOW(), updated_at = NOW() WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

function mp_registration_create_public_account(string $username, string $displayName, string $email, string $password, string $confirmPassword, string $honeypot = '', string $inviteCode = ''): array
{
    if (!mp_public_registration_enabled()) {
        throw new RuntimeException('Public registration is currently closed.');
    }

    mp_registration_validate_honeypot($honeypot);

    if (mp_registration_rate_limited($username, $email)) {
        throw new RuntimeException('Too many registration attempts. Try again later.');
    }

    try {
        if ($password !== $confirmPassword) {
            throw new RuntimeException('Password and confirmation do not match.');
        }

        $username = mp_normalize_username($username);
        $displayName = trim($displayName);
        $email = strtolower(trim($email));
        $invite = null;

        if (mp_registration_invite_required() || trim($inviteCode) !== '') {
            $invite = mp_registration_validate_invite_code($inviteCode);
        }

        $inviteRole = $invite ? mp_registration_normalize_invite_role((string)($invite['role'] ?? '')) : '';
        $role = $inviteRole !== '' ? $inviteRole : mp_registration_default_role();
        $requiresVerification = mp_registration_require_email_verification();
        $requiresApproval = mp_registration_account_requires_admin_approval($role);

        if ($requiresVerification && $email === '') {
            throw new RuntimeException('Email is required so the account can be verified.');
        }

        if ($requiresVerification && !mp_registration_mail_ready()) {
            throw new RuntimeException('Registration requires email verification, but mail is not configured yet.');
        }

        $status = ($requiresVerification || $requiresApproval) ? 'pending' : 'active';
        $markEmailVerified = !$requiresVerification;
        $user = mp_create_user($username, $displayName, $email, $role, $password, $status, $markEmailVerified);
        $token = '';
        $mailError = '';

        if ($invite) {
            mp_registration_mark_invite_used($invite);
        }

        if ($requiresVerification) {
            $token = mp_registration_verification_token();
            mp_registration_store_verification_token((int)$user['id'], $token);
            $user = mp_find_user_by_id_any((int)$user['id']) ?? $user;
            try {
                mp_registration_send_verification_email($user, $token);
            } catch (Throwable $e) {
                $mailError = $e->getMessage();
            }
        }

        mp_registration_record_attempt($username, $email, true);

        return [
            'user' => $user,
            'requires_verification' => $requiresVerification,
            'requires_approval' => $requiresApproval,
            'mail_error' => $mailError,
        ];
    } catch (Throwable $e) {
        mp_registration_record_attempt($username, $email, false);
        throw $e;
    }
}

function mp_registration_resend_verification(string $usernameOrEmail): string
{
    if (!mp_registration_require_email_verification()) {
        return mp_registration_resend_generic_message();
    }

    if (!mp_registration_mail_ready()) {
        throw new RuntimeException('Mail is not configured for verification emails yet.');
    }

    $needle = trim($usernameOrEmail);
    if ($needle === '') {
        throw new RuntimeException('Enter the username or email address for the pending account.');
    }

    if (mp_registration_resend_rate_limited($needle)) {
        throw new RuntimeException('Too many verification email requests. Try again later.');
    }

    $mailSent = false;
    try {
        $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('users') . ' WHERE status = :status AND (username = :username OR email = :email) LIMIT 1');
        $stmt->execute([
            'status' => 'pending',
            'username' => mp_normalize_username($needle),
            'email' => strtolower($needle),
        ]);
        $user = $stmt->fetch();

        if (is_array($user) && trim((string)($user['email_verified_at'] ?? '')) === '') {
            $token = mp_registration_verification_token();
            mp_registration_store_verification_token((int)$user['id'], $token);
            $user = mp_find_user_by_id_any((int)$user['id']) ?? $user;
            mp_registration_send_verification_email($user, $token);
            $mailSent = true;
        }
    } finally {
        mp_registration_record_resend_attempt($needle, $mailSent);
    }

    return mp_registration_resend_generic_message();
}
