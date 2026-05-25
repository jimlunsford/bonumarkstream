<?php

class MP_TwitterArchiveImporter implements MP_ImporterInterface
{
    private const MAX_DATA_FILE_BYTES = 32_000_000;
    private const MAX_STAGED_MEDIA_BYTES = 64_000_000;
    private const MAX_IMPORT_ITEMS = 5000;

    public function label(): string
    {
        return 'Twitter/X Archive';
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
        if ($path === '' || !mp_import_uploaded_file_is_readable($path)) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $this->normalizeZipName((string)$zip->getNameIndex($i));
                if ($this->isTweetDataFile($entry) || $entry === 'your_archive.html' || $entry === 'data/account.js') {
                    return true;
                }
            }
        } finally {
            $zip->close();
        }

        return false;
    }

    public function importPreview(array $file): MP_ImportResult
    {
        $result = new MP_ImportResult($this->label());
        $path = (string)($file['tmp_name'] ?? '');
        $sourceName = (string)($file['name'] ?? 'twitter-archive.zip');

        if (!class_exists('ZipArchive')) {
            $result->addError('ZipArchive is required to import Twitter/X archive ZIP files.');
            return $result;
        }
        if ($path === '' || !mp_import_uploaded_file_is_readable($path)) {
            $result->addError('Uploaded Twitter/X archive could not be read.');
            return $result;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            $result->addError('The uploaded file is not a readable ZIP archive.');
            return $result;
        }

        $stagingToken = function_exists('mp_import_staging_token') ? mp_import_staging_token() : bin2hex(random_bytes(12));
        $mediaEntries = [];
        $tweetDataEntries = [];

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $this->normalizeZipName((string)$zip->getNameIndex($i));
                if ($entry === '' || str_ends_with($entry, '/')) {
                    continue;
                }
                if ($this->isUnsafeZipName($entry)) {
                    $result->addWarning('Skipped unsafe archive path: ' . $entry . '.');
                    continue;
                }
                if ($this->isTweetDataFile($entry)) {
                    $tweetDataEntries[] = $entry;
                } elseif ($this->isTweetMediaFile($entry)) {
                    $mediaEntries[] = $entry;
                }
            }

            sort($tweetDataEntries, SORT_NATURAL | SORT_FLAG_CASE);
            sort($mediaEntries, SORT_NATURAL | SORT_FLAG_CASE);

            if (!$tweetDataEntries) {
                $result->addError('No Twitter/X tweet data file was found. Expected files like data/tweets.js or data/tweets-part0.js inside the archive.');
                $this->addDiagnosticWarning($result, $zip);
                return $result;
            }

            $mediaMap = $this->indexMediaEntries($mediaEntries);
            $totalSeen = 0;
            $skippedRetweets = 0;
            $skippedEmpty = 0;
            $stagedBytes = 0;

            foreach ($tweetDataEntries as $entry) {
                if (count($result->items) >= self::MAX_IMPORT_ITEMS) {
                    break;
                }

                $raw = $this->readZipEntry($zip, $entry, self::MAX_DATA_FILE_BYTES);
                if ($raw === '') {
                    $result->addWarning('Skipped empty tweet data file: ' . $entry . '.');
                    continue;
                }

                $decoded = $this->decodeTwitterJsData($raw);
                if (!$decoded) {
                    $result->addWarning('Could not parse tweet data file: ' . $entry . '.');
                    continue;
                }

                foreach ($decoded as $record) {
                    if (count($result->items) >= self::MAX_IMPORT_ITEMS) {
                        break 2;
                    }
                    $tweet = $this->extractTweetRecord($record);
                    if (!$tweet) {
                        continue;
                    }
                    $totalSeen++;

                    if ($this->isRetweet($tweet)) {
                        $skippedRetweets++;
                        continue;
                    }

                    $item = $this->tweetToItem($tweet, $sourceName, $entry, $zip, $mediaMap, $stagingToken, $stagedBytes);
                    if (trim($item->body) === '') {
                        $skippedEmpty++;
                        continue;
                    }
                    $result->addItem($item);
                }
            }

            if (count($result->items) >= self::MAX_IMPORT_ITEMS) {
                $result->addWarning('Prepared the first ' . self::MAX_IMPORT_ITEMS . ' Twitter/X posts. The archive is larger than Bonumark Stream\'s safety limit for one import preview, so use the original archive again after importing this range if you need the rest.');
            } else {
                $result->addWarning('Prepared ' . count($result->items) . ' Twitter/X post(s) for import. The preview screen may show only a sample, but confirmation can import the full prepared set.');
            }
            if ($skippedRetweets > 0) {
                $result->addWarning('Skipped ' . $skippedRetweets . ' retweet(s) so only authored posts are imported.');
            }
            if ($skippedEmpty > 0) {
                $result->addWarning('Skipped ' . $skippedEmpty . ' empty or unsupported tweet record(s).');
            }
            if ($mediaEntries) {
                $result->addWarning('Detected ' . count($mediaEntries) . ' archive media file(s). Supported local image files are staged during preview and imported into Media during confirmation.');
            }
            if ($stagedBytes > 0) {
                $result->addWarning('Staged ' . $this->humanBytes($stagedBytes) . ' of local Twitter/X image media for this preview.');
            }
            if ($totalSeen === 0) {
                $result->addError('Tweet data files were found, but no tweet records could be read.');
                $this->addDiagnosticWarning($result, $zip);
            } elseif (!$result->hasItems()) {
                $result->addError('No importable authored Twitter/X posts were found. Retweets and empty records are skipped.');
            }
        } catch (Throwable $e) {
            $result->addError('Twitter/X archive import failed during preview: ' . $e->getMessage());
        } finally {
            $zip->close();
        }

        return $result;
    }

    private function normalizeZipName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = preg_replace('#/+#', '/', $name) ?? $name;
        return trim($name, '/');
    }

    private function isUnsafeZipName(string $name): bool
    {
        return $name === '' || str_starts_with($name, '/') || str_contains($name, '../') || str_contains($name, '..\\') || preg_match('/^[A-Za-z]:/', $name) === 1;
    }

    private function isTweetDataFile(string $entry): bool
    {
        $entry = strtolower($this->normalizeZipName($entry));
        if (!str_starts_with($entry, 'data/') || !str_ends_with($entry, '.js')) {
            return false;
        }
        $base = basename($entry);
        return $base === 'tweets.js'
            || $base === 'tweet.js'
            || preg_match('/^tweets?-part\d+\.js$/', $base) === 1
            || preg_match('/^tweets?\.part\d+\.js$/', $base) === 1;
    }

    private function isTweetMediaFile(string $entry): bool
    {
        $entry = strtolower($this->normalizeZipName($entry));
        if (!str_starts_with($entry, 'data/')) {
            return false;
        }
        if (!str_contains($entry, 'tweet') || !str_contains($entry, 'media')) {
            return false;
        }
        return preg_match('/\.(jpe?g|png|gif|webp)$/i', $entry) === 1;
    }

    /** @param list<string> $entries @return array<string,list<string>> */
    private function indexMediaEntries(array $entries): array
    {
        $map = [];
        foreach ($entries as $entry) {
            $base = basename($entry);
            if (preg_match('/^(\d{5,})[-_]/', $base, $match) === 1) {
                $map[$match[1]][] = $entry;
            }
        }
        return $map;
    }

    private function readZipEntry(ZipArchive $zip, string $entry, int $maxBytes): string
    {
        $stat = $zip->statName($entry);
        $size = is_array($stat) ? (int)($stat['size'] ?? 0) : 0;
        if ($size > $maxBytes) {
            throw new RuntimeException('Archive data file is too large to parse safely: ' . $entry . '.');
        }
        $stream = $zip->getStream($entry);
        if (!$stream) {
            return '';
        }
        $data = '';
        while (!feof($stream)) {
            $data .= (string)fread($stream, 8192);
            if (strlen($data) > $maxBytes) {
                fclose($stream);
                throw new RuntimeException('Archive data file exceeded the safe read limit: ' . $entry . '.');
            }
        }
        fclose($stream);
        return $data;
    }

    /** @return list<mixed> */
    private function decodeTwitterJsData(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $json = $raw;
        $position = strpos($raw, '=');
        if ($position !== false) {
            $json = trim(substr($raw, $position + 1));
        }
        $json = trim($json);
        $json = rtrim($json, "; \t\n\r\0\x0B");
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @param mixed $record @return array<string,mixed> */
    private function extractTweetRecord(mixed $record): array
    {
        if (!is_array($record)) {
            return [];
        }
        if (isset($record['tweet']) && is_array($record['tweet'])) {
            return $record['tweet'];
        }
        if (isset($record['tweetCreate']) && is_array($record['tweetCreate'])) {
            return $record['tweetCreate'];
        }
        if (isset($record['legacy']) && is_array($record['legacy'])) {
            return $record['legacy'];
        }
        return $record;
    }

    /** @param array<string,mixed> $tweet */
    private function isRetweet(array $tweet): bool
    {
        $text = $this->tweetText($tweet);
        if (preg_match('/^RT\s+@/u', $text) === 1) {
            return true;
        }
        return isset($tweet['retweeted_status']) || isset($tweet['retweeted_status_result']);
    }

    /** @param array<string,mixed> $tweet */
    private function tweetToItem(array $tweet, string $sourceName, string $entry, ZipArchive $zip, array $mediaMap, string $stagingToken, int &$stagedBytes): MP_ImportItem
    {
        $id = trim((string)($tweet['id_str'] ?? $tweet['id'] ?? ''));
        $dateRaw = trim((string)($tweet['created_at'] ?? $tweet['createdAt'] ?? ''));
        $text = $this->tweetText($tweet);
        $hashtags = $this->hashtags($tweet);
        $body = $this->tweetBodyToMarkdown($text, $tweet);
        $warnings = [];

        $stagedMedia = [];
        if ($id !== '' && isset($mediaMap[$id])) {
            foreach ($mediaMap[$id] as $mediaEntry) {
                if ($stagedBytes >= self::MAX_STAGED_MEDIA_BYTES) {
                    $warnings[] = 'Archive media staging limit reached. Some local media files were not staged.';
                    break;
                }
                $stagedUrl = $this->stageMediaEntry($zip, $mediaEntry, $stagingToken, $stagedBytes, $warnings);
                if ($stagedUrl !== '') {
                    $stagedMedia[] = $stagedUrl;
                }
            }
        }

        $remoteMedia = $this->remoteMediaUrls($tweet);
        foreach ($stagedMedia as $url) {
            $body = trim($body . "\n\n" . '![Tweet image](' . $url . ')');
        }
        foreach ($remoteMedia as $url) {
            if (!str_contains($body, $url) && !$this->hasStagedMediaForRemote($url, $stagedMedia)) {
                $body = trim($body . "\n\n" . '![Tweet image](' . $url . ')');
            }
        }

        $title = 'Twitter/X post';
        if ($id !== '') {
            $title .= ' ' . $id;
        }
        $slug = $id !== '' ? 'twitter-x-' . $id : '';
        $source = $sourceName . ' ' . basename($entry);
        if ($id !== '') {
            $source .= ' #' . $id;
        }

        $item = mp_import_make_item([
            'title' => $title,
            'slug' => $slug,
            'body' => $body,
            'date' => mp_import_normalize_date($dateRaw),
            'created_at' => mp_import_normalize_datetime($dateRaw),
            'description' => $this->descriptionFromText($text),
            'status' => 'published',
            'source' => $source,
            'featured_media' => '',
            'tags' => $hashtags,
        ]);

        foreach ($warnings as $warning) {
            $item->warnings[] = $warning;
        }
        if ($stagedMedia) {
            $item->warnings[] = count($stagedMedia) . ' local archive image(s) staged for Media import during confirmation.';
        }
        if ($remoteMedia) {
            $item->warnings[] = count($remoteMedia) . ' remote media URL(s) detected. If no local archive image is available, the standard remote media importer can try to copy supported images during confirmation.';
        }

        return $item;
    }

    /** @param array<string,mixed> $tweet */
    private function tweetText(array $tweet): string
    {
        foreach (['full_text', 'text', 'fullText'] as $key) {
            if (isset($tweet[$key]) && is_scalar($tweet[$key])) {
                return html_entity_decode((string)$tweet[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return '';
    }

    /** @param array<string,mixed> $tweet */
    private function tweetBodyToMarkdown(string $text, array $tweet): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $entities = is_array($tweet['entities'] ?? null) ? $tweet['entities'] : [];

        foreach (($entities['media'] ?? []) as $media) {
            if (!is_array($media)) {
                continue;
            }
            foreach (['url', 'display_url', 'expanded_url'] as $mediaUrlKey) {
                $short = (string)($media[$mediaUrlKey] ?? '');
                if ($short !== '') {
                    $text = str_replace($short, '', $text);
                }
            }
        }

        foreach (($entities['urls'] ?? []) as $url) {
            if (!is_array($url)) {
                continue;
            }
            $short = (string)($url['url'] ?? '');
            $expanded = (string)($url['expanded_url'] ?? $url['display_url'] ?? '');
            if ($short !== '' && $expanded !== '') {
                $text = str_replace($short, $expanded, $text);
            }
        }

        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }

    /** @param array<string,mixed> $tweet @return list<string> */
    private function hashtags(array $tweet): array
    {
        $tags = [];
        $entities = is_array($tweet['entities'] ?? null) ? $tweet['entities'] : [];
        foreach (($entities['hashtags'] ?? []) as $tag) {
            if (is_array($tag) && isset($tag['text'])) {
                $tags[] = (string)$tag['text'];
            }
        }
        if (!$tags) {
            $text = $this->tweetText($tweet);
            if (preg_match_all('/(^|\s)#([\p{L}\p{N}_]+)/u', $text, $matches) !== false) {
                $tags = array_values(array_map('strval', $matches[2] ?? []));
            }
        }
        return mp_normalize_terms($tags);
    }

    /** @param array<string,mixed> $tweet @return list<string> */
    private function remoteMediaUrls(array $tweet): array
    {
        $urls = [];
        $sets = [];
        if (isset($tweet['extended_entities']) && is_array($tweet['extended_entities'])) {
            $sets[] = $tweet['extended_entities'];
        }
        if (isset($tweet['entities']) && is_array($tweet['entities'])) {
            $sets[] = $tweet['entities'];
        }
        foreach ($sets as $set) {
            foreach (($set['media'] ?? []) as $media) {
                if (!is_array($media)) {
                    continue;
                }
                $type = strtolower((string)($media['type'] ?? ''));
                if ($type !== '' && $type !== 'photo') {
                    continue;
                }
                $url = (string)($media['media_url_https'] ?? $media['media_url'] ?? '');
                if ($url !== '' && mp_import_is_remote_http_url($url)) {
                    $urls[] = $url;
                }
            }
        }
        return array_values(array_unique($urls));
    }

    private function hasStagedMediaForRemote(string $remoteUrl, array $stagedMedia): bool
    {
        if (!$stagedMedia) {
            return false;
        }
        $base = strtolower(pathinfo((string)(parse_url($remoteUrl, PHP_URL_PATH) ?: ''), PATHINFO_FILENAME));
        if ($base === '') {
            return true;
        }
        foreach ($stagedMedia as $staged) {
            if (str_contains(strtolower($staged), $base)) {
                return true;
            }
        }
        return false;
    }

    private function stageMediaEntry(ZipArchive $zip, string $entry, string $token, int &$stagedBytes, array &$warnings): string
    {
        $stat = $zip->statName($entry);
        $size = is_array($stat) ? (int)($stat['size'] ?? 0) : 0;
        if ($size <= 0) {
            return '';
        }
        if ($size > mp_import_remote_image_max_bytes()) {
            $warnings[] = 'Skipped oversized archive media file: ' . basename($entry) . '.';
            return '';
        }
        if ($stagedBytes + $size > self::MAX_STAGED_MEDIA_BYTES) {
            $warnings[] = 'Skipped archive media file because staging limit would be exceeded: ' . basename($entry) . '.';
            return '';
        }

        $data = $this->readZipEntry($zip, $entry, mp_import_remote_image_max_bytes());
        if ($data === '') {
            return '';
        }

        $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return '';
        }

        $relative = basename($entry);
        $destination = mp_import_staging_path($token, $relative);
        $dir = dirname($destination);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $warnings[] = 'Could not create import staging folder for archive media.';
            return '';
        }
        file_put_contents($destination, $data);
        @chmod($destination, 0600);
        $stagedBytes += strlen($data);

        return mp_import_staged_media_url($token, $relative);
    }

    private function descriptionFromText(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 156);
        }
        return substr($text, 0, 156);
    }

    private function addDiagnosticWarning(MP_ImportResult $result, ZipArchive $zip): void
    {
        $sample = [];
        for ($i = 0; $i < min(25, $zip->numFiles); $i++) {
            $name = $this->normalizeZipName((string)$zip->getNameIndex($i));
            if ($name !== '') {
                $sample[] = $name;
            }
        }
        if ($sample) {
            $result->addWarning('Archive diagnostic sample: ' . implode(', ', $sample) . '.');
        }
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
