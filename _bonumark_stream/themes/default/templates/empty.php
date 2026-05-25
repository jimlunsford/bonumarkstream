<?php
$data = is_array($mp_theme_data ?? null) ? $mp_theme_data : [];
?>
<section class="stream-empty stream-state-card ledger-panel ledger-empty-panel">
  <h2><?= htmlspecialchars((string)($data['title'] ?? 'No stream posts yet.'), ENT_QUOTES, 'UTF-8') ?></h2>
  <p><?= htmlspecialchars((string)($data['message'] ?? 'No stream posts have been published yet.'), ENT_QUOTES, 'UTF-8') ?></p>
</section>
