<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/markdown.php';

function bms_public_profile_url_for_user(array $user): string
{
    $username = bms_normalize_username((string)($user['username'] ?? ''));
    if ($username !== '') {
        return bms_url_path('profile/' . rawurlencode($username));
    }

    $id = (int)($user['id'] ?? 0);
    if ($id > 0) {
        return bms_url_path('profile.php?id=' . $id);
    }

    return bms_url_path('profile');
}


function bms_profile_user_is_viewable(array $user): bool
{
    if ((string)($user['status'] ?? '') !== 'active') {
        return false;
    }

    if ((string)($user['profile_visibility'] ?? 'public') !== 'private') {
        return true;
    }

    $current = function_exists('bms_current_user') ? bms_current_user() : [];
    return (int)($current['id'] ?? 0) === (int)($user['id'] ?? 0) || bms_current_user_can('manage_users');
}

function bms_find_public_user_by_id(int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    bms_require_installed();
    $stmt = bms_db()->prepare('SELECT id, username, display_name, role, status, bio, website, social_links, profile_visibility, avatar_path, created_at, updated_at FROM ' . bms_table('users') . ' WHERE id = :id AND status = :status LIMIT 1');
    $stmt->execute([
        'id' => $id,
        'status' => 'active',
    ]);
    $user = $stmt->fetch();
    if (!is_array($user)) {
        return null;
    }

    return bms_profile_user_is_viewable($user) ? $user : null;
}

function bms_find_public_user_by_username(string $username): ?array
{
    bms_require_installed();
    $stmt = bms_db()->prepare('SELECT id, username, display_name, role, status, bio, website, social_links, profile_visibility, avatar_path, created_at, updated_at FROM ' . bms_table('users') . ' WHERE username = :username AND status = :status LIMIT 1');
    $stmt->execute([
        'username' => bms_normalize_username($username),
        'status' => 'active',
    ]);
    $user = $stmt->fetch();
    if (!is_array($user)) {
        return null;
    }
    return bms_profile_user_is_viewable($user) ? $user : null;
}


function bms_user_initials(array $user): string
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

function bms_user_avatar_variant_widths(): array
{
    return [96, 192];
}

function bms_user_avatar_normalize_path(string $path): string
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

function bms_user_avatar_generate_variants(string $avatarPath): void
{
    if (!function_exists('bms_media_image_dimensions_for_public_path') || !function_exists('bms_media_generate_responsive_variant')) {
        return;
    }

    $relative = bms_user_avatar_normalize_path($avatarPath);
    if ($relative === '' || preg_match('#^https?://#i', $relative) || !str_starts_with($relative, 'media/avatars/')) {
        return;
    }

    $dimensions = bms_media_image_dimensions_for_public_path($relative);
    if (empty($dimensions['width']) || empty($dimensions['height'])) {
        return;
    }

    foreach (bms_user_avatar_variant_widths() as $width) {
        bms_media_generate_responsive_variant($relative, (int)$width, $dimensions);
    }
}

function bms_user_avatar_ensure_variant(string $avatarPath, int $targetWidth): string
{
    if (!function_exists('bms_media_generated_relative_path') || !function_exists('bms_media_image_dimensions_for_public_path') || !function_exists('bms_media_generate_responsive_variant') || !function_exists('bms_public_path')) {
        return '';
    }

    $targetWidth = (int)$targetWidth;
    if ($targetWidth < 1 || !in_array($targetWidth, bms_user_avatar_variant_widths(), true)) {
        return '';
    }

    $relative = bms_user_avatar_normalize_path($avatarPath);
    if ($relative === '' || preg_match('#^https?://#i', $relative) || !str_starts_with($relative, 'media/avatars/')) {
        return '';
    }

    $generated = bms_media_generated_relative_path($relative, $targetWidth);
    if ($generated !== '' && is_file(bms_public_path($generated))) {
        return $generated;
    }

    $dimensions = bms_media_image_dimensions_for_public_path($relative);
    if (empty($dimensions['width']) || empty($dimensions['height'])) {
        return '';
    }

    $generated = bms_media_generate_responsive_variant($relative, $targetWidth, $dimensions);
    if ($generated !== '' && is_file(bms_public_path($generated))) {
        return $generated;
    }

    return '';
}

function bms_user_avatar_variant_url(string $avatarPath, int $targetWidth = 192, bool $allowLargerFallback = true): string
{
    if (!function_exists('bms_media_generated_relative_path') || !function_exists('bms_public_path') || !function_exists('bms_url_path')) {
        return '';
    }

    $relative = bms_user_avatar_normalize_path($avatarPath);
    if ($relative === '' || preg_match('#^https?://#i', $relative) || !str_starts_with($relative, 'media/avatars/')) {
        return '';
    }

    $targetWidth = max(1, min(512, $targetWidth));
    $exact = bms_user_avatar_ensure_variant($relative, $targetWidth);
    if ($exact !== '') {
        return bms_url_path($exact);
    }

    $widths = bms_user_avatar_variant_widths();
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
        $generated = bms_media_generated_relative_path($relative, (int)$width);
        if ($generated !== '' && is_file(bms_public_path($generated))) {
            return bms_url_path($generated);
        }
    }

    return '';
}

function bms_user_avatar_url(array $user, int $targetWidth = 192, bool $allowLargerFallback = true): string
{
    $path = bms_user_avatar_normalize_path((string)($user['avatar_path'] ?? ''));
    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    if (str_starts_with($path, 'media/avatars/')) {
        $variant = bms_user_avatar_variant_url($path, $targetWidth, $allowLargerFallback);
        if ($variant !== '') {
            return $variant;
        }
    }

    return bms_url_path($path);
}

function bms_user_avatar_markup(array $user, string $class = '', int $targetWidth = 96, ?int $displaySize = null, bool $allowLargerFallback = true): string
{
    $targetWidth = max(1, min(512, $targetWidth));
    $displaySize = $displaySize !== null ? max(1, min(512, $displaySize)) : $targetWidth;
    $url = bms_user_avatar_url($user, $targetWidth, $allowLargerFallback);
    $class = trim($class);
    $extraClass = $class !== '' ? ' ' . preg_replace('/[^a-zA-Z0-9_ -]/', '', $class) : '';
    if ($url !== '') {
        $name = trim((string)($user['display_name'] ?? $user['username'] ?? ''));
        $alt = $name !== '' ? $name : 'Profile picture';
        if (function_exists('bms_media_image_attributes')) {
            $attributes = bms_media_image_attributes($url, $alt, [
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

    return '<span class="stream-author-avatar stream-author-initials' . $extraClass . '">' . htmlspecialchars(bms_user_initials($user), ENT_QUOTES, 'UTF-8') . '</span>';
}

function bms_user_avatar_allowed_extensions(): array
{
    return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
}

function bms_user_avatar_validate_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Profile picture upload failed. Choose an image and try again.');
    }

    $originalName = (string)($file['name'] ?? 'avatar');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = bms_user_avatar_allowed_extensions();
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

    if (!bms_media_mime_matches_extension($extension, $mime)) {
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

function bms_user_avatar_delete_file(string $avatarPath): void
{
    $avatarPath = trim(str_replace('\\', '/', $avatarPath));
    if ($avatarPath === '' || !str_starts_with(ltrim($avatarPath, '/'), 'media/avatars/')) {
        return;
    }

    $file = bms_public_path(ltrim($avatarPath, '/'));
    if (is_file($file)) {
        @unlink($file);
    }
    if (function_exists('bms_media_delete_generated_variants')) {
        bms_media_delete_generated_variants($avatarPath);
    }
}

function bms_remove_current_user_avatar(): array
{
    $current = bms_current_user();
    $currentId = (int)($current['id'] ?? 0);
    if ($currentId < 1) {
        throw new RuntimeException('You must be signed in to remove a profile picture.');
    }

    bms_user_avatar_delete_file((string)($current['avatar_path'] ?? ''));
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('users') . ' SET avatar_path = :avatar_path, updated_at = NOW() WHERE id = :id');
    $stmt->execute(['avatar_path' => '', 'id' => $currentId]);
    return bms_find_user_by_id($currentId) ?? bms_current_user();
}

function bms_update_current_user_avatar(array $file): array
{
    $current = bms_current_user();
    $currentId = (int)($current['id'] ?? 0);
    if ($currentId < 1) {
        throw new RuntimeException('You must be signed in to upload a profile picture.');
    }

    $valid = bms_user_avatar_validate_upload($file);
    if (!$valid) {
        return $current;
    }

    $folder = 'avatars/' . $currentId;
    $filename = 'avatar-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . (string)$valid['extension'];
    $relative = $folder . '/' . $filename;
    $destination = bms_media_public_root($relative);
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
    bms_user_avatar_generate_variants($avatarPath);

    bms_user_avatar_delete_file((string)($current['avatar_path'] ?? ''));
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('users') . ' SET avatar_path = :avatar_path, updated_at = NOW() WHERE id = :id');
    $stmt->execute(['avatar_path' => $avatarPath, 'id' => $currentId]);
    return bms_find_user_by_id($currentId) ?? bms_current_user();
}

function bms_apply_current_user_avatar_from_request(array $files, bool $removeAvatar = false): array
{
    if ($removeAvatar) {
        return bms_remove_current_user_avatar();
    }

    $file = $files['avatar'] ?? null;
    if (is_array($file) && (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        return bms_update_current_user_avatar($file);
    }

    return bms_current_user();
}

function bms_user_by_id_public(int $id): ?array
{
    if ($id < 1 || !bms_is_installed()) {
        return null;
    }
    $stmt = bms_db()->prepare('SELECT id, username, display_name, role, status, bio, website, social_links, profile_visibility, avatar_path, created_at, updated_at FROM ' . bms_table('users') . ' WHERE id = :id AND status = :status LIMIT 1');
    $stmt->execute(['id' => $id, 'status' => 'active']);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function bms_author_for_stream_page(array $page): array
{
    $fallback = [
        'id' => 0,
        'username' => '',
        'display_name' => (string)bms_setting_or_config('author_name', 'Admin'),
        'role' => 'admin',
        'profile_visibility' => 'private',
        'avatar_path' => '',
        'social_links' => '',
    ];

    if (!bms_is_installed()) {
        return $fallback;
    }

    try {
        $slug = bms_slugify((string)($page['slug'] ?? ''));
        if ($slug === '') {
            return $fallback;
        }
        $stmt = bms_db()->prepare('SELECT u.id, u.username, u.display_name, u.role, u.status, u.bio, u.website, u.social_links, u.profile_visibility, u.avatar_path, u.created_at, u.updated_at FROM ' . bms_table('posts') . ' p INNER JOIN ' . bms_table('users') . ' u ON u.id = p.author_id WHERE p.slug = :slug AND p.status = :status AND u.status = :user_status LIMIT 1');
        $stmt->execute(['slug' => $slug, 'status' => 'published', 'user_status' => 'active']);
        $user = $stmt->fetch();
        return is_array($user) ? $user : $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function bms_profile_post_count(int $userId): int
{
    try {
        $stmt = bms_db()->prepare('SELECT COUNT(*) FROM ' . bms_table('posts') . ' WHERE author_id = :author_id AND status = :status AND post_type = :post_type');
        $stmt->execute(['author_id' => $userId, 'status' => 'published', 'post_type' => 'stream']);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function bms_profile_comment_count(int $userId): int
{
    try {
        $stmt = bms_db()->prepare('SELECT COUNT(*) FROM ' . bms_table('comments') . ' WHERE user_id = :user_id AND status = :status');
        $stmt->execute(['user_id' => $userId, 'status' => 'approved']);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function bms_profile_member_since_label(array $user): string
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


function bms_author_published_stream_pages(int $userId, int $limit = 100): array
{
    if ($userId < 1 || !bms_is_installed()) {
        return [];
    }
    $limit = max(1, min(250, $limit));
    try {
        $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('posts') . ' WHERE author_id = :author_id AND status = :status AND post_type = :post_type ORDER BY COALESCE(published_at, updated_at, created_at) DESC LIMIT ' . $limit);
        $stmt->execute(['author_id' => $userId, 'status' => 'published', 'post_type' => 'stream']);
        $pages = [];
        foreach (($stmt->fetchAll() ?: []) as $row) {
            if (function_exists('bms_database_row_to_content_page')) {
                $pages[] = bms_database_row_to_content_page($row);
            }
        }
        return function_exists('bms_sort_stream_posts') ? bms_sort_stream_posts($pages) : $pages;
    } catch (Throwable $e) {
        return [];
    }
}



function bms_profile_identity_defaults(): array
{
    return [
        'headline' => '',
        'about_markdown' => '',
        'location' => '',
        'now_text' => '',
        'cover_image_path' => '',
        'links' => [],
        'interests' => [],
        'featured_items' => [],
        'profile_photos' => [],
        'show_post_count' => false,
        'show_comment_count' => false,
        'show_member_since' => false,
    ];
}

function bms_profile_json_list(mixed $raw): array
{
    if (is_array($raw)) {
        return array_values($raw);
    }
    $decoded = json_decode(trim((string)$raw), true);
    return is_array($decoded) ? array_values($decoded) : [];
}

function bms_profile_identity_for_user(int $userId, ?array $user = null): array
{
    $identity = bms_profile_identity_defaults();
    if ($userId < 1 || !bms_is_installed()) {
        return $identity;
    }

    try {
        $stmt = bms_db()->prepare('SELECT headline, about_markdown, location, now_text, cover_image_path, links_json, interests_json, featured_items_json, profile_photos_json, show_post_count, show_comment_count, show_member_since FROM ' . bms_table('user_profiles') . ' WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            $identity['headline'] = trim((string)($row['headline'] ?? ''));
            $identity['about_markdown'] = trim((string)($row['about_markdown'] ?? ''));
            $identity['location'] = trim((string)($row['location'] ?? ''));
            $identity['now_text'] = trim((string)($row['now_text'] ?? ''));
            $identity['cover_image_path'] = trim((string)($row['cover_image_path'] ?? ''));
            $identity['links'] = bms_profile_json_list($row['links_json'] ?? '[]');
            $identity['interests'] = bms_profile_json_list($row['interests_json'] ?? '[]');
            $identity['featured_items'] = bms_profile_json_list($row['featured_items_json'] ?? '[]');
            $identity['profile_photos'] = bms_profile_json_list($row['profile_photos_json'] ?? '[]');
            $identity['show_post_count'] = (int)($row['show_post_count'] ?? 0) === 1;
            $identity['show_comment_count'] = (int)($row['show_comment_count'] ?? 0) === 1;
            $identity['show_member_since'] = (int)($row['show_member_since'] ?? 0) === 1;
        }
    } catch (Throwable $e) {
        // Upgraded installs that have not run the new migration yet should keep
        // rendering the legacy profile instead of failing the public request.
    }

    if (!$identity['links'] && is_array($user) && function_exists('bms_profile_social_links_for_user')) {
        $identity['links'] = bms_profile_social_links_for_user($user);
    }

    return $identity;
}

function bms_profile_text_with_limit(string $value, int $limit, string $label): string
{
    $value = trim($value);
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length > $limit) {
        throw new RuntimeException($label . ' is too long. Keep it under ' . $limit . ' characters.');
    }
    return $value;
}

function bms_profile_normalize_public_url(string $url, string $label = 'URL'): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Enter a valid ' . strtolower($label) . ' or leave it blank.');
    }
    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException($label . ' must use http or https.');
    }
    return $url;
}

function bms_profile_normalize_links_input(array $input): array
{
    $labels = is_array($input['label'] ?? null) ? array_values($input['label']) : [];
    $urls = is_array($input['url'] ?? null) ? array_values($input['url']) : [];
    $count = min(8, max(count($labels), count($urls)));
    $links = [];

    for ($i = 0; $i < $count; $i++) {
        $label = bms_profile_text_with_limit((string)($labels[$i] ?? ''), 60, 'Profile link label');
        $urlRaw = trim((string)($urls[$i] ?? ''));
        if ($label === '' && $urlRaw === '') {
            continue;
        }
        if ($label === '') {
            throw new RuntimeException('Each profile link needs a label.');
        }
        if ($urlRaw === '') {
            throw new RuntimeException('Each profile link needs a URL.');
        }
        $links[] = [
            'label' => $label,
            'url' => bms_profile_normalize_public_url($urlRaw, 'profile link URL'),
        ];
    }

    return $links;
}


function bms_profile_safe_links(array $links): array
{
    $safe = [];
    foreach ($links as $link) {
        if (!is_array($link)) {
            continue;
        }
        $label = trim((string)($link['label'] ?? ''));
        $url = trim((string)($link['url'] ?? ''));
        if ($label === '' || $url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            continue;
        }
        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            continue;
        }
        $safe[] = ['label' => $label, 'url' => $url];
        if (count($safe) >= 8) {
            break;
        }
    }
    return $safe;
}

function bms_profile_link_form_rows(array $identity, int $rowCount = 8): array
{
    $rowCount = max(1, min(8, $rowCount));
    $rows = [];
    foreach (($identity['links'] ?? []) as $link) {
        if (!is_array($link) || count($rows) >= $rowCount) {
            continue;
        }
        $label = trim((string)($link['label'] ?? ''));
        $url = trim((string)($link['url'] ?? ''));
        if ($label === '' && $url === '') {
            continue;
        }
        $rows[] = [
            'label' => $label,
            'url' => $url,
        ];
    }

    // Keep the editor compact. Saved links are the only rows shown once a
    // Profile has links. A single starter row is kept only for an empty
    // Profile so adding the first link still works without JavaScript.
    if ($rows === []) {
        $rows[] = ['label' => '', 'url' => ''];
    }

    return $rows;
}


function bms_profile_featured_type(string $type): string
{
    $type = strtolower(trim($type));
    return in_array($type, ['stream', 'page', 'external'], true) ? $type : 'external';
}

function bms_profile_featured_type_label(string $type): string
{
    return match (bms_profile_featured_type($type)) {
        'stream' => 'Stream post',
        'page' => 'Page',
        default => 'External link',
    };
}

function bms_profile_featured_internal_content(string $type, string $target): ?array
{
    $type = bms_profile_featured_type($type);
    $slug = bms_slugify($target);
    if ($slug === '') {
        return null;
    }
    if (in_array($type, ['stream', 'page'], true) && function_exists('bms_find_database_content_by_slug_status')) {
        return bms_find_database_content_by_slug_status($slug, 'published', $type);
    }
    return null;
}

function bms_profile_normalize_featured_input(array $input): array
{
    $types = is_array($input['type'] ?? null) ? array_values($input['type']) : [];
    $targets = is_array($input['target'] ?? null) ? array_values($input['target']) : [];
    $titles = is_array($input['title'] ?? null) ? array_values($input['title']) : [];
    $descriptions = is_array($input['description'] ?? null) ? array_values($input['description']) : [];
    $count = min(4, max(count($types), count($targets), count($titles), count($descriptions)));
    $items = [];

    for ($i = 0; $i < $count; $i++) {
        $type = bms_profile_featured_type((string)($types[$i] ?? 'external'));
        $targetRaw = trim((string)($targets[$i] ?? ''));
        $title = bms_profile_text_with_limit((string)($titles[$i] ?? ''), 140, 'Featured work title');
        $description = bms_profile_text_with_limit((string)($descriptions[$i] ?? ''), 320, 'Featured work description');

        if ($targetRaw === '' && $title === '' && $description === '') {
            continue;
        }
        if ($targetRaw === '') {
            throw new RuntimeException('Each featured item needs a target.');
        }

        if ($type === 'external') {
            if ($title === '') {
                throw new RuntimeException('External featured links need a title.');
            }
            $target = bms_profile_normalize_public_url($targetRaw, 'featured link URL');
        } else {
            $target = bms_slugify($targetRaw);
            $content = bms_profile_featured_internal_content($type, $target);
            if (!$content) {
                throw new RuntimeException($type === 'page' ? 'Choose a published Page for featured work.' : 'Choose a published Stream post for featured work.');
            }
        }

        $items[] = [
            'type' => $type,
            'target' => $target,
            'title' => $title,
            'description' => $description,
        ];
    }

    return $items;
}

function bms_profile_featured_form_rows(array $identity, int $rowCount = 4): array
{
    $rowCount = max(1, min(4, $rowCount));
    $rows = [];
    foreach (($identity['featured_items'] ?? []) as $item) {
        if (!is_array($item) || count($rows) >= $rowCount) {
            continue;
        }
        $type = bms_profile_featured_type((string)($item['type'] ?? 'external'));
        $target = trim((string)($item['target'] ?? ''));
        $title = trim((string)($item['title'] ?? ''));
        $description = trim((string)($item['description'] ?? ''));
        if ($target === '' && $title === '' && $description === '') {
            continue;
        }
        $rows[] = compact('type', 'target', 'title', 'description');
    }
    if ($rows === []) {
        $rows[] = ['type' => 'external', 'target' => '', 'title' => '', 'description' => ''];
    }
    return $rows;
}

function bms_profile_featured_content_options(int $limit = 250): array
{
    $options = ['stream' => [], 'page' => []];
    if (!bms_is_installed()) {
        return $options;
    }
    $limit = max(1, min(500, $limit));
    try {
        $stmt = bms_db()->query("SELECT title, slug, post_type FROM " . bms_table('posts') . " WHERE status = 'published' AND post_type IN ('stream', 'page') ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT " . $limit);
        foreach (($stmt->fetchAll() ?: []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string)($row['post_type'] ?? '') === 'page' ? 'page' : 'stream';
            $slug = bms_slugify((string)($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $title = trim((string)($row['title'] ?? ''));
            $options[$type][] = ['slug' => $slug, 'title' => $title !== '' ? $title : ($type === 'page' ? 'Untitled Page' : 'Stream Post')];
        }
    } catch (Throwable $e) {
        return ['stream' => [], 'page' => []];
    }
    return $options;
}

function bms_profile_resolved_featured_items(array $items): array
{
    $resolved = [];
    foreach ($items as $item) {
        if (!is_array($item) || count($resolved) >= 4) {
            continue;
        }
        $type = bms_profile_featured_type((string)($item['type'] ?? 'external'));
        $target = trim((string)($item['target'] ?? ''));
        $title = trim((string)($item['title'] ?? ''));
        $description = trim((string)($item['description'] ?? ''));
        $url = '';
        $external = false;

        if ($type === 'external') {
            if ($target === '' || !filter_var($target, FILTER_VALIDATE_URL)) {
                continue;
            }
            $scheme = strtolower((string)(parse_url($target, PHP_URL_SCHEME) ?? ''));
            if (!in_array($scheme, ['http', 'https'], true) || $title === '') {
                continue;
            }
            $url = $target;
            $external = true;
        } else {
            $content = bms_profile_featured_internal_content($type, $target);
            if (!$content) {
                continue;
            }
            if ($title === '') {
                $title = trim((string)($content['title'] ?? ''));
            }
            if ($title === '') {
                $title = $type === 'page' ? 'Page' : 'Stream Post';
            }
            $url = $type === 'page' && function_exists('bms_page_url_for_page')
                ? bms_page_url_for_page($content)
                : (function_exists('bms_stream_url_for_post') ? bms_stream_url_for_post($content) : '');
            if ($url === '') {
                continue;
            }
        }

        $resolved[] = [
            'type' => $type,
            'type_label' => bms_profile_featured_type_label($type),
            'target' => $target,
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'external' => $external,
        ];
    }
    return $resolved;
}


function bms_profile_photo_allowed_extensions(): array
{
    return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
}

function bms_profile_photo_normalize_path(string $path, int $userId = 0): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || preg_match('#^https?://#i', $path) === 1) {
        return '';
    }
    $path = ltrim($path, '/');
    if (!str_starts_with($path, 'media/')) {
        $path = 'media/' . $path;
    }
    if (!str_starts_with($path, 'media/profile-photos/')) {
        return '';
    }
    if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1 || preg_match('/[\r\n]/', $path) === 1) {
        return '';
    }
    if ($userId > 0 && !str_starts_with($path, 'media/profile-photos/' . $userId . '/')) {
        return '';
    }
    return $path;
}

function bms_profile_photo_text(string $value, int $limit): string
{
    $value = trim($value);
    if (function_exists('mb_substr')) {
        return bms_text_substr($value, 0, $limit);
    }
    return substr($value, 0, $limit);
}

function bms_profile_safe_photos(array $photos, int $userId = 0): array
{
    $safe = [];
    foreach ($photos as $photo) {
        if (!is_array($photo) || count($safe) >= 4) {
            continue;
        }
        $path = bms_profile_photo_normalize_path((string)($photo['path'] ?? ''), $userId);
        if ($path === '') {
            continue;
        }
        $safe[] = [
            'path' => $path,
            'alt' => bms_profile_photo_text((string)($photo['alt'] ?? ''), 240),
            'caption' => bms_profile_photo_text((string)($photo['caption'] ?? ''), 500),
        ];
    }
    return $safe;
}

function bms_profile_photo_url(string $path, int $userId = 0): string
{
    $relative = bms_profile_photo_normalize_path($path, $userId);
    if ($relative === '' || !is_file(bms_public_path($relative))) {
        return '';
    }
    return bms_url_path($relative);
}

function bms_profile_photo_variant_widths(): array
{
    // Profile galleries render substantially smaller slots than normal Stream media.
    // Keep dedicated small candidates so phones and multi-column desktop layouts do
    // not have to download a 480px image for a roughly 140-260px rendered slot.
    return [240, 360, 480, 800, 1200];
}

function bms_profile_modern_image_quality(): int
{
    return 78;
}

function bms_profile_modern_variant_relative_path(string $relative, int $targetWidth): string
{
    $generated = bms_media_generated_relative_path($relative, $targetWidth);
    if ($generated === '') {
        return '';
    }
    return preg_replace('/\.[^.]+$/', '.webp', $generated) ?: '';
}

function bms_profile_generate_modern_variant(string $relative, int $targetWidth, array $dimensions, int $quality = 78): string
{
    $relative = trim(str_replace('\\', '/', $relative), '/');
    if ($relative === ''
        || (!str_starts_with($relative, 'media/profile-covers/') && !str_starts_with($relative, 'media/profile-photos/'))) {
        return '';
    }

    $sourceWidth = (int)($dimensions['width'] ?? 0);
    $sourceHeight = (int)($dimensions['height'] ?? 0);
    $sourceFile = (string)($dimensions['file'] ?? '');
    $mime = strtolower((string)($dimensions['mime'] ?? ''));
    if ($sourceWidth < 1 || $sourceHeight < 1 || $targetWidth < 1 || $targetWidth > $sourceWidth || !is_file($sourceFile)) {
        return '';
    }

    // A WebP source already has the desired modern encoding. Reuse the normal
    // responsive path for downscaled candidates and the original at full size.
    if ($mime === 'image/webp') {
        if ($targetWidth === $sourceWidth) {
            return $relative;
        }
        return bms_media_generate_responsive_variant($relative, $targetWidth, $dimensions);
    }

    // Prefer an already-generated same-width fallback derivative as the encoder
    // source. v0.5.119 creates these bounded JPEG/PNG/WebP files first, so an
    // upgraded Profile can modernize a small derivative instead of repeatedly
    // decoding a multi-megapixel phone original on the first public request.
    if ($targetWidth < $sourceWidth) {
        $boundedRelative = bms_media_generated_relative_path($relative, $targetWidth);
        $boundedFile = $boundedRelative !== '' ? bms_public_path($boundedRelative) : '';
        if ($boundedFile !== '' && is_file($boundedFile)) {
            $boundedInfo = @getimagesize($boundedFile);
            if (is_array($boundedInfo) && (int)($boundedInfo[0] ?? 0) === $targetWidth && (int)($boundedInfo[1] ?? 0) > 0) {
                $sourceWidth = (int)$boundedInfo[0];
                $sourceHeight = (int)$boundedInfo[1];
                $sourceFile = $boundedFile;
                $mime = strtolower((string)($boundedInfo['mime'] ?? $mime));
            }
        }
    }

    if (!in_array($mime, ['image/jpeg', 'image/pjpeg', 'image/png'], true)) {
        return '';
    }

    $generatedRelative = bms_profile_modern_variant_relative_path($relative, $targetWidth);
    if ($generatedRelative === '') {
        return '';
    }
    $generatedFile = bms_public_path($generatedRelative);
    if (is_file($generatedFile)) {
        return $generatedRelative;
    }

    $generatedDir = dirname($generatedFile);
    if (!is_dir($generatedDir) && !mkdir($generatedDir, 0755, true) && !is_dir($generatedDir)) {
        return '';
    }

    $targetHeight = max(1, (int)round($sourceHeight * ($targetWidth / $sourceWidth)));
    $quality = max(60, min(90, $quality));
    $saved = false;

    $capability = bms_media_resize_capability($mime);
    if ($capability
        && function_exists((string)($capability['load'] ?? ''))
        && function_exists('imagewebp')
        && function_exists('imagecreatetruecolor')) {
        $load = (string)$capability['load'];
        $source = @$load($sourceFile);
        if ($source) {
            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            if ($target) {
                if ($mime === 'image/png') {
                    imagealphablending($target, false);
                    imagesavealpha($target, true);
                    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
                    if ($transparent !== false) {
                        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
                    }
                }
                imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
                $saved = (bool)@imagewebp($target, $generatedFile, $quality);
                imagedestroy($target);
            }
            imagedestroy($source);
        }
    }

    if (!$saved && class_exists('Imagick')) {
        try {
            $formats = method_exists('Imagick', 'queryFormats') ? Imagick::queryFormats('WEBP') : [];
            if ($formats) {
                $image = new Imagick($sourceFile);
                if ($image->getNumberImages() > 1) {
                    $image->setIteratorIndex(0);
                }
                $image->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1, true);
                $image->setImageFormat('webp');
                $image->setImageCompressionQuality($quality);
                if (method_exists($image, 'stripImage')) {
                    $image->stripImage();
                }
                $saved = (bool)$image->writeImage($generatedFile);
                $image->clear();
                $image->destroy();
            }
        } catch (Throwable $e) {
            $saved = false;
        }
    }

    if (!$saved) {
        @unlink($generatedFile);
        return '';
    }

    @chmod($generatedFile, 0644);
    return $generatedRelative;
}

function bms_profile_modern_variant_srcset(string $relative, array $widths, bool $ensureMissing = false): array
{
    $relative = trim(str_replace('\\', '/', $relative), '/');
    if ($relative === '' || (!str_starts_with($relative, 'media/profile-covers/') && !str_starts_with($relative, 'media/profile-photos/'))) {
        return [];
    }

    $dimensions = bms_media_image_dimensions_for_public_path($relative);
    $sourceWidth = (int)($dimensions['width'] ?? 0);
    if ($sourceWidth < 1) {
        return [];
    }

    $requested = [];
    foreach ($widths as $width) {
        $width = (int)$width;
        if ($width > 0 && $width <= $sourceWidth) {
            $requested[$width] = $width;
        }
    }

    // If the source itself is smaller than the largest normal candidate, encode
    // one full-resolution WebP so the browser never has to jump back to a heavy
    // JPEG/PNG original just because its exact width was not in the preset list.
    $largestPreset = $widths ? max(array_map('intval', $widths)) : 0;
    if ($largestPreset > 0 && $sourceWidth <= $largestPreset) {
        $requested[$sourceWidth] = $sourceWidth;
    }

    ksort($requested, SORT_NUMERIC);
    $srcset = [];
    foreach ($requested as $width) {
        $generated = bms_profile_modern_variant_relative_path($relative, $width);
        if (strtolower((string)($dimensions['mime'] ?? '')) === 'image/webp' && $width === $sourceWidth) {
            $generated = $relative;
        } elseif ($ensureMissing && ($generated === '' || !is_file(bms_public_path($generated)))) {
            $generated = bms_profile_generate_modern_variant(
                $relative,
                $width,
                $dimensions,
                bms_profile_modern_image_quality()
            );
        }

        if ($generated !== '' && is_file(bms_public_path($generated))) {
            $srcset[$width] = bms_url_path($generated) . ' ' . $width . 'w';
        }
    }

    return array_values($srcset);
}

function bms_profile_bounded_fallback_url(string $relative, int $preferredWidth, bool $ensureMissing = false): string
{
    $relative = trim(str_replace('\\', '/', $relative), '/');
    $dimensions = bms_media_image_dimensions_for_public_path($relative);
    $sourceWidth = (int)($dimensions['width'] ?? 0);
    if ($relative === '' || $sourceWidth < 1) {
        return '';
    }
    if ($sourceWidth <= $preferredWidth) {
        return bms_url_path($relative);
    }

    $generated = bms_media_generated_relative_path($relative, $preferredWidth);
    if ($ensureMissing && $generated !== '' && !is_file(bms_public_path($generated))) {
        $generated = bms_media_generate_responsive_variant($relative, $preferredWidth, $dimensions);
    }
    if ($generated !== '' && is_file(bms_public_path($generated))) {
        return bms_url_path($generated);
    }
    return bms_url_path($relative);
}

function bms_profile_image_variant_srcset(string $relative, array $widths, bool $ensureMissing = false): array
{
    $relative = trim(str_replace('\\', '/', $relative), '/');
    if ($relative === '' || !str_starts_with($relative, 'media/') || str_starts_with($relative, 'media/_generated/')) {
        return [];
    }

    $dimensions = bms_media_image_dimensions_for_public_path($relative);
    $sourceWidth = (int)($dimensions['width'] ?? 0);
    if ($sourceWidth < 1) {
        return [];
    }

    $srcset = [];
    foreach ($widths as $width) {
        $width = (int)$width;
        if ($width < 1 || $width >= $sourceWidth) {
            continue;
        }

        $generated = bms_media_generated_relative_path($relative, $width);
        if ($ensureMissing && $generated !== '' && !is_file(bms_public_path($generated))) {
            $generated = bms_media_generate_responsive_variant($relative, $width, $dimensions);
        }
        if ($generated !== '' && is_file(bms_public_path($generated))) {
            $srcset[$width] = bms_url_path($generated) . ' ' . $width . 'w';
        }
    }

    $srcset[$sourceWidth] = bms_url_path($relative) . ' ' . $sourceWidth . 'w';
    ksort($srcset, SORT_NUMERIC);
    return array_values(array_unique($srcset));
}

function bms_profile_photo_generate_variants(string $path): void
{
    $relative = bms_profile_photo_normalize_path($path);
    if ($relative === '' || !is_file(bms_public_path($relative))) {
        return;
    }

    $dimensions = bms_media_image_dimensions_for_public_path($relative);
    if (!$dimensions) {
        return;
    }

    foreach (bms_profile_photo_variant_widths() as $width) {
        $width = (int)$width;
        if ($width > 0 && $width < (int)($dimensions['width'] ?? 0)) {
            bms_media_generate_responsive_variant($relative, $width, $dimensions);
        }
        if ($width > 0 && $width <= (int)($dimensions['width'] ?? 0)) {
            bms_profile_generate_modern_variant(
                $relative,
                $width,
                $dimensions,
                bms_profile_modern_image_quality()
            );
        }
    }

    $sourceWidth = (int)($dimensions['width'] ?? 0);
    $largestPreset = max(bms_profile_photo_variant_widths());
    if ($sourceWidth > 0 && $sourceWidth < $largestPreset) {
        bms_profile_generate_modern_variant(
            $relative,
            $sourceWidth,
            $dimensions,
            bms_profile_modern_image_quality()
        );
    }
}

function bms_profile_photo_image_attributes(string $path, string $alt = ''): string
{
    $relative = bms_profile_photo_normalize_path($path);
    if ($relative === '' || !is_file(bms_public_path($relative))) {
        return '';
    }

    $url = bms_url_path($relative);
    $dimensions = bms_media_image_dimensions_for_public_path($relative);
    $fallbackUrl = bms_profile_bounded_fallback_url($relative, 480, true);
    $attributes = [
        'src' => $fallbackUrl !== '' ? $fallbackUrl : $url,
        'alt' => $alt,
        'loading' => 'lazy',
        'decoding' => 'async',
        'fetchpriority' => 'low',
        'class' => 'profile-photo-image',
    ];

    $sourceWidth = (int)($dimensions['width'] ?? 0);
    $sourceHeight = (int)($dimensions['height'] ?? 0);
    if ($sourceWidth > 0 && $sourceHeight > 0) {
        $attributes['width'] = (string)$sourceWidth;
        $attributes['height'] = (string)$sourceHeight;
    }

    // Existing Profile photos from older releases may only have the former
    // 480/800/1200 derivatives. Generate the bounded small candidates once when
    // they are first needed; new uploads generate the full set immediately.
    $srcset = bms_profile_image_variant_srcset($relative, bms_profile_photo_variant_widths(), true);
    if ($srcset) {
        $attributes['srcset'] = implode(', ', $srcset);
        // `auto` lets modern browsers use the actual laid-out slot for lazy
        // images, which stays accurate even when a code-free theme changes the
        // gallery column count. The following lengths are safe fallbacks.
        $attributes['sizes'] = 'auto, (max-width: 640px) calc(50vw - 1.25rem), (max-width: 1100px) calc(50vw - 2rem), 520px';
    }

    $html = [];
    foreach ($attributes as $name => $value) {
        $html[] = $name . '="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"';
    }
    return implode(' ', $html);
}

function bms_profile_photo_picture_markup(string $path, string $alt = ''): string
{
    $relative = bms_profile_photo_normalize_path($path);
    $attributes = bms_profile_photo_image_attributes($path, $alt);
    if ($relative === '' || $attributes === '') {
        return '';
    }

    $modernSrcset = bms_profile_modern_variant_srcset($relative, bms_profile_photo_variant_widths(), true);
    if (!$modernSrcset || strtolower((string)(bms_media_image_dimensions_for_public_path($relative)['mime'] ?? '')) === 'image/webp') {
        return '<img ' . $attributes . '>';
    }

    $sizes = 'auto, (max-width: 640px) calc(50vw - 1.25rem), (max-width: 1100px) calc(50vw - 2rem), 520px';
    return '<picture class="profile-photo-picture">'
        . '<source type="image/webp" srcset="' . htmlspecialchars(implode(', ', $modernSrcset), ENT_QUOTES, 'UTF-8') . '" sizes="' . htmlspecialchars($sizes, ENT_QUOTES, 'UTF-8') . '">'
        . '<img ' . $attributes . '>'
        . '</picture>';
}

function bms_profile_public_photos(array $identity, int $userId): array
{
    $items = [];
    foreach (bms_profile_safe_photos(is_array($identity['profile_photos'] ?? null) ? $identity['profile_photos'] : [], $userId) as $photo) {
        $url = bms_profile_photo_url((string)$photo['path'], $userId);
        if ($url === '') {
            continue;
        }
        $attributes = bms_profile_photo_image_attributes((string)$photo['path'], (string)$photo['alt']);
        if ($attributes === '') {
            continue;
        }
        $items[] = [
            'path' => (string)$photo['path'],
            'url' => $url,
            'alt' => (string)$photo['alt'],
            'caption' => (string)$photo['caption'],
            'image_attributes' => $attributes,
            'image_markup' => bms_profile_photo_picture_markup((string)$photo['path'], (string)$photo['alt']),
        ];
        if (count($items) >= 4) {
            break;
        }
    }
    return $items;
}

function bms_profile_photo_form_rows(array $identity, int $userId): array
{
    $rows = [];
    $slot = 0;
    foreach (bms_profile_safe_photos(is_array($identity['profile_photos'] ?? null) ? $identity['profile_photos'] : [], $userId) as $photo) {
        if ($slot >= 4) {
            break;
        }
        $photo['slot'] = $slot;
        $photo['url'] = bms_profile_photo_url((string)$photo['path'], $userId);
        $photo['starter'] = false;
        $rows[] = $photo;
        $slot++;
    }
    if ($slot < 4) {
        $rows[] = [
            'slot' => $slot,
            'path' => '',
            'url' => '',
            'alt' => '',
            'caption' => '',
            'starter' => $slot > 0,
        ];
    }
    return $rows;
}

function bms_profile_photo_file_for_slot(array $files, int $slot): array
{
    $group = $files['profile_photo_files'] ?? null;
    if (!is_array($group) || !isset($group['error']) || !is_array($group['error'])) {
        return [];
    }
    return [
        'name' => (string)($group['name'][$slot] ?? ''),
        'type' => (string)($group['type'][$slot] ?? ''),
        'tmp_name' => (string)($group['tmp_name'][$slot] ?? ''),
        'error' => (int)($group['error'][$slot] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int)($group['size'][$slot] ?? 0),
    ];
}

function bms_profile_photo_store_upload(array $file, int $userId): string
{
    $valid = bms_media_validate_upload($file);
    $extension = strtolower((string)($valid['extension'] ?? ''));
    $mime = strtolower((string)($valid['mime'] ?? ''));
    $allowed = bms_profile_photo_allowed_extensions();
    if (!isset($allowed[$extension]) || !in_array($mime, array_values($allowed), true)) {
        throw new RuntimeException('Profile photos must be JPG, PNG, or WebP images.');
    }

    $folder = 'profile-photos/' . $userId;
    try {
        $random = bin2hex(random_bytes(5));
    } catch (Throwable $e) {
        $random = strtolower(str_replace('.', '', uniqid('', true)));
    }
    $filename = 'photo-' . date('YmdHis') . '-' . $random . '.' . $extension;
    $relativeWithinMedia = $folder . '/' . $filename;
    $destination = bms_media_public_root($relativeWithinMedia);
    $dir = dirname($destination);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the Profile photo upload folder.');
    }

    try {
        bms_media_privacy_store_upload($valid, $destination);
        @chmod($destination, 0644);
        $publicPath = 'media/' . $relativeWithinMedia;
        bms_profile_photo_generate_variants($publicPath);
        return $publicPath;
    } catch (Throwable $e) {
        if (is_file($destination)) {
            @unlink($destination);
        }
        bms_media_delete_generated_variants('media/' . $relativeWithinMedia);
        throw $e;
    }
}

function bms_profile_photo_delete_file(string $path, int $userId = 0): void
{
    $relative = bms_profile_photo_normalize_path($path, $userId);
    if ($relative === '') {
        return;
    }
    $file = bms_public_path($relative);
    if (is_file($file)) {
        @unlink($file);
    }
    bms_media_delete_generated_variants($relative);
}

function bms_apply_current_user_profile_photos_from_request(array $post, array $files): array
{
    $current = bms_current_user();
    $userId = (int)($current['id'] ?? 0);
    if ($userId < 1) {
        throw new RuntimeException('You must be signed in to edit Profile photos.');
    }

    $identity = bms_profile_identity_for_user($userId, $current);
    $currentPhotos = bms_profile_safe_photos(is_array($identity['profile_photos'] ?? null) ? $identity['profile_photos'] : [], $userId);
    $currentByPath = [];
    foreach ($currentPhotos as $photo) {
        $currentByPath[(string)$photo['path']] = $photo;
    }

    $rows = is_array($post['profile_photos'] ?? null) ? $post['profile_photos'] : [];
    $order = is_array($post['profile_photo_order'] ?? null) ? array_values($post['profile_photo_order']) : array_keys($rows);
    $removePaths = is_array($post['profile_photo_remove_paths'] ?? null) ? $post['profile_photo_remove_paths'] : [];
    $remove = [];
    foreach ($removePaths as $path) {
        $path = bms_profile_photo_normalize_path((string)$path, $userId);
        if ($path !== '' && isset($currentByPath[$path])) {
            $remove[$path] = true;
        }
    }

    $newPaths = [];
    $replacedOrRemoved = [];
    $represented = [];
    $photos = [];

    try {
        foreach ($order as $slotRaw) {
            if (count($photos) >= 4) {
                break;
            }
            $slot = (int)$slotRaw;
            if ($slot < 0 || $slot > 3 || !is_array($rows[$slot] ?? null)) {
                continue;
            }
            $row = $rows[$slot];
            $existingPath = bms_profile_photo_normalize_path((string)($row['existing_path'] ?? ''), $userId);
            if ($existingPath !== '' && !isset($currentByPath[$existingPath])) {
                $existingPath = '';
            }
            if ($existingPath !== '') {
                $represented[$existingPath] = true;
            }
            if (!empty($row['remove']) && $existingPath !== '') {
                $remove[$existingPath] = true;
                continue;
            }
            if ($existingPath !== '' && isset($remove[$existingPath])) {
                continue;
            }

            $alt = bms_profile_text_with_limit((string)($row['alt'] ?? ''), 240, 'Profile photo alt text');
            $caption = bms_profile_text_with_limit((string)($row['caption'] ?? ''), 500, 'Profile photo caption');
            $file = bms_profile_photo_file_for_slot($files, $slot);
            $hasUpload = $file && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $path = $existingPath;

            if ($hasUpload) {
                $path = bms_profile_photo_store_upload($file, $userId);
                $newPaths[] = $path;
                if ($existingPath !== '') {
                    $replacedOrRemoved[$existingPath] = true;
                }
            }

            if ($path === '') {
                continue;
            }
            $photos[] = ['path' => $path, 'alt' => $alt, 'caption' => $caption];
        }

        foreach ($currentPhotos as $photo) {
            if (count($photos) >= 4) {
                break;
            }
            $path = (string)$photo['path'];
            if (isset($represented[$path]) || isset($remove[$path]) || isset($replacedOrRemoved[$path])) {
                continue;
            }
            $photos[] = $photo;
        }

        foreach ($remove as $path => $_) {
            $replacedOrRemoved[$path] = true;
        }

        $stmt = bms_db()->prepare(
            'INSERT INTO ' . bms_table('user_profiles') . ' (user_id, headline, about_markdown, location, now_text, cover_image_path, links_json, interests_json, featured_items_json, profile_photos_json, show_post_count, show_comment_count, show_member_since, created_at, updated_at)
'
            . 'VALUES (:user_id, :headline, :about_markdown, :location, :now_text, :cover_image_path, :links_json, :interests_json, :featured_items_json, :profile_photos_json, :show_post_count, :show_comment_count, :show_member_since, NOW(), NOW())
'
            . 'ON DUPLICATE KEY UPDATE profile_photos_json = VALUES(profile_photos_json), updated_at = NOW()'
        );
        $stmt->execute([
            'user_id' => $userId,
            'headline' => (string)($identity['headline'] ?? ''),
            'about_markdown' => (string)($identity['about_markdown'] ?? ''),
            'location' => (string)($identity['location'] ?? ''),
            'now_text' => (string)($identity['now_text'] ?? ''),
            'cover_image_path' => (string)($identity['cover_image_path'] ?? ''),
            'links_json' => json_encode($identity['links'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            'interests_json' => json_encode($identity['interests'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            'featured_items_json' => json_encode($identity['featured_items'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            'profile_photos_json' => json_encode(array_values($photos), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            'show_post_count' => !empty($identity['show_post_count']) ? 1 : 0,
            'show_comment_count' => !empty($identity['show_comment_count']) ? 1 : 0,
            'show_member_since' => !empty($identity['show_member_since']) ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        foreach ($newPaths as $path) {
            bms_profile_photo_delete_file((string)$path, $userId);
        }
        throw $e;
    }

    foreach (array_keys($replacedOrRemoved) as $path) {
        bms_profile_photo_delete_file((string)$path, $userId);
    }

    return bms_profile_identity_for_user($userId, $current);
}

function bms_profile_normalize_interests(string $input): array
{
    $parts = preg_split('/[\r\n,]+/', $input) ?: [];
    $interests = [];
    $seen = [];

    foreach ($parts as $part) {
        $interest = bms_profile_text_with_limit((string)$part, 50, 'Interest');
        if ($interest === '') {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($interest) : strtolower($interest);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $interests[] = $interest;
        if (count($interests) >= 12) {
            break;
        }
    }
    return $interests;
}

function bms_profile_interests_form_value(array $identity): string
{
    $items = [];
    foreach (($identity['interests'] ?? []) as $interest) {
        $interest = trim((string)$interest);
        if ($interest !== '') {
            $items[] = $interest;
        }
    }
    return implode("\n", $items);
}

function bms_update_current_user_identity(
    string $displayName,
    string $bio,
    string $website,
    string $profileVisibility,
    string $headline,
    string $location,
    string $aboutMarkdown,
    string $nowText,
    array $linksInput,
    string $interestsInput,
    array $featuredInput,
    bool $showPostCount,
    bool $showCommentCount,
    bool $showMemberSince
): array {
    $current = bms_current_user();
    $currentId = (int)($current['id'] ?? 0);
    if ($currentId < 1) {
        throw new RuntimeException('You must be signed in to edit a profile.');
    }

    $displayName = bms_profile_text_with_limit($displayName, 120, 'Display name');
    if ($displayName === '') {
        throw new RuntimeException('Display name cannot be empty.');
    }
    $bio = bms_profile_text_with_limit($bio, 1000, 'Profile bio');
    $website = bms_profile_normalize_public_url($website, 'website URL');
    $profileVisibility = $profileVisibility === 'private' ? 'private' : 'public';
    $headline = bms_profile_text_with_limit($headline, 180, 'Headline');
    $location = bms_profile_text_with_limit($location, 190, 'Location');
    $aboutMarkdown = bms_profile_text_with_limit($aboutMarkdown, 12000, 'About section');
    $nowText = bms_profile_text_with_limit($nowText, 800, 'Now section');
    $links = bms_profile_normalize_links_input($linksInput);
    $interests = bms_profile_normalize_interests($interestsInput);
    $featuredItems = bms_profile_normalize_featured_input($featuredInput);

    $pdo = bms_db();
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare('UPDATE ' . bms_table('users') . ' SET display_name = :display_name, bio = :bio, website = :website, profile_visibility = :profile_visibility, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'display_name' => $displayName,
            'bio' => $bio,
            'website' => $website,
            'profile_visibility' => $profileVisibility,
            'id' => $currentId,
        ]);

        $stmt = $pdo->prepare(
            'INSERT INTO ' . bms_table('user_profiles') . ' (user_id, headline, about_markdown, location, now_text, cover_image_path, links_json, interests_json, featured_items_json, profile_photos_json, show_post_count, show_comment_count, show_member_since, created_at, updated_at)
             VALUES (:user_id, :headline, :about_markdown, :location, :now_text, :cover_image_path, :links_json, :interests_json, :featured_items_json, :profile_photos_json, :show_post_count, :show_comment_count, :show_member_since, NOW(), NOW())
             ON DUPLICATE KEY UPDATE headline = VALUES(headline), about_markdown = VALUES(about_markdown), location = VALUES(location), now_text = VALUES(now_text), links_json = VALUES(links_json), interests_json = VALUES(interests_json), featured_items_json = VALUES(featured_items_json), profile_photos_json = VALUES(profile_photos_json), show_post_count = VALUES(show_post_count), show_comment_count = VALUES(show_comment_count), show_member_since = VALUES(show_member_since), updated_at = NOW()'
        );
        $existingIdentity = bms_profile_identity_for_user($currentId, $current);
        $stmt->execute([
            'user_id' => $currentId,
            'headline' => $headline,
            'about_markdown' => $aboutMarkdown,
            'location' => $location,
            'now_text' => $nowText,
            'cover_image_path' => (string)($existingIdentity['cover_image_path'] ?? ''),
            'links_json' => json_encode($links, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            'interests_json' => json_encode($interests, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            'featured_items_json' => json_encode($featuredItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            'profile_photos_json' => json_encode($existingIdentity['profile_photos'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            'show_post_count' => $showPostCount ? 1 : 0,
            'show_comment_count' => $showCommentCount ? 1 : 0,
            'show_member_since' => $showMemberSince ? 1 : 0,
        ]);

        if (bms_normalize_role((string)($current['role'] ?? 'commenter')) === 'admin') {
            bms_set_setting('author_name', $displayName);
        }

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $updatedUser = bms_find_user_by_id($currentId) ?? bms_current_user();
    return [
        'user' => $updatedUser,
        'identity' => bms_profile_identity_for_user($currentId, $updatedUser),
    ];
}

function bms_profile_cover_allowed_extensions(): array
{
    return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
}

function bms_profile_cover_variant_widths(): array
{
    return [480, 640, 960, 1280, 1600];
}

function bms_profile_cover_normalize_path(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') {
        return '';
    }
    $path = ltrim($path, '/');
    if (!str_starts_with($path, 'media/')) {
        $path = 'media/' . $path;
    }
    return $path;
}

function bms_profile_cover_validate_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Cover image upload failed. Choose an image and try again.');
    }

    $originalName = (string)($file['name'] ?? 'cover');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = bms_profile_cover_allowed_extensions();
    if (!isset($allowed[$extension])) {
        throw new RuntimeException('Cover images must be JPG, PNG, or WebP images.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('The cover image file was empty.');
    }
    if ($size > 1024 * 1024 * 6) {
        throw new RuntimeException('Cover image is too large. Keep uploads under 6 MB.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new RuntimeException('Bonumark Stream could not read the cover image file.');
    }

    $imageInfo = @getimagesize($tmp);
    if (!is_array($imageInfo) || empty($imageInfo[0]) || empty($imageInfo[1])) {
        throw new RuntimeException('The uploaded cover does not appear to be a valid image.');
    }

    $mime = (string)($imageInfo['mime'] ?? '');
    if (!bms_media_mime_matches_extension($extension, $mime)) {
        throw new RuntimeException('Cover image type did not match the file extension.');
    }

    return [
        'tmp' => $tmp,
        'extension' => $extension,
        'mime' => $mime !== '' ? $mime : (string)$allowed[$extension],
        'width' => (int)$imageInfo[0],
        'height' => (int)$imageInfo[1],
    ];
}

function bms_profile_cover_generate_variants(string $coverPath): void
{
    $relative = bms_profile_cover_normalize_path($coverPath);
    if ($relative === '' || !str_starts_with($relative, 'media/profile-covers/')) {
        return;
    }

    $dimensions = bms_media_image_dimensions_for_public_path($relative);
    if (empty($dimensions['width']) || empty($dimensions['height'])) {
        return;
    }

    foreach (bms_profile_cover_variant_widths() as $width) {
        $width = (int)$width;
        if ($width > 0 && $width < (int)($dimensions['width'] ?? 0)) {
            bms_media_generate_responsive_variant($relative, $width, $dimensions);
        }
        if ($width > 0 && $width <= (int)($dimensions['width'] ?? 0)) {
            bms_profile_generate_modern_variant(
                $relative,
                $width,
                $dimensions,
                bms_profile_modern_image_quality()
            );
        }
    }

    $sourceWidth = (int)($dimensions['width'] ?? 0);
    $largestPreset = max(bms_profile_cover_variant_widths());
    if ($sourceWidth > 0 && $sourceWidth < $largestPreset) {
        bms_profile_generate_modern_variant(
            $relative,
            $sourceWidth,
            $dimensions,
            bms_profile_modern_image_quality()
        );
    }
}

function bms_profile_cover_delete_file(string $coverPath): void
{
    $relative = bms_profile_cover_normalize_path($coverPath);
    if ($relative === '' || !str_starts_with($relative, 'media/profile-covers/')) {
        return;
    }

    $file = bms_public_path($relative);
    if (is_file($file)) {
        @unlink($file);
    }
    bms_media_delete_generated_variants($relative);
}

function bms_profile_cover_url(array $identity): string
{
    $relative = bms_profile_cover_normalize_path((string)($identity['cover_image_path'] ?? ''));
    if ($relative === '' || !str_starts_with($relative, 'media/profile-covers/')) {
        return '';
    }
    if (!is_file(bms_public_path($relative))) {
        return '';
    }
    return bms_url_path($relative);
}

function bms_profile_cover_image_attributes(string $coverPath, string $alt = ''): string
{
    $relative = bms_profile_cover_normalize_path($coverPath);
    if ($relative === '' || !str_starts_with($relative, 'media/profile-covers/') || !is_file(bms_public_path($relative))) {
        return '';
    }

    $dimensions = bms_media_image_dimensions_for_public_path($relative);
    $sourceWidth = (int)($dimensions['width'] ?? 0);
    $sourceHeight = (int)($dimensions['height'] ?? 0);
    if ($sourceWidth < 1 || $sourceHeight < 1) {
        return '';
    }

    // Cover uploads intentionally live outside the Media Library table, so they
    // cannot use the generic recorded-variant lookup. Consume the verified
    // generated files directly and create a missing small candidate once for
    // upgraded installs.
    $srcset = bms_profile_image_variant_srcset($relative, bms_profile_cover_variant_widths(), true);
    $fallbackUrl = bms_profile_bounded_fallback_url($relative, 1280, true);
    $attributes = [
        'src' => $fallbackUrl !== '' ? $fallbackUrl : bms_url_path($relative),
        'alt' => $alt,
        'width' => (string)$sourceWidth,
        'height' => (string)$sourceHeight,
        'loading' => 'eager',
        'decoding' => 'async',
        'fetchpriority' => 'high',
        'class' => 'profile-cover-image ledger-profile-cover-image',
    ];
    if ($srcset) {
        $attributes['srcset'] = implode(', ', $srcset);
        $attributes['sizes'] = '(max-width: 760px) calc(100vw - 1rem), (max-width: 1120px) calc(100vw - 2rem), 1040px';
    }

    $html = [];
    foreach ($attributes as $name => $value) {
        $html[] = $name . '="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"';
    }
    return implode(' ', $html);
}

function bms_profile_cover_picture_markup(string $coverPath, string $alt = ''): string
{
    $relative = bms_profile_cover_normalize_path($coverPath);
    $attributes = bms_profile_cover_image_attributes($coverPath, $alt);
    if ($relative === '' || $attributes === '') {
        return '';
    }

    $modernSrcset = bms_profile_modern_variant_srcset($relative, bms_profile_cover_variant_widths(), true);
    if (!$modernSrcset || strtolower((string)(bms_media_image_dimensions_for_public_path($relative)['mime'] ?? '')) === 'image/webp') {
        return '<img ' . $attributes . '>';
    }

    $sizes = '(max-width: 760px) calc(100vw - 1rem), (max-width: 1120px) calc(100vw - 2rem), 1040px';
    return '<picture class="profile-cover-picture">'
        . '<source type="image/webp" srcset="' . htmlspecialchars(implode(', ', $modernSrcset), ENT_QUOTES, 'UTF-8') . '" sizes="' . htmlspecialchars($sizes, ENT_QUOTES, 'UTF-8') . '">'
        . '<img ' . $attributes . '>'
        . '</picture>';
}

function bms_profile_cover_preload_markup(string $coverPath): string
{
    $relative = bms_profile_cover_normalize_path($coverPath);
    if ($relative === '' || !is_file(bms_public_path($relative))) {
        return '';
    }

    $sizes = '(max-width: 760px) calc(100vw - 1rem), (max-width: 1120px) calc(100vw - 2rem), 1040px';
    $modernSrcset = bms_profile_modern_variant_srcset($relative, bms_profile_cover_variant_widths(), true);
    if ($modernSrcset) {
        $last = (string)end($modernSrcset);
        $href = preg_replace('/\s+\d+w$/', '', $last) ?: bms_url_path($relative);
        return '<link rel="preload" as="image" type="image/webp" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
            . '" imagesrcset="' . htmlspecialchars(implode(', ', $modernSrcset), ENT_QUOTES, 'UTF-8')
            . '" imagesizes="' . htmlspecialchars($sizes, ENT_QUOTES, 'UTF-8') . '" fetchpriority="high">';
    }

    $fallbackSrcset = bms_profile_image_variant_srcset($relative, bms_profile_cover_variant_widths(), true);
    if (!$fallbackSrcset) {
        return '';
    }
    $href = bms_profile_bounded_fallback_url($relative, 1280, true);
    return '<link rel="preload" as="image" href="' . htmlspecialchars($href !== '' ? $href : bms_url_path($relative), ENT_QUOTES, 'UTF-8')
        . '" imagesrcset="' . htmlspecialchars(implode(', ', $fallbackSrcset), ENT_QUOTES, 'UTF-8')
        . '" imagesizes="' . htmlspecialchars($sizes, ENT_QUOTES, 'UTF-8') . '" fetchpriority="high">';
}

function bms_update_current_user_profile_cover(array $file): array
{
    $current = bms_current_user();
    $currentId = (int)($current['id'] ?? 0);
    if ($currentId < 1) {
        throw new RuntimeException('You must be signed in to upload a cover image.');
    }

    $valid = bms_profile_cover_validate_upload($file);
    if (!$valid) {
        return bms_profile_identity_for_user($currentId, $current);
    }

    $identity = bms_profile_identity_for_user($currentId, $current);
    $folder = 'profile-covers/' . $currentId;
    $filename = 'cover-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . (string)$valid['extension'];
    $relative = $folder . '/' . $filename;
    $destination = bms_media_public_root($relative);
    $dir = dirname($destination);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create the profile cover upload folder.');
    }

    $moved = is_uploaded_file((string)$valid['tmp'])
        ? move_uploaded_file((string)$valid['tmp'], $destination)
        : copy((string)$valid['tmp'], $destination);
    if (!$moved) {
        throw new RuntimeException('Could not store the cover image.');
    }
    @chmod($destination, 0644);

    $coverPath = 'media/' . $relative;
    bms_profile_cover_generate_variants($coverPath);

    $stmt = bms_db()->prepare(
        'INSERT INTO ' . bms_table('user_profiles') . ' (user_id, headline, about_markdown, location, now_text, cover_image_path, links_json, interests_json, featured_items_json, profile_photos_json, show_post_count, show_comment_count, show_member_since, created_at, updated_at)
         VALUES (:user_id, :headline, :about_markdown, :location, :now_text, :cover_image_path, :links_json, :interests_json, :featured_items_json, :profile_photos_json, :show_post_count, :show_comment_count, :show_member_since, NOW(), NOW())
         ON DUPLICATE KEY UPDATE cover_image_path = VALUES(cover_image_path), updated_at = NOW()'
    );
    $stmt->execute([
        'user_id' => $currentId,
        'headline' => (string)($identity['headline'] ?? ''),
        'about_markdown' => (string)($identity['about_markdown'] ?? ''),
        'location' => (string)($identity['location'] ?? ''),
        'now_text' => (string)($identity['now_text'] ?? ''),
        'cover_image_path' => $coverPath,
        'links_json' => json_encode($identity['links'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        'interests_json' => json_encode($identity['interests'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        'featured_items_json' => json_encode($identity['featured_items'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        'profile_photos_json' => json_encode($identity['profile_photos'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        'show_post_count' => !empty($identity['show_post_count']) ? 1 : 0,
        'show_comment_count' => !empty($identity['show_comment_count']) ? 1 : 0,
        'show_member_since' => !empty($identity['show_member_since']) ? 1 : 0,
    ]);

    bms_profile_cover_delete_file((string)($identity['cover_image_path'] ?? ''));
    return bms_profile_identity_for_user($currentId, bms_find_user_by_id($currentId) ?? $current);
}

function bms_remove_current_user_profile_cover(): array
{
    $current = bms_current_user();
    $currentId = (int)($current['id'] ?? 0);
    if ($currentId < 1) {
        throw new RuntimeException('You must be signed in to remove a cover image.');
    }

    $identity = bms_profile_identity_for_user($currentId, $current);
    bms_profile_cover_delete_file((string)($identity['cover_image_path'] ?? ''));
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('user_profiles') . " SET cover_image_path = '', updated_at = NOW() WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $currentId]);
    return bms_profile_identity_for_user($currentId, $current);
}

function bms_apply_current_user_profile_cover_from_request(array $files, bool $removeCover = false): array
{
    if ($removeCover) {
        return bms_remove_current_user_profile_cover();
    }

    $file = $files['cover_image'] ?? null;
    if (is_array($file) && (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        return bms_update_current_user_profile_cover($file);
    }

    $current = bms_current_user();
    return bms_profile_identity_for_user((int)($current['id'] ?? 0), $current);
}

function bms_public_site_owner_user(): ?array
{
    if (!bms_is_installed()) {
        return null;
    }
    try {
        $stmt = bms_db()->query('SELECT id, username, display_name, role, status, bio, website, social_links, profile_visibility, avatar_path, created_at, updated_at FROM ' . bms_table('users') . " WHERE role = 'admin' AND status = 'active' ORDER BY id ASC LIMIT 1");
        $user = $stmt->fetch();
        if (!is_array($user)) {
            return null;
        }
        return bms_profile_user_is_viewable($user) ? $user : null;
    } catch (Throwable $e) {
        return null;
    }
}

function bms_account_post_counts(int $userId): array
{
    $counts = ['published' => 0, 'draft' => 0, 'total' => 0];
    if ($userId < 1 || !bms_is_installed()) {
        return $counts;
    }
    try {
        $stmt = bms_db()->prepare('SELECT status, COUNT(*) AS total FROM ' . bms_table('posts') . ' WHERE author_id = :author_id GROUP BY status');
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

function bms_account_comment_counts(int $userId): array
{
    $counts = ['approved' => 0, 'pending' => 0, 'trash' => 0, 'total' => 0];
    if ($userId < 1 || !bms_is_installed()) {
        return $counts;
    }
    try {
        $stmt = bms_db()->prepare('SELECT status, COUNT(*) AS total FROM ' . bms_table('comments') . ' WHERE user_id = :user_id GROUP BY status');
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

function bms_account_recent_comments(int $userId, int $limit = 10): array
{
    if ($userId < 1 || !bms_is_installed()) {
        return [];
    }
    $limit = max(1, min(250, $limit));
    try {
        $stmt = bms_db()->prepare('SELECT c.id, c.post_slug, c.post_id, c.body, c.status, c.created_at, c.updated_at, p.title AS post_title, p.slug AS resolved_post_slug FROM ' . bms_table('comments') . ' c LEFT JOIN ' . bms_table('posts') . ' p ON p.id = c.post_id WHERE c.user_id = :user_id AND c.status IN (\'approved\', \'pending\') ORDER BY c.created_at DESC, c.id DESC LIMIT ' . $limit);
        $stmt->execute(['user_id' => $userId]);
        $items = [];
        foreach (($stmt->fetchAll() ?: []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = bms_slugify((string)($row['resolved_post_slug'] ?? $row['post_slug'] ?? ''));
            $body = trim((string)($row['body'] ?? ''));
            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'post_slug' => $slug,
                'post_title' => trim((string)($row['post_title'] ?? '')) ?: 'Stream Post',
                'post_url' => $slug !== '' ? bms_stream_url($slug) . '#comments' : '',
                'body' => $body,
                'excerpt' => bms_account_activity_excerpt($body, 160),
                'status' => function_exists('bms_comment_normalize_status') ? bms_comment_normalize_status((string)($row['status'] ?? 'pending')) : ((string)($row['status'] ?? 'pending') === 'approved' ? 'approved' : 'pending'),
                'status_label' => function_exists('bms_comment_status_label') ? bms_comment_status_label((string)($row['status'] ?? 'pending')) : ucfirst((string)($row['status'] ?? 'pending')),
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

function bms_account_recent_stream_posts(int $userId, int $limit = 6): array
{
    if ($userId < 1 || !bms_is_installed()) {
        return [];
    }
    $limit = max(1, min(20, $limit));
    try {
        $stmt = bms_db()->prepare('SELECT id, title, slug, status, description, created_at, updated_at, published_at FROM ' . bms_table('posts') . ' WHERE author_id = :author_id AND status IN (\'published\', \'draft\') ORDER BY updated_at DESC, created_at DESC LIMIT ' . $limit);
        $stmt->execute(['author_id' => $userId]);
        $items = [];
        foreach (($stmt->fetchAll() ?: []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = bms_slugify((string)($row['slug'] ?? ''));
            $status = (string)($row['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => trim((string)($row['title'] ?? '')) ?: 'Stream Post',
                'slug' => $slug,
                'status' => $status,
                'status_label' => $status === 'published' ? 'Published' : 'Draft',
                'public_url' => ($status === 'published' && $slug !== '') ? bms_stream_url($slug) : '',
                'edit_url' => bms_admin_url('edit.php?section=' . ($status === 'published' ? 'published' : 'drafts') . '&file=' . rawurlencode($slug . '.md')),
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

function bms_account_activity_excerpt(string $body, int $limit = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return bms_text_length($text) > $limit ? rtrim(bms_text_substr($text, 0, max(0, $limit - 1))) . '…' : $text;
    }
    return strlen($text) > $limit ? rtrim(substr($text, 0, max(0, $limit - 1))) . '…' : $text;
}

function bms_account_dashboard_data(array $user): array
{
    $userId = (int)($user['id'] ?? 0);
    $role = bms_normalize_role((string)($user['role'] ?? 'commenter'));
    $visibility = (string)($user['profile_visibility'] ?? 'public') === 'private' ? 'private' : 'public';
    $emailVerified = trim((string)($user['email_verified_at'] ?? '')) !== '';
    $postCounts = bms_account_post_counts($userId);
    $commentCounts = bms_account_comment_counts($userId);

    return [
        'role_label' => bms_role_label($role),
        'status_label' => bms_user_status_label((string)($user['status'] ?? 'active')),
        'visibility_label' => $visibility === 'private' ? 'Private' : 'Public',
        'email_status_label' => $emailVerified ? 'Verified' : 'Unverified',
        'member_since' => (string)($user['created_at'] ?? ''),
        'post_counts' => $postCounts,
        'comment_counts' => $commentCounts,
        'profile_url' => bms_public_profile_url_for_user($user),
        'can_write_posts' => $role === 'admin',
        'can_comment' => in_array($role, ['admin', 'commenter'], true),
        'recent_comments' => bms_account_recent_comments($userId),
        'recent_posts' => bms_account_recent_stream_posts($userId),
    ];
}


function bms_profile_metadata_plain_text(string $text, int $limit = 160): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    if (function_exists('bms_markdown_to_html')) {
        $text = strip_tags((string)bms_markdown_to_html($text, false));
    } else {
        $text = strip_tags($text);
    }
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') {
        return '';
    }

    if (function_exists('bms_stream_limit_text')) {
        return bms_stream_limit_text($text, $limit, '…');
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return bms_text_length($text) > $limit ? rtrim(bms_text_substr($text, 0, max(0, $limit - 1))) . '…' : $text;
    }
    return strlen($text) > $limit ? rtrim(substr($text, 0, max(0, $limit - 1))) . '…' : $text;
}

function bms_profile_metadata_description(array $user, array $identity): string
{
    $bio = bms_profile_metadata_plain_text((string)($user['bio'] ?? ''), 160);
    if ($bio !== '') {
        return $bio;
    }

    $about = bms_profile_metadata_plain_text((string)($identity['about_markdown'] ?? ''), 160);
    if ($about !== '') {
        return $about;
    }

    $headline = bms_profile_metadata_plain_text((string)($identity['headline'] ?? ''), 160);
    if ($headline !== '') {
        return $headline;
    }

    $name = trim((string)($user['display_name'] ?? $user['username'] ?? ''));
    return $name !== '' ? 'Public profile for ' . $name . '.' : '';
}

function bms_profile_absolute_public_url(string $pathOrUrl): string
{
    $pathOrUrl = trim($pathOrUrl);
    if ($pathOrUrl === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $pathOrUrl) === 1) {
        return $pathOrUrl;
    }

    $basePath = function_exists('bms_base_path') ? bms_base_path() : '';
    if ($basePath !== '' && ($pathOrUrl === $basePath || str_starts_with($pathOrUrl, $basePath . '/'))) {
        $pathOrUrl = substr($pathOrUrl, strlen($basePath));
    }
    return function_exists('bms_site_url') ? bms_site_url(ltrim($pathOrUrl, '/')) : $pathOrUrl;
}

function bms_profile_metadata_same_as(array $user, array $links): array
{
    $urls = [];
    $candidates = [(string)($user['website'] ?? '')];
    foreach ($links as $link) {
        if (is_array($link)) {
            $candidates[] = (string)($link['url'] ?? '');
        }
    }

    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '' || preg_match('#^https?://#i', $candidate) !== 1) {
            continue;
        }
        $key = strtolower($candidate);
        if (!isset($urls[$key])) {
            $urls[$key] = $candidate;
        }
    }
    return array_values($urls);
}

function bms_profile_structured_metadata(array $user, array $identity, array $links, array $interests, string $canonical, string $description, string $avatarUrl): array
{
    $name = trim((string)($user['display_name'] ?? $user['username'] ?? ''));
    $username = trim((string)($user['username'] ?? ''));
    if ($name === '' || $canonical === '') {
        return [];
    }

    $person = [
        '@type' => 'Person',
        '@id' => $canonical . '#person',
        'name' => $name,
        'url' => $canonical,
    ];
    if ($username !== '') {
        $person['alternateName'] = '@' . $username;
    }
    if ($description !== '') {
        $person['description'] = $description;
    }
    if ($avatarUrl !== '') {
        $person['image'] = $avatarUrl;
    }

    $sameAs = bms_profile_metadata_same_as($user, $links);
    if ($sameAs) {
        $person['sameAs'] = $sameAs;
    }
    if ($interests) {
        $person['knowsAbout'] = array_values($interests);
    }

    return [
        '@context' => 'https://schema.org',
        '@type' => 'ProfilePage',
        '@id' => $canonical,
        'url' => $canonical,
        'name' => $name,
        'description' => $description,
        'mainEntity' => $person,
    ];
}

function bms_profile_metadata_payload(?array $user, array $identity, array $links, array $interests, string $canonical, string $coverUrl = ''): array
{
    if (!$user) {
        return [
            'og_type' => 'website',
            'robots' => 'noindex,nofollow',
            'structured_data' => [],
        ];
    }

    $name = trim((string)($user['display_name'] ?? $user['username'] ?? ''));
    $username = trim((string)($user['username'] ?? ''));
    $description = bms_profile_metadata_description($user, $identity);
    $visibility = (string)($user['profile_visibility'] ?? 'public') === 'private' ? 'private' : 'public';
    $avatarUrl = bms_profile_absolute_public_url(function_exists('bms_user_avatar_url') ? bms_user_avatar_url($user, 192, true) : '');
    $coverAbsolute = bms_profile_absolute_public_url($coverUrl);
    $imageUrl = $coverAbsolute !== '' ? $coverAbsolute : $avatarUrl;
    $imageAlt = $imageUrl !== '' ? ($coverAbsolute !== '' ? $name . ' profile cover' : $name . ' profile picture') : '';

    return [
        'og_type' => 'profile',
        'username' => $username,
        'author_name' => $name,
        'description' => $description,
        'canonical' => $canonical,
        'image_url' => $imageUrl,
        'image_alt' => $imageAlt,
        'twitter_card' => $coverAbsolute !== '' ? 'summary_large_image' : 'summary',
        'robots' => $visibility === 'private' ? 'noindex,nofollow' : '',
        'structured_data' => $visibility === 'public'
            ? bms_profile_structured_metadata($user, $identity, $links, $interests, $canonical, $description, $avatarUrl)
            : [],
    ];
}


function bms_profile_page_html(?array $user): string
{
    $siteNameRaw = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
    $titleRaw = $user ? (string)($user['display_name'] ?? $user['username']) : 'Profile not found';
    $canonicalPath = $user ? bms_public_profile_url_for_user($user) : bms_url_path('profile');

    $identity = $user ? bms_profile_identity_for_user((int)$user['id'], $user) : bms_profile_identity_defaults();
    $aboutMarkdown = trim((string)($identity['about_markdown'] ?? ''));
    $aboutHtml = $aboutMarkdown !== '' ? bms_markdown_to_html($aboutMarkdown, false) : '';
    $links = $user ? bms_profile_safe_links(is_array($identity['links'] ?? null) ? $identity['links'] : []) : [];
    $featuredItems = $user ? bms_profile_resolved_featured_items(is_array($identity['featured_items'] ?? null) ? $identity['featured_items'] : []) : [];
    $profilePhotos = $user ? bms_profile_public_photos($identity, (int)$user['id']) : [];
    $interests = [];
    foreach (($identity['interests'] ?? []) as $interest) {
        $interest = trim((string)$interest);
        if ($interest !== '') {
            $interests[] = $interest;
        }
        if (count($interests) >= 12) {
            break;
        }
    }

    $showPostCount = $user && !empty($identity['show_post_count']);
    $showCommentCount = $user && !empty($identity['show_comment_count']);
    $showMemberSince = $user && !empty($identity['show_member_since']);
    $current = function_exists('bms_current_user') ? bms_current_user() : [];
    $isOwner = $user && (int)($current['id'] ?? 0) > 0 && (int)($current['id'] ?? 0) === (int)($user['id'] ?? 0);
    $coverUrl = $user ? bms_profile_cover_url($identity) : '';
    $canonicalAbsolute = bms_profile_absolute_public_url($canonicalPath);
    $metadataDescription = $user ? bms_profile_metadata_description($user, $identity) : '';
    $socialTitle = $titleRaw;
    $headlineForMetadata = trim((string)($identity['headline'] ?? ''));
    if ($user && $headlineForMetadata !== '' && function_exists('bms_seo_join_title_parts')) {
        $socialTitle = bms_seo_join_title_parts([$titleRaw, $headlineForMetadata], 100);
    }
    $profileMetadata = bms_profile_metadata_payload($user, $identity, $links, $interests, $canonicalAbsolute, $coverUrl);
    $coverMarkup = '';
    $coverPreloadMarkup = '';
    if ($coverUrl !== '') {
        $coverPath = (string)($identity['cover_image_path'] ?? '');
        $coverMarkup = bms_profile_cover_picture_markup($coverPath, '');
        $coverPreloadMarkup = bms_profile_cover_preload_markup($coverPath);
    }

    $view = [
        'site_name' => $siteNameRaw,
        'title' => $titleRaw,
        'seo_title_primary' => $titleRaw,
        'seo_social_title' => $socialTitle,
        'description' => $metadataDescription,
        'canonical' => $canonicalAbsolute,
        'profile_metadata' => $profileMetadata,
        'style_url' => bms_asset_url('assets/style.css'),
        'script_url' => bms_asset_url('assets/stream.js'),
        'theme_stylesheet_links' => bms_public_theme_stylesheet_links(),
        'favicon_tags' => function_exists('bms_site_favicon_tags') ? bms_site_favicon_tags() : '',
        'head_preload_html' => $coverPreloadMarkup,
        'theme_script_tags' => bms_public_theme_script_tags(),
        'body_class' => bms_public_theme_class('profile-page'),
        'header_html' => bms_render_public_header('profile', null, $canonicalPath),
        'footer_html' => bms_render_public_footer($canonicalPath),
        'home_url' => bms_url_path(),
        'user' => $user,
        'display_name' => $user ? (string)($user['display_name'] ?? $user['username']) : '',
        'username' => $user ? (string)($user['username'] ?? '') : '',
        'headline' => trim((string)($identity['headline'] ?? '')),
        'bio' => $user ? trim((string)($user['bio'] ?? '')) : '',
        'location' => trim((string)($identity['location'] ?? '')),
        'about_html' => $aboutHtml,
        'now_text' => trim((string)($identity['now_text'] ?? '')),
        'website' => $user ? trim((string)($user['website'] ?? '')) : '',
        'profile_links' => $links,
        'featured_items' => $featuredItems,
        'profile_photos' => $profilePhotos,
        'interests' => $interests,
        'avatar_markup' => $user ? bms_user_avatar_markup($user, '', 192, 192) : '',
        'cover_markup' => $coverMarkup,
        'cover_url' => $coverUrl,
        'show_post_count' => $showPostCount,
        'show_comment_count' => $showCommentCount,
        'show_member_since' => $showMemberSince,
        'post_count' => $showPostCount ? bms_profile_post_count((int)$user['id']) : 0,
        'comment_count' => $showCommentCount ? bms_profile_comment_count((int)$user['id']) : 0,
        'member_since' => $showMemberSince ? bms_profile_member_since_label($user) : '',
        'profile_url' => $user ? bms_public_profile_url_for_user($user) : '',
        'is_profile_owner' => $isOwner,
        'edit_profile_url' => $isOwner ? bms_url_path('account.php?section=profile') : '',
    ];

    return bms_render_public_theme_template('profile', $view);
}
