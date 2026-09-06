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
        <?php if (!empty($data['remote_reactions'])): ?>
          <section class="stream-state-card stream-card-content" aria-labelledby="remote-reactions-title">
            <h2 id="remote-reactions-title">From the fediverse</h2>
            <p>Recent likes and boosts, visible only to you.</p>
            <?php foreach (['likes' => 'Likes', 'boosts' => 'Boosts'] as $key => $label): ?>
              <?php if (!empty($data['remote_reactions'][$key])): ?>
                <h3><?= $label ?></h3>
                <ul>
                  <?php foreach ($data['remote_reactions'][$key] as $reaction): ?>
                    <li><strong><?= htmlspecialchars((string)$reaction['name'], ENT_QUOTES, 'UTF-8') ?></strong> <bdi><?= htmlspecialchars((string)$reaction['handle'], ENT_QUOTES, 'UTF-8') ?></bdi></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            <?php endforeach; ?>
          </section>
        <?php endif; ?>
        <?= (string)($data['comments_html'] ?? '') ?>
<?php ml_close_document($data); ?>
