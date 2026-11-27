<?php
/**
 * Content portability unit test. Needs no database.
 *
 * Covers the pieces an Export → Import round trip depends on: front matter
 * that reads back exactly what was written, publication instants and their
 * storage on a site with a pre-0.5.23 timestamp cutover, media paths and the
 * rewriting of references to restored media, the export document's
 * publication fields, and the import item's carried fields.
 *
 * Every check is reported, so a run against an older tree shows exactly which
 * pieces are missing.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('CLI only.');
}

define('BMS_STATELESS_PROTOCOL_REQUEST', true);
$root = dirname(__DIR__);
require_once $root . '/_bonumark_stream/app/functions.php';
require_once $root . '/_bonumark_stream/app/database.php';
if (is_file($root . '/_bonumark_stream/app/portability.php')) {
    require_once $root . '/_bonumark_stream/app/portability.php';
}
require_once $root . '/_bonumark_stream/app/importers/ImportItem.php';

$results = ['pass' => 0, 'fail' => 0, 'failed' => []];

function bms_port_check(string $label, bool $condition): void
{
    global $results;
    $results[$condition ? 'pass' : 'fail']++;
    if (!$condition) {
        $results['failed'][] = $label;
    }
    fwrite(STDOUT, ($condition ? 'PASS ' : 'FAIL ') . $label . "\n");
}

function bms_port_section(string $label, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        bms_port_check($label . ' ran without error (' . get_class($e) . ': ' . $e->getMessage() . ')', false);
    }
}

bms_port_section('Front matter', static function (): void {
    $values = [
        'quotes' => 'Launch "Day" notes',
        'backslash' => 'C:\\path\\to\\file',
        'apostrophes' => "It's 'single'",
        'mixed' => 'Say \\"hi\\" \\\\ there',
        'edge quotes' => '"Quoted at both ends"',
        'colon and hash' => 'Time: 10:30 # not a comment',
        'unicode' => 'Café — “curly” ✓',
        'trailing backslash' => 'ends with \\',
    ];
    foreach ($values as $label => $value) {
        $raw = bms_build_markdown_document([
            'title' => $value, 'slug' => 'x', 'seo_title' => $value, 'robots' => 'noindex, nofollow',
            'link_preview_title' => $value, 'link_preview_description' => $value, 'location_name' => $value,
        ], 'Body');
        $parsed = bms_parse_markdown_string($raw);
        bms_port_check('Front matter round-trips a value with ' . $label, $parsed['title'] === $value && $parsed['seo_title'] === $value && $parsed['link_preview_title'] === $value && $parsed['link_preview_description'] === $value && $parsed['location_name'] === $value);
    }
    // Tags trim quotes at their ends when normalized; quotes inside survive.
    $raw = bms_build_markdown_document(['title' => 'Tagged', 'slug' => 'tagged', 'tags' => ['one "quoted" tag', 'two\\back']], 'Body');
    bms_port_check('Front matter round-trips quoted and backslashed tags', bms_parse_markdown_string($raw)['tags'] === ['one "quoted" tag', 'two\\back']);
    // Hand-written files keep the earlier lenient reading.
    $handWritten = bms_parse_markdown_string("---\ntitle: 'single quoted'\ndescription: plain text\nslug: \"C:\\path\"\n---\nBody\n");
    bms_port_check('Hand-written single-quoted and bare values read as before', $handWritten['title'] === 'single quoted' && $handWritten['description'] === 'plain text');
    bms_port_check('An unknown escape in a hand-written value is kept as written', $handWritten['front_matter']['slug'] === 'C:\\path');
});

bms_port_section('Publication instants', static function (): void {
    if (!function_exists('bms_portability_utc_from_value')) {
        bms_port_check('Export instants can be read', false);
        return;
    }
    bms_port_check('An ISO 8601 UTC instant is read as UTC', bms_portability_utc_from_value('2025-03-04T05:06:07Z') === '2025-03-04 05:06:07');
    bms_port_check('An instant with an offset is converted to UTC', bms_portability_utc_from_value('2025-03-04T05:06:07+02:00') === '2025-03-04 03:06:07');
    bms_port_check('A stored value without an offset is read as UTC', bms_portability_utc_from_value('2025-03-04 05:06:07') === '2025-03-04 05:06:07');
    bms_port_check('An impossible date is refused, not rolled over', bms_portability_utc_from_value('2025-02-30T00:00:00Z') === '');
    bms_port_check('Empty, zero, and malformed values are refused', bms_portability_utc_from_value('') === '' && bms_portability_utc_from_value('0000-00-00 00:00:00') === '' && bms_portability_utc_from_value('yesterday') === '');
    bms_port_check('A stored UTC value is exported as ISO 8601 UTC', bms_portability_iso_from_utc('2025-03-04 05:06:07') === '2025-03-04T05:06:07Z');

    $newYork = new DateTimeZone('America/New_York');
    $after = bms_portability_published_at_storage('2026-11-27 10:00:00', '2026-11-27 09:00:00', $newYork);
    bms_port_check('A time after the cutover is stored as UTC', $after === ['value' => '2026-11-27 10:00:00', 'exact' => true]);
    $before = bms_portability_published_at_storage('2025-06-07 08:09:10', '2026-11-27 09:00:00', $newYork);
    bms_port_check('A time before the cutover is stored as the site-local clock value that reads back as the same instant', $before === ['value' => '2025-06-07 04:09:10', 'exact' => true]);
    // 2025-11-02 05:30 UTC is 01:30 EDT, the first of two 01:30 readings.
    $firstFallBack = bms_portability_published_at_storage('2025-11-02 05:30:00', '2026-11-27 09:00:00', $newYork);
    bms_port_check('The first reading of a repeated local hour is stored exactly', $firstFallBack === ['value' => '2025-11-02 01:30:00', 'exact' => true]);
    // 06:30 UTC is 01:30 EST, which a local clock value cannot tell apart.
    $secondFallBack = bms_portability_published_at_storage('2025-11-02 06:30:00', '2026-11-27 09:00:00', $newYork);
    bms_port_check('A local clock value that cannot name the instant is reported as not exact', $secondFallBack['exact'] === false);
    $tokyo = new DateTimeZone('Asia/Tokyo');
    $nearCutover = bms_portability_published_at_storage('2026-11-27 08:00:00', '2026-11-27 09:00:00', $tokyo);
    bms_port_check('Just before the cutover, a local value that would read as UTC is reported as not exact', $nearCutover['exact'] === false);
});

bms_port_section('Media paths', static function (): void {
    if (!function_exists('bms_portability_clean_media_path')) {
        bms_port_check('Media paths can be checked', false);
        return;
    }
    bms_port_check('A library path is accepted', bms_portability_clean_media_path('/media/2026/11/bms-abc.jpg') === 'media/2026/11/bms-abc.jpg');
    foreach (['media/../config.php', 'media/2026/../../x.jpg', 'media/.htaccess', 'uploads/x.jpg', 'media/2026/a b.jpg', "media/x\0.jpg"] as $unsafe) {
        bms_port_check('An unsafe media path is refused: ' . json_encode($unsafe), bms_portability_clean_media_path($unsafe) === '');
    }
    bms_port_check('Library, derivative, profile, and other files are told apart',
        bms_portability_media_category('media/2026/11/a.jpg') === 'library'
        && bms_portability_media_category('media/_generated/2026/11/a-480w.jpg') === 'derivative'
        && bms_portability_media_category('media/avatars/a.jpg') === 'profile'
        && bms_portability_media_category('media/profile-photos/1/a.jpg') === 'profile'
        && bms_portability_media_category('media/2026/11/a.php') === 'other');
    bms_port_check('A media URL on any host maps to its media path', bms_portability_media_path_from_url('https://old.example/blog/media/2026/11/a.jpg') === 'media/2026/11/a.jpg' && bms_portability_media_path_from_url('/media/2026/11/a.jpg') === 'media/2026/11/a.jpg' && bms_portability_media_path_from_url('https://example.org/og.png') === '');
    $referenced = bms_portability_referenced_media("Text ![Alt one](/media/2026/11/a.jpg) and [a file](/media/2026/11/b.pdf) and ![remote](https://example.org/r.png)", ['media/2026/11/c.png']);
    bms_port_check('Referenced media lists body images, links, and gallery items', array_keys($referenced) === ['media/2026/11/a.jpg', 'media/2026/11/b.pdf', 'media/2026/11/c.png'] && $referenced['media/2026/11/a.jpg'] === 'Alt one');
    $body = "Keep ![Alt](/media/2026/11/a.jpg \"Title\") and move [file](/media/2026/11/b.pdf) but not ![r](https://example.org/r.png)";
    $rewritten = bms_portability_rewrite_media_references($body, ['media/2026/11/a.jpg' => 'media/2026/11/a.jpg', 'media/2026/11/b.pdf' => 'media/2026/11/new.pdf']);
    bms_port_check('Rewriting leaves an unchanged reference byte for byte', str_contains($rewritten, '![Alt](/media/2026/11/a.jpg "Title")'));
    bms_port_check('Rewriting points a moved file at its new path', str_contains($rewritten, '[file](/media/2026/11/new.pdf)') && str_contains($rewritten, '![r](https://example.org/r.png)'));
    bms_port_check('A gallery media path stays a media path', bms_portability_rewrite_media_value('media/2026/11/b.pdf', ['media/2026/11/b.pdf' => 'media/2026/11/new.pdf']) === 'media/2026/11/new.pdf' && bms_portability_rewrite_media_value('media/2026/11/a.jpg', ['media/2026/11/a.jpg' => 'media/2026/11/a.jpg']) === 'media/2026/11/a.jpg');
});

bms_port_section('Export document', static function (): void {
    if (!function_exists('bms_portability_export_document')) {
        bms_port_check('The export document carries publication fields', false);
        return;
    }
    $page = [
        'post_id' => 0, 'title' => 'Pinned "post"', 'slug' => 'pinned-post', 'status' => 'published', 'content_type' => 'stream', 'post_type' => 'stream',
        'date' => '2025-03-04', 'description' => 'Desc', 'tags' => ['news'], 'published_at' => '2025-03-04 05:06:07', 'is_pinned' => true, 'pinned_at' => '2025-03-05 06:07:08',
        'stream_created_at' => '2025-03-04 05:06:07', 'front_matter' => ['stream_created_at' => '2025-03-04 00:06:07'], 'seo_title' => 'SEO "x"', 'body' => 'Body text.',
    ];
    $raw = bms_portability_export_document($page);
    $parsed = bms_parse_markdown_string($raw);
    bms_port_check('The export document reads back as Markdown with its body', trim((string)$parsed['body']) === 'Body text.' && $parsed['title'] === 'Pinned "post"' && $parsed['seo_title'] === 'SEO "x"');
    bms_port_check('The export document carries the publication instant and pin state', ($parsed['front_matter']['published_at'] ?? '') === '2025-03-04T05:06:07Z' && ($parsed['front_matter']['pinned'] ?? '') === '1' && ($parsed['front_matter']['pinned_at'] ?? '') === '2025-03-05T06:07:08Z');
    bms_port_check('The export document carries the stored creation time, not the publication time', $parsed['stream_created_at'] === '2025-03-04 00:06:07');
    $draft = bms_portability_export_document(['status' => 'draft', 'is_pinned' => true, 'published_at' => '', 'pinned_at' => '2025-03-05 06:07:08'] + $page);
    bms_port_check('A draft carries no pin state or publication instant it does not have', !str_contains($draft, 'pinned:') && !str_contains($draft, 'published_at:'));
});

bms_port_section('Import item', static function (): void {
    $item = new BMS_ImportItem('T', 's', 'B', '2025-01-01', '2025-01-01 00:00:00', '', 'published', '', '', [], [], 'stream', [], ['seo_title' => 'SEO', 'is_pinned' => '1', 'unknown_key' => 'x', 'robots' => ['not', 'scalar']]);
    $copy = BMS_ImportItem::fromArray(json_decode((string)json_encode($item->toArray()), true));
    bms_port_check('Carried fields survive the stored preview', $copy->meta === ['seo_title' => 'SEO', 'is_pinned' => '1']);
    bms_port_check('Only known carried fields are kept', !isset($copy->meta['unknown_key']) && !isset($copy->meta['robots']));
});

fwrite(STDOUT, sprintf("\nContent portability test: %d passed, %d failed.\n", $results['pass'], $results['fail']));
if ($results['fail'] > 0) {
    foreach ($results['failed'] as $failed) {
        fwrite(STDERR, 'FAILED ' . $failed . "\n");
    }
    exit(1);
}
