<?php
require_once __DIR__ . '/database.php';

mp_start_secure_session();
mp_send_security_headers();

function mp_find_user_by_username(string $username): ?array
{
    mp_require_installed();
    $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('users') . ' WHERE username = :username AND status = :status LIMIT 1');
    $stmt->execute([
        'username' => mp_normalize_username($username),
        'status' => 'active',
    ]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function mp_find_user_by_username_any(string $username): ?array
{
    mp_require_installed();
    $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('users') . ' WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => mp_normalize_username($username)]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function mp_find_user_by_id(int|string $id): ?array
{
    mp_require_installed();
    $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('users') . ' WHERE id = :id AND status = :status LIMIT 1');
    $stmt->execute(['id' => (int)$id, 'status' => 'active']);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function mp_find_user_by_id_any(int|string $id): ?array
{
    mp_require_installed();
    $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('users') . ' WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int)$id]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function mp_guest_user(): array
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

function mp_clear_login_session(): void
{
    unset($_SESSION['mp_logged_in'], $_SESSION['mp_user_id']);
}

function mp_current_user(): array
{
    $sessionId = $_SESSION['mp_user_id'] ?? null;
    if (!empty($_SESSION['mp_logged_in']) && $sessionId !== null) {
        $user = mp_find_user_by_id($sessionId);
        if ($user) {
            return $user;
        }
        mp_clear_login_session();
    }

    return mp_guest_user();
}

function mp_login_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $ip . '|' . (string)(mp_config()['security_salt'] ?? 'bonumark'));
}

function mp_login_fallback_path(): string
{
    return mp_root_path('tmp/login-attempts.json');
}

function mp_load_login_fallback_attempts(): array
{
    $path = mp_login_fallback_path();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function mp_save_login_fallback_attempts(array $attempts): void
{
    $cutoff = time() - 3600;
    $attempts = array_values(array_filter($attempts, function ($attempt) use ($cutoff) {
        return is_array($attempt) && (int)($attempt['time'] ?? 0) >= $cutoff;
    }));
    mp_write_file(mp_login_fallback_path(), json_encode($attempts, JSON_PRETTY_PRINT));
}

function mp_record_login_attempt_fallback(string $username, bool $success): void
{
    try {
        $attempts = mp_load_login_fallback_attempts();
        $attempts[] = [
            'username' => mp_normalize_username($username),
            'ip_hash' => mp_login_ip_hash(),
            'success' => $success,
            'time' => time(),
        ];
        mp_save_login_fallback_attempts($attempts);
    } catch (Throwable $e) {
        // Last-resort fallback logging should not reveal errors to attackers.
    }
}

function mp_login_rate_limited_fallback(string $username): bool
{
    try {
        $cutoff = time() - 900;
        $username = mp_normalize_username($username);
        $ipHash = mp_login_ip_hash();
        $count = 0;
        foreach (mp_load_login_fallback_attempts() as $attempt) {
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

function mp_record_login_attempt(string $username, bool $success): void
{
    try {
        $stmt = mp_db()->prepare('INSERT INTO ' . mp_table('login_attempts') . ' (username, ip_hash, success, attempted_at) VALUES (:username, :ip_hash, :success, NOW())');
        $stmt->execute([
            'username' => mp_normalize_username($username),
            'ip_hash' => mp_login_ip_hash(),
            'success' => $success ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        mp_record_login_attempt_fallback($username, $success);
    }
}

function mp_login_rate_limited(string $username): bool
{
    try {
        $stmt = mp_db()->prepare('SELECT COUNT(*) FROM ' . mp_table('login_attempts') . ' WHERE attempted_at > (NOW() - INTERVAL 15 MINUTE) AND success = 0 AND (username = :username OR ip_hash = :ip_hash)');
        $stmt->execute([
            'username' => mp_normalize_username($username),
            'ip_hash' => mp_login_ip_hash(),
        ]);
        return (int)$stmt->fetchColumn() >= 10;
    } catch (Throwable $e) {
        return mp_login_rate_limited_fallback($username);
    }
}


function mp_roles(): array
{
    return [
        'administrator' => 'Admin',
        'user' => 'User',
        'commenter' => 'Commenter',
    ];
}

function mp_normalize_role(string $role): string
{
    $role = strtolower(trim($role));
    if ($role === 'author' || $role === 'editor') {
        return 'user';
    }
    return array_key_exists($role, mp_roles()) ? $role : 'commenter';
}

function mp_role_label(string $role): string
{
    $roles = mp_roles();
    $role = mp_normalize_role($role);
    return $roles[$role] ?? 'Commenter';
}


function mp_user_publish_mode(): string
{
    $mode = (string)mp_setting_or_config('user_publish_mode', 'draft_review');
    return in_array($mode, ['direct', 'draft_review'], true) ? $mode : 'draft_review';
}

function mp_standard_users_publish_directly(): bool
{
    return mp_user_publish_mode() === 'direct';
}

function mp_user_requires_post_review(?array $user = null): bool
{
    $user = $user ?? (function_exists('mp_current_user') ? mp_current_user() : []);
    $role = mp_normalize_role((string)($user['role'] ?? 'guest'));
    return $role === 'user' && !mp_standard_users_publish_directly();
}

function mp_current_user_requires_post_review(): bool
{
    return mp_user_requires_post_review(mp_current_user());
}

function mp_user_publish_mode_label(): string
{
    return mp_standard_users_publish_directly() ? 'Users can publish directly' : 'Users submit drafts for review';
}

function mp_user_status_options(): array
{
    return [
        'active' => 'Active',
        'pending' => 'Pending',
        'inactive' => 'Inactive',
    ];
}

function mp_normalize_user_status(string $status): string
{
    $status = strtolower(trim($status));
    return array_key_exists($status, mp_user_status_options()) ? $status : 'active';
}

function mp_user_status_label(string $status): string
{
    $options = mp_user_status_options();
    $status = mp_normalize_user_status($status);
    return $options[$status] ?? 'Active';
}

function mp_user_pending_counts(): array
{
    try {
        $sql = "SELECT
            SUM(CASE WHEN status = 'pending' AND (email_verified_at IS NULL OR email_verified_at = '') THEN 1 ELSE 0 END) AS pending_verification,
            SUM(CASE WHEN status = 'pending' AND email_verified_at IS NOT NULL AND email_verified_at <> '' THEN 1 ELSE 0 END) AS pending_approval
            FROM " . mp_table('users');
        $stmt = mp_db()->query($sql);
        $row = $stmt->fetch();
        return [
            'pending_verification' => (int)($row['pending_verification'] ?? 0),
            'pending_approval' => (int)($row['pending_approval'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['pending_verification' => 0, 'pending_approval' => 0];
    }
}

function mp_user_pending_reason(array $user): string
{
    if ((string)($user['status'] ?? 'active') !== 'pending') {
        return '';
    }
    return trim((string)($user['email_verified_at'] ?? '')) === '' ? 'Email verification' : 'Admin approval';
}

function mp_current_user_can(string $capability, ?array $subject = null): bool
{
    $user = mp_current_user();
    if ((int)($user['id'] ?? 0) < 1 || (string)($user['status'] ?? '') !== 'active') {
        return false;
    }
    $role = mp_normalize_role((string)($user['role'] ?? 'user'));
    if ($role === 'administrator') {
        return true;
    }

    if ($role === 'user') {
        if (in_array($capability, ['view_admin', 'manage_media', 'comment', 'edit_profile'], true)) {
            return true;
        }
        if ($capability === 'edit_content') {
            if (!$subject) {
                return true;
            }
            $authorId = (int)($subject['author_id'] ?? 0);
            return $authorId > 0 && $authorId === (int)($user['id'] ?? 0);
        }
        if ($capability === 'publish_content') {
            if (!mp_standard_users_publish_directly()) {
                return false;
            }
            if (!$subject) {
                return true;
            }
            $authorId = (int)($subject['author_id'] ?? 0);
            return $authorId > 0 && $authorId === (int)($user['id'] ?? 0);
        }
        if ($capability === 'restore_revisions') {
            if (!$subject) {
                return true;
            }
            $authorId = (int)($subject['author_id'] ?? 0);
            return $authorId > 0 && $authorId === (int)($user['id'] ?? 0);
        }
        if ($capability === 'restore_trash') {
            if (!$subject) {
                return false;
            }
            $authorId = (int)($subject['author_id'] ?? $subject['deleted_by'] ?? 0);
            return $authorId > 0 && $authorId === (int)($user['id'] ?? 0);
        }
    }

    if ($role === 'commenter') {
        return in_array($capability, ['comment', 'edit_profile'], true);
    }

    return false;
}

function mp_admin_route_capability(string $script): ?string
{
    return match ($script) {
        'index.php', 'welcome.php', 'help.php', 'user.php' => 'view_admin',
        'content.php', 'new.php', 'edit.php', 'preview.php', 'preview-current.php', 'quick-edit.php', 'delete.php', 'restore.php', 'delete-permanent.php', 'submit-review.php' => 'edit_content',
        'publish.php', 'unpublish.php' => 'publish_content',
        'pages.php', 'page-new.php', 'page-edit.php', 'page-delete.php', 'page-publish.php', 'page-unpublish.php', 'page-restore.php', 'page-delete-permanent.php' => 'manage_pages',
        'quick-post.php' => 'edit_content',
        'link-preview.php' => 'edit_content',
        'autosave.php' => 'edit_content',
        'media.php', 'media-upload.php', 'media-edit.php', 'media-picker.php', 'media-regenerate.php' => 'manage_media',
        'comments.php' => 'manage_comments',
        'revisions.php', 'compare-revision.php', 'restore-revision.php' => 'restore_revisions',
        'appearance.php', 'theme.php', 'theme-details.php', 'theme-settings.php', 'navigation.php', 'site-identity.php' => 'manage_appearance',
        'theme-install.php', 'theme-delete.php' => 'view_system',
        'settings.php', 'settings-writing.php', 'settings-reading.php', 'registration.php', 'mail.php' => 'manage_settings',
        'users.php', 'user-edit.php' => 'manage_users',
        'tools.php', 'upgrade.php', 'export.php', 'import.php', 'import-markdown.php', 'system-check.php', 'security.php' => 'view_system',
        default => null,
    };
}

function mp_enforce_admin_route_capability(): void
{
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $capability = mp_admin_route_capability($script);
    if ($capability !== null) {
        mp_require_capability($capability);
    }
}

function mp_filter_content_items_for_current_user(array $items): array
{
    $user = mp_current_user();
    if (mp_normalize_role((string)($user['role'] ?? 'user')) !== 'user') {
        return $items;
    }
    return array_values(array_filter($items, function ($item) {
        if (($item['content_status'] ?? '') === 'trash') {
            $authorId = (int)($item['original_author_id'] ?? $item['author_id'] ?? $item['deleted_by'] ?? 0);
            return $authorId > 0 && $authorId === (int)(mp_current_user()['id'] ?? 0);
        }
        $section = ((string)($item['content_status'] ?? 'draft')) === 'published' ? 'published' : 'drafts';
        $subject = function_exists('mp_content_subject_for_file') ? mp_content_subject_for_file($section, (string)($item['filename'] ?? ''), $item) : $item;
        return mp_current_user_can('edit_content', $subject);
    }));
}

function mp_current_user_has_standard_user_role(): bool
{
    $user = mp_current_user();
    return mp_normalize_role((string)($user['role'] ?? 'user')) === 'user';
}

function mp_require_trash_item_access(int $id): array
{
    $item = function_exists('mp_get_trash_item') ? mp_get_trash_item($id) : null;
    if (!$item) {
        mp_abort_request('Trash item not found.', 404);
    }
    $authorId = (int)($item['original_author_id'] ?? $item['author_id'] ?? $item['deleted_by'] ?? 0);
    mp_require_capability('restore_trash', ['author_id' => $authorId]);
    return $item;
}

function mp_require_revision_access(array $revision): void
{
    $authorId = function_exists('mp_revision_original_author_id') ? mp_revision_original_author_id($revision) : (int)($revision['author_id'] ?? 0);
    mp_require_capability('restore_revisions', ['author_id' => (int)$authorId]);
}

function mp_require_content_file_access(string $section, string $filename, string $capability = 'edit_content', array $page = []): void
{
    $subject = function_exists('mp_content_subject_for_file') ? mp_content_subject_for_file($section, $filename, $page) : $page;
    mp_require_capability($capability, $subject);
}

function mp_require_capability(string $capability, ?array $subject = null): void
{
    if (!mp_current_user_can($capability, $subject)) {
        mp_abort_request('You do not have permission to access this area.', 403);
    }
}

function mp_list_users(): array
{
    mp_require_installed();
    $stmt = mp_db()->query('SELECT id, username, display_name, email, email_verified_at, role, status, created_at, updated_at FROM ' . mp_table('users') . ' ORDER BY display_name ASC, username ASC');
    return $stmt->fetchAll() ?: [];
}

function mp_create_user(string $username, string $displayName, string $email, string $role, string $password, string $status = 'active', bool $markEmailVerified = true): array
{
    $username = mp_normalize_username($username);
    $displayName = trim($displayName);
    $email = strtolower(trim($email));
    $role = mp_normalize_role($role);
    $status = mp_normalize_user_status($status);
    if ($role === 'administrator' && !mp_current_user_can('manage_users')) {
        throw new RuntimeException('Only an admin can create another admin account.');
    }
    if (strlen($username) < 3) { throw new RuntimeException('Username must be at least 3 characters.'); }
    if ($displayName === '') { throw new RuntimeException('Display name cannot be empty.'); }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('Enter a valid email address or leave it blank.'); }
    mp_validate_password_policy($password, $username, $email);
    $existing = mp_find_user_by_username_any($username);
    if ($existing) { throw new RuntimeException('That username is already taken.'); }
    $stmt = mp_db()->prepare('INSERT INTO ' . mp_table('users') . ' (username, display_name, email, email_verified_at, password_hash, role, status, created_at, updated_at) VALUES (:username, :display_name, :email, :email_verified_at, :password_hash, :role, :status, NOW(), NOW())');
    $stmt->execute([
        'username' => $username,
        'display_name' => $displayName,
        'email' => $email,
        'email_verified_at' => $markEmailVerified ? date('Y-m-d H:i:s') : null,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'status' => $status,
    ]);
    return mp_find_user_by_id_any((int)mp_db()->lastInsertId()) ?? [];
}

function mp_update_user_role_status(int $id, string $role, string $status): void
{
    $currentId = (int)(mp_current_user()['id'] ?? 0);
    $role = mp_normalize_role($role);
    $status = mp_normalize_user_status($status);
    if ($id === $currentId && $status !== 'active') {
        throw new RuntimeException('You cannot deactivate your own account.');
    }
    $emailVerifiedSql = $status === 'active' ? ', email_verified_at = COALESCE(email_verified_at, NOW()), verification_token_hash = NULL, verification_token_expires_at = NULL' : '';
    $stmt = mp_db()->prepare('UPDATE ' . mp_table('users') . ' SET role = :role, status = :status' . $emailVerifiedSql . ', updated_at = NOW() WHERE id = :id');
    $stmt->execute(['role' => $role, 'status' => $status, 'id' => $id]);
}


function mp_active_administrator_count(?int $excludeUserId = null): int
{
    $sql = 'SELECT COUNT(*) FROM ' . mp_table('users') . " WHERE role = 'administrator' AND status = 'active'";
    $params = [];
    if ($excludeUserId !== null && $excludeUserId > 0) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeUserId;
    }
    $stmt = mp_db()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function mp_user_delete_reassign_targets(int $excludeUserId): array
{
    mp_require_installed();
    $stmt = mp_db()->prepare('SELECT id, username, display_name, role, status FROM ' . mp_table('users') . ' WHERE id <> :id AND status = :status ORDER BY display_name ASC, username ASC');
    $stmt->execute(['id' => $excludeUserId, 'status' => 'active']);
    return $stmt->fetchAll() ?: [];
}

function mp_admin_update_user_account(int $id, string $username, string $displayName, string $email, string $role, string $status, string $profileVisibility, bool $emailVerified): array
{
    mp_require_capability('manage_users');
    $existing = mp_find_user_by_id_any($id);
    if (!$existing) {
        throw new RuntimeException('User was not found.');
    }

    $currentId = (int)(mp_current_user()['id'] ?? 0);
    $username = mp_normalize_username($username);
    $displayName = trim($displayName);
    $email = strtolower(trim($email));
    $role = mp_normalize_role($role);
    $status = mp_normalize_user_status($status);
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

    $stmt = mp_db()->prepare('SELECT id FROM ' . mp_table('users') . ' WHERE username = :username AND id <> :id LIMIT 1');
    $stmt->execute(['username' => $username, 'id' => $id]);
    if ($stmt->fetchColumn() !== false) {
        throw new RuntimeException('That username is already taken.');
    }

    $wasActiveAdmin = mp_normalize_role((string)($existing['role'] ?? 'commenter')) === 'administrator' && (string)($existing['status'] ?? '') === 'active';
    $willRemainActiveAdmin = $role === 'administrator' && $status === 'active';
    if ($wasActiveAdmin && !$willRemainActiveAdmin && mp_active_administrator_count($id) < 1) {
        throw new RuntimeException('Create or activate another admin before changing this admin role or status.');
    }
    if ($id === $currentId && ($role !== 'administrator' || $status !== 'active')) {
        throw new RuntimeException('You cannot remove your own active admin access.');
    }

    $stmt = mp_db()->prepare('UPDATE ' . mp_table('users') . ' SET username = :username, display_name = :display_name, email = :email, role = :role, status = :status, profile_visibility = :profile_visibility, email_verified_at = :email_verified_at, verification_token_hash = NULL, verification_token_expires_at = NULL, updated_at = NOW() WHERE id = :id');
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

    $current = mp_current_user();
    if ($id === (int)($current['id'] ?? 0) && $role === 'administrator') {
        mp_set_setting('author_name', $displayName);
    }

    return mp_find_user_by_id_any($id) ?? [];
}

function mp_admin_reset_user_password(int $id, string $password, string $confirmPassword): void
{
    mp_require_capability('manage_users');
    $user = mp_find_user_by_id_any($id);
    if (!$user) {
        throw new RuntimeException('User was not found.');
    }
    if ($password !== $confirmPassword) {
        throw new RuntimeException('Password and confirmation do not match.');
    }
    mp_validate_password_policy($password, (string)($user['username'] ?? ''), (string)($user['email'] ?? ''));

    $pdo = mp_db();
    $stmt = $pdo->prepare('UPDATE ' . mp_table('users') . ' SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'id' => $id,
    ]);

    try {
        $stmt = $pdo->prepare('UPDATE ' . mp_table('password_reset_tokens') . ' SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL');
        $stmt->execute(['user_id' => $id]);
    } catch (Throwable $e) {
        // Password reset token cleanup should not block an admin password reset.
    }
}

function mp_admin_delete_user(int $id, int $reassignToUserId, string $confirmation): void
{
    mp_require_capability('manage_users');
    $user = mp_find_user_by_id_any($id);
    if (!$user) {
        throw new RuntimeException('User was not found.');
    }

    $currentId = (int)(mp_current_user()['id'] ?? 0);
    if ($id === $currentId) {
        throw new RuntimeException('You cannot delete your own account while signed in.');
    }

    $username = (string)($user['username'] ?? '');
    if (mp_normalize_username($confirmation) !== mp_normalize_username($username)) {
        throw new RuntimeException('Type the username exactly to confirm account deletion.');
    }

    $isActiveAdmin = mp_normalize_role((string)($user['role'] ?? 'commenter')) === 'administrator' && (string)($user['status'] ?? '') === 'active';
    if ($isActiveAdmin && mp_active_administrator_count($id) < 1) {
        throw new RuntimeException('Create or activate another admin before deleting this admin account.');
    }

    $reassignToUserId = max(0, $reassignToUserId);
    if ($reassignToUserId === $id) {
        throw new RuntimeException('Choose a different account for reassigned content.');
    }
    $reassignTargetId = null;
    if ($reassignToUserId > 0) {
        $target = mp_find_user_by_id_any($reassignToUserId);
        if (!$target || (string)($target['status'] ?? '') !== 'active') {
            throw new RuntimeException('Choose an active account for reassigned content.');
        }
        $reassignTargetId = (int)$target['id'];
    }

    $pdo = mp_db();
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
                $stmt = $pdo->prepare('UPDATE ' . mp_table($table) . ' SET ' . $column . ' = :target WHERE ' . $column . ' = :id');
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
                $stmt = $pdo->prepare('UPDATE ' . mp_table($table) . ' SET ' . $column . ' = NULL WHERE ' . $column . ' = :id');
                $stmt->execute(['id' => $id]);
            } catch (Throwable $e) {
                // Optional/legacy tables or NOT NULL columns should not block core account deletion.
            }
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM ' . mp_table('password_reset_tokens') . ' WHERE user_id = :id');
            $stmt->execute(['id' => $id]);
        } catch (Throwable $e) {
            // Cleanup-only.
        }

        $stmt = $pdo->prepare('DELETE FROM ' . mp_table('users') . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if (function_exists('mp_user_avatar_delete_file')) {
        mp_user_avatar_delete_file((string)($user['avatar_path'] ?? ''));
    }
}

function mp_profile_social_link_definitions(): array
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

function mp_profile_social_links_decode(mixed $raw): array
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

function mp_normalize_profile_social_url(string $url, string $label): string
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

function mp_normalize_profile_social_label(string $label, string $fallback): string
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

function mp_profile_social_link_form_values(array $user): array
{
    $values = [];
    foreach (mp_profile_social_link_definitions() as $id => $definition) {
        $values[$id] = '';
    }
    $values['custom_1_label'] = '';
    $values['custom_1_url'] = '';
    $values['custom_2_label'] = '';
    $values['custom_2_url'] = '';

    $customIndex = 1;
    foreach (mp_profile_social_links_decode($user['social_links'] ?? '') as $item) {
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

function mp_normalize_profile_social_links_from_input(array $input): string
{
    $links = [];
    foreach (mp_profile_social_link_definitions() as $id => $definition) {
        $label = (string)($definition['label'] ?? ucfirst($id));
        $url = mp_normalize_profile_social_url((string)($input[$id] ?? ''), $label);
        if ($url !== '') {
            $links[] = ['id' => $id, 'label' => $label, 'url' => $url];
        }
    }

    for ($i = 1; $i <= 2; $i++) {
        $labelKey = 'custom_' . $i . '_label';
        $urlKey = 'custom_' . $i . '_url';
        $rawLabel = trim((string)($input[$labelKey] ?? ''));
        $url = mp_normalize_profile_social_url((string)($input[$urlKey] ?? ''), 'Custom link ' . $i);
        if ($url === '') {
            continue;
        }
        if ($rawLabel === '') {
            throw new RuntimeException('Custom link ' . $i . ' needs a label.');
        }
        $links[] = [
            'id' => 'custom_' . $i,
            'label' => mp_normalize_profile_social_label($rawLabel, 'Custom Link ' . $i),
            'url' => $url,
        ];
    }

    return json_encode($links, JSON_UNESCAPED_SLASHES) ?: '[]';
}

function mp_profile_social_links_for_user(array $user): array
{
    $links = [];
    foreach (mp_profile_social_links_decode($user['social_links'] ?? '') as $item) {
        if (!is_array($item)) {
            continue;
        }
        $label = mp_normalize_profile_social_label((string)($item['label'] ?? ''), 'Link');
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

function mp_update_current_user_profile(string $username, string $displayName, string $email = '', string $bio = '', string $website = '', string $profileVisibility = 'public', array $socialLinksInput = []): array
{
    $current = mp_current_user();
    $currentId = (int)($current['id'] ?? 0);
    $username = mp_normalize_username($username);
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

    $socialLinks = mp_normalize_profile_social_links_from_input($socialLinksInput);

    $stmt = mp_db()->prepare('SELECT id FROM ' . mp_table('users') . ' WHERE username = :username AND id <> :id LIMIT 1');
    $stmt->execute(['username' => $username, 'id' => $currentId]);
    if ($stmt->fetchColumn() !== false) {
        throw new RuntimeException('That username is already taken.');
    }

    $stmt = mp_db()->prepare('UPDATE ' . mp_table('users') . ' SET username = :username, display_name = :display_name, email = :email, bio = :bio, website = :website, social_links = :social_links, profile_visibility = :profile_visibility, updated_at = NOW() WHERE id = :id');
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

    if (mp_normalize_role((string)($current['role'] ?? 'commenter')) === 'administrator') {
        mp_set_setting('author_name', $displayName);
    }
    return mp_find_user_by_id($currentId) ?? mp_current_user();
}

function mp_create_commenter_account(string $username, string $displayName, string $email, string $password, string $confirmPassword): array
{
    if (function_exists('mp_registration_create_public_account')) {
        $result = mp_registration_create_public_account($username, $displayName, $email, $password, $confirmPassword);
        return is_array($result['user'] ?? null) ? $result['user'] : [];
    }
    if (mp_setting_or_config('comment_registration_enabled', '1') !== '1') {
        throw new RuntimeException('Comment account registration is currently closed.');
    }
    if ($password !== $confirmPassword) {
        throw new RuntimeException('Password and confirmation do not match.');
    }
    return mp_create_user($username, $displayName, $email, 'commenter', $password);
}

function mp_update_current_user_password(string $currentPassword, string $newPassword, string $confirmPassword): void
{
    $current = mp_current_user();
    $currentId = (int)($current['id'] ?? 0);
    $hash = (string)($current['password_hash'] ?? '');

    if ($hash === '' || !password_verify($currentPassword, $hash)) {
        throw new RuntimeException('Current password did not match.');
    }

    mp_validate_password_policy($newPassword, (string)($current['username'] ?? ''), (string)($current['email'] ?? ''));

    if ($newPassword !== $confirmPassword) {
        throw new RuntimeException('New password and confirmation do not match.');
    }

    $stmt = mp_db()->prepare('UPDATE ' . mp_table('users') . ' SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id' => $currentId,
    ]);
}

function mp_is_logged_in(): bool
{
    if (empty($_SESSION['mp_logged_in']) || empty($_SESSION['mp_user_id'])) {
        return false;
    }

    $user = mp_find_user_by_id((int)$_SESSION['mp_user_id']);
    if (!$user) {
        mp_clear_login_session();
        return false;
    }

    return true;
}

function mp_require_login(): void
{
    mp_require_installed();
    if (!mp_is_logged_in()) {
        mp_redirect(mp_admin_url('login.php'));
    }
    mp_enforce_admin_route_capability();
}

function mp_attempt_login(string $username, string $password): bool
{
    mp_require_installed();

    if (mp_login_rate_limited($username)) {
        return false;
    }

    $user = mp_find_user_by_username($username);
    $hash = (string)($user['password_hash'] ?? '');

    if ($user && $hash !== '' && password_verify($password, $hash)) {
        session_regenerate_id(true);
        $_SESSION['mp_logged_in'] = true;
        $_SESSION['mp_user_id'] = (int)$user['id'];
        mp_record_login_attempt($username, true);
        return true;
    }

    mp_record_login_attempt($username, false);
    return false;
}

function mp_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function mp_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function mp_verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        mp_abort_request('Invalid request token.', 403);
    }
}
