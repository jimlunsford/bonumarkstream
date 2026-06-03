<?php
require_once __DIR__ . '/database.php';

bms_start_secure_session();
bms_send_security_headers();

function bms_find_user_by_username(string $username): ?array
{
    bms_require_installed();
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('users') . ' WHERE username = :username AND status = :status LIMIT 1');
    $stmt->execute([
        'username' => bms_normalize_username($username),
        'status' => 'active',
    ]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function bms_find_user_by_username_any(string $username): ?array
{
    bms_require_installed();
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('users') . ' WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => bms_normalize_username($username)]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function bms_find_user_by_id(int|string $id): ?array
{
    bms_require_installed();
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('users') . ' WHERE id = :id AND status = :status LIMIT 1');
    $stmt->execute(['id' => (int)$id, 'status' => 'active']);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function bms_find_user_by_id_any(int|string $id): ?array
{
    bms_require_installed();
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('users') . ' WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int)$id]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function bms_guest_user(): array
{
    return [
        'id' => 0,
        'username' => '',
        'display_name' => 'Guest',
        'email' => '',
        'role' => 'guest',
        'status' => 'guest',
    ];
}

function bms_clear_login_session(): void
{
    unset($_SESSION['bms_logged_in'], $_SESSION['bms_user_id']);
}

function bms_current_user(): array
{
    $sessionId = $_SESSION['bms_user_id'] ?? null;
    if (!empty($_SESSION['bms_logged_in']) && $sessionId !== null) {
        $user = bms_find_user_by_id($sessionId);
        if ($user) {
            return $user;
        }
        bms_clear_login_session();
    }

    return bms_guest_user();
}

function bms_login_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $ip . '|' . (string)(bms_config()['security_salt'] ?? 'bonumark'));
}

function bms_login_fallback_path(): string
{
    return bms_root_path('tmp/login-attempts.json');
}

function bms_load_login_fallback_attempts(): array
{
    $path = bms_login_fallback_path();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function bms_save_login_fallback_attempts(array $attempts): void
{
    $cutoff = time() - 3600;
    $attempts = array_values(array_filter($attempts, function ($attempt) use ($cutoff) {
        return is_array($attempt) && (int)($attempt['time'] ?? 0) >= $cutoff;
    }));
    bms_write_file(bms_login_fallback_path(), json_encode($attempts, JSON_PRETTY_PRINT));
}

function bms_record_login_attempt_fallback(string $username, bool $success): void
{
    try {
        $attempts = bms_load_login_fallback_attempts();
        $attempts[] = [
            'username' => bms_normalize_username($username),
            'ip_hash' => bms_login_ip_hash(),
            'success' => $success,
            'time' => time(),
        ];
        bms_save_login_fallback_attempts($attempts);
    } catch (Throwable $e) {
        // Last-resort fallback logging should not reveal errors to attackers.
    }
}

function bms_login_rate_limited_fallback(string $username): bool
{
    try {
        $cutoff = time() - 900;
        $username = bms_normalize_username($username);
        $ipHash = bms_login_ip_hash();
        $count = 0;
        foreach (bms_load_login_fallback_attempts() as $attempt) {
            if (!is_array($attempt) || !empty($attempt['success']) || (int)($attempt['time'] ?? 0) < $cutoff) {
                continue;
            }
            if (($attempt['username'] ?? '') === $username || ($attempt['ip_hash'] ?? '') === $ipHash) {
                $count++;
            }
        }
        return $count >= 10;
    } catch (Throwable $e) {
        return true;
    }
}

function bms_record_login_attempt(string $username, bool $success): void
{
    try {
        $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('login_attempts') . ' (username, ip_hash, success, attempted_at) VALUES (:username, :ip_hash, :success, NOW())');
        $stmt->execute([
            'username' => bms_normalize_username($username),
            'ip_hash' => bms_login_ip_hash(),
            'success' => $success ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        bms_record_login_attempt_fallback($username, $success);
    }
}

function bms_login_rate_limited(string $username): bool
{
    try {
        $stmt = bms_db()->prepare('SELECT COUNT(*) FROM ' . bms_table('login_attempts') . ' WHERE attempted_at > (NOW() - INTERVAL 15 MINUTE) AND success = 0 AND (username = :username OR ip_hash = :ip_hash)');
        $stmt->execute([
            'username' => bms_normalize_username($username),
            'ip_hash' => bms_login_ip_hash(),
        ]);
        return (int)$stmt->fetchColumn() >= 10;
    } catch (Throwable $e) {
        return bms_login_rate_limited_fallback($username);
    }
}


function bms_roles(): array
{
    return [
        'admin' => 'Admin',
        'commenter' => 'Commenter',
    ];
}

function bms_normalize_role(string $role): string
{
    $role = strtolower(trim($role));
    return $role === 'admin' ? 'admin' : 'commenter';
}

function bms_role_label(string $role): string
{
    $roles = bms_roles();
    $role = bms_normalize_role($role);
    return $roles[$role] ?? 'Commenter';
}

function bms_user_status_options(): array
{
    return [
        'active' => 'Active',
        'pending' => 'Pending',
        'inactive' => 'Inactive',
    ];
}

function bms_normalize_user_status(string $status): string
{
    $status = strtolower(trim($status));
    return array_key_exists($status, bms_user_status_options()) ? $status : 'active';
}

function bms_user_status_label(string $status): string
{
    $options = bms_user_status_options();
    $status = bms_normalize_user_status($status);
    return $options[$status] ?? 'Active';
}

function bms_user_pending_counts(): array
{
    try {
        $sql = "SELECT
            SUM(CASE WHEN status = 'pending' AND (email_verified_at IS NULL OR email_verified_at = '') THEN 1 ELSE 0 END) AS pending_verification,
            SUM(CASE WHEN status = 'pending' AND email_verified_at IS NOT NULL AND email_verified_at <> '' THEN 1 ELSE 0 END) AS pending_approval
            FROM " . bms_table('users');
        $stmt = bms_db()->query($sql);
        $row = $stmt->fetch();
        return [
            'pending_verification' => (int)($row['pending_verification'] ?? 0),
            'pending_approval' => (int)($row['pending_approval'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['pending_verification' => 0, 'pending_approval' => 0];
    }
}

function bms_user_pending_reason(array $user): string
{
    if ((string)($user['status'] ?? 'active') !== 'pending') {
        return '';
    }
    return trim((string)($user['email_verified_at'] ?? '')) === '' ? 'Email verification' : 'Admin approval';
}

function bms_current_user_can(string $capability, ?array $subject = null): bool
{
    $user = bms_current_user();
    if ((int)($user['id'] ?? 0) < 1 || (string)($user['status'] ?? '') !== 'active') {
        return false;
    }

    $role = bms_normalize_role((string)($user['role'] ?? 'commenter'));
    if ($role === 'admin') {
        return true;
    }

    if ($role === 'commenter') {
        return in_array($capability, ['comment', 'edit_profile'], true);
    }

    return false;
}

function bms_admin_route_capability(string $script): ?string
{
    return match ($script) {
        'index.php', 'welcome.php', 'help.php', 'user.php' => 'view_admin',
        'content.php', 'new.php', 'edit.php', 'preview.php', 'preview-current.php', 'quick-edit.php', 'delete.php', 'restore.php', 'delete-permanent.php' => 'edit_content',
        'publish.php', 'unpublish.php' => 'publish_content',
        'pages.php', 'page-new.php', 'page-edit.php', 'page-delete.php', 'page-publish.php', 'page-unpublish.php', 'page-restore.php', 'page-delete-permanent.php' => 'manage_pages',
        'quick-post.php' => 'edit_content',
        'link-preview.php' => 'edit_content',
        'autosave.php' => 'edit_content',
        'media.php', 'media-upload.php', 'media-edit.php', 'media-picker.php', 'media-regenerate.php' => 'manage_media',
        'comments.php' => 'manage_comments',
        'revisions.php', 'compare-revision.php', 'restore-revision.php' => 'restore_revisions',
        'appearance.php', 'theme.php', 'theme-details.php', 'theme-settings.php', 'theme-install.php', 'theme-delete.php', 'navigation.php', 'site-identity.php' => 'manage_appearance',
        'settings.php', 'settings-writing.php', 'settings-reading.php', 'registration.php', 'mail.php' => 'manage_settings',
        'users.php', 'user-edit.php' => 'manage_users',
        'tools.php', 'upgrade.php', 'export.php', 'import.php', 'import-markdown.php', 'system-check.php', 'security.php' => 'view_system',
        default => null,
    };
}

function bms_enforce_admin_route_capability(): void
{
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $capability = bms_admin_route_capability($script);
    if ($capability !== null) {
        bms_require_capability($capability);
    }
}

function bms_filter_content_items_for_current_user(array $items): array
{
    return $items;
}

function bms_require_trash_item_access(int $id): array
{
    $item = function_exists('bms_get_trash_item') ? bms_get_trash_item($id) : null;
    if (!$item) {
        bms_abort_request('Trash item not found.', 404);
    }
    $authorId = (int)($item['original_author_id'] ?? $item['author_id'] ?? $item['deleted_by'] ?? 0);
    bms_require_capability('restore_trash', ['author_id' => $authorId]);
    return $item;
}

function bms_require_revision_access(array $revision): void
{
    $authorId = function_exists('bms_revision_original_author_id') ? bms_revision_original_author_id($revision) : (int)($revision['author_id'] ?? 0);
    bms_require_capability('restore_revisions', ['author_id' => (int)$authorId]);
}

function bms_require_content_file_access(string $section, string $filename, string $capability = 'edit_content', array $page = []): void
{
    $subject = function_exists('bms_content_subject_for_file') ? bms_content_subject_for_file($section, $filename, $page) : $page;
    bms_require_capability($capability, $subject);
}

function bms_require_capability(string $capability, ?array $subject = null): void
{
    if (!bms_current_user_can($capability, $subject)) {
        bms_abort_request('You do not have permission to access this area.', 403);
    }
}

function bms_list_users(): array
{
    bms_require_installed();
    $stmt = bms_db()->query('SELECT id, username, display_name, email, email_verified_at, role, status, created_at, updated_at FROM ' . bms_table('users') . ' ORDER BY display_name ASC, username ASC');
    return $stmt->fetchAll() ?: [];
}

function bms_create_user(string $username, string $displayName, string $email, string $role, string $password, string $status = 'active', bool $markEmailVerified = true): array
{
    $username = bms_normalize_username($username);
    $displayName = trim($displayName);
    $email = strtolower(trim($email));
    $role = bms_normalize_role($role);
    $status = bms_normalize_user_status($status);
    if ($role === 'admin') {
        throw new RuntimeException('The installer creates the only admin account. Additional accounts must be commenters.');
    }
    $role = 'commenter';
    if (strlen($username) < 3) { throw new RuntimeException('Username must be at least 3 characters.'); }
    if ($displayName === '') { throw new RuntimeException('Display name cannot be empty.'); }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('Enter a valid email address or leave it blank.'); }
    bms_validate_password_policy($password, $username, $email);
    $existing = bms_find_user_by_username_any($username);
    if ($existing) { throw new RuntimeException('That username is already taken.'); }
    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('users') . ' (username, display_name, email, email_verified_at, password_hash, role, status, created_at, updated_at) VALUES (:username, :display_name, :email, :email_verified_at, :password_hash, :role, :status, NOW(), NOW())');
    $stmt->execute([
        'username' => $username,
        'display_name' => $displayName,
        'email' => $email,
        'email_verified_at' => $markEmailVerified ? date('Y-m-d H:i:s') : null,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'status' => $status,
    ]);
    return bms_find_user_by_id_any((int)bms_db()->lastInsertId()) ?? [];
}

function bms_update_user_role_status(int $id, string $role, string $status): void
{
    $currentId = (int)(bms_current_user()['id'] ?? 0);
    $existing = bms_find_user_by_id_any($id);
    if (!$existing) {
        throw new RuntimeException('Account was not found.');
    }

    $existingRole = bms_normalize_role((string)($existing['role'] ?? 'commenter'));
    $role = $existingRole === 'admin' ? 'admin' : 'commenter';
    $status = bms_normalize_user_status($status);

    if ($id === $currentId && ($role !== 'admin' || $status !== 'active')) {
        throw new RuntimeException('You cannot remove your own active admin access.');
    }
    if ($existingRole === 'admin' && $status !== 'active' && bms_active_admin_count($id) < 1) {
        throw new RuntimeException('The site must keep one active admin account.');
    }

    $emailVerifiedSql = $status === 'active' ? ', email_verified_at = COALESCE(email_verified_at, NOW()), verification_token_hash = NULL, verification_token_expires_at = NULL' : '';
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('users') . ' SET role = :role, status = :status' . $emailVerifiedSql . ', updated_at = NOW() WHERE id = :id');
    $stmt->execute(['role' => $role, 'status' => $status, 'id' => $id]);
}


function bms_active_admin_count(?int $excludeUserId = null): int
{
    $sql = 'SELECT COUNT(*) FROM ' . bms_table('users') . " WHERE role = 'admin' AND status = 'active'";
    $params = [];
    if ($excludeUserId !== null && $excludeUserId > 0) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeUserId;
    }
    $stmt = bms_db()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function bms_user_delete_reassign_targets(int $excludeUserId): array
{
    bms_require_installed();
    $stmt = bms_db()->prepare('SELECT id, username, display_name, role, status FROM ' . bms_table('users') . ' WHERE id <> :id AND status = :status ORDER BY display_name ASC, username ASC');
    $stmt->execute(['id' => $excludeUserId, 'status' => 'active']);
    return $stmt->fetchAll() ?: [];
}

function bms_admin_update_user_account(int $id, string $username, string $displayName, string $email, string $role, string $status, string $profileVisibility, bool $emailVerified): array
{
    bms_require_capability('manage_users');
    $existing = bms_find_user_by_id_any($id);
    if (!$existing) {
        throw new RuntimeException('User was not found.');
    }

    $currentId = (int)(bms_current_user()['id'] ?? 0);
    $username = bms_normalize_username($username);
    $displayName = trim($displayName);
    $email = strtolower(trim($email));
    $existingRole = bms_normalize_role((string)($existing['role'] ?? 'commenter'));
    $role = $existingRole === 'admin' ? 'admin' : 'commenter';
    $status = bms_normalize_user_status($status);
    $profileVisibility = $profileVisibility === 'private' ? 'private' : 'public';

    if (strlen($username) < 3) {
        throw new RuntimeException('Username must be at least 3 characters.');
    }
    if ($displayName === '') {
        throw new RuntimeException('Display name cannot be empty.');
    }
    if (strlen($displayName) > 120) {
        throw new RuntimeException('Display name is too long. Keep it under 120 characters.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address or leave it blank.');
    }

    $stmt = bms_db()->prepare('SELECT id FROM ' . bms_table('users') . ' WHERE username = :username AND id <> :id LIMIT 1');
    $stmt->execute(['username' => $username, 'id' => $id]);
    if ($stmt->fetchColumn() !== false) {
        throw new RuntimeException('That username is already taken.');
    }

    $wasActiveAdmin = $existingRole === 'admin' && (string)($existing['status'] ?? '') === 'active';
    $willRemainActiveAdmin = $role === 'admin' && $status === 'active';
    if ($wasActiveAdmin && !$willRemainActiveAdmin && bms_active_admin_count($id) < 1) {
        throw new RuntimeException('The site must keep one active admin account.');
    }
    if ($id === $currentId && ($role !== 'admin' || $status !== 'active')) {
        throw new RuntimeException('You cannot remove your own active admin access.');
    }

    $stmt = bms_db()->prepare('UPDATE ' . bms_table('users') . ' SET username = :username, display_name = :display_name, email = :email, role = :role, status = :status, profile_visibility = :profile_visibility, email_verified_at = :email_verified_at, verification_token_hash = NULL, verification_token_expires_at = NULL, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'username' => $username,
        'display_name' => $displayName,
        'email' => $email,
        'role' => $role,
        'status' => $status,
        'profile_visibility' => $profileVisibility,
        'email_verified_at' => $emailVerified ? date('Y-m-d H:i:s') : null,
        'id' => $id,
    ]);

    $current = bms_current_user();
    if ($id === (int)($current['id'] ?? 0) && $role === 'admin') {
        bms_set_setting('author_name', $displayName);
    }

    return bms_find_user_by_id_any($id) ?? [];
}

function bms_admin_reset_user_password(int $id, string $password, string $confirmPassword): void
{
    bms_require_capability('manage_users');
    $user = bms_find_user_by_id_any($id);
    if (!$user) {
        throw new RuntimeException('User was not found.');
    }
    if ($password !== $confirmPassword) {
        throw new RuntimeException('Password and confirmation do not match.');
    }
    bms_validate_password_policy($password, (string)($user['username'] ?? ''), (string)($user['email'] ?? ''));

    $pdo = bms_db();
    $stmt = $pdo->prepare('UPDATE ' . bms_table('users') . ' SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'id' => $id,
    ]);

    try {
        $stmt = $pdo->prepare('UPDATE ' . bms_table('password_reset_tokens') . ' SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL');
        $stmt->execute(['user_id' => $id]);
    } catch (Throwable $e) {
        // Password reset token cleanup should not block an admin password reset.
    }
}

function bms_admin_delete_user(int $id, int $reassignToUserId, string $confirmation): void
{
    bms_require_capability('manage_users');
    $user = bms_find_user_by_id_any($id);
    if (!$user) {
        throw new RuntimeException('User was not found.');
    }

    $currentId = (int)(bms_current_user()['id'] ?? 0);
    if ($id === $currentId) {
        throw new RuntimeException('You cannot delete your own account while signed in.');
    }

    $username = (string)($user['username'] ?? '');
    if (bms_normalize_username($confirmation) !== bms_normalize_username($username)) {
        throw new RuntimeException('Type the username exactly to confirm account deletion.');
    }

    $isActiveAdmin = bms_normalize_role((string)($user['role'] ?? 'commenter')) === 'admin' && (string)($user['status'] ?? '') === 'active';
    if ($isActiveAdmin && bms_active_admin_count($id) < 1) {
        throw new RuntimeException('The site must keep one active admin account.');
    }

    $reassignToUserId = max(0, $reassignToUserId);
    if ($reassignToUserId === $id) {
        throw new RuntimeException('Choose a different account for reassigned content.');
    }
    $reassignTargetId = $currentId > 0 ? $currentId : null;
    if ($reassignToUserId > 0) {
        $target = bms_find_user_by_id_any($reassignToUserId);
        if (!$target || (string)($target['status'] ?? '') !== 'active') {
            throw new RuntimeException('Choose an active account for reassigned content.');
        }
        $reassignTargetId = (int)$target['id'];
    }

    $pdo = bms_db();
    $pdo->beginTransaction();
    try {
        $updates = [
            ['posts', 'author_id'],
            ['revisions', 'author_id'],
            ['media', 'uploaded_by'],
            ['comments', 'user_id'],
            ['trash', 'original_author_id'],
            ['trash', 'deleted_by'],
        ];
        foreach ($updates as [$table, $column]) {
            try {
                $stmt = $pdo->prepare('UPDATE ' . bms_table($table) . ' SET ' . $column . ' = :target WHERE ' . $column . ' = :id');
                $stmt->execute(['target' => $reassignTargetId, 'id' => $id]);
            } catch (Throwable $e) {
                // Optional/legacy tables or columns should not block deletion cleanup.
            }
        }

        $nullUpdates = [
            ['autosaves', 'user_id'],
            ['registration_invites', 'created_by'],
            ['password_reset_tokens', 'user_id'],
        ];
        foreach ($nullUpdates as [$table, $column]) {
            try {
                $stmt = $pdo->prepare('UPDATE ' . bms_table($table) . ' SET ' . $column . ' = NULL WHERE ' . $column . ' = :id');
                $stmt->execute(['id' => $id]);
            } catch (Throwable $e) {
                // Optional/legacy tables or NOT NULL columns should not block core account deletion.
            }
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM ' . bms_table('password_reset_tokens') . ' WHERE user_id = :id');
            $stmt->execute(['id' => $id]);
        } catch (Throwable $e) {
            // Cleanup-only.
        }

        $stmt = $pdo->prepare('DELETE FROM ' . bms_table('users') . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if (function_exists('bms_user_avatar_delete_file')) {
        bms_user_avatar_delete_file((string)($user['avatar_path'] ?? ''));
    }
}

function bms_profile_social_link_definitions(): array
{
    return [
        'x' => ['label' => 'X', 'placeholder' => 'https://x.com/username'],
        'bluesky' => ['label' => 'Bluesky', 'placeholder' => 'https://bsky.app/profile/username.bsky.social'],
        'github' => ['label' => 'GitHub', 'placeholder' => 'https://github.com/username'],
        'instagram' => ['label' => 'Instagram', 'placeholder' => 'https://instagram.com/username'],
        'youtube' => ['label' => 'YouTube', 'placeholder' => 'https://youtube.com/@username'],
        'linkedin' => ['label' => 'LinkedIn', 'placeholder' => 'https://linkedin.com/in/username'],
        'facebook' => ['label' => 'Facebook', 'placeholder' => 'https://facebook.com/username'],
        'tiktok' => ['label' => 'TikTok', 'placeholder' => 'https://tiktok.com/@username'],
        'mastodon' => ['label' => 'Mastodon', 'placeholder' => 'https://mastodon.social/@username'],
    ];
}

function bms_profile_social_links_decode(mixed $raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    $raw = trim((string)$raw);
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function bms_normalize_profile_social_url(string $url, string $label): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (strlen($url) > 500) {
        throw new RuntimeException($label . ' URL is too long. Keep it under 500 characters.');
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Enter a valid ' . $label . ' URL or leave it blank.');
    }
    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException($label . ' URL must start with http:// or https://.');
    }
    return $url;
}

function bms_normalize_profile_social_label(string $label, string $fallback): string
{
    $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');
    if ($label === '') {
        $label = $fallback;
    }
    if (function_exists('mb_substr')) {
        $label = mb_substr($label, 0, 60);
    } else {
        $label = substr($label, 0, 60);
    }
    return trim($label) !== '' ? trim($label) : $fallback;
}

function bms_profile_social_link_form_values(array $user): array
{
    $values = [];
    foreach (bms_profile_social_link_definitions() as $id => $definition) {
        $values[$id] = '';
    }
    $values['custom_1_label'] = '';
    $values['custom_1_url'] = '';
    $values['custom_2_label'] = '';
    $values['custom_2_url'] = '';

    $customIndex = 1;
    foreach (bms_profile_social_links_decode($user['social_links'] ?? '') as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (string)($item['id'] ?? '');
        $url = trim((string)($item['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        if (array_key_exists($id, $values)) {
            $values[$id] = $url;
            continue;
        }
        if (str_starts_with($id, 'custom_') && $customIndex <= 2) {
            $values['custom_' . $customIndex . '_label'] = (string)($item['label'] ?? '');
            $values['custom_' . $customIndex . '_url'] = $url;
            $customIndex++;
        }
    }

    return $values;
}

function bms_normalize_profile_social_links_from_input(array $input): string
{
    $links = [];
    foreach (bms_profile_social_link_definitions() as $id => $definition) {
        $label = (string)($definition['label'] ?? ucfirst($id));
        $url = bms_normalize_profile_social_url((string)($input[$id] ?? ''), $label);
        if ($url !== '') {
            $links[] = ['id' => $id, 'label' => $label, 'url' => $url];
        }
    }

    for ($i = 1; $i <= 2; $i++) {
        $labelKey = 'custom_' . $i . '_label';
        $urlKey = 'custom_' . $i . '_url';
        $rawLabel = trim((string)($input[$labelKey] ?? ''));
        $url = bms_normalize_profile_social_url((string)($input[$urlKey] ?? ''), 'Custom link ' . $i);
        if ($url === '') {
            continue;
        }
        if ($rawLabel === '') {
            throw new RuntimeException('Custom link ' . $i . ' needs a label.');
        }
        $links[] = [
            'id' => 'custom_' . $i,
            'label' => bms_normalize_profile_social_label($rawLabel, 'Custom Link ' . $i),
            'url' => $url,
        ];
    }

    return json_encode($links, JSON_UNESCAPED_SLASHES) ?: '[]';
}

function bms_profile_social_links_for_user(array $user): array
{
    $links = [];
    foreach (bms_profile_social_links_decode($user['social_links'] ?? '') as $item) {
        if (!is_array($item)) {
            continue;
        }
        $label = bms_normalize_profile_social_label((string)($item['label'] ?? ''), 'Link');
        $url = trim((string)($item['url'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            continue;
        }
        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            continue;
        }
        $links[] = [
            'label' => $label,
            'url' => $url,
        ];
    }
    return $links;
}

function bms_update_current_user_profile(string $username, string $displayName, string $email = '', string $bio = '', string $website = '', string $profileVisibility = 'public', array $socialLinksInput = []): array
{
    $current = bms_current_user();
    $currentId = (int)($current['id'] ?? 0);
    $username = bms_normalize_username($username);
    $displayName = trim($displayName);
    $email = trim($email);
    $bio = trim($bio);
    $website = trim($website);
    $profileVisibility = $profileVisibility === 'private' ? 'private' : 'public';

    if (strlen($username) < 3) {
        throw new RuntimeException('Username must be at least 3 characters.');
    }

    if ($displayName === '') {
        throw new RuntimeException('Display name cannot be empty.');
    }

    if (strlen($displayName) > 120) {
        throw new RuntimeException('Display name is too long. Keep it under 120 characters.');
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address or leave it blank.');
    }

    if ($website !== '') {
        if (!preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        if (!filter_var($website, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Enter a valid website URL or leave it blank.');
        }
    }

    if (strlen($bio) > 1000) {
        throw new RuntimeException('Profile bio is too long. Keep it under 1000 characters.');
    }

    $socialLinks = bms_normalize_profile_social_links_from_input($socialLinksInput);

    $stmt = bms_db()->prepare('SELECT id FROM ' . bms_table('users') . ' WHERE username = :username AND id <> :id LIMIT 1');
    $stmt->execute(['username' => $username, 'id' => $currentId]);
    if ($stmt->fetchColumn() !== false) {
        throw new RuntimeException('That username is already taken.');
    }

    $stmt = bms_db()->prepare('UPDATE ' . bms_table('users') . ' SET username = :username, display_name = :display_name, email = :email, bio = :bio, website = :website, social_links = :social_links, profile_visibility = :profile_visibility, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'username' => $username,
        'display_name' => $displayName,
        'email' => $email,
        'bio' => $bio,
        'website' => $website,
        'social_links' => $socialLinks,
        'profile_visibility' => $profileVisibility,
        'id' => $currentId,
    ]);

    if (bms_normalize_role((string)($current['role'] ?? 'commenter')) === 'admin') {
        bms_set_setting('author_name', $displayName);
    }
    return bms_find_user_by_id($currentId) ?? bms_current_user();
}

function bms_create_commenter_account(string $username, string $displayName, string $email, string $password, string $confirmPassword): array
{
    if (function_exists('bms_registration_create_public_account')) {
        $result = bms_registration_create_public_account($username, $displayName, $email, $password, $confirmPassword);
        return is_array($result['user'] ?? null) ? $result['user'] : [];
    }
    if (bms_setting_or_config('comment_registration_enabled', '1') !== '1') {
        throw new RuntimeException('Comment account registration is currently closed.');
    }
    if ($password !== $confirmPassword) {
        throw new RuntimeException('Password and confirmation do not match.');
    }
    return bms_create_user($username, $displayName, $email, 'commenter', $password);
}

function bms_update_current_user_password(string $currentPassword, string $newPassword, string $confirmPassword): void
{
    $current = bms_current_user();
    $currentId = (int)($current['id'] ?? 0);
    $hash = (string)($current['password_hash'] ?? '');

    if ($hash === '' || !password_verify($currentPassword, $hash)) {
        throw new RuntimeException('Current password did not match.');
    }

    bms_validate_password_policy($newPassword, (string)($current['username'] ?? ''), (string)($current['email'] ?? ''));

    if ($newPassword !== $confirmPassword) {
        throw new RuntimeException('New password and confirmation do not match.');
    }

    $stmt = bms_db()->prepare('UPDATE ' . bms_table('users') . ' SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id' => $currentId,
    ]);
}

function bms_is_logged_in(): bool
{
    if (empty($_SESSION['bms_logged_in']) || empty($_SESSION['bms_user_id'])) {
        return false;
    }

    $user = bms_find_user_by_id((int)$_SESSION['bms_user_id']);
    if (!$user) {
        bms_clear_login_session();
        return false;
    }

    return true;
}

function bms_require_login(): void
{
    bms_require_installed();
    if (!bms_is_logged_in()) {
        bms_redirect(bms_admin_url('login.php'));
    }
    bms_enforce_admin_route_capability();
}

function bms_attempt_login(string $username, string $password): bool
{
    bms_require_installed();

    if (bms_login_rate_limited($username)) {
        return false;
    }

    $user = bms_find_user_by_username($username);
    $hash = (string)($user['password_hash'] ?? '');

    if ($user && $hash !== '' && password_verify($password, $hash)) {
        session_regenerate_id(true);
        $_SESSION['bms_logged_in'] = true;
        $_SESSION['bms_user_id'] = (int)$user['id'];
        bms_record_login_attempt($username, true);
        return true;
    }

    bms_record_login_attempt($username, false);
    return false;
}

function bms_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function bms_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function bms_verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        bms_abort_request('Invalid request token.', 403);
    }
}
