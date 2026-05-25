<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/editor.php';
mp_require_login();
mp_require_capability('manage_media');

header('Content-Type: application/json; charset=utf-8');

function mp_media_picker_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function mp_media_picker_items(string $search = ''): array
{
    $items = function_exists('mp_media_list') ? mp_media_list(160, $search) : [];
    return array_map('mp_editor_media_payload', $items);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $search = trim((string)($_GET['s'] ?? ''));
        mp_media_picker_json([
            'ok' => true,
            'items' => mp_media_picker_items($search),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        mp_verify_csrf();
        $action = (string)($_POST['action'] ?? 'upload');
        if ($action !== 'upload') {
            mp_media_picker_json(['ok' => false, 'message' => 'Unsupported media action.'], 400);
        }

        $file = $_FILES['media_file'] ?? null;
        if (!$file) {
            mp_media_picker_json(['ok' => false, 'message' => 'Choose a media file and try again.'], 400);
        }

        $media = mp_media_upload($file, (string)($_POST['alt_text'] ?? ''), (string)($_POST['caption'] ?? ''));
        mp_media_picker_json([
            'ok' => true,
            'message' => 'Media uploaded.',
            'media' => mp_editor_media_payload($media),
            'items' => mp_media_picker_items(),
        ]);
    }

    mp_media_picker_json(['ok' => false, 'message' => 'Method not allowed.'], 405);
} catch (Throwable $e) {
    mp_media_picker_json(['ok' => false, 'message' => $e->getMessage()], 400);
}
