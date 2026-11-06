<?php
/**
 * Page lifecycle HTTP regression test against a disposable install.
 * Includes the Work 32 blocked-publication probe cases as failing assertions.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_page_ci_* tables are created and
 * removed. Application files, sessions and the loopback HTTP server are temporary.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if ((string)getenv('BMS_DB_DANGER_RESET') !== '1') {
    fwrite(STDERR, "Refusing to run without BMS_DB_DANGER_RESET=1.\n");
    exit(1);
}
foreach (['BMS_DB_HOST', 'BMS_DB_NAME', 'BMS_DB_USER'] as $required) {
    if ((string)getenv($required) === '') {
        throw new RuntimeException($required . ' is required.');
    }
}

$source = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/bms-page-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_page_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;

function bms_page_test_expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function bms_page_test_request(string $base, string $path, array &$cookies, ?array $form = null): array
{
    $headers = ['User-Agent: Bonumark Page Lifecycle Regression', 'Connection: close'];
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(static fn(string $key, string $value): string => $key . '=' . $value, array_keys($cookies), $cookies));
    }
    $options = ['method' => $form === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10];
    if ($form !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['content'] = http_build_query($form);
    }
    $options['header'] = implode("\r\n", $headers);
    $body = file_get_contents($base . $path, false, stream_context_create(['http' => $options]));
    if ($body === false || !isset($http_response_header[0])) {
        throw new RuntimeException('HTTP request failed: ' . $path);
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
    $location = '';
    foreach ($http_response_header as $header) {
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $match)) {
            $cookies[$match[1]] = $match[2];
        }
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
    }
    return ['status' => (int)($status[1] ?? 0), 'body' => $body, 'location' => $location];
}

function bms_page_test_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function bms_page_test_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

try {
    mkdir($temporary, 0700);
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), static function (SplFileInfo $file) use ($source): bool {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($source) + 1));
        // Never copy an installed checkout's credentials, backups, uploads or
        // generated exports into the disposable HTTP document root.
        return !$file->isLink()
            && !in_array($relative, [
                '.git', '_bonumark_stream/config.php', '_bonumark_stream/installed.lock',
                '_bonumark_stream/data', '_bonumark_stream/tmp', '_bonumark_stream/backups',
                'media', 'index.html', 'stream', 'feed.xml', 'sitemap.xml', 'sitemap.xsl', 'robots.txt',
            ], true)
            && preg_match('/\.(?:log|sql|sqlite|zip|tar|tar\.gz|tgz)$/i', $relative) !== 1;
    }), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        $target = $temporary . substr($file->getPathname(), strlen($source));
        $file->isDir() ? mkdir($target, 0700, true) : copy($file->getPathname(), $target);
    }
    mkdir($temporary . '/sessions', 0700);
    mkdir($temporary . '/media', 0700);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $errorMessage);
    if ($listener === false) {
        throw new RuntimeException('Cannot reserve a loopback port: ' . $errorMessage);
    }
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $base = 'http://' . $address;
    $config = [
        'site_name' => 'Page Lifecycle Fixture', 'site_tagline' => 'Saved tagline',
        'site_admin_email' => 'saved@example.test', 'timezone' => 'America/Indiana/Indianapolis',
        'base_url' => $base, 'base_path' => '', 'security_salt' => bin2hex(random_bytes(24)),
        'scheduled_tasks_public_traffic_enabled' => '0', 'scheduled_tasks_heartbeat_enabled' => '0',
        'activitypub_enabled' => '0', 'pwa_enabled' => '0', 'mail_transport' => 'disabled',
        'database' => ['host' => (string)getenv('BMS_DB_HOST'), 'name' => (string)getenv('BMS_DB_NAME'),
            'user' => (string)getenv('BMS_DB_USER'), 'password' => (string)getenv('BMS_DB_PASS'), 'charset' => 'utf8mb4', 'prefix' => $prefix],
    ];
    file_put_contents($temporary . '/_bonumark_stream/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    touch($temporary . '/_bonumark_stream/installed.lock');
    require $temporary . '/_bonumark_stream/app/database.php';
    $pdo = bms_db();
    bms_database_require_supported($pdo);
    bms_install_schema($pdo, $prefix);
    $insertSetting = $pdo->prepare("INSERT INTO `{$prefix}settings` (setting_key, setting_value, updated_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    foreach ($config as $key => $value) {
        if (is_string($value)) {
            $insertSetting->execute([$key, $value]);
        }
    }
    $insertUser = $pdo->prepare("INSERT INTO `{$prefix}users` (username,display_name,email,email_verified_at,password_hash,role,status,created_at,updated_at) VALUES (?,?,?,UTC_TIMESTAMP(),?,?,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    foreach (['owner' => 'admin', 'commenter' => 'commenter'] as $username => $role) {
        $insertUser->execute([$username, ucfirst($username), $username . '@example.test', password_hash('disposable-password', PASSWORD_DEFAULT), $role]);
    }
    $migrationCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn();
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $temporary . '/sessions', '-S', $address, '-t', $temporary], [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/http.log', 'a'], 2 => ['file', $temporary . '/http.log', 'a']], $pipes, $temporary);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the isolated PHP HTTP server.');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @stream_socket_client('tcp://' . $address, $error, $errorMessage, 0.1);
        if ($socket !== false) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    bms_page_test_expect($ready, 'Isolated loopback server starts');
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    $guest = [];
    $sessions = [];
    foreach (['owner', 'commenter'] as $username) {
        $cookies = [];
        $login = bms_page_test_request($base, '/admin/login.php', $cookies);
        $login = bms_page_test_request($base, '/admin/login.php', $cookies, ['csrf_token' => bms_page_test_token($login['body']), 'username' => $username, 'password' => 'disposable-password']);
        bms_page_test_expect($login['status'] === 302, ucfirst($username) . ' fixture authenticates through real login');
        $sessions[$username] = $cookies;
    }

    require_once $temporary . '/_bonumark_stream/app/pages.php';
    $owner =& $sessions['owner'];
    $ownerId = (int)$pdo->query("SELECT id FROM `{$prefix}users` WHERE username='owner'")->fetchColumn();
    $commenterId = (int)$pdo->query("SELECT id FROM `{$prefix}users` WHERE username='commenter'")->fetchColumn();
    $row = static function (int $id) use ($pdo, $prefix): ?array {
        $query = $pdo->prepare("SELECT * FROM `{$prefix}posts` WHERE id=?");
        $query->execute([$id]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $bySlug = static function (string $slug, string $status, string $type = 'page') use ($pdo, $prefix): ?array {
        $query = $pdo->prepare("SELECT * FROM `{$prefix}posts` WHERE slug=? AND status=? AND post_type=?");
        $query->execute([$slug, $status, $type]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $related = static function (int $id) use ($pdo, $prefix): array {
        $data = [];
        foreach (['post_terms', 'revisions'] as $table) {
            $query = $pdo->prepare("SELECT * FROM `{$prefix}{$table}` WHERE post_id=?");
            $query->execute([$id]);
            $rows = array_map('serialize', $query->fetchAll(PDO::FETCH_ASSOC));
            sort($rows);
            $data[$table] = $rows;
        }
        return $data;
    };
    $snapshot = static function () use ($pdo, $prefix): array {
        $data = [];
        foreach (['posts', 'post_terms', 'revisions', 'trash'] as $table) {
            $rows = array_map('serialize', $pdo->query("SELECT * FROM `{$prefix}{$table}`")->fetchAll(PDO::FETCH_ASSOC));
            sort($rows);
            $data[$table] = $rows;
        }
        return $data;
    };
    $fields = static function (string $slug, string $body = 'Original Page body.'): array {
        return ['page_title' => 'Page ' . $slug, 'page_slug' => $slug, 'body_markdown' => $body,
            'page_date' => '2026-01-14', 'page_description' => 'Saved Page description.',
            'page_seo_title' => 'Search title for ' . $slug, 'page_robots' => 'noindex,follow', 'content_kind' => 'page'];
    };
    $tokenPage = bms_page_test_request($base, '/admin/page-new.php', $owner);
    $token = bms_page_test_token($tokenPage['body']);
    $create = static function (array $form, string $status = 'draft') use ($base, &$owner, $token, $bySlug): array {
        $response = bms_page_test_request($base, '/admin/page-new.php', $owner, $form + [
            'csrf_token' => $token, 'page_submit_action' => $status === 'published' ? 'publish' : 'draft']);
        bms_page_test_expect($response['status'] === 302 && str_contains($response['location'], 'page-edit.php?type=' . $status), 'Real form creates ' . $status . ' Page: ' . $form['page_slug']);
        parse_str((string)parse_url($response['location'], PHP_URL_QUERY), $query);
        $saved = $bySlug(pathinfo((string)($query['file'] ?? ''), PATHINFO_FILENAME), $status);
        bms_page_test_expect($saved !== null && $saved['post_type'] === 'page', 'Created Page exists in the database');
        return $saved;
    };
    $editPath = static fn(array $page): string => '/admin/page-edit.php?type=' . $page['status'] . '&file=' . rawurlencode($page['slug'] . '.md');
    $edit = static function (array $page, array $form, string $action = 'save', array $extra = []) use ($base, &$owner, $token, $editPath): array {
        return bms_page_test_request($base, $editPath($page), $owner, $form + $extra + [
            'csrf_token' => $token, 'type' => $page['status'], 'file' => $page['slug'] . '.md', 'stream_submit_action' => $action]);
    };
    $action = static function (string $route, array $page) use ($base, &$owner, $token): array {
        return bms_page_test_request($base, '/admin/' . $route . '.php', $owner, [
            'csrf_token' => $token, 'type' => $page['status'], 'file' => $page['slug'] . '.md']);
    };
    $notice = static function (array $response, string $message) use ($base, &$owner): void {
        bms_page_test_expect($response['status'] === 302, 'Operation retains its redirect');
        $destination = bms_page_test_request($base, $response['location'], $owner);
        bms_page_test_expect($destination['status'] === 200 && str_contains($destination['body'], $message), 'Destination shows notice: ' . $message);
    };
    $expectedExtras = [];
    $checkPage = static function (int $id, array $form, string $status, int $authorId, string $createdAt) use ($row, &$expectedExtras): array {
        $saved = $row($id);
        bms_page_test_expect($saved !== null && (int)$saved['id'] === $id, 'Page retains original record ID ' . $id);
        bms_page_test_expect($saved['post_type'] === 'page' && $saved['status'] === $status && $saved['slug'] === $form['page_slug'], 'Page type, status and slug match the requested transition');
        bms_page_test_expect((int)$saved['author_id'] === $authorId && $saved['created_at'] === $createdAt, 'Author identity and original creation timestamp remain intact');
        bms_page_test_expect($saved['title'] === $form['page_title'] && trim($saved['content_body']) === trim($form['body_markdown']), 'Title and Markdown body are preserved or updated exactly');
        $metadata = json_decode($saved['content_front_matter'], true, 512, JSON_THROW_ON_ERROR);
        foreach (['description' => 'page_description', 'seo_title' => 'page_seo_title', 'robots' => 'page_robots', 'date' => 'page_date'] as $stored => $field) {
            bms_page_test_expect(($metadata[$stored] ?? '') === $form[$field], 'Page metadata preserved: ' . $stored);
        }
        foreach ($expectedExtras[$id] ?? [] as $key => $value) {
            bms_page_test_expect(($metadata[$key] ?? null) === $value, 'Saved non-form Page metadata remains intact: ' . $key);
        }
        bms_page_test_expect($saved['content_source'] === 'database' && $saved['storage_mode'] === 'database', 'Database remains the runtime source');
        bms_page_test_expect($saved['markdown_path'] === 'content/pages/' . ($status === 'published' ? 'published' : 'drafts') . '/' . $form['page_slug'] . '.md', 'Virtual Page path follows the same record');
        return $saved;
    };

    $form = $fields('lifecycle-page');
    $draft = $create($form);
    $id = (int)$draft['id'];
    $createdAt = $draft['created_at'];
    $checkPage($id, $form, 'draft', $ownerId, $createdAt);
    $direct = $create($fields('direct-published'), 'published');
    bms_page_test_expect($direct['published_at'] !== null, 'Direct publication assigns a publication timestamp');
    $checkPage((int)$direct['id'], $fields('direct-published'), 'published', $ownerId, $direct['created_at']);

    // A historical/imported relation is a database fixture, not a new Page UI feature.
    $pdo->prepare("INSERT INTO `{$prefix}revisions` (post_id,slug,title,status,original_filename,content_body,content_source,author_id,created_at) VALUES (?,?,?,?,?,?,'database',?,UTC_TIMESTAMP())")
        ->execute([$id, $draft['slug'], 'Related identity fixture', 'draft', $draft['slug'] . '.md', 'Saved related body.', $ownerId]);
    $relations = $related($id);
    $form['body_markdown'] = "# Changed draft\n\nContent retained across publication.";
    $form['page_title'] = 'Changed draft title';
    $notice($edit($draft, $form), 'Draft page saved.');
    $draft = $checkPage($id, $form, 'draft', $ownerId, $createdAt);
    bms_page_test_expect($related($id) === $relations, 'Draft editing preserves category relationship and existing related identity');

    $expectedExtras[$id] = ['tags' => ['Imported tag'], 'featured_media' => 'media/page-fixture.jpg',
        'media_gallery' => ['media/page-fixture.jpg', 'media/page-second.jpg'], 'import_note' => 'Retained source metadata'];
    $metadata = array_replace(json_decode($draft['content_front_matter'], true, 512, JSON_THROW_ON_ERROR), $expectedExtras[$id]);
    $pdo->prepare("UPDATE `{$prefix}posts` SET content_front_matter=?,content_hash=? WHERE id=?")
        ->execute([json_encode($metadata, JSON_UNESCAPED_SLASHES), hash('sha256', $draft['content_body'] . "\n" . json_encode($metadata, JSON_UNESCAPED_SLASHES)), $id]);
    bms_sync_post_terms($id, ['category' => 'Page', 'tags' => ['Imported tag']]);

    $relations = $related($id);

    // These are the blocked Work 32 probe operations, now hard pass/fail assertions.
    $response = $action('page-publish', $draft);
    bms_page_test_expect($row($id) !== null && $row($id)['status'] === 'published', 'Dedicated publication keeps the original Page row (Work 32 negative control)');
    $notice($response, 'Page published.');
    $published = $checkPage($id, $form, 'published', $ownerId, $createdAt);
    $publishedAt = $published['published_at'];
    bms_page_test_expect($publishedAt !== null && $related($id) === $relations, 'Publication sets time and preserves related identity');
    $notice($action('page-unpublish', $published), 'back to drafts.');
    $draft = $checkPage($id, $form, 'draft', $ownerId, $createdAt);
    bms_page_test_expect($draft['published_at'] === $publishedAt && $related($id) === $relations, 'Unpublishing preserves first publication time and related records');
    $notice($edit($draft, $form, 'publish'), 'Page published.');
    $published = $checkPage($id, $form, 'published', $ownerId, $createdAt);
    bms_page_test_expect($published['published_at'] === $publishedAt && $related($id) === $relations, 'Editor publication preserves ID relationships and first publication time');

    $form['body_markdown'] = 'Updated published Page body.';
    $form['page_description'] = 'Updated description.';
    $form['page_seo_title'] = 'Updated search title';
    $form['page_robots'] = 'index,follow';
    $notice($edit($published, $form), 'Updated page.');
    $published = $checkPage($id, $form, 'published', $ownerId, $createdAt);
    bms_page_test_expect($related($id) === $relations, 'Published edit preserves existing relations');

    $before = $snapshot();
    $renamed = array_replace($form, ['page_slug' => 'renamed-published-page']);
    $notice($edit($published, $renamed), 'Confirm the live URL change');
    bms_page_test_expect($snapshot() === $before, 'Unacknowledged live URL change preserves all content records');
    $notice($edit($published, $renamed, 'save', ['confirm_slug_change' => '1']), 'Updated page.');
    $published = $checkPage($id, $renamed, 'published', $ownerId, $createdAt);
    bms_page_test_expect($bySlug($form['page_slug'], 'published') === null && $related($id) === $relations, 'Published slug change moves URL without replacing ID or relationships');
    $form = $renamed;
    $editor = bms_page_test_request($base, $editPath($published), $owner);
    bms_page_test_expect(str_contains($editor['body'], '/pages/renamed-published-page/'), 'Editor View Page points to the new public URL');
    $preview = bms_page_test_request($base, '/admin/preview.php?type=page-published&file=renamed-published-page.md', $owner);
    bms_page_test_expect($preview['status'] === 200 && str_contains($preview['body'], $form['body_markdown']), 'Saved Page preview reads the same content');
    $public = bms_page_test_request($base, '/page.php?slug=renamed-published-page', $guest);
    bms_page_test_expect($public['status'] === 200 && str_contains($public['body'], $form['body_markdown']) && str_contains($public['body'], 'index,follow'), 'Public Page renders body and indexing metadata after rename');
    $oldPublic = bms_page_test_request($base, '/page.php?slug=lifecycle-page', $guest);
    bms_page_test_expect($oldPublic['status'] === 404, 'Old Page URL retains existing not-found semantics');
    $home = bms_page_test_request($base, '/', $guest);
    bms_page_test_expect($home['status'] === 200 && !str_contains($home['body'], $form['body_markdown']), 'Page content stays out of the Stream frontend');

    $slugForm = $fields('draft-slug-before');
    $slugDraft = $create($slugForm);
    $slugId = (int)$slugDraft['id'];
    $slugForm['page_slug'] = 'draft-slug-after';
    $notice($edit($slugDraft, $slugForm), 'Draft page saved.');
    $slugDraft = $checkPage($slugId, $slugForm, 'draft', $ownerId, $slugDraft['created_at']);
    bms_page_test_expect($bySlug('draft-slug-before', 'draft') === null, 'Draft slug change leaves no replacement/duplicate old row');

    // Existing form collisions select a unique suffix; they do not overwrite.
    $targetBefore = $row((int)$direct['id']);
    $duplicate = $create($fields('direct-published'));
    bms_page_test_expect($duplicate['slug'] === 'direct-published-2' && $row((int)$direct['id']) === $targetBefore, 'Creation retains duplicate-slug suffixing and preserves target Page');
    $collisionForm = array_replace($slugForm, ['page_slug' => 'direct-published']);
    $notice($edit($slugDraft, $collisionForm), 'Draft page saved.');
    $collisionForm['page_slug'] = 'direct-published-3';
    $slugDraft = $checkPage($slugId, $collisionForm, 'draft', $ownerId, $slugDraft['created_at']);
    bms_page_test_expect($row((int)$direct['id']) === $targetBefore, 'Editing a conflicting slug cannot overwrite target Page');

    // Legacy/import data may contain the same slug in different statuses.
    $collisionDraft = $create($fields('collision-draft'));
    $collisionPublished = $create($fields('collision-published'), 'published');
    $pdo->prepare("UPDATE `{$prefix}posts` SET slug=?,markdown_path=? WHERE id=?")
        ->execute(['collision-draft', 'content/pages/published/collision-draft.md', $collisionPublished['id']]);
    $collisionPublished = $row((int)$collisionPublished['id']);
    $before = $snapshot();
    $notice($action('page-publish', $collisionDraft), 'Publish failed.');
    bms_page_test_expect($snapshot() === $before, 'Dedicated publish rejects occupied destination without changing either Page');
    $notice($action('page-unpublish', $collisionPublished), 'Unpublish failed.');
    bms_page_test_expect($snapshot() === $before, 'Dedicated unpublish rejects occupied destination without changing either Page');

    // HTTP-supplied IDs and authors must not replace trusted route identity.
    $otherBefore = $row((int)$direct['id']);
    $notice($edit($published, $form, 'save', ['post_id' => $direct['id'], 'id' => $direct['id'], 'author_id' => $commenterId]), 'Updated page.');
    $published = $checkPage($id, $form, 'published', $ownerId, $createdAt);
    bms_page_test_expect($row((int)$direct['id']) === $otherBefore, 'Forged request identity does not alter unrelated Page');

    // Real SQL failures, no application hooks or stubbed persistence. The term
    // INSERT failure occurs after posts UPDATE and term DELETE. Rename back
    // before comparison so an exact snapshot detects missing rollback.
    $failureDraftForm = $fields('failure-draft');
    $failureDraft = $create($failureDraftForm);
    $failurePublishedForm = $fields('failure-published');
    $failurePublished = $create($failurePublishedForm, 'published');
    $failures = [
        ['publish before update', 'posts', 'date_published', static fn() => $action('page-publish', $failureDraft), 'Publish failed.'],
        ['publish after update', 'post_terms', 'is_primary', static fn() => $action('page-publish', $failureDraft), 'Publish failed.'],
        ['unpublish after update', 'post_terms', 'is_primary', static fn() => $action('page-unpublish', $failurePublished), 'Unpublish failed.'],
        ['editor publication after update', 'post_terms', 'is_primary', static fn() => $edit($failureDraft, array_replace($failureDraftForm, ['body_markdown' => 'Must roll back.']), 'publish'), 'Save failed.'],
        ['draft slug after update', 'post_terms', 'is_primary', static fn() => $edit($failureDraft, array_replace($failureDraftForm, ['page_slug' => 'failure-renamed-draft', 'body_markdown' => 'Must roll back.'])), 'Save failed.'],
        ['published edit after update', 'post_terms', 'is_primary', static fn() => $edit($failurePublished, array_replace($failurePublishedForm, ['body_markdown' => 'Must roll back.'])), 'Save failed.'],
        ['published slug after update', 'post_terms', 'is_primary', static fn() => $edit($failurePublished, array_replace($failurePublishedForm, ['page_slug' => 'failure-renamed-published']), 'save', ['confirm_slug_change' => '1']), 'Save failed.'],
    ];
    foreach ($failures as [$label, $table, $column, $request, $message]) {
        $before = $snapshot();
        clearstatcache(true, $temporary . '/http.log');
        $logOffset = filesize($temporary . '/http.log');
        $pdo->exec("ALTER TABLE `{$prefix}{$table}` RENAME COLUMN `{$column}` TO `page_test_unavailable_column`");
        try {
            $response = $request();
        } finally {
            $pdo->exec("ALTER TABLE `{$prefix}{$table}` RENAME COLUMN `page_test_unavailable_column` TO `{$column}`");
        }
        $notice($response, $message);
        $newLog = substr(file_get_contents($temporary . '/http.log'), $logOffset);
        bms_page_test_expect(str_contains($newLog, 'Bonumark Stream admin error') && str_contains($newLog, $column), $label . ': real SQL failure was logged');
        bms_page_test_expect($snapshot() === $before, $label . ': complete original rows and related records survive unchanged');
    }
    $notice($action('page-publish', $failureDraft), 'Page published.');
    bms_page_test_expect($row((int)$failureDraft['id'])['status'] === 'published', 'Page remains usable after failed attempts');

    $before = $snapshot();
    foreach (['page_title', 'body_markdown'] as $required) {
        $invalid = array_replace($form, [$required => '']);
        $response = $edit($published, $invalid);
        $notice($response, $required === 'page_title' ? 'Page title is required.' : 'Page body cannot be empty.');
        bms_page_test_expect($snapshot() === $before, 'Required-field failure preserves original Page: ' . $required);
    }

    $protected = [
        ['/admin/page-new.php', $fields('unauthorized-page') + ['page_submit_action' => 'publish']],
        [$editPath($published), $form + ['stream_submit_action' => 'save']],
        ['/admin/page-publish.php', ['file' => $slugDraft['slug'] . '.md']],
        ['/admin/page-unpublish.php', ['file' => $published['slug'] . '.md']],
        ['/admin/page-delete.php', ['file' => $published['slug'] . '.md', 'type' => 'published']],
        ['/admin/page-restore.php', ['trash_id' => '1']],
        ['/admin/page-delete-permanent.php', ['empty_page_trash' => '1']],
    ];
    foreach ($protected as [$path, $formData]) {
        foreach (['guest', 'commenter', 'bad-csrf'] as $actor) {
            $cookies = $actor === 'guest' ? [] : ($actor === 'commenter' ? $sessions['commenter'] : $owner);
            $response = bms_page_test_request($base, $path, $cookies, $formData + ['csrf_token' => $actor === 'bad-csrf' ? 'invalid' : $token]);
            bms_page_test_expect($response['status'] === ($actor === 'guest' ? 302 : 403), $actor . ' cannot mutate through ' . $path);
            bms_page_test_expect($snapshot() === $before, 'Rejected request leaves content unchanged');
        }
    }
    foreach (['/admin/page-new.php', $editPath($published), '/admin/pages.php'] as $path) {
        foreach (['guest', 'commenter'] as $actor) {
            $cookies = $actor === 'guest' ? [] : $sessions['commenter'];
            $response = bms_page_test_request($base, $path, $cookies);
            bms_page_test_expect($response['status'] === ($actor === 'guest' ? 302 : 403), $actor . ' cannot read Page administration');
        }
    }
    foreach (['page-publish', 'page-unpublish', 'page-delete', 'page-restore', 'page-delete-permanent'] as $route) {
        $response = bms_page_test_request($base, '/admin/' . $route . '.php', $owner);
        bms_page_test_expect($response['status'] === 403 && $snapshot() === $before, 'GET without POST CSRF cannot invoke ' . $route);
    }

    // Stream creation/publication still uses its existing frontend and handler.
    $composer = bms_page_test_request($base, '/?compose=1', $owner);
    preg_match('/name="composer_request_key"\s+value="([^"]+)"/', $composer['body'], $requestKey);
    bms_page_test_expect(isset($requestKey[1]), 'Stream frontend retains its request guard');
    $streamResponse = bms_page_test_request($base, '/admin/quick-post.php', $owner, [
        'csrf_token' => bms_page_test_token($composer['body']), 'composer_request_key' => html_entity_decode($requestKey[1], ENT_QUOTES, 'UTF-8'),
        'stream_title' => 'Stream isolation fixture', 'stream_slug' => 'stream-isolation-fixture', 'stream_body' => 'Separate Stream body.',
        'stream_submit_action' => 'continue', 'return_to' => $base . '/']);
    $stream = $bySlug('stream-isolation-fixture', 'draft', 'stream');
    bms_page_test_expect($streamResponse['status'] === 302 && $stream !== null, 'Stream draft creation still works');
    $streamId = (int)$stream['id'];
    $before = $snapshot();
    $pageAttack = bms_page_test_request($base, '/admin/page-edit.php?type=draft&file=stream-isolation-fixture.md', $owner, $fields('stream-isolation-fixture') + ['csrf_token' => $token]);
    bms_page_test_expect($pageAttack['status'] === 404 && $snapshot() === $before, 'Page editor cannot address a Stream record');
    $notice($action('page-publish', $stream), 'Publish failed.');
    bms_page_test_expect($snapshot() === $before, 'Page publisher cannot address a Stream record');
    $streamResponse = bms_page_test_request($base, '/admin/publish.php', $owner, ['csrf_token' => $token, 'file' => 'stream-isolation-fixture.md']);
    bms_page_test_expect($streamResponse['status'] === 302 && $row($streamId)['status'] === 'published' && $row($streamId)['post_type'] === 'stream', 'Dedicated Stream publication preserves its original identity');
    $streamBefore = $row($streamId);

    // The shared upsert must continue rejecting invented or cross-type identity.
    foreach (['missing', 'stream'] as $kind) {
        $bad = bms_database_content_page_for_status(bms_database_row_to_content_page($published), 'draft', 'page');
        $bad['id'] = $bad['post_id'] = $kind === 'missing' ? 2147483647 : $streamId;
        $before = $snapshot();
        $error = '';
        try { bms_upsert_database_content($bad, 'pages/drafts', 'forged.md', $ownerId); }
        catch (RuntimeException $e) { $error = $e->getMessage(); }
        bms_page_test_expect(str_contains($error, $kind === 'missing' ? 'will not recreate' : 'different content type'), 'Shared guard rejects ' . $kind . ' identity');
        bms_page_test_expect($snapshot() === $before, 'Shared guard failure performs no content write');
    }

    // Trusted-source checks protect against stale saves and cross-record writes.
    $original = bms_find_database_content_by_markdown_path('pages/published', $published['slug'] . '.md');
    $updated = bms_database_content_page_for_status($original, 'draft', 'page');
    foreach (['stale', 'missing', 'stream'] as $kind) {
        $badOriginal = $original;
        if ($kind === 'stale') { $badOriginal['content_hash'] = str_repeat('0', 64); }
        else { $badOriginal['id'] = $badOriginal['post_id'] = $kind === 'missing' ? 2147483647 : $streamId; }
        $before = $snapshot();
        $error = '';
        try { bms_update_existing_page($badOriginal, $updated, 'pages/drafts', $updated['slug'] . '.md'); }
        catch (RuntimeException $e) { $error = $e->getMessage(); }
        bms_page_test_expect($error !== '' && $snapshot() === $before && !$pdo->inTransaction(), 'Page helper rejects ' . $kind . ' source without changing content');
    }

    // Caller-owned transactions remain owned by the caller on success or failure.
    $before = $snapshot();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE `{$prefix}posts` SET title=? WHERE id=?")->execute(['Outer transaction value', $direct['id']]);
        bms_update_existing_page($original, $updated, 'pages/drafts', $updated['slug'] . '.md');
        bms_page_test_expect($pdo->inTransaction() && $row($id)['status'] === 'draft', 'Nested Page update preserves caller transaction and original ID');
        bms_page_test_expect($row((int)$direct['id'])['title'] === 'Outer transaction value', 'Nested success preserves the outer write');
    } finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } }
    bms_page_test_expect($snapshot() === $before, 'Caller rollback reverses the nested successful Page update');

    $pdo->exec("ALTER TABLE `{$prefix}post_terms` RENAME COLUMN is_primary TO page_test_unavailable_column");
    try {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE `{$prefix}posts` SET title=? WHERE id=?")->execute(['Outer failure value', $direct['id']]);
            $outer = $snapshot();
            $error = '';
            try { bms_update_existing_page($original, $updated, 'pages/drafts', $updated['slug'] . '.md'); }
            catch (Throwable $e) { $error = $e->getMessage(); }
            bms_page_test_expect(str_contains($error, 'is_primary'), 'Nested update reaches the real term INSERT failure');
            bms_page_test_expect($pdo->inTransaction() && $snapshot() === $outer, 'Nested failure rolls back only the Page update and preserves the outer write');
        } finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } }
    } finally { $pdo->exec("ALTER TABLE `{$prefix}post_terms` RENAME COLUMN page_test_unavailable_column TO is_primary"); }
    bms_page_test_expect($snapshot() === $before, 'Outer rollback and schema restoration leave all content unchanged');

    $nullAuthorForm = $fields('null-author');
    $nullAuthor = $create($nullAuthorForm);
    $pdo->prepare("UPDATE `{$prefix}posts` SET author_id=NULL WHERE id=?")->execute([$nullAuthor['id']]);
    $notice($action('page-publish', $nullAuthor), 'Page published.');
    bms_page_test_expect($row((int)$nullAuthor['id'])['author_id'] === null, 'Publication preserves an existing NULL author rather than assigning the current Admin');

    $trash = $create($fields('page-trash-fixture'));
    $trashId = (int)$trash['id'];
    $notice($action('page-delete', $trash), 'Moved page');
    $query = $pdo->prepare("SELECT * FROM `{$prefix}trash` WHERE slug=? AND original_status='page_draft'");
    $query->execute(['page-trash-fixture']);
    $trashed = $query->fetch(PDO::FETCH_ASSOC);
    bms_page_test_expect($row($trashId) === null && $trashed && trim($trashed['content_body']) === 'Original Page body.', 'Page Trash retains its existing body-preservation behavior');
    $trashList = bms_page_test_request($base, '/admin/pages.php?status=trash', $owner);
    bms_page_test_expect($trashList['status'] === 200 && str_contains($trashList['body'], 'page-trash-fixture'), 'Page Trash remains discoverable');
    bms_page_test_expect($row($streamId) === $streamBefore, 'Page lifecycle and Trash do not change Stream content');
    bms_page_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn() === $migrationCount, 'Page correction introduces no migration');
    fwrite(STDOUT, 'Page lifecycle runtime regression passed: ' . $checks . " checks.\n");

} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($pdo instanceof PDO) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (str_starts_with((string)$table, $prefix)) {
                $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string)$table) . '`');
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    bms_page_test_remove_tree($temporary);
}
