<?php

class BMS_ImportItem
{
    public string $title;
    public string $slug;
    public string $body;
    public string $date;
    public string $createdAt;
    public string $description;
    public string $status;
    public string $source;
    public string $featuredMedia;
    public string $contentType;
    /** @var list<string> */
    public array $mediaGallery;
    /** @var list<string> */
    public array $tags;
    /** @var list<string> */
    public array $warnings;
    /**
     * Fields beyond the basic set, kept exactly as an importer read them:
     * SEO, link preview, location, publication, and pin values. Only the
     * keys in metaKeys() are kept.
     *
     * @var array<string,string>
     */
    public array $meta;

    /**
     * @param list<string> $tags
     * @param list<string> $warnings
     */
    public function __construct(
        string $title,
        string $slug,
        string $body,
        string $date,
        string $createdAt = '',
        string $description = '',
        string $status = 'draft',
        string $source = '',
        string $featuredMedia = '',
        array $tags = [],
        array $warnings = [],
        string $contentType = 'stream',
        array $mediaGallery = [],
        array $meta = []
    ) {
        $this->title = $title;
        $this->slug = $slug;
        $this->body = $body;
        $this->date = $date;
        $this->createdAt = $createdAt;
        $this->description = $description;
        $this->status = $status;
        $this->source = $source;
        $this->featuredMedia = $featuredMedia;
        $this->contentType = in_array($contentType, ['stream', 'page'], true) ? $contentType : 'stream';
        $this->mediaGallery = bms_normalize_media_gallery($mediaGallery, $featuredMedia);
        $this->tags = $tags;
        $this->warnings = $warnings;
        $this->meta = self::cleanMeta($meta);
    }

    /** @return list<string> */
    public static function metaKeys(): array
    {
        return [
            'exact', 'seo_title', 'robots', 'scheduled_at', 'published_at', 'published_at_source', 'is_pinned', 'pinned_at',
            'link_preview_url', 'link_preview_title', 'link_preview_description', 'link_preview_image', 'link_preview_site_name',
            'location_place_id', 'location_name', 'location_category', 'location_area', 'location_locality', 'location_region', 'location_country', 'location_display_mode',
        ];
    }

    /** @return array<string,string> */
    public static function cleanMeta(mixed $meta): array
    {
        if (!is_array($meta)) {
            return [];
        }
        $clean = [];
        foreach (self::metaKeys() as $key) {
            if (array_key_exists($key, $meta) && is_scalar($meta[$key])) {
                $clean[$key] = (string)$meta[$key];
            }
        }
        return $clean;
    }

    public function metaValue(string $key): string
    {
        return (string)($this->meta[$key] ?? '');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'slug' => $this->slug,
            'body' => $this->body,
            'date' => $this->date,
            'created_at' => $this->createdAt,
            'description' => $this->description,
            'status' => $this->status,
            'source' => $this->source,
            'featured_media' => $this->featuredMedia,
            'media_gallery' => $this->mediaGallery,
            'content_type' => $this->contentType,
            'tags' => $this->tags,
            'warnings' => $this->warnings,
            'meta' => $this->meta,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $tags = $data['tags'] ?? [];
        $warnings = $data['warnings'] ?? [];
        return new self(
            (string)($data['title'] ?? ''),
            (string)($data['slug'] ?? ''),
            (string)($data['body'] ?? ''),
            (string)($data['date'] ?? date('Y-m-d')),
            (string)($data['created_at'] ?? ''),
            (string)($data['description'] ?? ''),
            (string)($data['status'] ?? 'draft'),
            (string)($data['source'] ?? ''),
            (string)($data['featured_media'] ?? ''),
            is_array($tags) ? array_values(array_filter(array_map('strval', $tags))) : [],
            is_array($warnings) ? array_values(array_filter(array_map('strval', $warnings))) : [],
            in_array((string)($data['content_type'] ?? $data['post_type'] ?? 'stream'), ['stream', 'page'], true) ? (string)($data['content_type'] ?? $data['post_type'] ?? 'stream') : 'stream',
            is_array($data['media_gallery'] ?? null) ? $data['media_gallery'] : [],
            is_array($data['meta'] ?? null) ? $data['meta'] : []
        );
    }
}
