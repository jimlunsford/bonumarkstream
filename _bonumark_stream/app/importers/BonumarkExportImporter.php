<?php

class BMS_BonumarkExportImporter implements BMS_ImporterInterface
{
    private const MAX_MARKDOWN_BYTES = 4194304;
    private const MAX_STAGED_MEDIA_BYTES = 134217728;

    public function label(): string
    {
        return 'Bonumark Stream Export';
    }

    public function canImport(array $file): bool
    {
        $name = strtolower((string)($file['name'] ?? ''));
        if (!str_ends_with($name, '.zip')) {
            return false;
        }
        if (!class_exists('ZipArchive')) {
            return false;
        }
        $path = (string)($file['tmp_name'] ?? '');
        if ($path === '' || !bms_import_uploaded_file_is_readable($path)) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        $export = $this->readZipEntry($zip, 'EXPORT.txt', 32768);
        $zip->close();
        return stripos($export, 'Bonumark Stream export') !== false;
    }

    public function importPreview(array $file): BMS_ImportResult
    {
        $result = new BMS_ImportResult($this->label());
        if (!class_exists('ZipArchive')) {
            $result->addError('Bonumark export restore requires PHP ZipArchive. Ask the host to enable it.');
            return $result;
        }

        $path = (string)($file['tmp_name'] ?? '');
        if ($path === '' || !bms_import_uploaded_file_is_readable($path)) {
            $result->addError('Uploaded Bonumark export ZIP could not be read.');
            return $result;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            $result->addError('Could not open the Bonumark export ZIP.');
            return $result;
        }

        try {
            $exportText = $this->readZipEntry($zip, 'EXPORT.txt', 32768);
            if (stripos($exportText, 'Bonumark Stream export') === false) {
                $result->addError('This ZIP does not appear to be a Bonumark Stream export.');
                return $result;
            }

            $exportInfo = $this->parseExportInfo($exportText);
            $exportType = (string)($exportInfo['type'] ?? 'unknown');
            $exportVersion = (string)($exportInfo['version'] ?? 'unknown');
            $result->addWarning('Detected Bonumark Stream export type ' . $exportType . ' from version ' . $exportVersion . '. Database SQL is not executed during safe restore imports. Posts, pages, and referenced media are restored through the importer.');

            $mediaIndex = $this->buildMediaIndex($zip);
            $contentEntries = $this->contentEntries($zip);
            if (!$contentEntries) {
                $result->addWarning('The export did not contain Bonumark Markdown content entries. Media-only, static-only, and database-only exports cannot restore content through the Import screen.');
                return $result;
            }

            $stagingToken = bms_import_staging_token();
            $stagedMap = [];
            $stagedBytes = 0;
            $contentCount = 0;

            foreach ($contentEntries as $entry) {
                $raw = $this->readZipEntry($zip, $entry, self::MAX_MARKDOWN_BYTES);
                if (trim($raw) === '') {
                    continue;
                }

                $parsed = bms_parse_markdown_string($raw);
                $entryInfo = $this->contentEntryInfo($entry);
                $section = $entryInfo['status'];
                $contentType = $entryInfo['content_type'];
                $body = trim((string)($parsed['body'] ?? ''));
                $itemWarnings = [];
                $body = $this->rewriteLocalMediaReferences($body, $zip, $mediaIndex, $stagingToken, $stagedMap, $stagedBytes, $itemWarnings);

                $featuredMedia = trim((string)($parsed['featured_media'] ?? ''));
                if ($featuredMedia !== '') {
                    $featuredMedia = $this->stageFeaturedMedia($featuredMedia, $zip, $mediaIndex, $stagingToken, $stagedMap, $stagedBytes, $itemWarnings);
                }

                $title = trim((string)($parsed['title'] ?? ''));
                if ($title === '') {
                    $title = $contentType === 'page' ? 'Restored Bonumark Page' : 'Restored Bonumark Post';
                }
                $slug = trim((string)($parsed['slug'] ?? pathinfo($entry, PATHINFO_FILENAME)));
                $date = bms_import_normalize_date((string)($parsed['date'] ?? ''));
                $createdAt = bms_import_normalize_datetime((string)($parsed['stream_created_at'] ?? $parsed['date'] ?? ''));
                $description = trim((string)($parsed['description'] ?? ''));
                $tags = isset($parsed['tags']) && is_array($parsed['tags']) ? $parsed['tags'] : [];

                $result->addItem(bms_import_make_item([
                    'title' => $title,
                    'slug' => $slug,
                    'body' => $body,
                    'date' => $date,
                    'created_at' => $createdAt,
                    'description' => $description,
                    'status' => $section,
                    'source' => 'Bonumark export: ' . $entry,
                    'featured_media' => $featuredMedia,
                    'content_type' => $contentType,
                    'tags' => $contentType === 'page' ? [] : $tags,
                    'warnings' => $itemWarnings,
                ]));
                $contentCount++;
            }

            if ($contentCount === 0) {
                bms_import_cleanup_staging_token($stagingToken);
                $result->addWarning('No readable Markdown content entries were found in this Bonumark export.');
            }

            if ($mediaIndex && $stagedMap) {
                $result->addWarning('Staged ' . count($stagedMap) . ' referenced media file(s) from the Bonumark export for restore during confirmation.');
            } elseif ($mediaIndex) {
                $result->addWarning('The export contains media files, but no referenced media paths were found in the restored post bodies. Unused media files are not restored by this safe content importer.');
            }
        } finally {
            $zip->close();
        }

        return $result;
    }

    /** @return array<string,string> */
    private function parseExportInfo(string $text): array
    {
        $info = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^([A-Za-z ]+):\s*(.+)$/', trim($line), $match) === 1) {
                $key = strtolower(str_replace(' ', '_', trim((string)$match[1])));
                $info[$key] = trim((string)$match[2]);
            }
        }
        return $info;
    }

    /** @return list<string> */
    private function contentEntries(ZipArchive $zip): array
    {
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $this->safeZipName((string)$zip->getNameIndex($i));
            if ($name === '' || !str_ends_with(strtolower($name), '.md')) {
                continue;
            }
            if ($this->contentEntryInfo($name)['content_type'] !== '') {
                $entries[] = $name;
            }
        }
        sort($entries, SORT_NATURAL | SORT_FLAG_CASE);
        return $entries;
    }

    /** @return array{content_type:string,status:string} */
    private function contentEntryInfo(string $name): array
    {
        $name = $this->safeZipName($name);
        $map = [
            'content/published/' => ['content_type' => 'stream', 'status' => 'published'],
            'content/drafts/' => ['content_type' => 'stream', 'status' => 'draft'],
            'markdown/posts/published/' => ['content_type' => 'stream', 'status' => 'published'],
            'markdown/posts/drafts/' => ['content_type' => 'stream', 'status' => 'draft'],
            'markdown/pages/published/' => ['content_type' => 'page', 'status' => 'published'],
            'markdown/pages/drafts/' => ['content_type' => 'page', 'status' => 'draft'],
        ];
        foreach ($map as $prefix => $info) {
            if (str_starts_with($name, $prefix)) {
                return $info;
            }
        }
        return ['content_type' => '', 'status' => 'draft'];
    }

    /** @return array<string,string> */
    private function buildMediaIndex(ZipArchive $zip): array
    {
        $media = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $this->safeZipName((string)$zip->getNameIndex($i));
            if ($name === '' || !str_starts_with($name, 'media/') || str_ends_with($name, '/')) {
                continue;
            }
            $relative = substr($name, 6);
            $normalized = $this->normalizeMediaRelative($relative);
            if ($normalized !== '') {
                $media[$normalized] = $name;
            }
        }
        return $media;
    }

    /** @param array<string,string> $mediaIndex @param array<string,string> $stagedMap @param list<string> $warnings */
    private function rewriteLocalMediaReferences(string $body, ZipArchive $zip, array $mediaIndex, string $token, array &$stagedMap, int &$stagedBytes, array &$warnings): string
    {
        if ($body === '' || !$mediaIndex) {
            return $body;
        }

        return preg_replace_callback('/(!?)\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', function (array $matches) use ($zip, $mediaIndex, $token, &$stagedMap, &$stagedBytes, &$warnings): string {
            $prefix = (string)($matches[1] ?? '');
            $label = (string)($matches[2] ?? '');
            $url = trim((string)($matches[3] ?? ''));
            $relative = $this->mediaRelativeFromUrl($url);
            if ($relative === '' || !isset($mediaIndex[$relative])) {
                return (string)$matches[0];
            }

            $stagedUrl = $this->stageMediaEntry($zip, $mediaIndex[$relative], $token, $stagedMap, $stagedBytes, $warnings);
            if ($stagedUrl === '') {
                return (string)$matches[0];
            }

            $safeLabel = str_replace(["\r", "\n", '[', ']'], [' ', ' ', '', ''], $label);
            return $prefix . '[' . $safeLabel . '](' . $stagedUrl . ')';
        }, $body) ?? $body;
    }

    /** @param array<string,string> $mediaIndex @param array<string,string> $stagedMap @param list<string> $warnings */
    private function stageFeaturedMedia(string $featuredMedia, ZipArchive $zip, array $mediaIndex, string $token, array &$stagedMap, int &$stagedBytes, array &$warnings): string
    {
        $relative = $this->mediaRelativeFromUrl($featuredMedia);
        if ($relative === '' || !isset($mediaIndex[$relative])) {
            return $featuredMedia;
        }
        $stagedUrl = $this->stageMediaEntry($zip, $mediaIndex[$relative], $token, $stagedMap, $stagedBytes, $warnings);
        return $stagedUrl !== '' ? $stagedUrl : $featuredMedia;
    }

    private function mediaRelativeFromUrl(string $url): string
    {
        $url = trim($url, " \t\n\r\0\x0B\"'");
        if ($url === '' || str_starts_with($url, 'bms-import-media://')) {
            return '';
        }
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: $url);
        $path = rawurldecode(str_replace('\\', '/', $path));
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'media/')) {
            return $this->normalizeMediaRelative(substr($path, 6));
        }
        $position = strpos($path, '/media/');
        if ($position !== false) {
            return $this->normalizeMediaRelative(substr($path, $position + 7));
        }
        return '';
    }

    private function normalizeMediaRelative(string $path): string
    {
        $path = rawurldecode(str_replace('\\', '/', trim($path)));
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            return '';
        }
        return $path;
    }

    /** @param array<string,string> $stagedMap @param list<string> $warnings */
    private function stageMediaEntry(ZipArchive $zip, string $entry, string $token, array &$stagedMap, int &$stagedBytes, array &$warnings): string
    {
        if (isset($stagedMap[$entry])) {
            return $stagedMap[$entry];
        }

        $stat = $zip->statName($entry);
        $size = is_array($stat) ? (int)($stat['size'] ?? 0) : 0;
        if ($size <= 0) {
            $warnings[] = 'Skipped empty media file in export: ' . basename($entry) . '.';
            return '';
        }
        if ($size > bms_import_remote_image_max_bytes()) {
            $warnings[] = 'Skipped oversized media file in export: ' . basename($entry) . '.';
            return '';
        }
        if ($stagedBytes + $size > self::MAX_STAGED_MEDIA_BYTES) {
            $warnings[] = 'Skipped media file because restore staging limit would be exceeded: ' . basename($entry) . '.';
            return '';
        }

        $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        $allowed = bms_allowed_media_extensions();
        if (!isset($allowed[$extension])) {
            $warnings[] = 'Skipped unsupported media file in export: ' . basename($entry) . '.';
            return '';
        }

        $data = $this->readZipEntry($zip, $entry, bms_import_remote_image_max_bytes());
        if ($data === '') {
            $warnings[] = 'Could not read media file from export: ' . basename($entry) . '.';
            return '';
        }

        $safeName = substr(sha1($entry), 0, 12) . '-' . preg_replace('/[^a-z0-9._-]/i', '-', basename($entry));
        $destination = bms_import_staging_path($token, $safeName);
        $dir = dirname($destination);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $warnings[] = 'Could not create import staging folder for Bonumark media.';
            return '';
        }
        file_put_contents($destination, $data);
        @chmod($destination, 0600);
        $stagedBytes += strlen($data);
        $stagedMap[$entry] = bms_import_staged_media_url($token, $safeName);
        return $stagedMap[$entry];
    }

    private function readZipEntry(ZipArchive $zip, string $entry, int $maxBytes): string
    {
        $entry = $this->safeZipName($entry);
        if ($entry === '') {
            return '';
        }
        $stream = $zip->getStream($entry);
        if (!$stream) {
            return '';
        }
        $data = '';
        while (!feof($stream)) {
            $chunk = (string)fread($stream, 8192);
            $data .= $chunk;
            if (strlen($data) > $maxBytes) {
                fclose($stream);
                return '';
            }
        }
        fclose($stream);
        return $data;
    }

    private function safeZipName(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name));
        $name = ltrim($name, '/');
        if ($name === '' || str_contains($name, "\0") || preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
            return '';
        }
        return preg_replace('#/+#', '/', $name) ?? $name;
    }
}
