<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/appearance.php';
require_once __DIR__ . '/../_bonumark_stream/app/media.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_appearance');

function bms_site_identity_favicon_media_options(): array
{
    $items = function_exists('bms_media_list') ? bms_media_list(200, '', 'active') : [];
    return array_values(array_filter($items, static function (array $media): bool {
        return function_exists('bms_site_favicon_is_image') && bms_site_favicon_is_image($media);
    }));
}

function bms_site_identity_uploaded_favicon_present(array $file): bool
{
    return isset($file['error']) && (int)$file['error'] !== UPLOAD_ERR_NO_FILE;
}

function bms_site_identity_validate_favicon_upload_name(array $file): void
{
    $name = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        throw new RuntimeException('Favicons must be JPG, PNG, GIF, or WebP images.');
    }
}

function bms_site_identity_store_favicon_media(array $media): void
{
    if (!function_exists('bms_site_favicon_is_image') || !bms_site_favicon_is_image($media)) {
        throw new RuntimeException('Choose an active image from the Media Library.');
    }
    bms_set_setting('site_favicon_media_id', (string)(int)($media['id'] ?? 0));
    bms_set_setting('site_favicon_path', (string)($media['public_path'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $siteName = trim((string)($_POST['site_name'] ?? 'Bonumark Stream'));
    $tagline = bms_sanitize_site_identity_html((string)($_POST['site_tagline'] ?? ''));
    $eyebrow = trim((string)($_POST['homepage_eyebrow'] ?? ''));
    $footer = bms_sanitize_site_identity_html((string)($_POST['site_footer_text'] ?? ''));
    $powered = isset($_POST['show_powered_by']) ? '1' : '0';
    $faviconAction = (string)($_POST['favicon_action'] ?? 'keep');
    if ($siteName === '') {
        $siteName = 'Bonumark Stream';
    }
    if ($eyebrow === '') {
        $eyebrow = 'Own your short-form publishing';
    }
    try {
        bms_set_setting('site_name', $siteName);
        bms_set_setting('site_tagline', $tagline);
        bms_set_setting('homepage_eyebrow', $eyebrow);
        bms_set_setting('site_footer_text', $footer);
        bms_set_setting('show_powered_by', $powered);

        $faviconMessage = '';
        $uploadedFile = is_array($_FILES['site_favicon_file'] ?? null) ? $_FILES['site_favicon_file'] : [];
        if ($uploadedFile && bms_site_identity_uploaded_favicon_present($uploadedFile)) {
            bms_site_identity_validate_favicon_upload_name($uploadedFile);
            $uploadedMedia = bms_media_upload($uploadedFile, 'Site favicon', 'Site favicon used for browser tabs, saved bookmarks, and installed app icons.', ['generate_derivatives' => false]);
            bms_site_identity_store_favicon_media($uploadedMedia);
            $faviconMessage = ' Favicon uploaded.';
            $faviconView = bms_site_favicon_view_data();
            if (!empty($faviconView['id']) && empty($faviconView['is_square'])) {
                bms_flash('Favicon saved. For best browser and mobile results, use a square image.', 'info');
            }
        } elseif ($faviconAction === 'upload') {
            throw new RuntimeException('Choose a favicon image to upload, or select Keep current favicon.');
        } elseif ($faviconAction === 'remove') {
            bms_set_setting('site_favicon_media_id', '0');
            bms_set_setting('site_favicon_path', '');
            $faviconMessage = ' Favicon removed.';
        } elseif ($faviconAction === 'select') {
            $selectedId = max(0, (int)($_POST['site_favicon_media_id'] ?? 0));
            if ($selectedId <= 0) {
                throw new RuntimeException('Choose an image from the Media Library or select Keep current favicon.');
            }
            $selectedMedia = bms_media_find($selectedId);
            if (!is_array($selectedMedia)) {
                throw new RuntimeException('The selected favicon image could not be found.');
            }
            bms_site_identity_store_favicon_media($selectedMedia);
            $faviconMessage = ' Favicon selected.';
            $faviconView = bms_site_favicon_view_data();
            if (!empty($faviconView['id']) && empty($faviconView['is_square'])) {
                bms_flash('Favicon saved. For best browser and mobile results, use a square image.', 'info');
            }
        }

        bms_flash('Site identity saved. Dynamic public routes and admin pages use the updated identity immediately.' . $faviconMessage, 'success');
        bms_redirect(bms_admin_url('site-identity.php'));
    } catch (Throwable $e) {
        bms_log_admin_exception('site-identity', $e);

        bms_flash('Could not save site identity. Please try again.', 'error');
    }
}

$currentFavicon = function_exists('bms_site_favicon_view_data') ? bms_site_favicon_view_data() : [];
$currentFaviconMedia = is_array($currentFavicon['media'] ?? null) ? $currentFavicon['media'] : null;
$faviconOptions = bms_site_identity_favicon_media_options();
$currentFaviconId = (int)($currentFavicon['id'] ?? 0);

$siteNameValue = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
$siteTaglineValue = (string)bms_setting_or_config('site_tagline', '');
$homepageEyebrowValue = bms_homepage_eyebrow();
$siteFooterValue = bms_site_footer_text();
$poweredByEnabled = bms_show_powered_by();
$faviconReady = (string)($currentFavicon['url'] ?? '') !== '';

bms_admin_header('Site Identity', [
    ['label' => 'Themes', 'href' => bms_admin_url('theme.php'), 'style' => 'secondary'],
    ['label' => 'View Site', 'href' => bms_url_path(), 'style' => 'secondary', 'target' => true],
]);
?>
<section class="panel appearance-hero-panel">
  <div class="appearance-hero-copy">
    <p class="eyebrow">Site design</p>
    <h2>Set the public identity.</h2>
    <p class="meta">These values frame the public site across the homepage, browser title, header, footer, bookmarks, and installed app experience.</p>
  </div>
  <div class="appearance-hero-actions">
    <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('theme-settings.php'), ENT_QUOTES, 'UTF-8') ?>">Theme Settings</a>
    <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('navigation.php'), ENT_QUOTES, 'UTF-8') ?>">Navigation</a>
  </div>
</section>

<section class="panel appearance-summary-panel" aria-label="Site identity summary">
  <div class="appearance-summary-grid">
    <div><span>Site name</span><strong><?= htmlspecialchars($siteNameValue, ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div><span>Tagline</span><strong><?= trim(strip_tags($siteTaglineValue)) !== '' ? 'Set' : 'Not set' ?></strong></div>
    <div><span>Favicon</span><strong><?= $faviconReady ? 'Selected' : 'Default mark' ?></strong></div>
    <div><span>Bonumark credit</span><strong><?= $poweredByEnabled ? 'Shown' : 'Hidden' ?></strong></div>
  </div>
</section>

<form method="post" enctype="multipart/form-data" class="appearance-identity-form">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <div class="appearance-identity-layout">
    <section class="panel appearance-identity-copy-panel">
      <div class="appearance-section-heading">
        <div>
          <p class="eyebrow">Public framing</p>
          <h2>Name and describe the site.</h2>
          <p class="meta">Keep these values broad enough to work in the homepage header, browser chrome, and footer.</p>
        </div>
      </div>

      <div class="appearance-field-list">
        <div class="appearance-field-card">
          <label for="site_name">Site name</label>
          <input type="text" id="site_name" name="site_name" value="<?= htmlspecialchars($siteNameValue, ENT_QUOTES, 'UTF-8') ?>" maxlength="160" required>
          <p class="field-help">The primary public name and browser-title identity.</p>
        </div>
        <div class="appearance-field-card">
          <label for="site_tagline">Tagline</label>
          <input type="text" id="site_tagline" name="site_tagline" value="<?= htmlspecialchars($siteTaglineValue, ENT_QUOTES, 'UTF-8') ?>" maxlength="500">
          <p class="field-help">Optional public description. Plain text and safe links are allowed.</p>
        </div>
        <div class="appearance-field-card">
          <label for="homepage_eyebrow">Homepage label</label>
          <input type="text" id="homepage_eyebrow" name="homepage_eyebrow" value="<?= htmlspecialchars($homepageEyebrowValue, ENT_QUOTES, 'UTF-8') ?>" maxlength="120">
          <p class="field-help">Short framing text shown above the main homepage heading.</p>
        </div>
        <div class="appearance-field-card">
          <label for="site_footer_text">Footer text</label>
          <input type="text" id="site_footer_text" name="site_footer_text" value="<?= htmlspecialchars($siteFooterValue, ENT_QUOTES, 'UTF-8') ?>" maxlength="500" placeholder="Optional footer line">
          <p class="field-help">Optional footer copy. Plain text and safe links are allowed.</p>
        </div>
      </div>

      <label class="appearance-toggle-card appearance-credit-toggle">
        <input type="checkbox" name="show_powered_by" value="1" <?= $poweredByEnabled ? 'checked' : '' ?>>
        <span><strong>Show Bonumark publishing credit</strong><small>Add “Published with Bonumark Stream.” to the public footer.</small></span>
      </label>
    </section>

    <aside class="panel appearance-favicon-panel">
      <div class="appearance-section-heading">
        <div>
          <p class="eyebrow">Site icon</p>
          <h2>Browser and app identity.</h2>
          <p class="meta">Use one clear square image for browser tabs, bookmarks, Apple touch icons, and installed app icons.</p>
        </div>
      </div>

      <div class="appearance-favicon-current">
        <?php if ($faviconReady): ?>
          <img src="<?= htmlspecialchars((string)$currentFavicon['url'], ENT_QUOTES, 'UTF-8') ?>" alt="Current favicon preview" width="96" height="96">
        <?php else: ?>
          <span class="appearance-favicon-fallback" aria-hidden="true">B</span>
        <?php endif; ?>
        <div>
          <span>Current favicon</span>
          <strong><?= htmlspecialchars((string)($currentFaviconMedia['original_filename'] ?? $currentFaviconMedia['filename'] ?? ($faviconReady ? 'Selected image' : 'Default Bonumark mark')), ENT_QUOTES, 'UTF-8') ?></strong>
          <?php if (!empty($currentFavicon['width']) && !empty($currentFavicon['height'])): ?>
            <small><?= (int)$currentFavicon['width'] ?> × <?= (int)$currentFavicon['height'] ?><?= !empty($currentFavicon['is_square']) ? ' · square' : ' · not square' ?></small>
          <?php endif; ?>
        </div>
      </div>

      <div class="appearance-field-list appearance-favicon-controls">
        <div class="appearance-field-card">
          <label for="favicon_action">Favicon action</label>
          <select id="favicon_action" name="favicon_action">
            <option value="keep" selected>Keep current favicon</option>
            <option value="select">Use a Media Library image</option>
            <option value="upload">Upload a new image</option>
            <option value="remove">Remove favicon</option>
          </select>
        </div>
        <div class="appearance-field-card">
          <label for="site_favicon_media_id">Media Library image</label>
          <select id="site_favicon_media_id" name="site_favicon_media_id">
            <option value="0">Choose an image</option>
            <?php foreach ($faviconOptions as $media): ?>
              <?php
                $mediaId = (int)($media['id'] ?? 0);
                $label = trim((string)($media['original_filename'] ?? $media['filename'] ?? ('Media #' . $mediaId)));
                $width = (int)($media['width'] ?? 0);
                $height = (int)($media['height'] ?? 0);
                if ($width > 0 && $height > 0) { $label .= ' (' . $width . ' × ' . $height . ')'; }
              ?>
              <option value="<?= $mediaId ?>" <?= $mediaId === $currentFaviconId ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="appearance-field-card">
          <label for="site_favicon_file">Upload favicon image</label>
          <input id="site_favicon_file" type="file" name="site_favicon_file" accept="image/jpeg,image/png,image/gif,image/webp">
          <p class="field-help">JPG, PNG, GIF, or WebP. Use a square image, preferably at least 512 × 512.</p>
        </div>
      </div>
    </aside>
  </div>

  <div class="panel appearance-save-bar">
    <div><strong>Save public identity</strong><span>Dynamic routes and admin chrome update immediately.</span></div>
    <button type="submit">Save Site Identity</button>
  </div>
</form>
<?php bms_admin_footer(); ?>
