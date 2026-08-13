<?php
$data = is_array($bms_component_data ?? null) ? $bms_component_data : [];
$feedHtml = (string)($data['feed_html'] ?? '');
?>
<section class="stream-feed ledger-stream-feed" aria-label="Stream posts">
  <?= $feedHtml ?>
</section>
