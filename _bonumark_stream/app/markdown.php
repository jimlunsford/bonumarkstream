<?php
function mp_markdown_normalize_public_spacing(string $text): string
{
    return str_replace(["\xC2\xA0", '&nbsp;', '&#160;', '&#xA0;', '&#xa0;'], ' ', $text);
}

function mp_markdown_to_html(string $markdown, bool $stripFirstH1 = true): string
{
    $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
    $markdown = mp_markdown_normalize_public_spacing($markdown);
    $markdown = mp_markdown_normalize_imported_html_images($markdown);

    if ($stripFirstH1) {
        $markdown = preg_replace('/\A\s*#\s+.+\n?/', '', $markdown, 1) ?? $markdown;
    }

    $lines = explode("\n", $markdown);
    $html = [];
    $i = 0;
    $count = count($lines);

    while ($i < $count) {
        $line = rtrim($lines[$i]);

        if (trim($line) === '') {
            $i++;
            continue;
        }

        if (preg_match('/^```([A-Za-z0-9_-]+)?\s*$/', trim($line), $m)) {
            $language = $m[1] ?? '';
            $code = [];
            $i++;
            while ($i < $count && !preg_match('/^```\s*$/', trim($lines[$i]))) {
                $code[] = $lines[$i];
                $i++;
            }
            $i++;
            $class = $language ? ' class="language-' . htmlspecialchars($language, ENT_QUOTES, 'UTF-8') . '"' : '';
            $html[] = '<pre><code' . $class . '>' . htmlspecialchars(implode("\n", $code), ENT_NOQUOTES, 'UTF-8') . '</code></pre>';
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
            $level = strlen($m[1]);
            $text = trim($m[2]);
            $id = mp_heading_id($text);
            $html[] = '<h' . $level . ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">' . mp_inline_markdown($text) . '</h' . $level . '>';
            $i++;
            continue;
        }

        if (preg_match('/^\s*[-*_]{3,}\s*$/', $line)) {
            $html[] = '<hr>';
            $i++;
            continue;
        }

        if (mp_is_table_start($lines, $i)) {
            [$tableHtml, $next] = mp_parse_table($lines, $i);
            $html[] = $tableHtml;
            $i = $next;
            continue;
        }

        if (preg_match('/^>\s?(.*)$/', $line)) {
            $quote = [];
            while ($i < $count && preg_match('/^>\s?(.*)$/', rtrim($lines[$i]), $m)) {
                $quote[] = $m[1];
                $i++;
            }
            $html[] = '<blockquote>' . mp_markdown_to_html(implode("\n", $quote), false) . '</blockquote>';
            continue;
        }

        if (preg_match('/^\s*[-*+]\s+(.+)$/', $line)) {
            $items = [];
            while ($i < $count && preg_match('/^\s*[-*+]\s+(.+)$/', rtrim($lines[$i]), $m)) {
                $items[] = '<li>' . mp_inline_markdown(trim($m[1])) . '</li>';
                $i++;
            }
            $html[] = '<ul>' . implode('', $items) . '</ul>';
            continue;
        }

        if (preg_match('/^\s*\d+\.\s+(.+)$/', $line)) {
            $items = [];
            while ($i < $count && preg_match('/^\s*\d+\.\s+(.+)$/', rtrim($lines[$i]), $m)) {
                $items[] = '<li>' . mp_inline_markdown(trim($m[1])) . '</li>';
                $i++;
            }
            $html[] = '<ol>' . implode('', $items) . '</ol>';
            continue;
        }

        $paragraph = [$line];
        $i++;
        while ($i < $count) {
            $next = rtrim($lines[$i]);
            if (trim($next) === '') {
                $i++;
                break;
            }
            if (preg_match('/^(#{1,6})\s+/', $next) || preg_match('/^```/', trim($next)) || preg_match('/^>\s?/', $next) || preg_match('/^\s*[-*+]\s+/', $next) || preg_match('/^\s*\d+\.\s+/', $next) || mp_is_table_start($lines, $i)) {
                break;
            }
            $paragraph[] = $next;
            $i++;
        }
        $html[] = '<p>' . mp_inline_markdown(implode(' ', array_map('trim', $paragraph))) . '</p>';
    }

    return implode("\n", $html);
}


function mp_media_type_from_url(string $url): string
{
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? $url));
    if (preg_match('/\.(mp3|m4a|wav|ogg)$/', $path)) {
        return 'audio';
    }
    if (preg_match('/\.(mp4|webm|mov)$/', $path)) {
        return 'video';
    }
    return 'file';
}

function mp_media_label_text(string $label, string $fallback = 'Media'): string
{
    $label = trim(preg_replace('/[\r\n\[\]]+/', ' ', $label) ?? $label);
    return $label !== '' ? $label : $fallback;
}


function mp_autolink_plain_urls(string $html): string
{
    $parts = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) {
        return $html;
    }

    $insideAnchor = false;
    foreach ($parts as $index => $part) {
        if ($part === '') {
            continue;
        }

        if (str_starts_with($part, '<')) {
            if (preg_match('/^<a\s/i', $part) === 1) {
                $insideAnchor = true;
            } elseif (preg_match('/^<\/a>/i', $part) === 1) {
                $insideAnchor = false;
            }
            continue;
        }

        if ($insideAnchor) {
            continue;
        }

        $parts[$index] = preg_replace_callback('~\bhttps?://[^\s<]+~iu', static function (array $m): string {
            $urlText = (string)$m[0];
            $trailing = '';

            while ($urlText !== '' && preg_match('/[\.,;:!?\)\]]$/u', $urlText) === 1) {
                $last = substr($urlText, -1);
                if (($last === ')' && substr_count($urlText, '(') >= substr_count($urlText, ')')) || ($last === ']' && substr_count($urlText, '[') >= substr_count($urlText, ']'))) {
                    break;
                }
                $trailing = $last . $trailing;
                $urlText = substr($urlText, 0, strlen($urlText) - 1);
            }

            $href = mp_clean_url(htmlspecialchars_decode($urlText, ENT_QUOTES));
            if ($href === '#') {
                return $urlText . $trailing;
            }

            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $urlText . '</a>' . $trailing;
        }, $part) ?? $part;
    }

    return implode('', $parts);
}


function mp_markdown_image_source_from_attributes(string $attributes): string
{
    $candidates = ['src', 'data-src', 'data-lazy-src', 'data-original', 'data-full-url', 'data-large-file'];
    foreach ($candidates as $name) {
        $value = mp_markdown_attribute_value($attributes, $name);
        if ($value !== '') {
            return $value;
        }
    }

    $srcset = mp_markdown_attribute_value($attributes, 'srcset');
    if ($srcset !== '') {
        $parts = array_filter(array_map('trim', explode(',', $srcset)));
        foreach ($parts as $part) {
            $candidate = trim((string)preg_replace('/\s+\d+[wx]$/i', '', $part));
            if ($candidate !== '') {
                return $candidate;
            }
        }
    }

    return '';
}

function mp_markdown_normalize_imported_html_images(string $markdown): string
{
    $markdown = preg_replace('#<!--\s*/?wp:image[^>]*-->#i', '', $markdown) ?? $markdown;
    $markdown = preg_replace('#<noscript\b[^>]*>.*?</noscript>#is', '', $markdown) ?? $markdown;

    $markdown = preg_replace_callback('#<picture\b[^>]*>(.*?)</picture>#is', static function (array $matches): string {
        $inner = (string)($matches[1] ?? '');
        if (preg_match('#<img\b[^>]*>#is', $inner, $img) === 1) {
            return "

" . (string)$img[0] . "

";
        }
        return '';
    }, $markdown) ?? $markdown;

    $markdown = preg_replace_callback('#<figure\b[^>]*>(.*?)</figure>#is', static function (array $matches): string {
        $inner = (string)($matches[1] ?? '');
        if (preg_match_all('#<img\b[^>]*>#is', $inner, $imgs) > 0) {
            return "

" . implode("

", $imgs[0]) . "

";
        }
        return $inner;
    }, $markdown) ?? $markdown;

    return $markdown;
}

function mp_html_image_with_priority(string $imageHtml, string $loading = 'eager', string $fetchPriority = 'high'): string
{
    if (preg_match('#^<img\b#i', trim($imageHtml)) !== 1) {
        return $imageHtml;
    }

    $loading = in_array($loading, ['lazy', 'eager'], true) ? $loading : 'eager';
    $fetchPriority = in_array($fetchPriority, ['high', 'low', 'auto'], true) ? $fetchPriority : 'high';

    $imageHtml = preg_replace('/\sloading\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $imageHtml) ?? $imageHtml;
    $imageHtml = preg_replace('/\sfetchpriority\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $imageHtml) ?? $imageHtml;
    $imageHtml = preg_replace('/\sdecoding\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $imageHtml) ?? $imageHtml;

    return preg_replace('/\s*\/?>$/', ' loading="' . $loading . '" fetchpriority="' . $fetchPriority . '" decoding="async">', trim($imageHtml), 1) ?? $imageHtml;
}

function mp_markdown_prioritize_first_image(string $html): string
{
    return preg_replace_callback('#<img\b[^>]*>#i', static function (array $matches): string {
        return mp_html_image_with_priority((string)$matches[0], 'eager', 'high');
    }, $html, 1) ?? $html;
}

function mp_markdown_image_html(string $src, string $alt = '', string $title = ''): string
{
    $rawSrc = html_entity_decode(trim($src), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $cleanSrc = mp_clean_url($rawSrc);

    if (function_exists('mp_media_resolve_existing_public_relative_from_url') && function_exists('mp_url_path')) {
        $resolved = mp_media_resolve_existing_public_relative_from_url($rawSrc);
        if ($resolved !== '') {
            $cleanSrc = mp_url_path($resolved);
        }
    }

    if ($cleanSrc === '#' && function_exists('mp_media_public_relative_from_url')) {
        $relative = mp_media_public_relative_from_url($rawSrc);
        if ($relative !== '' && function_exists('mp_url_path')) {
            $cleanSrc = mp_url_path($relative);
        }
    }

    $altEscaped = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
    $titleAttr = $title !== '' ? ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"' : '';

    if ($cleanSrc === '#') {
        return '';
    }

    if (function_exists('mp_media_image_attributes')) {
        $attributes = mp_media_image_attributes($cleanSrc, html_entity_decode($altEscaped, ENT_QUOTES, 'UTF-8'), [
            'loading' => 'lazy',
            'decoding' => 'async',
            'sizes' => '(max-width: 720px) calc(100vw - 2rem), min(100vw - 4rem, 900px)',
        ]);
        return '<img ' . $attributes . $titleAttr . '>';
    }

    return '<img src="' . htmlspecialchars($cleanSrc, ENT_QUOTES, 'UTF-8') . '" alt="' . $altEscaped . '" loading="lazy" decoding="async"' . $titleAttr . '>';
}

function mp_markdown_attribute_value(string $attributes, string $name): string
{
    if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attributes, $match) !== 1) {
        return '';
    }
    return html_entity_decode((string)($match[2] ?? $match[3] ?? $match[4] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function mp_markdown_normalize_raw_images(string $text, array &$placeholders): string
{
    return preg_replace_callback('#<img\b([^>]*)>#i', static function (array $matches) use (&$placeholders): string {
        $attributes = (string)($matches[1] ?? '');
        $src = mp_markdown_image_source_from_attributes($attributes);
        if ($src === '') {
            return '';
        }
        $alt = mp_markdown_attribute_value($attributes, 'alt');
        $title = mp_markdown_attribute_value($attributes, 'title');
        $key = '%%RAWIMAGE' . count($placeholders) . '%%';
        $placeholders[$key] = mp_markdown_image_html($src, $alt !== '' ? $alt : 'Imported image', $title);
        return $key;
    }, $text) ?? $text;
}

function mp_inline_markdown(string $text): string
{
    $placeholders = [];
    $text = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$placeholders) {
        $key = '%%CODE' . count($placeholders) . '%%';
        $placeholders[$key] = '<code>' . htmlspecialchars($m[1], ENT_NOQUOTES, 'UTF-8') . '</code>';
        return $key;
    }, $text) ?? $text;

    $text = mp_markdown_normalize_raw_images($text, $placeholders);

    $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

    $text = preg_replace_callback('/!\[([^\]]*)\]\((<[^>]+>|[^\r\n)]+?)(?:\s+"([^"]*)")?\)/', function ($m) {
        $src = trim((string)$m[2]);
        if (str_starts_with($src, '<') && str_ends_with($src, '>')) {
            $src = substr($src, 1, -1);
        }
        return mp_markdown_image_html($src, (string)$m[1], isset($m[3]) ? (string)$m[3] : '');
    }, $text) ?? $text;

    $text = preg_replace_callback('/\[([^\]]+)\]\(([^\s)]+)(?:\s+"([^"]*)")?\)/', function ($m) {
        $label = mp_media_label_text((string)$m[1]);
        $href = mp_clean_url(htmlspecialchars_decode($m[2], ENT_QUOTES));
        $type = mp_media_type_from_url($href);
        if ($type === 'audio') {
            return '<audio controls preload="metadata" src="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"></audio>';
        }
        if ($type === 'video') {
            return '<video controls preload="metadata" src="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"></video>';
        }
        $title = isset($m[3]) ? ' title="' . htmlspecialchars($m[3], ENT_QUOTES, 'UTF-8') . '"' : '';
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $title . '>' . $label . '</a>';
    }, $text) ?? $text;

    $text = mp_autolink_plain_urls($text);

    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text) ?? $text;
    $text = preg_replace('/(?<!_)_([^_]+)_(?!_)/', '<em>$1</em>', $text) ?? $text;

    foreach ($placeholders as $key => $value) {
        $text = str_replace($key, $value, $text);
    }

    return $text;
}

function mp_clean_url(string $url): string
{
    $url = trim(str_replace('\\', '/', $url));
    if ($url === '' || str_contains($url, "\0") || preg_match('/[\r\n]/', $url)) {
        return '#';
    }

    if (str_starts_with($url, '#')) {
        return preg_match('/^#[A-Za-z0-9_\-:.]+$/', $url) === 1 ? $url : '#';
    }

    if (str_starts_with($url, '//')) {
        return '#';
    }

    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (is_string($scheme) && $scheme !== '') {
        return in_array(strtolower($scheme), ['http', 'https', 'mailto'], true) ? $url : '#';
    }

    $decoded = rawurldecode($url);
    $path = parse_url($decoded, PHP_URL_PATH);
    $path = is_string($path) ? $path : $decoded;
    if (str_starts_with($path, './') || str_starts_with($path, '../') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
        return '#';
    }

    if (str_starts_with($url, '/') || preg_match('/^[A-Za-z0-9][A-Za-z0-9._~\/\?#=&%+\-]*$/', $url) === 1) {
        return $url;
    }

    return '#';
}

function mp_heading_id(string $text): string
{
    $text = strip_tags($text);
    $text = preg_replace('/[^A-Za-z0-9]+/', '-', $text) ?? $text;
    return strtolower(trim($text, '-')) ?: 'section';
}

function mp_is_table_start(array $lines, int $i): bool
{
    if (!isset($lines[$i + 1])) {
        return false;
    }
    return str_contains($lines[$i], '|') && preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/', $lines[$i + 1]);
}

function mp_parse_table(array $lines, int $i): array
{
    $headers = mp_split_table_row($lines[$i]);
    $i += 2;
    $rows = [];
    $count = count($lines);
    while ($i < $count && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
        $rows[] = mp_split_table_row($lines[$i]);
        $i++;
    }

    $html = '<div class="markdown-table-wrap"><table><thead><tr>';
    foreach ($headers as $header) {
        $html .= '<th>' . mp_inline_markdown(trim($header)) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($headers as $index => $_) {
            $cell = $row[$index] ?? '';
            $html .= '<td>' . mp_inline_markdown(trim($cell)) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';

    return [$html, $i];
}

function mp_split_table_row(string $line): array
{
    $line = trim($line);
    $line = trim($line, '|');
    return array_map('trim', explode('|', $line));
}
