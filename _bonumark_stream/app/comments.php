<?php
require_once __DIR__ . '/profiles.php';

function bms_comments_enabled(): bool
{
    return (string)bms_setting_or_config('comments_enabled', '1') === '1';
}

function bms_comment_registration_enabled(): bool
{
    if (function_exists('bms_public_registration_enabled') && function_exists('bms_registration_default_role')) {
        return bms_public_registration_enabled() && bms_registration_default_role() === 'commenter';
    }
    return (string)bms_setting_or_config('comment_registration_enabled', '1') === '1';
}

function bms_comment_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $ip . '|' . (string)(bms_config()['security_salt'] ?? 'bonumark'));
}

function bms_comment_user_agent_hash(): string
{
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    return hash('sha256', $ua . '|' . (string)(bms_config()['security_salt'] ?? 'bonumark'));
}

function bms_comment_status_label(string $status): string
{
    return match ($status) {
        'approved' => 'Approved',
        'pending' => 'Pending',
        'trash' => 'Trash',
        default => 'Pending',
    };
}

function bms_comment_normalize_status(string $status): string
{
    return in_array($status, ['approved', 'pending', 'trash'], true) ? $status : 'pending';
}

function bms_find_published_post_for_comment(string $slug): ?array
{
    $slug = bms_slugify($slug);
    if ($slug === '') {
        return null;
    }
    try {
        $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('posts') . ' WHERE slug = :slug AND status = :status LIMIT 1');
        $stmt->execute(['slug' => $slug, 'status' => 'published']);
        $post = $stmt->fetch();
        return is_array($post) ? $post : null;
    } catch (Throwable $e) {
        return null;
    }
}

function bms_comment_count_for_slug(string $slug): int
{
    if (!bms_is_installed()) {
        return 0;
    }
    try {
        $stmt = bms_db()->prepare('SELECT COUNT(*) FROM ' . bms_table('comments') . ' WHERE post_slug = :post_slug AND status = :status');
        $stmt->execute(['post_slug' => bms_slugify($slug), 'status' => 'approved']);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function bms_comment_label(int $count): string
{
    return $count === 1 ? '1 Comment' : $count . ' Comments';
}

function bms_list_comments_for_slug(string $slug, bool $includeModeration = false): array
{
    if (!bms_is_installed()) {
        return [];
    }
    $where = $includeModeration ? 'c.status IN (\'approved\',\'pending\')' : 'c.status = \'approved\'';
    $sql = 'SELECT c.*, u.username, u.display_name, u.role, u.profile_visibility, u.avatar_path FROM ' . bms_table('comments') . ' c INNER JOIN ' . bms_table('users') . ' u ON u.id = c.user_id WHERE c.post_slug = :post_slug AND ' . $where . ' ORDER BY c.created_at ASC, c.id ASC';
    $stmt = bms_db()->prepare($sql);
    $stmt->execute(['post_slug' => bms_slugify($slug)]);
    return $stmt->fetchAll() ?: [];
}

function bms_create_comment(string $slug, string $body): array
{
    if (!bms_comments_enabled()) {
        throw new RuntimeException('Comments are closed.');
    }
    $user = bms_current_user();
    if ((int)($user['id'] ?? 0) < 1 || !bms_current_user_can('comment')) {
        throw new RuntimeException('Sign in with a comment account before commenting.');
    }
    $post = bms_find_published_post_for_comment($slug);
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
    $status = bms_comment_normalize_status((string)bms_setting_or_config('comments_default_status', 'approved'));
    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('comments') . ' (post_slug, post_id, user_id, parent_id, body, status, ip_hash, user_agent_hash, created_at, updated_at, approved_at) VALUES (:post_slug, :post_id, :user_id, NULL, :body, :status, :ip_hash, :user_agent_hash, NOW(), NOW(), :approved_at)');
    $stmt->execute([
        'post_slug' => bms_slugify($slug),
        'post_id' => (int)($post['id'] ?? 0),
        'user_id' => (int)$user['id'],
        'body' => $body,
        'status' => $status,
        'ip_hash' => bms_comment_ip_hash(),
        'user_agent_hash' => bms_comment_user_agent_hash(),
        'approved_at' => $status === 'approved' ? gmdate('Y-m-d H:i:s') : null,
    ]);
    return bms_find_comment_by_id((int)bms_db()->lastInsertId()) ?? [];
}

function bms_find_comment_by_id(int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $stmt = bms_db()->prepare('SELECT c.*, u.username, u.display_name, u.role, u.profile_visibility, u.avatar_path FROM ' . bms_table('comments') . ' c INNER JOIN ' . bms_table('users') . ' u ON u.id = c.user_id WHERE c.id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bms_update_comment_status(int $id, string $status): void
{
    $status = bms_comment_normalize_status($status);
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('comments') . ' SET status = :status, updated_at = NOW(), approved_at = CASE WHEN :approval_status = \'approved\' THEN COALESCE(approved_at, NOW()) ELSE approved_at END WHERE id = :id');
    $stmt->execute(['status' => $status, 'approval_status' => $status, 'id' => $id]);
}

function bms_delete_comment_permanently(int $id): void
{
    $stmt = bms_db()->prepare('DELETE FROM ' . bms_table('comments') . ' WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

function bms_list_admin_comments(string $status = 'approved', int $limit = 100): array
{
    $status = bms_comment_normalize_status($status);
    $stmt = bms_db()->prepare('SELECT c.*, u.username, u.display_name, p.title AS post_title FROM ' . bms_table('comments') . ' c INNER JOIN ' . bms_table('users') . ' u ON u.id = c.user_id LEFT JOIN ' . bms_table('posts') . ' p ON p.id = c.post_id WHERE c.status = :status ORDER BY c.created_at DESC LIMIT ' . max(1, min(200, $limit)));
    $stmt->execute(['status' => $status]);
    return $stmt->fetchAll() ?: [];
}

function bms_comments_view_data(string $slug, string $notice = ''): array
{
    $slug = bms_slugify($slug);
    $comments = [];
    foreach (bms_list_comments_for_slug($slug) as $comment) {
        $comments[] = [
            'author_name' => (string)($comment['display_name'] ?? 'Commenter'),
            'username' => (string)($comment['username'] ?? ''),
            'profile_url' => function_exists('bms_public_profile_url_for_user') ? bms_public_profile_url_for_user($comment) : bms_url_path('profile.php?user=' . rawurlencode((string)($comment['username'] ?? ''))),
            'avatar_html' => function_exists('bms_user_avatar_markup') ? bms_user_avatar_markup($comment, 'comment-avatar-image', 96, 96, false) : '<span class="stream-author-avatar stream-author-initials">' . htmlspecialchars(bms_user_initials($comment), ENT_QUOTES, 'UTF-8') . '</span>',
            'body' => (string)($comment['body'] ?? ''),
            'created_at' => (string)($comment['created_at'] ?? ''),
            'raw' => $comment,
        ];
    }

    $commentReturnTo = bms_stream_url($slug) . '#comments';
    $canCreateCommentAccount = bms_comment_registration_enabled() && (!function_exists('bms_registration_require_email_verification') || !bms_registration_require_email_verification() || bms_registration_mail_ready());

    return [
        'slug' => $slug,
        'notice' => $notice,
        'comments_enabled' => bms_comments_enabled(),
        'count' => bms_comment_count_for_slug($slug),
        'label' => bms_comment_label(bms_comment_count_for_slug($slug)),
        'comments' => $comments,
        'can_comment' => bms_is_logged_in() && bms_current_user_can('comment'),
        'can_create_comment_account' => $canCreateCommentAccount,
        'login_url' => bms_url_path('account.php?return_to=' . rawurlencode($commentReturnTo)),
        'register_url' => bms_url_path('account.php?action=register&return_to=' . rawurlencode($commentReturnTo) . '#create-account'),
        'csrf' => function_exists('bms_csrf_token') ? bms_csrf_token() : '',
        'comments_url' => bms_url_path('comments.php'),
    ];
}

function bms_render_comments_panel(string $slug, string $notice = ''): string
{
    return bms_render_public_theme_template('comments', bms_comments_view_data($slug, $notice));
}


function bms_comments_preview_view_data(array $page): array
{
    $slug = bms_slugify((string)($page['slug'] ?? ''));
    return [
        'page' => $page,
        'slug' => $slug,
        'notice' => 'Comments are disabled in draft preview. Publish the post before using public comments.',
        'comments_enabled' => false,
        'count' => 0,
        'label' => 'Comments',
        'comments' => [],
        'can_comment' => false,
        'can_create_comment_account' => false,
        'login_url' => '#',
        'register_url' => '#',
        'csrf' => '',
        'comments_url' => '#',
        'preview_mode' => true,
    ];
}

function bms_render_comments_preview_panel(array $page): string
{
    return bms_render_public_theme_template('comments', bms_comments_preview_view_data($page));
}

function bms_comments_mount_view_data(array $page): ?array
{
    if (!bms_comments_enabled()) {
        return null;
    }
    $slug = bms_slugify((string)($page['slug'] ?? ''));
    if ($slug === '') {
        return null;
    }

    return [
        'page' => $page,
        'slug' => $slug,
        'endpoint' => bms_url_path('comments.php'),
        'noscript_url' => bms_url_path('comments.php?slug=' . rawurlencode($slug)),
        'loading_text' => 'Loading comments...',
        'noscript_text' => 'View comments',
    ];
}

function bms_render_comments_mount(array $page): string
{
    if (function_exists('bms_public_preview_mode') && bms_public_preview_mode()) {
        return bms_render_comments_preview_panel($page);
    }
    $view = bms_comments_mount_view_data($page);
    if ($view === null) {
        return '';
    }

    return bms_render_public_theme_template('comments-mount', $view);
}

