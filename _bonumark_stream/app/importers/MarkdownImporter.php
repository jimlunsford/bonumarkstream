<?php

class MP_MarkdownImporter implements MP_ImporterInterface
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

    public function importPreview(array $file): MP_ImportResult
    {
        $result = new MP_ImportResult($this->label());
        $path = (string)($file['tmp_name'] ?? '');
        $name = (string)($file['name'] ?? 'import.md');
        if ($path === '' || !mp_import_uploaded_file_is_readable($path)) {
            $result->addError('Uploaded Markdown file could not be read.');
            return $result;
        }

        $raw = (string)file_get_contents($path);
        if (trim($raw) === '') {
            $result->addError('The uploaded Markdown file is empty.');
            return $result;
        }

        $parsed = mp_parse_markdown_string($raw);
        $body = trim((string)($parsed['body'] ?? ''));
        if ($body === '') {
            $body = trim($raw);
        }

        $title = trim((string)($parsed['title'] ?? ''));
        if ($title === '' || strtolower($title) === 'untitled') {
            $title = preg_replace('/\.[^.]+$/', '', basename($name)) ?? basename($name);
            $title = trim(str_replace(['-', '_'], ' ', $title));
        }

        $date = mp_import_normalize_date((string)($parsed['date'] ?? ''));
        $createdAt = mp_import_normalize_datetime((string)($parsed['stream_created_at'] ?? $parsed['date'] ?? ''));
        $status = mp_import_normalize_status((string)($parsed['status'] ?? 'draft'));
        $description = trim((string)($parsed['description'] ?? ''));
        $slug = trim((string)($parsed['slug'] ?? ''));
        $featuredMedia = trim((string)($parsed['featured_media'] ?? ''));
        $tags = isset($parsed['tags']) && is_array($parsed['tags']) ? $parsed['tags'] : [];

        $result->addItem(mp_import_make_item([
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
