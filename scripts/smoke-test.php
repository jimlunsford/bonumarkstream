<?php
/**
 * Bonumark Stream package smoke test.
 *
 * This script validates package metadata, migrations, release manifest hashes,
 * theme manifests, CSS brace balance, and common release hygiene rules.
 *
 * Database-backed smoke tests are intentionally separate because they require
 * real BMS_DB_* environment variables and BMS_DB_DANGER_RESET=1. That keeps
 * the package smoke test from touching a live database by accident.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('CLI only.');
}

$root = dirname(__DIR__);
$failures = [];
$functionsSource = @file_get_contents($root . '/_bonumark_stream/app/functions.php') ?: '';
$databaseSource = @file_get_contents($root . '/_bonumark_stream/app/database.php') ?: '';

function bm_smoke_fail(array &$failures, string $message): void
{
    $failures[] = $message;
}

function bm_smoke_relative(string $root, string $path): string
{
    return str_replace('\\', '/', substr($path, strlen($root) + 1));
}

function bm_smoke_files(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $files[] = $item->getPathname();
        }
    }

    sort($files);
    return $files;
}

$rootVersion = trim((string)@file_get_contents($root . '/VERSION'));
$privateVersion = trim((string)@file_get_contents($root . '/_bonumark_stream/VERSION'));
$packagePath = $root . '/_bonumark_stream/PACKAGE.json';
$package = is_file($packagePath) ? json_decode((string)file_get_contents($packagePath), true) : null;

if ($rootVersion === '') {
    bm_smoke_fail($failures, 'Root VERSION is missing or empty.');
}
if ($privateVersion === '') {
    bm_smoke_fail($failures, 'Private VERSION is missing or empty.');
}
if ($rootVersion !== '' && $privateVersion !== '' && $rootVersion !== $privateVersion) {
    bm_smoke_fail($failures, 'Root VERSION and private VERSION do not match.');
}
if (!is_array($package)) {
    bm_smoke_fail($failures, 'PACKAGE.json is missing or invalid.');
} elseif (($package['version'] ?? '') !== $rootVersion) {
    bm_smoke_fail($failures, 'PACKAGE.json version does not match VERSION.');
}
if (is_array($package) && (($package['license'] ?? '') !== 'AGPL-3.0-or-later')) {
    bm_smoke_fail($failures, 'PACKAGE.json license must be AGPL-3.0-or-later.');
}

$license = @file_get_contents($root . '/LICENSE') ?: '';
if (!str_contains($license, 'GNU AFFERO GENERAL PUBLIC LICENSE')) {
    bm_smoke_fail($failures, 'LICENSE does not contain the AGPLv3 license text.');
}
if (!str_contains($license, 'SPDX-License-Identifier: AGPL-3.0-or-later')) {
    bm_smoke_fail($failures, 'LICENSE does not contain the project SPDX notice.');
}

$configSample = @file_get_contents($root . '/_bonumark_stream/config.sample.php') ?: '';
if ($rootVersion !== '' && !str_contains($configSample, "'version' => '" . $rootVersion . "'")) {
    bm_smoke_fail($failures, 'config.sample.php does not contain the current version.');
}

$functionDefaults = @file_get_contents($root . '/_bonumark_stream/app/functions.php') ?: '';
if ($rootVersion !== '' && !str_contains($functionDefaults, "'version' => '" . $rootVersion . "'")) {
    bm_smoke_fail($failures, 'functions.php default config does not contain the current version.');
}

$changelog = @file_get_contents($root . '/_bonumark_stream/CHANGELOG.md') ?: '';
if ($rootVersion !== '' && !str_contains($changelog, '## ' . $rootVersion . ' - ')) {
    bm_smoke_fail($failures, 'CHANGELOG.md does not include the current version heading.');
}

$readme = @file_get_contents($root . '/README.md') ?: '';
if ($rootVersion !== '' && !str_contains($readme, 'Current version: **' . $rootVersion . '**')) {
    bm_smoke_fail($failures, 'README.md current version is stale.');
}

$streamSettings = @file_get_contents($root . '/admin/settings-reading.php') ?: '';
if (!str_contains($streamSettings, "bms_admin_header('Stream Settings'") || !str_contains($streamSettings, '<h2>Stream</h2>') || !str_contains($streamSettings, 'Save Stream Settings')) {
    bm_smoke_fail($failures, 'Stream settings screen still contains stale Reading Settings labels.');
}
if (str_contains($streamSettings, 'Reading Settings') || str_contains($streamSettings, '<h2>Reading</h2>') || str_contains($streamSettings, 'Save Reading Settings')) {
    bm_smoke_fail($failures, 'Stream settings screen has legacy Reading Settings copy.');
}

$apiDocs = @file_get_contents($root . '/docs/API.md') ?: '';
if (!is_file($root . '/api/v1/stream/posts.php')) {
    bm_smoke_fail($failures, 'Remote stream posts API endpoint is missing.');
}
if (!is_file($root . '/api/v1/media.php')) {
    bm_smoke_fail($failures, 'Remote media API endpoint is missing.');
}
if (!is_file($root . '/api/v1/media/import.php')) {
    bm_smoke_fail($failures, 'Remote media import API endpoint is missing.');
}
if (!str_contains($apiDocs, 'POST /api/v1/stream/posts')) {
    bm_smoke_fail($failures, 'docs/API.md does not document the remote stream posts endpoint.');
}
if (!str_contains($apiDocs, 'POST /api/v1/media')) {
    bm_smoke_fail($failures, 'docs/API.md does not document the remote media endpoint.');
}
if (!str_contains($apiDocs, 'POST /api/v1/media/import')) {
    bm_smoke_fail($failures, 'docs/API.md does not document the remote media import endpoint.');
}
if (!str_contains($apiDocs, 'placeholder_media_rejected')) {
    bm_smoke_fail($failures, 'docs/API.md does not document placeholder media rejection.');
}
if (!str_contains($apiDocs, 'media_uploads')) {
    bm_smoke_fail($failures, 'docs/API.md does not document embedded media upload fields for remote stream posts.');
}
if (substr_count($apiDocs, 'Uploaded media can still be used in a second request') > 1) {
    bm_smoke_fail($failures, 'docs/API.md contains duplicate uploaded-media second-request wording.');
}
if (!str_contains($apiDocs, 'embedded_media')) {
    bm_smoke_fail($failures, 'docs/API.md does not document embedded media in the remote post response.');
}
if (!str_contains($apiDocs, 'stream:publish')) {
    bm_smoke_fail($failures, 'docs/API.md does not document the stream:publish scope.');
}
if (!str_contains($apiDocs, 'Idempotency-Key')) {
    bm_smoke_fail($failures, 'docs/API.md does not document idempotency.');
}
if (!str_contains($apiDocs, 'media:upload')) {
    bm_smoke_fail($failures, 'docs/API.md does not document the media:upload scope.');
}
$openApiPath = $root . '/docs/openapi/bonumark-stream-api.json';
if (!is_file($openApiPath)) {
    bm_smoke_fail($failures, 'OpenAPI schema is missing.');
} else {
    $openApi = json_decode((string)file_get_contents($openApiPath), true);
    if (!is_array($openApi) || ($openApi['openapi'] ?? '') === '' || empty($openApi['paths']['/api/v1/stream/posts']) || empty($openApi['paths']['/api/v1/media']) || empty($openApi['paths']['/api/v1/media/import'])) {
        bm_smoke_fail($failures, 'OpenAPI schema is invalid or missing required API paths.');
    } else {
        foreach (($openApi['paths'] ?? []) as $path => $methods) {
            if (is_array($methods) && array_key_exists('head', $methods)) {
                bm_smoke_fail($failures, 'OpenAPI Action schema must not include HEAD operations: ' . $path);
            }
            if (is_array($methods)) {
                foreach ($methods as $method => $operation) {
                    if (is_array($operation) && strlen((string)($operation['description'] ?? '')) > 300) {
                        bm_smoke_fail($failures, 'OpenAPI operation description is over 300 characters: ' . $path . ' ' . $method);
                    }
                }
            }
        }
    }
}
if (!is_file($root . '/docs/CHATGPT-ACTIONS.md')) {
    bm_smoke_fail($failures, 'ChatGPT Actions documentation is missing.');
}
$clientDocsPath = $root . '/docs/REMOTE-POSTING-CLIENTS.md';
$clientDocs = @file_get_contents($clientDocsPath) ?: '';
if (!is_file($clientDocsPath)) {
    bm_smoke_fail($failures, 'Remote Posting client examples documentation is missing.');
} else {
    foreach (['PowerShell', 'curl', 'Python', 'GitHub Actions', 'Apple Shortcuts', 'Zapier Webhooks', 'Make HTTP module', 'IFTTT Webhooks', 'Generic no-code automation tools'] as $requiredClientSection) {
        if (!str_contains($clientDocs, '## ' . $requiredClientSection)) {
            bm_smoke_fail($failures, 'Remote Posting client examples are missing section: ' . $requiredClientSection);
        }
    }
    foreach (['POST /api/v1/stream/posts', 'POST /api/v1/media', 'media_import_url', 'Idempotency-Key', 'Authorization: Bearer YOUR_API_TOKEN_HERE'] as $requiredClientText) {
        if (!str_contains($clientDocs, $requiredClientText)) {
            bm_smoke_fail($failures, 'Remote Posting client examples are missing required text: ' . $requiredClientText);
        }
    }
}
if (!str_contains($readme, 'docs/REMOTE-POSTING-CLIENTS.md')) {
    bm_smoke_fail($failures, 'README.md does not link to Remote Posting client examples.');
}
if (!str_contains($apiDocs, 'docs/REMOTE-POSTING-CLIENTS.md')) {
    bm_smoke_fail($failures, 'docs/API.md does not link to Remote Posting client examples.');
}

$apiApp = @file_get_contents($root . '/_bonumark_stream/app/api.php') ?: '';
$indexPhp = @file_get_contents($root . '/index.php') ?: '';
$htaccess = @file_get_contents($root . '/.htaccess') ?: '';
$remotePostingDocs = @file_get_contents($root . '/docs/REMOTE-POSTING.md') ?: '';
$chatGptActionsDocs = @file_get_contents($root . '/docs/CHATGPT-ACTIONS.md') ?: '';
$apiDatabaseSmoke = @file_get_contents($root . '/scripts/api-database-smoke-test.php') ?: '';
$apiRouteFiles = [
    'api/v1/status.php' => 'bms_api_handle_status_endpoint();',
    'api/v1/stream/posts.php' => 'bms_api_handle_stream_posts_endpoint();',
    'api/v1/media.php' => 'bms_api_handle_media_endpoint();',
    'api/v1/media/import.php' => 'bms_api_handle_media_import_endpoint();',
];
foreach ($apiRouteFiles as $relative => $handlerCall) {
    $path = $root . '/' . $relative;
    $contents = @file_get_contents($path) ?: '';
    if (!is_file($path)) {
        bm_smoke_fail($failures, 'Required API route file is missing: ' . $relative);
        continue;
    }
    if (!str_contains($contents, "require_once __DIR__") || !str_contains($contents, '_bonumark_stream/app/api.php') || !str_contains($contents, $handlerCall)) {
        bm_smoke_fail($failures, 'API route file does not load the shared API handler correctly: ' . $relative);
    }
}
$requiredHtaccessRules = [
    'SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1',
    'RewriteRule ^api/v1/status/?$ index.php?__bonumark_route=api_status [L,QSA]',
    'RewriteRule ^api/v1/stream/posts/?$ index.php?__bonumark_route=api_stream_posts [L,QSA]',
    'RewriteRule ^api/v1/media/import/?$ index.php?__bonumark_route=api_media_import [L,QSA]',
    'RewriteRule ^api/v1/media/?$ index.php?__bonumark_route=api_media [L,QSA]',
];
foreach ($requiredHtaccessRules as $requiredRule) {
    if (!str_contains($htaccess, $requiredRule)) {
        bm_smoke_fail($failures, '.htaccess is missing Remote API clean URL routing or Authorization passthrough: ' . $requiredRule);
    }
}
if (!str_contains($indexPhp, "['api_status', 'api_stream_posts', 'api_media', 'api_media_import']") || !str_contains($indexPhp, "require_once __DIR__ . '/_bonumark_stream/app/api.php'")) {
    bm_smoke_fail($failures, 'index.php does not dispatch Remote API routes before installed-site/public routing.');
}
foreach (['api_status' => 'bms_api_handle_status_endpoint();', 'api_stream_posts' => 'bms_api_handle_stream_posts_endpoint();', 'api_media' => 'bms_api_handle_media_endpoint();', 'api_media_import' => 'bms_api_handle_media_import_endpoint();'] as $routeName => $handlerCall) {
    if (!str_contains($indexPhp, "\$route === '{$routeName}'") || !str_contains($indexPhp, $handlerCall)) {
        bm_smoke_fail($failures, 'index.php is missing Remote API route dispatch for: ' . $routeName);
    }
}
foreach (['status:read', 'stream:draft', 'stream:publish', 'media:upload'] as $requiredScope) {
    if (!str_contains($apiApp, "'" . $requiredScope . "' =>")) {
        bm_smoke_fail($failures, 'Remote API scope definition is missing: ' . $requiredScope);
    }
    if (!str_contains($apiDocs, $requiredScope) || !str_contains($remotePostingDocs, $requiredScope)) {
        bm_smoke_fail($failures, 'Remote API documentation is missing required scope: ' . $requiredScope);
    }
}
foreach (['remote_posting_disabled', 'missing_bearer_token', 'invalid_bearer_token', 'missing_scope', 'publish_confirmation_required', 'idempotency_key_conflict'] as $requiredApiCode) {
    if (!str_contains($apiApp, $requiredApiCode)) {
        bm_smoke_fail($failures, 'Remote API code path is missing expected API error code: ' . $requiredApiCode);
    }
}
foreach (['Idempotency-Key', 'idempotency_key', 'client_request_id', 'idempotency_key_conflict'] as $requiredIdempotencyText) {
    if (!str_contains($apiDocs, $requiredIdempotencyText) || !str_contains($remotePostingDocs, $requiredIdempotencyText)) {
        bm_smoke_fail($failures, 'Remote API idempotency documentation is missing required text: ' . $requiredIdempotencyText);
    }
}
foreach (['POST /api/v1/media', 'POST /api/v1/media/import', 'media_uploads', 'media_imports', 'media_import_url', 'embedded_media', 'source_url'] as $requiredMediaText) {
    if (!str_contains($apiDocs, $requiredMediaText)) {
        bm_smoke_fail($failures, 'Remote API media upload/import documentation is missing required text: ' . $requiredMediaText);
    }
}
if (!str_contains($remotePostingDocs, 'POST /api/v1/media') || !str_contains($remotePostingDocs, 'POST /api/v1/media/import') || !str_contains($remotePostingDocs, 'media:upload')) {
    bm_smoke_fail($failures, 'Remote Posting documentation does not cover media upload/import behavior.');
}
if (!str_contains($chatGptActionsDocs, 'docs/REMOTE-POSTING-CLIENTS.md') || !str_contains($clientDocs, 'docs/API.md')) {
    bm_smoke_fail($failures, 'Remote Posting cross-document references are incomplete.');
}
if (!is_file($root . '/scripts/api-database-smoke-test.php')) {
    bm_smoke_fail($failures, 'Optional Remote API database smoke test script is missing.');
} else {
    foreach (['disabled_api', 'missing_token', 'invalid_token', 'draft_create', 'publish_scope', 'publish_confirmation', 'media_scope', 'idempotency_replay', 'idempotency_conflict'] as $requiredScenario) {
        if (!str_contains($apiDatabaseSmoke, "'" . $requiredScenario . "'")) {
            bm_smoke_fail($failures, 'Optional Remote API database smoke test is missing scenario: ' . $requiredScenario);
        }
    }
    if (!str_contains($apiDatabaseSmoke, 'BMS_DB_DANGER_RESET=1') || !str_contains($apiDatabaseSmoke, 'bms_api_ci_')) {
        bm_smoke_fail($failures, 'Optional Remote API database smoke test must require explicit DB reset permission and use a temporary API table prefix.');
    }
}

$previewApp = @file_get_contents($root . '/_bonumark_stream/app/preview.php') ?: '';
$functionsApp = @file_get_contents($root . '/_bonumark_stream/app/functions.php') ?: '';
$rendererApp = @file_get_contents($root . '/_bonumark_stream/app/renderer.php') ?: '';
$appearanceApp = @file_get_contents($root . '/_bonumark_stream/app/appearance.php') ?: '';
$commentsApp = @file_get_contents($root . '/_bonumark_stream/app/comments.php') ?: '';
$cardTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/card.php') ?: '';
$headerTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/header.php') ?: '';
$footerTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/footer.php') ?: '';
$streamJs = @file_get_contents($root . '/assets/stream.js') ?: '';
$adminCss = @file_get_contents($root . '/assets/admin.css') ?: '';
$contentAdmin = @file_get_contents($root . '/admin/content.php') ?: '';
$pagesAdmin = @file_get_contents($root . '/admin/pages.php') ?: '';
if (!str_contains($adminCss, 'Admin Row Action Hover Stability Hotfix') || !str_contains($adminCss, 'body.bonumark-admin .row-actions .link-button:hover') || !str_contains($adminCss, 'body.bonumark-admin .table-actions .link-button:hover')) {
    bm_smoke_fail($failures, 'Admin CSS is missing row-action hover stability selectors.');
}
if (!str_contains($adminCss, 'font: inherit') || !str_contains($adminCss, 'font-weight: 500') || !str_contains($adminCss, 'padding: 0') || !str_contains($adminCss, 'border: 0')) {
    bm_smoke_fail($failures, 'Admin row-action CSS must keep hover layout metrics stable.');
}
if (!str_contains($adminCss, '.row-actions .state-link') || !str_contains($adminCss, '.row-actions .danger-link') || !str_contains($adminCss, '.table-actions .danger-link')) {
    bm_smoke_fail($failures, 'Admin row-action CSS must preserve state and destructive action styling.');
}
if (!str_contains($contentAdmin, 'class="link-button state-link">Publish</button>') || !str_contains($contentAdmin, 'class="link-button state-link">Move to Drafts</button>') || !str_contains($contentAdmin, 'class="link-button danger-link">Trash</button>')) {
    bm_smoke_fail($failures, 'Stream post row actions must use stable state/danger classes.');
}
if (!str_contains($pagesAdmin, 'class="table-actions"') || !str_contains($pagesAdmin, 'class="link-button state-link">Restore</button>') || !str_contains($pagesAdmin, 'class="link-button danger-link">Delete Permanently</button>')) {
    bm_smoke_fail($failures, 'Page table actions must share stable row-action styling.');
}

$commentsAdmin = @file_get_contents($root . '/admin/comments.php') ?: '';
if (!str_contains($adminCss, 'Admin Content List Width Utilization Pass') || !str_contains($adminCss, 'body.bonumark-admin .admin-table,') || !str_contains($adminCss, 'width: 100%') || !str_contains($adminCss, 'body.bonumark-admin .stream-posts-table')) {
    bm_smoke_fail($failures, 'Admin CSS is missing full-width admin list table coverage.');
}
if (!str_contains($adminCss, 'table-layout: fixed') || !str_contains($adminCss, '.stream-posts-table .check-column') || !str_contains($adminCss, '.stream-posts-table td.title-column')) {
    bm_smoke_fail($failures, 'Stream post table CSS must keep metadata columns stable while the Post column expands.');
}
if (!str_contains($adminCss, 'body.bonumark-admin .admin-content:has(.content-list-panel)') || !str_contains($adminCss, 'max-width: none')) {
    bm_smoke_fail($failures, 'Admin list screens must be allowed to use the full workspace width.');
}
if (!str_contains($contentAdmin, 'class="admin-table content-table stream-posts-table"')) {
    bm_smoke_fail($failures, 'Stream posts table must use the full-width stream-posts-table class.');
}
if (!str_contains($pagesAdmin, 'class="admin-table content-table pages-table"')) {
    bm_smoke_fail($failures, 'Pages table must use the shared admin/content table classes.');
}
if (!str_contains($commentsAdmin, 'comments-list-panel') || !str_contains($commentsAdmin, 'comments-table')) {
    bm_smoke_fail($failures, 'Comments list must expose stable list-panel and comments-table classes.');
}
if (!str_contains($functionsApp, 'function bms_public_preview_mode') || !str_contains($functionsApp, 'function bms_with_public_preview_mode')) {
    bm_smoke_fail($failures, 'Preview-mode core helpers are missing.');
}
if (!str_contains($previewApp, 'bms_with_public_preview_mode')) {
    bm_smoke_fail($failures, 'Admin preview rendering must enable public preview mode.');
}
if (!str_contains($rendererApp, "'preview_mode' =>") || !str_contains($rendererApp, "'enabled' => !\$previewMode")) {
    bm_smoke_fail($failures, 'Renderer does not pass preview mode or disable preview interactions.');
}
if (!str_contains($commentsApp, 'bms_render_comments_preview_panel')) {
    bm_smoke_fail($failures, 'Preview comments panel helper is missing.');
}
if (!str_contains($cardTemplate, "!empty(\$like['enabled'])") || !str_contains($cardTemplate, '$backLabel')) {
    bm_smoke_fail($failures, 'Card template does not honor preview-safe action state.');
}
if (!str_contains($headerTemplate, "'Preview'")) {
    bm_smoke_fail($failures, 'Header template does not expose preview state.');
}
if (!str_contains($appearanceApp, "'show_count_chip' => !\$previewMode") || !str_contains($appearanceApp, "'preview_header_state' => \$previewMode") || !str_contains($appearanceApp, "'show_public_menu' => !\$previewMode") || !str_contains($appearanceApp, "'navigation_html' => \$previewMode ? '' : \$navHtml")) {
    bm_smoke_fail($failures, 'Appearance renderer does not pass preview-safe header state or suppress public navigation in preview.');
}
if (!str_contains($appearanceApp, 'function bms_public_footer_items') || !str_contains($appearanceApp, "'footer_items' => \$footerItems") || !str_contains($appearanceApp, "'footer_separator' => ''")) {
    bm_smoke_fail($failures, 'Appearance renderer must pass normalized footer item data without a default slash separator.');
}
if (!str_contains($footerTemplate, '$footerItems') || !str_contains($footerTemplate, 'foreach ($footerItems as $index => $item)') || !str_contains($footerTemplate, '$index > 0 && $separator !==') || !str_contains($footerTemplate, 'footer-separator')) {
    bm_smoke_fail($failures, 'Footer template must render separators only when an explicit non-empty separator is supplied.');
}
if (str_contains($footerTemplate, '$separator = trim((string)($data[\'footer_separator\'] ?? \'/\'))') || str_contains($appearanceApp, "'footer_separator' => '/'")) {
    bm_smoke_fail($failures, 'Public footer must not default to a slash separator.');
}
if (!str_contains($headerTemplate, '$showPostCount = !$previewMode && $showCountChip') || !str_contains($headerTemplate, '!$previewMode && $showPublicMenu && $navigationHtml !==') || str_contains($headerTemplate, 'data-preview-menu')) {
    bm_smoke_fail($failures, 'Header template does not hide preview count/menu controls safely.');
}
if (!str_contains($streamJs, 'function isPreviewMode') || !str_contains($streamJs, 'if (!isPreviewMode())')) {
    bm_smoke_fail($failures, 'Public JavaScript does not skip live interactions in preview mode.');
}
$htaccess = @file_get_contents($root . '/.htaccess') ?: '';
$frontController = @file_get_contents($root . '/index.php') ?: '';
if (!str_contains($htaccess, 'api/v1/stream/posts')) {
    bm_smoke_fail($failures, '.htaccess does not route the remote stream posts endpoint.');
}
if (!str_contains($htaccess, 'api/v1/media')) {
    bm_smoke_fail($failures, '.htaccess does not route the remote media endpoint.');
}
if (!str_contains($htaccess, 'api/v1/media/import')) {
    bm_smoke_fail($failures, '.htaccess does not route the remote media import endpoint.');
}
if (!str_contains($htaccess, '__bonumark_route=api_status') || !str_contains($htaccess, '__bonumark_route=api_stream_posts') || !str_contains($htaccess, '__bonumark_route=api_media') || !str_contains($htaccess, '__bonumark_route=api_media_import')) {
    bm_smoke_fail($failures, '.htaccess must route API clean URLs through index.php for upgrade compatibility.');
}
if (!str_contains($frontController, 'bms_api_handle_status_endpoint') || !str_contains($frontController, 'bms_api_handle_stream_posts_endpoint') || !str_contains($frontController, 'bms_api_handle_media_endpoint') || !str_contains($frontController, 'bms_api_handle_media_import_endpoint')) {
    bm_smoke_fail($failures, 'index.php must dispatch API clean URL routes.');
}
$apiRuntime = @file_get_contents($root . '/_bonumark_stream/app/api.php') ?: '';
$createPostPosition = strpos($apiRuntime, 'function bms_api_create_remote_stream_post');
$embedCallPosition = strpos($apiRuntime, '$embeddedMedia = bms_api_embedded_media($payload, $token)');
$bodyPersistPosition = strpos($apiRuntime, 'bms_api_body_with_embedded_media($body');
$buildPosition = strpos($apiRuntime, 'bms_build_markdown_document($fields, $body)');
if ($createPostPosition === false || $embedCallPosition === false || $bodyPersistPosition === false || $buildPosition === false || $embedCallPosition < $createPostPosition || $bodyPersistPosition < $embedCallPosition || $buildPosition < $bodyPersistPosition) {
    bm_smoke_fail($failures, 'Remote stream post creation must embed media into the post body before persistence.');
}
if (!str_contains($apiRuntime, 'Content or embedded media is required.')) {
    bm_smoke_fail($failures, 'Remote media-only posts must be allowed when embedded media is supplied.');
}

$migrationDir = $root . '/_bonumark_stream/migrations';
$migrationFiles = glob($migrationDir . '/*.php') ?: [];
sort($migrationFiles);
$lastNumber = 0;
foreach ($migrationFiles as $file) {
    $base = basename($file);
    if (!preg_match('/^(\d{4})_[a-z0-9_]+\.php$/', $base, $match)) {
        bm_smoke_fail($failures, 'Migration filename is invalid: ' . $base);
        continue;
    }
    $number = (int)$match[1];
    if ($lastNumber > 0 && $number !== $lastNumber + 1) {
        bm_smoke_fail($failures, 'Migration sequence gap before: ' . $base);
    }
    $lastNumber = $number;

    $migration = require $file;
    if (!is_array($migration) || array_values($migration) !== $migration) {
        bm_smoke_fail($failures, 'Migration must return a numeric list: ' . $base);
        continue;
    }
    foreach ($migration as $statement) {
        if (!is_string($statement)) {
            bm_smoke_fail($failures, 'Migration statement is not a string: ' . $base);
        }
    }
}



// PWA and mobile share-target checks.
$pwaRequiredFiles = [
    'manifest.php',
    'pwa-icon.php',
    'sw.js',
    'assets/pwa.js',
    'assets/icons/bonumark-icon-192.png',
    'assets/icons/bonumark-icon-512.png',
    '_bonumark_stream/app/pwa.php',
    'admin/share-target.php',
];
foreach ($pwaRequiredFiles as $relative) {
    if (!is_file($root . '/' . $relative)) {
        bm_smoke_fail($failures, 'PWA/share-target file is missing: ' . $relative);
    }
}

$manifestOutput = trim((string)shell_exec('cd ' . escapeshellarg($root) . ' && php manifest.php 2>/dev/null'));
$manifestData = $manifestOutput !== '' ? json_decode($manifestOutput, true) : null;
if (!is_array($manifestData)) {
    bm_smoke_fail($failures, 'PWA manifest did not produce valid JSON.');
} else {
    foreach (['name', 'short_name', 'description', 'start_url', 'display', 'theme_color', 'background_color', 'scope', 'icons'] as $field) {
        if (!array_key_exists($field, $manifestData)) {
            bm_smoke_fail($failures, 'PWA manifest missing required field: ' . $field);
        }
    }
    if (($manifestData['display'] ?? '') !== 'standalone') {
        bm_smoke_fail($failures, 'PWA manifest display mode must be standalone.');
    }
    if (empty($manifestData['icons']) || !is_array($manifestData['icons'])) {
        bm_smoke_fail($failures, 'PWA manifest icons must be present.');
    } else {
        $iconSources = array_column($manifestData['icons'], 'src');
        if (!in_array('/assets/icons/bonumark-icon-192.png', $iconSources, true) || !in_array('/assets/icons/bonumark-icon-512.png', $iconSources, true)) {
            bm_smoke_fail($failures, 'PWA manifest must use bundled fallback icons when no Site Identity favicon is configured.');
        }
    }
    $shareTarget = $manifestData['share_target'] ?? null;
    if (!is_array($shareTarget) || ($shareTarget['action'] ?? '') === '' || ($shareTarget['method'] ?? '') !== 'POST' || ($shareTarget['params']['text'] ?? '') !== 'text' || ($shareTarget['params']['url'] ?? '') !== 'url') {
        bm_smoke_fail($failures, 'PWA manifest share_target must use POST and support shared text and URLs.');
    }
}

$serviceWorker = @file_get_contents($root . '/sw.js') ?: '';
if (!str_contains($serviceWorker, 'bonumark-stream-static-v' . $rootVersion)) {
    bm_smoke_fail($failures, 'Service worker cache name is not tied to the current version.');
}
if (!str_contains($serviceWorker, 'bmsBlockedPrivatePath') || !str_contains($serviceWorker, "relative.indexOf('admin/')") || !str_contains($serviceWorker, "relative.indexOf('api/')")) {
    bm_smoke_fail($failures, 'Service worker must explicitly avoid admin and API responses.');
}
if (!str_contains($serviceWorker, 'bmsSafeStaticAsset') || !str_contains($serviceWorker, "relative.indexOf('assets/')")) {
    bm_smoke_fail($failures, 'Service worker must cache only safe static assets.');
}
if (str_contains($serviceWorker, 'bonumark-icon-192.png') || str_contains($serviceWorker, 'bonumark-icon-512.png')) {
    bm_smoke_fail($failures, 'Service worker must not pre-cache PWA icons because Site Identity icons use versioned dynamic URLs.');
}

$pwaIconOutput = (string)shell_exec('cd ' . escapeshellarg($root) . ' && php pwa-icon.php 2>/dev/null');
if (!str_starts_with($pwaIconOutput, "\x89PNG\r\n\x1a\n")) {
    bm_smoke_fail($failures, 'PWA icon endpoint must return a PNG fallback when no Site Identity favicon is configured.');
}
$pwaRuntime = @file_get_contents($root . '/_bonumark_stream/app/pwa.php') ?: '';
if (!str_contains($pwaRuntime, 'function bms_pwa_site_icon_direct_url')
    || !str_contains($pwaRuntime, 'function bms_pwa_site_icon_native_size')
    || !str_contains($pwaRuntime, 'bms_pwa_site_icon_direct_url($source)')) {
    bm_smoke_fail($failures, 'Selected Site Identity favicons must remain PWA icon sources when GD and Imagick are unavailable.');
}
if (!str_contains($pwaRuntime, "'type' => (string)(\$source['mime'] ?? 'image/png')")
    || !str_contains($pwaRuntime, "'sizes' => bms_pwa_site_icon_native_size(\$source)")) {
    bm_smoke_fail($failures, 'Direct Site Identity PWA manifest icons must use the source image MIME type and native dimensions.');
}

$authRuntime = @file_get_contents($root . '/_bonumark_stream/app/auth.php') ?: '';
$shareRoute = @file_get_contents($root . '/admin/share-target.php') ?: '';
if (!str_contains($authRuntime, "'share-target.php' => 'publish_content'")) {
    bm_smoke_fail($failures, 'Share-target admin route must be mapped to publish_content.');
}
if (!str_contains($shareRoute, 'bms_require_login()') || !str_contains($shareRoute, "bms_require_capability('publish_content')")) {
    bm_smoke_fail($failures, 'Share-target route must require authentication and stream-publish capability.');
}
if (!str_contains($shareRoute, 'bms_share_target_store_pending($incoming)') || !str_contains($shareRoute, 'bms_share_target_front_composer_url()')) {
    bm_smoke_fail($failures, 'Share-target route must hand shared content to the front-end composer.');
}
if (str_contains($shareRoute, 'bms_sync_stream_metadata') || str_contains($shareRoute, "'status' => 'draft'")) {
    bm_smoke_fail($failures, 'Share-target route must not create backend drafts directly.');
}
if (preg_match('/share[-_]target.*published|published.*share[-_]target/i', $shareRoute) === 1) {
    bm_smoke_fail($failures, 'Share-target route must not publish shared content directly.');
}
if (!str_contains($shareRoute, 'bms_share_target_payload_from_array($_POST)') || str_contains($shareRoute, 'bms_share_target_payload_from_array($_GET)')) {
    bm_smoke_fail($failures, 'Share-target intake must read shared payloads from POST only.');
}
if (!str_contains($shareRoute, "share-target.php?pending=1") || !str_contains($shareRoute, 'bms_request_origin_is_same_site_or_absent')) {
    bm_smoke_fail($failures, 'Share-target must preserve only a pending-session continuation and validate browser origin metadata.');
}
$composerTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/composer.php') ?: '';
$rendererRuntime = @file_get_contents($root . '/_bonumark_stream/app/renderer.php') ?: '';
if (!str_contains($composerTemplate, "data['body_value']") || !str_contains($rendererRuntime, 'bms_share_target_take_pending_payload')) {
    bm_smoke_fail($failures, 'Front-end composer must support prefilled share-target content.');
}

// Remember-me app login checks.
$rememberMigration = @file_get_contents($root . '/_bonumark_stream/migrations/0006_remember_me_sessions.php') ?: '';
$adminLogin = @file_get_contents($root . '/admin/login.php') ?: '';
$accountTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/account.php') ?: '';
$accountRoute = @file_get_contents($root . '/_bonumark_stream/app/routes.php') ?: '';
$appearanceRuntime = @file_get_contents($root . '/_bonumark_stream/app/appearance.php') ?: '';
$readingSettings = @file_get_contents($root . '/admin/settings-reading.php') ?: '';
if (!str_contains($rememberMigration, 'remember_tokens') || !str_contains($rememberMigration, 'remember_login_enabled') || !str_contains($rememberMigration, 'remember_login_days')) {
    bm_smoke_fail($failures, 'Remember-me migration must create tokens table and default settings.');
}
foreach (['bms_create_remember_token', 'bms_restore_remembered_login', 'bms_revoke_current_remember_token', 'bms_revoke_user_remember_tokens'] as $requiredRememberFunction) {
    if (!str_contains($authRuntime, 'function ' . $requiredRememberFunction . '(')) {
        bm_smoke_fail($failures, 'Remember-me auth helper is missing: ' . $requiredRememberFunction);
    }
}
if (!str_contains($authRuntime, 'function bms_attempt_login(string $username, string $password, bool $remember = false)')) {
    bm_smoke_fail($failures, 'Login handler must accept the remember-me flag.');
}
if (!str_contains($authRuntime, 'hash(\'sha256\', $validator)') || !str_contains($authRuntime, 'last_used_at = NOW()')) {
    bm_smoke_fail($failures, 'Remember-me tokens must store hashed validators and rotate on use.');
}
if (!str_contains($authRuntime, "'httponly' => true") || !str_contains($authRuntime, "'samesite' => 'Lax'")) {
    bm_smoke_fail($failures, 'Remember-me cookie must be HttpOnly and SameSite=Lax.');
}
if (!str_contains($adminLogin, 'name="remember_me"') || !str_contains($accountTemplate, 'name="remember_me"')) {
    bm_smoke_fail($failures, 'Login forms must include Remember this device when enabled.');
}
if (!str_contains($accountTemplate, 'method="post"') || !str_contains($accountTemplate, 'name="action" value="logout"') || !str_contains($accountRoute, 'Sign out must be completed from the account page.') || preg_match('/GET[^\n]{0,400}bms_logout/', $accountRoute) === 1 || str_contains($appearanceRuntime, 'account.php?action=logout')) {
    bm_smoke_fail($failures, 'Logout must remain a CSRF-protected POST action without a legacy GET logout path or navigation link.');
}
if (!str_contains($readingSettings, 'remember_login_enabled') || !str_contains($readingSettings, 'remember_login_days')) {
    bm_smoke_fail($failures, 'Settings > Stream must expose remember-me login controls.');
}
if (!str_contains($authRuntime, 'bms_revoke_user_remember_tokens($currentId)') || !str_contains($authRuntime, 'bms_revoke_user_remember_tokens($id)')) {
    bm_smoke_fail($failures, 'Remembered devices must be revoked on password changes and admin password resets.');
}
if (!str_contains($functionsSource, 'function bms_session_cookie_name') || !str_contains($functionsSource, 'function bms_session_cookie_path') || !str_contains($functionsSource, 'session_name(bms_session_cookie_name())')) {
    bm_smoke_fail($failures, 'Sessions must use a Bonumark-specific cookie name and install-scoped cookie path.');
}
if (!str_contains($functionsSource, 'function bms_log_admin_exception') || !str_contains($functionsSource, 'function bms_request_origin_is_same_site_or_absent')) {
    bm_smoke_fail($failures, 'Release remediation security helpers are missing.');
}
$adminFiles = glob($root . '/admin/*.php') ?: [];
foreach ($adminFiles as $adminFile) {
    $adminSource = (string)file_get_contents($adminFile);
    if (preg_match('/bms_flash\([^\n;]*\$e->getMessage\(/', $adminSource) === 1) {
        bm_smoke_fail($failures, 'Admin UI must not flash raw exception details: ' . basename($adminFile));
    }
}

$migrationRepairPath = $root . '/_bonumark_stream/migrations/0011_published_timestamp_cutover_repair.php';
if (!is_file($migrationRepairPath) || !str_contains((string)file_get_contents($migrationRepairPath), 'stream_published_at_utc_cutover')) {
    bm_smoke_fail($failures, 'Published timestamp corrective migration is missing.');
}
if (!str_contains($databaseSource, 'function bms_resolve_stream_published_at_utc_cutover') || !str_contains($databaseSource, 'function bms_migration_contains_ddl') || !str_contains($databaseSource, 'function bms_database_table_exists')) {
    bm_smoke_fail($failures, 'Timestamp repair or resumable DDL migration support is missing.');
}
if (str_contains($databaseSource, 'SHOW TABLES LIKE :table_name') || !str_contains($databaseSource, "SHOW TABLES LIKE ' . \$pdo->quote(\$table)")) {
    bm_smoke_fail($failures, 'Database table-existence checks must use MariaDB-compatible quoted SHOW TABLES syntax.');
}
$databaseSmokeSource = @file_get_contents($root . '/scripts/database-smoke-test.php') ?: '';
foreach (['SHOW COLUMNS FROM `{$prefix}posts` LIKE :column_name', 'SHOW INDEX FROM `{$prefix}posts` WHERE Key_name = :index_name', 'SHOW TABLES LIKE :table_name'] as $unsupportedShowStatement) {
    if (str_contains($databaseSmokeSource, $unsupportedShowStatement)) {
        bm_smoke_fail($failures, 'Database smoke test contains a MariaDB-incompatible parameterized SHOW statement: ' . $unsupportedShowStatement);
    }
}
$upgradeSource = @file_get_contents($root . '/admin/upgrade.php') ?: '';
foreach ([
    "'manifest.php' => true",
    "'pwa-icon.php' => true",
    "'sw.js' => true",
    'bms_run_migrations($historyFromVersion)',
    'bms_write_upgrade_recovery_state',
    'bms_upgrade_record_recovery_required',
    'bms_upgrade_recovery_message',
] as $requiredUpgradeText) {
    if (!str_contains($upgradeSource, $requiredUpgradeText)) {
        bm_smoke_fail($failures, 'Upgrade remediation behavior is missing: ' . $requiredUpgradeText);
    }
}

if (!str_contains($databaseSource, 'function bms_upgrade_recovery_marker_path')
    || !str_contains($databaseSource, 'function bms_write_upgrade_recovery_state')
    || !str_contains($databaseSource, 'function bms_upgrade_recovery_matches_package')) {
    bm_smoke_fail($failures, 'Forward-only upgrade recovery state helpers are missing.');
}

$pwaSource = @file_get_contents($root . '/_bonumark_stream/app/pwa.php') ?: '';
if (!str_contains($pwaSource, 'function bms_share_target_client_hash')
    || !str_contains($pwaSource, 'function bms_share_target_rate_limit_path')
    || !str_contains($pwaSource, 'flock($handle, LOCK_EX)')
    || !str_contains($pwaSource, 'count($clean) > 1000')) {
    bm_smoke_fail($failures, 'Share target must use bounded server-side locked rate limit state keyed by a salted client hash.');
}
if (!str_contains($pwaSource, 'function bms_pwa_icon_source')
    || !str_contains($pwaSource, 'function bms_pwa_icon_url')
    || !str_contains($pwaSource, 'function bms_pwa_output_icon')
    || !str_contains($pwaSource, 'pwa-icon.php?size=')) {
    bm_smoke_fail($failures, 'PWA must derive installed app icon URLs from the Site Identity favicon when available.');
}

foreach (['scripts/smoke-test.php', 'scripts/migration-recovery-smoke-test.php'] as $script) {
    $scriptSource = @file_get_contents($root . '/' . $script) ?: '';
    if (!str_contains($scriptSource, "PHP_SAPI !== 'cli'") || !str_contains($scriptSource, "exit('CLI only.')")) {
        bm_smoke_fail($failures, 'Test scripts must refuse web execution: ' . $script);
    }
}

if (!str_contains($authRuntime, 'function bms_remember_expires_at_utc') || !str_contains($authRuntime, 'bms_remember_expires_at_utc($expires)')) {
    bm_smoke_fail($failures, 'Remember-me expiration must be stored in UTC.');
}

$registrationSource = @file_get_contents($root . '/_bonumark_stream/app/registration.php') ?: '';
if (!str_contains($registrationSource, 'bms_site_timezone()')
    || !str_contains($registrationSource, 'bms_utc_timezone()')
    || !str_contains($registrationSource, 'function bms_registration_invite_expiration_to_utc')
    || !str_contains($registrationSource, 'function bms_registration_format_invite_expiration')) {
    bm_smoke_fail($failures, 'Invite expirations must convert site-local input to UTC and render back in site time.');
}

$likeEndpoint = @file_get_contents($root . '/_bonumark_stream/app/stream-like-endpoint.php') ?: '';
if (!str_contains($likeEndpoint, 'bms_request_origin_is_same_site_or_absent')) {
    bm_smoke_fail($failures, 'Public like endpoint must reject cross-site browser submissions.');
}
$themeInstaller = @file_get_contents($root . '/_bonumark_stream/app/theme-installer.php') ?: '';
if (substr_count($themeInstaller, 'bms_read_theme_manifest_file((string)$candidate[\'manifest\'])') !== 1) {
    bm_smoke_fail($failures, 'Theme installer must read a candidate manifest once.');
}
$manifestPath = $root . '/_bonumark_stream/RELEASE-MANIFEST.json';
$manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
if (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files'])) {
    bm_smoke_fail($failures, 'Release manifest is missing or invalid.');
} else {
    $manifestFiles = [];
    foreach ($manifest['files'] as $entry) {
        $relative = str_replace('\\', '/', (string)($entry['path'] ?? ''));
        $hash = strtolower((string)($entry['sha256'] ?? ''));
        if ($relative === '' || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            bm_smoke_fail($failures, 'Release manifest contains an invalid entry.');
            continue;
        }
        $path = $root . '/' . $relative;
        if (!is_file($path)) {
            bm_smoke_fail($failures, 'Release manifest references a missing file: ' . $relative);
            continue;
        }
        if (!hash_equals($hash, hash_file('sha256', $path))) {
            bm_smoke_fail($failures, 'Release manifest hash mismatch: ' . $relative);
        }
        $manifestFiles[$relative] = true;
    }

    foreach (bm_smoke_files($root) as $path) {
        $relative = bm_smoke_relative($root, $path);
        if ($relative === '_bonumark_stream/RELEASE-MANIFEST.json') {
            continue;
        }
        if (!isset($manifestFiles[$relative])) {
            bm_smoke_fail($failures, 'Package file is not listed in release manifest: ' . $relative);
        }
    }
}

foreach (glob($root . '/_bonumark_stream/themes/*/theme.json') ?: [] as $themeManifest) {
    $theme = json_decode((string)file_get_contents($themeManifest), true);
    $themeName = basename(dirname($themeManifest));
    if (!is_array($theme)) {
        bm_smoke_fail($failures, 'Theme manifest is invalid: ' . $themeName);
        continue;
    }
    foreach (['name', 'version', 'assets'] as $required) {
        if (empty($theme[$required])) {
            bm_smoke_fail($failures, 'Theme manifest missing ' . $required . ': ' . $themeName);
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname($themeManifest), FilesystemIterator::SKIP_DOTS)) as $themeFile) {
        if ($themeFile instanceof SplFileInfo && strtolower($themeFile->getExtension()) === 'php') {
            bm_smoke_fail($failures, 'Theme package contains PHP code: ' . $themeName . '/' . $themeFile->getFilename());
        }
    }
    foreach (['templates', 'view_slots', 'required_templates'] as $legacyThemeKey) {
        if (array_key_exists($legacyThemeKey, $theme)) {
            bm_smoke_fail($failures, 'Theme manifest contains a legacy layout key: ' . $themeName);
        }
    }
}

foreach (bm_smoke_files($root) as $path) {
    $relative = bm_smoke_relative($root, $path);
    $contents = (string)file_get_contents($path);

    $conflictPattern = '/<' . '<<<<<<|=' . '======|>' . '>>>>>>/';
    if ($relative !== 'scripts/smoke-test.php' && preg_match($conflictPattern, $contents)) {
        bm_smoke_fail($failures, 'Merge conflict marker found: ' . $relative);
    }

    $markerPattern = '/\b(' . 'TODO' . '|' . 'FIXME' . ')\b/';
    if ($relative !== 'scripts/smoke-test.php' && preg_match($markerPattern, $contents)) {
        bm_smoke_fail($failures, 'Unresolved development marker found: ' . $relative);
    }

    if (preg_match('/\b(var_dump|print_r)\s*\(/', $contents)) {
        bm_smoke_fail($failures, 'Debug output call found: ' . $relative);
    }

    if (str_ends_with($relative, '.css')) {
        $open = substr_count($contents, '{');
        $close = substr_count($contents, '}');
        if ($open !== $close) {
            bm_smoke_fail($failures, 'CSS brace mismatch: ' . $relative);
        }
    }
}

$forbiddenPaths = [
    '_bonumark_stream/config.php',
    '_bonumark_stream/installed.lock',
    'index.html',
    'feed.xml',
];
foreach ($forbiddenPaths as $relative) {
    if (file_exists($root . '/' . $relative)) {
        bm_smoke_fail($failures, 'Runtime file should not be packaged: ' . $relative);
    }
}

// Pinned-post release checks. These protect the core-owned pin boundary
// without requiring a live database during package smoke testing.
$pinnedMigrationPath = $root . '/_bonumark_stream/migrations/0008_pinned_posts.php';
if (!is_file($pinnedMigrationPath)) {
    bm_smoke_fail($failures, 'Pinned-post migration is missing.');
} else {
    $pinnedMigration = require $pinnedMigrationPath;
    if (!is_array($pinnedMigration) || count($pinnedMigration) < 3) {
        bm_smoke_fail($failures, 'Pinned-post migration is invalid.');
    } else {
        $pinnedSql = implode("
", $pinnedMigration);
        foreach (['is_pinned', 'pinned_at', 'post_type_status_pinned_at'] as $requiredPinnedSql) {
            if (!str_contains($pinnedSql, $requiredPinnedSql)) {
                bm_smoke_fail($failures, 'Pinned-post migration is missing required SQL: ' . $requiredPinnedSql);
            }
        }
    }
}

$databaseSource = @file_get_contents($root . '/_bonumark_stream/app/database.php') ?: '';
$functionsSource = @file_get_contents($root . '/_bonumark_stream/app/functions.php') ?: '';
$rendererSource = @file_get_contents($root . '/_bonumark_stream/app/renderer.php') ?: '';
$migrationSource = @file_get_contents($root . '/_bonumark_stream/migrations/0010_published_timestamp_storage_cutover.php') ?: '';
$pinEndpoint = @file_get_contents($root . '/admin/pin.php') ?: '';
$contentList = @file_get_contents($root . '/admin/content.php') ?: '';
$cardTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/card.php') ?: '';
$themeCss = @file_get_contents($root . '/assets/themes/default/assets/css/theme.css') ?: '';
$coreCss = @file_get_contents($root . '/assets/style.css') ?: '';

foreach (['function bms_list_pinned_stream_posts', 'ORDER BY pinned_at DESC, id DESC', 'function bms_set_stream_post_pinned_state', 'is_pinned = CASE WHEN ? = 1 THEN is_pinned ELSE 0 END', 'pinned_at = CASE WHEN ? = 1 THEN pinned_at ELSE NULL END'] as $requiredPinnedDatabaseText) {
    if (!str_contains($databaseSource, $requiredPinnedDatabaseText)) {
        bm_smoke_fail($failures, 'Pinned-post database behavior is missing: ' . $requiredPinnedDatabaseText);
    }
}

foreach ([
    'function bms_apply_site_timezone',
    'function bms_stream_published_at_is_utc',
    'stream_published_at_utc_cutover',
    'date_default_timezone_set($timezone)',
    "SET time_zone = '+00:00'",
    '\'published_at\' => $record[\'status\'] === \'published\' ? gmdate(\'Y-m-d H:i:s\') : null',
    "->setTimezone(bms_site_timezone())->format('M j, Y g:i A')",
] as $requiredTimezoneText) {
    if (str_contains($requiredTimezoneText, 'to_version')) {
        $timezoneSource = $migrationSource;
    } elseif (str_contains($requiredTimezoneText, 'SET time_zone') || str_contains($requiredTimezoneText, "'published_at' => ")) {
        $timezoneSource = $databaseSource;
    } elseif (str_contains($requiredTimezoneText, 'setTimezone')) {
        $timezoneSource = $rendererSource;
    } else {
        $timezoneSource = $functionsSource;
    }
    if (!str_contains($timezoneSource, $requiredTimezoneText)) {
        bm_smoke_fail($failures, 'Timezone runtime behavior is missing: ' . $requiredTimezoneText);
    }
}
foreach (['bms_render_pinned_stream_posts($pinnedPosts)', 'bms_render_public_flash_notices()', 'stream-pinned-posts', 'bms_list_pinned_stream_posts()'] as $requiredPinnedRendererText) {
    if (!str_contains($rendererSource, $requiredPinnedRendererText)) {
        bm_smoke_fail($failures, 'Pinned-post renderer behavior is missing: ' . $requiredPinnedRendererText);
    }
}
foreach (['bms_require_login();', 'bms_verify_csrf();', "bms_require_content_file_access('published'", 'bms_set_stream_post_pinned_state'] as $requiredPinnedEndpointText) {
    if (!str_contains($pinEndpoint, $requiredPinnedEndpointText)) {
        bm_smoke_fail($failures, 'Pinned-post endpoint security or action is missing: ' . $requiredPinnedEndpointText);
    }
}
foreach (['status=pinned', 'Pin to Stream', 'Unpin from Stream', 'Pinned <span><?= $pinnedCount ?>'] as $requiredPinnedAdminText) {
    if (!str_contains($contentList, $requiredPinnedAdminText)) {
        bm_smoke_fail($failures, 'Pinned-post admin list behavior is missing: ' . $requiredPinnedAdminText);
    }
}
foreach (['pin_action', 'pin_csrf', 'stream-pin-form'] as $requiredPinnedCardText) {
    if (!str_contains($cardTemplate, $requiredPinnedCardText)) {
        bm_smoke_fail($failures, 'Pinned-post front-end controls are missing: ' . $requiredPinnedCardText);
    }
}
foreach (['stream-post-actions-menu', 'stream-post-actions-toggle', 'stream-post-actions-popover', 'stream-post-action-item', 'Post options'] as $requiredPostMenuText) {
    if (!str_contains($cardTemplate, $requiredPostMenuText)) {
        bm_smoke_fail($failures, 'Front-end post actions menu is missing: ' . $requiredPostMenuText);
    }
}
$streamJs = @file_get_contents($root . '/assets/stream.js') ?: '';
if (!str_contains($streamJs, 'summary, details, [data-stream-actions-menu]')) {
    bm_smoke_fail($failures, 'Card click handling must ignore the front-end post actions menu.');
}
foreach (['assets/style.css', '_bonumark_stream/themes/default/assets/css/theme.css', 'assets/themes/default/assets/css/theme.css'] as $menuCssPath) {
    $menuCss = (string)file_get_contents($root . '/' . $menuCssPath);
    if (!str_contains($menuCss, 'top: calc(100% + 0.45rem);') || !str_contains($menuCss, 'bottom: auto;')) {
        bm_smoke_fail($failures, 'Post options menu must open below its trigger in ' . $menuCssPath . '.');
    }
}
foreach (['_bonumark_stream/themes/default/assets/css/theme.css', 'assets/themes/default/assets/css/theme.css'] as $themeMenuCssPath) {
    $themeMenuCss = (string)file_get_contents($root . '/' . $themeMenuCssPath);
    if (!preg_match('/\.stream-card\s*\{[^}]*overflow:\s*visible;/s', $themeMenuCss) || !str_contains($themeMenuCss, '.stream-card:has(.stream-post-actions-menu[open])')) {
        bm_smoke_fail($failures, 'Bundled theme must keep the below-trigger post options menu visible above the stream in ' . $themeMenuCssPath . '.');
    }
}
if (!str_contains($coreCss, '.stream-post-actions-menu[open]') || !str_contains($coreCss, 'z-index: 31;')) {
    bm_smoke_fail($failures, 'Core fallback must keep an open post options menu above nearby stream content.');
}

foreach (['.stream-pinned-posts', '.stream-card-pinned', '.stream-post-actions-menu', '.stream-post-actions-popover'] as $requiredPinnedStyle) {
    if (!str_contains($coreCss, $requiredPinnedStyle) || !str_contains($themeCss, $requiredPinnedStyle)) {
        bm_smoke_fail($failures, 'Pinned-post fallback or reference-theme styling is missing: ' . $requiredPinnedStyle);
    }
}
foreach ([$coreCss, $themeCss] as $menuCss) {
    if (!preg_match('/\.stream-post-action-item\s*\{[^}]*justify-content:\s*flex-start;/s', $menuCss)) {
        bm_smoke_fail($failures, 'Post options menu actions must explicitly left-align button and link items.');
    }
}
if (!str_contains($readme, '## Pinned posts') || !str_contains($readme, 'RSS/feed order') || !str_contains($readme, 'static export output')) {
    bm_smoke_fail($failures, 'README pinned-post documentation is missing required behavior details.');
}

// Markdown image-rendering checks load markdown.php by itself, so these
// smoke-test fallbacks are intentionally wrapped. If the real media helpers are
// loaded first, the test must not redeclare app functions.
if (!function_exists('bms_media_resolve_existing_public_relative_from_url')) {
    function bms_media_resolve_existing_public_relative_from_url(string $url): string
    {
        return '';
    }
}
if (!function_exists('bms_media_public_relative_from_url')) {
    function bms_media_public_relative_from_url(string $url): string
    {
        return '';
    }
}
if (!function_exists('bms_media_image_attributes')) {
    function bms_media_image_attributes(string $url, string $alt = '', array $options = []): string
    {
        return 'src="/media/2026/06/example.jpg" alt="Example" loading="lazy" decoding="async" width="2400" height="1800" srcset="/media/_generated/2026/06/example-480w.jpg 480w, /media/2026/06/example.jpg 960w, /media/_generated/2026/06/example-1200w.jpg 1200w, /media/2026/06/example.jpg 2400w" sizes="(max-width: 720px) calc(100vw - 2rem), min(100vw - 4rem, 900px)"';
    }
}
require_once $root . '/_bonumark_stream/app/markdown.php';
$renderedMarkdownImage = bms_markdown_to_html("Testing image render.

![Example](https://example.com/media/2026/06/example.jpg)

Caption stays visible.");
if (!str_contains($renderedMarkdownImage, '<img ')) {
    bm_smoke_fail($failures, 'Markdown image rendering smoke test did not produce an image tag.');
}
if (!str_contains($renderedMarkdownImage, 'srcset="/media/_generated/2026/06/example-480w.jpg 480w')) {
    bm_smoke_fail($failures, 'Markdown image rendering smoke test did not preserve generated responsive variants.');
}
if (str_contains($renderedMarkdownImage, '<em>generated') || str_contains($renderedMarkdownImage, '</em>generated') || str_contains($renderedMarkdownImage, 'srcset="/media/<')) {
    bm_smoke_fail($failures, 'Markdown image rendering leaked generated responsive srcset text into visible content.');
}
if (!str_contains($renderedMarkdownImage, '<p>Caption stays visible.</p>')) {
    bm_smoke_fail($failures, 'Markdown image rendering smoke test did not keep the caption visible.');
}


if ($failures !== []) {
    fwrite(STDERR, "Bonumark smoke test failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '- ' . $failure . "\n");
    }
    exit(1);
}

echo 'Bonumark smoke test passed for version ' . $rootVersion . PHP_EOL;
