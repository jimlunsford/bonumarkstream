<?php
require_once __DIR__ . '/_helpers.php';
$data = ml_theme_data($bms_theme_data ?? []);
$user = is_array($data['user'] ?? null) ? $data['user'] : null;
ml_open_document($data, [
    'fallback_title' => 'Profile',
    'append_site_name' => true,
    'og_type' => 'profile',
    'main_class' => 'site-main profile-page-main profile-layout-shell ledger-profile-shell',
]);
?>
      <?php if (!$user): ?>
        <section class="profile-empty-panel ledger-panel ledger-profile-empty">
          <h1>Profile not found</h1>
          <p>This account does not exist or is not public.</p>
          <p><a class="profile-action-link ledger-action-link" href="<?= ml_h((string)($data['home_url'] ?? '/')) ?>">Back to stream</a></p>
        </section>
      <?php else: ?>
<?php
$profileTheme = is_array($data['theme'] ?? null) ? $data['theme'] : null;
$declarativeProfileHtml = bms_render_public_theme_layout_surface('profile', $data, $profileTheme);
?>
<?php if ($declarativeProfileHtml !== null): ?>
<?= $declarativeProfileHtml ?>
<?php else: ?>
<?= bms_render_core_public_component('profile.cover', $data) ?>
        <section class="profile-hero ledger-profile-hero" aria-labelledby="profile-name">
<?= bms_render_core_public_component('profile.avatar', $data) ?>
<?= bms_render_core_public_component('profile.identity', $data) ?>
        </section>

<?= bms_render_core_public_component('profile.about', $data) ?>
<?= bms_render_core_public_component('profile.featured', $data) ?>
<?= bms_render_core_public_component('profile.photos', $data) ?>
<?= bms_render_core_public_component('profile.now', $data) ?>
<?= bms_render_core_public_component('profile.interests', $data) ?>
<?= bms_render_core_public_component('profile.links', $data) ?>
<?= bms_render_core_public_component('profile.details', $data) ?>
<?php endif; ?>
      <?php endif; ?>
<?php ml_close_document($data); ?>
