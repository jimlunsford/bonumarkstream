<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();

$timezones = DateTimeZone::listIdentifiers();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();
    $siteName = trim((string)($_POST['site_name'] ?? 'Bonumark Stream'));
    $tagline = mp_sanitize_site_identity_html((string)($_POST['site_tagline'] ?? ''));
    $timezone = (string)($_POST['timezone'] ?? 'UTC');
    $adminEmail = trim((string)($_POST['site_admin_email'] ?? ''));
    if ($siteName === '') {
        $siteName = 'Bonumark Stream';
    }
    if (!in_array($timezone, $timezones, true)) {
        $timezone = 'UTC';
    }
    if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        mp_flash('Enter a valid admin email address or leave it blank.', 'error');
        mp_redirect(mp_admin_url('settings.php'));
    }
    try {
        mp_set_setting('site_name', $siteName);
        mp_set_setting('site_tagline', $tagline);
        mp_set_setting('timezone', $timezone);
        mp_set_setting('site_admin_email', $adminEmail);
        mp_flash('General settings saved. Site identity updates are active.', 'success');
        mp_redirect(mp_admin_url('settings.php'));
    } catch (Throwable $e) {
        mp_flash('Could not save settings: ' . $e->getMessage(), 'error');
    }
}

$config = mp_config();
$siteName = (string)mp_setting_or_config('site_name', 'Bonumark Stream');
$tagline = (string)mp_setting_or_config('site_tagline', 'A self-hosted microblog stream for owning short-form publishing.');
$timezone = (string)mp_setting_or_config('timezone', 'UTC');
$adminEmail = (string)mp_setting_or_config('site_admin_email', (string)(mp_current_user()['email'] ?? ''));
$baseUrl = (string)mp_setting_or_config('base_url', '');
$basePath = (string)mp_setting_or_config('base_path', '');
mp_admin_header('General Settings', []);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Settings</p>
  <h2>General</h2>
  <p class="meta">Set the public identity of the site. Bonumark Stream keeps advanced paths detected from the install unless you adjust the config directly.</p>
</section>

<section class="panel settings-panel">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
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
<?php mp_admin_footer(); ?>
