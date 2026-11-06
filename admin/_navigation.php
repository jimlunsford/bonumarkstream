<?php
/* Presentation-only navigation. Route controllers retain their own capability checks. */

function bms_admin_settings_groups(): array
{
    return [
        'Site' => [
            ['label' => 'General', 'path' => 'settings.php', 'capability' => 'manage_settings', 'description' => 'Name, email and timezone'],
            ['label' => 'Writing', 'path' => 'settings-writing.php', 'capability' => 'manage_settings', 'description' => 'Publishing defaults'],
            ['label' => 'Reading', 'path' => 'settings-reading.php', 'capability' => 'manage_settings', 'description' => 'Stream, feeds and session options'],
            ['label' => 'Site identity', 'path' => 'site-identity.php', 'capability' => 'manage_appearance', 'description' => 'Public title and favicon'],
            ['label' => 'Themes', 'path' => 'theme.php', 'capability' => 'manage_appearance', 'description' => 'Choose and manage public themes', 'files' => ['appearance.php', 'theme.php', 'theme-details.php', 'theme-install.php', 'theme-delete.php']],
            ['label' => 'Theme settings', 'path' => 'theme-settings.php', 'capability' => 'manage_appearance', 'description' => 'Customize the active public theme'],
            ['label' => 'Navigation', 'path' => 'navigation.php', 'capability' => 'manage_appearance', 'description' => 'Public menus and links'],
        ],
        'Access' => [
            ['label' => 'Security', 'path' => 'security.php', 'capability' => 'manage_settings', 'description' => 'Access overview'],
            ['label' => 'Registration', 'path' => 'registration.php', 'capability' => 'manage_settings', 'description' => 'Commenter signup and approval'],
            ['label' => 'Mail', 'path' => 'mail.php', 'capability' => 'manage_settings', 'description' => 'Delivery and recovery email'],
        ],
        'Connections' => [
            ['label' => 'ActivityPub', 'path' => 'activitypub.php', 'capability' => 'manage_settings', 'description' => 'Federation and moderation'],
            ['label' => 'Remote posting', 'path' => 'remote-posting.php', 'capability' => 'manage_settings', 'description' => 'API access and tokens'],
        ],
    ];
}

function bms_admin_navigation_areas(): array
{
    $settingsFiles = ['settings-index.php'];
    foreach (bms_admin_settings_groups() as $links) {
        foreach ($links as $link) {
            $settingsFiles = array_merge($settingsFiles, $link['files'] ?? [$link['path']]);
        }
    }
    return [
        'dashboard' => ['label' => 'Dashboard', 'path' => 'index.php', 'capability' => 'view_admin', 'files' => ['index.php']],
        'content' => [
            'label' => 'Content', 'path' => 'content.php', 'capability' => 'edit_content',
            'files' => ['content.php', 'new.php', 'edit.php', 'preview.php', 'preview-current.php', 'publish.php', 'unpublish.php', 'pin.php', 'quick-edit.php', 'stream-quick-edit.php', 'stream-trash.php', 'revisions.php', 'compare-revision.php', 'restore-revision.php', 'restore.php', 'delete.php', 'delete-permanent.php', 'pages.php', 'page-new.php', 'page-edit.php', 'page-publish.php', 'page-unpublish.php', 'page-delete.php', 'page-restore.php', 'page-delete-permanent.php', 'places.php', 'place-edit.php', 'place-delete.php', 'analytics.php'],
        ],
        'media' => ['label' => 'Media', 'path' => 'media.php', 'capability' => 'manage_media', 'files' => ['media.php', 'media-upload.php', 'media-edit.php', 'media-regenerate.php']],
        'comments' => ['label' => 'Comments', 'path' => 'comments.php', 'capability' => 'manage_comments', 'files' => ['comments.php', 'users.php', 'user-new.php', 'user-edit.php']],
        'settings' => ['label' => 'Settings', 'path' => 'settings-index.php', 'capability' => 'manage_settings', 'files' => $settingsFiles],
        'system' => ['label' => 'System', 'path' => 'tools.php', 'capability' => 'view_system', 'files' => ['tools.php', 'scheduled-tasks.php', 'export.php', 'import.php', 'import-markdown.php', 'upgrade.php', 'system-check.php', 'help.php', 'welcome.php']],
    ];
}

function bms_admin_current_area(string $file): string
{
    foreach (bms_admin_navigation_areas() as $area => $link) {
        if (in_array($file, $link['files'], true)) {
            return $area;
        }
    }
    return '';
}

function bms_admin_navigation_visible(array $links): array
{
    return array_values(array_filter($links, static function (array $link): bool {
        return function_exists('bms_current_user_can') && bms_current_user_can($link['capability']);
    }));
}

function bms_admin_context_links(string $area): array
{
    return match ($area) {
        'content' => [
            ['label' => 'Stream posts', 'path' => 'content.php', 'capability' => 'edit_content', 'files' => ['content.php', 'edit.php', 'quick-edit.php', 'revisions.php', 'compare-revision.php']],
            ['label' => 'Pages', 'path' => 'pages.php', 'capability' => 'manage_pages', 'files' => ['pages.php', 'page-new.php', 'page-edit.php']],
            ['label' => 'Places', 'path' => 'places.php', 'capability' => 'edit_content', 'files' => ['places.php', 'place-edit.php', 'place-delete.php']],
            ['label' => 'Analytics', 'path' => 'analytics.php', 'capability' => 'view_system'],
            ['label' => 'Open Stream composer', 'href' => bms_stream_composer_url(), 'capability' => 'publish_content'],
        ],
        'media' => [
            ['label' => 'Library', 'path' => 'media.php', 'capability' => 'manage_media', 'files' => ['media.php', 'media-edit.php']],
            ['label' => 'Upload', 'path' => 'media-upload.php', 'capability' => 'manage_media'],
            ['label' => 'Optimize images', 'path' => 'media-regenerate.php', 'capability' => 'manage_media'],
        ],
        'comments' => [
            ['label' => 'Comments', 'path' => 'comments.php', 'capability' => 'manage_comments'],
            ['label' => 'Commenter accounts', 'path' => 'users.php', 'capability' => 'manage_users', 'files' => ['users.php', 'user-new.php', 'user-edit.php']],
        ],
        'system' => [
            ['label' => 'Overview', 'path' => 'tools.php', 'capability' => 'view_system'],
            ['label' => 'System Check', 'path' => 'system-check.php', 'capability' => 'view_system'],
            ['label' => 'Scheduled tasks', 'path' => 'scheduled-tasks.php', 'capability' => 'manage_settings'],
            ['label' => 'Export', 'path' => 'export.php', 'capability' => 'view_system'],
            ['label' => 'Import', 'path' => 'import.php', 'capability' => 'view_system', 'files' => ['import.php', 'import-markdown.php']],
            ['label' => 'Upgrade', 'path' => 'upgrade.php', 'capability' => 'view_system'],
            ['label' => 'Help', 'path' => 'help.php', 'capability' => 'view_admin'],
        ],
        default => [],
    };
}

function bms_admin_navigation_link(array $link, string $currentFile, string $class): string
{
    $path = (string)($link['path'] ?? '');
    $files = $link['files'] ?? ($path !== '' ? [$path] : []);
    $active = in_array($currentFile, $files, true);
    $current = $active ? ' aria-current="' . ($currentFile === $path ? 'page' : 'location') . '"' : '';
    $class .= $active ? ' is-active' : '';
    $href = (string)($link['href'] ?? bms_admin_url($path));
    return '<a class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $current . '><span>' . htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') . '</span></a>';
}

function bms_admin_settings_has_local_navigation(string $file): bool
{
    return in_array($file, ['settings.php', 'settings-writing.php', 'settings-reading.php', 'site-identity.php', 'theme-settings.php', 'navigation.php', 'registration.php', 'mail.php'], true);
}

function bms_admin_render_settings_navigation(string $currentFile): void
{
    echo '<nav class="admin-settings-local-nav" aria-label="Settings navigation">';
    foreach (bms_admin_settings_groups() as $heading => $links) {
        $links = bms_admin_navigation_visible($links);
        if (!$links) {
            continue;
        }
        echo '<div class="admin-settings-nav-group"><h2>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h2>';
        foreach ($links as $link) {
            echo bms_admin_navigation_link($link, $currentFile, 'admin-context-link');
        }
        echo '</div>';
    }
    echo '</nav>';
}
