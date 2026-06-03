<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

function bms_diff_lines(string $old, string $new): array
{
    $a = preg_split('/\R/', $old) ?: [];
    $b = preg_split('/\R/', $new) ?: [];
    $m = count($a); $n = count($b);
    $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
    for ($i = $m - 1; $i >= 0; $i--) {
        for ($j = $n - 1; $j >= 0; $j--) {
            $dp[$i][$j] = ($a[$i] === $b[$j]) ? $dp[$i + 1][$j + 1] + 1 : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
        }
    }
    $out = []; $i = 0; $j = 0;
    while ($i < $m && $j < $n) {
        if ($a[$i] === $b[$j]) { $out[] = ['same', $a[$i]]; $i++; $j++; }
        elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) { $out[] = ['removed', $a[$i]]; $i++; }
        else { $out[] = ['added', $b[$j]]; $j++; }
    }
    while ($i < $m) { $out[] = ['removed', $a[$i++]]; }
    while ($j < $n) { $out[] = ['added', $b[$j++]]; }
    return $out;
}

$id = (int)($_GET['id'] ?? 0);
$revision = $id > 0 ? bms_get_revision($id) : null;
if (!$revision) {
    bms_admin_error_page('Revision not found', 'The requested revision could not be found.', 404, [
        ['label' => 'Revisions', 'href' => bms_admin_url('revisions.php'), 'style' => 'primary'],
        ['label' => 'Dashboard', 'href' => bms_admin_url(), 'style' => 'secondary'],
    ]);
}
bms_require_revision_access($revision);
$revisionSource = (string)($revision['content_body'] ?? '');
if ($revisionSource === '') {
    $revisionPath = bms_root_path((string)($revision['markdown_path'] ?? ''));
    $revisionSource = is_file($revisionPath) ? (string)file_get_contents($revisionPath) : '';
}
$currentSource = '';
$currentLabel = '';
$slug = bms_slugify((string)$revision['slug']);
foreach (['published', 'draft'] as $status) {
    $currentPost = function_exists('bms_find_database_content_by_slug_status') ? bms_find_database_content_by_slug_status($slug, $status, 'stream') : null;
    if ($currentPost) {
        $section = $status === 'published' ? 'published' : 'drafts';
        $filename = basename((string)($currentPost['filename'] ?? ($slug . '.md')));
        bms_require_content_file_access($section, $filename, 'edit_content', $currentPost);
        $currentSource = (string)($currentPost['body'] ?? '');
        $currentLabel = 'database ' . $status . ' record';
        break;
    }
}
$diff = bms_diff_lines($revisionSource, $currentSource);
bms_admin_header('Compare Revision', [
    ['label' => 'Revisions', 'href' => bms_admin_url('revisions.php?slug=' . urlencode((string)$revision['slug'])), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel"><p class="eyebrow">Revision comparison</p><h2><?= htmlspecialchars((string)($revision['title'] ?: $revision['slug']), ENT_QUOTES, 'UTF-8') ?></h2><p class="meta">Revision from <?= htmlspecialchars((string)$revision['created_at'], ENT_QUOTES, 'UTF-8') ?> compared to <?= htmlspecialchars($currentLabel !== '' ? $currentLabel : 'current content not found', ENT_QUOTES, 'UTF-8') ?>.</p></section>
<section class="panel revision-diff-panel">
  <?php if ($revisionSource === '' || $currentSource === ''): ?><p class="notice warning">One side of the comparison is missing. The available content is shown below.</p><?php endif; ?>
  <pre class="diff-view"><?php foreach ($diff as [$type, $line]): ?><span class="diff-line <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"><?= $type === 'added' ? '+ ' : ($type === 'removed' ? '- ' : '  ') ?><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></span>
<?php endforeach; ?></pre>
</section>
<section class="panel revision-actions-panel">
  <form method="post" action="<?= htmlspecialchars(bms_admin_url('restore-revision.php'), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="revision_id" value="<?= (int)$revision['id'] ?>"><button type="submit">Restore Revision as New Draft</button></form>
  <form method="post" action="<?= htmlspecialchars(bms_admin_url('restore-revision.php'), ENT_QUOTES, 'UTF-8') ?>" data-confirm="Restore this revision over the current content? Bonumark Stream will archive the current version first."><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="revision_id" value="<?= (int)$revision['id'] ?>"><input type="hidden" name="restore_mode" value="current"><button type="submit" class="secondary-button">Restore Over Current</button></form>
</section>
<script src="<?= htmlspecialchars(bms_asset_url('assets/editor.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php bms_admin_footer(); ?>
