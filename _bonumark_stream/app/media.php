<?php
require_once __DIR__ . '/database.php';

function bms_allowed_media_extensions(): array
{
    return [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
    ];
}

function bms_allowed_media_accept_attribute(): string
{
    return 'image/*,audio/*,video/*,.pdf,.doc,.docx,.txt';
}

function bms_allowed_media_extensions_label(): string
{
    return 'JPG, PNG, GIF, WebP, MP3, M4A, WAV, OGG, MP4, WebM, MOV, PDF, DOC, DOCX, and TXT';
}

function bms_media_public_root(string $path = ''): string
{
    return bms_public_path('media' . ($path ? '/' . ltrim($path, '/') : ''));
}

function bms_media_url(string $relativePath): string
{
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if (str_starts_with($relativePath, 'media/')) {
        return bms_url_path($relativePath);
    }
    return bms_url_path('media/' . $relativePath);
}

function bms_media_safe_name(string $originalName): string
{
    $name = pathinfo($originalName, PATHINFO_FILENAME);
    $slug = bms_slugify($name !== '' ? $name : 'media');
    return $slug !== '' ? $slug : 'media';
}

function bms_media_file_hash(string $path): string
{
    return is_file($path) ? hash_file('sha256', $path) : '';
}

function bms_media_expected_mime_for_extension(string $extension): string
{
    $allowed = bms_allowed_media_extensions();
    return (string)($allowed[strtolower($extension)] ?? 'application/octet-stream');
}

function bms_media_mime_matches_extension(string $extension, string $mime): bool
{
    $extension = strtolower($extension);
    $mime = strtolower(trim($mime));
    if ($mime === '') {
        return true;
    }

    $expected = strtolower(bms_media_expected_mime_for_extension($extension));
    if ($mime === $expected) {
        return true;
    }

    $aliases = [
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'audio/aac'],
        'wav' => ['audio/wav', 'audio/x-wav'],
        'mp4' => ['video/mp4', 'application/mp4'],
        'mov' => ['video/quicktime', 'video/mp4'],
        'txt' => ['text/plain', 'text/x-plain'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'pdf' => ['application/pdf', 'application/octet-stream'],
    ];

    return in_array($mime, $aliases[$extension] ?? [], true);
}


function bms_current_media_upload_limit_mb(): int
{
    $mb = (int)bms_setting_or_config('media_upload_limit_mb', '32');
    return max(1, min(128, $mb));
}

function bms_current_media_upload_limit_bytes(): int
{
    return bms_current_media_upload_limit_mb() * 1024 * 1024;
}

function bms_media_validate_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed. Choose a media file and try again.');
    }

    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = bms_allowed_media_extensions();
    if (!isset($allowed[$extension])) {
        throw new RuntimeException('Unsupported media type. Allowed formats: ' . bms_allowed_media_extensions_label() . '.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('The uploaded media file was empty.');
    }
    $limitBytes = bms_current_media_upload_limit_bytes();
    if ($size > $limitBytes) {
        throw new RuntimeException('Media file is too large. Keep uploads under ' . bms_media_human_size($limitBytes) . '.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new RuntimeException('Bonumark Stream could not read the uploaded media file.');
    }

    $width = null;
    $height = null;
    $mime = '';
    $isImage = str_starts_with((string)$allowed[$extension], 'image/');

    if ($isImage) {
        $imageInfo = @getimagesize($tmp);
        if (!is_array($imageInfo) || empty($imageInfo[0]) || empty($imageInfo[1])) {
            throw new RuntimeException('The uploaded file does not appear to be a valid image.');
        }
        $width = (int)$imageInfo[0];
        $height = (int)$imageInfo[1];
        $mime = (string)($imageInfo['mime'] ?? '');
    }

    if ($mime === '' && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    }

    if (!bms_media_mime_matches_extension($extension, $mime)) {
        throw new RuntimeException('Media type did not match the file extension.');
    }

    $normalizedMime = $mime !== '' ? ($mime === 'image/pjpeg' ? 'image/jpeg' : $mime) : (string)$allowed[$extension];
    if (in_array($extension, ['docx'], true)) {
        $normalizedMime = (string)$allowed[$extension];
    }

    return [
        'tmp' => $tmp,
        'original_name' => $originalName !== '' ? $originalName : ('media.' . $extension),
        'extension' => $extension,
        'mime' => $normalizedMime,
        'size' => $size,
        'width' => $width,
        'height' => $height,
    ];
}

function bms_media_unique_relative_path(string $originalName, string $extension): string
{
    $folder = date('Y/m');
    $base = bms_media_safe_name($originalName);
    $relative = $folder . '/' . $base . '.' . $extension;
    $counter = 2;
    while (is_file(bms_media_public_root($relative))) {
        $relative = $folder . '/' . $base . '-' . $counter . '.' . $extension;
        $counter++;
    }
    return $relative;
}


function bms_media_user_can_manage_item(array $media): bool
{
    if (!function_exists('bms_current_user')) {
        return false;
    }

    return function_exists('bms_current_user_can') && bms_current_user_can('manage_media');
}

function bms_require_media_item_access(array $media): void
{
    if (!bms_media_user_can_manage_item($media)) {
        bms_abort_request('You do not have permission to manage this media item.', 403);
    }
}

function bms_media_normalize_status(string $status): string
{
    $status = strtolower(trim($status));
    return in_array($status, ['active', 'trash', 'all'], true) ? $status : 'active';
}

function bms_media_is_trashed(array $media): bool
{
    return trim((string)($media['trashed_at'] ?? '')) !== '';
}

function bms_media_ids_from_request(array $source): array
{
    $raw = $source['media_ids'] ?? [];
    if (!is_array($raw)) {
        $raw = [$raw];
    }

    $ids = [];
    foreach ($raw as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function bms_media_user_scope_sql(string $alias = ''): array
{
    return ['', []];
}

function bms_media_upload(array $file, string $altText = '', string $caption = '', array $options = []): array
{
    if (!bms_is_installed()) {
        throw new RuntimeException('Bonumark Stream must be installed before uploading media.');
    }

    $valid = bms_media_validate_upload($file);
    $generateDerivatives = array_key_exists('generate_derivatives', $options) ? (bool)$options['generate_derivatives'] : true;
    $relative = bms_media_unique_relative_path((string)$valid['original_name'], (string)$valid['extension']);
    $destination = bms_media_public_root($relative);
    $dir = dirname($destination);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create the media upload folder.');
    }

    $moved = is_uploaded_file((string)$valid['tmp'])
        ? move_uploaded_file((string)$valid['tmp'], $destination)
        : copy((string)$valid['tmp'], $destination);
    if (!$moved) {
        throw new RuntimeException('Could not store the uploaded media file.');
    }
    @chmod($destination, 0644);
    $altText = trim($altText);
    $caption = trim($caption);
    if ($altText === '') {
        $altText = str_replace('-', ' ', bms_media_safe_name((string)$valid['original_name']));
    }

    $storedSize = is_file($destination) ? (int)filesize($destination) : (int)$valid['size'];
    $storedWidth = (int)$valid['width'];
    $storedHeight = (int)$valid['height'];
    if (str_starts_with((string)$valid['mime'], 'image/')) {
        $storedInfo = @getimagesize($destination);
        if (is_array($storedInfo) && !empty($storedInfo[0]) && !empty($storedInfo[1])) {
            $storedWidth = (int)$storedInfo[0];
            $storedHeight = (int)$storedInfo[1];
        }
    }

    $imageVariants = [];
    if ($generateDerivatives && str_starts_with((string)$valid['mime'], 'image/')) {
        $imageVariants = bms_media_generate_upload_derivatives('media/' . $relative);
    }
    $imageVariantsJson = $imageVariants ? json_encode($imageVariants, JSON_UNESCAPED_SLASHES) : null;

    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('media') . ' (filename, original_filename, public_path, mime_type, file_size, width, height, alt_text, caption, uploaded_by, file_hash, image_variants_json, created_at, updated_at) VALUES (:filename, :original_filename, :public_path, :mime_type, :file_size, :width, :height, :alt_text, :caption, :uploaded_by, :file_hash, :image_variants_json, NOW(), NOW())');
    $stmt->execute([
        'filename' => basename($relative),
        'original_filename' => (string)$valid['original_name'],
        'public_path' => 'media/' . $relative,
        'mime_type' => (string)$valid['mime'],
        'file_size' => $storedSize,
        'width' => $storedWidth > 0 ? $storedWidth : null,
        'height' => $storedHeight > 0 ? $storedHeight : null,
        'alt_text' => $altText,
        'caption' => $caption,
        'uploaded_by' => bms_current_user_id(),
        'file_hash' => bms_media_file_hash($destination),
        'image_variants_json' => $imageVariantsJson,
    ]);

    return bms_media_find((int)bms_db()->lastInsertId()) ?? [];
}

function bms_media_list(int $limit = 100, string $search = '', string $status = 'active'): array
{
    try {
        $limit = max(1, min(500, $limit));
        $status = bms_media_normalize_status($status);
        $sql = 'SELECT * FROM ' . bms_table('media');
        $params = [];
        $where = [];

        [$ownerWhere, $ownerParams] = bms_media_user_scope_sql();
        if ($ownerWhere !== '') {
            $where[] = $ownerWhere;
            $params = array_merge($params, $ownerParams);
        }

        if ($status === 'trash') {
            $where[] = 'trashed_at IS NOT NULL';
        } elseif ($status === 'active') {
            $where[] = 'trashed_at IS NULL';
        }

        $search = trim($search);
        if ($search !== '') {
            $where[] = '(original_filename LIKE :search OR filename LIKE :search OR alt_text LIKE :search OR caption LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= $status === 'trash'
            ? ' ORDER BY trashed_at DESC, updated_at DESC, id DESC LIMIT ' . $limit
            : ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
        $stmt = bms_db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function bms_media_find(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    try {
        $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('media') . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function bms_media_find_by_public_path(string $publicPath): ?array
{
    $relative = bms_media_public_relative_from_url($publicPath);
    if ($relative === '') {
        $relative = trim(str_replace('\\', '/', html_entity_decode($publicPath, ENT_QUOTES | ENT_HTML5, 'UTF-8')), '/');
    }

    if ($relative === '' || !str_starts_with($relative, 'media/') || str_starts_with($relative, 'media/_generated/')) {
        return null;
    }
    if (preg_match('#(^|/)\.\.(/|$)#', $relative) === 1 || preg_match('/[\r\n]/', $relative) === 1) {
        return null;
    }
    if (function_exists('bms_is_installed') && !bms_is_installed()) {
        return null;
    }

    try {
        $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('media') . ' WHERE public_path = :public_path LIMIT 1');
        $stmt->execute(['public_path' => $relative]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function bms_media_update(int $id, string $altText, string $caption): void
{
    if ($id <= 0) {
        throw new RuntimeException('Invalid media item.');
    }
    $media = bms_media_find($id);
    if (!$media) {
        throw new RuntimeException('Media item not found.');
    }
    bms_require_media_item_access($media);
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('media') . ' SET alt_text = :alt_text, caption = :caption, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'alt_text' => trim($altText),
        'caption' => trim($caption),
        'id' => $id,
    ]);
}

function bms_media_trash(int $id): void
{
    $media = bms_media_find($id);
    if (!$media) {
        throw new RuntimeException('Media item not found.');
    }
    bms_require_media_item_access($media);
    if (bms_media_is_trashed($media)) {
        return;
    }

    $stmt = bms_db()->prepare('UPDATE ' . bms_table('media') . ' SET trashed_at = NOW(), trashed_by = :trashed_by, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'trashed_by' => function_exists('bms_current_user_id') ? bms_current_user_id() : null,
        'id' => $id,
    ]);
}

function bms_media_restore(int $id): void
{
    $media = bms_media_find($id);
    if (!$media) {
        throw new RuntimeException('Media item not found.');
    }
    bms_require_media_item_access($media);
    if (!bms_media_is_trashed($media)) {
        return;
    }

    $stmt = bms_db()->prepare('UPDATE ' . bms_table('media') . ' SET trashed_at = NULL, trashed_by = NULL, updated_at = NOW() WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

function bms_media_delete_permanently(int $id): void
{
    $media = bms_media_find($id);
    if (!$media) {
        throw new RuntimeException('Media item not found.');
    }
    bms_require_media_item_access($media);
    if (!bms_media_is_trashed($media)) {
        throw new RuntimeException('Move this media item to trash before permanent deletion.');
    }

    $publicPath = trim((string)($media['public_path'] ?? ''));
    if ($publicPath !== '' && str_starts_with($publicPath, 'media/')) {
        $file = bms_public_path($publicPath);
        if (is_file($file)) {
            @unlink($file);
        }
        bms_media_delete_recorded_variants($media);
        bms_media_delete_generated_variants($publicPath);
    }
    $stmt = bms_db()->prepare('DELETE FROM ' . bms_table('media') . ' WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

function bms_media_delete(int $id): void
{
    bms_media_trash($id);
}

function bms_media_bulk_action(array $ids, string $action): array
{
    $action = strtolower(trim($action));
    $results = [
        'processed' => 0,
        'failed' => 0,
        'messages' => [],
    ];

    foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
        if ($id <= 0) {
            continue;
        }
        try {
            if ($action === 'trash') {
                bms_media_trash($id);
            } elseif ($action === 'restore') {
                bms_media_restore($id);
            } elseif ($action === 'delete_permanently') {
                bms_media_delete_permanently($id);
            } else {
                throw new RuntimeException('Unsupported bulk media action.');
            }
            $results['processed']++;
        } catch (Throwable $e) {
            $results['failed']++;
            $results['messages'][] = $e->getMessage();
        }
    }

    return $results;
}

function bms_media_bulk_action_label(string $action): string
{
    return match (strtolower(trim($action))) {
        'trash' => 'moved to trash',
        'restore' => 'restored',
        'delete_permanently' => 'permanently deleted',
        default => 'processed',
    };
}

function bms_media_public_url_for_item(array $media): string
{
    $publicPath = trim((string)($media['public_path'] ?? ''));
    if (str_starts_with($publicPath, 'media/')) {
        return bms_url_path($publicPath);
    }
    return bms_media_url($publicPath);
}


function bms_media_filename_lookup_key(string $filename): string
{
    $filename = basename(rawurldecode(str_replace('\\', '/', html_entity_decode(trim($filename), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    if ($filename === '') {
        return '';
    }

    $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    $name = (string)pathinfo($filename, PATHINFO_FILENAME);
    $slug = function_exists('bms_slugify') ? bms_slugify($name) : strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '-', $name));
    $slug = trim($slug, '-');
    if ($slug === '') {
        return '';
    }

    return $slug . ($extension !== '' ? '.' . $extension : '');
}

function bms_media_filename_lookup_stem(string $filename): string
{
    $key = bms_media_filename_lookup_key($filename);
    if ($key === '') {
        return '';
    }

    $extension = strtolower((string)pathinfo($key, PATHINFO_EXTENSION));
    $stem = (string)pathinfo($key, PATHINFO_FILENAME);
    if ($extension === '') {
        return $stem;
    }
    return $stem;
}

function bms_media_candidate_public_paths_from_url(string $url): array
{
    $url = trim(str_replace('\\', '/', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($url === '' || str_contains($url, "\0")) {
        return [];
    }

    $candidates = [];
    $path = (string)(parse_url($url, PHP_URL_PATH) ?: $url);
    $path = rawurldecode(str_replace('\\', '/', $path));
    $path = ltrim($path, '/');
    $basePath = function_exists('bms_base_path') ? trim(bms_base_path(), '/') : '';
    if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
        $path = substr($path, strlen($basePath) + 1);
    }

    if (str_starts_with($path, 'media/')) {
        $candidates[] = trim($path, '/');
    }

    $position = strpos('/' . $path, '/media/');
    if ($position !== false) {
        $candidates[] = trim(substr('/' . $path, $position + 1), '/');
    }

    $basename = basename($path);
    if ($basename !== '') {
        $normalized = bms_media_filename_lookup_key($basename);
        $extension = strtolower((string)pathinfo($normalized !== '' ? $normalized : $basename, PATHINFO_EXTENSION));
        if (preg_match('#(?:^|/)(\d{4})/(\d{2})/#', $path, $match) === 1) {
            $folder = $match[1] . '/' . $match[2] . '/';
            $candidates[] = 'media/' . $folder . $basename;
            if ($normalized !== '') {
                $candidates[] = 'media/' . $folder . $normalized;
            }
        }

        if ($normalized !== '') {
            $candidates[] = 'media/' . $normalized;
        }
    }

    $safe = [];
    foreach (array_unique($candidates) as $candidate) {
        $candidate = trim(str_replace('\\', '/', $candidate), '/');
        if ($candidate === '' || !str_starts_with($candidate, 'media/') || str_starts_with($candidate, 'media/_generated/')) {
            continue;
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $candidate) === 1 || preg_match('/[\r\n]/', $candidate) === 1) {
            continue;
        }
        $safe[] = $candidate;
    }
    return array_values(array_unique($safe));
}

function bms_media_find_existing_public_path_by_filename(string $filename): string
{
    $filename = basename(rawurldecode(str_replace('\\', '/', html_entity_decode(trim($filename), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    if ($filename === '') {
        return '';
    }

    $normalized = bms_media_filename_lookup_key($filename);
    $stem = bms_media_filename_lookup_stem($filename);
    $extension = strtolower((string)pathinfo($normalized !== '' ? $normalized : $filename, PATHINFO_EXTENSION));
    $directNames = array_values(array_unique(array_filter([$filename, $normalized])));

    if (function_exists('bms_is_installed') && bms_is_installed()) {
        try {
            $conditions = [];
            $params = [];
            foreach ($directNames as $index => $name) {
                $conditions[] = 'filename = :name' . $index;
                $conditions[] = 'original_filename = :name' . $index;
                $conditions[] = 'public_path LIKE :path' . $index;
                $params['name' . $index] = $name;
                $params['path' . $index] = '%/' . $name;
            }
            if ($stem !== '' && $extension !== '') {
                $conditions[] = 'public_path LIKE :stem_like';
                $conditions[] = 'filename LIKE :stem_name_like';
                $params['stem_like'] = '%/' . $stem . '%.'. $extension;
                $params['stem_name_like'] = $stem . '%.'. $extension;
            }

            if ($conditions) {
                $sql = 'SELECT public_path, filename, original_filename FROM ' . bms_table('media') . ' WHERE ' . implode(' OR ', $conditions) . ' ORDER BY id DESC LIMIT 50';
                $stmt = bms_db()->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $publicPath = trim((string)($row['public_path'] ?? ''));
                    if ($publicPath !== '' && str_starts_with($publicPath, 'media/') && is_file(bms_public_path($publicPath))) {
                        return $publicPath;
                    }
                }
            }
        } catch (Throwable $e) {
            // File-system fallback below keeps public rendering resilient when the DB cannot be queried.
        }
    }

    $patterns = [];
    foreach ($directNames as $name) {
        $patterns[] = bms_public_path('media/*/*/' . $name);
        $patterns[] = bms_public_path('media/*/' . $name);
        $patterns[] = bms_public_path('media/' . $name);
    }
    if ($stem !== '' && $extension !== '') {
        $patterns[] = bms_public_path('media/*/*/' . $stem . '*.' . $extension);
        $patterns[] = bms_public_path('media/*/' . $stem . '*.' . $extension);
        $patterns[] = bms_public_path('media/' . $stem . '*.' . $extension);
    }

    foreach ($patterns as $pattern) {
        $matches = glob($pattern, GLOB_NOSORT);
        if (!is_array($matches)) {
            continue;
        }
        foreach ($matches as $file) {
            if (!is_file($file)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file, strlen(rtrim(bms_public_path(), '/\\')) + 1));
            if (str_starts_with($relative, 'media/')) {
                return $relative;
            }
        }
    }

    return '';
}

function bms_media_resolve_existing_public_relative_from_url(string $url): string
{
    $candidates = bms_media_candidate_public_paths_from_url($url);
    foreach ($candidates as $candidate) {
        if (is_file(bms_public_path($candidate))) {
            return $candidate;
        }
    }

    $path = (string)(parse_url($url, PHP_URL_PATH) ?: $url);
    $basename = basename(rawurldecode(str_replace('\\', '/', $path)));
    if ($basename === '') {
        return '';
    }

    return bms_media_find_existing_public_path_by_filename($basename);
}



function bms_media_public_relative_from_url(string $url): string
{
    $url = trim(str_replace('\\', '/', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($url === '' || str_contains($url, "\0")) {
        return '';
    }

    $path = $url;
    $externalHost = false;
    if (preg_match('#^https?://#i', $url) === 1) {
        $site = function_exists('bms_site_url') ? rtrim(bms_site_url(''), '/') : '';
        if ($site !== '' && str_starts_with($url, $site . '/')) {
            $path = substr($url, strlen($site) + 1);
        } else {
            $externalHost = true;
            $pathOnly = parse_url($url, PHP_URL_PATH);
            $path = is_string($pathOnly) ? $pathOnly : '';
        }
    }

    $pathOnly = parse_url($path, PHP_URL_PATH);
    $path = is_string($pathOnly) ? $pathOnly : $path;
    $basePath = function_exists('bms_base_path') ? trim(bms_base_path(), '/') : '';
    $path = ltrim(rawurldecode($path), '/');
    if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
        $path = substr($path, strlen($basePath) + 1);
    }

    $path = trim($path, '/');
    if (!str_starts_with($path, 'media/')) {
        $position = strpos('/' . $path, '/media/');
        if ($position !== false) {
            $path = substr('/' . $path, $position + 1);
        }
    }

    if (!str_starts_with($path, 'media/') || str_starts_with($path, 'media/_generated/')) {
        return '';
    }
    if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1 || preg_match('/[\r\n]/', $path) === 1) {
        return '';
    }

    if ($externalHost && !is_file(bms_public_path($path))) {
        return '';
    }

    return $path;
}

function bms_media_image_dimensions_for_public_path(string $relativePath): array
{
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || !str_starts_with($relativePath, 'media/')) {
        return [];
    }

    $file = bms_public_path($relativePath);
    if (!is_file($file)) {
        return [];
    }

    $info = @getimagesize($file);
    if (!is_array($info) || empty($info[0]) || empty($info[1])) {
        return [];
    }

    return [
        'width' => (int)$info[0],
        'height' => (int)$info[1],
        'mime' => strtolower((string)($info['mime'] ?? '')),
        'file' => $file,
    ];
}

function bms_media_resize_capability(string $mime): array
{
    return match (strtolower($mime)) {
        'image/jpeg', 'image/pjpeg' => ['load' => 'imagecreatefromjpeg', 'save' => 'imagejpeg', 'quality' => 82],
        'image/png' => ['load' => 'imagecreatefrompng', 'save' => 'imagepng', 'quality' => 7],
        'image/webp' => ['load' => 'imagecreatefromwebp', 'save' => 'imagewebp', 'quality' => 82],
        default => [],
    };
}

function bms_media_generated_relative_path(string $relativePath, int $targetWidth): string
{
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if (str_starts_with($relativePath, 'media/')) {
        $relativePath = substr($relativePath, 6);
    }

    $directory = trim((string)pathinfo($relativePath, PATHINFO_DIRNAME), '.');
    $filename = (string)pathinfo($relativePath, PATHINFO_FILENAME);
    $extension = strtolower((string)pathinfo($relativePath, PATHINFO_EXTENSION));
    $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $filename) ?: 'image';
    $directory = $directory !== '' ? trim($directory, '/') . '/' : '';

    return 'media/_generated/' . $directory . $filename . '-' . $targetWidth . 'w.' . $extension;
}

function bms_media_generate_responsive_variant(string $relativePath, int $targetWidth, array $dimensions): string
{
    $sourceWidth = (int)($dimensions['width'] ?? 0);
    $sourceHeight = (int)($dimensions['height'] ?? 0);
    $sourceFile = (string)($dimensions['file'] ?? '');
    $mime = (string)($dimensions['mime'] ?? '');

    if ($sourceWidth < 1 || $sourceHeight < 1 || $targetWidth < 1 || $targetWidth >= $sourceWidth || !is_file($sourceFile)) {
        return '';
    }

    $generatedRelative = bms_media_generated_relative_path($relativePath, $targetWidth);
    $generatedFile = bms_public_path($generatedRelative);
    if (is_file($generatedFile)) {
        return $generatedRelative;
    }

    $generatedDir = dirname($generatedFile);
    if (!is_dir($generatedDir) && !mkdir($generatedDir, 0755, true)) {
        return '';
    }

    $targetHeight = max(1, (int)round($sourceHeight * ($targetWidth / $sourceWidth)));
    $saved = false;

    $capability = bms_media_resize_capability($mime);
    if ($capability && function_exists($capability['load']) && function_exists($capability['save']) && function_exists('imagecreatetruecolor')) {
        $source = @$capability['load']($sourceFile);
        if ($source) {
            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            if ($target) {
                if (in_array($mime, ['image/png', 'image/webp'], true)) {
                    imagealphablending($target, false);
                    imagesavealpha($target, true);
                    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
                    if ($transparent !== false) {
                        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
                    }
                }
                imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
                $saved = (bool)@$capability['save']($target, $generatedFile, (int)$capability['quality']);
                imagedestroy($target);
            }
            imagedestroy($source);
        }
    }

    if (!$saved && class_exists('Imagick')) {
        try {
            $image = new Imagick($sourceFile);
            if ($image->getNumberImages() > 1) {
                $image->setIteratorIndex(0);
            }
            $image->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1, true);
            if ($mime === 'image/jpeg' || $mime === 'image/pjpeg') {
                $image->setImageCompressionQuality(82);
                $image->setImageFormat('jpeg');
            } elseif ($mime === 'image/webp') {
                $image->setImageCompressionQuality(82);
                $image->setImageFormat('webp');
            } elseif ($mime === 'image/png') {
                $image->setImageFormat('png');
            }
            $saved = (bool)$image->writeImage($generatedFile);
            $image->clear();
            $image->destroy();
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


function bms_media_upload_derivative_widths(): array
{
    return [
        'small' => 480,
        'medium' => 800,
        'large' => 1200,
    ];
}

function bms_media_variant_metadata(string $relativePath): array
{
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || !str_starts_with($relativePath, 'media/')) {
        return [];
    }

    $file = bms_public_path($relativePath);
    if (!is_file($file)) {
        return [];
    }

    $dimensions = bms_media_image_dimensions_for_public_path($relativePath);
    if (!$dimensions) {
        return [];
    }

    return [
        'path' => $relativePath,
        'url' => bms_url_path($relativePath),
        'width' => (int)$dimensions['width'],
        'height' => (int)$dimensions['height'],
        'mime' => (string)($dimensions['mime'] ?? ''),
        'file_size' => (int)filesize($file),
    ];
}

function bms_media_generate_upload_derivatives(string $publicPath): array
{
    $relative = bms_media_public_relative_from_url($publicPath);
    if ($relative === '' || str_starts_with($relative, 'media/_generated/')) {
        return [];
    }

    $dimensions = bms_media_image_dimensions_for_public_path($relative);
    if (!$dimensions) {
        return [];
    }

    $mime = strtolower((string)($dimensions['mime'] ?? ''));
    if (!bms_media_resize_capability($mime)) {
        return [];
    }

    $sourceWidth = (int)($dimensions['width'] ?? 0);
    if ($sourceWidth < 1) {
        return [];
    }

    $variants = [];
    foreach (bms_media_upload_derivative_widths() as $label => $targetWidth) {
        $targetWidth = (int)$targetWidth;
        if ($targetWidth < 1 || $targetWidth >= $sourceWidth) {
            continue;
        }
        $generated = bms_media_generate_responsive_variant($relative, $targetWidth, $dimensions);
        if ($generated === '') {
            continue;
        }
        $metadata = bms_media_variant_metadata($generated);
        if ($metadata) {
            $variants[(string)$label] = $metadata;
        }
    }

    return $variants;
}


function bms_media_derivative_environment(string $mime = ''): array
{
    $mime = strtolower(trim($mime));
    $capability = $mime !== '' ? bms_media_resize_capability($mime) : [];
    $gdReady = false;
    if ($capability) {
        $gdReady = function_exists((string)$capability['load'])
            && function_exists((string)$capability['save'])
            && function_exists('imagecreatetruecolor')
            && function_exists('imagecopyresampled');
    }

    $generatedRoot = bms_public_path('media/_generated');
    $generatedParent = is_dir($generatedRoot) ? $generatedRoot : dirname($generatedRoot);
    $generatedWritable = is_dir($generatedRoot) ? is_writable($generatedRoot) : (is_dir($generatedParent) && is_writable($generatedParent));

    return [
        'mime' => $mime,
        'mime_supported' => (bool)$capability,
        'gd_available' => $gdReady,
        'imagick_available' => class_exists('Imagick'),
        'can_resize' => (bool)$capability && ($gdReady || class_exists('Imagick')),
        'generated_root' => $generatedRoot,
        'generated_root_exists' => is_dir($generatedRoot),
        'generated_root_writable' => $generatedWritable,
        'memory_limit' => (string)ini_get('memory_limit'),
        'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
        'post_max_size' => (string)ini_get('post_max_size'),
    ];
}

function bms_media_image_variant_status(array $media): array
{
    $publicPath = trim(str_replace('\\', '/', (string)($media['public_path'] ?? '')), '/');
    $mime = strtolower((string)($media['mime_type'] ?? ''));
    $isImage = str_starts_with($mime, 'image/');
    $relative = bms_media_public_relative_from_url($publicPath);
    $sourceFile = $relative !== '' ? bms_public_path($relative) : '';
    $sourceExists = $sourceFile !== '' && is_file($sourceFile);
    $dimensions = $sourceExists ? bms_media_image_dimensions_for_public_path($relative) : [];
    $sourceWidth = (int)($dimensions['width'] ?? ($media['width'] ?? 0));
    $sourceHeight = (int)($dimensions['height'] ?? ($media['height'] ?? 0));
    $environment = bms_media_derivative_environment((string)($dimensions['mime'] ?? $mime));
    $recorded = bms_media_decode_image_variants($media);
    $targets = [];

    foreach (bms_media_upload_derivative_widths() as $label => $targetWidth) {
        $targetWidth = (int)$targetWidth;
        $variant = is_array($recorded[$label] ?? null) ? $recorded[$label] : [];
        $recordedPath = trim(str_replace('\\', '/', (string)($variant['path'] ?? '')), '/');
        $expectedPath = $relative !== '' ? bms_media_generated_relative_path($relative, $targetWidth) : '';
        $path = $recordedPath !== '' ? $recordedPath : $expectedPath;
        $file = $path !== '' ? bms_public_path($path) : '';
        $exists = $file !== '' && is_file($file);
        $reason = '';

        if (!$isImage) {
            $reason = 'Not an image file.';
        } elseif (!$sourceExists) {
            $reason = 'Original file is missing on disk.';
        } elseif ($sourceWidth < 1 || $sourceHeight < 1) {
            $reason = 'Image dimensions are not available.';
        } elseif ($targetWidth >= $sourceWidth) {
            $reason = 'Skipped because the original is not wider than ' . $targetWidth . 'px.';
        } elseif (!$environment['mime_supported']) {
            $reason = 'Skipped because this image type is not supported for resizing.';
        } elseif (!$environment['can_resize']) {
            $reason = 'Skipped because GD or Imagick image resizing is not available for this image type.';
        } elseif (!$environment['generated_root_writable']) {
            $reason = 'Skipped because the generated media folder is not writable.';
        } elseif (!$exists) {
            $reason = 'Not generated yet or generation failed before the file was written.';
        }

        $targets[(string)$label] = [
            'label' => (string)$label,
            'target_width' => $targetWidth,
            'path' => $path,
            'exists' => $exists,
            'file_size' => $exists ? (int)filesize($file) : 0,
            'reason' => $reason,
        ];
    }

    $created = 0;
    foreach ($targets as $target) {
        if (!empty($target['exists'])) {
            $created++;
        }
    }

    $summary = 'Ready';
    if (!$isImage) {
        $summary = 'Not an image';
    } elseif (!$sourceExists) {
        $summary = 'Original missing';
    } elseif (!$environment['mime_supported']) {
        $summary = 'Unsupported image type';
    } elseif (!$environment['can_resize']) {
        $summary = 'No resize engine available';
    } elseif (!$environment['generated_root_writable']) {
        $summary = 'Generated folder not writable';
    } elseif ($sourceWidth > 0 && $created < 1) {
        $hasEligible = false;
        foreach (bms_media_upload_derivative_widths() as $targetWidth) {
            if ((int)$targetWidth < $sourceWidth) {
                $hasEligible = true;
                break;
            }
        }
        $summary = $hasEligible ? 'No variants generated' : 'Original smaller than derivative targets';
    } elseif ($created > 0) {
        $summary = $created . ' variant' . ($created === 1 ? '' : 's') . ' available';
    }

    return [
        'is_image' => $isImage,
        'public_path' => $publicPath,
        'relative_path' => $relative,
        'source_exists' => $sourceExists,
        'source_width' => $sourceWidth,
        'source_height' => $sourceHeight,
        'source_file_size' => $sourceExists ? (int)filesize($sourceFile) : 0,
        'mime' => (string)($dimensions['mime'] ?? $mime),
        'environment' => $environment,
        'targets' => $targets,
        'created_count' => $created,
        'summary' => $summary,
    ];
}

function bms_media_regenerate_image_variants(int $id): array
{
    $media = bms_media_find($id);
    if (!$media) {
        throw new RuntimeException('Media item not found.');
    }
    if (!bms_media_is_image_item($media)) {
        throw new RuntimeException('Only image media can generate optimized variants.');
    }

    $publicPath = trim(str_replace('\\', '/', (string)($media['public_path'] ?? '')), '/');
    $relative = bms_media_public_relative_from_url($publicPath);
    if ($relative === '') {
        throw new RuntimeException('The original image path could not be resolved.');
    }
    $file = bms_public_path($relative);
    if (!is_file($file)) {
        throw new RuntimeException('The original image file is missing on disk.');
    }

    bms_media_delete_recorded_variants($media);
    bms_media_delete_generated_variants($publicPath);

    $variants = bms_media_generate_upload_derivatives($publicPath);
    $dimensions = bms_media_image_dimensions_for_public_path($relative);
    $json = $variants ? json_encode($variants, JSON_UNESCAPED_SLASHES) : null;

    $stmt = bms_db()->prepare('UPDATE ' . bms_table('media') . ' SET file_size = :file_size, width = :width, height = :height, mime_type = :mime_type, file_hash = :file_hash, image_variants_json = :image_variants_json, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'file_size' => (int)filesize($file),
        'width' => !empty($dimensions['width']) ? (int)$dimensions['width'] : null,
        'height' => !empty($dimensions['height']) ? (int)$dimensions['height'] : null,
        'mime_type' => (string)($dimensions['mime'] ?? ($media['mime_type'] ?? '')),
        'file_hash' => bms_media_file_hash($file),
        'image_variants_json' => $json,
        'id' => $id,
    ]);

    $updated = bms_media_find($id) ?? $media;
    $report = bms_media_image_variant_status($updated);
    $report['generated_now'] = count($variants);
    return $report;
}


function bms_media_regeneration_batch_size(): int
{
    return 5;
}

function bms_media_regeneration_mode(string $mode): string
{
    $mode = strtolower(trim($mode));
    return in_array($mode, ['missing', 'all'], true) ? $mode : 'missing';
}

function bms_media_regeneration_candidate_sql(string $mode = 'missing', int $afterId = 0, int $limit = 5): array
{
    $mode = bms_media_regeneration_mode($mode);
    $afterId = max(0, $afterId);
    $limit = max(1, min(25, $limit));

    $where = [
        'trashed_at IS NULL',
        'id > :after_id',
        'mime_type LIKE :image_mime',
        'public_path LIKE :media_prefix',
        'public_path NOT LIKE :generated_prefix',
    ];
    $params = [
        'after_id' => $afterId,
        'image_mime' => 'image/%',
        'media_prefix' => 'media/%',
        'generated_prefix' => 'media/_generated/%',
    ];

    if ($mode === 'missing') {
        $where[] = "(image_variants_json IS NULL OR image_variants_json = '' OR image_variants_json = '[]')";
    }

    $sql = 'SELECT * FROM ' . bms_table('media') . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC LIMIT ' . $limit;
    return [$sql, $params];
}

function bms_media_regeneration_candidates(string $mode = 'missing', int $afterId = 0, int $limit = 5): array
{
    if (!bms_is_installed()) {
        return [];
    }
    [$sql, $params] = bms_media_regeneration_candidate_sql($mode, $afterId, $limit);
    try {
        $stmt = bms_db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function bms_media_regeneration_count(string $mode = 'missing'): int
{
    if (!bms_is_installed()) {
        return 0;
    }
    $mode = bms_media_regeneration_mode($mode);
    $where = [
        'trashed_at IS NULL',
        'mime_type LIKE :image_mime',
        'public_path LIKE :media_prefix',
        'public_path NOT LIKE :generated_prefix',
    ];
    $params = [
        'image_mime' => 'image/%',
        'media_prefix' => 'media/%',
        'generated_prefix' => 'media/_generated/%',
    ];
    if ($mode === 'missing') {
        $where[] = "(image_variants_json IS NULL OR image_variants_json = '' OR image_variants_json = '[]')";
    }

    try {
        $stmt = bms_db()->prepare('SELECT COUNT(*) FROM ' . bms_table('media') . ' WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function bms_media_regeneration_summary(): array
{
    if (!bms_is_installed()) {
        return ['total_images' => 0, 'with_variants' => 0, 'missing_variants' => 0, 'all_candidates' => 0];
    }

    try {
        $base = 'FROM ' . bms_table('media') . " WHERE trashed_at IS NULL AND mime_type LIKE 'image/%' AND public_path LIKE 'media/%' AND public_path NOT LIKE 'media/_generated/%'";
        $total = (int)bms_db()->query('SELECT COUNT(*) ' . $base)->fetchColumn();
        $with = (int)bms_db()->query("SELECT COUNT(*) " . $base . " AND image_variants_json IS NOT NULL AND image_variants_json <> '' AND image_variants_json <> '[]'")->fetchColumn();
        return [
            'total_images' => $total,
            'with_variants' => $with,
            'missing_variants' => max(0, $total - $with),
            'all_candidates' => $total,
        ];
    } catch (Throwable $e) {
        return ['total_images' => 0, 'with_variants' => 0, 'missing_variants' => 0, 'all_candidates' => 0];
    }
}

function bms_media_regeneration_run_batch(string $mode = 'missing', int $afterId = 0, int $limit = 5): array
{
    $mode = bms_media_regeneration_mode($mode);
    $limit = max(1, min(25, $limit));
    $items = bms_media_regeneration_candidates($mode, max(0, $afterId), $limit);
    $result = [
        'mode' => $mode,
        'limit' => $limit,
        'after_id' => max(0, $afterId),
        'last_id' => max(0, $afterId),
        'processed' => 0,
        'generated' => 0,
        'skipped' => 0,
        'failed' => 0,
        'items' => [],
        'has_more' => false,
        'remaining_estimate' => 0,
    ];

    foreach ($items as $media) {
        $id = (int)($media['id'] ?? 0);
        if ($id > 0) {
            $result['last_id'] = max((int)$result['last_id'], $id);
        }
        $name = (string)($media['original_filename'] ?? $media['filename'] ?? ('Media #' . $id));
        try {
            $report = bms_media_regenerate_image_variants($id);
            $generated = (int)($report['generated_now'] ?? 0);
            $created = (int)($report['created_count'] ?? 0);
            $summary = bms_media_variant_status_text($report);
            $result['processed']++;
            if ($generated > 0 || $created > 0) {
                $result['generated']++;
            } else {
                $result['skipped']++;
            }
            $result['items'][] = [
                'id' => $id,
                'name' => $name,
                'status' => ($generated > 0 || $created > 0) ? 'generated' : 'skipped',
                'message' => $summary,
            ];
        } catch (Throwable $e) {
            $result['processed']++;
            $result['failed']++;
            $result['items'][] = [
                'id' => $id,
                'name' => $name,
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    $next = bms_media_regeneration_candidates($mode, (int)$result['last_id'], 1);
    $result['has_more'] = !empty($next);
    $result['remaining_estimate'] = bms_media_regeneration_count($mode);
    return $result;
}

function bms_media_variant_status_text(array $report): string
{
    $summary = trim((string)($report['summary'] ?? ''));
    $created = (int)($report['created_count'] ?? 0);
    if ($summary !== '') {
        return $summary;
    }
    return $created > 0 ? ($created . ' variant' . ($created === 1 ? '' : 's') . ' available') : 'No variants available';
}

function bms_media_decode_image_variants(array $media): array
{
    $raw = trim((string)($media['image_variants_json'] ?? ''));
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function bms_media_delete_recorded_variants(array $media): void
{
    foreach (bms_media_decode_image_variants($media) as $variant) {
        if (!is_array($variant)) {
            continue;
        }
        $path = trim(str_replace('\\', '/', (string)($variant['path'] ?? '')), '/');
        if ($path === '' || !str_starts_with($path, 'media/_generated/') || str_contains($path, '..')) {
            continue;
        }
        $file = bms_public_path($path);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

function bms_media_responsive_image_data(string $url, string $alt = '', array $options = []): array
{
    $url = trim($url);
    if (function_exists('bms_media_resolve_existing_public_relative_from_url')) {
        $resolved = bms_media_resolve_existing_public_relative_from_url($url);
        if ($resolved !== '') {
            $url = bms_url_path($resolved);
        }
    }

    $loading = (string)($options['loading'] ?? 'lazy');
    $loading = in_array($loading, ['lazy', 'eager'], true) ? $loading : 'lazy';
    $decoding = (string)($options['decoding'] ?? 'async');
    $decoding = in_array($decoding, ['async', 'sync', 'auto'], true) ? $decoding : 'async';
    $sizes = trim((string)($options['sizes'] ?? '(max-width: 720px) calc(100vw - 2rem), min(100vw - 4rem, 900px)'));

    $data = [
        'src' => $url,
        'alt' => $alt,
        'width' => 0,
        'height' => 0,
        'srcset' => '',
        'sizes' => $sizes,
        'loading' => $loading,
        'decoding' => $decoding,
        'fetchpriority' => (string)($options['fetchpriority'] ?? ''),
    ];

    $relative = bms_media_public_relative_from_url($url);
    if ($relative === '') {
        return $data;
    }

    $data['src'] = bms_url_path($relative);

    $dimensions = bms_media_image_dimensions_for_public_path($relative);
    if (!$dimensions) {
        return $data;
    }

    $sourceWidth = (int)$dimensions['width'];
    $sourceHeight = (int)$dimensions['height'];
    $data['width'] = $sourceWidth;
    $data['height'] = $sourceHeight;

    $media = bms_media_find_by_public_path($relative);
    if (!$media) {
        return $data;
    }

    $recorded = bms_media_decode_image_variants($media);
    if (!$recorded) {
        return $data;
    }

    $candidates = [];
    foreach ($recorded as $variant) {
        if (!is_array($variant)) {
            continue;
        }
        $path = trim(str_replace('\\', '/', (string)($variant['path'] ?? '')), '/');
        $width = (int)($variant['width'] ?? 0);
        if ($path === '' || $width < 120 || !str_starts_with($path, 'media/_generated/')) {
            continue;
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1 || preg_match('/[\r\n]/', $path) === 1) {
            continue;
        }
        if ($sourceWidth > 0 && $width >= $sourceWidth) {
            continue;
        }
        $file = bms_public_path($path);
        if (!is_file($file)) {
            continue;
        }
        $variantDimensions = bms_media_image_dimensions_for_public_path($path);
        if (!$variantDimensions || (int)$variantDimensions['width'] !== $width) {
            continue;
        }
        $candidates[$width] = bms_url_path($path) . ' ' . $width . 'w';
    }

    if (!$candidates) {
        return $data;
    }

    ksort($candidates, SORT_NUMERIC);
    if ($sourceWidth > 0) {
        $candidates[$sourceWidth] = bms_url_path($relative) . ' ' . $sourceWidth . 'w';
    }

    $data['srcset'] = implode(', ', array_values(array_unique($candidates)));
    return $data;
}

function bms_media_image_attributes(string $url, string $alt = '', array $options = []): string
{
    // Display-first rendering: the original image remains the fallback src.
    // Responsive output is added only when Bonumark has recorded derivative
    // metadata and the derivative files still exist on disk. No derivative URL is
    // guessed or generated during public rendering. Width and height are only
    // output when Bonumark can verify them or when a caller supplies fixed display
    // dimensions, such as avatars.
    $rawUrl = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $src = '';
    $resolvedRelative = '';

    if (function_exists('bms_media_resolve_existing_public_relative_from_url') && function_exists('bms_url_path')) {
        $resolved = bms_media_resolve_existing_public_relative_from_url($rawUrl);
        if ($resolved !== '') {
            $resolvedRelative = $resolved;
            $src = bms_url_path($resolved);
        }
    }

    if ($src === '' && function_exists('bms_media_public_relative_from_url') && function_exists('bms_url_path')) {
        $relative = bms_media_public_relative_from_url($rawUrl);
        if ($relative !== '') {
            $resolvedRelative = $relative;
            $src = bms_url_path($relative);
        }
    }

    if ($src === '') {
        $clean = function_exists('bms_clean_url') ? bms_clean_url($rawUrl) : $rawUrl;
        $src = $clean !== '#' ? $clean : '';
    }

    if ($src === '') {
        $src = $rawUrl;
    }

    $loading = (string)($options['loading'] ?? 'lazy');
    $loading = in_array($loading, ['lazy', 'eager'], true) ? $loading : 'lazy';
    $decoding = (string)($options['decoding'] ?? 'async');
    $decoding = in_array($decoding, ['async', 'sync', 'auto'], true) ? $decoding : 'async';

    $attributes = [
        'src' => $src,
        'alt' => $alt,
        'loading' => $loading,
        'decoding' => $decoding,
    ];

    $explicitWidth = (int)($options['width'] ?? 0);
    $explicitHeight = (int)($options['height'] ?? 0);
    if ($explicitWidth > 0 && $explicitHeight > 0) {
        $attributes['width'] = (string)$explicitWidth;
        $attributes['height'] = (string)$explicitHeight;
    } elseif ($resolvedRelative !== '' && function_exists('bms_media_image_dimensions_for_public_path')) {
        $dimensions = bms_media_image_dimensions_for_public_path($resolvedRelative);
        if (!empty($dimensions['width']) && !empty($dimensions['height'])) {
            $attributes['width'] = (string)(int)$dimensions['width'];
            $attributes['height'] = (string)(int)$dimensions['height'];
        }
    }

    $sizes = trim((string)($options['sizes'] ?? ''));
    $responsiveAllowed = (($options['responsive'] ?? true) !== false)
        && $sizes !== ''
        && $explicitWidth <= 0
        && $explicitHeight <= 0
        && $resolvedRelative !== ''
        && !str_starts_with($resolvedRelative, 'media/_generated/');
    if ($responsiveAllowed && function_exists('bms_media_responsive_image_data')) {
        $responsive = bms_media_responsive_image_data($src, $alt, $options);
        $srcset = trim((string)($responsive['srcset'] ?? ''));
        if ($srcset !== '') {
            $attributes['srcset'] = $srcset;
            $attributes['sizes'] = trim((string)($responsive['sizes'] ?? $sizes));
        }
    }

    $fetchPriority = trim((string)($options['fetchpriority'] ?? ''));
    if (in_array($fetchPriority, ['high', 'low', 'auto'], true)) {
        $attributes['fetchpriority'] = $fetchPriority;
    }

    $class = trim((string)($options['class'] ?? ''));
    if ($class !== '') {
        $attributes['class'] = $class;
    }

    $html = [];
    foreach ($attributes as $name => $value) {
        $html[] = $name . '="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"';
    }
    return implode(' ', $html);
}

function bms_media_delete_generated_variants(string $publicPath): void
{
    $relative = bms_media_public_relative_from_url($publicPath);
    if ($relative === '') {
        return;
    }

    $pattern = bms_public_path(bms_media_generated_relative_path($relative, 999999));
    $pattern = preg_replace('/-999999w\.[^.]+$/', '-*w.*', $pattern) ?? '';
    if ($pattern === '') {
        return;
    }

    foreach (glob($pattern) ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}


function bms_media_mime_type(array $media): string
{
    return strtolower(trim((string)($media['mime_type'] ?? '')));
}

function bms_media_is_image_item(array $media): bool
{
    return str_starts_with(bms_media_mime_type($media), 'image/');
}

function bms_media_kind_label(array $media): string
{
    $mime = bms_media_mime_type($media);
    if (str_starts_with($mime, 'image/')) {
        return 'Image';
    }
    if (str_starts_with($mime, 'audio/')) {
        return 'Audio';
    }
    if (str_starts_with($mime, 'video/')) {
        return 'Video';
    }
    if ($mime === 'application/pdf') {
        return 'PDF';
    }
    if (str_contains($mime, 'wordprocessingml') || $mime === 'application/msword') {
        return 'Document';
    }
    if ($mime === 'text/plain') {
        return 'Text file';
    }
    return 'File';
}

function bms_media_file_label(array $media): string
{
    return trim((string)($media['original_filename'] ?? $media['filename'] ?? 'Media file'));
}


function bms_media_markdown(array $media): string
{
    $label = trim((string)($media['alt_text'] ?? ''));
    if ($label === '') {
        $label = bms_media_file_label($media);
    }
    $label = str_replace(["\n", "\r", '[', ']'], [' ', ' ', '', ''], $label);
    $url = bms_media_public_url_for_item($media);

    $mime = bms_media_mime_type($media);
    if (bms_media_is_image_item($media)) {
        return '![' . $label . '](' . $url . ')';
    }
    if (str_starts_with($mime, 'audio/')) {
        return '[Audio: ' . $label . '](' . $url . ')';
    }
    if (str_starts_with($mime, 'video/')) {
        return '[Video: ' . $label . '](' . $url . ')';
    }

    return '[' . $label . '](' . $url . ')';
}

function bms_media_count(string $status = 'active'): int
{
    try {
        $status = bms_media_normalize_status($status);
        $sql = 'SELECT COUNT(*) FROM ' . bms_table('media');
        $params = [];
        $where = [];
        [$ownerWhere, $ownerParams] = bms_media_user_scope_sql();
        if ($ownerWhere !== '') {
            $where[] = $ownerWhere;
            $params = $ownerParams;
        }
        if ($status === 'trash') {
            $where[] = 'trashed_at IS NOT NULL';
        } elseif ($status === 'active') {
            $where[] = 'trashed_at IS NULL';
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $stmt = bms_db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function bms_media_usage_references(array $media, int $limit = 20): array
{
    $publicPath = trim((string)($media['public_path'] ?? ''));
    if ($publicPath === '') {
        return [];
    }

    $needles = array_values(array_unique(array_filter([
        $publicPath,
        '/' . ltrim($publicPath, '/'),
        function_exists('bms_url_path') ? bms_url_path($publicPath) : '',
        function_exists('bms_media_public_url_for_item') ? bms_media_public_url_for_item($media) : '',
        basename($publicPath),
    ])));

    $references = [];

    if (function_exists('bms_db') && function_exists('bms_table')) {
        try {
            $where = [];
            $params = [];
            foreach ($needles as $index => $needle) {
                if ($needle === '') {
                    continue;
                }
                $param = ':needle_' . $index;
                $where[] = '(content_body LIKE ' . $param . ' OR content_front_matter LIKE ' . $param . ' OR description LIKE ' . $param . ')';
                $params[$param] = '%' . addcslashes($needle, "\\%_") . '%';
            }
            if ($where) {
                $sql = 'SELECT id, title, slug, status, post_type FROM ' . bms_table('posts') . ' WHERE ' . implode(' OR ', $where) . ' ORDER BY updated_at DESC LIMIT ' . max(1, $limit);
                $stmt = bms_db()->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll() ?: [] as $row) {
                    $postType = (string)($row['post_type'] ?? 'stream') === 'page' ? 'Page' : 'Stream Post';
                    $status = (string)($row['status'] ?? 'draft') === 'published' ? 'Published' : 'Draft';
                    $slug = (string)($row['slug'] ?? '');
                    $path = $slug !== ''
                        ? (($postType === 'Page') ? bms_url_path('pages/' . $slug . '/') : bms_url_path('stream/' . $slug . '/'))
                        : 'database record #' . (int)($row['id'] ?? 0);
                    $references[] = [
                        'label' => $status . ' ' . $postType,
                        'file' => (string)($row['title'] ?? $slug ?: 'Untitled'),
                        'path' => $path,
                    ];
                    if (count($references) >= $limit) {
                        return $references;
                    }
                }
            }
        } catch (Throwable $e) {
            // Database content is authoritative; explicit Markdown import tooling handles old files.
        }
    }

    $roots = [];
    if (function_exists('bms_content_path')) {
        $roots = [
            'Draft Markdown import' => bms_content_path('drafts'),
            'Published Markdown import' => bms_content_path('published'),
            'Page draft Markdown import' => bms_content_path('pages/drafts'),
            'Published page Markdown import' => bms_content_path('pages/published'),
        ];
    }

    foreach ($roots as $label => $root) {
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $contents = (string)@file_get_contents($file->getPathname());
            if ($contents === '') {
                continue;
            }
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($contents, $needle)) {
                    $references[] = [
                        'label' => $label,
                        'file' => basename($file->getPathname()),
                        'path' => $file->getPathname(),
                    ];
                    break;
                }
            }
            if (count($references) >= $limit) {
                return $references;
            }
        }
    }

    return $references;
}

function bms_media_usage_summary(array $media): string
{
    $references = bms_media_usage_references($media, 5);
    $count = count($references);
    if ($count === 0) {
        return 'No database content references found.';
    }
    if ($count === 1) {
        return 'Referenced in 1 content record.';
    }
    return 'Referenced in at least ' . $count . ' content records.';
}

function bms_media_human_size(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}
