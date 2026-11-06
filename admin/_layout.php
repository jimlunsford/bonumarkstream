<?php
require_once __DIR__ . '/../_bonumark_stream/app/profiles.php';
require_once __DIR__ . '/../_bonumark_stream/app/appearance.php';
require_once __DIR__ . '/../_bonumark_stream/app/pwa.php';
require_once __DIR__ . '/../_bonumark_stream/app/scheduler.php';
require_once __DIR__ . '/_navigation.php';

function bms_admin_action_link(array $action): string
{
    if (isset($action['html']) && is_string($action['html'])) {
        $class = 'admin-page-action-custom';
        if (!empty($action['class'])) {
            $class .= ' ' . preg_replace('/[^a-zA-Z0-9_\- ]/', '', (string)$action['class']);
        }
        return '<div class="' . $class . '">' . $action['html'] . '</div>';
    }

    $label = htmlspecialchars((string)($action['label'] ?? 'Open'), ENT_QUOTES, 'UTF-8');
    $href = htmlspecialchars((string)($action['href'] ?? '#'), ENT_QUOTES, 'UTF-8');
    $style = (string)($action['style'] ?? 'secondary');
    $class = $style === 'primary' ? 'primary-button' : 'button-link secondary';
    if (!empty($action['class'])) {
        $class .= ' ' . preg_replace('/[^a-zA-Z0-9_\- ]/', '', (string)$action['class']);
    }
    $target = !empty($action['target']) ? ' target="_blank" rel="noopener"' : '';

    return '<a class="' . $class . '" href="' . $href . '"' . $target . '>' . $label . '</a>';
}

function bms_view_site_action(string $label = 'View Site'): array
{
    return [
        'label' => $label,
        'href' => bms_url_path(),
        'style' => 'secondary',
        'target' => true,
        'class' => 'view-site-action',
    ];
}

function bms_view_stream_post_action(array $page, string $label = 'View Post'): array
{
    return [
        'label' => $label,
        'href' => bms_stream_url((string)($page['slug'] ?? ''), (string)($page['category'] ?? '')),
        'style' => 'secondary',
        'target' => true,
        'class' => 'view-stream-post-action',
    ];
}

function bms_admin_error_page(string $title, string $message, int $status = 404, array $actions = []): void
{
    http_response_code($status);
    if (!$actions) {
        $actions = [
            ['label' => 'Dashboard', 'href' => bms_admin_url(), 'style' => 'secondary'],
            ['label' => 'Stream Posts', 'href' => bms_admin_url('content.php'), 'style' => 'primary'],
        ];
    }
    bms_admin_header($title, $actions);
    echo '<section class="panel admin-error-panel">';
    echo '<p class="eyebrow">Needs attention</p>';
    echo '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</section>';
    bms_admin_footer();
    exit;
}

function bms_admin_header(string $title, array $actions = []): void
{
    bms_send_security_headers();
    if (function_exists('bms_maybe_publish_due_scheduled_posts') && function_exists('bms_current_user_can') && bms_current_user_can('publish_content')) {
        bms_maybe_publish_due_scheduled_posts();
    }

    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $styleUrl = htmlspecialchars(bms_asset_url('assets/style.css'), ENT_QUOTES, 'UTF-8');
    $adminStyleUrl = htmlspecialchars(bms_asset_url('assets/admin.css'), ENT_QUOTES, 'UTF-8');
    $adminShellStyleUrl = htmlspecialchars(bms_asset_url('assets/admin-shell.css'), ENT_QUOTES, 'UTF-8');
    $adminContentListStyleUrl = htmlspecialchars(bms_asset_url('assets/admin-content-list.css'), ENT_QUOTES, 'UTF-8');
    $adminEditorWorkflowStyleUrl = htmlspecialchars(bms_asset_url('assets/admin-editor-workflow.css'), ENT_QUOTES, 'UTF-8');
    $adminMediaLibraryStyleUrl = htmlspecialchars(bms_asset_url('assets/admin-media-library.css'), ENT_QUOTES, 'UTF-8');
    $adminCommentsStyleUrl = htmlspecialchars(bms_asset_url('assets/admin-comments.css'), ENT_QUOTES, 'UTF-8');
    $adminAccountsStyleUrl = htmlspecialchars(bms_asset_url('assets/admin-accounts.css'), ENT_QUOTES, 'UTF-8');
    $adminRegistrationStyleUrl = htmlspecialchars(bms_asset_url('assets/admin-registration.css'), ENT_QUOTES, 'UTF-8');
    $adminAppearanceStyleUrl = htmlspecialchars(bms_asset_url('assets/admin-appearance.css'), ENT_QUOTES, 'UTF-8');
    $adminSettingsStyleUrl = htmlspecialchars(bms_asset_url('assets/admin-settings.css'), ENT_QUOTES, 'UTF-8');
    $adminPlacesStyleUrl = htmlspecialchars(bms_asset_url('assets/admin-places.css'), ENT_QUOTES, 'UTF-8');
    $adminOperationsStyleUrl = htmlspecialchars(bms_asset_url('assets/admin-operations.css'), ENT_QUOTES, 'UTF-8');
    $adminScriptUrl = htmlspecialchars(bms_asset_url('assets/admin.js'), ENT_QUOTES, 'UTF-8');
    $adminPlacesScriptUrl = htmlspecialchars(bms_asset_url('assets/admin-places.js'), ENT_QUOTES, 'UTF-8');
    $csrf = function_exists('bms_csrf_token') ? htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') : '';
    $currentAdminFile = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $screenSlug = preg_replace('/[^a-z0-9_-]+/i', '-', pathinfo($currentAdminFile, PATHINFO_FILENAME)) ?: 'admin';
    $can = static function (string $capability): bool {
        return function_exists('bms_current_user_can') && bms_current_user_can($capability);
    };

    $siteUrl = bms_url_path();
    $dashboardUrl = bms_admin_url();
    $logoutUrl = bms_admin_url('logout.php');
    $scheduledRunnerUrl = htmlspecialchars(bms_admin_url('scheduled-runner.php'), ENT_QUOTES, 'UTF-8');

    $displayName = 'Admin';
    $username = 'admin';
    $adminProfileUrl = bms_admin_url('user.php');
    $publicProfileUrl = bms_url_path('profile');
    if (function_exists('bms_is_logged_in') && bms_is_logged_in()) {
        $user = bms_current_user();
        $displayName = (string)($user['display_name'] ?? 'Admin');
        $username = (string)($user['username'] ?? 'admin');
        if (function_exists('bms_public_profile_url_for_user')) {
            $publicProfileUrl = bms_public_profile_url_for_user($user);
        } elseif (trim($username) !== '') {
            $publicProfileUrl = bms_url_path('profile/' . rawurlencode($username));
        }
    }

    $safeDisplayName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
    $handleLabel = trim($username) !== '' ? '@' . $username : 'Profile';
    $safeHandleLabel = htmlspecialchars($handleLabel, ENT_QUOTES, 'UTF-8');
    $safeAdminProfileUrl = htmlspecialchars($adminProfileUrl, ENT_QUOTES, 'UTF-8');
    $safePublicProfileUrl = htmlspecialchars($publicProfileUrl, ENT_QUOTES, 'UTF-8');
    $profileOwnerLabel = trim($displayName) !== '' ? $displayName : (trim($username) !== '' ? $username : 'current user');
    $safeProfileOwnerLabel = htmlspecialchars($profileOwnerLabel, ENT_QUOTES, 'UTF-8');
    $adminFaviconTags = function_exists('bms_site_favicon_tags') ? bms_site_favicon_tags() : '';
    $adminPwaTags = function_exists('bms_pwa_meta_tags') ? bms_pwa_meta_tags() : '';
    $adminAvatarMarkup = '';
    if (function_exists('bms_is_logged_in') && bms_is_logged_in() && function_exists('bms_user_avatar_markup')) {
        $adminAvatarMarkup = '<span class="admin-user-avatar">' . bms_user_avatar_markup(bms_current_user(), 'admin-user-avatar-image', 96, 96, false) . '</span>';
    }

    $currentArea = bms_admin_current_area($currentAdminFile);
    $hasSettingsNavigation = bms_admin_settings_has_local_navigation($currentAdminFile);

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $safeTitle . ' | Bonumark Stream Admin</title>' . $adminFaviconTags . $adminPwaTags;
    echo '<link rel="stylesheet" href="' . $styleUrl . '"><link rel="stylesheet" href="' . $adminStyleUrl . '"><link rel="stylesheet" href="' . $adminShellStyleUrl . '"><link rel="stylesheet" href="' . $adminContentListStyleUrl . '"><link rel="stylesheet" href="' . $adminEditorWorkflowStyleUrl . '"><link rel="stylesheet" href="' . $adminMediaLibraryStyleUrl . '"><link rel="stylesheet" href="' . $adminCommentsStyleUrl . '"><link rel="stylesheet" href="' . $adminAccountsStyleUrl . '"><link rel="stylesheet" href="' . $adminRegistrationStyleUrl . '"><link rel="stylesheet" href="' . $adminAppearanceStyleUrl . '"><link rel="stylesheet" href="' . $adminSettingsStyleUrl . '"><link rel="stylesheet" href="' . $adminPlacesStyleUrl . '"><link rel="stylesheet" href="' . $adminOperationsStyleUrl . '"><script src="' . $adminScriptUrl . '" defer></script><script src="' . $adminPlacesScriptUrl . '" defer></script></head>';
    echo '<body class="bonumark-admin admin-screen-' . htmlspecialchars($screenSlug, ENT_QUOTES, 'UTF-8') . '" data-scheduled-runner-url="' . $scheduledRunnerUrl . '" data-scheduled-runner-csrf="' . $csrf . '">';
    echo '<a class="screen-reader-text admin-skip-link" href="#admin-main-content">Skip to content</a>';
    echo '<div class="admin-shell">';

    echo '<header class="admin-mobile-bar">';
    echo '<button class="admin-mobile-menu-button" type="button" data-admin-nav-open aria-controls="admin-sidebar" aria-expanded="false"><span class="admin-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span><span>Menu</span><span class="screen-reader-text">: open admin navigation</span></button>';
    echo '<a class="admin-mobile-brand" href="' . htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') . '"><span class="admin-brand-mark">B</span><span>Bonumark</span></a>';
    echo '<a class="admin-mobile-site" href="' . htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">View site<span class="screen-reader-text"> (opens in a new tab)</span></a>';
    echo '</header>';
    echo '<div class="admin-sidebar-backdrop" data-admin-nav-close hidden></div>';

    echo '<aside class="admin-sidebar" id="admin-sidebar" aria-label="Admin navigation" aria-hidden="false">';
    echo '<div class="admin-sidebar-header"><a class="admin-brand" href="' . htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') . '"><span class="admin-brand-mark">B</span><span class="admin-brand-copy"><strong>Bonumark</strong><small>Stream Admin</small></span></a><button class="admin-sidebar-close" type="button" data-admin-nav-close><span aria-hidden="true">×</span><span class="screen-reader-text">Close admin navigation</span></button></div>';
    echo '<nav class="admin-sidebar-nav" aria-label="Primary admin navigation">';
    foreach (bms_admin_navigation_visible(bms_admin_navigation_areas()) as $link) {
        echo bms_admin_navigation_link($link, $currentAdminFile, 'admin-nav-link nav-primary');
    }
    echo '</nav>';

    echo '<div class="admin-sidebar-footer">';
    echo '<a class="admin-sidebar-profile" href="' . $safeAdminProfileUrl . '">' . $adminAvatarMarkup . '<span class="admin-sidebar-profile-copy"><strong>' . $safeDisplayName . '</strong><small>' . $safeHandleLabel . '</small></span></a>';
    echo '<div class="admin-sidebar-utilities"><a href="' . $safeAdminProfileUrl . '">Account</a><a href="' . htmlspecialchars(bms_admin_url('help.php'), ENT_QUOTES, 'UTF-8') . '">Help</a></div>';
    echo '<a class="admin-sidebar-view-site" href="' . htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">View site<span class="screen-reader-text"> (opens in a new tab)</span></a>';
    echo '<form method="post" action="' . htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') . '" class="admin-sidebar-logout"><input type="hidden" name="csrf_token" value="' . $csrf . '"><button type="submit">Sign out</button></form>';
    echo '</div></aside>';

    echo '<section class="admin-main">';
    echo '<header class="admin-topbar"><div class="admin-topbar-context"><span>Bonumark Stream</span><strong>Administration</strong></div><div class="admin-topbar-actions"><a class="admin-topbar-site" href="' . htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">View site <span aria-hidden="true">↗</span><span class="screen-reader-text"> (opens in a new tab)</span></a><a class="admin-user" href="' . $safeAdminProfileUrl . '" aria-label="Account for ' . $safeProfileOwnerLabel . '">' . $adminAvatarMarkup . '<span class="admin-user-copy"><strong>' . $safeDisplayName . '</strong><small>' . $safeHandleLabel . '</small></span></a></div></header>';
    echo '<main class="admin-content" id="admin-main-content" tabindex="-1">';

    $flashes = bms_get_flash();
    if ($flashes) {
        echo '<div class="notice-stack" aria-live="polite">';
        foreach ($flashes as $flash) {
            $typeRaw = (string)($flash['type'] ?? 'info');
            $type = in_array($typeRaw, ['success', 'error', 'warning', 'info'], true) ? $typeRaw : 'info';
            $message = htmlspecialchars((string)($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8');
            $titleText = match ($type) {
                'success' => 'Done',
                'error' => 'Needs attention',
                'warning' => 'Warning',
                default => 'Item',
            };
            $icon = match ($type) {
                'success' => '✓',
                'error' => '!',
                'warning' => '!',
                default => 'i',
            };
            $role = $type === 'error' ? 'alert' : 'status';
            echo '<div class="flash notice ' . $type . '" role="' . $role . '"><span class="notice-icon" aria-hidden="true">' . $icon . '</span><div class="notice-copy"><strong>' . $titleText . '</strong><p>' . $message . '</p></div></div>';
        }
        echo '</div>';
    }

    // Dashboard owns this queue through its independent, failure-aware review row.
    if ($currentAdminFile !== 'index.php' && $can('manage_users') && function_exists('bms_user_pending_counts')) {
        $pendingCounts = bms_user_pending_counts();
        $pendingApproval = (int)($pendingCounts['pending_approval'] ?? 0);
        if ($pendingApproval > 0) {
            $usersLink = htmlspecialchars(bms_admin_url('users.php'), ENT_QUOTES, 'UTF-8');
            $plural = $pendingApproval === 1 ? 'account is' : 'accounts are';
            echo '<div class="notice-stack" aria-live="polite"><div class="flash notice warning" role="status"><span class="notice-icon" aria-hidden="true">!</span><div class="notice-copy"><strong>Approval needed</strong><p>' . $pendingApproval . ' ' . $plural . ' waiting for admin approval. <a href="' . $usersLink . '">Review pending commenters</a>.</p></div></div></div>';
        }
    }

    echo '<div class="admin-page-workspace' . ($hasSettingsNavigation ? ' has-settings-navigation' : '') . '">';
    if ($hasSettingsNavigation) {
        bms_admin_render_settings_navigation($currentAdminFile);
    }
    echo '<div class="admin-page-body">';
    if ($currentArea === 'settings' && $currentAdminFile !== 'settings-index.php') {
        echo '<a class="admin-back-link" href="' . htmlspecialchars(bms_admin_url('settings-index.php'), ENT_QUOTES, 'UTF-8') . '"><span aria-hidden="true">←</span> Back to Settings</a>';
    }

    echo '<div class="admin-page-title"><div class="admin-page-heading-copy"><h1>' . $safeTitle . '</h1></div>';
    if ($actions) {
        echo '<div class="admin-page-actions">';
        foreach ($actions as $action) {
            echo bms_admin_action_link($action);
        }
        echo '</div>';
    }
    echo '</div>';

    $contextLinks = bms_admin_navigation_visible(bms_admin_context_links($currentArea));
    if (in_array($currentAdminFile, ['help.php', 'welcome.php'], true) && $can('view_admin')) {
        $contextLinks[] = ['label' => 'Welcome', 'path' => 'welcome.php', 'capability' => 'view_admin'];
    }
    if ($contextLinks) {
        $areaLabel = bms_admin_navigation_areas()[$currentArea]['label'];
        echo '<nav class="admin-context-nav" aria-label="' . htmlspecialchars($areaLabel, ENT_QUOTES, 'UTF-8') . ' navigation">';
        foreach ($contextLinks as $link) {
            echo bms_admin_navigation_link($link, $currentAdminFile, 'admin-context-link');
        }
        echo '</nav>';
    }
}

function bms_admin_footer(): void
{
    $version = htmlspecialchars(bms_version(), ENT_QUOTES, 'UTF-8');
    echo '</div></div><footer class="admin-footer"><span>Bonumark Stream</span><span>v' . $version . '</span></footer></main></section></div></body></html>';
}
