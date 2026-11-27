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

/**
 * The one message every failed public sign-in shows. It depends only on the
 * site's registration settings, never on the account, and tells a new account
 * holder where to go: confirm the email address, or wait for approval.
 */
function bms_public_sign_in_failure_message(): string
{
    $message = 'Login failed. Check the username and password.';
    if (!bms_public_registration_enabled()) {
        return $message;
    }
    if (bms_registration_require_email_verification()) {
        $message .= ' If you created an account recently, confirm your email address first. You can request a new verification link on this page.';
    }
    if (bms_registration_require_admin_approval()) {
        $message .= ' New accounts can also need the site owner\'s approval before they can sign in.';
    }
    return $message;
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
    // Emailed links must be absolute, like password reset links.
    return bms_site_url('account.php?action=verify&token=' . rawurlencode($token));
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

    // Only an account still waiting for its first email verification can use
    // a verification link. An approved, active, or deactivated account never
    // changes status through one.
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('users') . " WHERE verification_token_hash = :hash AND verification_token_expires_at >= NOW() AND status = 'pending' AND email_verified_at IS NULL LIMIT 1");
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

    $stmt = bms_db()->prepare('UPDATE ' . bms_table('users') . " SET status = :status, email_verified_at = NOW(), verification_token_hash = NULL, verification_token_expires_at = NULL, updated_at = NOW() WHERE id = :id AND verification_token_hash = :hash AND status = 'pending' AND email_verified_at IS NULL");
    $stmt->execute([
        'status' => $newStatus,
        'id' => (int)$user['id'],
        'hash' => bms_registration_token_hash(trim($token)),
    ]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Verification link is invalid or expired.');
    }

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

function bms_registration_invite_expiration_to_utc(string $value): string
{
    $localValue = str_replace('T', ' ', trim($value));
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/', $localValue)) {
        throw new RuntimeException('Invite expiration date is not valid.');
    }

    try {
        return (new DateTimeImmutable($localValue, bms_site_timezone()))
            ->setTimezone(bms_utc_timezone())
            ->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        throw new RuntimeException('Invite expiration date is not valid.');
    }
}

function bms_registration_create_invite(string $label, int $maxUses, string $expiresAt = ''): array
{
    bms_require_capability('manage_settings');

    $label = trim($label);
    $maxUses = max(0, $maxUses);
    $expiresAt = trim($expiresAt);
    $expiresSql = null;

    if ($expiresAt !== '') {
        $expiresSql = bms_registration_invite_expiration_to_utc($expiresAt);
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
    if ($expiresAt === '') {
        return false;
    }

    try {
        return (new DateTimeImmutable($expiresAt, bms_utc_timezone()))->getTimestamp() < time();
    } catch (Throwable $e) {
        return true;
    }
}

function bms_registration_format_invite_expiration(string $utc): string
{
    $utc = trim($utc);
    if ($utc === '' || $utc === '0000-00-00 00:00:00') {
        return 'Never';
    }

    try {
        return (new DateTimeImmutable($utc, bms_utc_timezone()))
            ->setTimezone(bms_site_timezone())
            ->format('M j, Y g:i A T');
    } catch (Throwable $e) {
        return 'Invalid date';
    }
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
        $username = bms_normalize_username($needle);
        $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('users') . ' WHERE status = :status AND (username = :username OR email = :email) ORDER BY CASE WHEN username = :username_rank THEN 0 ELSE 1 END ASC, id ASC LIMIT 1');
        $stmt->execute([
            'status' => 'pending',
            'username' => $username,
            'email' => strtolower($needle),
            'username_rank' => $username,
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

/** Mail can deliver the confirmation link a self-service email change needs. */
function bms_account_email_change_ready(): bool
{
    $settings = bms_mail_settings();
    return (string)($settings['mail_transport'] ?? 'disabled') !== 'disabled'
        && trim((string)($settings['mail_from_email'] ?? '')) !== '';
}

function bms_account_email_change_url(string $token): string
{
    return bms_site_url('account.php?action=confirm_email&token=' . rawurlencode($token));
}

/** The pending email change of an account, or '' when none is waiting. */
function bms_account_pending_email(array $user): string
{
    $pending = trim((string)($user['pending_email'] ?? ''));
    $expires = trim((string)($user['pending_email_expires_at'] ?? ''));
    if ($pending === '' || $expires === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($expires, bms_utc_timezone()))->getTimestamp() >= time() ? $pending : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Store a pending email change and send its confirmation link to the new
 * address. The account keeps its current address until the link is opened.
 * A newer request replaces an older one.
 */
function bms_account_request_email_change(int $userId, string $newEmail): void
{
    $newEmail = bms_normalize_email($newEmail);
    if ($userId < 1 || $newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address or leave it blank.');
    }
    if (!bms_users_has_column('pending_email')) {
        throw new RuntimeException('Email changes are available after the pending database upgrade completes.');
    }

    $token = bin2hex(random_bytes(32));
    $stmt = bms_db()->prepare("UPDATE " . bms_table('users') . " SET pending_email = :pending_email, pending_email_token_hash = :hash, pending_email_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR), updated_at = NOW() WHERE id = :id AND status = 'active'");
    $stmt->execute([
        'pending_email' => $newEmail,
        'hash' => bms_registration_token_hash($token),
        'id' => $userId,
    ]);
    $user = bms_find_user_by_id_any($userId);
    if (!$user || $stmt->rowCount() < 1) {
        throw new RuntimeException('Your account could not be updated. Sign in again and retry.');
    }

    try {
        bms_account_send_email_change_confirmation($user, $newEmail, $token);
    } catch (Throwable $e) {
        bms_db()->prepare('UPDATE ' . bms_table('users') . ' SET pending_email = NULL, pending_email_token_hash = NULL, pending_email_expires_at = NULL WHERE id = :id AND pending_email_token_hash = :hash')
            ->execute(['id' => $userId, 'hash' => bms_registration_token_hash($token)]);
        error_log('Bonumark Stream email change mail error: ' . $e->getMessage());
        throw new RuntimeException('The confirmation email could not be sent. Your email address was not changed. Try again later.');
    }
}

function bms_account_send_email_change_confirmation(array $user, string $newEmail, string $token): void
{
    $settings = bms_mail_settings();
    $siteName = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
    $url = bms_account_email_change_url($token);
    $displayName = trim((string)($user['display_name'] ?? $user['username'] ?? 'there'));
    $body = "Hi {$displayName},\n\n"
        . "Confirm this email address for your {$siteName} account:\n\n"
        . $url . "\n\n"
        . "This link expires in 24 hours. Your account keeps its current email address until you open it. If you did not ask for this change, ignore this email.\n";

    $message = bms_mail_message_from_settings(
        $settings,
        $newEmail,
        'Confirm your new ' . $siteName . ' email address',
        $body,
        'plain_text'
    );

    bms_mail_send($settings, $message);
}

/**
 * Apply the pending email change a confirmation link belongs to. The new
 * address becomes the account's verified address and its unique claim.
 */
function bms_account_confirm_email_change(string $token): array
{
    $token = trim($token);
    if ($token === '' || strlen($token) < 32 || !bms_users_has_column('pending_email')) {
        throw new RuntimeException('Email confirmation link is invalid or expired.');
    }

    $hash = bms_registration_token_hash($token);
    $stmt = bms_db()->prepare("SELECT * FROM " . bms_table('users') . " WHERE pending_email_token_hash = :hash AND pending_email_expires_at >= NOW() AND status = 'active' LIMIT 1");
    $stmt->execute(['hash' => $hash]);
    $user = $stmt->fetch();
    if (!is_array($user)) {
        throw new RuntimeException('Email confirmation link is invalid or expired.');
    }

    $userId = (int)$user['id'];
    $newEmail = bms_normalize_email((string)($user['pending_email'] ?? ''));
    $clearPending = static function () use ($userId, $hash): void {
        bms_db()->prepare('UPDATE ' . bms_table('users') . ' SET pending_email = NULL, pending_email_token_hash = NULL, pending_email_expires_at = NULL WHERE id = :id AND pending_email_token_hash = :hash')
            ->execute(['id' => $userId, 'hash' => $hash]);
    };
    if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $clearPending();
        throw new RuntimeException('Email confirmation link is invalid or expired.');
    }
    if (bms_email_in_use($newEmail, $userId)) {
        $clearPending();
        throw new RuntimeException(bms_email_in_use_message());
    }

    $update = bms_db()->prepare('UPDATE ' . bms_table('users') . ' SET email = :email, email_normalized = :email_normalized, email_verified_at = NOW(), pending_email = NULL, pending_email_token_hash = NULL, pending_email_expires_at = NULL, updated_at = NOW() WHERE id = :id AND pending_email_token_hash = :hash');
    try {
        $update->execute(['email' => $newEmail, 'email_normalized' => $newEmail, 'id' => $userId, 'hash' => $hash]);
    } catch (PDOException $e) {
        if (bms_is_duplicate_email_claim_error($e)) {
            $clearPending();
            throw new RuntimeException(bms_email_in_use_message());
        }
        throw $e;
    }
    if ($update->rowCount() < 1) {
        throw new RuntimeException('Email confirmation link is invalid or expired.');
    }

    // The old address may now be held by exactly one other account.
    bms_users_claim_unique_emails();
    return bms_find_user_by_id_any($userId) ?? $user;
}
