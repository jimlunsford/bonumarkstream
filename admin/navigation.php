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

bms_admin_header('Navigation', [
    ['label' => 'Themes', 'href' => bms_admin_url('theme.php'), 'style' => 'secondary'],
    ['label' => 'Pages', 'href' => bms_admin_url('pages.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Appearance</p>
  <h2>Manage the public menu in one place.</h2>
  <p class="meta">Navigation is optional. Turn it on only if you want a public menu, then add the main links you want visitors to see.</p>
</section>

<section class="panel settings-panel navigation-builder-panel">
  <form method="post" class="navigation-manager-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="nav_action" value="save">

    <div class="navigation-display-toggle">
      <label class="checkbox-row"><input type="checkbox" name="primary_navigation_enabled" value="1" <?= $navigationEnabled ? 'checked' : '' ?>> Display public navigation</label>
      <p class="field-help">When this is off, the public Menu button and navigation panel are hidden.</p>
      <label class="checkbox-row"><input type="checkbox" name="public_navigation_account_links_enabled" value="1" <?= $accountLinksEnabled ? 'checked' : '' ?>> Show automatic account links</label>
      <p class="field-help">When this is on, Bonumark adds the correct sign-in, registration, dashboard/account, profile, and sign-out links for the current visitor. Turn it off if you want to manage every menu link manually.</p>
    </div>

    <div class="navigation-manager-list" aria-label="Current menu items">
      <?php if (!$items): ?>
        <div class="empty-state compact-empty-state">
          <h3>No menu items yet.</h3>
          <p class="meta">Add Home, a page, or a custom link below. The menu will stay hidden until public navigation is turned on.</p>
        </div>
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
        <article class="navigation-manager-item">
          <div class="navigation-manager-item-summary">
            <div class="navigation-manager-item-title-wrap">
              <p class="navigation-manager-item-kicker">Menu item <?= $index + 1 ?></p>
              <h3><?= htmlspecialchars($label !== '' ? $label : 'Menu item', ENT_QUOTES, 'UTF-8') ?></h3>
              <p class="navigation-manager-item-url"><?= htmlspecialchars($url !== '' ? $url : 'No URL set', ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="navigation-manager-actions" aria-label="Menu item controls">
              <button type="submit" class="secondary-button compact-button navigation-action-button" name="nav_action" value="move-up:<?= $index ?>" <?= $index === 0 ? 'disabled' : '' ?>>Move up</button>
              <button type="submit" class="secondary-button compact-button navigation-action-button" name="nav_action" value="move-down:<?= $index ?>" <?= $index >= count($items) - 1 ? 'disabled' : '' ?>>Move down</button>
              <button type="submit" class="link-button danger-link navigation-remove-button" name="nav_action" value="remove:<?= $index ?>">Remove</button>
            </div>
          </div>
          <div class="navigation-manager-fields">
            <input type="hidden" name="nav_source[]" value="<?= htmlspecialchars($source, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="nav_object_type[]" value="<?= htmlspecialchars($objectType, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="nav_object_slug[]" value="<?= htmlspecialchars($objectSlug, ENT_QUOTES, 'UTF-8') ?>">
            <div class="navigation-manager-field">
              <label for="nav_label_<?= $index ?>">Label</label>
              <input type="text" id="nav_label_<?= $index ?>" name="nav_label[]" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" maxlength="80" required>
            </div>
            <div class="navigation-manager-field">
              <label for="nav_url_<?= $index ?>">URL</label>
              <input type="text" id="nav_url_<?= $index ?>" name="nav_url[]" value="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" maxlength="255" required>
            </div>
            <div class="navigation-manager-field navigation-manager-field-small">
              <label for="nav_target_<?= $index ?>">Open</label>
              <select id="nav_target_<?= $index ?>" name="nav_target[]">
                <option value="_self" <?= $target === '_self' ? 'selected' : '' ?>>Same tab</option>
                <option value="_blank" <?= $target === '_blank' ? 'selected' : '' ?>>New tab</option>
              </select>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <p class="field-help">Order is controlled by the Move up and Move down buttons. Bonumark stores the final order automatically.</p>
    <button type="submit">Save Navigation</button>
  </form>
</section>

<section class="panel settings-panel navigation-add-panel">
  <div class="panel-heading-row">
    <div>
      <p class="eyebrow">Add items</p>
      <h2>Add pages and custom links.</h2>
    </div>
    <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('page-new.php'), ENT_QUOTES, 'UTF-8') ?>">New Page</a>
  </div>
  <div class="navigation-add-grid">
    <form method="post" class="navigation-add-card">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="nav_action" value="add_page">
      <?php if ($navigationEnabled): ?><input type="hidden" name="primary_navigation_enabled" value="1"><?php endif; ?>
      <?php if ($accountLinksEnabled): ?><input type="hidden" name="public_navigation_account_links_enabled" value="1"><?php endif; ?>
      <h3>Add published page</h3>
      <?php if (!$publishedPages): ?>
        <p class="meta">No published pages are available yet.</p>
      <?php else: ?>
        <label for="page_slug">Page</label>
        <select id="page_slug" name="page_slug">
          <?php foreach ($publishedPages as $page): ?>
            <?php
              $slug = bms_slugify((string)($page['slug'] ?? ''));
              $url = '/' . trim(bms_page_relative_directory($slug), '/') . '/';
              $alreadyAdded = in_array(bms_sanitize_navigation_url($url), $menuUrls, true);
            ?>
            <option value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" <?= $alreadyAdded ? 'disabled' : '' ?>><?= htmlspecialchars((string)($page['title'] ?? 'Untitled Page'), ENT_QUOTES, 'UTF-8') ?><?= $alreadyAdded ? ' (already added)' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="secondary-button">Add Page</button>
      <?php endif; ?>
    </form>

    <form method="post" class="navigation-add-card">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="nav_action" value="add_custom">
      <?php if ($navigationEnabled): ?><input type="hidden" name="primary_navigation_enabled" value="1"><?php endif; ?>
      <?php if ($accountLinksEnabled): ?><input type="hidden" name="public_navigation_account_links_enabled" value="1"><?php endif; ?>
      <h3>Add custom link</h3>
      <label for="custom_label">Label</label>
      <input type="text" id="custom_label" name="custom_label" maxlength="80" placeholder="Example: RSS">
      <label for="custom_url">URL</label>
      <input type="text" id="custom_url" name="custom_url" maxlength="255" placeholder="/feed.xml">
      <label for="custom_target">Open</label>
      <select id="custom_target" name="custom_target">
        <option value="_self">Same tab</option>
        <option value="_blank">New tab</option>
      </select>
      <button type="submit" class="secondary-button">Add Custom Link</button>
    </form>
  </div>
</section>
<?php bms_admin_footer(); ?>
