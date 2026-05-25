<?php

class MP_WordPressWxrImporter implements MP_ImporterInterface
{
    private const MAX_IMPORT_ITEMS = 5000;

    public function label(): string
    {
        return 'WordPress WXR';
    }

    public function canImport(array $file): bool
    {
        $name = strtolower((string)($file['name'] ?? ''));
        if (!str_ends_with($name, '.xml')) {
            return false;
        }

        $path = (string)($file['tmp_name'] ?? '');
        if ($path === '' || !mp_import_uploaded_file_is_readable($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $sample = (string)fread($handle, 65536);
        fclose($handle);

        return str_contains($sample, '<wp:wxr_version')
            || str_contains($sample, 'xmlns:wp="http://wordpress.org/export/')
            || str_contains($sample, '<wp:post_type>');
    }

    public function importPreview(array $file): MP_ImportResult
    {
        $result = new MP_ImportResult($this->label());
        $path = (string)($file['tmp_name'] ?? '');
        $name = (string)($file['name'] ?? 'wordpress-export.xml');
        if ($path === '' || !mp_import_uploaded_file_is_readable($path)) {
            $result->addError('Uploaded WordPress export file could not be read.');
            return $result;
        }

        $raw = (string)file_get_contents($path);
        if (trim($raw) === '') {
            $result->addError('The uploaded WordPress export file is empty.');
            return $result;
        }

        if (!$this->looksLikeWordPressExport($raw)) {
            $result->addError('The uploaded XML file does not look like a WordPress export. Use the XML file from Tools > Export in WordPress.');
            return $result;
        }

        $items = $this->extractItemBlocks($raw);
        if (!$items) {
            $result->addError('No WordPress export items were found in the XML file.');
            return $result;
        }

        $attachments = $this->attachmentMap($items);
        $skippedNonPosts = 0;
        $skippedStatuses = 0;

        foreach ($items as $index => $xmlItem) {
            $postType = trim($this->childValue($xmlItem, 'wp:post_type'));
            if ($postType !== 'post') {
                $skippedNonPosts++;
                continue;
            }

            $wpStatus = strtolower(trim($this->childValue($xmlItem, 'wp:status')));
            if (in_array($wpStatus, ['trash', 'auto-draft', 'inherit'], true)) {
                $skippedStatuses++;
                continue;
            }

            if (count($result->items) >= self::MAX_IMPORT_ITEMS) {
                break;
            }

            $item = $this->postToItem($xmlItem, $attachments, $name, $index + 1);
            if (trim($item->body) === '') {
                $result->addWarning('Skipped WordPress post ' . ($index + 1) . ' because it had no importable content.');
                continue;
            }

            $result->addItem($item);
        }

        if (count($result->items) >= self::MAX_IMPORT_ITEMS) {
            $result->addWarning('Prepared the first ' . self::MAX_IMPORT_ITEMS . ' WordPress post(s). The export is larger than Bonumark Stream\'s safety limit for one import preview, so use the original export again after importing this range if you need the rest.');
        } else {
            $result->addWarning('Prepared ' . count($result->items) . ' WordPress post(s) for import. The preview screen may show only a sample, but confirmation can import the full prepared set.');
        }
        if ($skippedNonPosts > 0) {
            $result->addWarning('Skipped ' . $skippedNonPosts . ' non-post WordPress item(s), including pages, revisions, menu items, and attachments used only for media URL lookup.');
        }
        if ($skippedStatuses > 0) {
            $result->addWarning('Skipped ' . $skippedStatuses . ' trashed, inherited, or auto-draft WordPress item(s).');
        }
        if (!$result->hasItems()) {
            $result->addError('No importable WordPress posts were found. Bonumark Stream imports WordPress posts, not pages, attachments, revisions, or menus.');
        }

        return $result;
    }

    private function looksLikeWordPressExport(string $xml): bool
    {
        return str_contains($xml, '<wp:wxr_version')
            || str_contains($xml, 'xmlns:wp="http://wordpress.org/export/')
            || str_contains($xml, '<wp:post_type>');
    }

    /** @return list<string> */
    private function extractItemBlocks(string $xml): array
    {
        $matched = preg_match_all('#<item\b[^>]*>(.*?)</item>#is', $xml, $matches);
        if ($matched === false || $matched === 0) {
            return [];
        }
        return array_values(array_map('strval', $matches[1]));
    }

    /** @param list<string> $items @return array<string,string> */
    private function attachmentMap(array $items): array
    {
        $attachments = [];
        foreach ($items as $xmlItem) {
            if (trim($this->childValue($xmlItem, 'wp:post_type')) !== 'attachment') {
                continue;
            }
            $id = trim($this->childValue($xmlItem, 'wp:post_id'));
            $url = trim($this->childValue($xmlItem, 'wp:attachment_url'));
            if ($id !== '' && $url !== '') {
                $attachments[$id] = $url;
            }
        }
        return $attachments;
    }

    /** @param array<string,string> $attachments */
    private function postToItem(string $xmlItem, array $attachments, string $sourceName, int $index): MP_ImportItem
    {
        $postId = trim($this->childValue($xmlItem, 'wp:post_id'));
        $title = trim($this->childValue($xmlItem, 'title'));
        if ($title === '') {
            $title = 'Imported WordPress post ' . $index;
        }

        $slug = trim($this->childValue($xmlItem, 'wp:post_name'));
        $dateRaw = trim($this->childValue($xmlItem, 'wp:post_date'));
        if ($dateRaw === '' || str_starts_with($dateRaw, '0000-00-00')) {
            $dateRaw = trim($this->childValue($xmlItem, 'pubDate'));
        }

        $contentHtml = trim($this->childValue($xmlItem, 'content:encoded'));
        if ($contentHtml === '') {
            $contentHtml = trim($this->childValue($xmlItem, 'description'));
        }

        $body = $this->htmlToMarkdown($contentHtml);
        $featuredImage = $this->featuredImageUrl($xmlItem, $attachments);
        if ($featuredImage !== '' && !str_contains($body, $featuredImage)) {
            $body = trim('![Featured image](' . $featuredImage . ')' . "\n\n" . $body);
        }

        $description = $this->htmlToPlainText(trim($this->childValue($xmlItem, 'excerpt:encoded')));
        if ($description === '') {
            $description = $this->htmlToPlainText(trim($this->childValue($xmlItem, 'description')));
        }

        $terms = $this->termsFromItem($xmlItem);
        $status = $this->normalizeWordPressStatus($this->childValue($xmlItem, 'wp:status'));
        $author = trim($this->childValue($xmlItem, 'dc:creator'));
        $source = $sourceName . ' post';
        if ($postId !== '') {
            $source .= ' #' . $postId;
        } else {
            $source .= ' ' . $index;
        }

        $item = mp_import_make_item([
            'title' => $title,
            'slug' => $slug,
            'body' => $body,
            'date' => mp_import_normalize_date($dateRaw),
            'created_at' => mp_import_normalize_datetime($dateRaw),
            'description' => $description,
            'status' => $status,
            'source' => $source,
            'featured_media' => '',
            'tags' => $terms,
        ]);

        $remoteImages = function_exists('mp_import_extract_remote_image_urls') ? mp_import_extract_remote_image_urls($body) : [];
        if ($remoteImages) {
            $item->warnings[] = count($remoteImages) . ' remote image reference(s) detected. Choose Import images into Media during confirmation to copy supported images into Bonumark Stream.';
        }
        if ($author !== '') {
            $item->warnings[] = 'Original WordPress author: ' . $author . '.';
        }

        return $item;
    }

    private function childValue(string $xml, string $tag): string
    {
        $quoted = preg_quote($tag, '#');
        if (preg_match('#<' . $quoted . '\b[^>]*>(.*?)</' . $quoted . '>#is', $xml, $match) !== 1) {
            return '';
        }
        return $this->decodeXmlValue((string)$match[1]);
    }

    private function decodeXmlValue(string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, '<![CDATA[') && str_ends_with($value, ']]>')) {
            $value = substr($value, 9, -3);
        }
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function normalizeWordPressStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return $status === 'publish' || $status === 'published' ? 'published' : 'draft';
    }

    /** @return list<string> */
    private function termsFromItem(string $xmlItem): array
    {
        $terms = [];
        $matched = preg_match_all('#<category\b[^>]*>(.*?)</category>#is', $xmlItem, $matches);
        if ($matched === false || $matched === 0) {
            return [];
        }
        foreach ($matches[1] as $rawTerm) {
            $term = trim($this->decodeXmlValue((string)$rawTerm));
            if ($term === '') {
                continue;
            }
            $terms[] = $term;
        }
        return mp_normalize_terms($terms);
    }

    /** @param array<string,string> $attachments */
    private function featuredImageUrl(string $xmlItem, array $attachments): string
    {
        $thumbnailId = '';
        $matched = preg_match_all('#<wp:postmeta\b[^>]*>(.*?)</wp:postmeta>#is', $xmlItem, $matches);
        if ($matched !== false && $matched > 0) {
            foreach ($matches[1] as $meta) {
                $key = trim($this->childValue((string)$meta, 'wp:meta_key'));
                if ($key === '_thumbnail_id') {
                    $thumbnailId = trim($this->childValue((string)$meta, 'wp:meta_value'));
                    break;
                }
            }
        }
        return $thumbnailId !== '' ? (string)($attachments[$thumbnailId] ?? '') : '';
    }

    private function htmlToMarkdown(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace_callback('#<img\b([^>]*)>#i', function ($matches): string {
            $src = $this->attributeValue((string)$matches[1], 'src');
            if ($src === '') {
                return '';
            }
            $alt = $this->attributeValue((string)$matches[1], 'alt');
            return "\n\n![" . $this->markdownLinkText($alt !== '' ? $alt : 'Image') . '](' . $src . ")\n\n";
        }, $html) ?? $html;

        $html = preg_replace_callback('#<a\b([^>]*)>(.*?)</a>#is', function ($matches): string {
            $href = $this->attributeValue((string)$matches[1], 'href');
            $label = $this->htmlToPlainText((string)$matches[2]);
            if ($href === '' || $label === '') {
                return $label;
            }
            return '[' . $this->markdownLinkText($label) . '](' . $href . ')';
        }, $html) ?? $html;

        for ($level = 6; $level >= 1; $level--) {
            $html = preg_replace_callback('#<h' . $level . '\b[^>]*>(.*?)</h' . $level . '>#is', function ($matches) use ($level): string {
                $text = $this->htmlToPlainText((string)$matches[1]);
                return $text !== '' ? "\n\n" . str_repeat('#', $level) . ' ' . $text . "\n\n" : '';
            }, $html) ?? $html;
        }

        $html = preg_replace_callback('#<li\b[^>]*>(.*?)</li>#is', function ($matches): string {
            $text = $this->htmlToPlainText((string)$matches[1]);
            return $text !== '' ? "\n- " . $text : '';
        }, $html) ?? $html;

        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|section|article|blockquote|ul|ol)>#i', "\n\n", $html) ?? $html;
        $html = preg_replace('#<(p|div|section|article|blockquote|ul|ol)[^>]*>#i', "\n\n", $html) ?? $html;
        $html = strip_tags($html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = preg_replace('/[ \t]+/', ' ', $html) ?? $html;
        $html = preg_replace('/\n[ \t]+/', "\n", $html) ?? $html;
        $html = preg_replace('/\n{3,}/', "\n\n", $html) ?? $html;

        return trim($html);
    }

    private function htmlToPlainText(string $html): string
    {
        $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }

    private function attributeValue(string $attributes, string $name): string
    {
        if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attributes, $match) !== 1) {
            return '';
        }
        return html_entity_decode((string)($match[2] ?? $match[3] ?? $match[4] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function markdownLinkText(string $text): string
    {
        $text = str_replace(["\r", "\n"], ' ', trim($text));
        $text = str_replace(['[', ']'], ['\\[', '\\]'], $text);
        return $text !== '' ? $text : 'Link';
    }
}
