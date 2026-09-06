<?php
require_once __DIR__ . '/../_bonumark_stream/app/profiles.php';
require_once __DIR__ . '/../_bonumark_stream/app/appearance.php';
require_once __DIR__ . '/../_bonumark_stream/app/pwa.php';
require_once __DIR__ . '/../_bonumark_stream/app/scheduler.php';

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

    $routeActive = static function (array $files) use ($currentAdminFile): bool {
        return in_array($currentAdminFile, $files, true);
    };

    $renderNavLink = static function (array $link) use ($routeActive): string {
        $label = htmlspecialchars((string)($link['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars((string)($link['href'] ?? '#'), ENT_QUOTES, 'UTF-8');
        $files = is_array($link['files'] ?? null) ? $link['files'] : [];
        $active = $files && $routeActive($files);
        $class = 'admin-nav-link' . ($active ? ' is-active' : '');
        if (!empty($link['class'])) {
            $class .= ' ' . preg_replace('/[^a-zA-Z0-9_\- ]/', '', (string)$link['class']);
        }
        $target = !empty($link['target']) ? ' target="_blank" rel="noopener"' : '';
        $current = $active ? ' aria-current="page"' : '';
        return '<a class="' . $class . '" href="' . $href . '"' . $target . $current . '><span>' . $label . '</span></a>';
    };

    $publishLinks = [
        [
            'label' => 'Stream Posts',
            'href' => bms_admin_url('content.php'),
            'files' => ['content.php', 'edit.php', 'preview.php', 'publish.php', 'unpublish.php', 'quick-edit.php', 'stream-quick-edit.php', 'stream-trash.php', 'revisions.php', 'compare-revision.php', 'restore-revision.php', 'restore.php', 'delete.php', 'delete-permanent.php'],
        ],
        [
            'label' => 'Stream Composer',
            'href' => bms_stream_composer_url(),
            'files' => ['new.php'],
        ],
    ];
    if ($can('manage_pages')) {
        $publishLinks[] = [
            'label' => 'Pages',
            'href' => bms_admin_url('pages.php'),
            'files' => ['pages.php', 'page-new.php', 'page-edit.php', 'page-publish.php', 'page-unpublish.php', 'page-delete.php', 'page-restore.php', 'page-delete-permanent.php'],
        ];
    }
    if ($can('manage_media')) {
        $publishLinks[] = [
            'label' => 'Media',
            'href' => bms_admin_url('media.php'),
            'files' => ['media.php', 'media-upload.php', 'media-edit.php', 'media-regenerate.php', 'media-picker.php'],
        ];
    }
    if ($can('edit_content')) {
        $publishLinks[] = [
            'label' => 'Places',
            'href' => bms_admin_url('places.php'),
            'files' => ['places.php', 'place-edit.php', 'place-delete.php', 'places-nearby.php', 'places-save.php'],
        ];
    }

    $manageLinks = [];
    if ($can('manage_comments')) {
        $manageLinks[] = [
            'label' => 'Comments',
            'href' => bms_admin_url('comments.php'),
            'files' => ['comments.php'],
        ];
    }
    if ($can('manage_users')) {
        $manageLinks[] = [
            'label' => 'Accounts',
            'href' => bms_admin_url('users.php'),
            'files' => ['users.php', 'user-new.php', 'user-edit.php'],
        ];
    }

    $designLinks = [];
    if ($can('manage_appearance')) {
        $designLinks = [
            [
                'label' => 'Themes',
                'href' => bms_admin_url('theme.php'),
                'files' => ['appearance.php', 'theme.php', 'theme-details.php', 'theme-install.php', 'theme-delete.php'],
            ],
            [
                'label' => 'Theme Settings',
                'href' => bms_admin_url('theme-settings.php'),
                'files' => ['theme-settings.php'],
            ],
            [
                'label' => 'Navigation',
                'href' => bms_admin_url('navigation.php'),
                'files' => ['navigation.php'],
            ],
            [
                'label' => 'Site Identity',
                'href' => bms_admin_url('site-identity.php'),
                'files' => ['site-identity.php'],
            ],
        ];
    }

    $settingsLinks = [];
    if ($can('manage_settings')) {
        $settingsLinks = [
            ['label' => 'General', 'href' => bms_admin_url('settings.php'), 'files' => ['settings.php']],
            ['label' => 'Writing', 'href' => bms_admin_url('settings-writing.php'), 'files' => ['settings-writing.php']],
            ['label' => 'Reading', 'href' => bms_admin_url('settings-reading.php'), 'files' => ['settings-reading.php']],
            ['label' => 'Security', 'href' => bms_admin_url('security.php'), 'files' => ['security.php']],
            ['label' => 'Registration', 'href' => bms_admin_url('registration.php'), 'files' => ['registration.php']],
            ['label' => 'Mail', 'href' => bms_admin_url('mail.php'), 'files' => ['mail.php']],
        ];
    }

    $systemLinks = [];
    if ($can('view_system')) {
        $systemLinks = [
            ['label' => 'Tools', 'href' => bms_admin_url('tools.php'), 'files' => ['tools.php']],
            ['label' => 'Analytics', 'href' => bms_admin_url('analytics.php'), 'files' => ['analytics.php']],
            ['label' => 'Scheduled Tasks', 'href' => bms_admin_url('scheduled-tasks.php'), 'files' => ['scheduled-tasks.php', 'scheduled-runner.php']],
            ['label' => 'ActivityPub', 'href' => bms_admin_url('activitypub.php'), 'files' => ['activitypub.php']],
            ['label' => 'Remote Posting', 'href' => bms_admin_url('remote-posting.php'), 'files' => ['remote-posting.php']],
            ['label' => 'Export', 'href' => bms_admin_url('export.php'), 'files' => ['export.php']],
            ['label' => 'Import', 'href' => bms_admin_url('import.php'), 'files' => ['import.php', 'import-markdown.php']],
            ['label' => 'Upgrade', 'href' => bms_admin_url('upgrade.php'), 'files' => ['upgrade.php']],
            ['label' => 'System Check', 'href' => bms_admin_url('system-check.php'), 'files' => ['system-check.php']],
            ['label' => 'Help', 'href' => bms_admin_url('help.php'), 'files' => ['help.php']],
        ];
    } else {
        $systemLinks = [
            ['label' => 'Help', 'href' => bms_admin_url('help.php'), 'files' => ['help.php']],
        ];
    }

    $navSections = [
        ['label' => 'Publish', 'links' => $publishLinks],
        ['label' => 'Manage', 'links' => $manageLinks],
        ['label' => 'Design', 'links' => $designLinks],
        ['label' => 'Settings', 'links' => $settingsLinks],
        ['label' => 'System', 'links' => $systemLinks],
    ];

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $safeTitle . ' | Bonumark Stream Admin</title>' . $adminFaviconTags . $adminPwaTags;
    echo '<link rel="stylesheet" href="' . $styleUrl . '"><link rel="stylesheet" href="' . $adminStyleUrl . '"><link rel="stylesheet" href="' . $adminShellStyleUrl . '"><link rel="stylesheet" href="' . $adminContentListStyleUrl . '"><link rel="stylesheet" href="' . $adminEditorWorkflowStyleUrl . '"><link rel="stylesheet" href="' . $adminMediaLibraryStyleUrl . '"><link rel="stylesheet" href="' . $adminCommentsStyleUrl . '"><link rel="stylesheet" href="' . $adminAccountsStyleUrl . '"><link rel="stylesheet" href="' . $adminRegistrationStyleUrl . '"><link rel="stylesheet" href="' . $adminAppearanceStyleUrl . '"><link rel="stylesheet" href="' . $adminSettingsStyleUrl . '"><link rel="stylesheet" href="' . $adminPlacesStyleUrl . '"><link rel="stylesheet" href="' . $adminOperationsStyleUrl . '"><script src="' . $adminScriptUrl . '" defer></script><script src="' . $adminPlacesScriptUrl . '" defer></script></head>';
    echo '<body class="bonumark-admin admin-screen-' . htmlspecialchars($screenSlug, ENT_QUOTES, 'UTF-8') . '" data-scheduled-runner-url="' . $scheduledRunnerUrl . '" data-scheduled-runner-csrf="' . $csrf . '">';
    echo '<div class="admin-shell">';

    echo '<header class="admin-mobile-bar">';
    echo '<button class="admin-mobile-menu-button" type="button" data-admin-nav-open aria-controls="admin-sidebar" aria-expanded="false"><span class="admin-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span><span class="screen-reader-text">Open admin navigation</span></button>';
    echo '<a class="admin-mobile-brand" href="' . htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') . '"><span class="admin-brand-mark">B</span><span>' . $safeTitle . '</span></a>';
    echo '<a class="admin-mobile-profile" href="' . $safeAdminProfileUrl . '" aria-label="Account for ' . $safeProfileOwnerLabel . '">' . $adminAvatarMarkup . '<span class="screen-reader-text">Profile</span></a>';
    echo '</header>';
    echo '<div class="admin-sidebar-backdrop" data-admin-nav-close hidden></div>';

    echo '<aside class="admin-sidebar" id="admin-sidebar" aria-label="Admin navigation" aria-hidden="false">';
    echo '<div class="admin-sidebar-header"><a class="admin-brand" href="' . htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') . '"><span class="admin-brand-mark">B</span><span class="admin-brand-copy"><strong>Bonumark</strong><small>Stream Admin</small></span></a><button class="admin-sidebar-close" type="button" data-admin-nav-close><span aria-hidden="true">×</span><span class="screen-reader-text">Close admin navigation</span></button></div>';
    echo '<nav class="admin-sidebar-nav" aria-label="Primary admin navigation">';
    echo $renderNavLink(['label' => 'Dashboard', 'href' => $dashboardUrl, 'files' => ['index.php'], 'class' => 'nav-primary']);
    echo $renderNavLink(['label' => 'View Site', 'href' => $siteUrl, 'files' => [], 'target' => true, 'class' => 'admin-sidebar-view-site']);

    foreach ($navSections as $section) {
        $links = is_array($section['links'] ?? null) ? array_values(array_filter($section['links'])) : [];
        if (!$links) {
            continue;
        }
        $sectionActive = false;
        foreach ($links as $link) {
            $files = is_array($link['files'] ?? null) ? $link['files'] : [];
            if ($files && $routeActive($files)) {
                $sectionActive = true;
                break;
            }
        }
        $open = $sectionActive ? ' open' : '';
        $activeClass = $sectionActive ? ' is-active' : '';
        echo '<details class="admin-nav-section' . $activeClass . '"' . $open . '><summary class="admin-nav-heading"><span>' . htmlspecialchars((string)$section['label'], ENT_QUOTES, 'UTF-8') . '</span></summary><div class="admin-nav-links">';
        foreach ($links as $link) {
            echo $renderNavLink($link);
        }
        echo '</div></details>';
    }
    echo '</nav>';

    echo '<div class="admin-sidebar-footer">';
    echo '<a class="admin-sidebar-profile" href="' . $safeAdminProfileUrl . '">' . $adminAvatarMarkup . '<span class="admin-sidebar-profile-copy"><strong>' . $safeDisplayName . '</strong><small>' . $safeHandleLabel . '</small></span></a>';
    echo '<form method="post" action="' . htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') . '" class="admin-sidebar-logout"><input type="hidden" name="csrf_token" value="' . $csrf . '"><button type="submit">Sign out</button></form>';
    echo '</div></aside>';

    echo '<section class="admin-main">';
    echo '<header class="admin-topbar"><div class="admin-topbar-context"><span>Bonumark Stream</span><strong>Administration</strong></div><div class="admin-topbar-actions"><a class="admin-topbar-site" href="' . htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">View site <span aria-hidden="true">↗</span></a><a class="admin-user" href="' . $safeAdminProfileUrl . '" aria-label="Account for ' . $safeProfileOwnerLabel . '">' . $adminAvatarMarkup . '<span class="admin-user-copy"><strong>' . $safeDisplayName . '</strong><small>' . $safeHandleLabel . '</small></span></a></div></header>';
    echo '<main class="admin-content">';

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

    echo '<div class="admin-page-title"><div class="admin-page-heading-copy"><p class="admin-page-kicker">Administration</p><h1>' . $safeTitle . '</h1></div>';
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
    echo '<footer class="admin-footer"><span>Bonumark Stream</span><span>v' . $version . '</span></footer></main></section></div></body></html>';
}
