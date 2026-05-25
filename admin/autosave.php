<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
mp_require_login();
header('Content-Type: application/json; charset=utf-8');

function mp_autosave_require_existing_file_access(string $section, string $filename): void
{
    $section = $section === 'published' ? 'published' : 'drafts';
    $filename = basename($filename);
    if ($filename === '') {
        return;
    }
    $page = function_exists('mp_find_database_content_by_markdown_path') ? mp_find_database_content_by_markdown_path($section, $filename) : null;
    $path = mp_content_path($section . '/' . $filename);
    if (!$page && is_file($path)) {
        $page = mp_parse_markdown_file($path);
    }
    if (!$page) {
        return;
    }
    mp_require_content_file_access($section, $filename, 'edit_content', $page);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $key = (string)($_GET['key'] ?? '');
        $autosave = function_exists('mp_get_autosave') ? mp_get_autosave($key) : null;
        if (!$autosave) {
            echo json_encode(['ok' => true, 'autosave' => null]);
            exit;
        }
        $autosaveFields = is_array($autosave['fields'] ?? null) ? $autosave['fields'] : [];
        mp_autosave_require_existing_file_access(
            (string)($autosaveFields['section'] ?? $autosave['section'] ?? 'drafts'),
            (string)($autosaveFields['filename'] ?? $autosave['filename'] ?? '')
        );
        echo json_encode([
            'ok' => true,
            'autosave' => [
                'title' => (string)($autosave['title'] ?? ''),
                'slug' => (string)($autosave['slug'] ?? ''),
                'markdown' => (string)($autosave['markdown'] ?? ''),
                'updated_at' => (string)($autosave['updated_at'] ?? ''),
                'fields' => is_array($autosave['fields'] ?? null) ? $autosave['fields'] : [],
            ],
        ]);
        exit;
    }

    mp_verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    $key = (string)($_POST['key'] ?? '');
    if ($action === 'delete') {
        if (function_exists('mp_delete_autosave')) {
            mp_delete_autosave($key);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    $markdown = (string)($_POST['markdown'] ?? '');
    if (strlen($markdown) > 1024 * 1024 * 2) {
        throw new RuntimeException('Autosave is too large.');
    }
    $fields = [
        'title' => (string)($_POST['title'] ?? ''),
        'slug' => (string)($_POST['slug'] ?? ''),
        'section' => (string)($_POST['section'] ?? 'drafts'),
        'filename' => basename((string)($_POST['filename'] ?? '')),
        'date' => (string)($_POST['date'] ?? ''),
        'content_type' => 'stream',
        'description' => (string)($_POST['description'] ?? ''),
        'category' => 'Stream',
        'tags' => '',
    ];
    mp_autosave_require_existing_file_access((string)$fields['section'], (string)$fields['filename']);
    if (function_exists('mp_save_autosave')) {
        mp_save_autosave($key, $fields, $markdown);
    }
    echo json_encode(['ok' => true, 'saved_at' => date('c')]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
