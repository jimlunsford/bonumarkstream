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
    public array $tags;
    /** @var list<string> */
    public array $warnings;

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
        string $contentType = 'stream'
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
        $this->tags = $tags;
        $this->warnings = $warnings;
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
            'content_type' => $this->contentType,
            'tags' => $this->tags,
            'warnings' => $this->warnings,
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
            in_array((string)($data['content_type'] ?? $data['post_type'] ?? 'stream'), ['stream', 'page'], true) ? (string)($data['content_type'] ?? $data['post_type'] ?? 'stream') : 'stream'
        );
    }
}
