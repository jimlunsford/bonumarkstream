<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();

$checks = mp_security_status();
mp_admin_header('System Check', [
    ['label' => 'Tools', 'href' => mp_admin_url('tools.php'), 'style' => 'secondary'],
]);
?>
<section class="panel">
  <div class="section-header-row">
    <div>
      <p class="eyebrow">Bonumark Stream health</p>
      <h2>System Check</h2>
      <p class="meta">Bonumark Stream checks the pieces that matter for a shared-hosting or server install: PHP, database, private storage, HTTPS, media support, upgrades, and writable output.</p>
    </div>
  </div>
  <div class="security-checks system-checks">
    <?php foreach ($checks as $check): ?>
      <?php
        $status = htmlspecialchars((string)$check['status'], ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars((string)$check['label'], ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars((string)$check['message'], ENT_QUOTES, 'UTF-8');
      ?>
      <div class="security-check <?= $status ?>">
        <strong><?= $label ?></strong>
        <span><?= strtoupper($status) ?></span>
        <p><?= $message ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="panel">
  <h2>Bonumark Stream-specific checks</h2>
  <ul class="plain-list">
    <li><strong>Content source:</strong> Database-first content records with Markdown import/export support.</li>
    <li><strong>Public output:</strong> Public routes render dynamically first. Static HTML is an optional cache/export layer.</li>
    <li><strong>Media uploads:</strong> Allowed media formats are validated, stored under <code>/media/</code>, tracked in the database, and can generate optimized image variants when the host supports resizing.</li>
    <li><strong>Private storage:</strong> <code>_bonumark_stream</code> should never be publicly reachable.</li>
  </ul>
</section>
<?php mp_admin_footer(); ?>
