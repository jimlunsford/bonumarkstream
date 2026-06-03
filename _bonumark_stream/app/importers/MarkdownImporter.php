<?php

class BMS_MarkdownImporter implements BMS_ImporterInterface
{
    public function label(): string
    {
        return 'Markdown';
    }

    public function canImport(array $file): bool
    {
        $name = strtolower((string)($file['name'] ?? ''));
        return preg_match('/\.(md|markdown|txt)$/', $name) === 1;
    }

    public function importPreview(array $file): BMS_ImportResult
    {
        $result = new BMS_ImportResult($this->label());
        $path = (string)($file['tmp_name'] ?? '');
        $name = (string)($file['name'] ?? 'import.md');
        if ($path === '' || !bms_import_uploaded_file_is_readable($path)) {
            $result->addError('Uploaded Markdown file could not be read.');
            return $result;
        }

        $raw = (string)file_get_contents($path);
        if (trim($raw) === '') {
            $result->addError('The uploaded Markdown file is empty.');
            return $result;
        }

        $parsed = bms_parse_markdown_string($raw);
        $body = trim((string)($parsed['body'] ?? ''));
        if ($body === '') {
            $body = trim($raw);
        }

        $title = trim((string)($parsed['title'] ?? ''));
        if ($title === '' || strtolower($title) === 'untitled') {
            $title = preg_replace('/\.[^.]+$/', '', basename($name)) ?? basename($name);
            $title = trim(str_replace(['-', '_'], ' ', $title));
        }

        $date = bms_import_normalize_date((string)($parsed['date'] ?? ''));
        $createdAt = bms_import_normalize_datetime((string)($parsed['stream_created_at'] ?? $parsed['date'] ?? ''));
        $status = bms_import_normalize_status((string)($parsed['status'] ?? 'draft'));
        $description = trim((string)($parsed['description'] ?? ''));
        $slug = trim((string)($parsed['slug'] ?? ''));
        $featuredMedia = trim((string)($parsed['featured_media'] ?? ''));
        $tags = isset($parsed['tags']) && is_array($parsed['tags']) ? $parsed['tags'] : [];

        $result->addItem(bms_import_make_item([
            'title' => $title,
            'slug' => $slug,
            'body' => $body,
            'date' => $date,
            'created_at' => $createdAt,
            'description' => $description,
            'status' => $status,
            'source' => $name,
            'featured_media' => $featuredMedia,
            'tags' => $tags,
        ]));

        return $result;
    }
}
