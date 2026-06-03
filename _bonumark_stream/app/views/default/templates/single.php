<?php
require_once __DIR__ . '/_helpers.php';
$data = ml_theme_data($bms_theme_data ?? []);
ml_open_document($data, [
    'fallback_title' => (string)($data['site_name'] ?? 'Stream Post'),
    'append_site_name' => true,
    'og_type' => 'article',
    'main_class' => 'site-main stream-shell stream-single-shell timeline ledger-stream-shell ledger-single-shell',
]);
?>
        <?= (string)($data['card_html'] ?? '') ?>
        <?= (string)($data['comments_html'] ?? '') ?>
<?php ml_close_document($data); ?>
