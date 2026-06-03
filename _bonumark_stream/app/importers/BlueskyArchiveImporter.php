<?php

class BMS_BlueskyArchiveImporter implements BMS_ImporterInterface
{
    private const MAX_CAR_BYTES = 128_000_000;
    private const MAX_BLOCK_BYTES = 8_000_000;

    public function label(): string
    {
        return 'Bluesky/AT Protocol Archive';
    }

    public function canImport(array $file): bool
    {
        $name = strtolower((string)($file['name'] ?? ''));
        $path = (string)($file['tmp_name'] ?? '');
        if ($path === '' || !bms_import_uploaded_file_is_readable($path)) {
            return false;
        }

        if (str_ends_with($name, '.car')) {
            return $this->fileLooksLikeCar($path);
        }

        if (!str_ends_with($name, '.zip') || !class_exists('ZipArchive')) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $this->normalizeArchiveName((string)$zip->getNameIndex($i));
                if ($entry === '' || str_ends_with($entry, '/')) {
                    continue;
                }
                if ($this->isCarEntry($entry) || $entry === 'export.car' || $entry === 'repo.car') {
                    return true;
                }
                if (str_contains(strtolower($entry), 'app.bsky.feed.post')) {
                    return true;
                }
            }
        } finally {
            $zip->close();
        }

        return false;
    }

    public function importPreview(array $file): BMS_ImportResult
    {
        $result = new BMS_ImportResult($this->label());
        $path = (string)($file['tmp_name'] ?? '');
        $name = (string)($file['name'] ?? 'bluesky-archive');

        if ($path === '' || !bms_import_uploaded_file_is_readable($path)) {
            $result->addError('Uploaded Bluesky archive could not be read.');
            return $result;
        }

        $lowerName = strtolower($name);
        try {
            if (str_ends_with($lowerName, '.car')) {
                $car = $this->readFileLimited($path, self::MAX_CAR_BYTES);
                $this->previewCarBytes($car, $name, $result);
            } elseif (str_ends_with($lowerName, '.zip')) {
                $this->previewZipArchive($path, $name, $result);
            } else {
                $result->addError('Bluesky imports must be a .car repository export. ZIP archives containing a repository CAR file are still supported for compatibility.');
            }
        } catch (Throwable $e) {
            $result->addError('Bluesky archive import failed during preview: ' . $e->getMessage());
        }

        if (!$result->hasItems() && !$result->errors) {
            $result->addError('No importable Bluesky posts were found. This importer looks for app.bsky.feed.post records in an AT Protocol repository CAR export.');
        }

        return $result;
    }

    private function fileLooksLikeCar(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $sample = (string)fread($handle, 4096);
        fclose($handle);
        return $sample !== '' && $this->parseCarHeaderVersion($sample) === 1;
    }

    private function readFileLimited(string $path, int $maxBytes): string
    {
        $size = filesize($path);
        if ($size !== false && $size > $maxBytes) {
            throw new RuntimeException('The Bluesky CAR file is larger than the safe import limit.');
        }
        $data = (string)file_get_contents($path);
        if (strlen($data) > $maxBytes) {
            throw new RuntimeException('The Bluesky CAR file exceeded the safe import limit.');
        }
        return $data;
    }

    private function previewZipArchive(string $path, string $sourceName, BMS_ImportResult $result): void
    {
        if (!class_exists('ZipArchive')) {
            $result->addError('ZipArchive is required to import Bluesky ZIP archives.');
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            $result->addError('The uploaded file is not a readable ZIP archive.');
            return;
        }

        try {
            $carEntries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $this->normalizeArchiveName((string)$zip->getNameIndex($i));
                if ($entry === '' || str_ends_with($entry, '/')) {
                    continue;
                }
                if ($this->isUnsafeArchiveName($entry)) {
                    $result->addWarning('Skipped unsafe archive path: ' . $entry . '.');
                    continue;
                }
                if ($this->isCarEntry($entry)) {
                    $carEntries[] = $entry;
                }
            }

            sort($carEntries, SORT_NATURAL | SORT_FLAG_CASE);
            if (!$carEntries) {
                $result->addError('No Bluesky repository CAR file was found. Expected a .car file such as repo.car inside the archive.');
                $this->addZipDiagnostics($zip, $result);
                return;
            }

            $carEntry = $this->chooseCarEntry($carEntries);
            $carBytes = $this->readZipEntry($zip, $carEntry, self::MAX_CAR_BYTES);
            $this->previewCarBytes($carBytes, $sourceName . ':' . $carEntry, $result);

            if (count($carEntries) > 1) {
                $result->addWarning('Multiple CAR files were found. Used ' . $carEntry . ' for this preview.');
            }
        } finally {
            $zip->close();
        }
    }

    private function previewCarBytes(string $car, string $sourceName, BMS_ImportResult $result): void
    {
        $parsed = $this->parseCarRecords($car);
        if (!$parsed['valid']) {
            $result->addError('The uploaded Bluesky CAR file could not be parsed as a CAR v1 repository export.');
            return;
        }

        $totalPosts = 0;
        $skippedReplies = 0;
        $skippedReposts = 0;
        $skippedEmpty = 0;
        $imageRefs = 0;

        foreach ($parsed['records'] as $record) {
            if (!is_array($record)) {
                continue;
            }
            $type = (string)($record['$type'] ?? '');
            if ($type === 'app.bsky.feed.repost') {
                $skippedReposts++;
                continue;
            }
            if ($type !== 'app.bsky.feed.post') {
                continue;
            }
            $totalPosts++;
            if (is_array($record['reply'] ?? null)) {
                $skippedReplies++;
                continue;
            }

            $item = $this->postRecordToItem($record, $sourceName, $imageRefs);
            if (trim($item->body) === '') {
                $skippedEmpty++;
                continue;
            }
            $result->addItem($item);
        }

        $result->addWarning('Prepared ' . count($result->items) . ' Bluesky post(s) for import from the CAR file. The preview screen may show only a sample, but the full prepared set can be imported without splitting the archive.');
        if ($skippedReplies > 0) {
            $result->addWarning('Skipped ' . $skippedReplies . ' Bluesky reply post(s) so the import stays focused on authored top-level posts.');
        }
        if ($skippedReposts > 0) {
            $result->addWarning('Skipped ' . $skippedReposts . ' Bluesky repost record(s).');
        }
        if ($skippedEmpty > 0) {
            $result->addWarning('Skipped ' . $skippedEmpty . ' empty Bluesky post record(s).');
        }
        if ($imageRefs > 0) {
            $result->addWarning('Detected ' . $imageRefs . ' Bluesky image reference(s). Bluesky CAR exports currently import text, timestamps, hashtags, and links only. Media import is not available from CAR exports at this time.');
        }
        if ($totalPosts === 0) {
            $result->addError('The CAR file was readable, but no app.bsky.feed.post records were found.');
        } elseif (!$result->hasItems()) {
            $result->addError('No importable Bluesky posts were found after filtering replies, reposts, and empty records.');
        }
    }

    private function normalizeArchiveName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = preg_replace('#/+#', '/', $name) ?? $name;
        return trim($name, '/');
    }

    private function isUnsafeArchiveName(string $name): bool
    {
        return $name === '' || str_starts_with($name, '/') || str_contains($name, '../') || str_contains($name, '..\\') || preg_match('/^[A-Za-z]:/', $name) === 1;
    }

    private function isCarEntry(string $entry): bool
    {
        return str_ends_with(strtolower($this->normalizeArchiveName($entry)), '.car');
    }

    private function chooseCarEntry(array $entries): string
    {
        foreach ($entries as $entry) {
            $base = strtolower(basename((string)$entry));
            if (in_array($base, ['repo.car', 'export.car', 'account.car'], true)) {
                return (string)$entry;
            }
        }
        return (string)$entries[0];
    }

    private function readZipEntry(ZipArchive $zip, string $entry, int $maxBytes): string
    {
        $stat = $zip->statName($entry);
        $size = is_array($stat) ? (int)($stat['size'] ?? 0) : 0;
        if ($size > $maxBytes) {
            throw new RuntimeException('Archive file is too large to parse safely: ' . $entry . '.');
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
                throw new RuntimeException('Archive file exceeded the safe read limit: ' . $entry . '.');
            }
        }
        fclose($stream);
        return $data;
    }

    /** @return array{valid:bool,records:list<mixed>} */
    private function parseCarRecords(string $car): array
    {
        if ($car === '') {
            return ['valid' => false, 'records' => []];
        }

        $offset = 0;
        $headerLen = $this->readUnsignedVarint($car, $offset);
        if ($headerLen <= 0 || $offset + $headerLen > strlen($car)) {
            return ['valid' => false, 'records' => []];
        }

        $headerBytes = substr($car, $offset, $headerLen);
        $offset += $headerLen;
        $version = $this->parseCarHeaderVersion($this->encodeVarint(strlen($headerBytes)) . $headerBytes);
        if ($version !== 1) {
            return ['valid' => false, 'records' => []];
        }

        $records = [];
        $length = strlen($car);
        while ($offset < $length) {
            $blockLen = $this->readUnsignedVarint($car, $offset);
            if ($blockLen <= 0 || $offset + $blockLen > $length) {
                break;
            }
            $block = substr($car, $offset, $blockLen);
            $offset += $blockLen;
            $dataOffset = $this->cidLength($block);
            if ($dataOffset <= 0 || $dataOffset >= strlen($block)) {
                continue;
            }
            $data = substr($block, $dataOffset);
            if (strlen($data) > self::MAX_BLOCK_BYTES) {
                continue;
            }
            $cborOffset = 0;
            $decoded = $this->decodeCbor($data, $cborOffset, 0);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        return ['valid' => true, 'records' => $records];
    }

    private function parseCarHeaderVersion(string $sample): int
    {
        try {
            $offset = 0;
            $headerLen = $this->readUnsignedVarint($sample, $offset);
            if ($headerLen <= 0 || $offset + $headerLen > strlen($sample)) {
                return 0;
            }
            $headerBytes = substr($sample, $offset, $headerLen);
            $cborOffset = 0;
            $header = $this->decodeCbor($headerBytes, $cborOffset, 0);
            if (is_array($header) && isset($header['version'])) {
                return (int)$header['version'];
            }
        } catch (Throwable) {
            return 0;
        }
        return 0;
    }

    private function readUnsignedVarint(string $bytes, int &$offset): int
    {
        $result = 0;
        $shift = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $byte = ord($bytes[$offset]);
            $offset++;
            $result |= (($byte & 0x7f) << $shift);
            if (($byte & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
            if ($shift > 56) {
                throw new RuntimeException('Invalid varint in Bluesky archive.');
            }
        }
        throw new RuntimeException('Unexpected end of varint in Bluesky archive.');
    }

    private function encodeVarint(int $value): string
    {
        $out = '';
        while ($value >= 0x80) {
            $out .= chr(($value & 0x7f) | 0x80);
            $value >>= 7;
        }
        return $out . chr($value);
    }

    private function cidLength(string $bytes): int
    {
        if (strlen($bytes) >= 34 && ord($bytes[0]) === 0x12 && ord($bytes[1]) === 0x20) {
            return 34;
        }
        try {
            $offset = 0;
            $version = $this->readUnsignedVarint($bytes, $offset);
            if ($version !== 1) {
                return 0;
            }
            $this->readUnsignedVarint($bytes, $offset); // multicodec
            $this->readUnsignedVarint($bytes, $offset); // multihash code
            $digestLength = $this->readUnsignedVarint($bytes, $offset);
            if ($digestLength < 0 || $offset + $digestLength > strlen($bytes)) {
                return 0;
            }
            return $offset + $digestLength;
        } catch (Throwable) {
            return 0;
        }
    }

    private function decodeCbor(string $data, int &$offset, int $depth): mixed
    {
        if ($depth > 64) {
            throw new RuntimeException('CBOR nesting depth exceeded.');
        }
        if ($offset >= strlen($data)) {
            throw new RuntimeException('Unexpected end of CBOR data.');
        }

        $initial = ord($data[$offset]);
        $offset++;
        $major = $initial >> 5;
        $additional = $initial & 0x1f;
        $arg = $this->readCborLength($data, $offset, $additional);

        return match ($major) {
            0 => $arg,
            1 => -1 - $arg,
            2 => $this->decodeBytes($data, $offset, $arg),
            3 => $this->decodeText($data, $offset, $arg),
            4 => $this->decodeArray($data, $offset, $arg, $depth + 1),
            5 => $this->decodeMap($data, $offset, $arg, $depth + 1),
            6 => $this->decodeTag($data, $offset, $arg, $depth + 1),
            7 => $this->decodeSimple($arg),
            default => null,
        };
    }

    private function readCborLength(string $data, int &$offset, int $additional): int
    {
        $length = strlen($data);
        if ($additional < 24) {
            return $additional;
        }
        $bytes = match ($additional) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            default => throw new RuntimeException('Unsupported indefinite or reserved CBOR length.'),
        };
        if ($offset + $bytes > $length) {
            throw new RuntimeException('Unexpected end of CBOR length.');
        }
        $value = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $value = ($value << 8) | ord($data[$offset + $i]);
        }
        $offset += $bytes;
        return $value;
    }

    private function decodeBytes(string $data, int &$offset, int $length): array
    {
        if ($length < 0 || $offset + $length > strlen($data)) {
            throw new RuntimeException('Invalid CBOR byte string length.');
        }
        $bytes = substr($data, $offset, $length);
        $offset += $length;
        return ['__bytes' => $bytes];
    }

    private function decodeText(string $data, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > strlen($data)) {
            throw new RuntimeException('Invalid CBOR text string length.');
        }
        $text = substr($data, $offset, $length);
        $offset += $length;
        return $text;
    }

    private function decodeArray(string $data, int &$offset, int $count, int $depth): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->decodeCbor($data, $offset, $depth);
        }
        return $items;
    }

    private function decodeMap(string $data, int &$offset, int $count, int $depth): array
    {
        $map = [];
        for ($i = 0; $i < $count; $i++) {
            $key = $this->decodeCbor($data, $offset, $depth);
            $value = $this->decodeCbor($data, $offset, $depth);
            if (is_string($key) || is_int($key)) {
                $map[(string)$key] = $value;
            }
        }
        return $map;
    }

    private function decodeTag(string $data, int &$offset, int $tag, int $depth): mixed
    {
        $value = $this->decodeCbor($data, $offset, $depth);
        if ($tag === 42 && is_array($value) && isset($value['__bytes']) && is_string($value['__bytes'])) {
            $bytes = $value['__bytes'];
            if ($bytes !== '' && ord($bytes[0]) === 0) {
                $cidBytes = substr($bytes, 1);
                return ['__cid' => $this->cidToString($cidBytes), '__cid_bytes' => $cidBytes];
            }
        }
        return ['__tag' => $tag, 'value' => $value];
    }

    private function decodeSimple(int $arg): mixed
    {
        return match ($arg) {
            20 => false,
            21 => true,
            22, 23 => null,
            default => null,
        };
    }

    private function cidToString(string $cidBytes): string
    {
        if ($cidBytes === '') {
            return '';
        }
        if (strlen($cidBytes) === 34 && ord($cidBytes[0]) === 0x12 && ord($cidBytes[1]) === 0x20) {
            return 'Qm' . substr(hash('sha256', $cidBytes), 0, 44);
        }
        return 'b' . $this->base32Lower($cidBytes);
    }

    private function base32Lower(string $bytes): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $bits = 0;
        $value = 0;
        $output = '';
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $output .= $alphabet[($value >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $output .= $alphabet[($value << (5 - $bits)) & 31];
        }
        return $output;
    }

    private function postRecordToItem(array $record, string $sourceName, int &$imageRefs): BMS_ImportItem
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string)($record['text'] ?? ''));
        $createdAt = (string)($record['createdAt'] ?? '');
        $date = bms_import_normalize_date($createdAt);
        $body = $this->applyFacetsToText($text, is_array($record['facets'] ?? null) ? $record['facets'] : []);
        $tags = $this->tagsFromPost($record, $text);
        $warnings = [];

        $embedImages = $this->embedImages($record);
        if ($embedImages) {
            $imageRefs += count($embedImages);
        }

        $externalUrl = $this->embedExternalUrl($record);
        if ($externalUrl !== '' && !str_contains($body, $externalUrl)) {
            $body = trim($body . "\n\n" . $externalUrl);
        }

        $hash = substr(sha1($createdAt . "\n" . $text), 0, 12);
        $slug = 'bluesky-' . ($date !== '' ? $date . '-' : '') . $hash;
        $titleText = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (function_exists('mb_substr')) {
            $titleText = mb_substr($titleText, 0, 72);
        } else {
            $titleText = substr($titleText, 0, 72);
        }

        return new BMS_ImportItem(
            $titleText !== '' ? $titleText : 'Bluesky post ' . $hash,
            $slug,
            trim($body),
            $date,
            bms_import_normalize_datetime($createdAt),
            '',
            'published',
            $sourceName,
            '',
            $tags,
            array_values(array_unique($warnings))
        );
    }

    private function applyFacetsToText(string $text, array $facets): string
    {
        $replacements = [];
        foreach ($facets as $facet) {
            if (!is_array($facet)) {
                continue;
            }
            $index = is_array($facet['index'] ?? null) ? $facet['index'] : [];
            $start = (int)($index['byteStart'] ?? -1);
            $end = (int)($index['byteEnd'] ?? -1);
            if ($start < 0 || $end <= $start || $end > strlen($text)) {
                continue;
            }
            $features = is_array($facet['features'] ?? null) ? $facet['features'] : [];
            foreach ($features as $feature) {
                if (!is_array($feature)) {
                    continue;
                }
                if ((string)($feature['$type'] ?? '') === 'app.bsky.richtext.facet#link') {
                    $uri = trim((string)($feature['uri'] ?? ''));
                    if ($uri !== '') {
                        $segment = substr($text, $start, $end - $start);
                        $safeSegment = str_replace(["\r", "\n", '[', ']'], [' ', ' ', '', ''], $segment);
                        $replacements[] = ['start' => $start, 'end' => $end, 'value' => '[' . $safeSegment . '](' . $uri . ')'];
                    }
                    break;
                }
            }
        }

        usort($replacements, static fn(array $a, array $b): int => (int)$b['start'] <=> (int)$a['start']);
        foreach ($replacements as $replacement) {
            $text = substr($text, 0, (int)$replacement['start']) . (string)$replacement['value'] . substr($text, (int)$replacement['end']);
        }
        return trim($text);
    }

    private function tagsFromPost(array $record, string $text): array
    {
        $tags = [];
        if (preg_match_all('/(?<!\w)#([\p{L}\p{N}_-]+)/u', $text, $matches)) {
            foreach ($matches[1] as $tag) {
                $tags[] = (string)$tag;
            }
        }
        $facets = is_array($record['facets'] ?? null) ? $record['facets'] : [];
        foreach ($facets as $facet) {
            if (!is_array($facet)) {
                continue;
            }
            $features = is_array($facet['features'] ?? null) ? $facet['features'] : [];
            foreach ($features as $feature) {
                if (is_array($feature) && (string)($feature['$type'] ?? '') === 'app.bsky.richtext.facet#tag') {
                    $tag = trim((string)($feature['tag'] ?? ''));
                    if ($tag !== '') {
                        $tags[] = $tag;
                    }
                }
            }
        }
        return bms_normalize_terms(array_values(array_unique($tags)));
    }

    private function embedExternalUrl(array $record): string
    {
        $embed = is_array($record['embed'] ?? null) ? $record['embed'] : [];
        $type = (string)($embed['$type'] ?? '');
        $external = [];
        if ($type === 'app.bsky.embed.external') {
            $external = is_array($embed['external'] ?? null) ? $embed['external'] : [];
        } elseif ($type === 'app.bsky.embed.recordWithMedia' && is_array($embed['media'] ?? null)) {
            $media = $embed['media'];
            if ((string)($media['$type'] ?? '') === 'app.bsky.embed.external') {
                $external = is_array($media['external'] ?? null) ? $media['external'] : [];
            }
        }
        $uri = trim((string)($external['uri'] ?? ''));
        if ($uri === '' || preg_match('#^https?://#i', $uri) !== 1) {
            return '';
        }
        return $uri;
    }

    /** @return list<array<string,mixed>> */
    private function embedImages(array $record): array
    {
        $embed = is_array($record['embed'] ?? null) ? $record['embed'] : [];
        $type = (string)($embed['$type'] ?? '');
        if ($type === 'app.bsky.embed.images') {
            return is_array($embed['images'] ?? null) ? array_values(array_filter($embed['images'], 'is_array')) : [];
        }
        if ($type === 'app.bsky.embed.recordWithMedia' && is_array($embed['media'] ?? null)) {
            $media = $embed['media'];
            if ((string)($media['$type'] ?? '') === 'app.bsky.embed.images') {
                return is_array($media['images'] ?? null) ? array_values(array_filter($media['images'], 'is_array')) : [];
            }
        }
        return [];
    }

    private function addZipDiagnostics(ZipArchive $zip, BMS_ImportResult $result): void
    {
        $entries = [];
        for ($i = 0; $i < min(20, $zip->numFiles); $i++) {
            $entry = $this->normalizeArchiveName((string)$zip->getNameIndex($i));
            if ($entry !== '') {
                $entries[] = $entry;
            }
        }
        if ($entries) {
            $result->addWarning('Archive sample entries: ' . implode(', ', $entries) . '.');
        }
    }
}
