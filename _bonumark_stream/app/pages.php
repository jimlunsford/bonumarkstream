<?php
require_once __DIR__ . '/renderer.php';


function mp_page_meta_value(array $page, string $key, string $default = ''): string
{
    return (string)($page[$key] ?? ($page['front_matter'][$key] ?? $default));
}

function mp_page_seo_title(array $page): string
{
    $seoTitle = trim(mp_page_meta_value($page, 'seo_title', ''));
    if ($seoTitle !== '') {
        return mp_stream_limit_text($seoTitle, 65, '…');
    }

    return mp_page_generated_seo_title((string)($page['title'] ?? 'Untitled Page'));
}

function mp_page_seo_description(array $page): string
{
    $description = trim((string)($page['description'] ?? $page['front_matter']['description'] ?? ''));
    if ($description !== '') {
        return mp_plain_excerpt($description, 160);
    }
    return mp_plain_excerpt((string)($page['body'] ?? ''), 160);
}

function mp_page_robots_directive(array $page): string
{
    $explicit = strtolower(trim(mp_page_meta_value($page, 'robots', '')));
    if ($explicit === '') {
        return '';
    }
    return preg_replace('/[^a-z,\s-]+/', '', $explicit) ?? '';
}

function mp_render_page(array $page): string
{
    $siteNameRaw = (string)mp_setting_or_config('site_name', 'Bonumark Stream');
    $titleRaw = trim((string)($page['title'] ?? 'Untitled Page')) ?: 'Untitled Page';
    $seoTitleRaw = mp_page_seo_title($page);
    $descriptionRaw = mp_page_seo_description($page);
    $bodyHtml = mp_markdown_to_html((string)($page['body'] ?? ''), true);
    $robotsDirective = mp_page_robots_directive($page);
    $robotsMeta = $robotsDirective !== '' ? '<meta name="robots" content="' . htmlspecialchars($robotsDirective, ENT_QUOTES, 'UTF-8') . '">' : '';

    return mp_render_public_theme_template('page', [
        'site_name' => $siteNameRaw,
        'title' => $seoTitleRaw,
        'seo_title_primary' => function_exists('mp_seo_strip_site_title') ? mp_seo_strip_site_title($seoTitleRaw, $siteNameRaw) : $seoTitleRaw,
        'page_title' => $titleRaw,
        'description' => $descriptionRaw,
        'canonical' => mp_site_url(mp_page_relative_directory_for_page($page) . '/'),
        'robots_meta' => $robotsMeta,
        'style_url' => mp_asset_url('assets/style.css'),
        'script_url' => mp_asset_url('assets/stream.js'),
        'theme_stylesheet_links' => mp_public_theme_stylesheet_links(),
        'favicon_tags' => function_exists('mp_site_favicon_tags') ? mp_site_favicon_tags() : '',
        'theme_script_tags' => mp_public_theme_script_tags(),
        'body_class' => mp_public_theme_class('page'),
        'header_html' => mp_render_public_header('page', null, mp_page_relative_directory_for_page($page) . '/'),
        'footer_html' => mp_render_public_footer(mp_page_relative_directory_for_page($page) . '/'),
        'page' => $page,
        'body_html' => $bodyHtml,
        'edit_url' => function_exists('mp_current_user_can') && mp_current_user_can('manage_pages') ? mp_admin_url('page-edit.php?type=published&file=' . rawurlencode((string)($page['filename'] ?? ''))) : '',
    ]);
}

function mp_find_published_page_by_slug(string $slug): ?array
{
    $slug = mp_slugify($slug);
    if ($slug === '') {
        return null;
    }
    if (function_exists('mp_find_database_content_by_slug_status')) {
        $databasePage = mp_find_database_content_by_slug_status($slug, 'published', 'page');
        if ($databasePage && mp_is_page($databasePage)) {
            return $databasePage;
        }
    }
    $path = mp_content_path('pages/published/' . $slug . '.md');
    if (!is_file($path)) {
        return null;
    }
    $page = mp_parse_markdown_file($path);
    $page['filename'] = basename($path);
    $page['path'] = $path;
    $page['section'] = 'pages/published';
    return mp_is_page($page) ? $page : null;
}

function mp_is_page(array $page): bool
{
    return mp_normalize_content_type((string)($page['content_type'] ?? $page['post_type'] ?? 'page')) === 'page';
}

function mp_handle_page_route(): void
{
    if (!mp_is_installed()) {
        mp_redirect(mp_url_path('install.php'));
    }
    $slug = mp_slugify((string)($_GET['slug'] ?? ''));
    $page = mp_find_published_page_by_slug($slug);
    if (!$page) {
        http_response_code(404);
        echo mp_render_public_theme_template('empty', [
            'context' => 'page',
            'title' => 'Page not found.',
            'message' => 'The requested page could not be found.',
        ]);
        return;
    }
    echo mp_render_page($page);
}


function mp_generate_static_page_exports(?string $targetRoot = null): int
{
    $count = 0;
    mp_delete_directory(mp_static_site_export_path('pages', $targetRoot));
    foreach (mp_list_page_records('published') as $page) {
        mp_write_file(mp_page_export_index_path_for_page($page, $targetRoot), mp_render_page($page));
        $count++;
    }
    return $count;
}

function mp_page_trash_original_status(string $status): string
{
    return $status === 'published' ? 'page_published' : 'page_draft';
}

function mp_page_status_from_trash_status(string $originalStatus): string
{
    return $originalStatus === 'page_published' ? 'published' : 'draft';
}

function mp_page_trash_label(string $originalStatus): string
{
    return mp_page_status_from_trash_status($originalStatus) === 'published' ? 'Published Page' : 'Draft Page';
}

function mp_record_trashed_page(array $page, string $originalStatus, string $originalFilename, string $trashFilename, string $trashPath = ''): void
{
    if (!mp_is_installed()) {
        return;
    }
    $normalizedStatus = $originalStatus === 'published' ? 'published' : 'draft';
    $originalSection = $normalizedStatus === 'published' ? 'pages/published' : 'pages/drafts';
    $originalAuthorId = function_exists('mp_content_author_id_for_file') ? mp_content_author_id_for_file($originalSection, $originalFilename) : null;
    if ($originalAuthorId === null && (int)($page['author_id'] ?? 0) > 0) {
        $originalAuthorId = (int)$page['author_id'];
    }
    $frontMatter = function_exists('mp_content_front_matter_for_database') ? mp_content_front_matter_for_database($page) : (is_array($page['front_matter'] ?? null) ? $page['front_matter'] : []);
    $frontMatterJson = json_encode($frontMatter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $body = (string)($page['body'] ?? '');
    $hash = hash('sha256', $body . "\n" . ($frontMatterJson ?: '{}'));
    $virtualPath = trim($trashPath) !== ''
        ? str_replace(rtrim(mp_root_path(), '/\\') . '/', '', $trashPath)
        : 'content/pages/trash/' . basename($trashFilename ?: (date('Ymd-His') . '-page-' . $normalizedStatus . '-' . mp_database_content_filename_for_page($page)));
    try {
        $stmt = mp_db()->prepare('INSERT INTO ' . mp_table('trash') . ' (title, slug, original_status, original_filename, trash_filename, markdown_path, post_type, content_body, content_front_matter, content_source, content_hash, original_author_id, deleted_by, deleted_at) VALUES (:title, :slug, :original_status, :original_filename, :trash_filename, :markdown_path, :post_type, :content_body, :content_front_matter, :content_source, :content_hash, :original_author_id, :deleted_by, NOW())');
        $stmt->execute([
            'title' => (string)($page['title'] ?? 'Untitled Page'),
            'slug' => (string)($page['slug'] ?? mp_slugify((string)($page['title'] ?? 'page'))),
            'original_status' => mp_page_trash_original_status($normalizedStatus),
            'original_filename' => basename($originalFilename),
            'trash_filename' => basename($trashFilename),
            'markdown_path' => $virtualPath,
            'post_type' => 'page',
            'content_body' => $body,
            'content_front_matter' => $frontMatterJson ?: '{}',
            'content_source' => 'database',
            'content_hash' => $hash,
            'original_author_id' => $originalAuthorId,
            'deleted_by' => function_exists('mp_current_user_id') ? mp_current_user_id() : null,
        ]);
    } catch (Throwable $e) {
        $stmt = mp_db()->prepare('INSERT INTO ' . mp_table('trash') . ' (title, slug, original_status, original_filename, trash_filename, markdown_path, content_hash, original_author_id, deleted_by, deleted_at) VALUES (:title, :slug, :original_status, :original_filename, :trash_filename, :markdown_path, :content_hash, :original_author_id, :deleted_by, NOW())');
        $stmt->execute([
            'title' => (string)($page['title'] ?? 'Untitled Page'),
            'slug' => (string)($page['slug'] ?? mp_slugify((string)($page['title'] ?? 'page'))),
            'original_status' => mp_page_trash_original_status($normalizedStatus),
            'original_filename' => basename($originalFilename),
            'trash_filename' => basename($trashFilename),
            'markdown_path' => $virtualPath,
            'content_hash' => $hash,
            'original_author_id' => $originalAuthorId,
            'deleted_by' => function_exists('mp_current_user_id') ? mp_current_user_id() : null,
        ]);
    }
}

function mp_list_page_trash_items(): array
{
    if (!mp_is_installed()) {
        return [];
    }
    try {
        $stmt = mp_db()->query("SELECT t.*, u.display_name AS deleted_by_name FROM " . mp_table('trash') . " t LEFT JOIN " . mp_table('users') . " u ON u.id = t.deleted_by WHERE t.original_status IN ('page_draft', 'page_published') ORDER BY t.deleted_at DESC, t.id DESC");
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $items = [];
    foreach ($rows as $row) {
        $parsed = function_exists('mp_trash_row_to_content_page') ? mp_trash_row_to_content_page($row, 'page') : [];
        $items[] = array_replace($parsed, [
            'trash_id' => (int)$row['id'],
            'title' => (string)($parsed['title'] ?? $row['title'] ?? 'Untitled Page'),
            'slug' => (string)($parsed['slug'] ?? $row['slug'] ?? ''),
            'filename' => (string)$row['trash_filename'],
            'original_filename' => (string)$row['original_filename'],
            'original_status' => (string)$row['original_status'],
            'content_status' => 'trash',
            'section' => 'pages/trash',
            'path' => mp_root_path((string)($row['markdown_path'] ?? '')),
            'deleted_at' => (string)$row['deleted_at'],
            'author_id' => (int)($row['original_author_id'] ?? 0),
            'original_author_id' => (int)($row['original_author_id'] ?? 0),
            'deleted_by' => (int)($row['deleted_by'] ?? 0),
            'deleted_by_name' => (string)($row['deleted_by_name'] ?? ''),
            'date' => (string)($parsed['date'] ?? substr((string)$row['deleted_at'], 0, 10)),
            'content_storage' => 'database-trash',
        ]);
    }
    return $items;
}

function mp_get_page_trash_item(int $id): ?array
{
    if ($id < 1 || !mp_is_installed()) {
        return null;
    }
    $stmt = mp_db()->prepare("SELECT * FROM " . mp_table('trash') . " WHERE id = :id AND original_status IN ('page_draft', 'page_published') LIMIT 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function mp_require_page_trash_item_access(int $id): array
{
    mp_require_capability('manage_pages');
    $item = mp_get_page_trash_item($id);
    if (!$item) {
        mp_abort_request('Page trash item not found.', 404);
    }
    return $item;
}

function mp_restore_page_trash_item(int $id): array
{
    $item = mp_require_page_trash_item_access($id);
    $page = function_exists('mp_trash_row_to_content_page') ? mp_trash_row_to_content_page($item, 'page') : [];
    $status = mp_page_status_from_trash_status((string)($item['original_status'] ?? 'page_draft'));
    $section = $status === 'published' ? 'pages/published' : 'pages/drafts';
    $restored = function_exists('mp_database_content_page_for_status') ? mp_database_content_page_for_status($page, $status, 'page') : $page;
    $filename = basename((string)($item['original_filename'] ?: mp_database_content_filename_for_page($restored)));
    $slug = mp_slugify((string)($restored['slug'] ?? pathinfo($filename, PATHINFO_FILENAME)));
    if (function_exists('mp_find_database_content_by_slug_status') && mp_find_database_content_by_slug_status($slug, $status, 'page')) {
        throw new RuntimeException('A ' . ($status === 'published' ? 'published page' : 'draft page') . ' already uses this slug. Rename or remove it first.');
    }
    mp_db()->prepare('DELETE FROM ' . mp_table('trash') . ' WHERE id = :id')->execute(['id' => $id]);
    $originalAuthorId = (int)($item['original_author_id'] ?? 0);
    mp_sync_page_metadata($restored, $section, $filename, $originalAuthorId > 0 ? $originalAuthorId : null);
    if ($status === 'published') {
    }
    return $restored + ['filename' => $filename, 'restored_status' => $status];
}

function mp_delete_page_trash_item_permanently(int $id): ?array
{
    $item = mp_require_page_trash_item_access($id);
    $path = mp_root_path((string)($item['markdown_path'] ?? ''));
    if (is_file($path)) {
        @unlink($path);
    }
    mp_db()->prepare('DELETE FROM ' . mp_table('trash') . ' WHERE id = :id')->execute(['id' => $id]);
    return $item;
}

function mp_empty_page_trash(): int
{
    $items = mp_list_page_trash_items();
    $count = 0;
    foreach ($items as $item) {
        $id = (int)($item['trash_id'] ?? 0);
        if ($id > 0) {
            mp_delete_page_trash_item_permanently($id);
            $count++;
        }
    }
    return $count;
}
