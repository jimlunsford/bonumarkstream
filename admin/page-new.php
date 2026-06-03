<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
require_once __DIR__ . '/../_bonumark_stream/app/editor.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_pages');

$today = date('Y-m-d');
$defaultStatus = 'draft';
$page = [
    'title' => '',
    'slug' => '',
    'status' => $defaultStatus,
    'date' => $today,
    'content_type' => 'page',
    'description' => '',
    'seo_title' => '',
    'robots' => '',
    'body' => '',
    'front_matter' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $submitAction = (string)($_POST['page_submit_action'] ?? $_POST['stream_submit_action'] ?? 'draft');
    $requestedStatus = $submitAction === 'publish' && bms_current_user_can('manage_pages') ? 'published' : 'draft';
    $section = bms_page_status_section($requestedStatus);
    $raw = bms_build_page_markdown_from_request($requestedStatus);
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $body = trim((string)($_POST['body_markdown'] ?? ''));
    if (trim((string)($_POST['page_title'] ?? '')) === '') {
        bms_flash('Page title is required.', 'error');
        bms_redirect(bms_admin_url('page-new.php'));
    }
    if ($body === '') {
        bms_flash('Page body cannot be empty.', 'error');
        bms_redirect(bms_admin_url('page-new.php'));
    }
    if (strlen($raw) > 1024 * 1024 * 2) {
        bms_flash('Page is too large. Keep files under 2 MB.', 'error');
        bms_redirect(bms_admin_url('page-new.php'));
    }
    try {
        $createdPage = bms_parse_markdown_string($raw);
        $filename = $createdPage['slug'] . '.md';
        if (function_exists('bms_find_database_content_by_slug_status') && (bms_find_database_content_by_slug_status((string)$createdPage['slug'], 'draft', 'page') || bms_find_database_content_by_slug_status((string)$createdPage['slug'], 'published', 'page'))) {
            throw new RuntimeException('A page with this slug already exists. Change the slug or edit the existing page.');
        }
        bms_sync_page_metadata($createdPage, $section, $filename, bms_current_user_id());
        if ($requestedStatus === 'published') {
            bms_flash('Page published. “' . $createdPage['title'] . '” is live through dynamic rendering.', 'success');
            bms_redirect(bms_admin_url('page-edit.php?type=published&file=' . urlencode($filename)));
        }
        bms_flash('Draft page created. “' . $createdPage['title'] . '” is ready to edit or publish.', 'success');
        bms_redirect(bms_admin_url('page-edit.php?type=draft&file=' . urlencode($filename)));
    } catch (Throwable $e) {
        bms_flash('Page creation failed. ' . $e->getMessage(), 'error');
    }
}

function bms_page_editor_form(array $page, string $section, string $buttonLabel): void
{
    ?>
    <section class="editor-panel editor-composer-panel">
      <div class="composer-top-row" aria-label="Editor options">
        <p class="editor-page-helper">Pages are stable site content. They do not appear in the stream feed.</p>
      </div>
      <form id="stream-editor-form" method="post" class="editor-form editor-layout-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="content_kind" value="page">
        <div class="editor-workspace">
          <div class="editor-primary-column">
            <?php bms_page_title_fields($page); ?>
            <?php bms_dual_editor((string)($page['body'] ?? '')); ?>
          </div>
          <aside class="editor-sidebar-column">
            <?php bms_publish_sidebar($section, $buttonLabel, '', ['mode' => 'new', 'content_type' => 'page']); ?>
            <?php bms_page_url_fields($page, $section); ?>
            <?php bms_page_settings_fields($page); ?>
              </aside>
        </div>
      </form>
    </section>
    <?php
}

bms_admin_header('New Page', [
    ['label' => 'All Pages', 'href' => bms_admin_url('pages.php'), 'style' => 'secondary'],
]);
bms_page_editor_form($page, 'pages/drafts', 'Save Draft');
bms_editor_script_tag();
bms_admin_footer();
