<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
require_once __DIR__ . '/../_bonumark_stream/app/editor.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();
mp_require_capability('manage_pages');

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
    mp_verify_csrf();
    $submitAction = (string)($_POST['page_submit_action'] ?? $_POST['stream_submit_action'] ?? 'draft');
    $requestedStatus = $submitAction === 'publish' && mp_current_user_can('manage_pages') ? 'published' : 'draft';
    $section = mp_page_status_section($requestedStatus);
    $raw = mp_build_page_markdown_from_request($requestedStatus);
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $body = trim((string)($_POST['body_markdown'] ?? ''));
    if (trim((string)($_POST['page_title'] ?? '')) === '') {
        mp_flash('Page title is required.', 'error');
        mp_redirect(mp_admin_url('page-new.php'));
    }
    if ($body === '') {
        mp_flash('Page body cannot be empty.', 'error');
        mp_redirect(mp_admin_url('page-new.php'));
    }
    if (strlen($raw) > 1024 * 1024 * 2) {
        mp_flash('Page is too large. Keep files under 2 MB.', 'error');
        mp_redirect(mp_admin_url('page-new.php'));
    }
    try {
        $createdPage = mp_parse_markdown_string($raw);
        $filename = $createdPage['slug'] . '.md';
        if (function_exists('mp_find_database_content_by_slug_status') && (mp_find_database_content_by_slug_status((string)$createdPage['slug'], 'draft', 'page') || mp_find_database_content_by_slug_status((string)$createdPage['slug'], 'published', 'page'))) {
            throw new RuntimeException('A page with this slug already exists. Change the slug or edit the existing page.');
        }
        mp_sync_page_metadata($createdPage, $section, $filename, mp_current_user_id());
        if ($requestedStatus === 'published') {
            mp_flash('Page published. “' . $createdPage['title'] . '” is live through dynamic rendering.', 'success');
            mp_redirect(mp_admin_url('page-edit.php?type=published&file=' . urlencode($filename)));
        }
        mp_flash('Draft page created. “' . $createdPage['title'] . '” is ready to edit or publish.', 'success');
        mp_redirect(mp_admin_url('page-edit.php?type=draft&file=' . urlencode($filename)));
    } catch (Throwable $e) {
        mp_flash('Page creation failed. ' . $e->getMessage(), 'error');
    }
}

function mp_page_editor_form(array $page, string $section, string $buttonLabel): void
{
    ?>
    <section class="editor-panel editor-composer-panel">
      <div class="composer-top-row" aria-label="Editor options">
        <p class="editor-page-helper">Pages are stable site content. They do not appear in the stream feed.</p>
      </div>
      <form id="stream-editor-form" method="post" class="editor-form editor-layout-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="content_kind" value="page">
        <div class="editor-workspace">
          <div class="editor-primary-column">
            <?php mp_page_title_fields($page); ?>
            <?php mp_dual_editor((string)($page['body'] ?? '')); ?>
          </div>
          <aside class="editor-sidebar-column">
            <?php mp_publish_sidebar($section, $buttonLabel, '', ['mode' => 'new', 'content_type' => 'page']); ?>
            <?php mp_page_url_fields($page, $section); ?>
            <?php mp_page_settings_fields($page); ?>
              </aside>
        </div>
      </form>
    </section>
    <?php
}

mp_admin_header('New Page', [
    ['label' => 'All Pages', 'href' => mp_admin_url('pages.php'), 'style' => 'secondary'],
]);
mp_page_editor_form($page, 'pages/drafts', 'Save Draft');
mp_editor_script_tag();
mp_admin_footer();
