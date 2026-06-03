<?php
require_once __DIR__ . '/_helpers.php';
$data = ml_theme_data($bms_theme_data ?? []);
$mainClass = (string)($data['main_class'] ?? 'site-main stream-shell timeline ledger-layout-shell');
ml_open_document($data, [
    'fallback_title' => (string)($data['site_name'] ?? 'Bonumark Stream'),
    'main_class' => $mainClass,
]);
?>
        <?= (string)($data['content_html'] ?? '') ?>
<?php ml_close_document($data); ?>
