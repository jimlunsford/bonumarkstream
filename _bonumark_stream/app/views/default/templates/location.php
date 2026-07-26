<?php
$data = is_array($bms_theme_data ?? null) ? $bms_theme_data : [];
$primary = trim((string)($data['primary'] ?? ''));
$secondary = trim((string)($data['secondary'] ?? ''));
if ($primary === '') {
    return;
}
?>
<div class="stream-card-location" aria-label="Post location">
  <span class="stream-card-location-icon" aria-hidden="true">
    <svg viewBox="0 0 24 24" focusable="false"><path d="M12 21s7-5.35 7-12A7 7 0 1 0 5 9c0 6.65 7 12 7 12Z"/><circle cx="12" cy="9" r="2.5"/></svg>
  </span>
  <span class="stream-card-location-copy"><strong><?= htmlspecialchars($primary, ENT_QUOTES, 'UTF-8') ?></strong><?php if ($secondary !== ''): ?><span><?= htmlspecialchars($secondary, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></span>
</div>
