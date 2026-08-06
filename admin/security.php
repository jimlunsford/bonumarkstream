<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/api.php';
require_once __DIR__ . '/../_bonumark_stream/app/registration.php';
require_once __DIR__ . '/../_bonumark_stream/app/password-recovery.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_settings');

$baseUrl = (string)bms_setting_or_config('base_url', '');
$httpsEnabled = str_starts_with(strtolower($baseUrl), 'https://');
$currentUser = bms_current_user();
$username = (string)($currentUser['username'] ?? 'admin');
$registrationMode = bms_registration_mode();
$registrationLabel = bms_registration_modes()[$registrationMode] ?? ucfirst($registrationMode);
$remoteEnabled = (string)bms_setting_or_config('remote_posting_enabled', '0') === '1';
$tokens = bms_api_list_tokens();
$activeTokens = count(array_filter($tokens, static function (array $token): bool {
    if ((string)($token['status'] ?? '') !== 'active') {
        return false;
    }
    $expiresAt = trim((string)($token['expires_at'] ?? ''));
    $expiresTimestamp = $expiresAt !== '' ? strtotime($expiresAt) : false;
    return $expiresTimestamp === false || $expiresTimestamp >= time();
}));
$rememberLoginEnabled = function_exists('bms_remember_login_enabled') ? bms_remember_login_enabled() : true;
$mailRecoveryReady = bms_password_recovery_mail_ready();

bms_admin_header('Security', [
    ['label' => 'Tools', 'href' => bms_admin_url('tools.php'), 'style' => 'secondary'],
    ['label' => 'Run System Check', 'href' => bms_admin_url('system-check.php'), 'style' => 'secondary'],
    ['label' => 'Edit Account', 'href' => bms_admin_url('user-edit.php?id=' . (int)($currentUser['id'] ?? 0)), 'style' => 'secondary'],
]);
?>
<section class="panel settings-workflow-hero">
  <div class="settings-workflow-hero-copy">
    <p class="eyebrow">Security operations</p>
    <h2>Review the controls that protect access.</h2>
    <p class="meta">Bonumark Stream keeps security decisions in the workflow where they belong. This page brings those controls together without duplicating passwords, tokens, registration rules, or hosting checks.</p>
  </div>
  <span class="static-pill <?= $httpsEnabled ? 'generated' : 'warning' ?>"><?= $httpsEnabled ? 'HTTPS' : 'CHECK HTTPS' ?></span>
</section>

<section class="panel settings-summary-panel">
  <div class="settings-summary-grid">
    <div><span>Site transport</span><strong><?= $httpsEnabled ? 'HTTPS' : 'Not confirmed' ?></strong></div>
    <div><span>Admin account</span><strong>@<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div><span>Public registration</span><strong><?= htmlspecialchars($registrationLabel, ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div><span>Remote API</span><strong><?= $remoteEnabled ? 'Enabled, ' . $activeTokens . ' active token' . ($activeTokens === 1 ? '' : 's') : 'Disabled' ?></strong></div>
  </div>
</section>

<?php if (!$httpsEnabled): ?>
<section class="panel settings-attention-panel is-danger">
  <p class="eyebrow">Needs review</p>
  <h2>HTTPS was not confirmed from the configured site URL.</h2>
  <p class="meta">Use the hosting System Check to verify HTTPS, private storage protection, file permissions, and server readiness before exposing account or API features publicly.</p>
  <div class="settings-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('system-check.php'), ENT_QUOTES, 'UTF-8') ?>">Run System Check</a></div>
</section>
<?php endif; ?>

<section class="settings-security-grid">
  <div class="settings-security-card">
    <p class="eyebrow">Hosting</p><h3>System and private storage</h3>
    <p>Check HTTPS, private directories, media support, upgrade readiness, and hosting configuration in one diagnostic screen.</p>
    <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('system-check.php'), ENT_QUOTES, 'UTF-8') ?>">Open System Check</a>
  </div>
  <div class="settings-security-card">
    <p class="eyebrow">Admin access</p><h3>Account and password</h3>
    <p>The installer-created admin account controls publishing and site settings. Update its email, profile visibility, or password from Account management.</p>
    <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('user-edit.php?id=' . (int)($currentUser['id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>">Manage Admin Account</a>
  </div>
  <div class="settings-security-card">
    <p class="eyebrow">Device access</p><h3>Remembered login</h3>
    <p>Remember this device is currently <strong><?= $rememberLoginEnabled ? 'enabled' : 'disabled' ?></strong>. Device tokens are separate from normal sessions and clear on logout or password changes.</p>
    <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('settings-reading.php'), ENT_QUOTES, 'UTF-8') ?>">Open Reading Settings</a>
  </div>
  <div class="settings-security-card">
    <p class="eyebrow">Public accounts</p><h3>Registration and recovery</h3>
    <p>Registration is <strong><?= htmlspecialchars(strtolower($registrationLabel), ENT_QUOTES, 'UTF-8') ?></strong>. Password recovery mail is <strong><?= $mailRecoveryReady ? 'ready' : 'not ready' ?></strong>.</p>
    <div class="settings-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('registration.php'), ENT_QUOTES, 'UTF-8') ?>">Registration</a><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('mail.php'), ENT_QUOTES, 'UTF-8') ?>">Mail</a></div>
  </div>
  <div class="settings-security-card">
    <p class="eyebrow">Remote access</p><h3>API tokens</h3>
    <p>The Remote API is <strong><?= $remoteEnabled ? 'enabled' : 'disabled' ?></strong> with <strong><?= $activeTokens ?></strong> active token<?= $activeTokens === 1 ? '' : 's' ?>. Tokens are shown only once and stored as hashes.</p>
    <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('remote-posting.php'), ENT_QUOTES, 'UTF-8') ?>">Remote Posting</a>
  </div>
  <div class="settings-security-card">
    <p class="eyebrow">Updates</p><h3>Release and upgrade safety</h3>
    <p>Bonumark release packages are validated before upgrade. Configuration, database records, uploads, and user data remain outside normal software replacement.</p>
    <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('upgrade.php'), ENT_QUOTES, 'UTF-8') ?>">Open Upgrade</a>
  </div>
</section>
<?php bms_admin_footer(); ?>
