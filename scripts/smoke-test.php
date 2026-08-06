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

$installerSource = @file_get_contents($root . '/install.php') ?: '';
if (!str_contains($installerSource, "\$pdo->exec(\"SET time_zone = '+00:00'\");")) {
    bm_smoke_fail($failures, 'Fresh installer database connection must force a UTC session before schema and seed writes.');
}
if (!str_contains($installerSource, "'email_verified_at' => gmdate('Y-m-d H:i:s')")) {
    bm_smoke_fail($failures, 'Fresh installer Admin verification timestamp must be explicit UTC.');
}

$quickEditEndpoint = @file_get_contents($root . '/admin/stream-quick-edit.php') ?: '';
$cardTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/card.php') ?: '';
$streamScript = @file_get_contents($root . '/assets/stream.js') ?: '';
$streamTrashEndpoint = @file_get_contents($root . '/admin/stream-trash.php') ?: '';
if (!str_contains($quickEditEndpoint, "bms_record_revision_from_page(\$page, 'published'") || !str_contains($quickEditEndpoint, 'bms_update_stream_post_body($page, $body)')) {
    bm_smoke_fail($failures, 'Front-end Quick edit endpoint must archive the published revision and use the body-only database updater.');
}
if (!str_contains($cardTemplate, 'data-stream-quick-edit-open') || !str_contains($cardTemplate, 'Open full editor')) {
    bm_smoke_fail($failures, 'Public card template is missing Quick edit or full-editor actions.');
}
if (!str_contains($streamScript, 'function setupQuickEdits(root)') || !str_contains($streamScript, 'setupQuickEdits(feed)')) {
    bm_smoke_fail($failures, 'Stream JavaScript is missing front-end Quick edit or Load More initialization.');
}
if (!str_contains($streamTrashEndpoint, 'bms_delete_content_file(\'published\', $file)')
    || !str_contains($streamTrashEndpoint, 'bms_current_user_can(\'edit_content\', $subject)')
    || !str_contains($streamTrashEndpoint, 'hash_equals((string)($_SESSION[\'csrf_token\'] ?? \'\'), $token)')) {
    bm_smoke_fail($failures, 'Front-end Move to trash endpoint is missing recoverable deletion, post-specific permission, or CSRF enforcement.');
}
if (!str_contains($cardTemplate, 'data-stream-trash-form') || !str_contains($cardTemplate, 'Move to trash')) {
    bm_smoke_fail($failures, 'Public card template is missing the front-end Move to trash action.');
}
if (!str_contains($streamScript, 'function setupStreamTrash(root)') || !str_contains($streamScript, 'setupStreamTrash(feed)')) {
    bm_smoke_fail($failures, 'Stream JavaScript is missing front-end Trash confirmation or Load More initialization.');
}
$readme = @file_get_contents($root . '/README.md') ?: '';
$upgradeDocs = @file_get_contents($root . '/docs/UPGRADING.md') ?: '';
foreach (['## v0.5.35 - Root RSS Discovery Hotfix', '## v0.5.37 - Local Places Composer Simplification Pass', '## Legacy timestamp display compatibility'] as $requiredUpgradeText) {
    if (!str_contains($upgradeDocs, $requiredUpgradeText)) {
        bm_smoke_fail($failures, 'Upgrade guide is missing required release-hardening text: ' . $requiredUpgradeText);
    }
}
if (str_contains($upgradeDocs, '## Scheduled Tasks run-history alignment')) {
    bm_smoke_fail($failures, 'Upgrade guide still contains the mislabeled v0.5.24 timestamp section.');
}

if ($rootVersion !== '' && !str_contains($readme, 'Current version: **' . $rootVersion . '**')) {
    bm_smoke_fail($failures, 'README.md current version is stale.');
}
if (preg_match('/^## v0\./m', $readme)) {
    bm_smoke_fail($failures, 'README.md contains release-history sections that belong in CHANGELOG.md.');
}
if (!str_contains($readme, '[CHANGELOG.md](CHANGELOG.md)')) {
    bm_smoke_fail($failures, 'README.md does not link to the complete release history.');
}

$installDocs = @file_get_contents($root . '/docs/INSTALL.md') ?: '';
$remotePostingDocs = @file_get_contents($root . '/docs/REMOTE-POSTING.md') ?: '';
if ($rootVersion !== '' && !str_contains($installDocs, 'Bonumark Stream v' . $rootVersion)) {
    bm_smoke_fail($failures, 'Install guide current version is stale.');
}
if ($rootVersion !== '' && !str_contains($upgradeDocs, 'Bonumark Stream v' . $rootVersion)) {
    bm_smoke_fail($failures, 'Upgrade guide current version is stale.');
}
if ($rootVersion !== '' && !str_contains($remotePostingDocs, 'Current status in v' . $rootVersion)) {
    bm_smoke_fail($failures, 'Remote Posting guide current version is stale.');
}

$composerTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/composer.php') ?: '';
$quickPostSource = @file_get_contents($root . '/admin/quick-post.php') ?: '';
$newPostRoute = @file_get_contents($root . '/admin/new.php') ?: '';
$adminLayoutSource = @file_get_contents($root . '/admin/_layout.php') ?: '';
foreach (['data-stream-action="draft"', 'data-stream-action="continue"', 'data-stream-more-menu', 'data-stream-advanced-panel', 'name="stream_slug"', 'name="stream_robots"'] as $requiredComposerText) {
    if (!str_contains($composerTemplate, $requiredComposerText)) {
        bm_smoke_fail($failures, 'Unified Stream composer is missing required control: ' . $requiredComposerText);
    }
}
foreach (["['publish', 'schedule', 'draft', 'continue']", 'bms_sync_stream_metadata($page, $targetSection', 'edit.php?type=draft&file='] as $requiredQuickPostText) {
    if (!str_contains($quickPostSource, $requiredQuickPostText)) {
        bm_smoke_fail($failures, 'Front composer save route is missing unified workflow behavior: ' . $requiredQuickPostText);
    }
}
if (str_contains($composerTemplate, 'stream-compose-secondary-actions') || str_contains($composerTemplate, '<summary>Advanced</summary>')) {
    bm_smoke_fail($failures, 'Front composer still exposes the cluttered v0.5.46 secondary action row or always-visible Advanced control.');
}
if (!str_contains($composerTemplate, 'data-stream-advanced-panel hidden') || !str_contains($composerTemplate, 'data-stream-advanced-toggle')) {
    bm_smoke_fail($failures, 'Advanced composer metadata is not hidden behind the compact More options workflow.');
}
if (!str_contains($newPostRoute, 'bms_stream_composer_url()') || str_contains($newPostRoute, 'bms_new_content_form')) {
    bm_smoke_fail($failures, 'admin/new.php is not a clean compatibility redirect to the stream composer.');
}
if (str_contains($adminLayoutSource, '>New Stream Post<') || !str_contains($adminLayoutSource, "'label' => 'Stream Composer'")) {
    bm_smoke_fail($failures, 'Admin navigation does not identify the stream composer as the creation surface.');
}

$streamSettings = @file_get_contents($root . '/admin/settings-reading.php') ?: '';
if (!str_contains($streamSettings, "bms_admin_header('Reading Settings'")
    || !str_contains($streamSettings, 'Control how the stream is read and discovered.')
    || !str_contains($streamSettings, 'Save Reading Settings')) {
    bm_smoke_fail($failures, 'Reading settings screen is missing the current workflow title or save action.');
}
if (str_contains($streamSettings, "bms_admin_header('Stream Settings'") || str_contains($streamSettings, 'Save Stream Settings')) {
    bm_smoke_fail($failures, 'Reading settings screen still contains the retired Stream Settings title.');
}

if (str_contains($streamSettings, 'name="stream_composer_enabled"')) {
    bm_smoke_fail($failures, 'Stream settings still expose an off switch for the canonical composer.');
}
$writingSettings = @file_get_contents($root . '/admin/settings-writing.php') ?: '';
if (str_contains($writingSettings, 'name="default_content_status"')) {
    bm_smoke_fail($failures, 'Writing settings still expose the retired backend-new-post default status.');
}
foreach (['admin/_layout.php', 'admin/welcome.php', 'admin/content.php', 'admin/index.php'] as $composerLinkFile) {
    $composerLinkSource = @file_get_contents($root . '/' . $composerLinkFile) ?: '';
    if (str_contains($composerLinkSource, "bms_admin_url('new.php')")) {
        bm_smoke_fail($failures, 'Admin UI still links to the retired new-post route: ' . $composerLinkFile);
    }
}

$apiDocs = @file_get_contents($root . '/docs/API.md') ?: '';
if ($rootVersion !== '' && !str_contains($apiDocs, '"version": "' . $rootVersion . '"')) {
    bm_smoke_fail($failures, 'API documentation response examples do not use the current version.');
}
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
if (!str_contains($apiDocs, 'GET /api/v1/stream/posts')) {
    bm_smoke_fail($failures, 'docs/API.md does not document the read-only stream posts endpoint.');
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
        if ($rootVersion !== '' && (string)($openApi['info']['version'] ?? '') !== $rootVersion) {
            bm_smoke_fail($failures, 'OpenAPI info.version does not match VERSION.');
        }
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
    foreach (['GET /api/v1/stream/posts', 'POST /api/v1/stream/posts', 'POST /api/v1/media', 'media_import_url', 'Idempotency-Key', 'Authorization: Bearer YOUR_API_TOKEN_HERE'] as $requiredClientText) {
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
foreach (['status:read', 'stream:read', 'stream:draft', 'stream:publish', 'media:upload'] as $requiredScope) {
    if (!str_contains($apiApp, "'" . $requiredScope . "' =>")) {
        bm_smoke_fail($failures, 'Remote API scope definition is missing: ' . $requiredScope);
    }
    if (!str_contains($apiDocs, $requiredScope) || !str_contains($remotePostingDocs, $requiredScope)) {
        bm_smoke_fail($failures, 'Remote API documentation is missing required scope: ' . $requiredScope);
    }
}
if (!str_contains($apiApp, "bms_api_query_choice('status', ['published'], 'published')")
    || !str_contains($apiApp, "AND status = :status LIMIT 1")
    || !str_contains($apiDocs, '`status`: `published` only')) {
    bm_smoke_fail($failures, 'The stream:read scope is not restricted to published Stream posts.');
}
$streamHandlerStart = strpos($apiApp, 'function bms_api_handle_stream_posts_endpoint(): never');
$mediaHandlerStart = strpos($apiApp, 'function bms_api_handle_media_endpoint(): never');
$mediaHandlerEnd = strpos($apiApp, 'function bms_api_handle_media_import_endpoint(): never');
if ($streamHandlerStart === false || $mediaHandlerStart === false || $mediaHandlerEnd === false || $mediaHandlerEnd <= $mediaHandlerStart) {
    bm_smoke_fail($failures, 'Remote API route handlers could not be inspected.');
} else {
    $streamHandler = substr($apiApp, $streamHandlerStart, $mediaHandlerStart - $streamHandlerStart);
    if (!str_contains($streamHandler, 'bms_api_handle_stream_posts_read_endpoint();')) {
        bm_smoke_fail($failures, 'Stream posts endpoint does not dispatch GET and HEAD requests to the read handler.');
    }
    $mediaHandler = substr($apiApp, $mediaHandlerStart, $mediaHandlerEnd - $mediaHandlerStart);
    if (str_contains($mediaHandler, 'bms_api_handle_stream_posts_read_endpoint')) {
        bm_smoke_fail($failures, 'Remote media endpoint incorrectly dispatches to the Stream read endpoint.');
    }
    if (!str_contains($mediaHandler, "header('Allow: POST')")) {
        bm_smoke_fail($failures, 'Remote media endpoint no longer advertises POST-only behavior.');
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
    foreach (['disabled_api', 'missing_token', 'invalid_token', 'stream_read', 'draft_create', 'publish_scope', 'publish_confirmation', 'media_scope', 'idempotency_replay', 'idempotency_conflict'] as $requiredScenario) {
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
$adminContentListCss = @file_get_contents($root . '/assets/admin-content-list.css') ?: '';
$adminEditorWorkflowCss = @file_get_contents($root . '/assets/admin-editor-workflow.css') ?: '';
$adminMediaLibraryCss = @file_get_contents($root . '/assets/admin-media-library.css') ?: '';
$adminCommentsCss = @file_get_contents($root . '/assets/admin-comments.css') ?: '';
$adminAccountsCss = @file_get_contents($root . '/assets/admin-accounts.css') ?: '';
$adminRegistrationCss = @file_get_contents($root . '/assets/admin-registration.css') ?: '';
$adminAppearanceCss = @file_get_contents($root . '/assets/admin-appearance.css') ?: '';
$adminSettingsCss = @file_get_contents($root . '/assets/admin-settings.css') ?: '';
$adminPlacesCss = @file_get_contents($root . '/assets/admin-places.css') ?: '';
$adminOperationsCss = @file_get_contents($root . '/assets/admin-operations.css') ?: '';
$adminPlacesJs = @file_get_contents($root . '/assets/admin-places.js') ?: '';
$placesAdmin = @file_get_contents($root . '/admin/places.php') ?: '';
$placeEditAdmin = @file_get_contents($root . '/admin/place-edit.php') ?: '';
$placeDeleteAdmin = @file_get_contents($root . '/admin/place-delete.php') ?: '';
$placesApp = @file_get_contents($root . '/_bonumark_stream/app/places.php') ?: '';
$generalSettingsAdmin = @file_get_contents($root . '/admin/settings.php') ?: '';
$writingSettingsAdmin = @file_get_contents($root . '/admin/settings-writing.php') ?: '';
$readingSettingsAdmin = @file_get_contents($root . '/admin/settings-reading.php') ?: '';
$securitySettingsAdmin = @file_get_contents($root . '/admin/security.php') ?: '';
$mailSettingsAdmin = @file_get_contents($root . '/admin/mail.php') ?: '';
$remotePostingAdmin = @file_get_contents($root . '/admin/remote-posting.php') ?: '';
$scheduledTasksAdmin = @file_get_contents($root . '/admin/scheduled-tasks.php') ?: '';
$toolsAdmin = @file_get_contents($root . '/admin/tools.php') ?: '';
$importAdmin = @file_get_contents($root . '/admin/import.php') ?: '';
$importMarkdownAdmin = @file_get_contents($root . '/admin/import-markdown.php') ?: '';
$exportAdmin = @file_get_contents($root . '/admin/export.php') ?: '';
$upgradeAdmin = @file_get_contents($root . '/admin/upgrade.php') ?: '';
$systemCheckAdmin = @file_get_contents($root . '/admin/system-check.php') ?: '';
$analyticsAdmin = @file_get_contents($root . '/admin/analytics.php') ?: '';
$helpAdmin = @file_get_contents($root . '/admin/help.php') ?: '';
$themesAdmin = @file_get_contents($root . '/admin/theme.php') ?: '';
$themeSettingsAdmin = @file_get_contents($root . '/admin/theme-settings.php') ?: '';
$themeDetailsAdmin = @file_get_contents($root . '/admin/theme-details.php') ?: '';
$themeInstallAdmin = @file_get_contents($root . '/admin/theme-install.php') ?: '';
$themeDeleteAdmin = @file_get_contents($root . '/admin/theme-delete.php') ?: '';
$siteIdentityAdmin = @file_get_contents($root . '/admin/site-identity.php') ?: '';
$navigationAdmin = @file_get_contents($root . '/admin/navigation.php') ?: '';
$registrationAdmin = @file_get_contents($root . '/admin/registration.php') ?: '';
$commentsUi = @file_get_contents($root . '/admin/_comments-ui.php') ?: '';
$usersAdmin = @file_get_contents($root . '/admin/users.php') ?: '';
$userNewAdmin = @file_get_contents($root . '/admin/user-new.php') ?: '';
$authApp = @file_get_contents($root . '/_bonumark_stream/app/auth.php') ?: '';
$adminShellCss = @file_get_contents($root . '/assets/admin-shell.css') ?: '';
$adminJs = @file_get_contents($root . '/assets/admin.js') ?: '';
$adminLayout = @file_get_contents($root . '/admin/_layout.php') ?: '';
$contentAdmin = @file_get_contents($root . '/admin/content.php') ?: '';
$pagesAdmin = @file_get_contents($root . '/admin/pages.php') ?: '';
$pageEditAdmin = @file_get_contents($root . '/admin/page-edit.php') ?: '';
$pageNewAdmin = @file_get_contents($root . '/admin/page-new.php') ?: '';
$editorApp = @file_get_contents($root . '/_bonumark_stream/app/editor.php') ?: '';
$editorJs = @file_get_contents($root . '/assets/editor.js') ?: '';
$mediaAdmin = @file_get_contents($root . '/admin/media.php') ?: '';
if ($adminShellCss === '' || !str_contains($adminShellCss, 'Admin Shell Foundation Pass')) {
    bm_smoke_fail($failures, 'Admin shell foundation stylesheet is missing.');
}
if (!str_contains($adminLayout, "bms_asset_url('assets/admin-shell.css')") || !str_contains($adminLayout, 'data-admin-nav-open') || !str_contains($adminLayout, 'admin-sidebar-backdrop')) {
    bm_smoke_fail($failures, 'Shared Admin layout does not load or expose the responsive shell controls.');
}
if (!str_contains($adminLayout, "'label' => 'Publish'") || !str_contains($adminLayout, "'label' => 'Manage'") || !str_contains($adminLayout, "'label' => 'Design'") || !str_contains($adminLayout, "'label' => 'System'")) {
    bm_smoke_fail($failures, 'Shared Admin navigation is missing task-oriented sections.');
}
if (!str_contains($adminJs, "classList.add('admin-js-ready')") || !str_contains($adminJs, 'function attachAdminNavigation()') || !str_contains($adminJs, "event.key === 'Escape'") || !str_contains($adminJs, "body.classList.toggle('admin-nav-open'")) {
    bm_smoke_fail($failures, 'Admin navigation script is missing drawer, Escape, or state handling.');
}
if (!str_contains($adminShellCss, '@media (max-width: 900px)') || !str_contains($adminShellCss, 'html.admin-js-ready body.bonumark-admin .admin-sidebar') || !str_contains($adminShellCss, 'transform: translateX(-105%)') || !str_contains($adminShellCss, 'min-height: 44px')) {
    bm_smoke_fail($failures, 'Admin shell stylesheet is missing mobile drawer or touch-target rules.');
}

$adminCssLineCount = substr_count($adminCss, "\n");
if ($adminCssLineCount >= 7258) {
    bm_smoke_fail($failures, 'Legacy Admin CSS consolidation pass 3 did not reduce assets/admin.css below its 7,258-line starting point.');
}
foreach ([
    '--admin-surface-3:#101318',
    '--admin-bg:#f0f0f1',
    '--admin-bg:#0b0d10',
    '--editor-composer-min-height:720px',
    '--editor-composer-max-height:6500px',
    '--editor-composer-max-height:5400px',
    'min-height:58vh',
    '.admin-shell{min-height:100vh',
    '.admin-topbar{min-height:48px',
    '.import-preview-heading{align-items:flex-start',
] as $supersededAdminCssFragment) {
    if (str_contains(str_replace(' ', '', $adminCss), $supersededAdminCssFragment)) {
        bm_smoke_fail($failures, 'Legacy Admin CSS still contains a superseded shell or editor declaration: ' . $supersededAdminCssFragment);
    }
}
if (!str_contains($adminShellCss, '--admin-bg: #0a0d12')
    || !str_contains($adminShellCss, '--admin-sidebar-width: 264px')
    || !str_contains($adminEditorWorkflowCss, '--editor-composer-min-height: 380px')
    || !str_contains($adminEditorWorkflowCss, '--editor-composer-max-height: 1400px')
    || !str_contains($adminOperationsCss, 'body.bonumark-admin .upgrade-confirm-actions')
    || !str_contains($adminShellCss, 'body.bonumark-admin .dashboard-metric-grid')
    || !str_contains($adminOperationsCss, 'body.bonumark-admin .import-preview-heading')
    || !str_contains($adminPlacesCss, 'body.bonumark-admin .editor-location-card .local-places-selected')) {
    bm_smoke_fail($failures, 'Dedicated Admin shell, editor, or operations component stylesheet is missing a definition that replaced removed legacy CSS.');
}

if (!str_contains($adminCss, 'Admin Row Action Hover Stability Hotfix') || !str_contains($adminCss, 'body.bonumark-admin .row-actions .link-button:hover') || !str_contains($adminCss, 'body.bonumark-admin .table-actions .link-button:hover')) {
    bm_smoke_fail($failures, 'Admin CSS is missing row-action hover stability selectors.');
}
if (!str_contains($adminCss, 'font: inherit') || !str_contains($adminCss, 'font-weight: 500') || !str_contains($adminCss, 'padding: 0') || !str_contains($adminCss, 'border: 0')) {
    bm_smoke_fail($failures, 'Admin row-action CSS must keep hover layout metrics stable.');
}
if (!str_contains($adminCss, '.row-actions .state-link') || !str_contains($adminCss, '.row-actions .danger-link') || !str_contains($adminCss, '.table-actions .danger-link')) {
    bm_smoke_fail($failures, 'Admin row-action CSS must preserve state and destructive action styling.');
}
if (!str_contains($contentAdmin, 'class="content-action-button state-link">Publish</button>') || !str_contains($contentAdmin, 'class="content-action-button state-link">Move to drafts</button>') || !str_contains($contentAdmin, 'class="content-action-button danger-link">Move to Trash</button>')) {
    bm_smoke_fail($failures, 'Stream post action menus must preserve state and destructive action classes.');
}
if (!str_contains($pagesAdmin, 'class="content-action-button state-link">Restore</button>')
    || !str_contains($pagesAdmin, 'class="content-action-button state-link">Publish page</button>')
    || !str_contains($pagesAdmin, 'class="content-action-button danger-link">Move to Trash</button>')
    || !str_contains($pagesAdmin, 'class="content-action-button danger-link">Delete permanently</button>')) {
    bm_smoke_fail($failures, 'Page action menus must preserve state and destructive action classes.');
}

$commentsAdmin = @file_get_contents($root . '/admin/comments.php') ?: '';
if (!str_contains($adminContentListCss, 'Bonumark Stream Admin Content List') || !str_contains($adminContentListCss, '.content-record-header') || !str_contains($adminContentListCss, '.content-record-actions') || !str_contains($adminContentListCss, '@media (max-width: 760px)')) {
    bm_smoke_fail($failures, 'Responsive Stream Posts record-list CSS is missing required desktop or mobile component coverage.');
}
if (!str_contains($adminLayout, "bms_asset_url('assets/admin-media-library.css')")) {
    bm_smoke_fail($failures, 'Shared Admin layout does not load the Media Library component stylesheet.');
}
if (!str_contains($adminMediaLibraryCss, 'Bonumark Stream Admin Media Library')
    || !str_contains($adminMediaLibraryCss, '.media-library-grid')
    || !str_contains($adminMediaLibraryCss, 'grid-template-columns: repeat(2, minmax(0, 1fr));')
    || !str_contains($adminMediaLibraryCss, '.media-details-dialog')
    || !str_contains($adminMediaLibraryCss, 'inset: auto 0 0;')) {
    bm_smoke_fail($failures, 'Media Library CSS is missing browsing-grid, phone-grid, dialog, or bottom-sheet coverage.');
}
if (!str_contains($mediaAdmin, 'class="media-library-grid"')
    || !str_contains($mediaAdmin, 'data-media-details-open')
    || !str_contains($mediaAdmin, 'data-media-details-dialog')
    || !str_contains($mediaAdmin, 'data-media-detail-markdown')
    || substr_count($mediaAdmin, 'class="copy-field"') !== 1) {
    bm_smoke_fail($failures, 'Media Library must use compact cards and one shared on-demand details surface instead of repeated Markdown fields.');
}
if (!str_contains($adminJs, 'function attachMediaDetailsDialog()')
    || !str_contains($adminJs, "dialog.showModal()")
    || !str_contains($adminJs, "card.classList.toggle('is-selected', box.checked)")) {
    bm_smoke_fail($failures, 'Media Library dialog or selection-state behavior is missing from Admin JavaScript.');
}
if (!str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-media-library.css'")) {
    bm_smoke_fail($failures, 'Service worker asset list does not include the Media Library component stylesheet.');
}

if (!str_contains($contentAdmin, 'class="content-record-header"') || !str_contains($contentAdmin, 'id="stream-content-list"') || !str_contains($contentAdmin, 'class="content-actions-menu"') || !str_contains($contentAdmin, 'data-select-scope="#stream-content-list"')) {
    bm_smoke_fail($failures, 'Stream Posts must use the responsive record-list structure, scoped selection, and compact action menus.');
}
if (!str_contains($adminContentListCss, 'body.bonumark-admin.admin-screen-content .admin-content') || !str_contains($adminContentListCss, 'max-width: 1500px')) {
    bm_smoke_fail($failures, 'The Stream Posts record list must use an intentional desktop workspace width.');
}
if (!str_contains($pagesAdmin, 'class="content-record-header page-content-record-header"')
    || !str_contains($pagesAdmin, 'class="content-record page-content-record')
    || !str_contains($pagesAdmin, 'class="content-actions-menu"')
    || !str_contains($adminContentListCss, '.page-content-record')
    || !str_contains($adminContentListCss, 'admin-screen-pages')) {
    bm_smoke_fail($failures, 'Pages must use the shared responsive record-list structure and page-specific desktop/mobile layout.');
}
if (!str_contains($pagesAdmin, 'content-filter pages-content-filter')
    || !str_contains($adminContentListCss, '.pages-content-filter')
    || !str_contains($adminContentListCss, 'grid-template-columns: repeat(2, minmax(0, 1fr));')) {
    bm_smoke_fail($failures, 'Phone Page status filters must remain fully visible in a two-column grid.');
}
if (!str_contains($pageEditAdmin, "bms_editor_screen_controls_action('page')")
    || !str_contains($pageEditAdmin, "'content_type' => 'page'")
    || !str_contains($pageEditAdmin, 'bms_editor_mobile_action_bar')
    || !str_contains($pageNewAdmin, 'bms_editor_mobile_action_bar')
    || !str_contains($editorApp, 'data-editor-card="page-url"')
    || !str_contains($editorApp, 'data-editor-card="page-settings"')
    || !str_contains($editorApp, "? bms_current_user_can(\$isPageContent ? 'manage_pages' : 'publish_content')")) {
    bm_smoke_fail($failures, 'Page editor workflow is missing page-aware screen controls, metadata cards, permissions, or mobile actions.');
}
if (!str_contains($pageEditAdmin, "\$submitAction === 'publish'")
    || !str_contains($pageEditAdmin, "\$targetSection = bms_page_status_section(\$targetStatus)")
    || !str_contains($editorJs, "'page-url': true")
    || !str_contains($editorJs, '[data-page-final-url-input]')) {
    bm_smoke_fail($failures, 'Page editor does not publish current form contents or keep page URL previews synchronized.');
}
if (!str_contains($editorApp, "\$isPageContent ? 'page' : 'post'")
    || str_contains($editorApp, 'Save changes first when the post has unsaved edits.</p>')) {
    bm_smoke_fail($failures, 'Publish preview guidance must use page-specific language in the Page editor.');
}
if (!str_contains($adminLayout, "bms_asset_url('assets/admin-comments.css')")
    || !str_contains($adminCommentsCss, 'Bonumark Stream Admin Comments')
    || !str_contains($adminCommentsCss, '.comment-record-header')
    || !str_contains($adminCommentsCss, '.comment-record-mobile-status')
    || !str_contains($adminCommentsCss, 'grid-template-columns: repeat(3, minmax(0, 1fr));')) {
    bm_smoke_fail($failures, 'Comments moderation stylesheet is missing desktop records, phone cards, or visible mobile status filters.');
}
if (!str_contains($commentsAdmin, 'class="comment-record-header"')
    || !str_contains($commentsAdmin, 'id="comment-record-list"')
    || !str_contains($commentsAdmin, 'data-select-scope="#comment-record-list"')
    || !str_contains($commentsAdmin, 'class="content-actions-menu"')
    || !str_contains($commentsAdmin, 'Search comments, authors, usernames, posts, or slugs')
    || !str_contains($commentsAdmin, 'Restore as approved')
    || !str_contains($commentsAdmin, 'Delete permanently')) {
    bm_smoke_fail($failures, 'Comments must use the responsive moderation records, scoped bulk controls, search, and complete state actions.');
}
if (!str_contains($commentsApp, 'function bms_admin_comment_status_counts')
    || !str_contains($commentsApp, 'function bms_update_comment_statuses')
    || !str_contains($commentsApp, 'function bms_delete_trashed_comments_permanently')
    || !str_contains($commentsApp, "WHERE id = :id AND status = :status")) {
    bm_smoke_fail($failures, 'Comment moderation helpers must support counts, bulk state changes, and Trash-only permanent deletion.');
}
if (!str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-comments.css'")) {
    bm_smoke_fail($failures, 'Service worker asset list does not include the Comments moderation stylesheet.');
}

if (!str_contains($commentsAdmin, "require_once __DIR__ . '/_comments-ui.php'")
    || !str_contains($commentsAdmin, 'str_starts_with($action, \'bulk_\') && !$selected')
    || str_contains($commentsUi, '$action')
    || !str_contains($commentsUi, 'function bms_comments_admin_date')) {
    bm_smoke_fail($failures, 'Comments warning correction or runtime-safe display helpers are missing.');
}
if (!is_file($root . '/scripts/admin-comments-runtime-test.php')) {
    bm_smoke_fail($failures, 'Comments runtime warning regression test is missing.');
}
if (!str_contains($adminCommentsCss, 'v0.5.60 desktop moderation control correction')
    || !str_contains($adminCommentsCss, 'flex: 0 0 220px;')
    || !str_contains($adminCommentsCss, 'white-space: nowrap;')) {
    bm_smoke_fail($failures, 'Comments desktop control bar correction is missing.');
}
if (!str_contains($adminLayout, "bms_asset_url('assets/admin-accounts.css')")
    || !str_contains($adminLayout, "'label' => 'Accounts'")
    || !str_contains($adminLayout, "'users.php', 'user-new.php', 'user-edit.php'")) {
    bm_smoke_fail($failures, 'Accounts navigation or component stylesheet integration is incomplete.');
}
if (!str_contains($usersAdmin, 'class="account-record-header"')
    || !str_contains($usersAdmin, 'class="account-record-list"')
    || !str_contains($usersAdmin, 'Manage Account')
    || !str_contains($usersAdmin, 'Approve Account')
    || str_contains($usersAdmin, 'class="admin-table')
    || str_contains($usersAdmin, '<h2>Add commenter</h2>')) {
    bm_smoke_fail($failures, 'Accounts must use responsive records, one Actions menu, and a separate creation screen.');
}
if (!str_contains($userNewAdmin, 'name="new_commenter_username"')
    || !str_contains($userNewAdmin, 'autocomplete="new-password"')
    || !str_contains($userNewAdmin, 'data-1p-ignore')
    || !str_contains($userNewAdmin, 'data-lpignore="true"')) {
    bm_smoke_fail($failures, 'Dedicated Add Commenter fields are missing autofill-resistant account controls.');
}
if (!str_contains($adminAccountsCss, 'Bonumark Stream Admin Accounts')
    || !str_contains($adminAccountsCss, '.account-record-header')
    || !str_contains($adminAccountsCss, '.account-record-mobile-label')) {
    bm_smoke_fail($failures, 'Accounts component stylesheet is missing desktop records or responsive account cards.');
}
if (!str_contains($authApp, "'users.php', 'user-new.php', 'user-edit.php' => 'manage_users'")
    || !str_contains($authApp, 'profile_visibility, avatar_path')) {
    bm_smoke_fail($failures, 'Account creation route protection or account-list profile data is incomplete.');
}
if (!str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-accounts.css'")) {
    bm_smoke_fail($failures, 'Service worker asset list does not include the Accounts stylesheet.');
}
if (!str_contains($commentsAdmin, 'class="content-filter-link')
    || !str_contains($commentsAdmin, 'class="content-filter-label"')
    || !str_contains($commentsAdmin, 'class="content-filter-count"')
    || !str_contains($usersAdmin, 'class="content-filter-link')
    || !str_contains($usersAdmin, 'class="content-filter-label"')
    || !str_contains($usersAdmin, 'class="content-filter-count"')) {
    bm_smoke_fail($failures, 'Comments and Accounts filters must keep labels and counts as separate styled elements.');
}
if (!str_contains($adminContentListCss, 'v0.5.61 shared filter label and count treatment')
    || !str_contains($adminContentListCss, '.content-filter-count')
    || !str_contains($adminContentListCss, 'font-variant-numeric: tabular-nums;')) {
    bm_smoke_fail($failures, 'Shared filter count badge styling is missing.');
}
if (!str_contains($usersAdmin, 'accounts-summary-total')
    || !str_contains($adminAccountsCss, '.accounts-summary-total')
    || !str_contains($adminAccountsCss, 'grid-column: 1 / -1;')) {
    bm_smoke_fail($failures, 'Total accounts must span the full second row in the phone summary grid.');
}

if (!str_contains($adminLayout, "bms_asset_url('assets/admin-registration.css')")
    || !str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-registration.css'")) {
    bm_smoke_fail($failures, 'Registration component stylesheet is not integrated into the Admin shell and service worker.');
}
if (!str_contains($registrationAdmin, "bms_admin_header('Registration'")
    || !str_contains($registrationAdmin, 'registration-summary-panel')
    || !str_contains($registrationAdmin, 'class="registration-option-card"')
    || !str_contains($registrationAdmin, 'class="registration-invite-record"')
    || str_contains($registrationAdmin, 'class="admin-table')) {
    bm_smoke_fail($failures, 'Registration must use the modern summary, option-card, and responsive invite-record workflow.');
}
if (!str_contains($adminRegistrationCss, 'Bonumark Stream Admin Registration')
    || !str_contains($adminRegistrationCss, '.registration-workflow-grid')
    || !str_contains($adminRegistrationCss, '.registration-invite-record')
    || !str_contains($adminRegistrationCss, '.registration-mobile-label')) {
    bm_smoke_fail($failures, 'Registration component stylesheet is missing desktop or phone workflow rules.');
}
if (!str_contains($registrationAdmin, '$registrationEnabled = $mode !== \'disabled\';')
    || !str_contains($registrationAdmin, 'if ($registrationEnabled && $verify && !$mailReady)')
    || !str_contains($registrationAdmin, "'Required when enabled'")
    || !str_contains($registrationAdmin, "'Automatic when enabled'")) {
    bm_smoke_fail($failures, 'Registration state guidance must stay quiet while registration is disabled and clarify deferred verification and approval rules.');
}

if (!str_contains($adminLayout, "bms_asset_url('assets/admin-appearance.css')")
    || !str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-appearance.css'")) {
    bm_smoke_fail($failures, 'Appearance component stylesheet is not integrated into the Admin shell and service worker.');
}
if (!str_contains($adminAppearanceCss, 'Bonumark Stream Admin Appearance')
    || !str_contains($adminAppearanceCss, '.appearance-theme-grid')
    || !str_contains($adminAppearanceCss, '.appearance-identity-layout')
    || !str_contains($adminAppearanceCss, '.appearance-navigation-item')
    || !str_contains($adminAppearanceCss, '@media (max-width: 640px)')) {
    bm_smoke_fail($failures, 'Appearance component stylesheet is missing theme, identity, navigation, or phone workflow rules.');
}
if (!str_contains($adminAppearanceCss, '@media (min-width: 641px)')
    || !str_contains($adminAppearanceCss, '.appearance-active-theme-facts > div:first-child')
    || !str_contains($adminAppearanceCss, 'grid-column: 1 / -1;')) {
    bm_smoke_fail($failures, 'Active-theme metadata must reserve a full desktop row for the slug without changing the accepted phone layout.');
}

if (!str_contains($adminLayout, "bms_asset_url('assets/admin-settings.css')")
    || !str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-settings.css'")) {
    bm_smoke_fail($failures, 'Settings component stylesheet is not integrated into the Admin shell and service worker.');
}
if (!str_contains($adminLayout, "'label' => 'Reading'")
    || !str_contains($adminLayout, "'label' => 'Security'")
    || !str_contains($authApp, "'settings-reading.php', 'security.php', 'registration.php'")) {
    bm_smoke_fail($failures, 'Settings navigation or Security route capability is incomplete.');
}
if (!str_contains($adminSettingsCss, 'Bonumark Stream Admin Settings')
    || !str_contains($adminSettingsCss, '.settings-workflow-grid')
    || !str_contains($adminSettingsCss, '.settings-option-card')
    || !str_contains($adminSettingsCss, '.settings-token-record')
    || !str_contains($adminSettingsCss, '@media (max-width: 720px)')) {
    bm_smoke_fail($failures, 'Settings component stylesheet is missing workflow, option, record, or phone rules.');
}
if (!str_contains($adminSettingsCss, 'Settings URL Overflow Hotfix')
    || !str_contains($adminSettingsCss, '.settings-technical-value')
    || !str_contains($adminSettingsCss, 'overflow-wrap: anywhere;')
    || !str_contains($adminSettingsCss, 'word-break: break-word;')
    || !str_contains($adminSettingsCss, '.settings-workflow-grid > *')) {
    bm_smoke_fail($failures, 'Settings technical URLs must shrink and wrap inside Reading, Remote Posting, Scheduled Tasks, and related panels.');
}
if (!str_contains($readingSettingsAdmin, 'class="settings-technical-value"')
    || !str_contains($remotePostingAdmin, 'class="settings-technical-value"')
    || !str_contains($scheduledTasksAdmin, 'class="settings-technical-value"')) {
    bm_smoke_fail($failures, 'Settings URL containers are missing the shared technical-value overflow treatment.');
}
if (!str_contains($generalSettingsAdmin, 'settings-summary-grid')
    || !str_contains($writingSettingsAdmin, 'settings-option-card')
    || !str_contains($readingSettingsAdmin, "bms_admin_header('Reading Settings'")
    || !str_contains($readingSettingsAdmin, 'settings-section-list')) {
    bm_smoke_fail($failures, 'General, Writing, or Reading settings are missing the modern workflow structure.');
}
if (!str_contains($securitySettingsAdmin, 'settings-security-grid')
    || str_contains($securitySettingsAdmin, "bms_redirect(bms_admin_url('system-check.php'))")
    || !str_contains($securitySettingsAdmin, 'API tokens')) {
    bm_smoke_fail($failures, 'Security must be a real settings hub instead of redirecting to System Check.');
}
if (!str_contains($mailSettingsAdmin, 'settings-mail-record')
    || str_contains($mailSettingsAdmin, 'class="admin-table')
    || !str_contains($remotePostingAdmin, 'settings-token-record')
    || !str_contains($remotePostingAdmin, 'settings-audit-record')
    || str_contains($remotePostingAdmin, 'class="admin-table')) {
    bm_smoke_fail($failures, 'Mail and Remote Posting must use responsive settings records instead of legacy tables.');
}
if (!str_contains($scheduledTasksAdmin, 'settings-history-record')
    || str_contains($scheduledTasksAdmin, 'class="admin-table')
    || !str_contains($scheduledTasksAdmin, 'Protected web cron')) {
    bm_smoke_fail($failures, 'Scheduled Tasks must use the modern runner workflow and responsive history records.');
}


if (!str_contains($adminLayout, "bms_asset_url('assets/admin-operations.css')")
    || !str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-operations.css'")) {
    bm_smoke_fail($failures, 'Operations component stylesheet is not integrated into the Admin shell and service worker.');
}
if (!str_contains($adminOperationsCss, 'Bonumark Stream Admin Operations')
    || !str_contains($adminOperationsCss, '.operations-danger-zone')
    || !str_contains($adminOperationsCss, '.operations-workflow-grid')
    || !str_contains($adminOperationsCss, '.operations-record')
    || !str_contains($adminOperationsCss, '@media (max-width: 720px)')) {
    bm_smoke_fail($failures, 'Operations stylesheet is missing risk, workflow, record, or phone rules.');
}
if (!str_contains($adminOperationsCss, '.operations-check-card > *')
    || !str_contains($adminOperationsCss, 'body.admin-screen-tools .operations-group-list')
    || !str_contains($adminOperationsCss, 'body.admin-screen-upgrade .operations-workflow-history')
    || !str_contains($adminOperationsCss, 'grid-template-areas:')
    || !str_contains($upgradeAdmin, 'operations-workflow-history')
    || !str_contains($upgradeAdmin, 'Version change')
    || !str_contains($upgradeAdmin, 'is-version-change')) {
    bm_smoke_fail($failures, 'Tools and System acceptance polish is missing diagnostic wrapping, Upgrade flow placement, or compact history records.');
}
if (!str_contains($toolsAdmin, 'Move and protect data')
    || !str_contains($toolsAdmin, 'Change system software')
    || !str_contains($toolsAdmin, 'Monitor and automate')
    || !str_contains($toolsAdmin, 'Access and support')) {
    bm_smoke_fail($failures, 'Tools must distinguish data movement, upgrades, diagnostics, and access workflows.');
}
if (!str_contains($importAdmin, 'Preview first')
    || !str_contains($importAdmin, 'Step 3')
    || !str_contains($importAdmin, 'Writes database')
    || !str_contains($importMarkdownAdmin, 'Advanced migration')) {
    bm_smoke_fail($failures, 'Import workflows must distinguish staging, confirmation, database writes, and private-folder migration.');
}
if (!str_contains($exportAdmin, 'Portable outputs')
    || !str_contains($exportAdmin, 'Private backups')
    || !str_contains($exportAdmin, 'Sensitive data')) {
    bm_smoke_fail($failures, 'Export must distinguish portable outputs from sensitive private backups.');
}
if (!str_contains($upgradeAdmin, 'High-risk operation')
    || !str_contains($upgradeAdmin, 'Step 1')
    || !str_contains($upgradeAdmin, 'Final confirmation')
    || str_contains($upgradeAdmin, 'class="admin-table')) {
    bm_smoke_fail($failures, 'Upgrade must use staged package checks, explicit final confirmation, and responsive history records.');
}
if (!str_contains($systemCheckAdmin, 'Read-only diagnostics')
    || !str_contains($systemCheckAdmin, 'operations-check-grid')
    || !str_contains($analyticsAdmin, 'operations-danger-zone')
    || !str_contains($helpAdmin, 'Operational help')) {
    bm_smoke_fail($failures, 'Diagnostics, analytics danger actions, or operational Help are incomplete.');
}
if (!str_contains($adminLayout, "['label' => 'Scheduled Tasks'")
    || !str_contains($adminLayout, "['label' => 'Remote Posting'")) {
    bm_smoke_fail($failures, 'Scheduled Tasks and Remote Posting must remain available in System navigation.');
}

if (!str_contains($adminLayout, "bms_asset_url('assets/admin-places.css')")
    || !str_contains($adminLayout, "bms_asset_url('assets/admin-places.js')")
    || !str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-places.css'")
    || !str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-places.js'")) {
    bm_smoke_fail($failures, 'Local Places component assets are not integrated into the Admin shell and service worker.');
}
if (!str_contains($adminPlacesCss, 'Bonumark Stream Admin Local Places')
    || !str_contains($adminPlacesCss, '.places-record')
    || !str_contains($adminPlacesCss, '.places-editor-layout')
    || !str_contains($adminPlacesCss, '.places-nearby-results')
    || !str_contains($adminPlacesCss, '@media (max-width: 640px)')) {
    bm_smoke_fail($failures, 'Local Places component stylesheet is missing directory, editor, nearby, or phone workflow rules.');
}
if (!str_contains($placesAdmin, 'data-places-directory-nearby')
    || !str_contains($placesAdmin, 'class="places-record-list"')
    || !str_contains($placesAdmin, 'class="places-search-form"')
    || str_contains($placesAdmin, 'local-places-directory-item')) {
    bm_smoke_fail($failures, 'Local Places directory must use search, nearby results, and the responsive record system.');
}
if (!str_contains($placeEditAdmin, 'data-place-editor')
    || !str_contains($placeEditAdmin, 'data-place-editor-nearby')
    || !str_contains($placeEditAdmin, 'data-place-preview-primary')
    || !str_contains($placeEditAdmin, 'data-place-preview-marker')
    || !str_contains($placeEditAdmin, 'Enter a place name to preview the public label')
    || !str_contains($placeEditAdmin, 'class="places-editor-layout"')) {
    bm_smoke_fail($failures, 'Local Places editor is missing the preview, empty-state marker treatment, nearby duplicate check, or modern editor layout.');
}
if (!str_contains($adminPlacesJs, "previewMarker.hidden = !hasPrimary")
    || !str_contains($adminPlacesJs, "previewPrimary.classList.toggle('is-placeholder', !hasPrimary)")
    || !str_contains($adminPlacesCss, '.places-public-preview.is-empty')
    || !str_contains($adminPlacesCss, '.places-preview-marker svg')) {
    bm_smoke_fail($failures, 'Local Places preview must hide the marker and show neutral guidance until a real public label exists.');
}
if (!str_contains($placeDeleteAdmin, 'name="confirm_name"')
    || !str_contains($placeDeleteAdmin, 'Delete Place Permanently')
    || !str_contains($placeDeleteAdmin, 'Existing Stream Posts keep the public location snapshot')) {
    bm_smoke_fail($failures, 'Local Places deletion must use a dedicated confirmation workflow and preserve existing post snapshots.');
}
if (!str_contains($placesApp, 'function bms_places_filter(')
    || !str_contains($adminPlacesJs, 'function attachDirectoryNearby()')
    || !str_contains($adminPlacesJs, 'function attachPlaceEditor()')
    || !str_contains($adminPlacesJs, 'Location permission was denied')) {
    bm_smoke_fail($failures, 'Local Places search, nearby loading/error handling, or editor interaction code is incomplete.');
}

if (!str_contains($themesAdmin, 'class="appearance-theme-grid"')
    || !str_contains($themesAdmin, 'class="appearance-summary-grid"')
    || !str_contains($themesAdmin, 'View Details')
    || !str_contains($themesAdmin, 'Active Theme Settings')) {
    bm_smoke_fail($failures, 'Themes must act as the Site Design hub with theme summaries, cards, and lifecycle actions.');
}
if (!str_contains($themeSettingsAdmin, 'type="hidden" name="active_public_theme"')
    || !str_contains($themeSettingsAdmin, 'class="appearance-setting-list"')
    || !str_contains($themeSettingsAdmin, 'Choose Another Theme')
    || str_contains($themeSettingsAdmin, '<select id="active_public_theme"')) {
    bm_smoke_fail($failures, 'Theme Settings must edit the active theme only and leave activation to the Themes workflow.');
}
if (!str_contains($themeDetailsAdmin, 'class="appearance-theme-detail-layout"')
    || !str_contains($themeDetailsAdmin, 'class="appearance-record-list"')
    || !str_contains($themeInstallAdmin, 'class="appearance-file-drop"')
    || !str_contains($themeInstallAdmin, 'Code-free only')
    || !str_contains($themeDeleteAdmin, 'class="appearance-delete-form"')) {
    bm_smoke_fail($failures, 'Theme details, installation, or deletion is missing the modern package lifecycle structure.');
}
if (!str_contains($siteIdentityAdmin, 'appearance-identity-layout')
    || !str_contains($siteIdentityAdmin, 'appearance-favicon-current')
    || !str_contains($siteIdentityAdmin, 'appearance-save-bar')) {
    bm_smoke_fail($failures, 'Site Identity must separate public framing, favicon management, and the save action.');
}
if (!str_contains($navigationAdmin, 'appearance-navigation-option-list')
    || !str_contains($navigationAdmin, 'appearance-navigation-item')
    || !str_contains($navigationAdmin, 'appearance-navigation-add-grid')
    || !str_contains($navigationAdmin, 'Show automatic account links')) {
    bm_smoke_fail($failures, 'Navigation must expose menu state, editable ordered records, and page/custom additions.');
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
if (!str_contains($upgradeSource, '$skipPublic = [\'media\' => true, \'uploads\' => true]')
    || !str_contains($upgradeSource, '!isset($skipPublic[$topLevel])')
    || !str_contains($upgradeSource, '$preservedRuntimeItems = [\'media\' => true, \'uploads\' => true]')
    || !str_contains($upgradeSource, 'isset($preservedRuntimeItems[$item])')) {
    bm_smoke_fail($failures, 'Upgrade software selection and backups must skip public media and upload runtime directories.');
}
if (!str_contains($upgradeSource, "'CHANGELOG.md' => true")
    || !str_contains($upgradeSource, "'analytics.php' => true")) {
    bm_smoke_fail($failures, 'Upgrade cleanup coverage is missing package-managed top-level files.');
}
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
$placesApp = @file_get_contents($root . '/_bonumark_stream/app/places.php') ?: '';
if (!str_contains($placesApp, 'function bms_places_nearby') || !str_contains($placesApp, 'function bms_place_request_fields')) {
    bm_smoke_fail($failures, 'Local Places core functions are missing.');
}
if (!str_contains($placesApp, 'data-place-create-modal')
    || !str_contains($placesApp, 'data-place-new-public-label')
    || str_contains($placesApp, 'data-place-new-category')
    || str_contains($placesApp, 'data-place-display-select')) {
    bm_smoke_fail($failures, 'Local Places composer controls are not using the simplified picker and add-place dialog.');
}
$placesMigration = @file_get_contents($root . '/_bonumark_stream/migrations/0014_local_places.php') ?: '';
if (!str_contains($placesMigration, '{{prefix}}places')) {
    bm_smoke_fail($failures, 'Local Places migration is missing or invalid.');
}
$composerTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/composer.php') ?: '';
if (!str_contains($composerTemplate, 'data-local-places-toggle')) {
    bm_smoke_fail($failures, 'Front-end composer is missing the Local Places control.');
}
$locationTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/location.php') ?: '';
if ($locationTemplate === '' || str_contains($locationTemplate, 'latitude') || str_contains($locationTemplate, 'longitude')) {
    bm_smoke_fail($failures, 'Public Local Places template is missing or exposes coordinates.');
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



// Four-photo gallery checks.
$quickPostSource = @file_get_contents($root . '/admin/quick-post.php') ?: '';
$editorSource = @file_get_contents($root . '/_bonumark_stream/app/editor.php') ?: '';
$editorJs = @file_get_contents($root . '/assets/editor.js') ?: '';
$mediaTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/media.php') ?: '';
$composerTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/composer.php') ?: '';
$themingDocs = @file_get_contents($root . '/docs/THEMING.md') ?: '';
$apiSource = @file_get_contents($root . '/_bonumark_stream/app/api.php') ?: '';
foreach (['function bms_normalize_media_gallery', "'media_gallery' =>", 'media_gallery:'] as $requiredGalleryFunctionText) {
    if (!str_contains($functionsSource, $requiredGalleryFunctionText)) {
        bm_smoke_fail($failures, 'Core gallery front-matter support is missing: ' . $requiredGalleryFunctionText);
    }
}
if (!str_contains($quickPostSource, 'count($files) > 4') || !str_contains($quickPostSource, "'media_gallery' => \$mediaGallery") || !str_contains($quickPostSource, 'bms_media_discard_new_upload')) {
    bm_smoke_fail($failures, 'Quick Post must enforce four files, save ordered gallery metadata, and roll back failed uploads.');
}
if (!str_contains($composerTemplate, 'name="stream_media[]"') || !str_contains($composerTemplate, 'multiple') || !str_contains($composerTemplate, 'data-stream-max-files')) {
    bm_smoke_fail($failures, 'Front-end composer must expose a multiple file input capped by the gallery controller.');
}
foreach (['setupEditorPhotoGallery', 'addMediaToPhotoGallery', "payload.append('image_only', '1')"] as $requiredEditorGalleryText) {
    if (!str_contains($editorJs, $requiredEditorGalleryText)) {
        bm_smoke_fail($failures, 'Admin editor gallery control is missing: ' . $requiredEditorGalleryText);
    }
}
if (!str_contains($editorSource, 'data-editor-photo-gallery') || !str_contains($editorSource, 'name="media_gallery[]"') || !str_contains($editorSource, 'data-media-picker-mode="gallery"')) {
    bm_smoke_fail($failures, 'Admin editor must render ordered gallery controls and hidden gallery fields.');
}
foreach (['stream-media-gallery-count-', 'stream-media-gallery-layout-', 'stream-media-gallery-item-'] as $requiredGalleryMarkup) {
    if (!str_contains($mediaTemplate, $requiredGalleryMarkup)) {
        bm_smoke_fail($failures, 'Core media template gallery contract is missing: ' . $requiredGalleryMarkup);
    }
}
foreach (['.stream-media-gallery', '--bms-media-gallery-gap', '--bms-media-gallery-object-fit'] as $requiredGalleryCss) {
    if (!str_contains($coreCss, $requiredGalleryCss) || !str_contains($themeCss, $requiredGalleryCss)) {
        bm_smoke_fail($failures, 'Core fallback or bundled theme gallery styling is missing: ' . $requiredGalleryCss);
    }
}
if (!str_contains($themingDocs, '## Photo gallery presentation') || !str_contains($themingDocs, 'Older themes remain compatible')) {
    bm_smoke_fail($failures, 'Theme documentation must define the gallery contract and older-theme fallback.');
}
if (!str_contains($functionsSource, "img-src 'self' data: blob: https: http:")) {
    bm_smoke_fail($failures, 'Composer photo previews require blob: in the image Content Security Policy.');
}
foreach (['previewObjectUrls', 'revokePreviewObjectUrls', 'window.URL.createObjectURL'] as $requiredPreviewText) {
    if (!str_contains($streamJs, $requiredPreviewText)) {
        bm_smoke_fail($failures, 'Front-end gallery preview URL lifecycle is missing: ' . $requiredPreviewText);
    }
}
foreach (['function setupMediaViewer', 'data-stream-media-viewer-modal', 'Close photo viewer', "event.key === 'Escape'", 'mediaViewerControllerInitialized'] as $requiredViewerText) {
    if (!str_contains($streamJs, $requiredViewerText)) {
        bm_smoke_fail($failures, 'Core photo viewer behavior is missing: ' . $requiredViewerText);
    }
}
if (!str_contains($mediaTemplate, 'data-stream-media-viewer')) {
    bm_smoke_fail($failures, 'Core image links must opt into the photo viewer contract.');
}
foreach (['.stream-media-viewer', '.stream-media-viewer-close', 'body.stream-media-viewer-open'] as $requiredViewerCss) {
    if (!str_contains($coreCss, $requiredViewerCss)) {
        bm_smoke_fail($failures, 'Core photo viewer styling is missing: ' . $requiredViewerCss);
    }
}
if (!str_contains($themingDocs, 'Core also owns the full-size photo viewer')) {
    bm_smoke_fail($failures, 'Theme documentation does not define the core photo viewer boundary.');
}
if (!str_contains($apiSource, 'function bms_api_media_display_mode') || !str_contains($apiSource, "'display' => 'gallery'") || !str_contains($apiDocs, 'media_display')) {
    bm_smoke_fail($failures, 'Remote API gallery mode or its documentation is missing.');
}

$galleryProbeCode = 'require ' . var_export($root . '/_bonumark_stream/app/functions.php', true) . '; '
    . '$raw=bms_build_markdown_document(["title"=>"Gallery","slug"=>"gallery","status"=>"draft","date"=>"2026-08-04","featured_media"=>"media/a.jpg","media_gallery"=>["media/a.jpg","media/b.jpg","media/c.jpg","media/d.jpg","media/e.jpg"]],""); '
    . '$page=bms_parse_markdown_string($raw); echo json_encode([$page["featured_media"],$page["media_gallery"]]);';
$galleryProbeCommand = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($galleryProbeCode) . ' 2>/dev/null';
$galleryProbe = json_decode(trim((string)shell_exec($galleryProbeCommand)), true);
if (!is_array($galleryProbe) || ($galleryProbe[0] ?? '') !== 'media/a.jpg' || count((array)($galleryProbe[1] ?? [])) !== 4 || (($galleryProbe[1][3] ?? '') !== 'media/d.jpg')) {
    bm_smoke_fail($failures, 'Gallery front matter must preserve order, keep the first image featured, and cap galleries at four.');
}

$editorWorkflowCss = @file_get_contents($root . '/assets/admin-editor-workflow.css') ?: '';
if (str_contains($editorWorkflowCss, 'max-height: calc(100vh - 104px)') || str_contains($editorWorkflowCss, 'scrollbar-gutter: stable')) {
    bm_smoke_fail($failures, 'Editor metadata rail must not retain the nested desktop scrollbar from v0.5.52.');
}
if (!str_contains($editorWorkflowCss, '.editor-layout-form.has-sticky-sidebar:not(.is-single-column-editor) .publish-card')
    || !str_contains($editorWorkflowCss, 'position: sticky;')) {
    bm_smoke_fail($failures, 'Editor correction must keep only the Publish card sticky on wide desktop layouts.');
}
if (!str_contains($editorJs, 'if (window.innerWidth <= 640) { return 260; }')
    || !str_contains($editorJs, 'if (window.innerWidth <= 900) { return 300; }')) {
    bm_smoke_fail($failures, 'Editor mobile writing surface baselines are not reduced to the v0.5.53 values.');
}
if (!str_contains($editorSource, '<section class="side-card editor-secondary-card editor-location-card"')
    || !str_contains($editorSource, '<h3>Location</h3>')
    || str_contains($editorSource, '<details class="side-card editor-secondary-card editor-native-disclosure editor-location-card"')
    || !str_contains($editorWorkflowCss, 'body.bonumark-admin .editor-location-card .editor-card-heading')) {
    bm_smoke_fail($failures, 'Location must use the shared collapsible side-card component without exposing its old duplicate heading.');
}
if (!str_contains($editorSource, 'data-editor-mobile-action-bar')
    || !str_contains($editorWorkflowCss, 'inset: auto 0 0 0 !important;')
    || !str_contains($editorWorkflowCss, '.editor-mobile-action-bar.is-context-hidden')
    || !str_contains($editorWorkflowCss, '--editor-mobile-action-reserve: calc(84px + env(safe-area-inset-bottom));')
    || !str_contains($editorJs, 'controlsEnterDockZone()')
    || !str_contains($editorJs, 'window.visualViewport')
    || !str_contains($editorJs, 'entry.intersectionRatio >= 0.24')) {
    bm_smoke_fail($failures, 'Mobile editor action dock hotfix is incomplete or missing its viewport-safety behavior.');
}

if ($failures !== []) {
    fwrite(STDERR, "Bonumark smoke test failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '- ' . $failure . "\n");
    }
    exit(1);
}

echo 'Bonumark smoke test passed for version ' . $rootVersion . PHP_EOL;
