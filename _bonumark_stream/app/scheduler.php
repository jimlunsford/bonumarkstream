<?php
require_once __DIR__ . '/database.php';

function bms_scheduled_posts_lock_path(): string
{
    return bms_root_path('tmp/scheduled-posts.lock');
}

function bms_scheduled_tasks_lock_path(): string
{
    return bms_root_path('tmp/scheduled-tasks.lock');
}

function bms_scheduled_tasks_allowed_sources(): array
{
    return ['public_traffic', 'heartbeat', 'admin', 'manual', 'server_cron', 'web_cron'];
}

function bms_scheduled_tasks_normalize_source(string $source): string
{
    $source = strtolower(trim($source));
    return in_array($source, bms_scheduled_tasks_allowed_sources(), true) ? $source : 'manual';
}

function bms_scheduled_tasks_source_label(string $source): string
{
    return match (bms_scheduled_tasks_normalize_source($source)) {
        'public_traffic' => 'Public traffic',
        'heartbeat' => 'Browser heartbeat',
        'admin' => 'Admin page load',
        'server_cron' => 'Server cron',
        'web_cron' => 'Web cron',
        default => 'Manual run',
    };
}

function bms_scheduled_tasks_expected_interval_minutes(): int
{
    $minutes = (int)bms_setting_or_config('scheduled_tasks_expected_interval_minutes', '5');
    $allowed = [1, 5, 15, 30, 60];
    return in_array($minutes, $allowed, true) ? $minutes : 5;
}

function bms_scheduled_tasks_public_traffic_enabled(): bool
{
    return (string)bms_setting_or_config('scheduled_tasks_public_traffic_enabled', '1') !== '0';
}

function bms_scheduled_tasks_heartbeat_enabled(): bool
{
    return (string)bms_setting_or_config('scheduled_tasks_heartbeat_enabled', '1') !== '0';
}

function bms_scheduled_tasks_web_cron_enabled(): bool
{
    return (string)bms_setting_or_config('scheduled_tasks_web_cron_enabled', '0') === '1'
        && trim((string)bms_setting('scheduled_tasks_web_cron_key_hash', '')) !== '';
}

function bms_scheduled_tasks_last_run_timestamp(): int
{
    if (isset($GLOBALS['bms_scheduled_tasks_last_run_at'])) {
        return (int)$GLOBALS['bms_scheduled_tasks_last_run_at'];
    }
    return max(0, (int)bms_setting('scheduled_tasks_last_run_at', '0'));
}

function bms_scheduled_tasks_mark_last_run(array $result): void
{
    $timestamp = (int)($result['completed_at_unix'] ?? time());
    $source = bms_scheduled_tasks_normalize_source((string)($result['source'] ?? 'manual'));
    $status = (string)($result['status'] ?? 'completed');
    $message = trim((string)($result['message'] ?? 'Scheduled tasks completed.'));

    $GLOBALS['bms_scheduled_tasks_last_run_at'] = $timestamp;
    $GLOBALS['bms_scheduled_tasks_last_source'] = $source;
    $GLOBALS['bms_scheduled_tasks_last_status'] = $status;
    $GLOBALS['bms_scheduled_tasks_last_message'] = $message;

    bms_set_setting('scheduled_tasks_last_run_at', (string)$timestamp);
    bms_set_setting('scheduled_tasks_last_source', $source);
    bms_set_setting('scheduled_tasks_last_status', $status);
    bms_set_setting('scheduled_tasks_last_message', bms_scheduled_tasks_limit_text($message, 500));
}

function bms_scheduled_tasks_limit_text(string $value, int $limit): string
{
    $value = trim($value);
    if ($limit < 1) {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($value, 'UTF-8') > $limit ? mb_substr($value, 0, $limit, 'UTF-8') : $value;
    }
    return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
}

function bms_scheduled_tasks_should_record_history(string $source): bool
{
    return in_array(bms_scheduled_tasks_normalize_source($source), ['manual', 'server_cron', 'web_cron'], true);
}

function bms_scheduled_tasks_record_history(array $result): void
{
    if (!bms_scheduled_tasks_should_record_history((string)($result['source'] ?? 'manual'))) {
        return;
    }
    try {
        $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('scheduled_task_runs') . ' (source, status, scheduled_posts_published, details, started_at, completed_at) VALUES (:source, :status, :scheduled_posts_published, :details, :started_at, :completed_at)');
        $stmt->execute([
            'source' => bms_scheduled_tasks_normalize_source((string)($result['source'] ?? 'manual')),
            'status' => (string)($result['status'] ?? 'completed'),
            'scheduled_posts_published' => max(0, (int)($result['scheduled_posts_published'] ?? 0)),
            'details' => bms_scheduled_tasks_limit_text((string)($result['message'] ?? ''), 1000),
            'started_at' => gmdate('Y-m-d H:i:s', max(0, (int)($result['started_at_unix'] ?? time()))),
            'completed_at' => gmdate('Y-m-d H:i:s', max(0, (int)($result['completed_at_unix'] ?? time()))),
        ]);
        $pdo = bms_db();
        $pdo->exec('DELETE FROM ' . bms_table('scheduled_task_runs') . ' WHERE id NOT IN (SELECT id FROM (SELECT id FROM ' . bms_table('scheduled_task_runs') . ' ORDER BY id DESC LIMIT 100) AS recent_runs)');
    } catch (Throwable $e) {
        error_log('Bonumark Stream scheduled-task history error: ' . $e->getMessage());
    }
}

function bms_scheduled_tasks_history(int $limit = 20): array
{
    if (!bms_is_installed()) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    try {
        $stmt = bms_db()->query('SELECT * FROM ' . bms_table('scheduled_task_runs') . ' ORDER BY id DESC LIMIT ' . $limit);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function bms_scheduled_tasks_status(): array
{
    $lastRunAt = bms_scheduled_tasks_last_run_timestamp();
    $expectedMinutes = bms_scheduled_tasks_expected_interval_minutes();
    $ageSeconds = $lastRunAt > 0 ? max(0, time() - $lastRunAt) : null;
    $status = 'unknown';
    $message = 'No scheduled-task activity has been recorded yet.';
    if ($lastRunAt > 0) {
        $threshold = max(900, $expectedMinutes * 60 * 3);
        if ($ageSeconds !== null && $ageSeconds <= $threshold) {
            $status = 'healthy';
            $message = 'Recent task activity is within the expected interval.';
        } else {
            $status = 'stale';
            $message = 'No task run has been recorded within three expected intervals.';
        }
    }
    return [
        'status' => $status,
        'message' => $message,
        'last_run_at' => $lastRunAt,
        'last_source' => (string)($GLOBALS['bms_scheduled_tasks_last_source'] ?? bms_setting('scheduled_tasks_last_source', '')),
        'last_status' => (string)($GLOBALS['bms_scheduled_tasks_last_status'] ?? bms_setting('scheduled_tasks_last_status', '')),
        'last_message' => (string)($GLOBALS['bms_scheduled_tasks_last_message'] ?? bms_setting('scheduled_tasks_last_message', '')),
        'expected_interval_minutes' => $expectedMinutes,
        'public_traffic_enabled' => bms_scheduled_tasks_public_traffic_enabled(),
        'heartbeat_enabled' => bms_scheduled_tasks_heartbeat_enabled(),
        'web_cron_enabled' => bms_scheduled_tasks_web_cron_enabled(),
    ];
}

function bms_scheduled_tasks_history_timestamp(array $run, string $field = 'completed_at'): int
{
    $value = trim((string)($run[$field] ?? ''));
    if ($value === '') {
        return 0;
    }
    try {
        // Scheduled-task history is persisted in UTC through gmdate().
        return (new DateTimeImmutable($value, bms_utc_timezone()))->getTimestamp();
    } catch (Throwable $e) {
        return 0;
    }
}

function bms_scheduled_tasks_format_timestamp(int $timestamp): string
{
    if ($timestamp < 1) {
        return 'Not recorded yet';
    }
    try {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(bms_site_timezone())->format('M j, Y, g:i A T');
    } catch (Throwable $e) {
        return gmdate('M j, Y, g:i A \\U\\T\\C', $timestamp);
    }
}

function bms_scheduled_tasks_cron_expression(int $minutes): string
{
    $minutes = in_array($minutes, [1, 5, 15, 30, 60], true) ? $minutes : 5;
    if ($minutes === 1) {
        return '* * * * *';
    }
    if ($minutes === 60) {
        return '0 * * * *';
    }
    return '*/' . $minutes . ' * * * *';
}

function bms_scheduled_tasks_server_cron_command(): string
{
    $script = bms_public_path('scripts/run-scheduled-tasks.php');
    return 'php ' . escapeshellarg($script) . ' >/dev/null 2>&1';
}

function bms_scheduled_tasks_web_cron_url(): string
{
    return bms_site_url('api/v1/cron');
}

function bms_scheduled_tasks_extract_web_cron_key(): string
{
    $headerKey = trim((string)($_SERVER['HTTP_X_BONUMARK_CRON_KEY'] ?? ''));
    if ($headerKey !== '') {
        return $headerKey;
    }
    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\\s+(.+)$/i', $authorization, $matches) === 1) {
        return trim((string)$matches[1]);
    }
    return trim((string)($_GET['key'] ?? $_POST['key'] ?? ''));
}

function bms_scheduled_tasks_web_cron_key_is_valid(string $key): bool
{
    $key = trim($key);
    $hash = trim((string)bms_setting('scheduled_tasks_web_cron_key_hash', ''));
    return $key !== '' && $hash !== '' && password_verify($key, $hash);
}

function bms_scheduled_tasks_generate_web_cron_key(): string
{
    $key = bin2hex(random_bytes(32));
    bms_set_setting('scheduled_tasks_web_cron_key_hash', password_hash($key, PASSWORD_DEFAULT));
    bms_set_setting('scheduled_tasks_web_cron_enabled', '1');
    return $key;
}

function bms_scheduled_tasks_disable_web_cron(): void
{
    bms_set_setting('scheduled_tasks_web_cron_enabled', '0');
    bms_set_setting('scheduled_tasks_web_cron_key_hash', '');
}

function bms_scheduled_posts_due_count(): int
{
    if (!bms_is_installed() || !bms_database_content_columns_ready()) {
        return 0;
    }
    try {
        $stmt = bms_db()->query("SELECT COUNT(*) FROM " . bms_table('posts') . " WHERE post_type = 'stream' AND status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= UTC_TIMESTAMP()");
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function bms_upcoming_scheduled_posts(int $limit = 5): array
{
    if (!bms_is_installed() || !bms_database_content_columns_ready()) {
        return [];
    }
    $limit = max(1, min(20, $limit));
    try {
        $stmt = bms_db()->prepare("SELECT * FROM " . bms_table('posts') . " WHERE post_type = 'stream' AND status = 'scheduled' ORDER BY scheduled_at ASC, id ASC LIMIT " . $limit);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        return array_map('bms_database_row_to_content_page', $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function bms_scheduled_utc_to_site_date(string $utc): string
{
    $utc = trim($utc);
    if ($utc === '' || $utc === '0000-00-00 00:00:00') {
        return date('Y-m-d');
    }
    try {
        $date = new DateTimeImmutable($utc, bms_utc_timezone());
        return $date->setTimezone(bms_site_timezone())->format('Y-m-d');
    } catch (Throwable $e) {
        return substr($utc, 0, 10);
    }
}

function bms_publish_due_scheduled_posts(int $limit = 20): int
{
    if (!bms_is_installed() || !bms_database_content_columns_ready()) {
        return 0;
    }
    $limit = max(1, min(50, $limit));
    $lockPath = bms_scheduled_posts_lock_path();
    $lockDir = dirname($lockPath);
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0755, true);
    }
    $handle = @fopen($lockPath, 'c');
    if (!$handle) {
        return 0;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return 0;
    }

    try {
        $pdo = bms_db();
        $stmt = $pdo->prepare("SELECT id FROM " . bms_table('posts') . " WHERE post_type = 'stream' AND status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= UTC_TIMESTAMP() ORDER BY scheduled_at ASC, id ASC LIMIT " . $limit);
        $stmt->execute();
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (!$ids) {
            bms_set_setting('scheduled_posts_last_due_check', (string)time());
            return 0;
        }
        $published = 0;
        foreach ($ids as $id) {
            $select = $pdo->prepare('SELECT * FROM ' . bms_table('posts') . ' WHERE id = :id AND status = :status LIMIT 1');
            $select->execute(['id' => $id, 'status' => 'scheduled']);
            $row = $select->fetch();
            if (!is_array($row)) {
                continue;
            }
            $scheduledAt = (string)($row['scheduled_at'] ?? '');
            $publishedAt = $scheduledAt !== '' ? $scheduledAt : gmdate('Y-m-d H:i:s');
            $datePublished = bms_scheduled_utc_to_site_date($publishedAt);
            $frontMatter = [];
            $encodedFrontMatter = (string)($row['content_front_matter'] ?? '');
            if ($encodedFrontMatter !== '') {
                $decodedFrontMatter = json_decode($encodedFrontMatter, true);
                if (is_array($decodedFrontMatter)) {
                    $frontMatter = $decodedFrontMatter;
                }
            }
            $frontMatter['stream_created_at'] = $publishedAt;
            $frontMatter['scheduled_at'] = $scheduledAt;
            $contentFrontMatter = json_encode($frontMatter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($contentFrontMatter)) {
                $contentFrontMatter = $encodedFrontMatter;
            }
            $contentHash = hash('sha256', (string)($row['content_body'] ?? '') . "\n" . $contentFrontMatter);
            $htmlPath = trim(bms_stream_relative_directory_for_post(bms_database_row_to_content_page(array_merge($row, [
                'status' => 'published',
                'published_at' => $publishedAt,
                'date_published' => $datePublished,
                'content_front_matter' => $contentFrontMatter,
            ]))), '/') . '/index.html';
            $update = $pdo->prepare("UPDATE " . bms_table('posts') . " SET status = 'published', markdown_path = :markdown_path, html_path = :html_path, date_published = :date_published, published_at = :published_at, content_front_matter = :content_front_matter, content_hash = :content_hash, updated_at = UTC_TIMESTAMP() WHERE id = :id AND status = 'scheduled'");
            $update->execute([
                'markdown_path' => 'content/published/' . basename((string)($row['markdown_path'] ?? ((string)($row['slug'] ?? 'post') . '.md'))),
                'html_path' => $htmlPath,
                'date_published' => $datePublished,
                'published_at' => $publishedAt,
                'content_front_matter' => $contentFrontMatter,
                'content_hash' => $contentHash,
                'id' => $id,
            ]);
            if ($update->rowCount() > 0) {
                $published++;
            }
        }
        bms_set_setting('scheduled_posts_last_due_check', (string)time());
        if ($published > 0 && function_exists('bms_set_setting')) {
            bms_set_setting('public_output_stale', '1');
        }
        return $published;
    } catch (Throwable $e) {
        error_log('Bonumark Stream scheduled-post runner error: ' . $e->getMessage());
        return 0;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function bms_run_due_tasks(string $source = 'manual', bool $force = false, int $scheduledPostLimit = 50): array
{
    $source = bms_scheduled_tasks_normalize_source($source);
    $startedAt = time();
    $base = [
        'ok' => true,
        'source' => $source,
        'status' => 'completed',
        'started_at_unix' => $startedAt,
        'completed_at_unix' => $startedAt,
        'scheduled_posts_published' => 0,
        'message' => 'Scheduled tasks completed.',
        'skipped' => false,
    ];

    if (!bms_is_installed() || !bms_database_content_columns_ready()) {
        return array_merge($base, [
            'status' => 'unavailable',
            'message' => 'Scheduled tasks are unavailable until Bonumark Stream is installed and ready.',
            'skipped' => true,
        ]);
    }

    if (!$force) {
        $last = bms_scheduled_tasks_last_run_timestamp();
        if ($last > 0 && time() - $last < 30) {
            return array_merge($base, [
                'status' => 'throttled',
                'message' => 'Scheduled tasks were checked recently.',
                'skipped' => true,
            ]);
        }
    }

    $lockPath = bms_scheduled_tasks_lock_path();
    $lockDir = dirname($lockPath);
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0755, true);
    }
    $handle = @fopen($lockPath, 'c');
    if (!$handle) {
        return array_merge($base, [
            'ok' => false,
            'status' => 'error',
            'message' => 'Could not create the scheduled-task lock file.',
        ]);
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return array_merge($base, [
            'status' => 'locked',
            'message' => 'Another scheduled-task run is already in progress.',
            'skipped' => true,
        ]);
    }

    try {
        $published = bms_publish_due_scheduled_posts($scheduledPostLimit);
        $completedAt = time();
        $message = $published > 0
            ? 'Published ' . $published . ' due scheduled post' . ($published === 1 ? '' : 's') . '.'
            : 'No due scheduled posts were waiting.';
        $result = array_merge($base, [
            'completed_at_unix' => $completedAt,
            'scheduled_posts_published' => $published,
            'message' => $message,
        ]);
        bms_scheduled_tasks_mark_last_run($result);
        bms_scheduled_tasks_record_history($result);
        return $result;
    } catch (Throwable $e) {
        $completedAt = time();
        $message = 'Scheduled task run failed.';
        error_log('Bonumark Stream scheduled-task runner error: ' . $e->getMessage());
        $result = array_merge($base, [
            'ok' => false,
            'status' => 'error',
            'completed_at_unix' => $completedAt,
            'message' => $message,
        ]);
        try {
            bms_scheduled_tasks_mark_last_run($result);
            bms_scheduled_tasks_record_history($result);
        } catch (Throwable $ignored) {
        }
        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function bms_maybe_run_due_tasks(string $source = 'public_traffic'): array
{
    $source = bms_scheduled_tasks_normalize_source($source);
    if ($source === 'public_traffic' && !bms_scheduled_tasks_public_traffic_enabled()) {
        return ['ok' => true, 'source' => $source, 'status' => 'disabled', 'message' => 'Public traffic fallback is disabled.', 'skipped' => true, 'scheduled_posts_published' => 0];
    }
    if ($source === 'heartbeat' && !bms_scheduled_tasks_heartbeat_enabled()) {
        return ['ok' => true, 'source' => $source, 'status' => 'disabled', 'message' => 'Browser heartbeat fallback is disabled.', 'skipped' => true, 'scheduled_posts_published' => 0];
    }
    return bms_run_due_tasks($source, false, 20);
}

function bms_maybe_publish_due_scheduled_posts(): void
{
    bms_maybe_run_due_tasks('admin');
}

function bms_maybe_publish_due_scheduled_posts_for_public_request(string $context = 'public'): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return;
    }
    bms_maybe_run_due_tasks('public_traffic');
}

function bms_handle_web_cron_request(): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'GET or POST required.']);
        exit;
    }
    if (!bms_is_installed() || !bms_scheduled_tasks_web_cron_enabled()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found.']);
        exit;
    }
    if (!bms_scheduled_tasks_web_cron_key_is_valid(bms_scheduled_tasks_extract_web_cron_key())) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found.']);
        exit;
    }
    $result = bms_run_due_tasks('web_cron', true, 50);
    http_response_code(!empty($result['ok']) ? 200 : 500);
    echo json_encode([
        'ok' => !empty($result['ok']),
        'status' => (string)($result['status'] ?? 'error'),
        'scheduled_posts_published' => (int)($result['scheduled_posts_published'] ?? 0),
        'message' => (string)($result['message'] ?? ''),
        'checked_at' => gmdate('c', (int)($result['completed_at_unix'] ?? time())),
    ]);
    exit;
}

function bms_schedule_post_page(array $page, string $section, string $filename, ?int $authorId, string $scheduledAtUtc): int
{
    $page['status'] = 'scheduled';
    $page['content_status'] = 'scheduled';
    $page['scheduled_at'] = $scheduledAtUtc;
    $front = is_array($page['front_matter'] ?? null) ? $page['front_matter'] : [];
    $front['scheduled_at'] = $scheduledAtUtc;
    $page['front_matter'] = $front;
    return bms_upsert_database_content($page, 'scheduled', $filename, $authorId);
}
