<?php
require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/profiles.php';

function mp_sitemap_enabled(): bool
{
    return (string)mp_setting_or_config('sitemap_enabled', '1') === '1';
}

function mp_sitemap_include_stream_posts(): bool
{
    return (string)mp_setting_or_config('sitemap_include_stream_posts', '1') === '1';
}

function mp_sitemap_include_pages(): bool
{
    return (string)mp_setting_or_config('sitemap_include_pages', '1') === '1';
}

function mp_sitemap_include_profiles(): bool
{
    return (string)mp_setting_or_config('sitemap_include_profiles', '0') === '1';
}


function mp_sitemap_absolute_url(string $pathOrUrl): string
{
    $pathOrUrl = trim($pathOrUrl);
    if ($pathOrUrl === '') {
        return mp_site_url('');
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $pathOrUrl) === 1) {
        return $pathOrUrl;
    }
    if (str_starts_with($pathOrUrl, '/')) {
        $base = rtrim((string)mp_setting_or_config('base_url', ''), '/');
        return $base !== '' ? $base . $pathOrUrl : $pathOrUrl;
    }
    return mp_site_url($pathOrUrl);
}

function mp_sitemap_datetime(string $raw): string
{
    $raw = trim($raw);
    $time = $raw !== '' ? strtotime($raw) : false;
    if ($time === false) {
        $time = time();
    }
    return date('c', $time);
}

function mp_sitemap_lastmod_from_content(array $item): string
{
    foreach (['updated_at', 'published_at', 'stream_created_at', 'date_published', 'date', 'created_at'] as $key) {
        $value = trim((string)($item[$key] ?? ($item['front_matter'][$key] ?? '')));
        if ($value !== '') {
            return mp_sitemap_datetime($value);
        }
    }
    return mp_sitemap_datetime('');
}

function mp_sitemap_content_is_indexable(array $item, string $type): bool
{
    if ($type === 'page' && function_exists('mp_page_robots_directive')) {
        return !str_contains(strtolower(mp_page_robots_directive($item)), 'noindex');
    }
    if ($type === 'stream' && function_exists('mp_stream_robots_directive')) {
        return !str_contains(strtolower(mp_stream_robots_directive($item)), 'noindex');
    }
    $robots = strtolower(trim((string)($item['robots'] ?? ($item['front_matter']['robots'] ?? ''))));
    return $robots === '' || !str_contains($robots, 'noindex');
}

function mp_sitemap_add_url(array &$urls, string $loc, string $lastmod = '', string $changefreq = '', string $priority = ''): void
{
    $loc = trim($loc);
    if ($loc === '') {
        return;
    }
    $key = strtolower($loc);
    if (isset($urls[$key])) {
        return;
    }
    $urls[$key] = [
        'loc' => $loc,
        'lastmod' => $lastmod !== '' ? mp_sitemap_datetime($lastmod) : '',
        'changefreq' => $changefreq,
        'priority' => $priority,
    ];
}

function mp_sitemap_latest_lastmod(array $items): string
{
    $latest = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        foreach (['updated_at', 'published_at', 'stream_created_at', 'date_published', 'date', 'created_at'] as $key) {
            $raw = trim((string)($item[$key] ?? ($item['front_matter'][$key] ?? '')));
            if ($raw === '') {
                continue;
            }
            $time = strtotime($raw);
            if ($time !== false && $time > $latest) {
                $latest = $time;
            }
        }
    }
    return $latest > 0 ? date('c', $latest) : date('c');
}

function mp_sitemap_public_users(): array
{
    if (!mp_is_installed()) {
        return [];
    }
    try {
        $stmt = mp_db()->prepare('SELECT id, username, display_name, email, role, status, bio, website, social_links, profile_visibility, avatar_path, created_at, updated_at FROM ' . mp_table('users') . ' WHERE status = :status AND profile_visibility <> :visibility ORDER BY updated_at DESC, id ASC LIMIT 500');
        $stmt->execute(['status' => 'active', 'visibility' => 'private']);
        $users = [];
        foreach ($stmt->fetchAll() ?: [] as $user) {
            if (is_array($user) && mp_profile_user_is_viewable($user)) {
                $users[] = $user;
            }
        }
        return $users;
    } catch (Throwable $e) {
        return [];
    }
}

function mp_sitemap_url_entries(?array $streamPosts = null, ?array $pages = null): array
{
    $streamPosts = $streamPosts ?? mp_list_content_records('published');
    $pages = $pages ?? mp_list_page_records('published');
    $urls = [];
    $combined = array_merge($streamPosts, $pages);
    $latest = mp_sitemap_latest_lastmod($combined);

    mp_sitemap_add_url($urls, mp_site_url(''), $latest, 'daily', '1.0');
    mp_sitemap_add_url($urls, mp_site_url('stream/'), $latest, 'daily', '0.9');

    if (mp_sitemap_include_stream_posts()) {
        foreach (mp_sort_stream_posts(mp_filter_stream_posts($streamPosts)) as $post) {
            if (!mp_sitemap_content_is_indexable($post, 'stream')) {
                continue;
            }
            $slug = mp_slugify((string)($post['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            mp_sitemap_add_url($urls, mp_site_url(mp_stream_relative_directory_for_post($post) . '/'), mp_sitemap_lastmod_from_content($post), 'weekly', '0.8');
        }
    }

    if (mp_sitemap_include_pages()) {
        foreach ($pages as $page) {
            if (!mp_sitemap_content_is_indexable($page, 'page')) {
                continue;
            }
            $slug = mp_slugify((string)($page['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            mp_sitemap_add_url($urls, mp_site_url(mp_page_relative_directory_for_page($page) . '/'), mp_sitemap_lastmod_from_content($page), 'monthly', '0.7');
        }
    }

    if (mp_sitemap_include_profiles()) {
        foreach (mp_sitemap_public_users() as $user) {
            $profileUrl = mp_public_profile_url_for_user($user);
            mp_sitemap_add_url($urls, mp_sitemap_absolute_url($profileUrl), mp_sitemap_datetime((string)($user['updated_at'] ?? $user['created_at'] ?? '')), 'monthly', '0.4');
        }
    }

    return array_values($urls);
}

function mp_render_sitemap_xsl(): string
{
    $stylesheetHref = mp_xml_escape(mp_asset_url('assets/sitemap.css'));
    return <<<XSL
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9">
  <xsl:output method="html" encoding="UTF-8" indent="yes"/>
  <xsl:template match="/">
    <html lang="en">
      <head>
        <meta charset="UTF-8"/>
        <meta name="viewport" content="width=device-width, initial-scale=1"/>
        <title>Bonumark Stream Sitemap</title>
        <link rel="stylesheet" href="{$stylesheetHref}"/>
      </head>
      <body>
        <main class="bonumark-sitemap-shell">
          <header class="bonumark-sitemap-hero">
            <p class="bonumark-sitemap-kicker">Bonumark Stream</p>
            <h1>XML Sitemap</h1>
            <p class="bonumark-sitemap-lede">A live index of public URLs available for search engines and human review.</p>
            <div class="bonumark-sitemap-stats" aria-label="Sitemap summary">
              <span><strong><xsl:value-of select="count(sitemap:urlset/sitemap:url)"/></strong> indexed URLs</span>
              <span>Generated from published public content</span>
            </div>
          </header>
          <section class="bonumark-sitemap-card" aria-label="Sitemap URL list">
            <div class="bonumark-sitemap-table-wrap">
              <table class="bonumark-sitemap-table">
                <thead>
                  <tr>
                    <th scope="col">Type</th>
                    <th scope="col">URL</th>
                    <th scope="col">Last Modified</th>
                  </tr>
                </thead>
                <tbody>
                  <xsl:for-each select="sitemap:urlset/sitemap:url">
                    <tr>
                      <td data-label="Type">
                        <span class="bonumark-sitemap-type">
                          <xsl:choose>
                            <xsl:when test="substring(sitemap:loc, string-length(sitemap:loc) - 7) = '/stream/'">Stream</xsl:when>
                            <xsl:when test="contains(sitemap:loc, '/stream/')">Post</xsl:when>
                            <xsl:when test="contains(sitemap:loc, '/pages/')">Page</xsl:when>
                            <xsl:when test="contains(sitemap:loc, '/profile.php')">Profile</xsl:when>
                            <xsl:otherwise>Home</xsl:otherwise>
                          </xsl:choose>
                        </span>
                      </td>
                      <td data-label="URL">
                        <a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc"/></a>
                      </td>
                      <td data-label="Last Modified">
                        <span class="bonumark-sitemap-date"><xsl:value-of select="sitemap:lastmod"/></span>
                      </td>
                    </tr>
                  </xsl:for-each>
                </tbody>
              </table>
            </div>
          </section>
          <footer class="bonumark-sitemap-footer">
            Sitemap styling is for browser review only. Crawlers read the XML source.
          </footer>
        </main>
      </body>
    </html>
  </xsl:template>
</xsl:stylesheet>
XSL;
}

function mp_render_xml_sitemap(?array $streamPosts = null, ?array $pages = null): string
{
    $entries = mp_sitemap_url_entries($streamPosts, $pages);
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<?xml-stylesheet type="text/xsl" href="' . mp_xml_escape(mp_url_path('sitemap.xsl')) . '"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($entries as $entry) {
        $xml .= '  <url>' . "\n"
            . '    <loc>' . mp_xml_escape((string)$entry['loc']) . '</loc>' . "\n";
        if ((string)$entry['lastmod'] !== '') {
            $xml .= '    <lastmod>' . mp_xml_escape((string)$entry['lastmod']) . '</lastmod>' . "\n";
        }
        if ((string)$entry['changefreq'] !== '') {
            $xml .= '    <changefreq>' . mp_xml_escape((string)$entry['changefreq']) . '</changefreq>' . "\n";
        }
        if ((string)$entry['priority'] !== '') {
            $xml .= '    <priority>' . mp_xml_escape((string)$entry['priority']) . '</priority>' . "\n";
        }
        $xml .= '  </url>' . "\n";
    }
    return $xml . '</urlset>' . "\n";
}

function mp_render_robots_txt(): string
{
    $lines = [
        'User-agent: *',
        'Allow: /',
    ];
    if (mp_sitemap_enabled()) {
        $lines[] = '';
        $lines[] = 'Sitemap: ' . mp_site_url('sitemap.xml');
    }
    return implode("\n", $lines) . "\n";
}

function mp_generate_static_sitemap(?array $streamPosts = null, ?array $pages = null, ?string $targetRoot = null): void
{
    if (!mp_sitemap_enabled()) {
        return;
    }
    $streamPosts = $streamPosts ?? mp_list_content_records('published');
    $pages = $pages ?? mp_list_page_records('published');
    mp_write_file(mp_static_site_export_path('sitemap.xml', $targetRoot), mp_render_xml_sitemap($streamPosts, $pages));
    mp_write_file(mp_static_site_export_path('sitemap.xsl', $targetRoot), mp_render_sitemap_xsl());
    mp_write_file(mp_static_site_export_path('robots.txt', $targetRoot), mp_render_robots_txt());
}
