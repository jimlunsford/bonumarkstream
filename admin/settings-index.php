<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_settings');

bms_admin_header('Settings');
?>
<div class="settings-index">
  <?php foreach (bms_admin_settings_groups() as $heading => $destinations): ?>
    <?php $destinations = bms_admin_navigation_visible($destinations); ?>
    <?php if (!$destinations) { continue; } ?>
    <section class="settings-index-group" aria-labelledby="settings-group-<?= strtolower($heading) ?>">
      <h2 id="settings-group-<?= strtolower($heading) ?>"><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></h2>
      <ul class="settings-index-list">
        <?php foreach ($destinations as $destination): ?>
          <li><a class="settings-index-link" href="<?= htmlspecialchars(bms_admin_url($destination['path']), ENT_QUOTES, 'UTF-8') ?>">
            <strong><?= htmlspecialchars($destination['label'], ENT_QUOTES, 'UTF-8') ?></strong>
            <span class="settings-index-description"><?= htmlspecialchars($destination['description'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="settings-index-arrow" aria-hidden="true">›</span>
          </a></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>
</div>
<?php bms_admin_footer(); ?>
