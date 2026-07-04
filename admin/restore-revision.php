<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
bms_require_login();
bms_verify_csrf();

$id = (int)($_POST['revision_id'] ?? 0);
$mode = (string)($_POST['restore_mode'] ?? 'draft');

try {
    $revision = bms_get_revision($id);
    if (!$revision) {
        throw new RuntimeException('Revision not found.');
    }
    bms_require_revision_access($revision);

    if ($mode === 'current') {
        $page = bms_restore_revision_over_current($id);
        $type = ($page['restored_status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        bms_flash('Revision restored over the current ' . ($type === 'published' ? 'published stream post' : 'draft') . ': “' . $page['title'] . '”.', 'success');
        bms_redirect(bms_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode((string)$page['filename'])));
    }

    $page = bms_restore_revision_as_draft($id);
    bms_flash('Revision restored as a new draft: “' . $page['title'] . '”. Review it before publishing.', 'success');
    bms_redirect(bms_admin_url('edit.php?type=draft&file=' . urlencode((string)$page['filename'])));
} catch (Throwable $e) {
    bms_log_admin_exception('restore-revision', $e);

    bms_flash('Revision restore failed. Please try again.', 'error');
    bms_redirect(bms_admin_url('revisions.php'));
}
