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
            $result->addWarning('Detected Bonumark Stream export type ' . $exportType . ' from version ' . $exportVersion . '. Database SQL is not executed during safe restore imports. Posts, Pages, and media library files are restored through the importer. Comments, accounts, settings, and analytics are not restored.');

            $contentEntries = $this->contentEntries($zip);
            if (!$contentEntries) {
                $result->addWarning('The export did not contain Bonumark Markdown content entries. Media-only, static-only, and database-only exports cannot restore content through the Import screen.');
                return $result;
            }

            $contentManifest = json_decode($this->readZipEntry($zip, 'markdown/manifest.json', self::MAX_MARKDOWN_BYTES), true);
            $formatVersion = is_array($contentManifest) ? (int)($contentManifest['format_version'] ?? 1) : 1;
            $mediaIndex = $this->buildMediaIndex($zip);
            $mediaManifest = $this->readMediaManifest($zip);
            $stagingToken = bms_import_staging_token();
            $stagedMap = [];
            $stagedBytes = 0;
            $contentCount = 0;
            $referenced = [];

            foreach ($contentEntries as $entry) {
                $raw = $this->readZipEntry($zip, $entry, self::MAX_MARKDOWN_BYTES);
                if (trim($raw) === '') {
                    continue;
                }

                $parsed = bms_parse_markdown_string($raw);
                $frontMatter = is_array($parsed['front_matter'] ?? null) ? $parsed['front_matter'] : [];
                $entryInfo = $this->contentEntryInfo($entry);
                $section = $entryInfo['status'];
                $contentType = $entryInfo['content_type'];
                $body = trim((string)($parsed['body'] ?? ''));

                // Media keeps its exported paths. The library restore puts the
                // files back at those paths during confirmation.
                $featuredMedia = trim((string)($parsed['featured_media'] ?? ''));
                $mediaGallery = bms_normalize_media_gallery($parsed['media_gallery'] ?? [], $featuredMedia);
                $featuredMedia = $mediaGallery[0] ?? '';
                foreach (bms_portability_referenced_media($body, $mediaGallery) as $mediaPath => $altText) {
                    if (!isset($referenced[$mediaPath]) || ($referenced[$mediaPath] === '' && $altText !== '')) {
                        $referenced[$mediaPath] = $altText;
                    }
                }

                $title = trim((string)($parsed['title'] ?? ''));
                if ($title === '') {
                    $title = $contentType === 'page' ? 'Restored Bonumark Page' : 'Restored Bonumark Post';
                }
                $slug = trim((string)($parsed['slug'] ?? pathinfo($entry, PATHINFO_FILENAME)));
                $date = bms_import_normalize_date((string)($parsed['date'] ?? ''));
                // An empty creation time stays empty, as it was stored.
                $createdAt = trim((string)($parsed['stream_created_at'] ?? ''));
                $description = trim((string)($parsed['description'] ?? ''));
                $tags = isset($parsed['tags']) && is_array($parsed['tags']) ? $parsed['tags'] : [];

                $meta = [
                    'exact' => '1',
                    'seo_title' => (string)($parsed['seo_title'] ?? ''),
                    'robots' => (string)($parsed['robots'] ?? ''),
                    'scheduled_at' => bms_portability_utc_from_value((string)($frontMatter['scheduled_at'] ?? '')),
                    'published_at' => bms_portability_utc_from_value((string)($frontMatter['published_at'] ?? '')),
                    'is_pinned' => trim((string)($frontMatter['pinned'] ?? '')) === '1' ? '1' : '0',
                    'pinned_at' => bms_portability_utc_from_value((string)($frontMatter['pinned_at'] ?? '')),
                ];
                foreach (array_merge(bms_portability_link_preview_keys(), bms_portability_location_keys()) as $key) {
                    $meta[$key] = (string)($parsed[$key] ?? '');
                }
                if ($formatVersion < 2 && $section === 'published' && $meta['published_at'] === '') {
                    // Earlier exports had no publication time. Their
                    // stream_created_at holds the stored published time.
                    $meta['published_at'] = bms_portability_utc_from_value((string)($parsed['stream_created_at'] ?? ''));
                    $meta['published_at_source'] = 'stream_created_at';
                }

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
                    'media_gallery' => $mediaGallery,
                    'content_type' => $contentType,
                    'tags' => $contentType === 'page' ? [] : $tags,
                    'meta' => $meta,
                ]));
                $contentCount++;
            }

            if ($contentCount === 0) {
                bms_import_cleanup_staging_token($stagingToken);
                $result->addWarning('No readable Markdown content entries were found in this Bonumark export.');
                return $result;
            }

            $library = $this->prepareMediaLibrary($zip, $mediaIndex, $mediaManifest, $referenced, $stagingToken, $stagedMap, $stagedBytes, $result);
            if ($library !== []) {
                $result->mediaLibrary = ['token' => $stagingToken, 'items' => $library];
            } else {
                bms_import_cleanup_staging_token($stagingToken);
            }
            if ($formatVersion < 2) {
                $result->addWarning('This export was made by an earlier version. Publication times come from each post\'s saved creation time, and pin state and media library details are not included.');
            }
        } finally {
            $zip->close();
        }

        return $result;
    }

    /**
     * Read the media library list an export includes, keyed by media path.
     *
     * @return array<string,array<string,mixed>>
     */
    private function readMediaManifest(ZipArchive $zip): array
    {
        $decoded = json_decode($this->readZipEntry($zip, bms_portability_media_manifest_entry(), self::MAX_MARKDOWN_BYTES * 2), true);
        if (!is_array($decoded) || (string)($decoded['format'] ?? '') !== 'bonumark-media-library' || !is_array($decoded['items'] ?? null)) {
            return [];
        }
        $items = [];
        foreach ($decoded['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $path = bms_portability_clean_media_path((string)($item['public_path'] ?? ''));
            if ($path === '' || bms_portability_media_category($path) !== 'library' || isset($items[$path])) {
                continue;
            }
            $variants = [];
            foreach (is_array($item['variants'] ?? null) ? $item['variants'] : [] as $variant) {
                $variantPath = is_array($variant) ? bms_portability_clean_media_path((string)($variant['path'] ?? '')) : '';
                if ($variantPath !== '' && bms_portability_media_category($variantPath) === 'derivative') {
                    $variants[] = ['label' => substr(preg_replace('/[^a-z0-9_-]/i', '', (string)($variant['label'] ?? '')) ?? '', 0, 40), 'path' => $variantPath];
                }
            }
            $text = static fn(string $key, int $limit): string => is_scalar($item[$key] ?? null) ? substr((string)$item[$key], 0, $limit) : '';
            $items[$path] = [
                'public_path' => $path,
                'original_filename' => $text('original_filename', 1024),
                'alt_text' => $text('alt_text', 2048),
                'caption' => $text('caption', 65535),
                'file_hash' => strtolower(preg_replace('/[^a-f0-9]/i', '', $text('file_hash', 64)) ?? ''),
                'privacy_status' => preg_replace('/[^a-z_]/', '', $text('privacy_status', 30)) ?? '',
                'privacy_note' => $text('privacy_note', 1024),
                'created_at' => $text('created_at', 40),
                'updated_at' => $text('updated_at', 40),
                'trashed_at' => $text('trashed_at', 40),
                'variants' => array_slice($variants, 0, 8),
            ];
        }
        return $items;
    }

    /**
     * Stage every media library file the export includes, whether or not a
     * post uses it. Files listed in the media manifest keep their recorded
     * details. Other library files keep their file name. Profile and account
     * media is counted and left out; content import does not restore it.
     *
     * @param array<string,string> $mediaIndex
     * @param array<string,array<string,mixed>> $mediaManifest
     * @param array<string,string> $referenced
     * @param array<string,string> $stagedMap
     * @return list<array<string,mixed>>
     */
    private function prepareMediaLibrary(ZipArchive $zip, array $mediaIndex, array $mediaManifest, array $referenced, string $token, array &$stagedMap, int &$stagedBytes, BMS_ImportResult $result): array
    {
        $library = [];
        $warnings = [];
        $listed = [];
        $missing = 0;
        $profile = 0;
        $unsupported = 0;
        $limit = bms_current_media_upload_limit_bytes();

        foreach ($mediaManifest as $path => $item) {
            $listed[$path] = true;
            $entry = $mediaIndex[substr($path, 6)] ?? '';
            $staged = $entry !== '' ? $this->stageMediaEntry($zip, $entry, $token, $stagedMap, $stagedBytes, $warnings, $limit) : '';
            if ($entry !== '' && $staged === '') {
                continue;
            }
            if ($entry === '') {
                $missing++;
            }
            $variants = [];
            foreach ($item['variants'] as $variant) {
                $listed[$variant['path']] = true;
                $variantEntry = $mediaIndex[substr($variant['path'], 6)] ?? '';
                $variantStaged = $variantEntry !== '' && $staged !== '' ? $this->stageMediaEntry($zip, $variantEntry, $token, $stagedMap, $stagedBytes, $warnings, $limit) : '';
                if ($variantStaged !== '') {
                    $variants[] = ['label' => $variant['label'], 'path' => $variant['path'], 'staged' => $variantStaged];
                }
            }
            $library[] = array_merge($item, [
                'staged' => $staged,
                'variants' => $variants,
                'referenced' => isset($referenced[$path]),
                'source' => 'manifest',
            ]);
        }

        foreach ($mediaIndex as $relative => $entry) {
            $path = bms_portability_clean_media_path('media/' . $relative);
            if ($path === '') {
                if (!str_starts_with(basename($relative), '.')) {
                    $unsupported++;
                }
                continue;
            }
            if (isset($listed[$path])) {
                continue;
            }
            $category = bms_portability_media_category($path);
            if ($category === 'profile') {
                $profile++;
                continue;
            }
            if ($category === 'derivative') {
                // Derivatives without a recorded original can be regenerated.
                continue;
            }
            if ($category !== 'library') {
                $unsupported++;
                continue;
            }
            $staged = $this->stageMediaEntry($zip, $entry, $token, $stagedMap, $stagedBytes, $warnings, $limit);
            if ($staged === '') {
                continue;
            }
            $library[] = [
                'public_path' => $path,
                'original_filename' => basename($path),
                'alt_text' => (string)($referenced[$path] ?? ''),
                'caption' => '',
                'file_hash' => '',
                'privacy_status' => '',
                'privacy_note' => '',
                'created_at' => '',
                'updated_at' => '',
                'trashed_at' => '',
                'staged' => $staged,
                'variants' => [],
                'referenced' => isset($referenced[$path]),
                'source' => 'archive',
            ];
        }

        foreach ($warnings as $warning) {
            $result->addWarning($warning);
        }
        $staged = count(array_filter($library, static fn(array $item): bool => (string)$item['staged'] !== ''));
        if ($staged > 0) {
            $unused = count(array_filter($library, static fn(array $item): bool => (string)$item['staged'] !== '' && empty($item['referenced'])));
            $result->addWarning('Prepared ' . $staged . ' media library file(s) for restore during confirmation' . ($unused > 0 ? ', including ' . $unused . ' that no post uses' : '') . '.');
        }
        if ($missing > 0) {
            $result->addWarning($missing . ' media file(s) listed in this export are not included in it. A Markdown export carries media details only; use a Full export to move media files.');
        }
        if ($profile > 0) {
            $result->addWarning($profile . ' Profile or account media file(s) in the export are not restored. Content import does not restore Profiles or accounts.');
        }
        if ($unsupported > 0) {
            $result->addWarning($unsupported . ' file(s) in the export media folder are not supported media and are not restored.');
        }
        return $library;
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
            'content/scheduled/' => ['content_type' => 'stream', 'status' => 'scheduled'],
            'markdown/posts/published/' => ['content_type' => 'stream', 'status' => 'published'],
            'markdown/posts/drafts/' => ['content_type' => 'stream', 'status' => 'draft'],
            'markdown/posts/scheduled/' => ['content_type' => 'stream', 'status' => 'scheduled'],
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

    /**
     * Copy one media file from the export into private import staging and
     * return its staged name, or '' with a warning when it is skipped.
     *
     * @param array<string,string> $stagedMap
     * @param list<string> $warnings
     */
    private function stageMediaEntry(ZipArchive $zip, string $entry, string $token, array &$stagedMap, int &$stagedBytes, array &$warnings, int $maxBytes): string
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
        if ($size > $maxBytes) {
            $warnings[] = 'Skipped media file larger than this site\'s upload limit: ' . basename($entry) . '.';
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

        $data = $this->readZipEntry($zip, $entry, $maxBytes);
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
        if (file_put_contents($destination, $data) === false) {
            $warnings[] = 'Could not stage media file from export: ' . basename($entry) . '.';
            return '';
        }
        @chmod($destination, 0600);
        $stagedBytes += strlen($data);
        $stagedMap[$entry] = $safeName;
        return $safeName;
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
