<?php
/** Real-database regression for the Admin media library and picker search. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

function bms_api_smoke_media_search_native_prepares(): void
{
    $pdo = bms_db();
    $assert = static function (bool $ok, string $message): void {
        if (!$ok) { throw new RuntimeException($message); }
    };
    $assert(!$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), 'Media search must be tested with native prepares.');
    $ids = static fn(array $rows): array => array_map('intval', array_column($rows, 'id'));
    $insert = $pdo->prepare('INSERT INTO ' . bms_table('media') . " (filename, original_filename, public_path, mime_type, file_size, alt_text, caption, uploaded_by, created_at, updated_at, trashed_at) VALUES (?, ?, ?, 'image/png', 4, ?, ?, 1, ?, ?, ?)");
    $seed = static function (string $filename, string $original, string $alt, string $caption, string $created = '2026-10-04 12:00:00', string $updated = '2026-10-04 12:00:00', ?string $trashed = null) use ($pdo, $insert): int {
        $insert->execute([$filename, $original, 'media/' . $filename, $alt, $caption, $created, $updated, $trashed]);
        return (int)$pdo->lastInsertId();
    };
    // Each needle appears in exactly one searchable field and one row.
    $fields = [
        'original_filename' => ['a.png', 'unique-original-needle', '', ''],
        'filename' => ['unique-filename-needle.png', 'b.png', '', ''],
        'alt_text' => ['c.png', 'c-original.png', 'unique-alt-needle', ''],
        'caption' => ['d.png', 'd-original.png', '', 'unique-caption-needle'],
    ];
    $needles = ['original_filename' => 'unique-original-needle', 'filename' => 'unique-filename-needle', 'alt_text' => 'unique-alt-needle', 'caption' => 'unique-caption-needle'];
    foreach ($fields as $field => $values) {
        $id = $seed(...$values);
        $assert(in_array($id, $ids(bms_media_list()), true), 'Unfiltered fixture row missing.');
        $assert($ids(bms_media_list(100, $needles[$field])) === [$id], 'Native-prepared media search failed for ' . $field . '.');
        $assert($ids(bms_media_list(100, '  ' . $needles[$field] . '  ')) === [$id], 'Search trimming changed for ' . $field . '.');
    }
    $assert(bms_media_list(100, 'no-such-media-needle') === [], 'A legitimate no-match search is not empty.');
    $assert(bms_media_list(100, " \t\n") === bms_media_list(), 'Whitespace-only search changed ordinary listing.');

    // Tied timestamps exercise every existing secondary sort column.
    $a = $seed('order-a.png', 'group-needle', '', '', '2026-10-04 11:00:00');
    $b = $seed('order-b.png', 'group-needle', '', '');
    $c = $seed('order-c.png', 'group-needle', '', '');
    $t1 = $seed('order-t1.png', 'group-needle', '', '', '2026-10-04 12:00:00', '2026-10-04 14:00:00', '2026-10-04 13:00:00');
    $t2 = $seed('order-t2.png', 'group-needle', '', '', '2026-10-04 12:00:00', '2026-10-04 15:00:00', '2026-10-04 13:00:00');
    $t3 = $seed('order-t3.png', 'group-needle', '', '', '2026-10-04 12:00:00', '2026-10-04 15:00:00', '2026-10-04 13:00:00');
    $t4 = $seed('order-t4.png', 'group-needle', '', '', '2026-10-04 12:00:00', '2026-10-04 12:00:00', '2026-10-04 14:00:00');
    $assert($ids(bms_media_list(100, 'group-needle', 'active')) === [$c, $b, $a], 'Active search filtering/order changed.');
    $assert($ids(bms_media_list(100, 'group-needle', 'trash')) === [$t4, $t3, $t2, $t1], 'Trash search filtering/order changed.');
    $assert($ids(bms_media_list(100, 'group-needle', 'all')) === [$t4, $t3, $t2, $t1, $c, $b, $a], 'All-media search filtering/order changed.');
    $assert(bms_media_list(100, 'group-needle', 'invalid') === bms_media_list(100, 'group-needle', 'active'), 'Unknown status normalization changed.');
    $assert(bms_media_list(100, 'group-needle', ' TRASH ') === bms_media_list(100, 'group-needle', 'trash'), 'Status case/whitespace normalization changed.');

    $wildA = $seed('wild-a.png', 'wild-X-token', '', '');
    $wildB = $seed('wild-b.png', 'wild-XYZ-token', '', '');
    $assert($ids(bms_media_list(100, 'wild-%-token')) === [$wildB, $wildA], 'Percent no longer acts as a LIKE wildcard.');
    $assert($ids(bms_media_list(100, 'wild-_-token')) === [$wildA], 'Underscore no longer acts as a LIKE wildcard.');
    $quote = $seed('quote.png', "bound' OR 1=1 --", '', '');
    $assert($ids(bms_media_list(100, "bound' OR 1=1 --")) === [$quote], 'Search was not safely bound as data.');

    $limitIds = [];
    for ($n = 0; $n < 505; $n++) { $limitIds[] = $seed('limit-' . $n . '.png', 'limit-needle', '', ''); }
    foreach ([-10 => 1, 0 => 1, 1 => 1, 100 => 100, 160 => 160, 200 => 200, 500 => 500, 999 => 500] as $limit => $count) {
        $assert($ids(bms_media_list($limit, 'limit-needle')) === array_slice(array_reverse($limitIds), 0, $count), 'Search limit clamp or row ordering changed.');
    }
    $assert(count(bms_media_list(100, '', 'all')) === 100 && bms_media_list(500, '', 'all') === bms_media_list(500, ' ', 'all'), 'Empty search changed all-media listing.');

    // Distinguish unrelated database failure from a legitimate zero-match result.
    // Only this isolated suite's randomly prefixed fixture table is renamed.
    $table = bms_table('media');
    $before = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
    $pdo->exec('RENAME TABLE ' . $table . ' TO ' . $table . '_unavailable');
    try {
        $failed = false;
        try { $pdo->query('SELECT * FROM ' . $table); }
        catch (PDOException $e) { $failed = $e->getCode() === '42S02'; }
        $assert($failed, 'Unrelated database-failure fixture was not active.');
        $assert(bms_media_list(100, 'group-needle') === [] && bms_media_list() === [], 'Admin failure fallback changed.');
    } finally { $pdo->exec('RENAME TABLE ' . $table . '_unavailable TO ' . $table); }
    $assert($before === $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(), 'Search/fallback changed media rows.');
    $assert($ids(bms_media_list(100, 'group-needle')) === [$c, $b, $a], 'Search failed after database recovery.');
}
