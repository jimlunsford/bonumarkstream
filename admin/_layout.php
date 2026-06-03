<?php
require_once __DIR__ . '/../_bonumark_stream/app/profiles.php';
require_once __DIR__ . '/../_bonumark_stream/app/appearance.php';
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
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $styleUrl = htmlspecialchars(bms_asset_url('assets/style.css'), ENT_QUOTES, 'UTF-8');
    $adminStyleUrl = htmlspecialchars(bms_asset_url('assets/admin.css'), ENT_QUOTES, 'UTF-8');
    $adminScriptUrl = htmlspecialchars(bms_asset_url('assets/admin.js'), ENT_QUOTES, 'UTF-8');
    $siteUrl = htmlspecialchars(bms_url_path(), ENT_QUOTES, 'UTF-8');
    $dashboardUrl = htmlspecialchars(bms_admin_url(), ENT_QUOTES, 'UTF-8');
    $contentUrl = htmlspecialchars(bms_admin_url('content.php'), ENT_QUOTES, 'UTF-8');
    $addNewUrl = htmlspecialchars(bms_admin_url('new.php'), ENT_QUOTES, 'UTF-8');
    $pagesUrl = htmlspecialchars(bms_admin_url('pages.php'), ENT_QUOTES, 'UTF-8');
    $pageNewUrl = htmlspecialchars(bms_admin_url('page-new.php'), ENT_QUOTES, 'UTF-8');
    $pageTrashUrl = htmlspecialchars(bms_admin_url('pages.php?status=trash'), ENT_QUOTES, 'UTF-8');
    $trashUrl = htmlspecialchars(bms_admin_url('content.php?status=trash'), ENT_QUOTES, 'UTF-8');
    $revisionsUrl = htmlspecialchars(bms_admin_url('revisions.php'), ENT_QUOTES, 'UTF-8');
    $mediaUrl = htmlspecialchars(bms_admin_url('media.php'), ENT_QUOTES, 'UTF-8');
    $mediaUploadUrl = htmlspecialchars(bms_admin_url('media-upload.php'), ENT_QUOTES, 'UTF-8');
    $mediaTrashUrl = htmlspecialchars(bms_admin_url('media.php?status=trash'), ENT_QUOTES, 'UTF-8');
    $appearanceUrl = htmlspecialchars(bms_admin_url('theme.php'), ENT_QUOTES, 'UTF-8');
    $themeUrl = htmlspecialchars(bms_admin_url('theme.php'), ENT_QUOTES, 'UTF-8');
    $themeInstallUrl = htmlspecialchars(bms_admin_url('theme-install.php'), ENT_QUOTES, 'UTF-8');
    $themeSettingsUrl = htmlspecialchars(bms_admin_url('theme-settings.php'), ENT_QUOTES, 'UTF-8');
    $navigationUrl = htmlspecialchars(bms_admin_url('navigation.php'), ENT_QUOTES, 'UTF-8');
    $siteIdentityUrl = htmlspecialchars(bms_admin_url('site-identity.php'), ENT_QUOTES, 'UTF-8');
    $usersUrl = htmlspecialchars(bms_admin_url('users.php'), ENT_QUOTES, 'UTF-8');
    $commentsUrl = htmlspecialchars(bms_admin_url('comments.php'), ENT_QUOTES, 'UTF-8');
    $settingsUrl = htmlspecialchars(bms_admin_url('settings.php'), ENT_QUOTES, 'UTF-8');
    $writingUrl = htmlspecialchars(bms_admin_url('settings-writing.php'), ENT_QUOTES, 'UTF-8');
    $readingUrl = htmlspecialchars(bms_admin_url('settings-reading.php'), ENT_QUOTES, 'UTF-8');
    $registrationUrl = htmlspecialchars(bms_admin_url('registration.php'), ENT_QUOTES, 'UTF-8');
    $mailUrl = htmlspecialchars(bms_admin_url('mail.php'), ENT_QUOTES, 'UTF-8');
    $toolsUrl = htmlspecialchars(bms_admin_url('tools.php'), ENT_QUOTES, 'UTF-8');
    $upgradeUrl = htmlspecialchars(bms_admin_url('upgrade.php'), ENT_QUOTES, 'UTF-8');
    $exportUrl = htmlspecialchars(bms_admin_url('export.php'), ENT_QUOTES, 'UTF-8');
    $importUrl = htmlspecialchars(bms_admin_url('import.php'), ENT_QUOTES, 'UTF-8');
    $helpUrl = htmlspecialchars(bms_admin_url('help.php'), ENT_QUOTES, 'UTF-8');
    $systemCheckUrl = htmlspecialchars(bms_admin_url('system-check.php'), ENT_QUOTES, 'UTF-8');
    $accountUrl = htmlspecialchars(bms_admin_url('user.php'), ENT_QUOTES, 'UTF-8');
    $logoutUrl = htmlspecialchars(bms_admin_url('logout.php'), ENT_QUOTES, 'UTF-8');
    $csrf = function_exists('bms_csrf_token') ? htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') : '';
    $currentAdminFile = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $sectionOpen = static function (array $files) use ($currentAdminFile): string {
        return in_array($currentAdminFile, $files, true) ? ' open' : '';
    };
    $can = static function (string $capability): bool {
        return function_exists('bms_current_user_can') && bms_current_user_can($capability);
    };

    $displayName = 'Admin';
    $username = 'admin';
    $adminProfileUrl = bms_admin_url('user.php');
    $publicProfileUrl = bms_url_path('profile.php');
    if (function_exists('bms_is_logged_in') && bms_is_logged_in()) {
        $user = bms_current_user();
        $displayName = (string)($user['display_name'] ?? 'Admin');
        $username = (string)($user['username'] ?? 'admin');
        if (function_exists('bms_public_profile_url_for_user')) {
            $publicProfileUrl = bms_public_profile_url_for_user($user);
        } elseif (trim($username) !== '') {
            $publicProfileUrl = bms_url_path('profile.php?user=' . rawurlencode($username));
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
    $adminAvatarMarkup = '';
    if (function_exists('bms_is_logged_in') && bms_is_logged_in() && function_exists('bms_user_avatar_markup')) {
        $adminAvatarMarkup = '<span class="admin-user-avatar">' . bms_user_avatar_markup(bms_current_user(), 'admin-user-avatar-image', 96, 96, false) . '</span>';
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $safeTitle . ' | Bonumark Stream Admin</title>' . $adminFaviconTags . '<link rel="stylesheet" href="' . $styleUrl . '">
  <link rel="stylesheet" href="' . $adminStyleUrl . '"><script src="' . $adminScriptUrl . '" defer></script></head><body class="bonumark-admin"><div class="admin-shell">';
    echo '<aside class="admin-sidebar" aria-label="Admin navigation"><a class="admin-brand" href="' . $dashboardUrl . '"><span class="admin-brand-mark">B</span><span>Bonumark Stream</span></a><nav class="admin-sidebar-nav">';
    echo '<a class="nav-primary view-site-nav" href="' . $siteUrl . '" target="_blank" rel="noopener">View Site</a>';
    echo '<a class="nav-primary" href="' . $dashboardUrl . '">Dashboard</a>';

    echo '<details class="admin-nav-section"' . $sectionOpen(['content.php', 'new.php', 'edit.php', 'preview.php', 'publish.php', 'unpublish.php', 'quick-edit.php', 'revisions.php', 'compare-revision.php', 'restore-revision.php', 'restore.php', 'delete.php', 'delete-permanent.php']) . '><summary class="admin-nav-heading">Stream Posts</summary><div class="admin-nav-links">';
    echo '<a href="' . $contentUrl . '">All Stream Posts</a>';
    echo '<a href="' . $addNewUrl . '">New Stream Post</a>';
    echo '<a href="' . $trashUrl . '">Trash</a>';
    echo '<a href="' . $revisionsUrl . '">Revisions</a>';
    echo '</div></details>';

    if ($can('manage_pages')) {
        echo '<details class="admin-nav-section"' . $sectionOpen(['pages.php', 'page-new.php', 'page-edit.php', 'page-publish.php', 'page-unpublish.php', 'page-delete.php', 'page-restore.php', 'page-delete-permanent.php']) . '><summary class="admin-nav-heading">Pages</summary><div class="admin-nav-links">';
        echo '<a href="' . $pagesUrl . '">All Pages</a>';
        echo '<a href="' . $pageNewUrl . '">New Page</a>';
        echo '<a href="' . $pageTrashUrl . '">Page Trash</a>';
        echo '</div></details>';
    }

    if ($can('manage_media')) {
        echo '<details class="admin-nav-section"' . $sectionOpen(['media.php', 'media-upload.php', 'media-edit.php']) . '><summary class="admin-nav-heading">Media</summary><div class="admin-nav-links">';
        echo '<a href="' . $mediaUrl . '">Library</a>';
        echo '<a href="' . $mediaUploadUrl . '">Add New</a>';
        echo '<a href="' . $mediaTrashUrl . '">Trash</a>';
        echo '</div></details>';
    }

    if ($can('manage_appearance')) {
        echo '<details class="admin-nav-section"' . $sectionOpen(['appearance.php', 'theme.php', 'theme-details.php', 'theme-install.php', 'theme-delete.php', 'theme-settings.php', 'navigation.php', 'site-identity.php']) . '><summary class="admin-nav-heading">Appearance</summary><div class="admin-nav-links">';
        echo '<a href="' . $themeUrl . '">Themes</a>';
        echo '<a href="' . $themeInstallUrl . '">Install Theme</a>';
        echo '<a href="' . $themeSettingsUrl . '">Theme Settings</a>';
        echo '<a href="' . $navigationUrl . '">Navigation</a>';
        echo '<a href="' . $siteIdentityUrl . '">Site Identity</a>';
        echo '</div></details>';
    }

    if ($can('manage_comments')) {
        echo '<details class="admin-nav-section"' . $sectionOpen(['comments.php']) . '><summary class="admin-nav-heading">Comments</summary><div class="admin-nav-links">';
        echo '<a href="' . $commentsUrl . '">All Comments</a>';
        echo '</div></details>';
    }

    echo '<details class="admin-nav-section"' . $sectionOpen(['users.php', 'user.php']) . '><summary class="admin-nav-heading">Account</summary><div class="admin-nav-links">';
    if ($can('manage_users')) {
        echo '<a href="' . $usersUrl . '">Accounts</a>';
    }
    echo '<a href="' . $accountUrl . '">Profile</a>';
    echo '</div></details>';

    if ($can('manage_settings')) {
        echo '<details class="admin-nav-section"' . $sectionOpen(['settings.php', 'settings-writing.php', 'settings-reading.php', 'registration.php', 'mail.php']) . '><summary class="admin-nav-heading">Settings</summary><div class="admin-nav-links">';
        echo '<a href="' . $settingsUrl . '">General</a>';
        echo '<a href="' . $writingUrl . '">Writing</a>';
        echo '<a href="' . $readingUrl . '">Stream</a>';
        echo '<a href="' . $registrationUrl . '">Registration</a>';
        echo '<a href="' . $mailUrl . '">Mail</a>';
        echo '</div></details>';
    }

    if ($can('view_system')) {
        echo '<details class="admin-nav-section"' . $sectionOpen(['tools.php', 'export.php', 'import.php', 'import-markdown.php', 'upgrade.php', 'system-check.php', 'help.php', 'security.php']) . '><summary class="admin-nav-heading">Tools</summary><div class="admin-nav-links">';
        echo '<a href="' . $toolsUrl . '">All Tools</a>';
        echo '<a href="' . $exportUrl . '">Export</a>';
        echo '<a href="' . $importUrl . '">Import</a>';
        echo '<a href="' . $upgradeUrl . '">Upgrade</a>';
        echo '<a href="' . $systemCheckUrl . '">System Check</a>';
        echo '<a href="' . $helpUrl . '">Help</a>';
        echo '</div></details>';
    } else {
        echo '<details class="admin-nav-section"' . $sectionOpen(['help.php']) . '><summary class="admin-nav-heading">Help</summary><div class="admin-nav-links">';
        echo '<a href="' . $helpUrl . '">Help</a>';
        echo '</div></details>';
    }

    echo '<details class="admin-nav-section"' . $sectionOpen(['logout.php']) . '><summary class="admin-nav-heading">Session</summary><div class="admin-nav-links">';
    echo '<form method="post" action="' . $logoutUrl . '" class="nav-form"><input type="hidden" name="csrf_token" value="' . $csrf . '"><button type="submit">Logout</button></form>';
    echo '</div></details>';
    echo '</nav></aside>';

    echo '<section class="admin-main"><header class="admin-topbar"><div class="admin-topbar-title">' . $safeTitle . '</div><div class="admin-user">' . $adminAvatarMarkup . '<span>Signed in as</span> <a class="admin-user-name" href="' . $safeAdminProfileUrl . '" aria-label="Edit profile for ' . $safeProfileOwnerLabel . '"><strong>' . $safeDisplayName . '</strong></a> <a class="admin-user-handle" href="' . $safePublicProfileUrl . '" target="_blank" rel="noopener" aria-label="View public profile for ' . $safeProfileOwnerLabel . '">' . $safeHandleLabel . '</a></div></header><main class="admin-content">';

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
    if ($can('manage_users') && function_exists('bms_user_pending_counts')) {
        $pendingCounts = bms_user_pending_counts();
        $pendingApproval = (int)($pendingCounts['pending_approval'] ?? 0);
        if ($pendingApproval > 0) {
            $usersLink = htmlspecialchars(bms_admin_url('users.php'), ENT_QUOTES, 'UTF-8');
            $plural = $pendingApproval === 1 ? 'account is' : 'accounts are';
            echo '<div class="notice-stack" aria-live="polite"><div class="flash notice warning" role="status"><span class="notice-icon" aria-hidden="true">!</span><div class="notice-copy"><strong>Approval needed</strong><p>' . $pendingApproval . ' ' . $plural . ' waiting for admin approval. <a href="' . $usersLink . '">Review pending commenters</a>.</p></div></div></div>';
        }
    }

    echo '<div class="admin-page-title"><h1>' . $safeTitle . '</h1>';
    if ($actions) {
        echo '<div class="admin-page-actions">';
        foreach ($actions as $action) {
            echo bms_admin_action_link($action);
        }
        echo '</div>';
    }
    echo '</div>';
}

function bms_admin_footer(): void
{
    $version = htmlspecialchars(bms_version(), ENT_QUOTES, 'UTF-8');
    echo '<footer class="admin-footer">Bonumark Stream v' . $version . '</footer></main></section></div></body></html>';
}
