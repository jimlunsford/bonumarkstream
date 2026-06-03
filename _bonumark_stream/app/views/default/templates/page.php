<?php
require_once __DIR__ . '/_helpers.php';
$data = ml_theme_data($bms_theme_data ?? []);
$page = is_array($data['page'] ?? null) ? $data['page'] : [];
$settings = is_array($data['theme_settings'] ?? null) ? $data['theme_settings'] : [];
$date = trim((string)($page['date'] ?? ''));
$showUpdatedDate = (string)($settings['show_page_updated_date'] ?? '0') === '1';
ml_open_document($data, [
    'fallback_title' => 'Page',
    'og_title' => (string)($data['page_title'] ?? $data['title'] ?? 'Page'),
    'og_type' => 'article',
    'main_class' => 'site-main stream-shell page-shell ledger-page-shell ledger-static-shell',
]);
?>
        <article class="site-page-card ledger-page-card" aria-labelledby="ledger-page-title">
          <header class="site-page-header ledger-page-header">
            <h1 id="ledger-page-title"><?= ml_h((string)($data['page_title'] ?? 'Untitled Page')) ?></h1>
            <?php if ($showUpdatedDate && $date !== ''): ?><p class="meta ledger-page-meta">Updated <?= ml_h($date) ?></p><?php endif; ?>
          </header>
          <div class="site-page-content ledger-page-content">
            <?= (string)($data['body_html'] ?? '') ?>
          </div>
          <?php if ((string)($data['edit_url'] ?? '') !== ''): ?>
            <p class="site-page-actions ledger-page-actions"><a class="stream-meta-pill ledger-action-pill" href="<?= ml_h((string)$data['edit_url']) ?>">Edit Page</a></p>
          <?php endif; ?>
        </article>
<?php ml_close_document($data); ?>
