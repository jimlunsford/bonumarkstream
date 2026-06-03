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
            $uploadedMedia = bms_media_upload($uploadedFile, 'Site favicon', 'Site favicon used for browser tabs and saved bookmarks.', ['generate_derivatives' => false]);
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
        bms_flash('Could not save site identity: ' . $e->getMessage(), 'error');
    }
}

$currentFavicon = function_exists('bms_site_favicon_view_data') ? bms_site_favicon_view_data() : [];
$currentFaviconMedia = is_array($currentFavicon['media'] ?? null) ? $currentFavicon['media'] : null;
$faviconOptions = bms_site_identity_favicon_media_options();
$currentFaviconId = (int)($currentFavicon['id'] ?? 0);

bms_admin_header('Site Identity', [
    ['label' => 'Themes', 'href' => bms_admin_url('theme.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Appearance</p>
  <h2>Name the site and set the public framing.</h2>
  <p class="meta">These settings control the public homepage, header, browser title, footer text, and browser tab icon.</p>
</section>

<section class="panel settings-panel site-identity-panel">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <label for="site_name">Site name</label>
    <input type="text" id="site_name" name="site_name" value="<?= htmlspecialchars((string)bms_setting_or_config('site_name', 'Bonumark Stream'), ENT_QUOTES, 'UTF-8') ?>" maxlength="160" required>

    <label for="site_tagline">Tagline</label>
    <input type="text" id="site_tagline" name="site_tagline" value="<?= htmlspecialchars((string)bms_setting_or_config('site_tagline', ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="500">
    <p class="field-help">Plain text and safe links are allowed. Example: &lt;a href=&quot;https://example.com&quot; title=&quot;Visit example&quot;&gt;Example&lt;/a&gt;</p>

    <label for="homepage_eyebrow">Homepage label</label>
    <input type="text" id="homepage_eyebrow" name="homepage_eyebrow" value="<?= htmlspecialchars(bms_homepage_eyebrow(), ENT_QUOTES, 'UTF-8') ?>" maxlength="120">

    <label for="site_footer_text">Footer text</label>
    <input type="text" id="site_footer_text" name="site_footer_text" value="<?= htmlspecialchars(bms_site_footer_text(), ENT_QUOTES, 'UTF-8') ?>" maxlength="500" placeholder="Optional footer line">
    <p class="field-help">Plain text and safe links are allowed. Example: &lt;a href=&quot;https://example.com&quot; title=&quot;Visit example&quot;&gt;Example&lt;/a&gt;</p>

    <label class="checkbox-line"><input type="checkbox" name="show_powered_by" value="1" <?= bms_show_powered_by() ? 'checked' : '' ?>> Show “Published with Bonumark Stream.” in the public footer</label>

    <div class="site-identity-favicon-box">
      <div class="site-identity-favicon-preview">
        <span class="site-identity-favicon-label">Current favicon</span>
        <?php if ((string)($currentFavicon['url'] ?? '') !== ''): ?>
          <img src="<?= htmlspecialchars((string)$currentFavicon['url'], ENT_QUOTES, 'UTF-8') ?>" alt="Current favicon preview" width="64" height="64">
          <span><?= htmlspecialchars((string)($currentFaviconMedia['original_filename'] ?? $currentFaviconMedia['filename'] ?? $currentFavicon['path'] ?? 'Favicon image'), ENT_QUOTES, 'UTF-8') ?></span>
          <?php if (!empty($currentFavicon['width']) && !empty($currentFavicon['height'])): ?>
            <small><?= (int)$currentFavicon['width'] ?> × <?= (int)$currentFavicon['height'] ?><?= !empty($currentFavicon['is_square']) ? ', square' : ', not square' ?></small>
          <?php endif; ?>
        <?php else: ?>
          <span class="site-identity-favicon-empty" aria-hidden="true">B</span>
          <span>No favicon selected</span>
        <?php endif; ?>
      </div>
      <div class="site-identity-favicon-controls">
        <label for="favicon_action">Favicon action</label>
        <select id="favicon_action" name="favicon_action">
          <option value="keep" selected>Keep current favicon</option>
          <option value="select">Use selected Media Library image</option>
          <option value="upload">Upload new favicon image</option>
          <option value="remove">Remove favicon</option>
        </select>

        <label for="site_favicon_media_id">Media Library image</label>
        <select id="site_favicon_media_id" name="site_favicon_media_id">
          <option value="0">Choose an image</option>
          <?php foreach ($faviconOptions as $media): ?>
            <?php
              $mediaId = (int)($media['id'] ?? 0);
              $label = trim((string)($media['original_filename'] ?? $media['filename'] ?? ('Media #' . $mediaId)));
              $width = (int)($media['width'] ?? 0);
              $height = (int)($media['height'] ?? 0);
              if ($width > 0 && $height > 0) {
                  $label .= ' (' . $width . ' × ' . $height . ')';
              }
            ?>
            <option value="<?= $mediaId ?>" <?= $mediaId === $currentFaviconId ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>

        <label for="site_favicon_file">Upload favicon image</label>
        <input id="site_favicon_file" type="file" name="site_favicon_file" accept="image/jpeg,image/png,image/gif,image/webp">
        <p class="field-help">Use JPG, PNG, GIF, or WebP. Square images work best. A 180 × 180 or larger square image also outputs an Apple touch icon tag.</p>
      </div>
    </div>

    <button type="submit">Save Site Identity</button>
  </form>
</section>
<?php bms_admin_footer(); ?>
