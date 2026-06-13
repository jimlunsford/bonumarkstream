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

$root = dirname(__DIR__);
$failures = [];

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
