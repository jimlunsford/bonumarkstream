<?php
require_once __DIR__ . '/database.php';

/** A failed read is never an empty queue. Keep failures local to each source. */
function bms_dashboard_pending_review(string $source): array
{
    try {
        if ($source === 'comments') {
            $sql = "SELECT COUNT(*) FROM " . bms_table('comments') . " WHERE status = 'pending'";
        } elseif ($source === 'accounts') {
            // email_verified_at is a nullable DATETIME, not a text column.
            $sql = "SELECT COUNT(*) FROM " . bms_table('users') . " WHERE role = 'commenter' AND status = 'pending' AND email_verified_at IS NOT NULL";
        } else {
            throw new InvalidArgumentException('Unknown Dashboard review source.');
        }
        $statement = bms_db()->query($sql);
        $count = $statement === false ? false : $statement->fetchColumn();
        if ($count === false || !ctype_digit((string)$count)) {
            throw new RuntimeException('Dashboard count unavailable.');
        }
        return ['state' => (int)$count > 0 ? 'populated' : 'empty', 'count' => (int)$count];
    } catch (Throwable $e) {
        error_log('Bonumark Dashboard ' . $source . ' query unavailable.');
        return ['state' => 'unavailable', 'count' => null];
    }
}

/**
 * Read the entire scheduled Stream set, including past and missing due times.
 * UTC storage is converted with the configured IANA zone at the saved date.
 * A malformed saved time remains known work requiring review, never empty.
 */
function bms_dashboard_scheduled_content(): array
{
    try {
        $statement = bms_db()->query("SELECT id, title, content_body AS body, scheduled_at FROM " . bms_table('posts') . " WHERE post_type = 'stream' AND status = 'scheduled' ORDER BY scheduled_at ASC, id ASC");
        if ($statement === false) {
            throw new RuntimeException('Dashboard schedule unavailable.');
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return ['state' => 'empty', 'count' => 0, 'next' => null, 'needs_review' => false];
        }
        $earliest = null;
        $invalid = null;
        foreach ($rows as $row) {
            $raw = (string)($row['scheduled_at'] ?? '');
            $due = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw, bms_utc_timezone());
            if ($due === false || $due->format('Y-m-d H:i:s') !== $raw || str_starts_with($raw, '0000-')) {
                $invalid = $invalid ?? $row;
                continue;
            }
            $row['due'] = $due->setTimezone(bms_site_timezone());
            if ($earliest === null || $row['due'] < $earliest['due']) {
                $earliest = $row;
            }
        }
        $next = $invalid ?? $earliest;
        return [
            'state' => 'populated', 'count' => count($rows), 'next' => $next,
            'needs_review' => $invalid !== null || $next['due']->getTimestamp() <= time(),
        ];
    } catch (Throwable $e) {
        error_log('Bonumark Dashboard scheduled content query unavailable.');
        return ['state' => 'unavailable', 'count' => null, 'next' => null, 'needs_review' => false];
    }
}
