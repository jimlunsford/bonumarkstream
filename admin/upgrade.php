<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/upgrader.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

$precheck = null;
$upgradeRecovery = bms_upgrade_recovery_state_current();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['post_upgrade']) && !empty($_SESSION['completed_upgrade']) && is_array($_SESSION['completed_upgrade'])) {
    $completedUpgrade = $_SESSION['completed_upgrade'];
    unset($_SESSION['completed_upgrade']);

    $migrationCount = count($completedUpgrade['migrations'] ?? []);
    bms_flash('Upgrade complete. Bonumark Stream moved from v' . (string)($completedUpgrade['from'] ?? 'unknown') . ' to v' . (string)($completedUpgrade['to'] ?? bms_version()) . '. Backup created and ' . $migrationCount . ' migration(s) ran. Dynamic public routes now use the upgraded code.', 'success');

    $runtimeDirectories = bms_ensure_runtime_directories();
    $runtimeFailures = array_values(array_filter(
        $runtimeDirectories,
        static fn(array $directory): bool => empty($directory['writable'])
    ));
    if ($runtimeFailures !== []) {
        $failedPaths = array_map(
            static fn(array $directory): string => (string)($directory['relative_path'] ?? 'runtime storage'),
            $runtimeFailures
        );
        bms_flash('Upgrade completed, but some runtime storage still needs hosting attention: ' . implode(', ', $failedPaths) . '. Run System Check for details.', 'warning');
    }

    bms_redirect(bms_admin_url());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();

    if (!empty($_POST['cancel_upgrade'])) {
        bms_upgrade_clear_pending();
        bms_flash('Pending upgrade canceled. No software files were changed.', 'info');
        bms_redirect(bms_admin_url('upgrade.php'));
    }

    if (!empty($_POST['confirm_upgrade'])) {
        $pending = $_SESSION['pending_upgrade'] ?? null;
        if (!is_array($pending) || empty($pending['zip_path']) || !is_file((string)$pending['zip_path'])) {
            bms_flash('Upgrade confirmation expired. Upload the Bonumark Stream release ZIP again.', 'warning');
            bms_redirect(bms_admin_url('upgrade.php'));
        }

        try {
            $result = bms_upgrade_install((string)$pending['zip_path']);
            $_SESSION['completed_upgrade'] = $result;
            bms_upgrade_clear_pending();
            bms_redirect(bms_admin_url('upgrade.php?post_upgrade=1'));
        } catch (Throwable $e) {
            bms_log_admin_exception('upgrade', $e);

            $recovery = bms_upgrade_recovery_state_current();
            $message = $recovery ? bms_upgrade_recovery_message($recovery) : trim($e->getMessage());
            if ($message === '') {
                $message = 'Upgrade failed before database migration began. Review System Check and the server error log before trying again.';
            }
            bms_flash($message, 'error');
            bms_redirect(bms_admin_url('upgrade.php'));
        }
    }

    if (empty($_FILES['upgrade_zip']) || $_FILES['upgrade_zip']['error'] !== UPLOAD_ERR_OK) {
        bms_flash('Upload failed. Choose a Bonumark Stream release ZIP and try again.', 'error');
        bms_redirect(bms_admin_url('upgrade.php'));
    }

    $file = $_FILES['upgrade_zip'];
    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'zip') {
        bms_flash('Only Bonumark Stream .zip release packages are allowed.', 'error');
        bms_redirect(bms_admin_url('upgrade.php'));
    }

    if (($file['size'] ?? 0) > 1024 * 1024 * 20) {
        bms_flash('Upgrade package is too large. Keep ZIP uploads under 20 MB.', 'error');
        bms_redirect(bms_admin_url('upgrade.php'));
    }

    try {
        bms_upgrade_clear_pending();
        $precheck = bms_upgrade_precheck_package((string)$file['tmp_name'], (string)$file['name']);
        bms_flash('Upgrade package checked. Review the status below before running the upgrade.', 'info');
    } catch (Throwable $e) {
        bms_upgrade_clear_pending();
        bms_log_admin_exception('upgrade', $e);

        bms_flash('Upgrade check failed. Please try again.', 'error');
        bms_redirect(bms_admin_url('upgrade.php'));
    }
} elseif (!empty($_SESSION['pending_upgrade']) && is_array($_SESSION['pending_upgrade'])) {
    $pending = $_SESSION['pending_upgrade'];
    if (!empty($pending['zip_path']) && is_file((string)$pending['zip_path'])) {
        $precheck = $pending;
    } else {
        unset($_SESSION['pending_upgrade']);
    }
}

$installedUpgradeCapability = bms_automatic_upgrade_capability();

$upgradeHistory = [];
try {
    $upgradeHistory = bms_db()->query('SELECT * FROM ' . bms_table('upgrade_history') . ' ORDER BY ran_at DESC LIMIT 8')->fetchAll() ?: [];
} catch (Throwable $e) {
    $upgradeHistory = [];
}

bms_admin_header('Upgrade', [
    ['label' => 'System Check', 'href' => bms_admin_url('system-check.php'), 'style' => 'secondary'],
    ['label' => 'Tools', 'href' => bms_admin_url('tools.php'), 'style' => 'secondary'],
]);
?>
<section class="panel operations-hero operations-danger-zone">
  <div class="operations-hero-copy"><p class="eyebrow">High-risk operation</p><h2>Replace Bonumark Stream software only after package and recovery checks pass.</h2><p class="meta">An upgrade can replace PHP, assets, bundled themes, documentation, and version markers, then run database migrations. Configuration, runtime data, uploads, media, backups, and custom themes remain protected.</p></div>
  <span class="operation-risk-label is-destructive">Software replacement</span>
</section>
<section class="panel operations-summary-panel"><div class="operations-summary-grid"><div><span>Installed version</span><strong>v<?= htmlspecialchars(bms_version(), ENT_QUOTES, 'UTF-8') ?></strong></div><div><span>Package state</span><strong><?= $precheck ? 'Checked' : 'Not uploaded' ?></strong></div><div><span>Upgrade mode</span><strong><?= !empty($installedUpgradeCapability['available']) ? 'Automatic available' : 'Manual required' ?></strong></div><div><span>Recovery state</span><strong><?= $upgradeRecovery ? 'Resume required' : 'Clear' ?></strong></div></div></section>

<?php if ($upgradeRecovery): ?>
<section class="panel operations-danger-zone upgrade-recovery-panel"><div class="operations-panel-heading"><div><p class="eyebrow">Recovery required</p><h2>Database migration recovery must be resumed.</h2><p><?= htmlspecialchars(bms_upgrade_recovery_message($upgradeRecovery), ENT_QUOTES, 'UTF-8') ?></p><p class="meta">Recorded target: <strong>v<?= htmlspecialchars((string)($upgradeRecovery['to_version'] ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?></strong>. Backup: <code class="operations-technical-value"><?= htmlspecialchars(basename((string)($upgradeRecovery['backup_path'] ?? '')), ENT_QUOTES, 'UTF-8') ?></code></p></div><span class="operation-risk-label is-destructive">Do not use another package</span></div></section>
<?php endif; ?>

<?php if (($installedUpgradeCapability['status'] ?? '') === 'unavailable'): $installedBlocked = $installedUpgradeCapability['blocked'] ?? []; ?>
<section class="panel operations-panel"><div class="operations-panel-heading"><div><p class="eyebrow">Hosting capability</p><h2>Web-based software upgrades are unavailable on this installation.</h2><p class="meta">Bonumark Stream can run normally with a locked-down application tree. The web/PHP process cannot safely replace package-managed software. Keep those permissions locked and run <code>php scripts/deploy-update.php /path/to/release.zip</code> as the application owner when shell access is available; otherwise use the documented hosting-layer/manual deployment workflow.</p><?php if ($installedBlocked): ?><p class="meta"><strong>First blocked path:</strong> <code><?= htmlspecialchars((string)($installedBlocked[0]['relative_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code><?php if (count($installedBlocked) > 1): ?> (+<?= count($installedBlocked) - 1 ?> more)<?php endif; ?></p><?php endif; ?></div><span class="operation-risk-label is-warning">Owner-run CLI available</span></div></section>
<?php endif; ?>

<div class="operations-workflow-grid">
  <div class="operations-workflow-main">
    <section class="panel operations-review-panel upgrade-upload-panel">
      <div class="operations-panel-heading"><div><p class="eyebrow">Step 1</p><h2>Upload and check a release ZIP</h2><p class="meta">The package is staged and validated before the Run Upgrade action is available.</p></div><span class="operation-risk-label is-safe">Precheck only</span></div>
      <form method="post" enctype="multipart/form-data" class="operations-workflow-main upgrade-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <label class="operations-upload-card" for="upgrade_zip"><strong>Bonumark Stream release ZIP</strong><input id="upgrade_zip" type="file" name="upgrade_zip" accept=".zip,application/zip" required><small class="meta">Upload only a release package you created or trust.</small></label>
        <div class="operations-form-actions"><button type="submit">Upload and Check Package</button></div>
      </form>
    </section>

    <?php if ($precheck): ?>
    <section class="panel operations-danger-zone upgrade-check-panel">
      <div class="operations-panel-heading"><div><p class="eyebrow">Step 2 · Upgrade check</p><h2><?= !empty($precheck['recovery_resume']) ? 'Ready to resume recovery for' : 'Ready to upgrade from v' . htmlspecialchars((string)$precheck['current_version'], ENT_QUOTES, 'UTF-8') . ' to' ?> v<?= htmlspecialchars((string)$precheck['package_version'], ENT_QUOTES, 'UTF-8') ?></h2><p class="meta"><?= !empty($precheck['recovery_resume']) ? 'This exact package matches the recorded recovery state.' : 'The package passed validation. Review backup and migration state before running it.' ?></p><?php if (!empty($precheck['release_notes'])): ?><p class="meta"><strong>Release notes:</strong> <?= htmlspecialchars((string)$precheck['release_notes'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?></div><span class="operation-risk-label is-destructive">Final confirmation</span></div>
      <?php $precheckAutomatic = !empty($precheck['automatic_upgrade']['available']); $precheckBlocked = $precheck['automatic_upgrade']['blocked'] ?? []; ?>
      <div class="upgrade-status-grid"><div class="upgrade-status-card pass"><span>Current version</span><strong>v<?= htmlspecialchars((string)$precheck['current_version'], ENT_QUOTES, 'UTF-8') ?></strong></div><div class="upgrade-status-card pass"><span>Uploaded version</span><strong>v<?= htmlspecialchars((string)$precheck['package_version'], ENT_QUOTES, 'UTF-8') ?></strong></div><div class="upgrade-status-card <?= !empty($precheck['backup_ready']) ? 'pass' : 'fail' ?>"><span>Backup status</span><strong><?= !empty($precheck['backup_ready']) ? 'Ready' : 'Not writable' ?></strong></div><div class="upgrade-status-card <?= $precheckAutomatic ? 'pass' : 'fail' ?>"><span>Software write access</span><strong><?= $precheckAutomatic ? 'Ready' : 'Manual required' ?></strong></div><div class="upgrade-status-card pass"><span>Migration status</span><strong><?= count($precheck['pending_migrations'] ?? []) ?> pending</strong></div><div class="upgrade-status-card pass"><span>Public output</span><strong><?= (int)($precheck['published_count'] ?? 0) ?> post(s)</strong></div></div>
      <details class="upgrade-details upgrade-precheck-details"><summary>Advanced package and migration details</summary><div><p><strong>Uploaded package:</strong> <?= htmlspecialchars((string)$precheck['uploaded_name'], ENT_QUOTES, 'UTF-8') ?></p><?php if (!$precheckAutomatic && $precheckBlocked): ?><p><strong>Automatic-upgrade blocker:</strong> <code><?= htmlspecialchars((string)($precheckBlocked[0]['relative_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code><?php if (count($precheckBlocked) > 1): ?> (+<?= count($precheckBlocked) - 1 ?> more)<?php endif; ?></p><?php endif; ?><?php if (!empty($precheck['pending_migrations'])): ?><div class="upgrade-migrations-list"><h3>Pending migrations</h3><ul><?php foreach ($precheck['pending_migrations'] as $migration): ?><li><code><?= htmlspecialchars((string)$migration, ENT_QUOTES, 'UTF-8') ?></code></li><?php endforeach; ?></ul></div><?php else: ?><p class="meta">No database migrations appear pending.</p><?php endif; ?><p>Running the upgrade creates a backup, replaces package-managed software, removes obsolete package files, preserves config and runtime data, then runs migrations.</p></div></details>
      <form method="post" class="operations-form-actions upgrade-confirm-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit" name="confirm_upgrade" value="1" class="danger-button" <?= empty($precheck['backup_ready']) || !$precheckAutomatic ? 'disabled' : '' ?>>Run Upgrade</button><button type="submit" name="cancel_upgrade" value="1" class="secondary-button">Cancel Package</button></form>
      <?php $upgradeBlocked = empty($precheck['backup_ready']) || !$precheckAutomatic; ?>
      <p class="field-help <?= $upgradeBlocked ? 'warning-text' : '' ?>"><?= empty($precheck['backup_ready']) ? 'Upgrade is blocked until the backup folder is writable.' : (!$precheckAutomatic ? 'The package is valid, but web-based upgrade is unavailable because PHP cannot safely replace application software. Use the owner-run CLI upgrade as the application owner, or the documented manual/hosting-layer workflow when shell access is unavailable.' : 'A backup will be created before software files are replaced.') ?></p>
    </section>
    <?php endif; ?>
  </div>
  <aside class="operations-workflow-rail is-sticky">
    <section class="panel operations-panel"><div class="operations-panel-heading"><div><p class="eyebrow">What is protected</p><h2>Runtime and owner data</h2></div></div><dl class="operations-fact-list"><div><dt>Protected</dt><dd>Config, install lock, runtime data, backups, uploads, media, and custom themes.</dd></div><div><dt>Replaced</dt><dd>Package-managed PHP, assets, docs, migrations, bundled themes, and version markers.</dd></div><div><dt>Before migrations</dt><dd>Software-copy failures restore the previous files.</dd></div><div><dt>After migrations begin</dt><dd>The newer files remain and the same package can be retried safely.</dd></div></dl></section>
    <section class="panel operations-danger-zone"><div class="operations-panel-heading"><div><p class="eyebrow">Trust boundary</p><h2>Release ZIPs contain executable PHP.</h2><p class="meta">Manifest and path validation cannot make a malicious trusted-admin package safe.</p></div></div></section>
  </aside>
  <section class="panel operations-panel operations-workflow-history"><div class="operations-record-heading"><div><p class="eyebrow">History</p><h2>Recent upgrade attempts</h2><p class="meta">Recorded software updates and outcomes.</p></div><span class="static-pill draft"><?= count($upgradeHistory) ?> RECORD<?= count($upgradeHistory) === 1 ? '' : 'S' ?></span></div><?php if (!$upgradeHistory): ?><div class="operations-empty-state"><h3>No upgrade history recorded.</h3><p class="meta">Completed or failed upgrade attempts will appear here.</p></div><?php else: ?><div class="operations-record-header operations-upgrade-record"><span>Version change</span><span>Status</span><span>Ran</span></div><div class="operations-record-list"><?php foreach ($upgradeHistory as $row): ?><article class="operations-record operations-upgrade-record"><div class="operations-record-cell is-version-change"><span class="operations-mobile-label">Version change</span><strong><span>v<?= htmlspecialchars((string)$row['from_version'], ENT_QUOTES, 'UTF-8') ?></span><span aria-hidden="true">→</span><span>v<?= htmlspecialchars((string)$row['to_version'], ENT_QUOTES, 'UTF-8') ?></span></strong></div><div class="operations-record-cell"><span class="operations-mobile-label">Status</span><span class="static-pill <?= strtolower((string)$row['status']) === 'completed' ? 'generated' : 'warning' ?>"><?= htmlspecialchars(strtoupper((string)$row['status']), ENT_QUOTES, 'UTF-8') ?></span></div><div class="operations-record-cell"><span class="operations-mobile-label">Ran</span><?= htmlspecialchars((string)$row['ran_at'], ENT_QUOTES, 'UTF-8') ?></div></article><?php endforeach; ?></div><?php endif; ?></section>
</div>

<?php bms_admin_footer(); ?>
