<?php
require_once __DIR__ . '/profiles.php';

function mp_comments_enabled(): bool
{
    return (string)mp_setting_or_config('comments_enabled', '1') === '1';
}

function mp_comment_registration_enabled(): bool
{
    if (function_exists('mp_public_registration_enabled') && function_exists('mp_registration_default_role')) {
        return mp_public_registration_enabled() && mp_registration_default_role() === 'commenter';
    }
    return (string)mp_setting_or_config('comment_registration_enabled', '1') === '1';
}

function mp_comment_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $ip . '|' . (string)(mp_config()['security_salt'] ?? 'bonumark'));
}

function mp_comment_user_agent_hash(): string
{
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    return hash('sha256', $ua . '|' . (string)(mp_config()['security_salt'] ?? 'bonumark'));
}

function mp_comment_status_label(string $status): string
{
    return match ($status) {
        'approved' => 'Approved',
        'pending' => 'Pending',
        'trash' => 'Trash',
        default => 'Pending',
    };
}

function mp_comment_normalize_status(string $status): string
{
    return in_array($status, ['approved', 'pending', 'trash'], true) ? $status : 'pending';
}

function mp_find_published_post_for_comment(string $slug): ?array
{
    $slug = mp_slugify($slug);
    if ($slug === '') {
        return null;
    }
    try {
        $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('posts') . ' WHERE slug = :slug AND status = :status LIMIT 1');
        $stmt->execute(['slug' => $slug, 'status' => 'published']);
        $post = $stmt->fetch();
        return is_array($post) ? $post : null;
    } catch (Throwable $e) {
        return null;
    }
}

function mp_comment_count_for_slug(string $slug): int
{
    if (!mp_is_installed()) {
        return 0;
    }
    try {
        $stmt = mp_db()->prepare('SELECT COUNT(*) FROM ' . mp_table('comments') . ' WHERE post_slug = :post_slug AND status = :status');
        $stmt->execute(['post_slug' => mp_slugify($slug), 'status' => 'approved']);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function mp_comment_label(int $count): string
{
    return $count === 1 ? '1 Comment' : $count . ' Comments';
}

function mp_list_comments_for_slug(string $slug, bool $includeModeration = false): array
{
    if (!mp_is_installed()) {
        return [];
    }
    $where = $includeModeration ? 'c.status IN (\'approved\',\'pending\')' : 'c.status = \'approved\'';
    $sql = 'SELECT c.*, u.username, u.display_name, u.role, u.profile_visibility, u.avatar_path FROM ' . mp_table('comments') . ' c INNER JOIN ' . mp_table('users') . ' u ON u.id = c.user_id WHERE c.post_slug = :post_slug AND ' . $where . ' ORDER BY c.created_at ASC, c.id ASC';
    $stmt = mp_db()->prepare($sql);
    $stmt->execute(['post_slug' => mp_slugify($slug)]);
    return $stmt->fetchAll() ?: [];
}

function mp_create_comment(string $slug, string $body): array
{
    if (!mp_comments_enabled()) {
        throw new RuntimeException('Comments are closed.');
    }
    $user = mp_current_user();
    if ((int)($user['id'] ?? 0) < 1 || !mp_current_user_can('comment')) {
        throw new RuntimeException('Sign in with a comment account before commenting.');
    }
    $post = mp_find_published_post_for_comment($slug);
    if (!$post) {
        throw new RuntimeException('That Stream Post is not available for comments.');
    }
    $body = trim($body);
    if ($body === '') {
        throw new RuntimeException('Comment cannot be empty.');
    }
    if (strlen($body) > 5000) {
        throw new RuntimeException('Comment is too long. Keep it under 5000 characters.');
    }
    $status = mp_comment_normalize_status((string)mp_setting_or_config('comments_default_status', 'approved'));
    $stmt = mp_db()->prepare('INSERT INTO ' . mp_table('comments') . ' (post_slug, post_id, user_id, parent_id, body, status, ip_hash, user_agent_hash, created_at, updated_at, approved_at) VALUES (:post_slug, :post_id, :user_id, NULL, :body, :status, :ip_hash, :user_agent_hash, NOW(), NOW(), :approved_at)');
    $stmt->execute([
        'post_slug' => mp_slugify($slug),
        'post_id' => (int)($post['id'] ?? 0),
        'user_id' => (int)$user['id'],
        'body' => $body,
        'status' => $status,
        'ip_hash' => mp_comment_ip_hash(),
        'user_agent_hash' => mp_comment_user_agent_hash(),
        'approved_at' => $status === 'approved' ? date('Y-m-d H:i:s') : null,
    ]);
    return mp_find_comment_by_id((int)mp_db()->lastInsertId()) ?? [];
}

function mp_find_comment_by_id(int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $stmt = mp_db()->prepare('SELECT c.*, u.username, u.display_name, u.role, u.profile_visibility, u.avatar_path FROM ' . mp_table('comments') . ' c INNER JOIN ' . mp_table('users') . ' u ON u.id = c.user_id WHERE c.id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function mp_update_comment_status(int $id, string $status): void
{
    $status = mp_comment_normalize_status($status);
    $stmt = mp_db()->prepare('UPDATE ' . mp_table('comments') . ' SET status = :status, updated_at = NOW(), approved_at = CASE WHEN :status = \'approved\' THEN COALESCE(approved_at, NOW()) ELSE approved_at END WHERE id = :id');
    $stmt->execute(['status' => $status, 'id' => $id]);
}

function mp_delete_comment_permanently(int $id): void
{
    $stmt = mp_db()->prepare('DELETE FROM ' . mp_table('comments') . ' WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

function mp_list_admin_comments(string $status = 'approved', int $limit = 100): array
{
    $status = mp_comment_normalize_status($status);
    $stmt = mp_db()->prepare('SELECT c.*, u.username, u.display_name, p.title AS post_title FROM ' . mp_table('comments') . ' c INNER JOIN ' . mp_table('users') . ' u ON u.id = c.user_id LEFT JOIN ' . mp_table('posts') . ' p ON p.id = c.post_id WHERE c.status = :status ORDER BY c.created_at DESC LIMIT ' . max(1, min(200, $limit)));
    $stmt->execute(['status' => $status]);
    return $stmt->fetchAll() ?: [];
}

function mp_comments_view_data(string $slug, string $notice = ''): array
{
    $slug = mp_slugify($slug);
    $comments = [];
    foreach (mp_list_comments_for_slug($slug) as $comment) {
        $comments[] = [
            'author_name' => (string)($comment['display_name'] ?? 'Commenter'),
            'username' => (string)($comment['username'] ?? ''),
            'profile_url' => function_exists('mp_public_profile_url_for_user') ? mp_public_profile_url_for_user($comment) : mp_url_path('profile.php?user=' . rawurlencode((string)($comment['username'] ?? ''))),
            'avatar_html' => function_exists('mp_user_avatar_markup') ? mp_user_avatar_markup($comment, 'comment-avatar-image', 96, 96, false) : '<span class="stream-author-avatar stream-author-initials">' . htmlspecialchars(mp_user_initials($comment), ENT_QUOTES, 'UTF-8') . '</span>',
            'body' => (string)($comment['body'] ?? ''),
            'created_at' => (string)($comment['created_at'] ?? ''),
            'raw' => $comment,
        ];
    }

    $commentReturnTo = mp_stream_url($slug) . '#comments';
    $canCreateCommentAccount = mp_comment_registration_enabled() && (!function_exists('mp_registration_require_email_verification') || !mp_registration_require_email_verification() || mp_registration_mail_ready());

    return [
        'slug' => $slug,
        'notice' => $notice,
        'comments_enabled' => mp_comments_enabled(),
        'count' => mp_comment_count_for_slug($slug),
        'label' => mp_comment_label(mp_comment_count_for_slug($slug)),
        'comments' => $comments,
        'can_comment' => mp_is_logged_in() && mp_current_user_can('comment'),
        'can_create_comment_account' => $canCreateCommentAccount,
        'login_url' => mp_url_path('account.php?return_to=' . rawurlencode($commentReturnTo)),
        'register_url' => mp_url_path('account.php?action=register&return_to=' . rawurlencode($commentReturnTo) . '#create-account'),
        'csrf' => function_exists('mp_csrf_token') ? mp_csrf_token() : '',
        'comments_url' => mp_url_path('comments.php'),
    ];
}

function mp_render_comments_panel(string $slug, string $notice = ''): string
{
    return mp_render_public_theme_template('comments', mp_comments_view_data($slug, $notice));
}

function mp_comments_mount_view_data(array $page): ?array
{
    if (!mp_comments_enabled()) {
        return null;
    }
    $slug = mp_slugify((string)($page['slug'] ?? ''));
    if ($slug === '') {
        return null;
    }

    return [
        'page' => $page,
        'slug' => $slug,
        'endpoint' => mp_url_path('comments.php'),
        'noscript_url' => mp_url_path('comments.php?slug=' . rawurlencode($slug)),
        'loading_text' => 'Loading comments...',
        'noscript_text' => 'View comments',
    ];
}

function mp_render_comments_mount(array $page): string
{
    $view = mp_comments_mount_view_data($page);
    if ($view === null) {
        return '';
    }

    return mp_render_public_theme_template('comments-mount', $view);
}

