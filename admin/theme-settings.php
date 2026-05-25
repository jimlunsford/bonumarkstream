<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/appearance.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();

$packages = function_exists('mp_public_theme_packages') ? mp_public_theme_packages() : ['default' => ['slug' => 'default', 'name' => 'Midnight Ledger', 'description' => 'The default Bonumark Stream public theme.', 'settings' => []]];
$activePackage = function_exists('mp_active_public_theme_slug') ? mp_active_public_theme_slug() : 'default';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();

    $postedPackage = function_exists('mp_theme_slug') ? mp_theme_slug((string)($_POST['active_public_theme'] ?? $activePackage)) : 'default';
    if (!isset($packages[$postedPackage])) {
        $postedPackage = 'default';
    }

    $selectedHealth = is_array($packages[$postedPackage]['health'] ?? null) ? $packages[$postedPackage]['health'] : (function_exists('mp_public_theme_package_health') ? mp_public_theme_package_health($packages[$postedPackage]) : ['valid' => true]);
    if (empty($selectedHealth['valid'])) {
        $message = function_exists('mp_public_theme_activation_error') ? mp_public_theme_activation_error($packages[$postedPackage]) : 'The selected theme is not safe to activate.';
        mp_flash($message, 'error');
        mp_redirect(mp_admin_url('theme-settings.php'));
    }

    $rawThemeSettings = is_array($_POST['theme_settings'] ?? null) ? $_POST['theme_settings'] : [];

    try {
        mp_set_setting('active_public_theme', $postedPackage);
        if (function_exists('mp_save_public_theme_settings')) {
            mp_save_public_theme_settings($postedPackage, $rawThemeSettings);
        }
        mp_flash('Theme settings saved. Dynamic public routes use the updated theme values immediately.', 'success');
        mp_redirect(mp_admin_url('theme-settings.php'));
    } catch (Throwable $e) {
        mp_flash('Could not save theme settings: ' . $e->getMessage(), 'error');
    }

    $activePackage = $postedPackage;
}

$activeTheme = $packages[$activePackage] ?? $packages['default'];
$activeSettings = function_exists('mp_public_theme_settings') ? mp_public_theme_settings($activePackage) : [];
$settingsSchema = function_exists('mp_public_theme_settings_schema') ? mp_public_theme_settings_schema($activePackage) : [];
$screenshotUrl = function_exists('mp_public_theme_screenshot_url') ? mp_public_theme_screenshot_url($activeTheme) : '';
$activeHealth = is_array($activeTheme['health'] ?? null) ? $activeTheme['health'] : (function_exists('mp_public_theme_package_health') ? mp_public_theme_package_health($activeTheme) : ['valid' => true, 'label' => 'Safe to activate', 'errors' => [], 'warnings' => []]);

mp_admin_header('Theme Settings', [
    ['label' => 'Themes', 'href' => mp_admin_url('theme.php'), 'style' => 'secondary'],
]);
?>
<p class="meta theme-settings-lead">Adjust the active public theme and the settings declared by that theme.</p>

<form method="post" class="settings-form theme-settings-form theme-settings-wide-form">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

  <div class="theme-settings-workbench">
    <section class="panel theme-settings-theme-panel" aria-label="Active theme summary">
      <div class="theme-settings-preview-frame">
        <?php if ($screenshotUrl !== ''): ?>
          <img class="theme-settings-screenshot" src="<?= htmlspecialchars($screenshotUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($activeTheme['name'] ?? 'Active theme'), ENT_QUOTES, 'UTF-8') ?> screenshot">
        <?php else: ?>
          <div class="theme-preview dark-preview theme-settings-screenshot-fallback" aria-hidden="true"></div>
        <?php endif; ?>
      </div>

      <div class="theme-settings-theme-copy">
        <p class="eyebrow">Active theme</p>
        <h2><?= htmlspecialchars((string)($activeTheme['name'] ?? 'Midnight Ledger'), ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="meta"><?= htmlspecialchars((string)($activeTheme['description'] ?? 'A Bonumark Stream public theme.'), ENT_QUOTES, 'UTF-8') ?></p>
      </div>

      <div class="readonly-settings-grid compact-readonly-grid theme-settings-meta-grid">
        <div><span>Slug</span><code><?= htmlspecialchars((string)($activeTheme['slug'] ?? $activePackage), ENT_QUOTES, 'UTF-8') ?></code></div>
        <div><span>Version</span><code><?= htmlspecialchars((string)($activeTheme['version'] ?? '1.0.0'), ENT_QUOTES, 'UTF-8') ?></code></div>
        <div><span>Author</span><code><?= htmlspecialchars((string)($activeTheme['author'] ?? 'Bonumark'), ENT_QUOTES, 'UTF-8') ?></code></div>
        <div><span>Templates</span><code><?= htmlspecialchars((string)count((array)($activeTheme['templates'] ?? [])), ENT_QUOTES, 'UTF-8') ?></code></div>
        <div><span>Health</span><code><?= htmlspecialchars((string)($activeHealth['label'] ?? 'Safe to activate'), ENT_QUOTES, 'UTF-8') ?></code></div>
      </div>
    </section>

    <section class="panel theme-settings-control-panel" aria-label="Theme settings editor">
      <div class="theme-settings-control-head">
        <div>
          <p class="eyebrow">Settings</p>
          <h2>Active theme settings</h2>
          <p class="meta">Change the active theme or edit the values this theme exposes.</p>
        </div>
        <button type="submit">Save Theme Settings</button>
      </div>

      <div class="theme-settings-switcher">
        <div class="theme-setting-info">
          <label for="active_public_theme">Active public theme</label>
          <p class="field-help">Changing themes updates the database setting. Dynamic public routes use the selected theme immediately.</p>
        </div>
        <div class="theme-setting-control">
          <select id="active_public_theme" name="active_public_theme">
            <?php foreach ($packages as $key => $theme): ?>
              <?php $themeHealth = is_array($theme['health'] ?? null) ? $theme['health'] : (function_exists('mp_public_theme_package_health') ? mp_public_theme_package_health($theme) : ['valid' => true]); ?>
              <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $key === $activePackage ? 'selected' : '' ?> <?= empty($themeHealth['valid']) ? 'disabled' : '' ?>><?= htmlspecialchars((string)($theme['name'] ?? $key), ENT_QUOTES, 'UTF-8') ?>, v<?= htmlspecialchars((string)($theme['version'] ?? '1.0.0'), ENT_QUOTES, 'UTF-8') ?><?= empty($themeHealth['valid']) ? ' (not safe to activate)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <?php if (!$settingsSchema): ?>
        <div class="theme-settings-empty-state">
          <p class="meta">This theme does not expose any editable setting values yet.</p>
        </div>
      <?php else: ?>
        <div class="theme-settings-list">
          <?php foreach ($settingsSchema as $key => $setting): ?>
            <?php
              $type = (string)($setting['type'] ?? 'text');
              $value = (string)($activeSettings[$key] ?? $setting['default'] ?? '');
              $fieldId = 'theme_setting_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $key);
              $label = (string)($setting['label'] ?? $key);
              $description = (string)($setting['description'] ?? '');
            ?>
            <div class="theme-setting-row theme-setting-row-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
              <div class="theme-setting-info">
                <label for="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></label>
                <?php if ($description !== ''): ?><p class="field-help"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
              </div>
              <div class="theme-setting-control">
                <?php if ($type === 'checkbox'): ?>
                  <input type="hidden" name="theme_settings[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]" value="0">
                  <label class="theme-toggle-line" for="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>">
                    <input id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>" type="checkbox" name="theme_settings[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]" value="1" <?= $value === '1' ? 'checked' : '' ?>>
                    <span>Enabled</span>
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

      <div class="form-actions-row theme-settings-bottom-actions">
        <button type="submit">Save Theme Settings</button>
        <a class="button-link" href="<?= htmlspecialchars(mp_admin_url('theme.php'), ENT_QUOTES, 'UTF-8') ?>">Back to Themes</a>
      </div>
    </section>
  </div>
</form>
<?php mp_admin_footer(); ?>
