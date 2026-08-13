<?php
$data = is_array($bms_component_data ?? null) ? $bms_component_data : [];
$single = !empty($data['single']);
$pageUrl = (string)($data['page_url'] ?? '#');
?>
      <div class="stream-card-headerline">
        <?php if ((string)($data['author_profile_url'] ?? '') !== ''): ?>
          <a class="stream-card-author" href="<?= htmlspecialchars((string)$data['author_profile_url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($data['author_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?></a>
        <?php else: ?>
          <span class="stream-card-author"><?= htmlspecialchars((string)($data['author_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
        <?php if (!empty($data['show_dates']) && (string)($data['date_label'] ?? '') !== ''): ?>
          <span class="stream-card-separator" aria-hidden="true">&middot;</span><a class="stream-card-datetime stream-card-permalink stream-permalink" href="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>"><time datetime="<?= htmlspecialchars((string)($data['date_iso'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$data['date_label'], ENT_QUOTES, 'UTF-8') ?></time></a>
        <?php elseif (!$single): ?>
          <span class="stream-card-separator" aria-hidden="true">&middot;</span><a class="stream-card-datetime stream-card-permalink stream-permalink" href="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">Permalink</a>
        <?php endif; ?>
      </div>
