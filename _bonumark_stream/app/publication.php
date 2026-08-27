<?php

/**
 * Core publication-transition seam.
 *
 * Publishing remains authoritative in the posts table. Optional integrations
 * may observe completed transitions, but they must never own the post state or
 * make normal publishing depend on an external service.
 */

function bms_register_publication_transition_handler(string $name, callable $handler): void
{
    $name = strtolower(trim($name));
    if ($name === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) !== 1) {
        throw new InvalidArgumentException('Publication transition handler names must use lowercase letters, numbers, underscores, or hyphens.');
    }
    $GLOBALS['bms_publication_transition_handlers'][$name] = $handler;
}

function bms_publication_transition_handlers(): array
{
    $handlers = $GLOBALS['bms_publication_transition_handlers'] ?? [];
    return is_array($handlers) ? $handlers : [];
}

function bms_publication_state(?array $content): ?array
{
    if ($content === null) {
        return null;
    }

    $postType = strtolower(trim((string)($content['post_type'] ?? $content['content_type'] ?? 'stream')));
    $status = strtolower(trim((string)($content['status'] ?? $content['content_status'] ?? 'draft')));
    $postId = (int)($content['id'] ?? $content['post_id'] ?? 0);
    $frontMatter = $content['content_front_matter'] ?? $content['front_matter'] ?? [];
    if (is_string($frontMatter)) {
        $decoded = json_decode($frontMatter, true);
        $frontMatter = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($frontMatter)) {
        $frontMatter = [];
    }
    $contentHash = strtolower(trim((string)($content['content_hash'] ?? '')));
    if (preg_match('/^[a-f0-9]{64}$/', $contentHash) !== 1) {
        $body = (string)($content['content_body'] ?? $content['body'] ?? $content['raw'] ?? '');
        $encodedFrontMatter = json_encode($frontMatter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $contentHash = hash('sha256', $body . "\n" . (is_string($encodedFrontMatter) ? $encodedFrontMatter : ''));
    }

    return [
        'post_id' => $postId,
        'post_type' => $postType === 'page' ? 'page' : 'stream',
        'status' => $status,
        'slug' => trim((string)($content['slug'] ?? '')),
        'content_hash' => $contentHash,
        'published_at' => trim((string)($content['published_at'] ?? $content['stream_created_at'] ?? '')),
        'updated_at' => trim((string)($content['updated_at'] ?? '')),
    ];
}

function bms_publication_transition(?array $before, ?array $after, array $context = []): ?array
{
    $beforeState = bms_publication_state($before);
    $afterState = bms_publication_state($after);
    $beforePublished = $beforeState !== null && $beforeState['post_type'] === 'stream' && $beforeState['status'] === 'published';
    $afterPublished = $afterState !== null && $afterState['post_type'] === 'stream' && $afterState['status'] === 'published';

    $eventType = '';
    if (!$beforePublished && $afterPublished) {
        $eventType = 'published';
    } elseif ($beforePublished && $afterPublished) {
        if (hash_equals((string)$beforeState['content_hash'], (string)$afterState['content_hash'])) {
            return null;
        }
        $eventType = 'updated';
    } elseif ($beforePublished && !$afterPublished) {
        $eventType = $afterState === null ? 'deleted' : 'unpublished';
    } else {
        return null;
    }

    $current = $afterState ?? $beforeState;
    return [
        'event_type' => $eventType,
        'source' => strtolower(trim((string)($context['source'] ?? 'application'))) ?: 'application',
        'post_id' => (int)($current['post_id'] ?? 0),
        'post_type' => (string)($current['post_type'] ?? 'stream'),
        'slug' => (string)($current['slug'] ?? ''),
        'content_hash' => (string)($current['content_hash'] ?? ''),
        'before' => $beforeState,
        'after' => $afterState,
    ];
}

function bms_dispatch_publication_transition(?array $before, ?array $after, array $context = []): void
{
    $transition = bms_publication_transition($before, $after, $context);
    if ($transition === null) {
        return;
    }

    foreach (bms_publication_transition_handlers() as $name => $handler) {
        try {
            $handler($transition);
        } catch (Throwable $e) {
            error_log('Bonumark Stream publication transition handler ' . $name . ' failed: ' . $e->getMessage());
        }
    }
}
