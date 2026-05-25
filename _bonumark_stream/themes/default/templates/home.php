<?php
require_once __DIR__ . '/_helpers.php';
$data = ml_theme_data($mp_theme_data ?? []);
ml_open_document($data, [
    'fallback_title' => (string)($data['site_name'] ?? 'Bonumark Stream'),
    'og_type' => 'website',
    'feed' => true,
    'main_class' => 'site-main stream-shell timeline ledger-stream-shell ledger-home-shell',
]);
?>
        <?= (string)($data['composer_html'] ?? '') ?>
        <section class="stream-feed ledger-stream-feed" aria-label="Stream posts">
          <?= (string)($data['items_html'] ?? '') ?>
        </section>
        <?= (string)($data['pagination_html'] ?? '') ?>
<?php ml_close_document($data); ?>
