<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

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
        bms_set_setting('site_admin_email', $adminEmail);
        bms_flash('General settings saved. Site identity updates are active.', 'success');
        bms_redirect(bms_admin_url('settings.php'));
    } catch (Throwable $e) {
        bms_flash('Could not save settings: ' . $e->getMessage(), 'error');
    }
}

$config = bms_config();
$siteName = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
$tagline = (string)bms_setting_or_config('site_tagline', 'A self-hosted microblog stream for owning short-form publishing.');
$timezone = (string)bms_setting_or_config('timezone', 'UTC');
$adminEmail = (string)bms_setting_or_config('site_admin_email', (string)(bms_current_user()['email'] ?? ''));
$baseUrl = (string)bms_setting_or_config('base_url', '');
$basePath = (string)bms_setting_or_config('base_path', '');
bms_admin_header('General Settings', []);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Settings</p>
  <h2>General</h2>
  <p class="meta">Set the public identity of the site. Bonumark Stream keeps advanced paths detected from the install unless you adjust the config directly.</p>
</section>

<section class="panel settings-panel">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <label for="site_name">Site name</label>
    <input type="text" id="site_name" name="site_name" value="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>" maxlength="160" required>

    <label for="site_tagline">Tagline</label>
    <input type="text" id="site_tagline" name="site_tagline" value="<?= htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8') ?>" maxlength="500">
    <p class="field-help">Plain text and safe links are allowed.</p>

    <label for="site_admin_email">Admin email</label>
    <input type="email" id="site_admin_email" name="site_admin_email" value="<?= htmlspecialchars($adminEmail, ENT_QUOTES, 'UTF-8') ?>" maxlength="190">

    <label for="timezone">Timezone</label>
    <select id="timezone" name="timezone">
      <?php foreach ($timezones as $tz): ?>
        <option value="<?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>" <?= $tz === $timezone ? 'selected' : '' ?>><?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?></option>
      <?php endforeach; ?>
    </select>

    <div class="readonly-settings-grid">
      <div><span>Site URL</span><code><?= htmlspecialchars($baseUrl !== '' ? $baseUrl : 'Auto-detected during install', ENT_QUOTES, 'UTF-8') ?></code></div>
      <div><span>Base Path</span><code><?= htmlspecialchars($basePath !== '' ? $basePath : '/', ENT_QUOTES, 'UTF-8') ?></code></div>
    </div>

    <button type="submit">Save Settings</button>
  </form>
</section>
<?php bms_admin_footer(); ?>
