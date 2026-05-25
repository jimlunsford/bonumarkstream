<?php
require_once __DIR__ . '/_helpers.php';
$data = ml_theme_data($mp_theme_data ?? []);
$query = (string)($data['query'] ?? '');
$resultCount = (int)($data['result_count'] ?? 0);
ml_open_document($data, [
    'fallback_title' => 'Search',
    'og_type' => 'website',
    'main_class' => 'site-main stream-shell timeline search-shell ledger-search-shell',
]);
?>
        <section class="stream-state-card search-card ledger-panel ledger-search-panel">
          <p class="eyebrow">Search</p>
          <h1>Search the stream</h1>
          <form class="public-search-form ledger-search-form" method="get" action="<?= ml_h((string)($data['search_url'] ?? 'search.php')) ?>">
            <label class="screen-reader-text" for="stream_search_q">Search stream posts</label>
            <input id="stream_search_q" type="search" name="q" value="<?= ml_h($query) ?>" placeholder="Search stream posts">
            <button type="submit">Search</button>
          </form>
          <?php if ($query !== ''): ?>
            <p class="meta"><?= ml_h((string)$resultCount) ?> result<?= $resultCount === 1 ? '' : 's' ?> for “<?= ml_h($query) ?>”.</p>
          <?php endif; ?>
        </section>
        <?php if ($query !== ''): ?>
          <section class="stream-feed ledger-stream-feed" aria-label="Search results">
            <?= (string)($data['items_html'] ?? '') ?>
          </section>
        <?php endif; ?>
<?php ml_close_document($data); ?>
