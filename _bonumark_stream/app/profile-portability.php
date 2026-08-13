<?php
require_once __DIR__ . '/profiles.php';

function bms_profile_portability_export_media(array $user, array $identity): array
{
    $media = [
        'avatar' => null,
        'cover' => null,
        'photos' => [],
    ];

    $candidates = [
        'avatar' => (string)($user['avatar_path'] ?? ''),
        'cover' => (string)($identity['cover_image_path'] ?? ''),
    ];

    foreach ($candidates as $kind => $rawPath) {
        $rawPath = trim(str_replace('\\', '/', $rawPath));
        if ($rawPath === '') {
            continue;
        }

        if (preg_match('#^https?://#i', $rawPath)) {
            $media[$kind] = ['url' => $rawPath];
            continue;
        }

        $relative = ltrim($rawPath, '/');
        if (!str_starts_with($relative, 'media/') || str_contains($relative, '..')) {
            continue;
        }

        $sourcePath = bms_public_path($relative);
        $realSource = realpath($sourcePath);
        $realMediaRoot = realpath(bms_public_path('media'));
        if ($realSource === false || $realMediaRoot === false || !is_file($realSource)) {
            continue;
        }

        $mediaPrefix = rtrim(str_replace('\\', '/', $realMediaRoot), '/') . '/';
        $sourceNormalized = str_replace('\\', '/', $realSource);
        if (!str_starts_with($sourceNormalized, $mediaPrefix)) {
            continue;
        }

        $extension = strtolower((string)pathinfo($realSource, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? '.' . $extension : '';
        $exportPath = 'profile-media/' . $kind . $extension;
        $media[$kind] = [
            'file' => $exportPath,
            '_source_path' => $realSource,
        ];
    }

    $userId = (int)($user['id'] ?? 0);
    $photoIndex = 1;
    foreach (bms_profile_safe_photos(is_array($identity['profile_photos'] ?? null) ? $identity['profile_photos'] : [], $userId) as $photo) {
        if ($photoIndex > 4) {
            break;
        }
        $relative = bms_profile_photo_normalize_path((string)($photo['path'] ?? ''), $userId);
        if ($relative === '') {
            continue;
        }
        $sourcePath = bms_public_path($relative);
        $realSource = realpath($sourcePath);
        $realMediaRoot = realpath(bms_public_path('media'));
        if ($realSource === false || $realMediaRoot === false || !is_file($realSource)) {
            continue;
        }
        $mediaPrefix = rtrim(str_replace('\\', '/', $realMediaRoot), '/') . '/';
        $sourceNormalized = str_replace('\\', '/', $realSource);
        if (!str_starts_with($sourceNormalized, $mediaPrefix)) {
            continue;
        }
        $extension = strtolower((string)pathinfo($realSource, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? '.' . $extension : '';
        $media['photos'][] = [
            'file' => 'profile-media/photo-' . $photoIndex . $extension,
            'alt' => trim((string)($photo['alt'] ?? '')),
            'caption' => trim((string)($photo['caption'] ?? '')),
            '_source_path' => $realSource,
        ];
        $photoIndex++;
    }

    return $media;
}

function bms_profile_portability_public_media(array $media): array
{
    $public = [];
    foreach (['avatar', 'cover'] as $kind) {
        $entry = $media[$kind] ?? null;
        if (!is_array($entry)) {
            $public[$kind] = null;
            continue;
        }
        if (isset($entry['file'])) {
            $public[$kind] = ['file' => (string)$entry['file']];
            continue;
        }
        if (isset($entry['url'])) {
            $public[$kind] = ['url' => (string)$entry['url']];
            continue;
        }
        $public[$kind] = null;
    }
    return $public;
}

function bms_profile_portability_payload(array $user, array $identity, array $media): array
{
    $username = bms_normalize_username((string)($user['username'] ?? ''));
    $profileUrl = bms_profile_absolute_public_url(bms_public_profile_url_for_user($user));

    return [
        'format' => 'bonumark-profile',
        'format_version' => 1,
        'exported_at' => date('c'),
        'source' => [
            'application' => 'Bonumark Stream',
            'version' => bms_version(),
            'profile_url' => $profileUrl,
        ],
        'profile' => [
            'username' => $username,
            'display_name' => trim((string)($user['display_name'] ?? '')),
            'headline' => trim((string)($identity['headline'] ?? '')),
            'location' => trim((string)($identity['location'] ?? '')),
            'short_bio' => trim((string)($user['bio'] ?? '')),
            'about_markdown' => trim((string)($identity['about_markdown'] ?? '')),
            'now' => trim((string)($identity['now_text'] ?? '')),
            'website' => trim((string)($user['website'] ?? '')),
            'visibility' => (string)($user['profile_visibility'] ?? 'public') === 'private' ? 'private' : 'public',
            'links' => bms_profile_safe_links(is_array($identity['links'] ?? null) ? $identity['links'] : []),
            'interests' => array_slice(array_values(array_filter(array_map(static fn($item): string => trim((string)$item), is_array($identity['interests'] ?? null) ? $identity['interests'] : []), static fn(string $item): bool => $item !== '')), 0, 12),
            'featured_items' => array_slice(array_values(array_filter(array_map(static function ($item): ?array {
                if (!is_array($item)) {
                    return null;
                }
                $type = bms_profile_featured_type((string)($item['type'] ?? 'external'));
                $target = trim((string)($item['target'] ?? ''));
                if ($target === '') {
                    return null;
                }
                return [
                    'type' => $type,
                    'target' => $target,
                    'title' => trim((string)($item['title'] ?? '')),
                    'description' => trim((string)($item['description'] ?? '')),
                ];
            }, is_array($identity['featured_items'] ?? null) ? $identity['featured_items'] : []))), 0, 4),
            'photos' => array_values(array_map(static function ($photo): array {
                return [
                    'media' => (string)($photo['file'] ?? $photo['url'] ?? ''),
                    'alt' => trim((string)($photo['alt'] ?? '')),
                    'caption' => trim((string)($photo['caption'] ?? '')),
                ];
            }, is_array($media['photos'] ?? null) ? $media['photos'] : [])),
            'optional_details' => [
                'show_post_count' => !empty($identity['show_post_count']),
                'show_comment_count' => !empty($identity['show_comment_count']),
                'show_member_since' => !empty($identity['show_member_since']),
            ],
            'media' => bms_profile_portability_public_media($media),
        ],
    ];
}

function bms_profile_portability_markdown(array $payload): string
{
    $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
    $lines = [];
    $name = trim((string)($profile['display_name'] ?? ''));
    $username = trim((string)($profile['username'] ?? ''));

    $lines[] = '# ' . ($name !== '' ? $name : ($username !== '' ? '@' . $username : 'Profile'));
    if ($username !== '') {
        $lines[] = '';
        $lines[] = '@' . $username;
    }

    foreach ([
        'headline' => 'Headline',
        'location' => 'Location',
        'website' => 'Website',
        'visibility' => 'Visibility',
    ] as $key => $label) {
        $value = trim((string)($profile[$key] ?? ''));
        if ($value !== '') {
            $lines[] = $label . ': ' . $value;
        }
    }

    $bio = trim((string)($profile['short_bio'] ?? ''));
    if ($bio !== '') {
        $lines[] = '';
        $lines[] = $bio;
    }

    $about = trim((string)($profile['about_markdown'] ?? ''));
    if ($about !== '') {
        $lines[] = '';
        $lines[] = '## About';
        $lines[] = '';
        $lines[] = $about;
    }

    $now = trim((string)($profile['now'] ?? ''));
    if ($now !== '') {
        $lines[] = '';
        $lines[] = '## Now';
        $lines[] = '';
        $lines[] = $now;
    }

    $interests = is_array($profile['interests'] ?? null) ? $profile['interests'] : [];
    if ($interests) {
        $lines[] = '';
        $lines[] = '## Interests';
        $lines[] = '';
        foreach ($interests as $interest) {
            $interest = trim((string)$interest);
            if ($interest !== '') {
                $lines[] = '- ' . $interest;
            }
        }
    }

    $links = is_array($profile['links'] ?? null) ? $profile['links'] : [];
    if ($links) {
        $lines[] = '';
        $lines[] = '## Links';
        $lines[] = '';
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $label = trim((string)($link['label'] ?? ''));
            $url = trim((string)($link['url'] ?? ''));
            if ($label !== '' && $url !== '') {
                $lines[] = '- [' . str_replace([']', '['], ['\\]', '\\['], $label) . '](' . str_replace(')', '%29', $url) . ')';
            }
        }
    }

    $featured = is_array($profile['featured_items'] ?? null) ? $profile['featured_items'] : [];
    if ($featured) {
        $lines[] = '';
        $lines[] = '## Featured work';
        $lines[] = '';
        foreach ($featured as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = bms_profile_featured_type((string)($item['type'] ?? 'external'));
            $target = trim((string)($item['target'] ?? ''));
            $title = trim((string)($item['title'] ?? ''));
            $description = trim((string)($item['description'] ?? ''));
            $label = $title !== '' ? $title : ($target !== '' ? $target : bms_profile_featured_type_label($type));
            if ($type === 'external' && filter_var($target, FILTER_VALIDATE_URL)) {
                $lines[] = '- [' . str_replace([']', '['], ['\\]', '\\['], $label) . '](' . str_replace(')', '%29', $target) . ')';
            } else {
                $lines[] = '- ' . $label . ' (' . bms_profile_featured_type_label($type) . ': ' . $target . ')';
            }
            if ($description !== '') {
                $lines[] = '  ' . $description;
            }
        }
    }

    $photos = is_array($profile['photos'] ?? null) ? $profile['photos'] : [];
    if ($photos) {
        $lines[] = '';
        $lines[] = '## Photos';
        $lines[] = '';
        foreach ($photos as $index => $photo) {
            if (!is_array($photo)) {
                continue;
            }
            $mediaPath = trim((string)($photo['media'] ?? ''));
            if ($mediaPath === '') {
                continue;
            }
            $lines[] = '- Photo ' . ((int)$index + 1) . ': ' . $mediaPath;
            $alt = trim((string)($photo['alt'] ?? ''));
            $caption = trim((string)($photo['caption'] ?? ''));
            if ($alt !== '') {
                $lines[] = '  Alt: ' . $alt;
            }
            if ($caption !== '') {
                $lines[] = '  Caption: ' . $caption;
            }
        }
    }

    $media = is_array($profile['media'] ?? null) ? $profile['media'] : [];
    if ($media) {
        $mediaLines = [];
        foreach (['avatar' => 'Profile picture', 'cover' => 'Cover image'] as $kind => $label) {
            $entry = $media[$kind] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            $value = trim((string)($entry['file'] ?? $entry['url'] ?? ''));
            if ($value !== '') {
                $mediaLines[] = '- ' . $label . ': ' . $value;
            }
        }
        if ($mediaLines) {
            $lines[] = '';
            $lines[] = '## Profile media';
            $lines[] = '';
            array_push($lines, ...$mediaLines);
        }
    }

    $options = is_array($profile['optional_details'] ?? null) ? $profile['optional_details'] : [];
    $lines[] = '';
    $lines[] = '## Optional public details';
    $lines[] = '';
    $lines[] = '- Show published post count: ' . (!empty($options['show_post_count']) ? 'yes' : 'no');
    $lines[] = '- Show approved comment count: ' . (!empty($options['show_comment_count']) ? 'yes' : 'no');
    $lines[] = '- Show member-since date: ' . (!empty($options['show_member_since']) ? 'yes' : 'no');

    return rtrim(implode("\n", $lines)) . "\n";
}

function bms_profile_portability_readme(): string
{
    return "Bonumark Stream Profile export\n\n"
        . "profile.json is the structured, machine-readable identity export.\n"
        . "profile.md is a human-readable Markdown copy of the same Profile identity.\n"
        . "profile-media/ contains the original local Profile picture, cover image, and up to four Profile photos when those files are available. Generated responsive variants are not included.\n\n"
        . "This export contains Profile identity, public links, interests, Featured work references, Profile photos with alt text and captions, visibility, and optional-public-detail preferences.\n"
        . "It does not contain email addresses, password hashes, roles, login/security records, post or comment contents, activity counts, API credentials, or theme presentation settings.\n\n"
        . "Featured Stream posts and Pages are exported as type + slug references. Their content is not copied into this Profile package.\n";
}

function bms_profile_portability_write_zip(string $path, array $payload, array $media): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Profile export requires PHP ZipArchive.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the Profile export ZIP.');
    }

    try {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode Profile identity as JSON.');
        }
        $zip->addFromString('profile.json', $json . "\n");
        $zip->addFromString('profile.md', bms_profile_portability_markdown($payload));
        $zip->addFromString('README.txt', bms_profile_portability_readme());
        $zip->addEmptyDir('profile-media');

        foreach (['avatar', 'cover'] as $kind) {
            $entry = $media[$kind] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            $sourcePath = (string)($entry['_source_path'] ?? '');
            $exportPath = (string)($entry['file'] ?? '');
            if ($sourcePath !== '' && $exportPath !== '' && is_file($sourcePath)) {
                $zip->addFile($sourcePath, $exportPath);
            }
        }
        foreach (is_array($media['photos'] ?? null) ? $media['photos'] : [] as $photo) {
            if (!is_array($photo)) {
                continue;
            }
            $sourcePath = (string)($photo['_source_path'] ?? '');
            $exportPath = (string)($photo['file'] ?? '');
            if ($sourcePath !== '' && $exportPath !== '' && is_file($sourcePath)) {
                $zip->addFile($sourcePath, $exportPath);
            }
        }
    } finally {
        $zip->close();
    }
}

function bms_create_current_user_profile_export_zip(): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Profile export requires PHP ZipArchive.');
    }

    $user = bms_current_user();
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) {
        throw new RuntimeException('You must be signed in to export a Profile.');
    }

    $identity = bms_profile_identity_for_user($userId, $user);
    $media = bms_profile_portability_export_media($user, $identity);
    $payload = bms_profile_portability_payload($user, $identity, $media);

    $tmpDir = bms_root_path('tmp/profile-exports');
    if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('Could not prepare the Profile export folder.');
    }

    $username = bms_normalize_username((string)($user['username'] ?? ''));
    if ($username === '') {
        $username = 'profile';
    }
    $filename = 'bonumark-profile-' . $username . '-' . date('Ymd-His') . '.zip';
    $path = $tmpDir . '/' . $filename;

    bms_profile_portability_write_zip($path, $payload, $media);

    if (!is_file($path) || filesize($path) < 1) {
        @unlink($path);
        throw new RuntimeException('Profile export could not be completed.');
    }

    return [
        'path' => $path,
        'filename' => $filename,
        'payload' => $payload,
    ];
}
