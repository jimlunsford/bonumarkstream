<?php

/**
 * Federation-owned inbound replies and lightweight social interactions.
 *
 * Every record is bound to an immutable local ActivityPub object URI and its
 * publication generation. These helpers do not create local users, comments,
 * or anonymous Stream likes.
 */

function bms_activitypub_stage5_activity_types(): array
{
    return ['Create', 'Update', 'Delete', 'Like', 'Announce', 'Undo'];
}

function bms_activitypub_remote_plain_text(string $value, int $limit = 5000): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\R{3,}/u', "\n\n", $value) ?? $value;
    return trim(bms_text_substr($value, 0, max(0, $limit)));
}

function bms_activitypub_remote_link_url(string $value): string
{
    $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
        return '';
    }
    $parts = parse_url($value);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || trim((string)($parts['host'] ?? '')) === '' || isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }
    $host = strtolower(rtrim(trim((string)$parts['host'], '[]'), '.'));
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
        || str_ends_with($host, '.internal') || str_ends_with($host, '.home.arpa')
        || (filter_var($host, FILTER_VALIDATE_IP) !== false && !bms_activitypub_ip_is_public($host))) {
        return '';
    }
    return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
}

function bms_activitypub_sanitize_remote_html_node(DOMNode $node): string
{
    if ($node instanceof DOMText) {
        return htmlspecialchars((string)$node->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    if (!($node instanceof DOMElement)) {
        return '';
    }
    $tag = strtolower($node->tagName);
    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form'], true)) {
        return '';
    }
    $children = '';
    foreach ($node->childNodes as $child) {
        $children .= bms_activitypub_sanitize_remote_html_node($child);
    }
    if (!in_array($tag, ['p', 'br', 'a', 'strong', 'b', 'em', 'i', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li'], true)) {
        return $children;
    }
    if ($tag === 'br') {
        return '<br>';
    }
    if ($tag === 'a') {
        $href = bms_activitypub_remote_link_url((string)$node->getAttribute('href'));
        return $href === '' ? $children : '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" rel="nofollow noopener noreferrer">' . $children . '</a>';
    }
    $normalized = match ($tag) {
        'b' => 'strong',
        'i' => 'em',
        default => $tag,
    };
    return '<' . $normalized . '>' . $children . '</' . $normalized . '>';
}

function bms_activitypub_sanitize_remote_html_fallback(string $html, string $text): string
{
    $html = preg_replace('#<(script|style|iframe|object|embed|svg|math|form)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';
    $html = preg_replace('#<(?:br|/p|/li|/blockquote)\s*/?>#i', "\n", $html) ?? $html;
    $safeLinks = [];
    $tokenSalt = substr(hash('sha256', $html), 0, 16);
    $html = preg_replace_callback('#<a\b([^>]*)>(.*?)</a\s*>#is', static function (array $match) use (&$safeLinks, $tokenSalt): string {
        $href = '';
        if (preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', (string)$match[1], $hrefMatch) === 1) {
            $href = (string)($hrefMatch[1] ?? $hrefMatch[2] ?? $hrefMatch[3] ?? '');
        }
        $label = bms_activitypub_remote_plain_text((string)$match[2], 1000);
        $href = bms_activitypub_remote_link_url($href);
        if ($href === '' || $label === '') {
            return $label;
        }
        $token = 'BMSREMOTELINK' . $tokenSalt . count($safeLinks) . 'TOKEN';
        $safeLinks[$token] = '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" rel="nofollow noopener noreferrer">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        return $token;
    }, $html) ?? $html;
    $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace('/\R{3,}/u', "\n\n", $plain) ?? $plain;
    $parts = preg_split('/(BMSREMOTELINK' . preg_quote($tokenSalt, '/') . '[0-9]+TOKEN)/', $plain, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$plain];
    $safe = '';
    foreach ($parts as $part) {
        $safe .= $safeLinks[$part] ?? nl2br(htmlspecialchars($part, ENT_QUOTES, 'UTF-8'), false);
    }
    $safe = trim($safe);
    return $safe !== '' ? '<p>' . $safe . '</p>' : '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false) . '</p>';
}

function bms_activitypub_sanitize_remote_html(string $html): array
{
    if (strlen($html) > 65536) {
        throw new BmsActivityPubSecurityException('The remote Note content is too large.', 413);
    }
    $text = bms_activitypub_remote_plain_text($html, 10000);
    if ($text === '') {
        throw new BmsActivityPubSecurityException('The remote Note content is empty.', 400);
    }
    if (!class_exists('DOMDocument')) {
        return ['html' => bms_activitypub_sanitize_remote_html_fallback($html, $text), 'text' => $text];
    }
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div id="bms-remote-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        return ['html' => bms_activitypub_sanitize_remote_html_fallback($html, $text), 'text' => $text];
    }
    $root = $document->getElementById('bms-remote-root');
    if (!($root instanceof DOMElement)) {
        return ['html' => bms_activitypub_sanitize_remote_html_fallback($html, $text), 'text' => $text];
    }
    $safe = '';
    foreach ($root->childNodes as $child) {
        $safe .= bms_activitypub_sanitize_remote_html_node($child);
    }
    $safe = trim($safe);
    if ($safe === '') {
        $safe = '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false) . '</p>';
    }
    return ['html' => $safe, 'text' => $text];
}

function bms_activitypub_remote_datetime(mixed $value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
        throw new BmsActivityPubSecurityException('A remote timestamp is invalid.', 400);
    }
    try {
        $date = new DateTimeImmutable($value);
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        throw new BmsActivityPubSecurityException('A remote timestamp is invalid.', 400);
    }
}

function bms_activitypub_local_generation_by_uri(string $objectUri): ?array
{
    $stmt = bms_db()->prepare('SELECT lo.*, p.slug, p.title, p.status AS post_status FROM ' . bms_table('activitypub_local_objects') . ' lo LEFT JOIN ' . bms_table('posts') . ' p ON p.id = lo.post_id WHERE lo.object_uri = :object_uri LIMIT 1');
    $stmt->execute(['object_uri' => $objectUri]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bms_activitypub_remote_reply_by_uri(string $objectUri, bool $lock = false): ?array
{
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('activitypub_remote_replies') . ' WHERE remote_object_uri = :object_uri LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute(['object_uri' => $objectUri]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bms_activitypub_target_for_reply(string $inReplyTo): ?array
{
    $local = bms_activitypub_local_generation_by_uri($inReplyTo);
    if (is_array($local)) {
        return ['local' => $local, 'parent' => null];
    }
    $parent = bms_activitypub_remote_reply_by_uri($inReplyTo, true);
    if (!is_array($parent)) {
        return null;
    }
    $local = bms_activitypub_local_generation_by_uri((string)$parent['target_object_uri']);
    return is_array($local) ? ['local' => $local, 'parent' => $parent] : null;
}

function bms_activitypub_actor_domain(string $actorUri): string
{
    return strtolower(rtrim((string)(parse_url($actorUri, PHP_URL_HOST) ?? ''), '.'));
}

function bms_activitypub_actor_is_blocked(string $actorUri): bool
{
    $stmt = bms_db()->prepare("SELECT 1 FROM " . bms_table('activitypub_followers') . " WHERE actor_uri = :actor_uri AND state = 'blocked' LIMIT 1");
    $stmt->execute(['actor_uri' => $actorUri]);
    if ($stmt->fetchColumn() !== false) {
        return true;
    }
    $domain = bms_activitypub_actor_domain($actorUri);
    $stmt = bms_db()->prepare('SELECT 1 FROM ' . bms_table('activitypub_blocks') . ' WHERE (block_type = :actor_type AND block_value = :actor_uri) OR (block_type = :domain_type AND block_value = :domain) LIMIT 1');
    $stmt->execute(['actor_type' => 'actor', 'actor_uri' => $actorUri, 'domain_type' => 'domain', 'domain' => $domain]);
    return $stmt->fetchColumn() !== false;
}

function bms_activitypub_enforce_stage5_rate_limit(string $actorUri, string $type): void
{
    if (!in_array($type, bms_activitypub_stage5_activity_types(), true)) {
        return;
    }
    $limit = in_array($type, ['Create', 'Update', 'Delete'], true) ? 30 : 120;
    $stmt = bms_db()->prepare('SELECT COUNT(*) FROM ' . bms_table('activitypub_inbox_receipts') . ' WHERE actor_uri = :actor_uri AND activity_type = :activity_type AND received_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)');
    $stmt->execute(['actor_uri' => $actorUri, 'activity_type' => $type]);
    if ((int)$stmt->fetchColumn() >= $limit) {
        throw new BmsActivityPubSecurityException('The remote actor has exceeded the inbound activity rate limit.', 429);
    }
}

function bms_activitypub_note_actor(array $note): string
{
    return bms_activitypub_actor_reference($note['attributedTo'] ?? $note['actor'] ?? null);
}

function bms_activitypub_process_reply_create(array $activity, array $remoteActor, int $receiptId): string
{
    $actorUri = bms_activitypub_actor_reference($activity['actor'] ?? null);
    $note = $activity['object'] ?? null;
    if (!is_array($note) || array_is_list($note) || strcasecmp(trim((string)($note['type'] ?? '')), 'Note') !== 0) {
        throw new BmsActivityPubSecurityException('Create must contain a remote Note.', 400);
    }
    if (!hash_equals($actorUri, bms_activitypub_note_actor($note))) {
        throw new BmsActivityPubSecurityException('The authenticated actor does not own the remote Note.', 403);
    }
    $objectUri = bms_activitypub_identifier_uri((string)($note['id'] ?? ''), false);
    $inReplyTo = bms_activitypub_identifier_uri(bms_activitypub_target_object_id($note['inReplyTo'] ?? null), false);
    $existing = bms_activitypub_remote_reply_by_uri($objectUri, true);
    if (is_array($existing)) {
        return (string)$existing['lifecycle_state'] === 'deleted' ? 'reply_create_after_delete' : 'reply_duplicate_object';
    }
    $target = bms_activitypub_target_for_reply($inReplyTo);
    if (!is_array($target)) {
        return 'reply_unknown_target';
    }
    $parent = $target['parent'];
    if (is_array($parent) && (string)$parent['lifecycle_state'] === 'deleted') {
        return 'reply_parent_deleted';
    }
    $local = $target['local'];
    $retired = !empty($local['deleted_at']);
    $content = bms_activitypub_sanitize_remote_html(is_string($note['content'] ?? null) ? (string)$note['content'] : '');
    $activityUri = bms_activitypub_identifier_uri((string)$activity['id'], true);
    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('activitypub_remote_replies') . ' (remote_actor_id, actor_uri, remote_object_uri, create_activity_uri, last_activity_uri, created_receipt_id, last_receipt_id, target_post_id, target_publication_generation, target_object_uri, parent_reply_id, in_reply_to_uri, content_html, content_text, content_hash, moderation_state, lifecycle_state, remote_published_at, remote_updated_at, deleted_at, moderated_at, moderator_user_id, created_at, updated_at) VALUES (:remote_actor_id, :actor_uri, :remote_object_uri, :create_activity_uri, :last_activity_uri, :created_receipt_id, :last_receipt_id, :target_post_id, :target_generation, :target_object_uri, :parent_reply_id, :in_reply_to_uri, :content_html, :content_text, :content_hash, :moderation_state, :lifecycle_state, :remote_published_at, :remote_updated_at, NULL, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $stmt->execute([
        'remote_actor_id' => (int)$remoteActor['id'], 'actor_uri' => $actorUri, 'remote_object_uri' => $objectUri,
        'create_activity_uri' => $activityUri, 'last_activity_uri' => $activityUri, 'created_receipt_id' => $receiptId,
        'last_receipt_id' => $receiptId, 'target_post_id' => (int)$local['post_id'],
        'target_generation' => (int)$local['publication_generation'], 'target_object_uri' => (string)$local['object_uri'],
        'parent_reply_id' => is_array($parent) ? (int)$parent['id'] : null, 'in_reply_to_uri' => $inReplyTo,
        'content_html' => (string)$content['html'], 'content_text' => (string)$content['text'],
        'content_hash' => hash('sha256', (string)$content['html']), 'moderation_state' => $retired ? 'target_retired' : 'pending',
        'lifecycle_state' => 'active', 'remote_published_at' => bms_activitypub_remote_datetime($note['published'] ?? null),
        'remote_updated_at' => bms_activitypub_remote_datetime($note['updated'] ?? null),
    ]);
    return $retired ? 'reply_target_retired' : 'reply_pending';
}

function bms_activitypub_process_reply_update(array $activity, int $receiptId): string
{
    $actorUri = bms_activitypub_actor_reference($activity['actor'] ?? null);
    $note = $activity['object'] ?? null;
    if (!is_array($note) || array_is_list($note) || strcasecmp(trim((string)($note['type'] ?? '')), 'Note') !== 0) {
        throw new BmsActivityPubSecurityException('Update must contain a remote Note.', 400);
    }
    if (!hash_equals($actorUri, bms_activitypub_note_actor($note))) {
        throw new BmsActivityPubSecurityException('The authenticated actor does not own the updated Note.', 403);
    }
    $objectUri = bms_activitypub_identifier_uri((string)($note['id'] ?? ''), false);
    $reply = bms_activitypub_remote_reply_by_uri($objectUri, true);
    if (!is_array($reply)) {
        return 'reply_update_unmatched';
    }
    if (!hash_equals((string)$reply['actor_uri'], $actorUri)) {
        throw new BmsActivityPubSecurityException('A remote actor cannot update another actor\'s reply.', 403);
    }
    if ((string)$reply['lifecycle_state'] === 'deleted') {
        return 'reply_update_after_delete';
    }
    if (isset($note['inReplyTo']) && !hash_equals((string)$reply['in_reply_to_uri'], bms_activitypub_identifier_uri(bms_activitypub_target_object_id($note['inReplyTo']), false))) {
        throw new BmsActivityPubSecurityException('A remote reply cannot change its parent or publication generation.', 400);
    }
    $content = bms_activitypub_sanitize_remote_html(is_string($note['content'] ?? null) ? (string)$note['content'] : '');
    $hash = hash('sha256', (string)$content['html']);
    $activityUri = bms_activitypub_identifier_uri((string)$activity['id'], true);
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('activitypub_remote_replies') . ' SET last_activity_uri = :activity_uri, last_receipt_id = :receipt_id, content_html = :content_html, content_text = :content_text, content_hash = :content_hash, remote_updated_at = :remote_updated_at, updated_at = UTC_TIMESTAMP() WHERE id = :id');
    $stmt->execute(['activity_uri' => $activityUri, 'receipt_id' => $receiptId, 'content_html' => (string)$content['html'], 'content_text' => (string)$content['text'], 'content_hash' => $hash, 'remote_updated_at' => bms_activitypub_remote_datetime($note['updated'] ?? null), 'id' => (int)$reply['id']]);
    return hash_equals((string)$reply['content_hash'], $hash) ? 'reply_update_unchanged' : 'reply_updated';
}

function bms_activitypub_process_reply_delete(array $activity, int $receiptId): string
{
    $actorUri = bms_activitypub_actor_reference($activity['actor'] ?? null);
    $objectUri = bms_activitypub_identifier_uri(bms_activitypub_target_object_id($activity['object'] ?? null), false);
    $reply = bms_activitypub_remote_reply_by_uri($objectUri, true);
    if (!is_array($reply)) {
        return 'reply_delete_unmatched';
    }
    if (!hash_equals((string)$reply['actor_uri'], $actorUri)) {
        throw new BmsActivityPubSecurityException('A remote actor cannot delete another actor\'s reply.', 403);
    }
    if ((string)$reply['lifecycle_state'] === 'deleted') {
        return 'reply_delete_duplicate';
    }
    $stmt = bms_db()->prepare("UPDATE " . bms_table('activitypub_remote_replies') . " SET lifecycle_state = 'deleted', last_activity_uri = :activity_uri, last_receipt_id = :receipt_id, deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id");
    $stmt->execute(['activity_uri' => bms_activitypub_identifier_uri((string)$activity['id'], true), 'receipt_id' => $receiptId, 'id' => (int)$reply['id']]);
    return 'reply_deleted';
}

function bms_activitypub_interaction_semantic_key(string $actorUri, string $type, string $targetUri): string
{
    return hash('sha256', $actorUri . "\n" . strtolower($type) . "\n" . $targetUri);
}

function bms_activitypub_process_remote_interaction(array $activity, array $remoteActor, int $receiptId, string $type): string
{
    $actorUri = bms_activitypub_actor_reference($activity['actor'] ?? null);
    $targetUri = bms_activitypub_identifier_uri(bms_activitypub_target_object_id($activity['object'] ?? null), false);
    $local = bms_activitypub_local_generation_by_uri($targetUri);
    if (!is_array($local)) {
        return strtolower($type) . '_unknown_target';
    }
    $activityUri = bms_activitypub_identifier_uri((string)$activity['id'], true);
    $semanticKey = bms_activitypub_interaction_semantic_key($actorUri, $type, $targetUri);
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('activitypub_remote_interactions') . ' WHERE semantic_key = :semantic_key LIMIT 1 FOR UPDATE');
    $stmt->execute(['semantic_key' => $semanticKey]);
    $interaction = $stmt->fetch();
    $retired = !empty($local['deleted_at']);
    $ledgerState = $retired ? 'retired' : 'active';
    if (!is_array($interaction)) {
        $state = $retired ? 'target_retired' : 'active';
        $insert = bms_db()->prepare('INSERT INTO ' . bms_table('activitypub_remote_interactions') . ' (semantic_key, interaction_type, remote_actor_id, actor_uri, target_post_id, target_publication_generation, target_object_uri, current_activity_uri, state, activity_at, undone_at, created_at, updated_at) VALUES (:semantic_key, :interaction_type, :remote_actor_id, :actor_uri, :target_post_id, :target_generation, :target_object_uri, :activity_uri, :state, :activity_at, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $insert->execute(['semantic_key' => $semanticKey, 'interaction_type' => $type, 'remote_actor_id' => (int)$remoteActor['id'], 'actor_uri' => $actorUri, 'target_post_id' => (int)$local['post_id'], 'target_generation' => (int)$local['publication_generation'], 'target_object_uri' => $targetUri, 'activity_uri' => $activityUri, 'state' => $state, 'activity_at' => bms_activitypub_remote_datetime($activity['published'] ?? null)]);
        $interactionId = (int)bms_db()->lastInsertId();
    } else {
        $interactionId = (int)$interaction['id'];
        if ($retired) {
            $ledgerState = 'retired';
        } elseif ((string)$interaction['state'] === 'undone') {
            $update = bms_db()->prepare("UPDATE " . bms_table('activitypub_remote_interactions') . " SET current_activity_uri = :activity_uri, state = 'active', activity_at = :activity_at, undone_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id");
            $update->execute(['activity_uri' => $activityUri, 'activity_at' => bms_activitypub_remote_datetime($activity['published'] ?? null), 'id' => $interactionId]);
        } else {
            $ledgerState = 'duplicate';
        }
    }
    $ledger = bms_db()->prepare('INSERT INTO ' . bms_table('activitypub_interaction_log') . ' (interaction_id, remote_actor_id, actor_uri, activity_uri, activity_type, receipt_id, state, undo_activity_uri, activity_at, undone_at, created_at, updated_at) VALUES (:interaction_id, :remote_actor_id, :actor_uri, :activity_uri, :activity_type, :receipt_id, :state, NULL, :activity_at, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $ledger->execute(['interaction_id' => $interactionId, 'remote_actor_id' => (int)$remoteActor['id'], 'actor_uri' => $actorUri, 'activity_uri' => $activityUri, 'activity_type' => $type, 'receipt_id' => $receiptId, 'state' => $ledgerState, 'activity_at' => bms_activitypub_remote_datetime($activity['published'] ?? null)]);
    if ($retired) {
        return strtolower($type) . '_target_retired';
    }
    return $ledgerState === 'duplicate' ? strtolower($type) . '_duplicate' : strtolower($type) . '_recorded';
}

function bms_activitypub_process_interaction_undo(array $activity): ?string
{
    $actorUri = bms_activitypub_actor_reference($activity['actor'] ?? null);
    $object = $activity['object'] ?? null;
    $objectUri = bms_activitypub_identifier_uri(bms_activitypub_target_object_id($object), true);
    $embeddedType = is_array($object) ? trim((string)($object['type'] ?? '')) : '';
    if ($embeddedType !== '' && !in_array($embeddedType, ['Like', 'Announce'], true)) {
        return null;
    }
    if (is_array($object) && !hash_equals($actorUri, bms_activitypub_actor_reference($object['actor'] ?? null))) {
        throw new BmsActivityPubSecurityException('The Undo actor does not own the referenced interaction.', 403);
    }
    $stmt = bms_db()->prepare('SELECT ia.*, i.current_activity_uri, i.state AS interaction_state FROM ' . bms_table('activitypub_interaction_log') . ' ia INNER JOIN ' . bms_table('activitypub_remote_interactions') . ' i ON i.id = ia.interaction_id WHERE ia.activity_uri = :activity_uri LIMIT 1 FOR UPDATE');
    $stmt->execute(['activity_uri' => $objectUri]);
    $ledger = $stmt->fetch();
    if (!is_array($ledger)) {
        return null;
    }
    if (!hash_equals((string)$ledger['actor_uri'], $actorUri)) {
        throw new BmsActivityPubSecurityException('A remote actor cannot Undo another actor\'s interaction.', 403);
    }
    if ((string)$ledger['state'] !== 'active' || !hash_equals((string)$ledger['current_activity_uri'], $objectUri) || (string)$ledger['interaction_state'] !== 'active') {
        return strtolower((string)$ledger['activity_type']) . '_undo_inactive';
    }
    $undoUri = bms_activitypub_identifier_uri((string)$activity['id'], true);
    $update = bms_db()->prepare("UPDATE " . bms_table('activitypub_interaction_log') . " SET state = 'undone', undo_activity_uri = :undo_uri, undone_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id");
    $update->execute(['undo_uri' => $undoUri, 'id' => (int)$ledger['id']]);
    $aggregate = bms_db()->prepare("UPDATE " . bms_table('activitypub_remote_interactions') . " SET state = 'undone', undone_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id");
    $aggregate->execute(['id' => (int)$ledger['interaction_id']]);
    return strtolower((string)$ledger['activity_type']) . '_undone';
}

function bms_activitypub_result_is_ignored(string $result): bool
{
    return $result === 'unsupported_activity'
        || str_contains($result, '_unknown_target') || str_contains($result, '_target_retired')
        || str_contains($result, '_unmatched') || str_contains($result, '_after_delete')
        || str_contains($result, '_duplicate') || str_contains($result, '_inactive')
        || str_contains($result, 'remote_note_not_followed')
        || in_array($result, ['reply_parent_deleted', 'blocked_actor'], true);
}

function bms_activitypub_remote_reply_rows(string $state = '', int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $where = $state !== '' ? ' WHERE r.moderation_state = :state' : '';
    $stmt = bms_db()->prepare('SELECT r.*, a.preferred_username, a.display_name, p.slug AS post_slug, p.title AS post_title FROM ' . bms_table('activitypub_remote_replies') . ' r INNER JOIN ' . bms_table('activitypub_remote_actors') . ' a ON a.id = r.remote_actor_id LEFT JOIN ' . bms_table('posts') . ' p ON p.id = r.target_post_id' . $where . ' ORDER BY r.updated_at DESC, r.id DESC LIMIT ' . $limit);
    $stmt->execute($state !== '' ? ['state' => $state] : []);
    return $stmt->fetchAll() ?: [];
}

function bms_activitypub_moderate_remote_reply(int $replyId, string $action, int $moderatorUserId): string
{
    $state = match (strtolower(trim($action))) {
        'approve' => 'approved', 'pending' => 'pending', 'reject' => 'rejected', 'hide' => 'hidden',
        default => throw new InvalidArgumentException('Unsupported remote reply moderation action.'),
    };
    $stmt = bms_db()->prepare('UPDATE ' . bms_table('activitypub_remote_replies') . ' SET moderation_state = :state, moderated_at = UTC_TIMESTAMP(), moderator_user_id = :moderator_user_id, updated_at = UTC_TIMESTAMP() WHERE id = :id AND lifecycle_state = :lifecycle_state AND moderation_state <> :retired_state');
    $stmt->execute(['state' => $state, 'moderator_user_id' => $moderatorUserId > 0 ? $moderatorUserId : null, 'id' => $replyId, 'lifecycle_state' => 'active', 'retired_state' => 'target_retired']);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('The active remote reply was not available for moderation.');
    }
    return $state;
}

function bms_activitypub_block_actor(string $actorUri, string $reason = ''): void
{
    $actorUri = bms_activitypub_identifier_uri($actorUri, false);
    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('activitypub_blocks') . ' (block_type, block_value, reason, created_at, updated_at) VALUES (:block_type, :block_value, :reason, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE reason = VALUES(reason), updated_at = UTC_TIMESTAMP()');
    $stmt->execute(['block_type' => 'actor', 'block_value' => $actorUri, 'reason' => bms_activitypub_remote_plain_text($reason, 255)]);
    bms_db()->prepare("UPDATE " . bms_table('activitypub_remote_replies') . " SET moderation_state = 'hidden', moderated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE actor_uri = :actor_uri AND lifecycle_state = 'active'")->execute(['actor_uri' => $actorUri]);
    bms_db()->prepare("UPDATE " . bms_table('activitypub_remote_interactions') . " SET state = 'blocked', updated_at = UTC_TIMESTAMP() WHERE actor_uri = :actor_uri AND state = 'active'")->execute(['actor_uri' => $actorUri]);
    bms_db()->prepare("UPDATE " . bms_table('activitypub_followers') . " SET state = 'blocked', moderated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE actor_uri = :actor_uri AND state <> 'blocked'")->execute(['actor_uri' => $actorUri]);
    if (bms_database_table_exists(bms_db(), bms_table('activitypub_remote_objects'))) {
        bms_db()->prepare("UPDATE " . bms_table('activitypub_following') . " SET state = 'removed', state_changed_at = UTC_TIMESTAMP(), removed_at = UTC_TIMESTAMP(), last_error = 'Actor blocked locally.', updated_at = UTC_TIMESTAMP() WHERE actor_uri = :actor_uri AND state <> 'removed'")->execute(['actor_uri' => $actorUri]);
        bms_db()->prepare("UPDATE " . bms_table('activitypub_remote_objects') . " SET lifecycle_state = 'blocked', updated_at = UTC_TIMESTAMP() WHERE actor_uri = :actor_uri AND lifecycle_state = 'active'")->execute(['actor_uri' => $actorUri]);
        bms_activitypub_cancel_blocked_owner_deliveries($actorUri, 'Actor blocked locally.');
    }
}

function bms_activitypub_block_domain_for_actor(string $actorUri, string $reason = ''): string
{
    $actorUri = bms_activitypub_identifier_uri($actorUri, false);
    $domain = bms_activitypub_actor_domain($actorUri);
    if ($domain === '') {
        throw new InvalidArgumentException('The remote actor domain is invalid.');
    }
    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('activitypub_blocks') . ' (block_type, block_value, reason, created_at, updated_at) VALUES (:block_type, :block_value, :reason, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE reason = VALUES(reason), updated_at = UTC_TIMESTAMP()');
    $stmt->execute(['block_type' => 'domain', 'block_value' => $domain, 'reason' => bms_activitypub_remote_plain_text($reason, 255)]);
    $actors = bms_db()->query('SELECT actor_uri FROM ' . bms_table('activitypub_remote_actors'));
    foreach ($actors ? ($actors->fetchAll(PDO::FETCH_COLUMN) ?: []) : [] as $candidate) {
        $candidate = (string)$candidate;
        if (hash_equals($domain, bms_activitypub_actor_domain($candidate))) {
            bms_db()->prepare("UPDATE " . bms_table('activitypub_remote_replies') . " SET moderation_state = 'hidden', moderated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE actor_uri = :actor_uri AND lifecycle_state = 'active'")->execute(['actor_uri' => $candidate]);
            bms_db()->prepare("UPDATE " . bms_table('activitypub_remote_interactions') . " SET state = 'blocked', updated_at = UTC_TIMESTAMP() WHERE actor_uri = :actor_uri AND state = 'active'")->execute(['actor_uri' => $candidate]);
            bms_db()->prepare("UPDATE " . bms_table('activitypub_followers') . " SET state = 'blocked', moderated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE actor_uri = :actor_uri AND state <> 'blocked'")->execute(['actor_uri' => $candidate]);
            if (bms_database_table_exists(bms_db(), bms_table('activitypub_remote_objects'))) {
                bms_db()->prepare("UPDATE " . bms_table('activitypub_following') . " SET state = 'removed', state_changed_at = UTC_TIMESTAMP(), removed_at = UTC_TIMESTAMP(), last_error = 'Domain blocked locally.', updated_at = UTC_TIMESTAMP() WHERE actor_uri = :actor_uri AND state <> 'removed'")->execute(['actor_uri' => $candidate]);
                bms_db()->prepare("UPDATE " . bms_table('activitypub_remote_objects') . " SET lifecycle_state = 'blocked', updated_at = UTC_TIMESTAMP() WHERE actor_uri = :actor_uri AND lifecycle_state = 'active'")->execute(['actor_uri' => $candidate]);
                bms_activitypub_cancel_blocked_owner_deliveries($candidate, 'Domain blocked locally.');
            }
        }
    }
    return $domain;
}

function bms_activitypub_cancel_blocked_owner_deliveries(string $actorUri, string $reason): void
{
    $stmt = bms_db()->prepare('SELECT id FROM ' . bms_table('activitypub_remote_actors') . ' WHERE actor_uri = :actor_uri LIMIT 1');
    $stmt->execute(['actor_uri' => $actorUri]);
    $actorId = (int)$stmt->fetchColumn();
    if ($actorId < 1) {
        return;
    }
    $message = bms_activitypub_remote_plain_text($reason, 255);
    $rows = bms_db()->query("SELECT id, activity_uri, recipient_actor_ids_json FROM " . bms_table('activitypub_deliveries') . " WHERE delivery_type = 'owner_activity' AND event_id IS NULL AND status IN ('pending', 'retry')");
    $activityUris = [];
    foreach ($rows ? ($rows->fetchAll() ?: []) : [] as $row) {
        $recipientIds = json_decode((string)($row['recipient_actor_ids_json'] ?? '[]'), true);
        if (!is_array($recipientIds) || !in_array($actorId, array_map('intval', $recipientIds), true)) {
            continue;
        }
        $cancel = bms_db()->prepare("UPDATE " . bms_table('activitypub_deliveries') . " SET status = 'dead', last_error = :last_error, updated_at = UTC_TIMESTAMP() WHERE id = :id AND status IN ('pending', 'retry')");
        $cancel->execute(['last_error' => $message, 'id' => (int)$row['id']]);
        $activityUris[(string)$row['activity_uri']] = true;
    }
    foreach (array_keys($activityUris) as $activityUri) {
        if (function_exists('bms_activitypub_mark_owner_delivery_result')) {
            bms_activitypub_mark_owner_delivery_result(['activity_uri' => $activityUri], 'failed', $message);
        }
    }
}

function bms_activitypub_approved_replies_for_post(int $postId, int $generation): array
{
    if ($postId < 1 || $generation < 1) {
        return [];
    }
    $stmt = bms_db()->prepare("SELECT r.*, a.preferred_username, a.display_name FROM " . bms_table('activitypub_remote_replies') . " r INNER JOIN " . bms_table('activitypub_remote_actors') . " a ON a.id = r.remote_actor_id WHERE r.target_post_id = :post_id AND r.target_publication_generation = :generation AND r.moderation_state = 'approved' AND r.lifecycle_state = 'active' ORDER BY r.created_at ASC, r.id ASC");
    $stmt->execute(['post_id' => $postId, 'generation' => $generation]);
    return $stmt->fetchAll() ?: [];
}

function bms_activitypub_current_local_generation_for_post(int $postId): ?array
{
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('activitypub_local_objects') . ' WHERE post_id = :post_id AND deleted_at IS NULL ORDER BY publication_generation DESC LIMIT 1');
    $stmt->execute(['post_id' => $postId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bms_activitypub_federated_interaction_count(int $postId, int $generation, string $type): int
{
    if (!in_array($type, ['Like', 'Announce'], true)) {
        return 0;
    }
    $stmt = bms_db()->prepare("SELECT COUNT(*) FROM " . bms_table('activitypub_remote_interactions') . " WHERE target_post_id = :post_id AND target_publication_generation = :generation AND interaction_type = :interaction_type AND state = 'active'");
    $stmt->execute(['post_id' => $postId, 'generation' => $generation, 'interaction_type' => $type]);
    return (int)$stmt->fetchColumn();
}
