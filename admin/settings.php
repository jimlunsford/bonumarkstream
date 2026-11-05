<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_settings');

$timezones = DateTimeZone::listIdentifiers();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $siteName = trim((string)($_POST['site_name'] ?? 'Bonumark Stream'));
    $tagline = bms_sanitize_site_identity_html((string)($_POST['site_tagline'] ?? ''));
    $timezone = (string)($_POST['timezone'] ?? 'UTC');
    $adminEmail = trim((string)($_POST['site_admin_email'] ?? ''));
    if ($siteName === '') {
        $siteName = 'Bonumark Stream';
    }
    if (!in_array($timezone, $timezones, true)) {
        $timezone = 'UTC';
    }
    if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        bms_flash('Enter a valid admin email address or leave it blank.', 'error');
        bms_redirect(bms_admin_url('settings.php'));
    }
    try {
        bms_set_setting('site_name', $siteName);
        bms_set_setting('site_tagline', $tagline);
        bms_set_setting('timezone', $timezone);
        if (function_exists('bms_apply_site_timezone')) {
            bms_apply_site_timezone($timezone);
        }
        bms_set_setting('site_admin_email', $adminEmail);
        bms_flash('General settings saved. Site identity and timezone updates are active.', 'success');
        bms_redirect(bms_admin_url('settings.php'));
    } catch (Throwable $e) {
        bms_log_admin_exception('settings', $e);
        bms_flash('Could not save settings. Please try again.', 'error');
    }
}

$siteName = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
$tagline = (string)bms_setting_or_config('site_tagline', 'A self-hosted microblog stream for owning short-form publishing.');
$timezone = (string)bms_setting_or_config('timezone', 'UTC');
$adminEmail = (string)bms_setting_or_config('site_admin_email', (string)(bms_current_user()['email'] ?? ''));
$baseUrl = (string)bms_setting_or_config('base_url', '');
$basePath = (string)bms_setting_or_config('base_path', '');

bms_admin_header('General settings');
?>
<div class="settings-general">
  <form method="post" class="settings-general-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <div class="settings-general-field">
      <label for="site_name">Site name</label>
      <input type="text" id="site_name" name="site_name" value="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>" maxlength="160" required>
    </div>
    <div class="settings-general-field">
      <label for="site_tagline">Tagline</label>
      <input type="text" id="site_tagline" name="site_tagline" value="<?= htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8') ?>" maxlength="500" aria-describedby="site-tagline-help">
      <p class="field-help" id="site-tagline-help">Plain text and safe links are allowed.</p>
    </div>
    <div class="settings-general-field">
      <label for="site_admin_email">Admin email</label>
      <input type="email" id="site_admin_email" name="site_admin_email" value="<?= htmlspecialchars($adminEmail, ENT_QUOTES, 'UTF-8') ?>" maxlength="190" aria-describedby="site-admin-email-help">
      <p class="field-help" id="site-admin-email-help">Default contact and fallback for Mail. Optional.</p>
    </div>
    <div class="settings-general-field">
      <label for="timezone">Timezone</label>
      <select id="timezone" name="timezone" aria-describedby="timezone-identifier timezone-help" data-settings-timezone>
        <?php foreach ($timezones as $tz): ?>
          <option value="<?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>" <?= $tz === $timezone ? 'selected' : '' ?>><?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
      <p class="field-help settings-timezone-identifier" id="timezone-identifier"><span data-settings-timezone-label>Saved timezone: </span><span data-settings-timezone-value><?= htmlspecialchars($timezone, ENT_QUOTES, 'UTF-8') ?></span></p>
      <p class="field-help" id="timezone-help">Used for public dates, scheduled content and Admin timestamps.</p>
    </div>
    <div class="settings-general-actions">
      <p class="settings-timezone-warning">Timezone changes take effect immediately after saving.</p>
      <button type="submit">Save changes</button>
    </div>
  </form>

  <details class="settings-installation-details">
    <summary>Installation details<span>Site URL and base path. Read only.</span></summary>
    <dl>
      <div><dt>Site URL</dt><dd><?= htmlspecialchars($baseUrl !== '' ? $baseUrl : 'Auto-detected during install', ENT_QUOTES, 'UTF-8') ?></dd></div>
      <div><dt>Base path</dt><dd><?= htmlspecialchars($basePath !== '' ? $basePath : '/', ENT_QUOTES, 'UTF-8') ?></dd></div>
    </dl>
  </details>
  <p class="settings-general-identity"><a href="<?= htmlspecialchars(bms_admin_url('site-identity.php'), ENT_QUOTES, 'UTF-8') ?>">Open Site identity</a></p>
</div>
<?php bms_admin_footer(); ?>
