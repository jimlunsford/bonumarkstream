<?php
/**
 * Content portability: the Bonumark export format and how Import restores it.
 *
 * Export and Import are content-only. They carry Stream Posts, Pages, and the
 * media library. They never carry comments, accounts, settings, analytics,
 * Profiles, the Local Places directory, or ActivityPub state. Server backups
 * of the database and files are the full recovery path.
 */
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/places.php';

/** Version of the Markdown export layout written by this release. */
function bms_portability_format_version(): int
{
    return 2;
}

function bms_portability_media_manifest_entry(): string
{
    return 'markdown/media.json';
}

/** @return list<string> */
function bms_portability_location_keys(): array
{
    return ['location_place_id', 'location_name', 'location_category', 'location_area', 'location_locality', 'location_region', 'location_country', 'location_display_mode'];
}

/** @return list<string> */
function bms_portability_link_preview_keys(): array
{
    return ['link_preview_url', 'link_preview_title', 'link_preview_description', 'link_preview_image', 'link_preview_site_name'];
}

/* -------------------------------------------------------------------------
 * Time values
 * ---------------------------------------------------------------------- */

/** A stored UTC DATETIME as an ISO 8601 UTC instant, or '' when empty. */
function bms_portability_iso_from_utc(string $value): string
{
    $value = trim($value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '';
    }
    try {
        return (new DateTimeImmutable($value, bms_utc_timezone()))->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Read an exported instant as a UTC 'Y-m-d H:i:s' value, or '' when it is not
 * a valid time. A value without an offset is read as UTC, as exports write it.
 */
function bms_portability_utc_from_value(string $value): string
{
    $value = trim($value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '';
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?$/', $value, $match) !== 1) {
        return '';
    }
    try {
        $date = new DateTimeImmutable($value, bms_utc_timezone());
    } catch (Throwable $e) {
        return '';
    }
    // Reject dates PHP would roll over, such as February 30.
    if ($date->format('Y-m-d') !== $match[1]) {
        return '';
    }
    return $date->setTimezone(bms_utc_timezone())->format('Y-m-d H:i:s');
}

/**
 * The instant a post's stored published_at stands for, as ISO 8601 UTC.
 * Values from before the v0.5.23 storage change are site-local clock values.
 */
function bms_portability_published_instant(array $page): string
{
    $raw = trim((string)($page['published_at'] ?? ''));
    if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
        return '';
    }
    $timezone = bms_stream_published_at_is_utc($page) ? bms_utc_timezone() : bms_site_timezone();
    try {
        return (new DateTimeImmutable($raw, $timezone))->setTimezone(bms_utc_timezone())->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable $e) {
        return '';
    }
}

/** The stored publication cutover, read past the per-request setting cache. */
function bms_portability_current_cutover(): string
{
    try {
        $stmt = bms_db()->prepare('SELECT setting_value FROM ' . bms_table('settings') . ' WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute(['setting_key' => 'stream_published_at_utc_cutover']);
        $value = trim((string)($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        $value = '';
    }
    $value = bms_normalize_utc_datetime($value);
    return $value !== '' ? $value : '1970-01-01 00:00:00';
}

/**
 * Let this site read imported publication times as UTC.
 *
 * Bonumark reads a published_at value earlier than the stored cutover as a
 * site-local clock value from before v0.5.23. A fresh install records its
 * install time as the cutover, so a historical imported time would be read in
 * the wrong zone. When no stored value lies between the earliest imported time
 * and the cutover, moving the cutover back to that time changes the reading of
 * nothing already stored. Returns the cutover in effect afterwards.
 *
 * @param list<string> $instants UTC 'Y-m-d H:i:s' values
 */
function bms_portability_prepare_publication_cutover(array $instants): string
{
    $cutover = bms_portability_current_cutover();
    $earliest = '';
    foreach ($instants as $instant) {
        $instant = bms_normalize_utc_datetime((string)$instant);
        if ($instant !== '' && $instant < $cutover && ($earliest === '' || $instant < $earliest)) {
            $earliest = $instant;
        }
    }
    if ($earliest === '' || $earliest === '1970-01-01 00:00:00') {
        return $cutover;
    }

    $stmt = bms_db()->prepare('SELECT COUNT(*) FROM ' . bms_table('posts') . ' WHERE published_at IS NOT NULL AND published_at >= :earliest AND published_at < :cutover');
    $stmt->execute(['earliest' => $earliest, 'cutover' => $cutover]);
    if ((int)$stmt->fetchColumn() > 0) {
        return $cutover;
    }

    bms_set_setting('stream_published_at_utc_cutover', $earliest);
    bms_set_setting('stream_published_at_utc_cutover_source', 'content_import');
    return $earliest;
}

/**
 * The published_at value to store so this site reads it as the UTC instant
 * $utc. Before the cutover the site reads site-local clock values, so the
 * instant is stored that way when that reads back exactly.
 *
 * @return array{value:string,exact:bool}
 */
function bms_portability_published_at_storage(string $utc, string $cutover, ?DateTimeZone $timezone = null): array
{
    if ($utc >= $cutover) {
        return ['value' => $utc, 'exact' => true];
    }
    $timezone = $timezone ?? bms_site_timezone();
    try {
        $instant = new DateTimeImmutable($utc, bms_utc_timezone());
        $local = $instant->setTimezone($timezone)->format('Y-m-d H:i:s');
        if ($local < $cutover && (new DateTimeImmutable($local, $timezone))->getTimestamp() === $instant->getTimestamp()) {
            return ['value' => $local, 'exact' => true];
        }
    } catch (Throwable $e) {
        // Fall through to the UTC value.
    }
    return ['value' => $utc, 'exact' => false];
}

/* -------------------------------------------------------------------------
 * Export
 * ---------------------------------------------------------------------- */

/**
 * The Markdown document for one content record in an export. It is the
 * stored document plus the publication fields that live in database columns:
 * the publication instant and the pin state.
 */
function bms_portability_export_document(array $page): string
{
    $exportPage = $page;
    // Stored values, not the values derived when the record is read: the
    // stored creation time (reading a published record replaces it with the
    // publication time) and the tags kept in the term tables.
    $frontMatter = is_array($page['front_matter'] ?? null) ? $page['front_matter'] : [];
    $exportPage['stream_created_at'] = (string)($frontMatter['stream_created_at'] ?? '');
    $postId = (int)($page['post_id'] ?? 0);
    if ((string)($page['content_type'] ?? $page['post_type'] ?? 'stream') === 'stream' && empty($page['tags']) && $postId > 0) {
        $exportPage['tags'] = bms_portability_post_tags($postId);
    }
    $raw = bms_database_content_raw($exportPage);
    $extra = [];
    $publishedAt = bms_portability_published_instant($page);
    if ($publishedAt !== '') {
        $extra[] = 'published_at: ' . bms_front_matter_quote($publishedAt);
    }
    if (bms_is_pinned_stream_post($page)) {
        $extra[] = 'pinned: "1"';
        $pinnedAt = bms_portability_iso_from_utc((string)($page['pinned_at'] ?? ''));
        if ($pinnedAt !== '') {
            $extra[] = 'pinned_at: ' . bms_front_matter_quote($pinnedAt);
        }
    }
    if ($extra === []) {
        return $raw;
    }
    $closing = strpos($raw, "\n---\n");
    if ($closing === false) {
        return $raw;
    }
    return substr($raw, 0, $closing) . "\n" . implode("\n", $extra) . substr($raw, $closing);
}

/** @return list<string> the tags of a Stream Post, in the order they were saved */
function bms_portability_post_tags(int $postId): array
{
    try {
        $stmt = bms_db()->prepare('SELECT t.name FROM ' . bms_table('post_terms') . ' pt INNER JOIN ' . bms_table('terms') . " t ON t.id = pt.term_id WHERE pt.post_id = :post_id AND t.term_type = 'tag' ORDER BY pt.term_id ASC");
        $stmt->execute(['post_id' => $postId]);
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Normalize a public media path ("media/..."). Every segment must start with a
 * letter, digit, or underscore, so dot segments and dotfiles are refused.
 */
function bms_portability_clean_media_path(string $path): string
{
    $path = ltrim(str_replace('\\', '/', trim($path)), '/');
    if (strlen($path) > 255 || preg_match('#^media(?:/[A-Za-z0-9_][A-Za-z0-9._-]*)+$#', $path) !== 1) {
        return '';
    }
    return $path;
}

/**
 * What a file under media/ is: a media library file, a generated derivative,
 * Profile or account media, or something else.
 */
function bms_portability_media_category(string $publicPath): string
{
    if (str_starts_with($publicPath, 'media/_generated/')) {
        return 'derivative';
    }
    foreach (['media/avatars/', 'media/profile-covers/', 'media/profile-photos/'] as $prefix) {
        if (str_starts_with($publicPath, $prefix)) {
            return 'profile';
        }
    }
    $extension = strtolower(pathinfo($publicPath, PATHINFO_EXTENSION));
    return isset(bms_allowed_media_extensions()[$extension]) ? 'library' : 'other';
}

/** The media library records an export lists, with their derivatives. */
function bms_portability_media_manifest(): array
{
    try {
        $rows = bms_db()->query('SELECT * FROM ' . bms_table('media') . ' ORDER BY id ASC')->fetchAll() ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    $items = [];
    foreach ($rows as $row) {
        $path = bms_portability_clean_media_path((string)($row['public_path'] ?? ''));
        if ($path === '' || bms_portability_media_category($path) !== 'library') {
            continue;
        }
        $variants = [];
        foreach (bms_media_decode_image_variants($row) as $label => $variant) {
            $variantPath = is_array($variant) ? bms_portability_clean_media_path((string)($variant['path'] ?? '')) : '';
            if ($variantPath === '' || bms_portability_media_category($variantPath) !== 'derivative') {
                continue;
            }
            $variants[] = ['label' => (string)$label, 'path' => $variantPath];
        }
        $items[] = [
            'public_path' => $path,
            'original_filename' => (string)($row['original_filename'] ?? ''),
            'mime_type' => (string)($row['mime_type'] ?? ''),
            'file_size' => (int)($row['file_size'] ?? 0),
            'width' => isset($row['width']) ? (int)$row['width'] : null,
            'height' => isset($row['height']) ? (int)$row['height'] : null,
            'alt_text' => (string)($row['alt_text'] ?? ''),
            'caption' => (string)($row['caption'] ?? ''),
            'file_hash' => (string)($row['file_hash'] ?? ''),
            'privacy_status' => (string)($row['privacy_status'] ?? ''),
            'privacy_note' => (string)($row['privacy_note'] ?? ''),
            'created_at' => bms_portability_iso_from_utc((string)($row['created_at'] ?? '')),
            'updated_at' => bms_portability_iso_from_utc((string)($row['updated_at'] ?? '')),
            'trashed_at' => bms_portability_iso_from_utc((string)($row['trashed_at'] ?? '')),
            'variants' => $variants,
        ];
    }

    return [
        'format' => 'bonumark-media-library',
        'version' => 1,
        'generated_at' => gmdate('c'),
        'items' => $items,
    ];
}

/* -------------------------------------------------------------------------
 * Import: media references
 * ---------------------------------------------------------------------- */

/** The media path ("media/...") a link or image URL points to, or ''. */
function bms_portability_media_path_from_url(string $url): string
{
    $url = trim($url, " \t\n\r\0\x0B\"'");
    if ($url === '' || str_starts_with($url, 'bms-import-media://')) {
        return '';
    }
    $path = (string)(parse_url($url, PHP_URL_PATH) ?: $url);
    $path = ltrim(rawurldecode(str_replace('\\', '/', $path)), '/');
    if (!str_starts_with($path, 'media/')) {
        $position = strpos($path, '/media/');
        if ($position === false) {
            return '';
        }
        $path = substr($path, $position + 1);
    }
    return bms_portability_clean_media_path($path);
}

/**
 * Media paths a post uses: Markdown links and images in the body, plus the
 * featured media and gallery.
 *
 * @param list<string> $gallery
 * @return array<string,string> media path => alt text of its first image use
 */
function bms_portability_referenced_media(string $body, array $gallery): array
{
    $paths = [];
    if (preg_match_all('/(!?)\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $body, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $path = bms_portability_media_path_from_url((string)$match[3]);
            if ($path !== '' && !isset($paths[$path])) {
                $paths[$path] = (string)$match[1] === '!' ? trim((string)$match[2]) : '';
            }
        }
    }
    foreach ($gallery as $item) {
        $path = bms_portability_media_path_from_url((string)$item);
        if ($path !== '' && !isset($paths[$path])) {
            $paths[$path] = '';
        }
    }
    return $paths;
}

/**
 * Point a body's links and images at the restored media. Each reference to a
 * restored file becomes this site's URL for it, which is unchanged when the
 * file kept its path and the site keeps the same base path.
 *
 * @param array<string,string> $restored original media path => restored media path
 */
function bms_portability_rewrite_media_references(string $body, array $restored): string
{
    if ($body === '' || $restored === []) {
        return $body;
    }
    return preg_replace_callback('/(!?)\[([^\]]*)\]\(([^)\s]+)((?:\s+"[^"]*")?)\)/', static function (array $match) use ($restored): string {
        $path = bms_portability_media_path_from_url((string)$match[3]);
        if ($path === '' || !isset($restored[$path])) {
            return (string)$match[0];
        }
        $url = bms_media_url($restored[$path]);
        return $url === (string)$match[3] ? (string)$match[0] : $match[1] . '[' . $match[2] . '](' . $url . $match[4] . ')';
    }, $body) ?? $body;
}

/**
 * A gallery or featured media value for the restored media. A media path
 * ("media/...") stays a media path; a URL becomes this site's URL for the file.
 *
 * @param array<string,string> $restored original media path => restored media path
 */
function bms_portability_rewrite_media_value(string $value, array $restored): string
{
    $path = bms_portability_media_path_from_url($value);
    if ($path === '' || !isset($restored[$path])) {
        return $value;
    }
    $target = $restored[$path];
    $trimmed = trim($value);
    if (str_starts_with($trimmed, 'media/')) {
        return $target;
    }
    $url = bms_media_url($target);
    return $trimmed === $url ? $value : $url;
}

/* -------------------------------------------------------------------------
 * Import: media library restore
 * ---------------------------------------------------------------------- */

function bms_portability_unique_media_path(string $path): string
{
    $directory = trim(str_replace('\\', '/', dirname($path)), '/');
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = $directory . '/' . bms_media_random_basename() . '.' . $extension;
        if (!file_exists(bms_public_path($candidate)) && bms_media_find_by_public_path($candidate) === null) {
            return $candidate;
        }
    }
    throw new RuntimeException('Could not choose a free media file name.');
}

/** Create the directory for a restored media file inside the media folder. */
function bms_portability_prepare_media_directory(string $destination): void
{
    $directory = dirname($destination);
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        throw new RuntimeException('Could not create the media folder for a restored file.');
    }
    $mediaRoot = realpath(bms_media_public_root());
    $real = realpath($directory);
    if ($mediaRoot === false || $real === false || ($real !== $mediaRoot && !str_starts_with($real, $mediaRoot . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('A restored media file would be written outside the media folder.');
    }
}

/** Copy a staged file into place through a temporary name in the same folder. */
function bms_portability_place_file(string $source, string $destination): void
{
    bms_portability_prepare_media_directory($destination);
    $temporary = dirname($destination) . '/.bms-restore-' . bin2hex(random_bytes(8));
    if (!copy($source, $temporary)) {
        @unlink($temporary);
        throw new RuntimeException('Could not copy a restored media file.');
    }
    @chmod($temporary, 0644);
    if (!rename($temporary, $destination)) {
        @unlink($temporary);
        throw new RuntimeException('Could not place a restored media file.');
    }
}

function bms_portability_media_text(string $value, int $limit): string
{
    $value = trim($value);
    if (preg_match('//u', $value) !== 1) {
        return '';
    }
    return bms_text_length($value) > $limit ? bms_text_substr($value, 0, $limit) : $value;
}

/**
 * Restore the derivatives recorded for a media file that kept its exported
 * bytes and path. A derivative whose path holds a different file is left out;
 * Media → Regenerate can create it again.
 *
 * @return array<string,array<string,mixed>>
 */
function bms_portability_restore_variants(array $item, string $token): array
{
    $variants = [];
    foreach ((array)($item['variants'] ?? []) as $variant) {
        if (!is_array($variant)) {
            continue;
        }
        $path = bms_portability_clean_media_path((string)($variant['path'] ?? ''));
        $staged = (string)($variant['staged'] ?? '');
        $label = preg_replace('/[^a-z0-9_-]/i', '', (string)($variant['label'] ?? '')) ?: (string)count($variants);
        if ($path === '' || bms_portability_media_category($path) !== 'derivative' || $staged === '') {
            continue;
        }
        $stagedPath = bms_import_staging_path($token, $staged);
        $info = is_file($stagedPath) ? @getimagesize($stagedPath) : false;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!is_array($info) || empty($info[0]) || !in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            continue;
        }
        $destination = bms_public_path($path);
        if (is_link($destination)) {
            continue;
        }
        if (is_file($destination)) {
            if (!hash_equals((string)hash_file('sha256', $stagedPath), (string)hash_file('sha256', $destination))) {
                continue;
            }
        } else {
            bms_portability_place_file($stagedPath, $destination);
        }
        $metadata = bms_media_variant_metadata($path);
        if ($metadata) {
            $variants[$label] = $metadata;
        }
    }
    return $variants;
}

/** Insert the media record for a restored file and return it. */
function bms_portability_insert_media_record(array $item, string $path, array $valid, array $privacy, array $variants): array
{
    $destination = bms_public_path($path);
    $mime = (string)($valid['mime'] ?? '');
    $width = null;
    $height = null;
    if (str_starts_with($mime, 'image/')) {
        $info = @getimagesize($destination);
        if (is_array($info) && !empty($info[0]) && !empty($info[1])) {
            $width = (int)$info[0];
            $height = (int)$info[1];
        }
    }
    $originalName = bms_portability_media_text((string)($item['original_filename'] ?? ''), 255);
    $altText = bms_portability_media_text((string)($item['alt_text'] ?? ''), 255);
    if ($altText === '') {
        $altText = str_starts_with($mime, 'image/') ? 'Uploaded image' : 'Uploaded media';
    }
    $createdAt = bms_portability_utc_from_value((string)($item['created_at'] ?? '')) ?: gmdate('Y-m-d H:i:s');
    $updatedAt = bms_portability_utc_from_value((string)($item['updated_at'] ?? '')) ?: $createdAt;
    $trashedAt = bms_portability_utc_from_value((string)($item['trashed_at'] ?? ''));
    $currentUser = bms_current_user_id();

    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('media') . ' (filename, original_filename, public_path, mime_type, file_size, width, height, alt_text, caption, uploaded_by, file_hash, image_variants_json, privacy_status, privacy_note, privacy_checked_at, created_at, updated_at, trashed_at, trashed_by) VALUES (:filename, :original_filename, :public_path, :mime_type, :file_size, :width, :height, :alt_text, :caption, :uploaded_by, :file_hash, :image_variants_json, :privacy_status, :privacy_note, NOW(), :created_at, :updated_at, :trashed_at, :trashed_by)');
    $stmt->execute([
        'filename' => basename($path),
        'original_filename' => $originalName !== '' ? $originalName : basename($path),
        'public_path' => $path,
        'mime_type' => $mime,
        'file_size' => (int)filesize($destination),
        'width' => $width,
        'height' => $height,
        'alt_text' => $altText,
        'caption' => (string)($item['caption'] ?? ''),
        'uploaded_by' => $currentUser,
        'file_hash' => (string)hash_file('sha256', $destination),
        'image_variants_json' => $variants ? json_encode($variants, JSON_UNESCAPED_SLASHES) : null,
        'privacy_status' => (string)($privacy['status'] ?? 'unconfirmed'),
        'privacy_note' => bms_portability_media_text((string)($privacy['note'] ?? ''), 255),
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
        'trashed_at' => $trashedAt !== '' ? $trashedAt : null,
        'trashed_by' => $trashedAt !== '' ? $currentUser : null,
    ]);
    return bms_media_find((int)bms_db()->lastInsertId()) ?? [];
}

/**
 * Restore one prepared media library file and its record.
 *
 * The file returns to its exported path. When that path already holds the
 * same bytes, the existing file and record are kept. When it holds a different
 * file, the restored file gets a new name. An image the export does not mark
 * as privacy-cleaned is cleaned again under this site's media privacy mode.
 *
 * @return array{status:string,path:string,message:string}
 *   status is 'restored', 'reused', 'missing', or 'failed'.
 */
function bms_portability_restore_media_item(array $item, string $token): array
{
    $path = bms_portability_clean_media_path((string)($item['public_path'] ?? ''));
    if ($path === '' || bms_portability_media_category($path) !== 'library') {
        return ['status' => 'failed', 'path' => '', 'message' => 'Skipped a media file with an unsupported path.'];
    }
    $name = basename($path);
    $staged = (string)($item['staged'] ?? '');
    $stagedPath = $staged !== '' ? bms_import_staging_path($token, $staged) : '';
    $destination = bms_public_path($path);
    $existing = bms_media_find_by_public_path($path);
    $exportedHash = strtolower((string)($item['file_hash'] ?? ''));

    if ($stagedPath === '' || !is_file($stagedPath)) {
        // The export lists this file without including it. A copy already in
        // place with the exported bytes still gets its record back.
        if ($existing !== null) {
            return ['status' => 'reused', 'path' => $path, 'message' => ''];
        }
        if ($exportedHash !== '' && is_file($destination) && !is_link($destination) && hash_equals($exportedHash, (string)hash_file('sha256', $destination))) {
            $valid = bms_media_validate_upload(['name' => $name, 'tmp_name' => $destination, 'size' => (int)filesize($destination), 'error' => UPLOAD_ERR_OK]);
            $privacy = ['status' => (string)($item['privacy_status'] ?? 'legacy_unchecked') ?: 'legacy_unchecked', 'note' => (string)($item['privacy_note'] ?? '')];
            bms_portability_insert_media_record($item, $path, $valid, $privacy, []);
            return ['status' => 'restored', 'path' => $path, 'message' => ''];
        }
        return ['status' => 'missing', 'path' => '', 'message' => 'The export lists ' . $name . ' but does not include the file.'];
    }

    $stagedHash = (string)hash_file('sha256', $stagedPath);
    if (is_link($destination) || is_file($destination)) {
        if (!is_link($destination) && hash_equals($stagedHash, (string)hash_file('sha256', $destination))) {
            if ($existing !== null) {
                return ['status' => 'reused', 'path' => $path, 'message' => ''];
            }
            $valid = bms_media_validate_upload(['name' => $name, 'tmp_name' => $destination, 'size' => (int)filesize($destination), 'error' => UPLOAD_ERR_OK]);
            $privacy = ['status' => (string)($item['privacy_status'] ?? 'legacy_unchecked') ?: 'legacy_unchecked', 'note' => (string)($item['privacy_note'] ?? '')];
            bms_portability_insert_media_record($item, $path, $valid, $privacy, []);
            return ['status' => 'restored', 'path' => $path, 'message' => ''];
        }
        if ($existing !== null && !is_link($destination) && (string)($existing['original_filename'] ?? '') === (string)($item['original_filename'] ?? '')) {
            // The same file restored earlier, then cleaned again on this site.
            return ['status' => 'reused', 'path' => $path, 'message' => ''];
        }
        $path = bms_portability_unique_media_path($path);
        $destination = bms_public_path($path);
    } elseif ($existing !== null) {
        // A record without its file: put the file back under the record.
        bms_media_validate_upload(['name' => $name, 'tmp_name' => $stagedPath, 'size' => (int)filesize($stagedPath), 'error' => UPLOAD_ERR_OK]);
        bms_portability_place_file($stagedPath, $destination);
        return ['status' => 'reused', 'path' => $path, 'message' => ''];
    }

    $valid = bms_media_validate_upload(['name' => $name, 'tmp_name' => $stagedPath, 'size' => (int)filesize($stagedPath), 'error' => UPLOAD_ERR_OK]);
    $isImage = str_starts_with((string)$valid['mime'], 'image/');
    $keepBytes = !$isImage || (string)($item['privacy_status'] ?? '') === 'cleaned';
    $written = false;
    try {
        if ($keepBytes) {
            bms_portability_place_file($stagedPath, $destination);
            $written = true;
            $status = (string)($item['privacy_status'] ?? '');
            $privacy = [
                'status' => $status !== '' ? $status : ($isImage ? 'unconfirmed' : 'not_applicable'),
                'note' => (string)($item['privacy_note'] ?? ''),
            ];
            if ($privacy['note'] === '') {
                $privacy['note'] = bms_media_privacy_default_note($privacy['status']);
            }
        } else {
            bms_portability_prepare_media_directory($destination);
            $written = true;
            $privacy = bms_media_privacy_store_upload($valid, $destination);
            @chmod($destination, 0644);
        }
        $variants = $keepBytes && $path === bms_portability_clean_media_path((string)($item['public_path'] ?? ''))
            ? bms_portability_restore_variants($item, $token)
            : [];
        bms_portability_insert_media_record($item, $path, $valid, $privacy, $variants);
    } catch (Throwable $e) {
        if ($written && is_file($destination)) {
            @unlink($destination);
        }
        throw $e;
    }
    return ['status' => 'restored', 'path' => $path, 'message' => ''];
}

/**
 * Restore every prepared media library file. Files return to their exported
 * paths; the map lists each restored file by its exported path.
 *
 * @return array{map:array<string,string>,restored:int,reused:int,missing:int,failed:int,warnings:list<string>}
 */
function bms_portability_restore_media_library(array $library): array
{
    $summary = ['map' => [], 'restored' => 0, 'reused' => 0, 'missing' => 0, 'failed' => 0, 'warnings' => []];
    $token = (string)($library['token'] ?? '');
    foreach ((array)($library['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $original = bms_portability_clean_media_path((string)($item['public_path'] ?? ''));
        try {
            $result = bms_portability_restore_media_item($item, $token);
        } catch (Throwable $e) {
            $result = ['status' => 'failed', 'path' => '', 'message' => 'Could not restore media ' . basename($original) . ': ' . $e->getMessage()];
        }
        $summary[$result['status']]++;
        if ($result['path'] !== '' && $original !== '') {
            $summary['map'][$original] = $result['path'];
            if ($result['path'] !== $original) {
                $summary['warnings'][] = 'Restored media ' . basename($original) . ' as ' . basename($result['path']) . ' because a different file already uses its name.';
            }
        }
        if ($result['message'] !== '') {
            $summary['warnings'][] = $result['message'];
        }
    }
    return $summary;
}

/* -------------------------------------------------------------------------
 * Import: Local Places references
 * ---------------------------------------------------------------------- */

/**
 * Advance the Local Places id counter without keeping a row. With $id the
 * counter moves past that id; without it, the next id is consumed and
 * returned. InnoDB never hands out an id again once it has been allocated,
 * including inside a rolled-back transaction.
 */
function bms_portability_reserve_place_id(?int $id = null): int
{
    $pdo = bms_db();
    if ($pdo->inTransaction() || !$pdo->beginTransaction()) {
        throw new RuntimeException('Could not reserve a Local Places id.');
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO ' . bms_table('places') . ' (id, name, category, area_label, locality, region, country, latitude, longitude, default_display_mode, created_at, updated_at) VALUES (:id, :name, :category, :area_label, :locality, :region, :country, 0, 0, :mode, NOW(), NOW())');
        $stmt->execute(['id' => $id, 'name' => 'Reserved import reference', 'category' => 'other', 'area_label' => '', 'locality' => '', 'region' => '', 'country' => '', 'mode' => 'exact']);
        $reserved = $id ?? (int)$pdo->lastInsertId();
    } finally {
        $pdo->rollBack();
    }
    return $reserved;
}

/**
 * Keep imported Local Places references from pointing at the wrong place.
 *
 * An imported post keeps its location text and the place id it was exported
 * with. The Local Places directory itself is not exported, so the id has no
 * place on a new site; the post shows its saved location text, as it does for
 * a place that was deleted. The id counter is moved past every imported id, so
 * a place created later never takes one over. When this site already has a
 * different place under an imported id, the reference moves to a reserved
 * unused id instead.
 *
 * @param array<int,array{name:string,locality:string}> $references keyed by place id
 * @return array<int,int> imported place id => id to store, for moved references
 */
function bms_portability_prepare_place_references(array $references): array
{
    if ($references === [] || !function_exists('bms_places_table_ready') || !bms_places_table_ready()) {
        return [];
    }
    $map = [];
    $dangling = [];
    $lookup = bms_db()->prepare('SELECT id, name, locality FROM ' . bms_table('places') . ' WHERE id = :id LIMIT 1');
    foreach ($references as $id => $snapshot) {
        $id = (int)$id;
        if ($id < 1) {
            continue;
        }
        $lookup->execute(['id' => $id]);
        $row = $lookup->fetch();
        $lookup->closeCursor();
        if (!is_array($row)) {
            $dangling[] = $id;
            continue;
        }
        $sameName = trim((string)($row['name'] ?? '')) === trim((string)($snapshot['name'] ?? ''));
        $sameLocality = trim((string)($row['locality'] ?? '')) === trim((string)($snapshot['locality'] ?? ''));
        if (!$sameName || !$sameLocality) {
            $map[$id] = bms_portability_reserve_place_id();
        }
    }
    if ($dangling !== []) {
        bms_portability_reserve_place_id(max($dangling));
    }
    return $map;
}
