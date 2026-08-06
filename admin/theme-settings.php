<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/appearance.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

$packages = function_exists('bms_public_theme_packages') ? bms_public_theme_packages() : ['default' => ['slug' => 'default', 'name' => 'Midnight Ledger', 'description' => 'The default Bonumark Stream public theme.', 'settings' => []]];
$activePackage = function_exists('bms_active_public_theme_slug') ? bms_active_public_theme_slug() : 'default';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();

    $postedPackage = function_exists('bms_theme_slug') ? bms_theme_slug((string)($_POST['active_public_theme'] ?? $activePackage)) : 'default';
    if (!isset($packages[$postedPackage])) {
        $postedPackage = 'default';
    }

    $selectedHealth = is_array($packages[$postedPackage]['health'] ?? null) ? $packages[$postedPackage]['health'] : (function_exists('bms_public_theme_package_health') ? bms_public_theme_package_health($packages[$postedPackage]) : ['valid' => true]);
    if (empty($selectedHealth['valid'])) {
        $message = function_exists('bms_public_theme_activation_error') ? bms_public_theme_activation_error($packages[$postedPackage]) : 'The selected theme is not safe to activate.';
        bms_flash($message, 'error');
        bms_redirect(bms_admin_url('theme-settings.php'));
    }

    $rawThemeSettings = is_array($_POST['theme_settings'] ?? null) ? $_POST['theme_settings'] : [];

    try {
        bms_set_setting('active_public_theme', $postedPackage);
        if (function_exists('bms_save_public_theme_settings')) {
            bms_save_public_theme_settings($postedPackage, $rawThemeSettings);
        }
        bms_flash('Theme settings saved. Dynamic public routes use the updated theme values immediately.', 'success');
        bms_redirect(bms_admin_url('theme-settings.php'));
    } catch (Throwable $e) {
        bms_log_admin_exception('theme-settings', $e);

        bms_flash('Could not save theme settings. Please try again.', 'error');
    }

    $activePackage = $postedPackage;
}

$activeTheme = $packages[$activePackage] ?? $packages['default'];
$activeSettings = function_exists('bms_public_theme_settings') ? bms_public_theme_settings($activePackage) : [];
$settingsSchema = function_exists('bms_public_theme_settings_schema') ? bms_public_theme_settings_schema($activePackage) : [];
$screenshotUrl = function_exists('bms_public_theme_screenshot_url') ? bms_public_theme_screenshot_url($activeTheme) : '';
$activeHealth = is_array($activeTheme['health'] ?? null) ? $activeTheme['health'] : (function_exists('bms_public_theme_package_health') ? bms_public_theme_package_health($activeTheme) : ['valid' => true, 'label' => 'Safe to activate', 'errors' => [], 'warnings' => []]);

bms_admin_header('Theme Settings', [
    ['label' => 'Themes', 'href' => bms_admin_url('theme.php'), 'style' => 'secondary'],
    ['label' => 'View Site', 'href' => bms_url_path(), 'style' => 'secondary', 'target' => true],
]);
?>
<section class="panel appearance-hero-panel appearance-theme-settings-hero">
  <div class="appearance-hero-copy">
    <p class="eyebrow">Active theme</p>
    <h2>Configure <?= htmlspecialchars((string)($activeTheme['name'] ?? 'the active theme'), ENT_QUOTES, 'UTF-8') ?>.</h2>
    <p class="meta">This screen edits the settings exposed by the active theme. Activate a different design from Themes before configuring it.</p>
  </div>
  <span class="status-pill <?= !empty($activeHealth['valid']) ? 'published' : 'trash' ?>"><?= htmlspecialchars((string)($activeHealth['label'] ?? 'Theme health'), ENT_QUOTES, 'UTF-8') ?></span>
</section>

<form method="post" class="appearance-theme-settings-form">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="active_public_theme" value="<?= htmlspecialchars($activePackage, ENT_QUOTES, 'UTF-8') ?>">

  <div class="appearance-settings-layout">
    <aside class="panel appearance-active-theme-panel">
      <div class="appearance-active-theme-preview">
        <?php if ($screenshotUrl !== ''): ?>
          <img src="<?= htmlspecialchars($screenshotUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($activeTheme['name'] ?? 'Active theme'), ENT_QUOTES, 'UTF-8') ?> screenshot">
        <?php else: ?>
          <div class="appearance-theme-preview-empty"><span>No screenshot</span></div>
        <?php endif; ?>
      </div>
      <div class="appearance-active-theme-copy">
        <p class="eyebrow">Current design</p>
        <h2><?= htmlspecialchars((string)($activeTheme['name'] ?? 'Midnight Ledger'), ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="meta"><?= htmlspecialchars((string)($activeTheme['description'] ?? 'A Bonumark Stream public theme.'), ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <dl class="appearance-theme-facts appearance-active-theme-facts">
        <div><dt>Slug</dt><dd><code><?= htmlspecialchars((string)($activeTheme['slug'] ?? $activePackage), ENT_QUOTES, 'UTF-8') ?></code></dd></div>
        <div><dt>Version</dt><dd><?= htmlspecialchars((string)($activeTheme['version'] ?? '1.0.0'), ENT_QUOTES, 'UTF-8') ?></dd></div>
        <div><dt>Author</dt><dd><?= htmlspecialchars((string)($activeTheme['author'] ?? 'Bonumark'), ENT_QUOTES, 'UTF-8') ?></dd></div>
        <div><dt>Fields</dt><dd><?= count($settingsSchema) ?></dd></div>
      </dl>
      <a class="button-link secondary appearance-full-action" href="<?= htmlspecialchars(bms_admin_url('theme.php'), ENT_QUOTES, 'UTF-8') ?>">Choose Another Theme</a>
    </aside>

    <section class="panel appearance-theme-fields-panel">
      <div class="appearance-section-heading">
        <div>
          <p class="eyebrow">Theme values</p>
          <h2>Presentation settings</h2>
          <p class="meta">Changes apply immediately to dynamic public routes after saving.</p>
        </div>
        <button type="submit">Save Theme Settings</button>
      </div>

      <?php if (!$settingsSchema): ?>
        <div class="empty-state appearance-empty-state">
          <h3>No editable settings.</h3>
          <p class="meta">This theme provides a fixed presentation and does not expose configuration fields.</p>
        </div>
      <?php else: ?>
        <div class="appearance-setting-list">
          <?php foreach ($settingsSchema as $key => $setting): ?>
            <?php
              $type = (string)($setting['type'] ?? 'text');
              $value = (string)($activeSettings[$key] ?? $setting['default'] ?? '');
              $fieldId = 'theme_setting_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $key);
              $label = (string)($setting['label'] ?? $key);
              $description = (string)($setting['description'] ?? '');
            ?>
            <div class="appearance-setting-card appearance-setting-card-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
              <div class="appearance-setting-copy">
                <label for="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></label>
                <?php if ($description !== ''): ?><p class="field-help"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
              </div>
              <div class="appearance-setting-control">
                <?php if ($type === 'checkbox'): ?>
                  <input type="hidden" name="theme_settings[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]" value="0">
                  <label class="appearance-toggle-card" for="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>">
                    <input id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>" type="checkbox" name="theme_settings[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]" value="1" <?= $value === '1' ? 'checked' : '' ?>>
                    <span><?= $value === '1' ? 'Enabled' : 'Enable' ?></span>
                  </label>
                <?php elseif ($type === 'select'): ?>
                  <select id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>" name="theme_settings[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]">
                    <?php foreach ((array)($setting['options'] ?? []) as $optionValue => $optionLabel): ?>
                      <option value="<?= htmlspecialchars((string)$optionValue, ENT_QUOTES, 'UTF-8') ?>" <?= (string)$optionValue === $value ? 'selected' : '' ?>><?= htmlspecialchars((string)$optionLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php elseif ($type === 'textarea'): ?>
                  <textarea id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>" name="theme_settings[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]" rows="4"><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></textarea>
                <?php else: ?>
                  <input id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>" type="text" name="theme_settings[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="appearance-form-actions">
        <button type="submit">Save Theme Settings</button>
        <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('theme.php'), ENT_QUOTES, 'UTF-8') ?>">Back to Themes</a>
      </div>
    </section>
  </div>
</form>
<?php bms_admin_footer(); ?>
