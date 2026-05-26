<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/appearance.php';
require_once __DIR__ . '/../_bonumark_stream/app/media.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();
mp_require_capability('manage_appearance');

function mp_site_identity_favicon_media_options(): array
{
    $items = function_exists('mp_media_list') ? mp_media_list(200, '', 'active') : [];
    return array_values(array_filter($items, static function (array $media): bool {
        return function_exists('mp_site_favicon_is_image') && mp_site_favicon_is_image($media);
    }));
}

function mp_site_identity_uploaded_favicon_present(array $file): bool
{
    return isset($file['error']) && (int)$file['error'] !== UPLOAD_ERR_NO_FILE;
}

function mp_site_identity_validate_favicon_upload_name(array $file): void
{
    $name = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        throw new RuntimeException('Favicons must be JPG, PNG, GIF, or WebP images.');
    }
}

function mp_site_identity_store_favicon_media(array $media): void
{
    if (!function_exists('mp_site_favicon_is_image') || !mp_site_favicon_is_image($media)) {
        throw new RuntimeException('Choose an active image from the Media Library.');
    }
    mp_set_setting('site_favicon_media_id', (string)(int)($media['id'] ?? 0));
    mp_set_setting('site_favicon_path', (string)($media['public_path'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();
    $siteName = trim((string)($_POST['site_name'] ?? 'Bonumark Stream'));
    $tagline = mp_sanitize_site_identity_html((string)($_POST['site_tagline'] ?? ''));
    $eyebrow = trim((string)($_POST['homepage_eyebrow'] ?? ''));
    $footer = mp_sanitize_site_identity_html((string)($_POST['site_footer_text'] ?? ''));
    $powered = isset($_POST['show_powered_by']) ? '1' : '0';
    $faviconAction = (string)($_POST['favicon_action'] ?? 'keep');
    if ($siteName === '') {
        $siteName = 'Bonumark Stream';
    }
    if ($eyebrow === '') {
        $eyebrow = 'Own your short-form publishing';
    }
    try {
        mp_set_setting('site_name', $siteName);
        mp_set_setting('site_tagline', $tagline);
        mp_set_setting('homepage_eyebrow', $eyebrow);
        mp_set_setting('site_footer_text', $footer);
        mp_set_setting('show_powered_by', $powered);

        $faviconMessage = '';
        $uploadedFile = is_array($_FILES['site_favicon_file'] ?? null) ? $_FILES['site_favicon_file'] : [];
        if ($uploadedFile && mp_site_identity_uploaded_favicon_present($uploadedFile)) {
            mp_site_identity_validate_favicon_upload_name($uploadedFile);
            $uploadedMedia = mp_media_upload($uploadedFile, 'Site favicon', 'Site favicon used for browser tabs and saved bookmarks.', ['generate_derivatives' => false]);
            mp_site_identity_store_favicon_media($uploadedMedia);
            $faviconMessage = ' Favicon uploaded.';
            $faviconView = mp_site_favicon_view_data();
            if (!empty($faviconView['id']) && empty($faviconView['is_square'])) {
                mp_flash('Favicon saved. For best browser and mobile results, use a square image.', 'info');
            }
        } elseif ($faviconAction === 'upload') {
            throw new RuntimeException('Choose a favicon image to upload, or select Keep current favicon.');
        } elseif ($faviconAction === 'remove') {
            mp_set_setting('site_favicon_media_id', '0');
            mp_set_setting('site_favicon_path', '');
            $faviconMessage = ' Favicon removed.';
        } elseif ($faviconAction === 'select') {
            $selectedId = max(0, (int)($_POST['site_favicon_media_id'] ?? 0));
            if ($selectedId <= 0) {
                throw new RuntimeException('Choose an image from the Media Library or select Keep current favicon.');
            }
            $selectedMedia = mp_media_find($selectedId);
            if (!is_array($selectedMedia)) {
                throw new RuntimeException('The selected favicon image could not be found.');
            }
            mp_site_identity_store_favicon_media($selectedMedia);
            $faviconMessage = ' Favicon selected.';
            $faviconView = mp_site_favicon_view_data();
            if (!empty($faviconView['id']) && empty($faviconView['is_square'])) {
                mp_flash('Favicon saved. For best browser and mobile results, use a square image.', 'info');
            }
        }

        mp_flash('Site identity saved. Dynamic public routes and admin pages use the updated identity immediately.' . $faviconMessage, 'success');
        mp_redirect(mp_admin_url('site-identity.php'));
    } catch (Throwable $e) {
        mp_flash('Could not save site identity: ' . $e->getMessage(), 'error');
    }
}

$currentFavicon = function_exists('mp_site_favicon_view_data') ? mp_site_favicon_view_data() : [];
$currentFaviconMedia = is_array($currentFavicon['media'] ?? null) ? $currentFavicon['media'] : null;
$faviconOptions = mp_site_identity_favicon_media_options();
$currentFaviconId = (int)($currentFavicon['id'] ?? 0);

mp_admin_header('Site Identity', [
    ['label' => 'Themes', 'href' => mp_admin_url('theme.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Appearance</p>
  <h2>Name the site and set the public framing.</h2>
  <p class="meta">These settings control the public homepage, header, browser title, footer text, and browser tab icon.</p>
</section>

<section class="panel settings-panel site-identity-panel">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <label for="site_name">Site name</label>
    <input type="text" id="site_name" name="site_name" value="<?= htmlspecialchars((string)mp_setting_or_config('site_name', 'Bonumark Stream'), ENT_QUOTES, 'UTF-8') ?>" maxlength="160" required>

    <label for="site_tagline">Tagline</label>
    <input type="text" id="site_tagline" name="site_tagline" value="<?= htmlspecialchars((string)mp_setting_or_config('site_tagline', ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="500">
    <p class="field-help">Plain text and safe links are allowed. Example: &lt;a href=&quot;https://example.com&quot; title=&quot;Visit example&quot;&gt;Example&lt;/a&gt;</p>

    <label for="homepage_eyebrow">Homepage label</label>
    <input type="text" id="homepage_eyebrow" name="homepage_eyebrow" value="<?= htmlspecialchars(mp_homepage_eyebrow(), ENT_QUOTES, 'UTF-8') ?>" maxlength="120">

    <label for="site_footer_text">Footer text</label>
    <input type="text" id="site_footer_text" name="site_footer_text" value="<?= htmlspecialchars(mp_site_footer_text(), ENT_QUOTES, 'UTF-8') ?>" maxlength="500" placeholder="Optional footer line">
    <p class="field-help">Plain text and safe links are allowed. Example: &lt;a href=&quot;https://example.com&quot; title=&quot;Visit example&quot;&gt;Example&lt;/a&gt;</p>

    <label class="checkbox-line"><input type="checkbox" name="show_powered_by" value="1" <?= mp_show_powered_by() ? 'checked' : '' ?>> Show “Published with Bonumark Stream.” in the public footer</label>

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
<?php mp_admin_footer(); ?>
