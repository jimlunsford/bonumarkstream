<?php
require_once __DIR__ . '/database.php';

class BMS_Stream_Creation_Conflict extends RuntimeException {}
class BMS_Stream_Document_Too_Large extends RuntimeException {}

/**
 * Create a new Stream post from adapter-prepared fields and body.
 * The collision policy is a trusted internal adapter choice, never request input.
 * Uploads, authorization, receipts and transport errors belong to the adapter.
 */
function bms_stream_create_prepared(array $intent, string $body, ?int $authorId, string $collisionPolicy): array
{
    if ($collisionPolicy === 'reject_prepared') {
        $page = bms_stream_prepare_creation_page($intent, $body);
        $section = match ((string)($intent['status'] ?? 'draft')) {
            'published' => 'published',
            'scheduled' => 'scheduled',
            default => 'drafts',
        };
        $id = bms_insert_database_content($page, $section, (string)$page['slug'] . '.md', $authorId);
        return [$page, $id];
    }
    if ($collisionPolicy !== 'allocate_retry') {
        throw new InvalidArgumentException('Unknown Stream creation collision policy.');
    }
    return bms_with_stream_slug_lock(static function () use ($intent, $body, $authorId): array {
        $section = match ((string)($intent['status'] ?? 'draft')) {
            'published' => 'published',
            'scheduled' => 'scheduled',
            default => 'drafts',
        };
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $fields = bms_stream_prepare_metadata_fields($intent, $body, '');
            if (trim((string)($intent['slug'] ?? '')) !== '') {
                $fields['slug'] = bms_stream_unique_slug((string)$fields['slug']);
            }
            $raw = bms_build_markdown_document($fields, $body);
            if (strlen($raw) > 1024 * 1024 * 2) {
                throw new BMS_Stream_Document_Too_Large('Prepared Stream document exceeds two megabytes.');
            }
            $page = bms_parse_markdown_string($raw);
            try {
                $postId = bms_insert_database_content($page, $section, (string)$page['slug'] . '.md', $authorId);
                return [$page, $postId];
            } catch (BMS_Content_Slug_Conflict $e) {
                // A non-cooperating/older writer may still hit the database index.
                // Only the failed post attempt is rolled back; media is retained.
            }
        }
        throw new BMS_Stream_Creation_Conflict('A unique post slug could not be allocated.');
    });
}

/** Preserve quick-composer preparation and its early collision rejection. */
function bms_stream_prepare_creation_page(array $fields, string $body): array
{
    $fields = bms_stream_prepare_metadata_fields($fields, $body);

    $raw = bms_build_markdown_document($fields, $body);
    $page = bms_parse_markdown_string($raw);
    $slug = bms_slugify((string)($page['slug'] ?? ''));
    if ($slug === '') {
        throw new RuntimeException('Bonumark Stream could not create a valid post URL. Add more post text or enter a slug under Advanced.');
    }

    if (function_exists('bms_find_database_content_by_slug_status')) {
        foreach (['draft', 'published', 'scheduled'] as $existingStatus) {
            if (bms_find_database_content_by_slug_status($slug, $existingStatus, 'stream')) {
                throw new RuntimeException('Another stream post already uses this slug. Change the Advanced slug or edit the existing post.');
            }
        }
    }

    return $page;
}
