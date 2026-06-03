<?php
require_once __DIR__ . '/renderer.php';
require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/import-media.php';
require_once __DIR__ . '/importers/ImportItem.php';
require_once __DIR__ . '/importers/ImportResult.php';
require_once __DIR__ . '/importers/ImporterInterface.php';
require_once __DIR__ . '/importers/MarkdownImporter.php';
require_once __DIR__ . '/importers/JsonImporter.php';
require_once __DIR__ . '/importers/WordPressWxrImporter.php';
require_once __DIR__ . '/importers/TwitterArchiveImporter.php';
require_once __DIR__ . '/importers/BlueskyArchiveImporter.php';
require_once __DIR__ . '/importers/BonumarkExportImporter.php';


function bms_import_uploaded_file_is_readable(string $path): bool
{
    if ($path === '') {
        return false;
    }
    if (is_uploaded_file($path)) {
        return true;
    }
    return PHP_SAPI === 'cli' && is_file($path);
}

function bms_import_max_upload_bytes(): int
{
    return 128 * 1024 * 1024;
}

/** @return list<BMS_ImporterInterface> */
function bms_importers(): array
{
    return [
        new BMS_MarkdownImporter(),
        new BMS_JsonImporter(),
        new BMS_WordPressWxrImporter(),
        new BMS_BonumarkExportImporter(),
        new BMS_BlueskyArchiveImporter(),
        new BMS_TwitterArchiveImporter(),
    ];
}

/** @param array<string,mixed> $file */
function bms_import_detect_importer(array $file): ?BMS_ImporterInterface
{
    foreach (bms_importers() as $importer) {
        if ($importer->canImport($file)) {
            return $importer;
        }
    }
    return null;
}

function bms_import_normalize_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return date('Y-m-d');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        return $value;
    }
    $time = strtotime($value);
    return $time ? date('Y-m-d', $time) : date('Y-m-d');
}

function bms_import_normalize_datetime(string $value): string
{
    $value = trim($value);
    $time = $value !== '' ? strtotime($value) : false;
    return $time ? date('Y-m-d H:i:s', $time) : date('Y-m-d H:i:s');
}

function bms_import_normalize_status(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['published', 'publish', 'public', 'live'], true) ? 'published' : 'draft';
}

/** @param array<string,mixed> $data */
function bms_import_normalize_content_type(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['page', 'pages'], true) ? 'page' : 'stream';
}

function bms_import_make_item(array $data): BMS_ImportItem
{
    $body = str_replace(["\r\n", "\r"], "\n", (string)($data['body'] ?? ''));
    $date = bms_import_normalize_date((string)($data['date'] ?? ''));
    $createdAt = bms_import_normalize_datetime((string)($data['created_at'] ?? $date));
    $title = trim((string)($data['title'] ?? ''));
    $slug = trim((string)($data['slug'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $featuredMedia = trim((string)($data['featured_media'] ?? ''));
    $tags = bms_normalize_terms($data['tags'] ?? []);
    $status = bms_import_normalize_status((string)($data['status'] ?? 'draft'));
    $source = trim((string)($data['source'] ?? ''));
    $contentType = bms_import_normalize_content_type((string)($data['content_type'] ?? $data['post_type'] ?? 'stream'));
    $rawWarnings = $data['warnings'] ?? [];
    $warnings = is_array($rawWarnings) ? array_values(array_filter(array_map('strval', $rawWarnings))) : [];

    if ($contentType === 'page') {
        $fields = [
            'title' => $title,
            'slug' => $slug,
            'status' => $status,
            'date' => $date,
            'content_type' => 'page',
            'description' => $description,
            'featured_media' => $featuredMedia,
            'stream_created_at' => $createdAt,
            'seo_title' => '',
            'robots' => '',
            'show_in_menu' => (string)($data['show_in_menu'] ?? '0'),
            'menu_label' => (string)($data['menu_label'] ?? ''),
            'menu_order' => (string)($data['menu_order'] ?? '100'),
        ];
        $prepared = function_exists('bms_page_prepare_metadata_fields') ? bms_page_prepare_metadata_fields($fields, $body, '') : bms_stream_prepare_metadata_fields($fields, $body, '');
    } else {
        $fields = [
            'title' => $title,
            'slug' => $slug,
            'status' => $status,
            'date' => $date,
            'content_type' => 'stream',
            'description' => $description,
            'category' => 'Stream',
            'tags' => $tags,
            'featured_media' => $featuredMedia,
            'stream_created_at' => $createdAt,
            'seo_title' => '',
            'robots' => '',
        ];
        $prepared = bms_stream_prepare_metadata_fields($fields, $body, '');
    }

    return new BMS_ImportItem(
        (string)$prepared['title'],
        (string)$prepared['slug'],
        $body,
        $date,
        $createdAt,
        $description,
        $status,
        $source,
        $featuredMedia,
        $contentType === 'page' ? [] : $tags,
        $warnings,
        $contentType
    );
}

function bms_import_preview_session_key(): string
{
    return 'bms_import_preview';
}

function bms_import_preview_storage_root(): string
{
    return bms_import_staging_root('') . '/previews';
}

function bms_import_preview_storage_path(string $token): string
{
    $token = preg_replace('/[^a-f0-9]/i', '', $token) ?? '';
    return bms_import_preview_storage_root() . '/' . $token . '.json';
}

function bms_import_delete_preview_file(?array $preview): void
{
    if (!is_array($preview)) {
        return;
    }
    $path = (string)($preview['preview_file'] ?? '');
    if ($path === '') {
        $token = (string)($preview['token'] ?? '');
        $path = $token !== '' ? bms_import_preview_storage_path($token) : '';
    }
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}

function bms_import_store_preview(BMS_ImportResult $result, string $filename): string
{
    $existingPreview = bms_import_get_preview();
    foreach (bms_import_extract_staging_tokens_from_preview($existingPreview) as $oldToken) {
        bms_import_cleanup_staging_token($oldToken);
    }
    bms_import_delete_preview_file($existingPreview);

    $token = bin2hex(random_bytes(16));
    $items = array_map(static fn(BMS_ImportItem $item): array => $item->toArray(), $result->items);
    $payload = [
        'token' => $token,
        'filename' => $filename,
        'importer' => $result->importerName,
        'created_at' => time(),
        'items' => $items,
        'warnings' => $result->warnings,
        'errors' => $result->errors,
    ];

    $storageRoot = bms_import_preview_storage_root();
    if (!is_dir($storageRoot) && !mkdir($storageRoot, 0755, true)) {
        throw new RuntimeException('Could not create the private import preview staging folder.');
    }
    $previewFile = bms_import_preview_storage_path($token);
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($encoded) || file_put_contents($previewFile, $encoded) === false) {
        throw new RuntimeException('Could not write the private import preview file.');
    }
    @chmod($previewFile, 0600);

    $_SESSION[bms_import_preview_session_key()] = [
        'token' => $token,
        'filename' => $filename,
        'importer' => $result->importerName,
        'created_at' => time(),
        'preview_file' => $previewFile,
        'item_count' => count($items),
        'warning_count' => count($result->warnings),
        'error_count' => count($result->errors),
    ];
    return $token;
}

/** @return array<string,mixed>|null */
function bms_import_get_preview(): ?array
{
    $preview = $_SESSION[bms_import_preview_session_key()] ?? null;
    if (!is_array($preview)) {
        return null;
    }
    if ((int)($preview['created_at'] ?? 0) < time() - 3600) {
        $loaded = bms_import_load_preview_payload($preview);
        foreach (bms_import_extract_staging_tokens_from_preview($loaded) as $token) {
            bms_import_cleanup_staging_token($token);
        }
        bms_import_delete_preview_file($preview);
        unset($_SESSION[bms_import_preview_session_key()]);
        return null;
    }
    return bms_import_load_preview_payload($preview);
}

/** @return array<string,mixed>|null */
function bms_import_load_preview_payload(array $preview): ?array
{
    $file = (string)($preview['preview_file'] ?? '');
    if ($file !== '' && is_file($file)) {
        $raw = file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $decoded['preview_file'] = $file;
            return $decoded;
        }
    }
    if (isset($preview['items']) && is_array($preview['items'])) {
        return $preview;
    }
    return null;
}

function bms_import_save_preview_payload(array $preview): void
{
    $token = (string)($preview['token'] ?? '');
    $file = (string)($preview['preview_file'] ?? '');
    if ($file === '' && $token !== '') {
        $file = bms_import_preview_storage_path($token);
    }
    if ($token === '' || $file === '') {
        throw new RuntimeException('Import preview could not be updated because the preview token was missing.');
    }

    $payload = $preview;
    unset($payload['preview_file']);
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($encoded) || file_put_contents($file, $encoded) === false) {
        throw new RuntimeException('Import preview progress could not be saved.');
    }
    @chmod($file, 0600);

    if (isset($_SESSION[bms_import_preview_session_key()]) && is_array($_SESSION[bms_import_preview_session_key()])) {
        $_SESSION[bms_import_preview_session_key()]['preview_file'] = $file;
        $_SESSION[bms_import_preview_session_key()]['item_count'] = is_array($preview['items'] ?? null) ? count($preview['items']) : 0;
        $_SESSION[bms_import_preview_session_key()]['warning_count'] = is_array($preview['warnings'] ?? null) ? count($preview['warnings']) : 0;
        $_SESSION[bms_import_preview_session_key()]['error_count'] = is_array($preview['errors'] ?? null) ? count($preview['errors']) : 0;
    }
}

function bms_import_update_preview_progress(array $preview, array $progress): void
{
    $existing = is_array($preview['progress'] ?? null) ? $preview['progress'] : [];
    $preview['progress'] = array_merge($existing, $progress);
    bms_import_save_preview_payload($preview);
}

function bms_import_clear_preview(): void
{
    $sessionPreview = $_SESSION[bms_import_preview_session_key()] ?? null;
    $preview = bms_import_get_preview();
    foreach (bms_import_extract_staging_tokens_from_preview($preview) as $token) {
        bms_import_cleanup_staging_token($token);
    }
    if (is_array($sessionPreview)) {
        bms_import_delete_preview_file($sessionPreview);
    } else {
        bms_import_delete_preview_file($preview);
    }
    unset($_SESSION[bms_import_preview_session_key()]);
}

/**
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function bms_import_select_items(array $items, int $start, int $limit): array
{
    $start = max(1, $start);
    $offset = $start - 1;
    if ($limit > 0) {
        return array_slice($items, $offset, $limit);
    }
    return array_slice($items, $offset);
}

/**
 * @param list<array<string,mixed>> $items
 * @return array{imported:int,skipped:int,published:int,drafted:int,media_imported:int,media_removed:int,media_failed:int,details:list<string>}
 */
function bms_import_commit_items(array $items, string $targetStatus, bool $preserveDates, string $duplicatePolicy, string $mediaPolicy = 'remote'): array
{
    $targetStatus = in_array($targetStatus, ['draft', 'published', 'original'], true) ? $targetStatus : 'draft';
    $duplicatePolicy = in_array($duplicatePolicy, ['skip', 'rename'], true) ? $duplicatePolicy : 'skip';
    $mediaPolicy = in_array($mediaPolicy, ['remote', 'import', 'skip'], true) ? $mediaPolicy : 'remote';
    $summary = ['imported' => 0, 'skipped' => 0, 'published' => 0, 'drafted' => 0, 'media_imported' => 0, 'media_removed' => 0, 'media_failed' => 0, 'details' => []];
    $mediaCache = [];
    $authorId = function_exists('bms_current_user_id') ? bms_current_user_id() : null;

    foreach ($items as $rawItem) {
        $item = BMS_ImportItem::fromArray($rawItem);
        $contentType = bms_import_normalize_content_type($item->contentType);
        $status = $targetStatus === 'original' ? bms_import_normalize_status($item->status) : $targetStatus;
        $section = $status === 'published' ? 'published' : 'drafts';
        $databaseSection = $contentType === 'page' ? 'pages/' . $section : $section;
        $date = $preserveDates ? bms_import_normalize_date($item->date) : date('Y-m-d');
        $createdAt = $preserveDates ? bms_import_normalize_datetime($item->createdAt) : date('Y-m-d H:i:s');
        $slug = $contentType === 'page' ? bms_page_clean_slug_candidate($item->slug) : bms_slugify($item->slug);
        if ($slug === '') {
            $slug = $contentType === 'page'
                ? bms_page_unique_slug($item->title !== '' ? $item->title : 'Imported Page')
                : bms_stream_unique_slug(bms_stream_slug_base($item->body, $createdAt, bms_stream_media_context_from_path($item->featuredMedia)));
        }

        $duplicateExists = function_exists('bms_find_database_content_by_slug_status')
            && (bms_find_database_content_by_slug_status($slug, 'published', $contentType) || bms_find_database_content_by_slug_status($slug, 'draft', $contentType));
        if (!$duplicateExists) {
            if ($contentType === 'page') {
                $publishedPath = bms_content_path('pages/published/' . $slug . '.md');
                $draftPath = bms_content_path('pages/drafts/' . $slug . '.md');
            } else {
                $publishedPath = bms_content_path('published/' . $slug . '.md');
                $draftPath = bms_content_path('drafts/' . $slug . '.md');
            }
            $duplicateExists = is_file($publishedPath) || is_file($draftPath);
        }
        if ($duplicateExists) {
            if ($duplicatePolicy === 'skip') {
                $summary['skipped']++;
                $summary['details'][] = 'Skipped duplicate slug: ' . $slug;
                continue;
            }
            $slug = $contentType === 'page' ? bms_page_unique_slug($slug) : bms_stream_unique_slug($slug);
        }

        $body = $item->body;
        $featuredMedia = $item->featuredMedia;
        $hasStagedMedia = function_exists('bms_import_extract_staged_media_urls') && count(bms_import_extract_staged_media_urls($body)) > 0;
        if ($mediaPolicy !== 'remote' || $hasStagedMedia) {
            $mediaResult = bms_import_apply_media_policy_to_body($body, $mediaPolicy, $mediaCache);
            $body = $mediaResult['body'];
            $summary['media_imported'] += (int)$mediaResult['imported'];
            $summary['media_removed'] += (int)$mediaResult['removed'];
            $summary['media_failed'] += (int)$mediaResult['failed'];
            foreach ($mediaResult['warnings'] as $warning) {
                $summary['details'][] = $warning;
            }
        }

        if ($featuredMedia !== '') {
            $featuredNeedsProcessing = (function_exists('bms_import_is_staged_media_url') && bms_import_is_staged_media_url($featuredMedia))
                || ($mediaPolicy === 'import' && function_exists('bms_import_is_remote_http_url') && bms_import_is_remote_http_url($featuredMedia))
                || ($mediaPolicy === 'skip' && ((function_exists('bms_import_is_staged_media_url') && bms_import_is_staged_media_url($featuredMedia)) || (function_exists('bms_import_is_remote_http_url') && bms_import_is_remote_http_url($featuredMedia))));
            if ($featuredNeedsProcessing) {
                $featuredProbe = '![Featured media](' . $featuredMedia . ')';
                $featuredResult = bms_import_apply_media_policy_to_body($featuredProbe, $mediaPolicy, $mediaCache);
                $summary['media_imported'] += (int)$featuredResult['imported'];
                $summary['media_removed'] += (int)$featuredResult['removed'];
                $summary['media_failed'] += (int)$featuredResult['failed'];
                foreach ($featuredResult['warnings'] as $warning) {
                    $summary['details'][] = $warning;
                }
                if (preg_match('/!\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', (string)$featuredResult['body'], $featuredMatch) === 1) {
                    $featuredPath = function_exists('bms_import_local_url_to_media_path') ? bms_import_local_url_to_media_path((string)$featuredMatch[1]) : '';
                    $featuredMedia = $featuredPath !== '' ? $featuredPath : (string)$featuredMatch[1];
                } elseif ($mediaPolicy === 'skip') {
                    $featuredMedia = '';
                }
            }
        }

        if ($contentType === 'page') {
            $fields = [
                'title' => $item->title,
                'slug' => $slug,
                'status' => $status,
                'date' => $date,
                'content_type' => 'page',
                'description' => $item->description,
                'featured_media' => $featuredMedia,
                'stream_created_at' => $createdAt,
                'seo_title' => '',
                'robots' => '',
            ];
            $fields = bms_page_prepare_metadata_fields($fields, $body, '');
            $fields['slug'] = $slug;
            $fields['status'] = $status;
            $fields['date'] = $date;
        } else {
            $fields = [
                'title' => $item->title,
                'slug' => $slug,
                'status' => $status,
                'date' => $date,
                'content_type' => 'stream',
                'description' => $item->description,
                'category' => 'Stream',
                'tags' => $item->tags,
                'featured_media' => $featuredMedia,
                'stream_created_at' => $createdAt,
                'seo_title' => '',
                'robots' => '',
            ];
            $fields = bms_stream_prepare_metadata_fields($fields, $body, '');
            $fields['slug'] = $slug;
            $fields['status'] = $status;
            $fields['date'] = $date;
            $fields['stream_created_at'] = $createdAt;
        }

        $raw = bms_build_markdown_document($fields, $body);
        $page = bms_parse_markdown_string($raw);
        $page['content_type'] = $contentType;
        $page['post_type'] = $contentType;
        $filename = $page['slug'] . '.md';
        if (function_exists('bms_find_database_content_by_slug_status') && bms_find_database_content_by_slug_status((string)$page['slug'], $status, $contentType)) {
            $summary['skipped']++;
            $summary['details'][] = 'Skipped duplicate content record: ' . $filename;
            continue;
        }

        if ($contentType === 'page') {
            if (function_exists('bms_sync_page_metadata')) {
                bms_sync_page_metadata($page, $databaseSection, $filename, $authorId);
            }
        } elseif (function_exists('bms_sync_stream_metadata')) {
            bms_sync_stream_metadata($page, $databaseSection, $filename, $authorId);
        }
        if ($status === 'published') {
            $summary['published']++;
        } else {
            $summary['drafted']++;
        }
        $summary['imported']++;
    }

    return $summary;
}
