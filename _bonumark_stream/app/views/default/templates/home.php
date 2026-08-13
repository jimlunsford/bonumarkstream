<?php
require_once __DIR__ . '/_helpers.php';
$data = ml_theme_data($bms_theme_data ?? []);
$homeTheme = is_array($data['theme'] ?? null) ? $data['theme'] : null;
$declarativeHomeHtml = function_exists('bms_render_public_theme_layout_surface')
    ? bms_render_public_theme_layout_surface('home', $data, $homeTheme)
    : null;
ml_open_document($data, [
    'fallback_title' => (string)($data['site_name'] ?? 'Bonumark Stream'),
    'og_type' => 'website',
    'feed' => true,
    'main_class' => 'site-main stream-shell timeline ledger-stream-shell ledger-home-shell',
]);
?>
<?php if ($declarativeHomeHtml !== null): ?>
        <?= $declarativeHomeHtml ?>
<?php else: ?>
        <?= (string)($data['composer_html'] ?? '') ?>
        <section class="stream-feed ledger-stream-feed" aria-label="Stream posts">
          <?= (string)($data['items_html'] ?? '') ?>
        </section>
        <?= (string)($data['pagination_html'] ?? '') ?>
<?php endif; ?>
<?php ml_close_document($data); ?>
