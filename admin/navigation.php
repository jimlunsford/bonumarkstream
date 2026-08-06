<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
require_once __DIR__ . '/../_bonumark_stream/app/appearance.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
function bms_admin_navigation_posted_items(): array
{
    $labels = $_POST['nav_label'] ?? [];
    $urls = $_POST['nav_url'] ?? [];
    $targets = $_POST['nav_target'] ?? [];
    $sources = $_POST['nav_source'] ?? [];
    $objectTypes = $_POST['nav_object_type'] ?? [];
    $objectSlugs = $_POST['nav_object_slug'] ?? [];

    if (!is_array($labels) || !is_array($urls) || !is_array($targets) || !is_array($sources) || !is_array($objectTypes) || !is_array($objectSlugs)) {
        throw new RuntimeException('Submitted menu data was invalid.');
    }

    $items = [];
    $max = min(100, max(count($labels), count($urls), count($targets)));
    for ($index = 0; $index < $max; $index++) {
        $label = trim((string)($labels[$index] ?? ''));
        $url = trim((string)($urls[$index] ?? ''));
        if ($label === '' || $url === '') {
            continue;
        }
        $items[] = [
            'label' => $label,
            'url' => $url,
            'target' => ((string)($targets[$index] ?? '_self')) === '_blank' ? '_blank' : '_self',
            'source' => (string)($sources[$index] ?? 'custom'),
            'object_type' => (string)($objectTypes[$index] ?? ''),
            'object_slug' => (string)($objectSlugs[$index] ?? ''),
        ];
    }

    return $items;
}

function bms_admin_navigation_save_state(bool $enabled, bool $accountLinksEnabled, array $items): void
{
    bms_save_public_navigation_enabled($enabled);
    if (function_exists('bms_save_public_navigation_account_links_enabled')) {
        bms_save_public_navigation_account_links_enabled($accountLinksEnabled);
    }
    bms_save_navigation_items($items);
}

function bms_admin_navigation_find_page_by_slug(array $pages, string $slug): ?array
{
    $slug = bms_slugify($slug);
    foreach ($pages as $page) {
        if (bms_slugify((string)($page['slug'] ?? '')) === $slug) {
            return $page;
        }
    }
    return null;
}

function bms_admin_navigation_item_exists(array $items, string $url): bool
{
    $url = bms_sanitize_navigation_url($url);
    foreach ($items as $item) {
        if (bms_sanitize_navigation_url((string)($item['url'] ?? '')) === $url) {
            return true;
        }
    }
    return false;
}

$publishedPages = function_exists('bms_list_page_records') ? bms_list_page_records('published') : [];
usort($publishedPages, function (array $a, array $b): int {
    return strcmp(strtolower((string)($a['title'] ?? '')), strtolower((string)($b['title'] ?? '')));
});

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['nav_action'] ?? 'save');
    $enabled = !empty($_POST['primary_navigation_enabled']);
    $accountLinksEnabled = !empty($_POST['public_navigation_account_links_enabled']);

    try {
        if ($action === 'add_page') {
            $items = bms_navigation_items();
            $page = bms_admin_navigation_find_page_by_slug($publishedPages, (string)($_POST['page_slug'] ?? ''));
            if (!$page) {
                throw new RuntimeException('Choose a published page to add.');
            }
            $pageItem = bms_navigation_prepare_page_item($page);
            if ($pageItem === null) {
                throw new RuntimeException('The selected page could not be added to navigation.');
            }
            if (bms_admin_navigation_item_exists($items, (string)$pageItem['url'])) {
                throw new RuntimeException('That page is already in the menu.');
            }
            $items[] = $pageItem;
            bms_admin_navigation_save_state($enabled, $accountLinksEnabled, $items);
            bms_flash('Page added to navigation.', 'success');
            bms_redirect(bms_admin_url('navigation.php'));
        }

        if ($action === 'add_custom') {
            $items = bms_navigation_items();
            $label = trim((string)($_POST['custom_label'] ?? ''));
            $url = trim((string)($_POST['custom_url'] ?? ''));
            if ($label === '' || $url === '') {
                throw new RuntimeException('Custom links need both a label and a URL.');
            }
            $items[] = [
                'label' => $label,
                'url' => $url,
                'target' => ((string)($_POST['custom_target'] ?? '_self')) === '_blank' ? '_blank' : '_self',
                'source' => 'custom',
            ];
            bms_admin_navigation_save_state($enabled, $accountLinksEnabled, $items);
            bms_flash('Custom link added to navigation.', 'success');
            bms_redirect(bms_admin_url('navigation.php'));
        }

        $items = bms_admin_navigation_posted_items();
        if (preg_match('/^move-up:(\d+)$/', $action, $match)) {
            $index = (int)$match[1];
            if ($index > 0 && isset($items[$index], $items[$index - 1])) {
                [$items[$index - 1], $items[$index]] = [$items[$index], $items[$index - 1]];
            }
        } elseif (preg_match('/^move-down:(\d+)$/', $action, $match)) {
            $index = (int)$match[1];
            if (isset($items[$index], $items[$index + 1])) {
                [$items[$index], $items[$index + 1]] = [$items[$index + 1], $items[$index]];
            }
        } elseif (preg_match('/^remove:(\d+)$/', $action, $match)) {
            $index = (int)$match[1];
            if (isset($items[$index])) {
                array_splice($items, $index, 1);
            }
        }

        bms_admin_navigation_save_state($enabled, $accountLinksEnabled, $items);
        $message = $action === 'save' ? 'Navigation saved.' : 'Navigation updated.';
        bms_flash($message . ' Dynamic public routes use the updated menu immediately.', 'success');
        bms_redirect(bms_admin_url('navigation.php'));
    } catch (Throwable $e) {
        bms_log_admin_exception('navigation', $e);

        bms_flash('Could not update navigation. Please try again.', 'error');
    }
}

$items = bms_navigation_items();
$navigationEnabled = bms_public_navigation_enabled();
$accountLinksEnabled = function_exists('bms_public_navigation_account_links_enabled') ? bms_public_navigation_account_links_enabled() : true;
$menuUrls = [];
foreach ($items as $item) {
    $menuUrls[] = bms_sanitize_navigation_url((string)($item['url'] ?? ''));
}

$menuItemCount = count($items);
$customItemCount = 0;
$pageItemCount = 0;
foreach ($items as $item) {
    if ((string)($item['source'] ?? 'custom') === 'page') { $pageItemCount++; } else { $customItemCount++; }
}

bms_admin_header('Navigation', [
    ['label' => 'Site Identity', 'href' => bms_admin_url('site-identity.php'), 'style' => 'secondary'],
    ['label' => 'View Site', 'href' => bms_url_path(), 'style' => 'secondary', 'target' => true],
]);
?>
<section class="panel appearance-hero-panel">
  <div class="appearance-hero-copy">
    <p class="eyebrow">Site design</p>
    <h2>Build the public menu.</h2>
    <p class="meta">Control whether navigation appears, let Bonumark add visitor-specific account links, and manage the pages and custom destinations visitors can reach.</p>
  </div>
  <span class="status-pill <?= $navigationEnabled ? 'published' : 'draft' ?>"><?= $navigationEnabled ? 'Navigation on' : 'Navigation hidden' ?></span>
</section>

<section class="panel appearance-summary-panel" aria-label="Navigation summary">
  <div class="appearance-summary-grid">
    <div><span>Public menu</span><strong><?= $navigationEnabled ? 'Shown' : 'Hidden' ?></strong></div>
    <div><span>Menu items</span><strong><?= $menuItemCount ?></strong></div>
    <div><span>Page links</span><strong><?= $pageItemCount ?></strong></div>
    <div><span>Account links</span><strong><?= $accountLinksEnabled ? 'Automatic' : 'Manual' ?></strong></div>
  </div>
</section>

<form method="post" class="appearance-navigation-form">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="nav_action" value="save">

  <section class="panel appearance-navigation-options-panel">
    <div class="appearance-section-heading">
      <div><p class="eyebrow">Menu behavior</p><h2>Choose what visitors see.</h2></div>
      <button type="submit">Save Navigation</button>
    </div>
    <div class="appearance-option-list appearance-navigation-option-list">
      <label class="appearance-toggle-card">
        <input type="checkbox" name="primary_navigation_enabled" value="1" <?= $navigationEnabled ? 'checked' : '' ?>>
        <span><strong>Display public navigation</strong><small>Show the public Menu button and navigation panel.</small></span>
      </label>
      <label class="appearance-toggle-card">
        <input type="checkbox" name="public_navigation_account_links_enabled" value="1" <?= $accountLinksEnabled ? 'checked' : '' ?>>
        <span><strong>Show automatic account links</strong><small>Add the correct sign-in, registration, dashboard or account, profile, and sign-out links for each visitor.</small></span>
      </label>
    </div>
  </section>

  <section class="panel appearance-navigation-items-panel">
    <div class="appearance-section-heading">
      <div>
        <p class="eyebrow">Current menu</p>
        <h2>Order and edit menu items.</h2>
        <p class="meta">Move controls save the new order immediately. Edit labels and URLs, then use Save Navigation.</p>
      </div>
      <span class="appearance-record-count"><?= $menuItemCount ?></span>
    </div>

    <div class="appearance-navigation-list" aria-label="Current menu items">
      <?php if (!$items): ?>
        <div class="empty-state appearance-empty-state"><h3>No menu items yet.</h3><p class="meta">Add a published page or custom link below. The public menu remains empty until items are added.</p></div>
      <?php endif; ?>
      <?php foreach ($items as $index => $item): ?>
        <?php
          $label = (string)($item['label'] ?? '');
          $url = (string)($item['url'] ?? '');
          $target = (string)($item['target'] ?? '_self') === '_blank' ? '_blank' : '_self';
          $source = (string)($item['source'] ?? 'custom');
          $objectType = (string)($item['object_type'] ?? '');
          $objectSlug = (string)($item['object_slug'] ?? '');
        ?>
        <article class="appearance-navigation-item">
          <input type="hidden" name="nav_source[]" value="<?= htmlspecialchars($source, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="nav_object_type[]" value="<?= htmlspecialchars($objectType, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="nav_object_slug[]" value="<?= htmlspecialchars($objectSlug, ENT_QUOTES, 'UTF-8') ?>">

          <header class="appearance-navigation-item-header">
            <span class="appearance-navigation-index"><?= $index + 1 ?></span>
            <div class="appearance-navigation-item-copy">
              <span><?= htmlspecialchars($source === 'page' ? 'Published page' : 'Custom link', ENT_QUOTES, 'UTF-8') ?></span>
              <h3><?= htmlspecialchars($label !== '' ? $label : 'Menu item', ENT_QUOTES, 'UTF-8') ?></h3>
              <code><?= htmlspecialchars($url !== '' ? $url : 'No URL set', ENT_QUOTES, 'UTF-8') ?></code>
            </div>
            <div class="appearance-navigation-item-actions" aria-label="Menu item controls">
              <button type="submit" class="secondary-button compact-button" name="nav_action" value="move-up:<?= $index ?>" <?= $index === 0 ? 'disabled' : '' ?>>Up</button>
              <button type="submit" class="secondary-button compact-button" name="nav_action" value="move-down:<?= $index ?>" <?= $index >= count($items) - 1 ? 'disabled' : '' ?>>Down</button>
              <button type="submit" class="link-button danger-link" name="nav_action" value="remove:<?= $index ?>">Remove</button>
            </div>
          </header>

          <div class="appearance-navigation-item-fields">
            <label><span>Label</span><input type="text" name="nav_label[]" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" maxlength="80" required></label>
            <label><span>URL</span><input type="text" name="nav_url[]" value="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" maxlength="255" required></label>
            <label class="appearance-navigation-target"><span>Open</span><select name="nav_target[]"><option value="_self" <?= $target === '_self' ? 'selected' : '' ?>>Same tab</option><option value="_blank" <?= $target === '_blank' ? 'selected' : '' ?>>New tab</option></select></label>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <?php if ($items): ?><div class="appearance-form-actions"><button type="submit">Save Navigation</button></div><?php endif; ?>
  </section>
</form>

<section class="panel appearance-navigation-add-panel">
  <div class="appearance-section-heading">
    <div><p class="eyebrow">Add destinations</p><h2>Add pages and custom links.</h2></div>
    <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('page-new.php'), ENT_QUOTES, 'UTF-8') ?>">New Page</a>
  </div>
  <div class="appearance-navigation-add-grid">
    <form method="post" class="appearance-navigation-add-card">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="nav_action" value="add_page">
      <?php if ($navigationEnabled): ?><input type="hidden" name="primary_navigation_enabled" value="1"><?php endif; ?>
      <?php if ($accountLinksEnabled): ?><input type="hidden" name="public_navigation_account_links_enabled" value="1"><?php endif; ?>
      <div><p class="eyebrow">Published content</p><h3>Add a page</h3><p class="meta">Use a published stable page as a menu destination.</p></div>
      <?php if (!$publishedPages): ?>
        <div class="empty-state appearance-empty-state compact"><h3>No published pages.</h3><p class="meta">Publish a page before adding it to navigation.</p></div>
      <?php else: ?>
        <label for="page_slug">Page</label>
        <select id="page_slug" name="page_slug">
          <?php foreach ($publishedPages as $page): ?>
            <?php $slug = bms_slugify((string)($page['slug'] ?? '')); $url = '/' . trim(bms_page_relative_directory($slug), '/') . '/'; $alreadyAdded = in_array(bms_sanitize_navigation_url($url), $menuUrls, true); ?>
            <option value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" <?= $alreadyAdded ? 'disabled' : '' ?>><?= htmlspecialchars((string)($page['title'] ?? 'Untitled Page'), ENT_QUOTES, 'UTF-8') ?><?= $alreadyAdded ? ' (already added)' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="secondary-button">Add Page</button>
      <?php endif; ?>
    </form>

    <form method="post" class="appearance-navigation-add-card">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="nav_action" value="add_custom">
      <?php if ($navigationEnabled): ?><input type="hidden" name="primary_navigation_enabled" value="1"><?php endif; ?>
      <?php if ($accountLinksEnabled): ?><input type="hidden" name="public_navigation_account_links_enabled" value="1"><?php endif; ?>
      <div><p class="eyebrow">Any destination</p><h3>Add a custom link</h3><p class="meta">Link to feeds, external sites, downloads, or any valid URL.</p></div>
      <label for="custom_label">Label</label><input type="text" id="custom_label" name="custom_label" maxlength="80" placeholder="Example: RSS">
      <label for="custom_url">URL</label><input type="text" id="custom_url" name="custom_url" maxlength="255" placeholder="/feed.xml">
      <label for="custom_target">Open</label><select id="custom_target" name="custom_target"><option value="_self">Same tab</option><option value="_blank">New tab</option></select>
      <button type="submit" class="secondary-button">Add Custom Link</button>
    </form>
  </div>
</section>
<?php bms_admin_footer(); ?>
