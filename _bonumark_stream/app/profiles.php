<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/media.php';

function mp_public_profile_url_for_user(array $user): string
{
    $username = trim((string)($user['username'] ?? ''));
    if ($username !== '') {
        return mp_url_path('profile.php?user=' . rawurlencode(mp_normalize_username($username)));
    }

    $id = (int)($user['id'] ?? 0);
    if ($id > 0) {
        return mp_url_path('profile.php?id=' . $id);
    }

    return mp_url_path('profile.php');
}


function mp_author_archive_url_for_user(array $user): string
{
    $username = mp_normalize_username((string)($user['username'] ?? ''));
    if ($username !== '') {
        return mp_url_path('author/' . rawurlencode($username) . '/');
    }

    $id = (int)($user['id'] ?? 0);
    return $id > 0 ? mp_url_path('author.php?id=' . $id) : mp_url_path('author.php');
}

function mp_profile_user_is_viewable(array $user): bool
{
    if ((string)($user['status'] ?? '') !== 'active') {
        return false;
    }

    if ((string)($user['profile_visibility'] ?? 'public') !== 'private') {
        return true;
    }

    $current = function_exists('mp_current_user') ? mp_current_user() : [];
    return (int)($current['id'] ?? 0) === (int)($user['id'] ?? 0) || mp_current_user_can('manage_users');
}

function mp_find_public_user_by_id(int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    mp_require_installed();
    $stmt = mp_db()->prepare('SELECT id, username, display_name, email, role, status, bio, website, social_links, profile_visibility, avatar_path, created_at, updated_at FROM ' . mp_table('users') . ' WHERE id = :id AND status = :status LIMIT 1');
    $stmt->execute([
        'id' => $id,
        'status' => 'active',
    ]);
    $user = $stmt->fetch();
    if (!is_array($user)) {
        return null;
    }

    return mp_profile_user_is_viewable($user) ? $user : null;
}

function mp_find_public_user_by_username(string $username): ?array
{
    mp_require_installed();
    $stmt = mp_db()->prepare('SELECT id, username, display_name, email, role, status, bio, website, social_links, profile_visibility, avatar_path, created_at, updated_at FROM ' . mp_table('users') . ' WHERE username = :username AND status = :status LIMIT 1');
    $stmt->execute([
        'username' => mp_normalize_username($username),
        'status' => 'active',
    ]);
    $user = $stmt->fetch();
    if (!is_array($user)) {
        return null;
    }
    return mp_profile_user_is_viewable($user) ? $user : null;
}


function mp_find_public_user_by_handle(string $handle): ?array
{
    $user = mp_find_public_user_by_username($handle);
    if ($user) {
        return $user;
    }

    $wanted = mp_normalize_username($handle);
    if ($wanted === '') {
        return null;
    }

    try {
        $stmt = mp_db()->prepare('SELECT id, username, display_name, email, role, status, bio, website, social_links, profile_visibility, avatar_path, created_at, updated_at FROM ' . mp_table('users') . ' WHERE status = :status ORDER BY id ASC LIMIT 500');
        $stmt->execute(['status' => 'active']);
        foreach (($stmt->fetchAll() ?: []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidateUsername = mp_normalize_username((string)($candidate['username'] ?? ''));
            $candidateDisplay = mp_normalize_username((string)($candidate['display_name'] ?? ''));
            if ($wanted === $candidateUsername || $wanted === $candidateDisplay) {
                return mp_profile_user_is_viewable($candidate) ? $candidate : null;
            }
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

function mp_user_initials(array $user): string
{
    $name = trim((string)($user['display_name'] ?? $user['username'] ?? ''));
    if ($name === '') {
        return 'B';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }
    return $initials !== '' ? $initials : 'B';
}

function mp_user_avatar_variant_widths(): array
{
    return [96, 192];
}

function mp_user_avatar_normalize_path(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $path = ltrim($path, '/');
    if (!str_starts_with($path, 'media/')) {
        $path = 'media/' . $path;
    }

    return $path;
}

function mp_user_avatar_generate_variants(string $avatarPath): void
{
    if (!function_exists('mp_media_image_dimensions_for_public_path') || !function_exists('mp_media_generate_responsive_variant')) {
        return;
    }

    $relative = mp_user_avatar_normalize_path($avatarPath);
    if ($relative === '' || preg_match('#^https?://#i', $relative) || !str_starts_with($relative, 'media/avatars/')) {
        return;
    }

    $dimensions = mp_media_image_dimensions_for_public_path($relative);
    if (empty($dimensions['width']) || empty($dimensions['height'])) {
        return;
    }

    foreach (mp_user_avatar_variant_widths() as $width) {
        mp_media_generate_responsive_variant($relative, (int)$width, $dimensions);
    }
}

function mp_user_avatar_ensure_variant(string $avatarPath, int $targetWidth): string
{
    if (!function_exists('mp_media_generated_relative_path') || !function_exists('mp_media_image_dimensions_for_public_path') || !function_exists('mp_media_generate_responsive_variant') || !function_exists('mp_public_path')) {
        return '';
    }

    $targetWidth = (int)$targetWidth;
    if ($targetWidth < 1 || !in_array($targetWidth, mp_user_avatar_variant_widths(), true)) {
        return '';
    }

    $relative = mp_user_avatar_normalize_path($avatarPath);
    if ($relative === '' || preg_match('#^https?://#i', $relative) || !str_starts_with($relative, 'media/avatars/')) {
        return '';
    }

    $generated = mp_media_generated_relative_path($relative, $targetWidth);
    if ($generated !== '' && is_file(mp_public_path($generated))) {
        return $generated;
    }

    $dimensions = mp_media_image_dimensions_for_public_path($relative);
    if (empty($dimensions['width']) || empty($dimensions['height'])) {
        return '';
    }

    $generated = mp_media_generate_responsive_variant($relative, $targetWidth, $dimensions);
    if ($generated !== '' && is_file(mp_public_path($generated))) {
        return $generated;
    }

    return '';
}

function mp_user_avatar_variant_url(string $avatarPath, int $targetWidth = 192, bool $allowLargerFallback = true): string
{
    if (!function_exists('mp_media_generated_relative_path') || !function_exists('mp_public_path') || !function_exists('mp_url_path')) {
        return '';
    }

    $relative = mp_user_avatar_normalize_path($avatarPath);
    if ($relative === '' || preg_match('#^https?://#i', $relative) || !str_starts_with($relative, 'media/avatars/')) {
        return '';
    }

    $targetWidth = max(1, min(512, $targetWidth));
    $exact = mp_user_avatar_ensure_variant($relative, $targetWidth);
    if ($exact !== '') {
        return mp_url_path($exact);
    }

    $widths = mp_user_avatar_variant_widths();
    sort($widths, SORT_NUMERIC);
    $preferred = [];
    foreach ($widths as $width) {
        $width = (int)$width;
        if ($width <= $targetWidth) {
            $preferred[] = $width;
        }
    }
    rsort($preferred, SORT_NUMERIC);

    if ($allowLargerFallback) {
        foreach ($widths as $width) {
            $width = (int)$width;
            if ($width > $targetWidth) {
                $preferred[] = $width;
            }
        }
    }

    $preferred = array_values(array_unique($preferred));
    foreach ($preferred as $width) {
        $generated = mp_media_generated_relative_path($relative, (int)$width);
        if ($generated !== '' && is_file(mp_public_path($generated))) {
            return mp_url_path($generated);
        }
    }

    return '';
}

function mp_user_avatar_url(array $user, int $targetWidth = 192, bool $allowLargerFallback = true): string
{
    $path = mp_user_avatar_normalize_path((string)($user['avatar_path'] ?? ''));
    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    if (str_starts_with($path, 'media/avatars/')) {
        $variant = mp_user_avatar_variant_url($path, $targetWidth, $allowLargerFallback);
        if ($variant !== '') {
            return $variant;
        }
    }

    return mp_url_path($path);
}

function mp_user_avatar_markup(array $user, string $class = '', int $targetWidth = 96, ?int $displaySize = null, bool $allowLargerFallback = true): string
{
    $targetWidth = max(1, min(512, $targetWidth));
    $displaySize = $displaySize !== null ? max(1, min(512, $displaySize)) : $targetWidth;
    $url = mp_user_avatar_url($user, $targetWidth, $allowLargerFallback);
    $class = trim($class);
    $extraClass = $class !== '' ? ' ' . preg_replace('/[^a-zA-Z0-9_ -]/', '', $class) : '';
    if ($url !== '') {
        $name = trim((string)($user['display_name'] ?? $user['username'] ?? ''));
        $alt = $name !== '' ? $name : 'Profile picture';
        if (function_exists('mp_media_image_attributes')) {
            $attributes = mp_media_image_attributes($url, $alt, [
                'class' => 'stream-author-avatar stream-author-image' . $extraClass,
                'loading' => 'lazy',
                'decoding' => 'async',
                'width' => $displaySize,
                'height' => $displaySize,
            ]);
            return '<img ' . $attributes . '>';
        }
        return '<img class="stream-author-avatar stream-author-image' . $extraClass . '" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" width="' . $displaySize . '" height="' . $displaySize . '" loading="lazy" decoding="async">';
    }

    return '<span class="stream-author-avatar stream-author-initials' . $extraClass . '">' . htmlspecialchars(mp_user_initials($user), ENT_QUOTES, 'UTF-8') . '</span>';
}

function mp_user_avatar_allowed_extensions(): array
{
    return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
}

function mp_user_avatar_validate_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Profile picture upload failed. Choose an image and try again.');
    }

    $originalName = (string)($file['name'] ?? 'avatar');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = mp_user_avatar_allowed_extensions();
    if (!isset($allowed[$extension])) {
        throw new RuntimeException('Profile pictures must be JPG, PNG, GIF, or WebP images.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('The profile picture file was empty.');
    }
    if ($size > 1024 * 1024 * 4) {
        throw new RuntimeException('Profile picture is too large. Keep uploads under 4 MB.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new RuntimeException('Bonumark Stream could not read the profile picture file.');
    }

    $imageInfo = @getimagesize($tmp);
    if (!is_array($imageInfo) || empty($imageInfo[0]) || empty($imageInfo[1])) {
        throw new RuntimeException('The uploaded profile picture does not appear to be a valid image.');
    }

    $mime = (string)($imageInfo['mime'] ?? '');
    if ($mime === '' && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    }

    if (!mp_media_mime_matches_extension($extension, $mime)) {
        throw new RuntimeException('Profile picture type did not match the file extension.');
    }

    return [
        'tmp' => $tmp,
        'original_name' => $originalName,
        'extension' => $extension,
        'mime' => $mime !== '' ? $mime : (string)$allowed[$extension],
        'width' => (int)$imageInfo[0],
        'height' => (int)$imageInfo[1],
    ];
}

function mp_user_avatar_delete_file(string $avatarPath): void
{
    $avatarPath = trim(str_replace('\\', '/', $avatarPath));
    if ($avatarPath === '' || !str_starts_with(ltrim($avatarPath, '/'), 'media/avatars/')) {
        return;
    }

    $file = mp_public_path(ltrim($avatarPath, '/'));
    if (is_file($file)) {
        @unlink($file);
    }
    if (function_exists('mp_media_delete_generated_variants')) {
        mp_media_delete_generated_variants($avatarPath);
    }
}

function mp_remove_current_user_avatar(): array
{
    $current = mp_current_user();
    $currentId = (int)($current['id'] ?? 0);
    if ($currentId < 1) {
        throw new RuntimeException('You must be signed in to remove a profile picture.');
    }

    mp_user_avatar_delete_file((string)($current['avatar_path'] ?? ''));
    $stmt = mp_db()->prepare('UPDATE ' . mp_table('users') . ' SET avatar_path = :avatar_path, updated_at = NOW() WHERE id = :id');
    $stmt->execute(['avatar_path' => '', 'id' => $currentId]);
    return mp_find_user_by_id($currentId) ?? mp_current_user();
}

function mp_update_current_user_avatar(array $file): array
{
    $current = mp_current_user();
    $currentId = (int)($current['id'] ?? 0);
    if ($currentId < 1) {
        throw new RuntimeException('You must be signed in to upload a profile picture.');
    }

    $valid = mp_user_avatar_validate_upload($file);
    if (!$valid) {
        return $current;
    }

    $folder = 'avatars/' . $currentId;
    $filename = 'avatar-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . (string)$valid['extension'];
    $relative = $folder . '/' . $filename;
    $destination = mp_media_public_root($relative);
    $dir = dirname($destination);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create the profile picture upload folder.');
    }

    $moved = is_uploaded_file((string)$valid['tmp'])
        ? move_uploaded_file((string)$valid['tmp'], $destination)
        : copy((string)$valid['tmp'], $destination);
    if (!$moved) {
        throw new RuntimeException('Could not store the profile picture.');
    }
    @chmod($destination, 0644);
    $avatarPath = 'media/' . $relative;
    mp_user_avatar_generate_variants($avatarPath);

    mp_user_avatar_delete_file((string)($current['avatar_path'] ?? ''));
    $stmt = mp_db()->prepare('UPDATE ' . mp_table('users') . ' SET avatar_path = :avatar_path, updated_at = NOW() WHERE id = :id');
    $stmt->execute(['avatar_path' => $avatarPath, 'id' => $currentId]);
    return mp_find_user_by_id($currentId) ?? mp_current_user();
}

function mp_apply_current_user_avatar_from_request(array $files, bool $removeAvatar = false): array
{
    if ($removeAvatar) {
        return mp_remove_current_user_avatar();
    }

    $file = $files['avatar'] ?? null;
    if (is_array($file) && (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        return mp_update_current_user_avatar($file);
    }

    return mp_current_user();
}

function mp_user_by_id_public(int $id): ?array
{
    if ($id < 1 || !mp_is_installed()) {
        return null;
    }
    $stmt = mp_db()->prepare('SELECT id, username, display_name, email, role, status, bio, website, social_links, profile_visibility, avatar_path, created_at, updated_at FROM ' . mp_table('users') . ' WHERE id = :id AND status = :status LIMIT 1');
    $stmt->execute(['id' => $id, 'status' => 'active']);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function mp_author_for_stream_page(array $page): array
{
    $fallback = [
        'id' => 0,
        'username' => '',
        'display_name' => (string)mp_setting_or_config('author_name', 'Admin'),
        'role' => 'administrator',
        'profile_visibility' => 'private',
        'avatar_path' => '',
        'social_links' => '',
    ];

    if (!mp_is_installed()) {
        return $fallback;
    }

    try {
        $slug = mp_slugify((string)($page['slug'] ?? ''));
        if ($slug === '') {
            return $fallback;
        }
        $stmt = mp_db()->prepare('SELECT u.id, u.username, u.display_name, u.email, u.role, u.status, u.bio, u.website, u.social_links, u.profile_visibility, u.avatar_path, u.created_at, u.updated_at FROM ' . mp_table('posts') . ' p INNER JOIN ' . mp_table('users') . ' u ON u.id = p.author_id WHERE p.slug = :slug AND p.status = :status AND u.status = :user_status LIMIT 1');
        $stmt->execute(['slug' => $slug, 'status' => 'published', 'user_status' => 'active']);
        $user = $stmt->fetch();
        return is_array($user) ? $user : $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function mp_profile_post_count(int $userId): int
{
    try {
        $stmt = mp_db()->prepare('SELECT COUNT(*) FROM ' . mp_table('posts') . ' WHERE author_id = :author_id AND status = :status');
        $stmt->execute(['author_id' => $userId, 'status' => 'published']);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function mp_profile_comment_count(int $userId): int
{
    try {
        $stmt = mp_db()->prepare('SELECT COUNT(*) FROM ' . mp_table('comments') . ' WHERE user_id = :user_id AND status = :status');
        $stmt->execute(['user_id' => $userId, 'status' => 'approved']);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function mp_profile_recent_posts(int $userId, int $limit = 100): array
{
    if ($userId < 1 || !mp_is_installed()) {
        return [];
    }

    $limit = max(1, min(250, $limit));
    try {
        $stmt = mp_db()->prepare('SELECT title, slug, description, published_at, updated_at, date_published FROM ' . mp_table('posts') . ' WHERE author_id = :author_id AND status = :status ORDER BY COALESCE(published_at, updated_at, created_at) DESC LIMIT ' . $limit);
        $stmt->execute(['author_id' => $userId, 'status' => 'published']);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function mp_profile_activity_excerpt(array $post, int $limit = 150): string
{
    $text = trim((string)($post['description'] ?? ''));
    if ($text === '') {
        $text = trim((string)($post['title'] ?? ''));
    }
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
    if ($text === '') {
        return 'Stream post';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, max(0, $limit - 1))) . '…' : $text;
    }
    return strlen($text) > $limit ? rtrim(substr($text, 0, max(0, $limit - 1))) . '…' : $text;
}

function mp_profile_activity_date_label(array $post): string
{
    $raw = trim((string)($post['published_at'] ?? $post['date_published'] ?? $post['updated_at'] ?? ''));
    if ($raw === '') {
        return '';
    }
    $time = strtotime($raw);
    if ($time === false) {
        return $raw;
    }
    return date('M j, Y', $time);
}

function mp_profile_member_since_label(array $user): string
{
    $raw = trim((string)($user['created_at'] ?? ''));
    if ($raw === '') {
        return '';
    }
    $time = strtotime($raw);
    if ($time === false) {
        return '';
    }
    return date('M j, Y', $time);
}


function mp_author_published_stream_pages(int $userId, int $limit = 100): array
{
    if ($userId < 1 || !mp_is_installed()) {
        return [];
    }
    $limit = max(1, min(250, $limit));
    try {
        $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('posts') . ' WHERE author_id = :author_id AND status = :status AND post_type = :post_type ORDER BY COALESCE(published_at, updated_at, created_at) DESC LIMIT ' . $limit);
        $stmt->execute(['author_id' => $userId, 'status' => 'published', 'post_type' => 'stream']);
        $pages = [];
        foreach (($stmt->fetchAll() ?: []) as $row) {
            if (function_exists('mp_database_row_to_content_page')) {
                $pages[] = mp_database_row_to_content_page($row);
                continue;
            }
            $relative = trim(str_replace('\\', '/', (string)($row['markdown_path'] ?? '')));
            if ($relative === '') { continue; }
            $path = mp_root_path($relative);
            if (is_file($path)) {
                $page = mp_parse_markdown_file($path);
                $page['section'] = 'published';
                $page['filename'] = basename($relative);
                $pages[] = $page;
            }
        }
        return function_exists('mp_sort_stream_posts') ? mp_sort_stream_posts($pages) : $pages;
    } catch (Throwable $e) {
        return [];
    }
}


function mp_account_post_counts(int $userId): array
{
    $counts = ['published' => 0, 'draft' => 0, 'total' => 0];
    if ($userId < 1 || !mp_is_installed()) {
        return $counts;
    }
    try {
        $stmt = mp_db()->prepare('SELECT status, COUNT(*) AS total FROM ' . mp_table('posts') . ' WHERE author_id = :author_id GROUP BY status');
        $stmt->execute(['author_id' => $userId]);
        foreach (($stmt->fetchAll() ?: []) as $row) {
            $status = (string)($row['status'] ?? '');
            $total = (int)($row['total'] ?? 0);
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $total;
            }
            $counts['total'] += $total;
        }
    } catch (Throwable $e) {
        return ['published' => 0, 'draft' => 0, 'total' => 0];
    }
    return $counts;
}

function mp_account_comment_counts(int $userId): array
{
    $counts = ['approved' => 0, 'pending' => 0, 'trash' => 0, 'total' => 0];
    if ($userId < 1 || !mp_is_installed()) {
        return $counts;
    }
    try {
        $stmt = mp_db()->prepare('SELECT status, COUNT(*) AS total FROM ' . mp_table('comments') . ' WHERE user_id = :user_id GROUP BY status');
        $stmt->execute(['user_id' => $userId]);
        foreach (($stmt->fetchAll() ?: []) as $row) {
            $status = (string)($row['status'] ?? '');
            $total = (int)($row['total'] ?? 0);
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $total;
            }
            $counts['total'] += $total;
        }
    } catch (Throwable $e) {
        return ['approved' => 0, 'pending' => 0, 'trash' => 0, 'total' => 0];
    }
    return $counts;
}

function mp_account_recent_comments(int $userId, int $limit = 10): array
{
    if ($userId < 1 || !mp_is_installed()) {
        return [];
    }
    $limit = max(1, min(250, $limit));
    try {
        $stmt = mp_db()->prepare('SELECT c.id, c.post_slug, c.post_id, c.body, c.status, c.created_at, c.updated_at, p.title AS post_title, p.slug AS resolved_post_slug FROM ' . mp_table('comments') . ' c LEFT JOIN ' . mp_table('posts') . ' p ON p.id = c.post_id WHERE c.user_id = :user_id AND c.status IN (\'approved\', \'pending\') ORDER BY c.created_at DESC, c.id DESC LIMIT ' . $limit);
        $stmt->execute(['user_id' => $userId]);
        $items = [];
        foreach (($stmt->fetchAll() ?: []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = mp_slugify((string)($row['resolved_post_slug'] ?? $row['post_slug'] ?? ''));
            $body = trim((string)($row['body'] ?? ''));
            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'post_slug' => $slug,
                'post_title' => trim((string)($row['post_title'] ?? '')) ?: 'Stream Post',
                'post_url' => $slug !== '' ? mp_stream_url($slug) . '#comments' : '',
                'body' => $body,
                'excerpt' => mp_account_activity_excerpt($body, 160),
                'status' => function_exists('mp_comment_normalize_status') ? mp_comment_normalize_status((string)($row['status'] ?? 'pending')) : ((string)($row['status'] ?? 'pending') === 'approved' ? 'approved' : 'pending'),
                'status_label' => function_exists('mp_comment_status_label') ? mp_comment_status_label((string)($row['status'] ?? 'pending')) : ucfirst((string)($row['status'] ?? 'pending')),
                'created_at' => (string)($row['created_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
                'raw' => $row,
            ];
        }
        return $items;
    } catch (Throwable $e) {
        return [];
    }
}

function mp_account_recent_stream_posts(int $userId, int $limit = 6): array
{
    if ($userId < 1 || !mp_is_installed()) {
        return [];
    }
    $limit = max(1, min(20, $limit));
    try {
        $stmt = mp_db()->prepare('SELECT id, title, slug, status, description, created_at, updated_at, published_at FROM ' . mp_table('posts') . ' WHERE author_id = :author_id AND status IN (\'published\', \'draft\') ORDER BY updated_at DESC, created_at DESC LIMIT ' . $limit);
        $stmt->execute(['author_id' => $userId]);
        $items = [];
        foreach (($stmt->fetchAll() ?: []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = mp_slugify((string)($row['slug'] ?? ''));
            $status = (string)($row['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => trim((string)($row['title'] ?? '')) ?: 'Stream Post',
                'slug' => $slug,
                'status' => $status,
                'status_label' => $status === 'published' ? 'Published' : 'Draft',
                'public_url' => ($status === 'published' && $slug !== '') ? mp_stream_url($slug) : '',
                'edit_url' => mp_admin_url('edit.php?section=' . ($status === 'published' ? 'published' : 'drafts') . '&file=' . rawurlencode($slug . '.md')),
                'updated_at' => (string)($row['updated_at'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'raw' => $row,
            ];
        }
        return $items;
    } catch (Throwable $e) {
        return [];
    }
}

function mp_account_activity_excerpt(string $body, int $limit = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, max(0, $limit - 1))) . '…' : $text;
    }
    return strlen($text) > $limit ? rtrim(substr($text, 0, max(0, $limit - 1))) . '…' : $text;
}

function mp_account_dashboard_data(array $user): array
{
    $userId = (int)($user['id'] ?? 0);
    $role = mp_normalize_role((string)($user['role'] ?? 'commenter'));
    $visibility = (string)($user['profile_visibility'] ?? 'public') === 'private' ? 'private' : 'public';
    $emailVerified = trim((string)($user['email_verified_at'] ?? '')) !== '';
    $postCounts = mp_account_post_counts($userId);
    $commentCounts = mp_account_comment_counts($userId);

    return [
        'role_label' => mp_role_label($role),
        'status_label' => mp_user_status_label((string)($user['status'] ?? 'active')),
        'visibility_label' => $visibility === 'private' ? 'Private' : 'Public',
        'email_status_label' => $emailVerified ? 'Verified' : 'Unverified',
        'member_since' => (string)($user['created_at'] ?? ''),
        'post_counts' => $postCounts,
        'comment_counts' => $commentCounts,
        'profile_url' => mp_public_profile_url_for_user($user),
        'can_write_posts' => $role === 'administrator' || $role === 'user',
        'can_comment' => in_array($role, ['administrator', 'user', 'commenter'], true),
        'recent_comments' => mp_account_recent_comments($userId),
        'recent_posts' => mp_account_recent_stream_posts($userId),
    ];
}


function mp_author_archive_page_html(?array $user): string
{
    if (!$user) {
        return mp_profile_page_html(null);
    }

    $viewHtml = mp_profile_page_html($user);
    return $viewHtml;
}

function mp_profile_page_html(?array $user): string
{
    $siteNameRaw = (string)mp_setting_or_config('site_name', 'Bonumark Stream');
    $titleRaw = $user ? (string)($user['display_name'] ?? $user['username']) : 'Profile not found';
    $canonicalPath = $user ? mp_public_profile_url_for_user($user) : mp_url_path('profile.php');
    $recentPosts = [];

    if ($user) {
        foreach (mp_profile_recent_posts((int)$user['id'], 100) as $post) {
            $slug = mp_slugify((string)($post['slug'] ?? ''));
            $recentPosts[] = [
                'url' => $slug !== '' ? mp_stream_url($slug) : '',
                'label' => 'Open post',
                'excerpt' => mp_profile_activity_excerpt($post),
                'date_label' => mp_profile_activity_date_label($post),
                'raw' => $post,
            ];
        }
    }

    $view = [
        'site_name' => $siteNameRaw,
        'title' => $titleRaw,
        'canonical' => mp_site_url($canonicalPath),
        'style_url' => mp_asset_url('assets/style.css'),
        'script_url' => mp_asset_url('assets/stream.js'),
        'theme_stylesheet_links' => mp_public_theme_stylesheet_links(),
        'favicon_tags' => function_exists('mp_site_favicon_tags') ? mp_site_favicon_tags() : '',
        'theme_script_tags' => mp_public_theme_script_tags(),
        'body_class' => mp_public_theme_class('profile-page'),
        'header_html' => mp_render_public_header('profile', null, $canonicalPath),
        'footer_html' => mp_render_public_footer($canonicalPath),
        'home_url' => mp_url_path(),
        'user' => $user,
        'display_name' => $user ? (string)($user['display_name'] ?? $user['username']) : '',
        'username' => $user ? (string)($user['username'] ?? '') : '',
        'bio' => $user ? trim((string)($user['bio'] ?? '')) : '',
        'website' => $user ? trim((string)($user['website'] ?? '')) : '',
        'profile_links' => $user ? mp_profile_social_links_for_user($user) : [],
        'avatar_markup' => $user ? mp_user_avatar_markup($user, '', 192, 192) : '',
        'post_count' => $user ? mp_profile_post_count((int)$user['id']) : 0,
        'comment_count' => $user ? mp_profile_comment_count((int)$user['id']) : 0,
        'member_since' => $user ? mp_profile_member_since_label($user) : '',
        'profile_url' => $user ? mp_public_profile_url_for_user($user) : '',
        'author_archive_url' => $user ? mp_author_archive_url_for_user($user) : '',
        'recent_posts' => $recentPosts,
    ];

    return mp_render_public_theme_template('profile', $view);
}

