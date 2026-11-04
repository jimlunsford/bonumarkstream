<?php
/** RSS and sitemap timestamps, using disposable files and no database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$sourceRoot = realpath($argv[1] ?? dirname(__DIR__));
if ($sourceRoot === false || is_file($sourceRoot . '/_bonumark_stream/config.php')
    || is_file($sourceRoot . '/_bonumark_stream/installed.lock')) {
    fwrite(STDERR, "Use a clean source tree, never an installed site.\n");
    exit(2);
}
$fixtureRoot = sys_get_temp_dir() . '/bms-timestamps-' . bin2hex(random_bytes(8));
mkdir($fixtureRoot, 0700);
function timestamp_remove_tree(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            timestamp_remove_tree($path . '/' . $name);
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}
register_shutdown_function(static function () use ($fixtureRoot): void { timestamp_remove_tree($fixtureRoot); });
function timestamp_copy_tree(string $from, string $to): void
{
    mkdir($to, 0700, true);
    foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $name) {
        if (is_link($from . '/' . $name)) { throw new RuntimeException('Unexpected source symlink'); }
        if (is_dir($from . '/' . $name)) { timestamp_copy_tree($from . '/' . $name, $to . '/' . $name); }
        elseif (!copy($from . '/' . $name, $to . '/' . $name)) { throw new RuntimeException('Fixture copy failed'); }
    }
}
timestamp_copy_tree($sourceRoot . '/_bonumark_stream/app', $fixtureRoot . '/_bonumark_stream/app');
function timestamp_configure(string $cutover = '1970-01-01 00:00:00', string $zone = 'America/Indiana/Indianapolis'): void
{
    global $fixtureRoot;
    $config = ['timezone' => $zone, 'stream_published_at_utc_cutover' => $cutover,
        'base_url' => 'https://timestamps.invalid', 'public_path' => $fixtureRoot,
        'site_name' => 'Timestamp & Fixture', 'site_tagline' => 'Timestamp regression fixture',
        'stream_index_policy' => 'all', 'sitemap_include_profiles' => '0'];
    file_put_contents($fixtureRoot . '/_bonumark_stream/config.php', '<?php return ' . var_export($config, true) . ';');
    if (function_exists('bms_config')) { bms_config(true); bms_apply_site_timezone(); }
}
timestamp_configure();
require $fixtureRoot . '/_bonumark_stream/app/functions.php';
require $fixtureRoot . '/_bonumark_stream/app/renderer.php';
require $fixtureRoot . '/_bonumark_stream/app/sitemap.php';

$checks = 0;
$failures = [];
function timestamp_check(bool $ok, string $label): void
{
    global $checks, $failures;
    $checks++;
    if (!$ok) { $failures[] = $label; }
}
function timestamp_post(array $fields): array
{
    return array_replace(['status' => 'published', 'content_type' => 'stream', 'slug' => 'fixture',
        'title' => 'Timestamp fixture', 'body' => 'A timestamp fixture.', 'front_matter' => []], $fields);
}
function timestamp_xml(string $xml): DOMDocument
{
    $doc = new DOMDocument();
    if (!$doc->loadXML($xml)) { throw new RuntimeException('Invalid XML'); }
    return $doc;
}
function timestamp_feed_date(array $post): string
{
    return timestamp_xml(bms_render_rss_feed([$post]))->getElementsByTagName('pubDate')->item(0)->textContent;
}
function timestamp_near_now(string $value, int $before, string $label): void
{
    $epoch = strtotime($value);
    timestamp_check($epoch !== false && $epoch >= $before && $epoch <= time(), $label);
}
timestamp_check(!bms_is_installed() && !bms_has_database_config(), 'Fixture has no live database or installed state');

// Expected instants are literal, independent of the production conversion helpers.
$cases = [
    ['September 26 confirmed example', ['published_at' => '2026-09-26 09:12:31'], '2026-09-26T05:12:31-04:00'],
    ['September 13 confirmed example', ['published_at' => '2026-09-13 16:40:00'], '2026-09-13T12:40:00-04:00'],
    ['EST UTC publication', ['published_at' => '2026-01-15 12:30:00'], '2026-01-15T07:30:00-05:00'],
    ['EDT UTC publication', ['published_at' => '2026-07-15 12:30:00'], '2026-07-15T08:30:00-04:00'],
    ['Before spring forward', ['published_at' => '2026-03-08 06:59:59'], '2026-03-08T01:59:59-05:00'],
    ['After spring forward', ['published_at' => '2026-03-08 07:00:00'], '2026-03-08T03:00:00-04:00'],
    ['First fall-back hour', ['published_at' => '2026-11-01 05:30:00'], '2026-11-01T01:30:00-04:00'],
    ['Second fall-back hour', ['published_at' => '2026-11-01 06:30:00'], '2026-11-01T01:30:00-05:00'],
    ['Explicit positive offset', ['published_at' => '2026-09-26T14:42:31+05:30'], '2026-09-26T05:12:31-04:00'],
    ['Explicit UTC Z', ['published_at' => '2026-09-26T09:12:31Z'], '2026-09-26T05:12:31-04:00'],
    ['Explicit second repeated hour', ['published_at' => '2026-11-01T01:30:00-05:00'], '2026-11-01T01:30:00-05:00'],
    ['Local authored fallback', ['stream_created_at' => '2026-09-26 05:12:31'], '2026-09-26T05:12:31-04:00'],
    ['Local front matter fallback', ['front_matter' => ['stream_created_at' => '2026-01-15 07:30:00']], '2026-01-15T07:30:00-05:00'],
    ['Local date-only fallback', ['date' => '2026-09-26'], '2026-09-26T00:00:00-04:00'],
    ['Scheduled publication', ['created_at' => '2026-09-12 12:00:00', 'stream_created_at' => '2026-09-12 08:00:00',
        'published_at' => '2026-09-13 16:45:00', 'scheduled_at' => '2026-09-13 16:40:00',
        'updated_at' => '2026-09-14 16:00:00'], '2026-09-13T12:40:00-04:00'],
    ['Scheduled front matter', ['status' => 'scheduled', 'front_matter' => ['scheduled_at' => '2026-11-01 06:30:00']], '2026-11-01T01:30:00-05:00'],
    ['Edited after publication', ['published_at' => '2026-09-13 16:40:00', 'updated_at' => '2026-09-26 09:12:31'], '2026-09-13T12:40:00-04:00'],
];
foreach ($cases as [$label, $fields, $expected]) {
    $post = timestamp_post($fields);
    timestamp_check(bms_stream_public_datetime_iso($post) === $expected, $label . ': unchanged public page');
    $rss = timestamp_feed_date($post);
    timestamp_check(strtotime($rss) === strtotime($expected), $label . ': RSS instant');
    timestamp_check($rss === date(DATE_RSS, strtotime($expected)), $label . ': RSS format');
    if (isset($fields['published_at']) && !isset($fields['scheduled_at'])) {
        timestamp_check(bms_sitemap_lastmod_from_content(['published_at' => $fields['published_at']]) === $expected, $label . ': sitemap instant');
    }
}

timestamp_configure('2026-08-20 08:40:36');
foreach ([['2026-08-20 08:40:35', '2026-08-20T08:40:35-04:00'],
          ['2026-08-20 08:40:36', '2026-08-20T04:40:36-04:00'],
          ['2026-08-20 08:40:37', '2026-08-20T04:40:37-04:00'],
          ['2026-01-15T07:30:00-05:00', '2026-01-15T07:30:00-05:00']] as [$raw, $expected]) {
    $post = timestamp_post(['published_at' => $raw, 'stream_created_at' => $raw]);
    timestamp_check(bms_stream_public_datetime_iso($post) === $expected, 'Cutover public page: ' . $raw);
    timestamp_check(strtotime(timestamp_feed_date($post)) === strtotime($expected), 'Cutover RSS: ' . $raw);
    timestamp_check(bms_sitemap_lastmod_from_content($post) === $expected, 'Cutover sitemap entry: ' . $raw);
    timestamp_check(bms_sitemap_latest_lastmod([$post]) === $expected, 'Cutover sitemap aggregate and mirrored field: ' . $raw);
}
timestamp_configure();

// Exercise database hydration, which mirrors canonical publication into stream_created_at.
foreach (['2026-09-26 09:12:31', '2026-09-13 16:40:00'] as $raw) {
    $post = bms_database_row_to_content_page(['id' => 1, 'slug' => 'hydrated', 'status' => 'published',
        'post_type' => 'stream', 'created_at' => $raw, 'updated_at' => $raw, 'published_at' => $raw]);
    $expected = date('c', strtotime($raw . ' UTC'));
    timestamp_check(strtotime(timestamp_feed_date($post)) === strtotime($expected), 'Hydrated RSS: ' . $raw);
    timestamp_check(bms_sitemap_lastmod_from_content($post) === $expected, 'Hydrated sitemap: ' . $raw);
    timestamp_check(bms_sitemap_latest_lastmod([$post]) === $expected, 'Hydrated aggregate: ' . $raw);
}
$lastmods = [
    ['Updated overrides publication and creation', ['updated_at' => '2026-09-26 09:12:31', 'published_at' => '2026-09-13 16:40:00', 'created_at' => '2026-09-12 12:00:00'], '2026-09-26T05:12:31-04:00'],
    ['Updated wins even over later fallback', ['updated_at' => '2026-09-13 16:40:00', 'date' => '2026-09-26'], '2026-09-13T12:40:00-04:00'],
    ['Scheduled edit retains updated precedence', $cases[14][1], '2026-09-14T12:00:00-04:00'],
    ['Publication precedes local fallback', ['published_at' => '2026-09-13 16:40:00', 'stream_created_at' => '2026-09-12 08:00:00'], '2026-09-13T12:40:00-04:00'],
    ['UTC creation fallback', ['created_at' => '2026-01-15 12:30:00'], '2026-01-15T07:30:00-05:00'],
    ['Local stream fallback', ['stream_created_at' => '2026-09-26 05:12:31'], '2026-09-26T05:12:31-04:00'],
    ['Mirrored schedule fallback', ['status' => 'published', 'scheduled_at' => '2026-09-13 16:40:00', 'stream_created_at' => '2026-09-13 16:40:00'], '2026-09-13T12:40:00-04:00'],
    ['Date-only publication fallback', ['date_published' => '2026-09-26'], '2026-09-26T00:00:00-04:00'],
    ['Date-only fallback', ['date' => '2026-09-26'], '2026-09-26T00:00:00-04:00'],
    ['Front matter UTC updated fallback', ['front_matter' => ['updated_at' => '2026-09-26 09:12:31']], '2026-09-26T05:12:31-04:00'],
    ['Explicit updated offset', ['updated_at' => '2026-09-26T05:12:31-04:00'], '2026-09-26T05:12:31-04:00'],
    ['Page edit', ['content_type' => 'page', 'updated_at' => '2026-09-26 09:12:31', 'published_at' => '2026-09-13 16:40:00'], '2026-09-26T05:12:31-04:00'],
];
foreach ($lastmods as [$label, $fields, $expected]) {
    timestamp_check(bms_sitemap_lastmod_from_content(timestamp_post($fields)) === $expected, $label);
}
timestamp_check(bms_sitemap_profile_lastmod(['updated_at' => '2026-09-26 09:12:31']) === '2026-09-26T05:12:31-04:00', 'Profile updated UTC');
timestamp_check(bms_sitemap_profile_lastmod(['created_at' => '2026-01-15 12:30:00']) === '2026-01-15T07:30:00-05:00', 'Profile created UTC');
timestamp_check(bms_sitemap_latest_lastmod([
    ['updated_at' => '2026-11-01 06:15:00'], ['date' => '2026-11-01T01:45:00-04:00'],
]) === '2026-11-01T01:15:00-05:00', 'Aggregate compares absolute instants across fall back');
timestamp_check(bms_sitemap_latest_lastmod([
    ['updated_at' => '2026-09-13 16:40:00', 'date' => '2026-09-26'],
]) === '2026-09-26T00:00:00-04:00', 'Aggregate retains maximum-across-fields behavior');
timestamp_check(bms_sitemap_latest_lastmod([
    ['updated_at' => 'invalid', 'published_at' => '2026-09-26 09:12:31'], 'not an item',
]) === '2026-09-26T05:12:31-04:00', 'Aggregate retains malformed-field skip');

foreach ([[], ['published_at' => 'not a date'], ['stream_created_at' => 'not a date']] as $fields) {
    $before = time();
    timestamp_near_now(timestamp_feed_date(timestamp_post($fields)), $before, 'RSS missing/malformed fallback');
}
foreach ([[], ['updated_at' => 'not a date', 'published_at' => '2026-09-13 16:40:00']] as $fields) {
    $before = time();
    timestamp_near_now(bms_sitemap_lastmod_from_content($fields), $before, 'Sitemap first nonempty invalid/missing fallback');
}
$before = time();
timestamp_near_now(bms_sitemap_latest_lastmod([[], ['updated_at' => 'invalid']]), $before, 'Empty/invalid aggregate fallback');
try {
    timestamp_check(bms_rss_date(0) === 'Wed, 31 Dec 1969 19:00:00 -0500', 'Integer epoch zero is valid');
} catch (TypeError $e) {
    timestamp_check(false, 'Integer epoch zero is valid');
}
timestamp_check(strtotime(bms_rss_date('2026-09-26T09:12:31Z')) === 1790413951, 'Legacy string formatter remains supported');

// Compare the complete XML envelopes and child order, not just extracted times.
$older = timestamp_post(['slug' => 'older', 'title' => 'Older & first', 'published_at' => '2026-09-13 16:40:00', 'updated_at' => '2026-09-13 16:40:00']);
$newer = timestamp_post(['slug' => 'newer', 'title' => 'Newer', 'published_at' => '2026-09-26 09:12:31', 'updated_at' => '2026-09-26 09:12:31']);
foreach (['root', 'stream'] as $feedType) {
    $doc = timestamp_xml(bms_render_rss_feed([$older, $newer], $feedType));
    timestamp_check($doc->documentElement->tagName === 'rss' && $doc->documentElement->getAttribute('version') === '2.0', 'RSS root/version');
    timestamp_check($doc->documentElement->lookupNamespaceURI('content') === 'http://purl.org/rss/1.0/modules/content/', 'RSS content namespace');
    timestamp_check($doc->documentElement->lookupNamespaceURI('atom') === 'http://www.w3.org/2005/Atom', 'RSS atom namespace');
    $self = $doc->getElementsByTagNameNS('http://www.w3.org/2005/Atom', 'link')->item(0);
    timestamp_check($self->getAttribute('href') === 'https://timestamps.invalid/' . ($feedType === 'root' ? '' : 'stream/') . 'feed.xml', 'Feed self URL');
    $items = $doc->getElementsByTagName('item');
    timestamp_check($items->length === 2, 'RSS item count');
    foreach (['newer', 'older'] as $i => $slug) {
        $item = $items->item($i);
        $tags = [];
        foreach ($item->childNodes as $child) { if ($child instanceof DOMElement) { $tags[] = $child->tagName; } }
        timestamp_check($tags === ['title', 'link', 'guid', 'pubDate', 'description', 'content:encoded'], 'RSS item field order');
        $url = 'https://timestamps.invalid/stream/' . $slug . '/';
        timestamp_check($item->getElementsByTagName('link')->item(0)->textContent === $url, 'RSS ordering and URL');
        $guid = $item->getElementsByTagName('guid')->item(0);
        timestamp_check($guid->textContent === $url && $guid->getAttribute('isPermaLink') === 'true', 'RSS GUID metadata');
        timestamp_check($item->getElementsByTagNameNS('http://purl.org/rss/1.0/modules/content/', 'encoded')->item(0)->textContent === '<p>A timestamp fixture.</p>', 'RSS body preserved');
    }
}
$page = timestamp_post(['slug' => 'page', 'content_type' => 'page', 'updated_at' => '2026-09-27 10:00:00']);
$xml = bms_render_xml_sitemap([$older, $newer], [$page]);
$doc = timestamp_xml($xml);
timestamp_check($doc->documentElement->namespaceURI === 'http://www.sitemaps.org/schemas/sitemap/0.9', 'Sitemap namespace');
timestamp_check(str_contains($xml, '<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>'), 'Sitemap stylesheet');
$urls = $doc->getElementsByTagName('url');
timestamp_check($urls->length === 5, 'Sitemap entry count');
foreach (['', 'stream/', 'stream/newer/', 'stream/older/', 'pages/page/'] as $i => $path) {
    $entry = $urls->item($i);
    timestamp_check($entry->getElementsByTagName('loc')->item(0)->textContent === 'https://timestamps.invalid/' . $path, 'Sitemap URL/order');
    $tags = [];
    foreach ($entry->childNodes as $child) { if ($child instanceof DOMElement) { $tags[] = $child->tagName; } }
    timestamp_check($tags === ['loc', 'lastmod', 'changefreq', 'priority'], 'Sitemap fields/order');
    timestamp_check($entry->getElementsByTagName('changefreq')->item(0)->textContent === ['daily', 'daily', 'weekly', 'weekly', 'monthly'][$i], 'Sitemap frequency');
    timestamp_check($entry->getElementsByTagName('priority')->item(0)->textContent === ['1.0', '0.9', '0.8', '0.8', '0.7'][$i], 'Sitemap priority');
}
timestamp_check($urls->item(0)->getElementsByTagName('lastmod')->item(0)->textContent === '2026-09-27T06:00:00-04:00', 'Sitemap aggregate XML');
timestamp_check($urls->item(2)->getElementsByTagName('lastmod')->item(0)->textContent === '2026-09-26T05:12:31-04:00', 'Sitemap entry XML');
timestamp_configure('1970-01-01 00:00:00', 'UTC');
timestamp_check(timestamp_feed_date($newer) === 'Sat, 26 Sep 2026 09:12:31 +0000', 'UTC site RSS');
timestamp_check(bms_sitemap_lastmod_from_content($newer) === '2026-09-26T09:12:31+00:00', 'UTC site sitemap');

foreach ($failures as $failure) { fwrite(STDERR, 'FAIL ' . $failure . "\n"); }
echo ($failures ? 'FAIL' : 'PASS') . ' timestamp regression: ' . $checks . ' checks, ' . count($failures) . " failures\n";
exit($failures ? 1 : 0);
