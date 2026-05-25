<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/appearance.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();
    $siteName = trim((string)($_POST['site_name'] ?? 'Bonumark Stream'));
    $tagline = mp_sanitize_site_identity_html((string)($_POST['site_tagline'] ?? ''));
    $eyebrow = trim((string)($_POST['homepage_eyebrow'] ?? ''));
    $footer = mp_sanitize_site_identity_html((string)($_POST['site_footer_text'] ?? ''));
    $powered = isset($_POST['show_powered_by']) ? '1' : '0';
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
        mp_flash('Site identity saved. Dynamic public routes use the updated identity immediately.', 'success');
        mp_redirect(mp_admin_url('site-identity.php'));
    } catch (Throwable $e) {
        mp_flash('Could not save site identity: ' . $e->getMessage(), 'error');
    }
}

mp_admin_header('Site Identity', [
    ['label' => 'Themes', 'href' => mp_admin_url('theme.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Appearance</p>
  <h2>Name the site and set the public framing.</h2>
  <p class="meta">These settings control the public homepage, header, browser title, and footer text.</p>
</section>

<section class="panel settings-panel">
  <form method="post">
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

    <button type="submit">Save Site Identity</button>
  </form>
</section>
<?php mp_admin_footer(); ?>
