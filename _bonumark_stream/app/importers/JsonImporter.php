<?php

class MP_JsonImporter implements MP_ImporterInterface
{
    private const MAX_IMPORT_ITEMS = 5000;

    public function label(): string
    {
        return 'Generic JSON';
    }

    public function canImport(array $file): bool
    {
        $name = strtolower((string)($file['name'] ?? ''));
        return str_ends_with($name, '.json');
    }

    public function importPreview(array $file): MP_ImportResult
    {
        $result = new MP_ImportResult($this->label());
        $path = (string)($file['tmp_name'] ?? '');
        $name = (string)($file['name'] ?? 'import.json');
        if ($path === '' || !mp_import_uploaded_file_is_readable($path)) {
            $result->addError('Uploaded JSON file could not be read.');
            return $result;
        }

        $raw = (string)file_get_contents($path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $result->addError('The uploaded JSON file is not valid JSON.');
            return $result;
        }

        $records = $this->extractRecords($decoded);
        if (!$records) {
            $result->addError('No importable post records were found in the JSON file.');
            return $result;
        }

        $skippedEmpty = 0;
        $skippedInvalid = 0;
        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                $skippedInvalid++;
                continue;
            }
            if (count($result->items) >= self::MAX_IMPORT_ITEMS) {
                break;
            }
            $item = $this->recordToItem($record, $name, $index + 1);
            if (trim($item->body) === '' && trim($item->featuredMedia) === '') {
                $skippedEmpty++;
                continue;
            }
            $result->addItem($item);
        }

        if (count($result->items) >= self::MAX_IMPORT_ITEMS) {
            $result->addWarning('Prepared the first ' . self::MAX_IMPORT_ITEMS . ' JSON record(s). The file is larger than Bonumark Stream\'s safety limit for one import preview, so use the original JSON file again after importing this range if you need the rest.');
        } else {
            $result->addWarning('Prepared ' . count($result->items) . ' JSON record(s) for import. The preview screen may show only a sample, but confirmation can import the full prepared set.');
        }
        if ($skippedInvalid > 0) {
            $result->addWarning('Skipped ' . $skippedInvalid . ' JSON record(s) because they were not objects.');
        }
        if ($skippedEmpty > 0) {
            $result->addWarning('Skipped ' . $skippedEmpty . ' JSON record(s) because they had no body or media reference.');
        }
        if (!$result->hasItems()) {
            $result->addError('No importable JSON records were found after filtering empty or invalid records.');
        }

        return $result;
    }

    /** @param array<mixed> $data @return list<mixed> */
    private function extractRecords(array $data): array
    {
        $candidateKeys = ['posts', 'items', 'entries', 'data', 'records'];
        foreach ($candidateKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values($data[$key]);
            }
        }

        if (array_is_list($data)) {
            return array_values($data);
        }

        if (isset($data['content']) || isset($data['body']) || isset($data['text']) || isset($data['markdown'])) {
            return [$data];
        }

        return [];
    }

    /** @param array<string,mixed> $record */
    private function recordToItem(array $record, string $sourceName, int $index): MP_ImportItem
    {
        $body = $this->firstString($record, ['body', 'content', 'markdown', 'text', 'post_content', 'description_html']);
        $body = html_entity_decode(trim(strip_tags($body)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = $this->firstString($record, ['title', 'name', 'post_title', 'subject']);
        $slug = $this->firstString($record, ['slug', 'post_name', 'uri', 'id']);
        $dateRaw = $this->firstString($record, ['date', 'date_published', 'published_at', 'created_at', 'post_date', 'timestamp']);
        $description = $this->firstString($record, ['description', 'summary', 'excerpt', 'seo_description']);
        $status = $this->firstString($record, ['status', 'post_status']);
        $featuredMedia = $this->firstString($record, ['featured_media', 'image', 'media', 'attachment']);
        $tags = $this->termsFromRecord($record);

        if (trim($title) === '') {
            $title = 'Imported post ' . $index;
        }

        return mp_import_make_item([
            'title' => $title,
            'slug' => $slug,
            'body' => $body,
            'date' => mp_import_normalize_date($dateRaw),
            'created_at' => mp_import_normalize_datetime($dateRaw),
            'description' => $description,
            'status' => mp_import_normalize_status($status),
            'source' => $sourceName . ' #' . $index,
            'featured_media' => $featuredMedia,
            'tags' => $tags,
        ]);
    }

    /** @param array<string,mixed> $record @param list<string> $keys */
    private function firstString(array $record, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $record)) {
                continue;
            }
            $value = $record[$key];
            if (is_scalar($value)) {
                return trim((string)$value);
            }
            if (is_array($value) && isset($value['url']) && is_scalar($value['url'])) {
                return trim((string)$value['url']);
            }
        }
        return '';
    }

    /** @param array<string,mixed> $record @return list<string> */
    private function termsFromRecord(array $record): array
    {
        foreach (['tags', 'tag_names', 'keywords'] as $key) {
            if (!isset($record[$key])) {
                continue;
            }
            $value = $record[$key];
            if (is_string($value)) {
                return mp_normalize_terms($value);
            }
            if (is_array($value)) {
                return mp_normalize_terms($value);
            }
        }
        return [];
    }
}
