<?php
require_once __DIR__ . '/_helpers.php';
$data = ml_theme_data($bms_theme_data ?? []);
ml_open_document($data, [
    'fallback_title' => 'Stream',
    'og_type' => 'website',
    'feed' => true,
    'main_class' => 'site-main stream-shell timeline stream-archive-shell ledger-stream-shell ledger-archive-shell',
]);
?>
        <section class="stream-feed ledger-stream-feed" aria-label="Stream archive posts">
          <?= (string)($data['items_html'] ?? '') ?>
        </section>
        <?= (string)($data['pagination_html'] ?? '') ?>
<?php ml_close_document($data); ?>
