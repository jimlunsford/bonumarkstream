<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
mp_require_login();
mp_verify_csrf();

$id = (int)($_POST['revision_id'] ?? 0);
$mode = (string)($_POST['restore_mode'] ?? 'draft');

try {
    $revision = mp_get_revision($id);
    if (!$revision) {
        throw new RuntimeException('Revision not found.');
    }
    mp_require_revision_access($revision);

    if ($mode === 'current') {
        $page = mp_restore_revision_over_current($id);
        $type = ($page['restored_status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        mp_flash('Revision restored over the current ' . ($type === 'published' ? 'published stream post' : 'draft') . ': “' . $page['title'] . '”.', 'success');
        mp_redirect(mp_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode((string)$page['filename'])));
    }

    $page = mp_restore_revision_as_draft($id);
    mp_flash('Revision restored as a new draft: “' . $page['title'] . '”. Review it before publishing.', 'success');
    mp_redirect(mp_admin_url('edit.php?type=draft&file=' . urlencode((string)$page['filename'])));
} catch (Throwable $e) {
    mp_flash('Revision restore failed. ' . $e->getMessage(), 'error');
    mp_redirect(mp_admin_url('revisions.php'));
}
