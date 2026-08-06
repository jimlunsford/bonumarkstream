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

bms_admin_header('General Settings', [
    ['label' => 'Site Identity', 'href' => bms_admin_url('site-identity.php'), 'style' => 'secondary'],
    ['label' => 'View Site', 'href' => bms_url_path(), 'style' => 'secondary', 'target' => true],
]);
?>
<section class="panel settings-workflow-hero">
  <div class="settings-workflow-hero-copy">
    <p class="eyebrow">Settings</p>
    <h2>Set the site-wide defaults.</h2>
    <p class="meta">General Settings controls the administrative identity, contact address, and site timezone. Public theme framing and favicon management remain in Site Identity.</p>
  </div>
  <span class="static-pill generated">SITE CONFIGURATION</span>
</section>

<section class="panel settings-summary-panel">
  <div class="settings-summary-grid">
    <div><span>Site name</span><strong><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div><span>Timezone</span><strong><?= htmlspecialchars($timezone, ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div><span>Admin email</span><strong><?= htmlspecialchars($adminEmail !== '' ? $adminEmail : 'Not set', ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div><span>Install path</span><strong><?= htmlspecialchars($basePath !== '' ? $basePath : '/', ENT_QUOTES, 'UTF-8') ?></strong></div>
  </div>
</section>

<div class="settings-workflow-grid">
  <section class="panel settings-section-panel">
    <div class="settings-section-header">
      <div><p class="eyebrow">General</p><h2>Core site settings</h2><p class="meta">These values are shared across the Admin, public routes, email, timestamps, and generated metadata.</p></div>
    </div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <div class="settings-field-grid is-single">
        <div class="settings-field-card">
          <label for="site_name">Site name</label>
          <input type="text" id="site_name" name="site_name" value="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>" maxlength="160" required>
          <p class="field-help">Used as the main administrative and public site name.</p>
        </div>
        <div class="settings-field-card">
          <label for="site_tagline">Tagline</label>
          <input type="text" id="site_tagline" name="site_tagline" value="<?= htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8') ?>" maxlength="500">
          <p class="field-help">Plain text and safe links are allowed.</p>
        </div>
      </div>
      <div class="settings-field-grid">
        <div class="settings-field-card">
          <label for="site_admin_email">Admin email</label>
          <input type="email" id="site_admin_email" name="site_admin_email" value="<?= htmlspecialchars($adminEmail, ENT_QUOTES, 'UTF-8') ?>" maxlength="190">
          <p class="field-help">Used as the default administrative contact and Mail fallback address.</p>
        </div>
        <div class="settings-field-card">
          <label for="timezone">Timezone</label>
          <select id="timezone" name="timezone">
            <?php foreach ($timezones as $tz): ?>
              <option value="<?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>" <?= $tz === $timezone ? 'selected' : '' ?>><?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
          <p class="field-help">Public dates, scheduled content, and administrative timestamps use this timezone.</p>
        </div>
      </div>
      <div class="settings-save-bar">
        <div><strong>Save general settings</strong><p class="meta">Timezone changes become active immediately.</p></div>
        <button type="submit">Save General Settings</button>
      </div>
    </form>
  </section>

  <aside class="settings-workflow-rail is-sticky">
    <section class="panel settings-section-panel">
      <p class="eyebrow">Installation</p>
      <h2>Detected paths</h2>
      <p class="meta">These values come from the installation and remain read-only here.</p>
      <dl class="settings-fact-list">
        <div><dt>Site URL</dt><dd><code class="settings-technical-value"><?= htmlspecialchars($baseUrl !== '' ? $baseUrl : 'Auto-detected during install', ENT_QUOTES, 'UTF-8') ?></code></dd></div>
        <div><dt>Base path</dt><dd><code class="settings-technical-value"><?= htmlspecialchars($basePath !== '' ? $basePath : '/', ENT_QUOTES, 'UTF-8') ?></code></dd></div>
      </dl>
    </section>
  </aside>
</div>
<?php bms_admin_footer(); ?>
