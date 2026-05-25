<?php
/**
 * Midnight Ledger template helpers.
 *
 * These helpers keep repeated document setup out of individual templates while
 * leaving Bonumark core responsible for routing, data preparation, security,
 * publishing, users, media, comments, feeds, and upgrades.
 */

if (!function_exists('ml_theme_data')) {
    function ml_theme_data($value): array
    {
        return is_array($value) ? $value : [];
    }
}

if (!function_exists('ml_h')) {
    function ml_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ml_setting_token')) {
    function ml_setting_token(array $settings, string $key, string $fallback): string
    {
        $value = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower((string)($settings[$key] ?? $fallback))) ?: $fallback;
        $value = trim($value, '-_');
        return $value !== '' ? substr($value, 0, 64) : $fallback;
    }
}

if (!function_exists('ml_ledger_class')) {
    function ml_ledger_class(array $data): string
    {
        $settings = is_array($data['theme_settings'] ?? null) ? $data['theme_settings'] : [];
        return trim(
            'ledger-accent-' . ml_setting_token($settings, 'accent', 'copper') . ' ' .
            'ledger-density-' . ml_setting_token($settings, 'density', 'comfortable') . ' ' .
            'ledger-width-' . ml_setting_token($settings, 'content_width', 'standard')
        );
    }
}

if (!function_exists('ml_body_class')) {
    function ml_body_class(array $data, string $extra = ''): string
    {
        return trim((string)($data['body_class'] ?? '') . ' ' . ml_ledger_class($data) . ' ' . $extra);
    }
}

if (!function_exists('ml_page_title')) {
    function ml_page_title(array $data, string $fallback = '', bool $appendSiteName = false): string
    {
        $title = trim((string)($data['title'] ?? $fallback));
        if ($title === '') {
            $title = trim($fallback) !== '' ? $fallback : 'Bonumark Stream';
        }
        if ($appendSiteName) {
            $siteName = trim((string)($data['site_name'] ?? ''));
            if ($siteName !== '' && !str_contains($title, $siteName)) {
                $title .= ' | ' . $siteName;
            }
        }
        return $title;
    }
}

if (!function_exists('ml_document_head')) {
    function ml_document_head(array $data, array $options = []): void
    {
        $title = ml_page_title($data, (string)($options['fallback_title'] ?? ''), !empty($options['append_site_name']));
        $description = (string)($data['description'] ?? '');
        $canonical = (string)($data['canonical'] ?? '');
        $ogTitle = (string)($options['og_title'] ?? ($data['page_title'] ?? $data['title'] ?? $title));
        $ogType = (string)($options['og_type'] ?? 'website');
        ?>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= ml_h($title) ?></title>
  <?php if ($description !== ''): ?><meta name="description" content="<?= ml_h($description) ?>"><?php endif; ?>
  <?php if ($canonical !== ''): ?><link rel="canonical" href="<?= ml_h($canonical) ?>"><?php endif; ?>
  <?php if (!empty($options['feed']) && (string)($data['feed_url'] ?? '') !== ''): ?><link rel="alternate" type="application/rss+xml" title="<?= ml_h((string)($data['feed_title'] ?? 'Stream Feed')) ?>" href="<?= ml_h((string)$data['feed_url']) ?>"><?php endif; ?>
  <meta property="og:title" content="<?= ml_h($ogTitle) ?>">
  <?php if ($description !== ''): ?><meta property="og:description" content="<?= ml_h($description) ?>"><?php endif; ?>
  <meta property="og:type" content="<?= ml_h($ogType) ?>">
  <?php if ($canonical !== ''): ?><meta property="og:url" content="<?= ml_h($canonical) ?>"><?php endif; ?>
  <?= (string)($data['published_meta'] ?? '') ?>
  <?= (string)($data['image_meta'] ?? '') ?>
  <?= (string)($data['robots_meta'] ?? '') ?>
  <link rel="stylesheet" href="<?= ml_h((string)($data['style_url'] ?? '')) ?>">
<?= (string)($data['theme_stylesheet_links'] ?? '') ?></head>
<?php
    }
}

if (!function_exists('ml_open_document')) {
    function ml_open_document(array $data, array $options = []): void
    {
        $bodyExtra = (string)($options['body_class'] ?? '');
        $mainClass = trim((string)($options['main_class'] ?? 'site-main stream-shell timeline'));
        ?>
<!doctype html>
<html lang="en">
<?php ml_document_head($data, $options); ?>
<body class="<?= ml_h(ml_body_class($data, $bodyExtra)) ?>">
  <a class="skip-link" href="#site-main">Skip to content</a>
  <div class="site-wrapper stream-site-wrapper ledger-site-wrapper">
    <div class="site-shell stream-site-shell ledger-site-shell">
      <?= (string)($data['header_html'] ?? '') ?>
      <main id="site-main" class="<?= ml_h($mainClass) ?>">
<?php
    }
}

if (!function_exists('ml_close_document')) {
    function ml_close_document(array $data): void
    {
        ?>
      </main>
      <?= (string)($data['footer_html'] ?? '') ?>
    </div>
  </div>
  <?php if ((string)($data['script_url'] ?? '') !== ''): ?><script src="<?= ml_h((string)$data['script_url']) ?>" defer></script><?php endif; ?>
<?= (string)($data['theme_script_tags'] ?? '') ?></body>
</html>
<?php
    }
}
