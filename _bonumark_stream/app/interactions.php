<?php
require_once __DIR__ . '/database.php';

function mp_stream_like_cookie_name(): string
{
    return 'bms_stream_like_key';
}

function mp_stream_like_cookie_value(): string
{
    $cookieName = mp_stream_like_cookie_name();
    $existing = (string)($_COOKIE[$cookieName] ?? '');
    if (preg_match('/^[A-Fa-f0-9]{64}$/', $existing) === 1) {
        return strtolower($existing);
    }

    try {
        $value = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $value = hash('sha256', uniqid('bms-like-', true) . '|' . (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . microtime(true));
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie($cookieName, $value, [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[$cookieName] = $value;
    return $value;
}

function mp_stream_like_visitor_hash(): string
{
    $key = mp_stream_like_cookie_value();
    $salt = (string)(mp_config()['security_salt'] ?? 'bonumark-stream');
    return hash('sha256', $key . '|' . $salt);
}

function mp_stream_like_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $salt = (string)(mp_config()['security_salt'] ?? 'bonumark-stream');
    return hash('sha256', $ip . '|' . $salt);
}

function mp_stream_ensure_like_attempts_table(): void
{
    static $done = false;
    if ($done || !mp_is_installed() || !mp_has_database_config()) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS `" . mp_table('stream_like_attempts') . "` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `post_slug` VARCHAR(190) NOT NULL,
      `visitor_hash` CHAR(64) NOT NULL,
      `ip_hash` CHAR(64) NOT NULL,
      `attempted_at` DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      KEY `post_slug` (`post_slug`),
      KEY `visitor_hash` (`visitor_hash`),
      KEY `ip_hash` (`ip_hash`),
      KEY `attempted_at` (`attempted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        mp_db()->exec($sql);
        $done = true;
    } catch (Throwable $e) {
        throw new RuntimeException('Stream like rate-limit table is not available. Run the Bonumark Stream upgrade.');
    }
}

function mp_stream_like_enforce_rate_limit(string $slug, string $visitorHash): void
{
    mp_stream_ensure_like_attempts_table();
    $ipHash = mp_stream_like_ip_hash();

    try {
        mp_db()->exec('DELETE FROM ' . mp_table('stream_like_attempts') . ' WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');

        $stmt = mp_db()->prepare('SELECT COUNT(*) FROM ' . mp_table('stream_like_attempts') . ' WHERE (visitor_hash = :visitor_hash OR ip_hash = :ip_hash) AND attempted_at >= (NOW() - INTERVAL 60 SECOND)');
        $stmt->execute(['visitor_hash' => $visitorHash, 'ip_hash' => $ipHash]);
        if ((int)$stmt->fetchColumn() >= 30) {
            throw new RuntimeException('Too many like attempts. Try again in a moment.');
        }

        $insert = mp_db()->prepare('INSERT INTO ' . mp_table('stream_like_attempts') . ' (post_slug, visitor_hash, ip_hash, attempted_at) VALUES (:post_slug, :visitor_hash, :ip_hash, NOW())');
        $insert->execute(['post_slug' => mp_slugify($slug), 'visitor_hash' => $visitorHash, 'ip_hash' => $ipHash]);
    } catch (RuntimeException $e) {
        throw $e;
    } catch (Throwable $e) {
        // Likes should not fail just because the lightweight attempt log is unavailable.
    }
}


function mp_stream_like_label(int $count): string
{
    return number_format(max(0, $count)) . ' ' . ($count === 1 ? 'like' : 'likes');
}

function mp_stream_ensure_likes_table(): void
{
    static $done = false;
    if ($done || !mp_is_installed() || !mp_has_database_config()) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS `" . mp_table('stream_likes') . "` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `post_id` BIGINT UNSIGNED NOT NULL,
      `post_slug` VARCHAR(190) NOT NULL,
      `visitor_hash` CHAR(64) NOT NULL,
      `created_at` DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `post_visitor` (`post_id`, `visitor_hash`),
      KEY `post_id` (`post_id`),
      KEY `post_slug` (`post_slug`),
      KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        mp_db()->exec($sql);
        $done = true;
    } catch (Throwable $e) {
        throw new RuntimeException('Stream likes table is not available. Run the Bonumark Stream upgrade.');
    }
}

function mp_stream_post_record_by_slug(string $slug): ?array
{
    if (!mp_is_installed() || !mp_has_database_config()) {
        return null;
    }

    $slug = mp_slugify($slug);
    if ($slug === '') {
        return null;
    }

    try {
        $stmt = mp_db()->prepare('SELECT id, slug, status, post_type FROM ' . mp_table('posts') . ' WHERE slug = :slug AND status = :status AND post_type = :post_type LIMIT 1');
        $stmt->execute(['slug' => $slug, 'status' => 'published', 'post_type' => 'stream']);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function mp_stream_like_count_for_slug(string $slug): int
{
    $post = mp_stream_post_record_by_slug($slug);
    if (!$post) {
        return 0;
    }

    try {
        mp_stream_ensure_likes_table();
        $stmt = mp_db()->prepare('SELECT COUNT(*) FROM ' . mp_table('stream_likes') . ' WHERE post_id = :post_id');
        $stmt->execute(['post_id' => (int)$post['id']]);
        return max(0, (int)$stmt->fetchColumn());
    } catch (Throwable $e) {
        return 0;
    }
}

function mp_stream_visitor_liked_slug(string $slug): bool
{
    $post = mp_stream_post_record_by_slug($slug);
    if (!$post) {
        return false;
    }

    try {
        mp_stream_ensure_likes_table();
        $stmt = mp_db()->prepare('SELECT id FROM ' . mp_table('stream_likes') . ' WHERE post_id = :post_id AND visitor_hash = :visitor_hash LIMIT 1');
        $stmt->execute([
            'post_id' => (int)$post['id'],
            'visitor_hash' => mp_stream_like_visitor_hash(),
        ]);
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function mp_stream_like_status_for_slugs(array $slugs): array
{
    $cleanSlugs = [];
    foreach ($slugs as $slug) {
        $slug = mp_slugify((string)$slug);
        if ($slug !== '') {
            $cleanSlugs[$slug] = true;
        }
    }
    $cleanSlugs = array_keys($cleanSlugs);

    if (!$cleanSlugs || !mp_is_installed() || !mp_has_database_config()) {
        return [];
    }

    try {
        mp_stream_ensure_likes_table();
        $placeholders = [];
        $params = ['status' => 'published', 'post_type' => 'stream'];
        foreach ($cleanSlugs as $index => $slug) {
            $key = 'slug' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $slug;
        }

        $stmt = mp_db()->prepare('SELECT id, slug FROM ' . mp_table('posts') . ' WHERE status = :status AND post_type = :post_type AND slug IN (' . implode(',', $placeholders) . ')');
        $stmt->execute($params);
        $posts = [];
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $slug = (string)$row['slug'];
            $id = (int)$row['id'];
            $posts[$slug] = ['id' => $id, 'slug' => $slug];
            $ids[$id] = $slug;
        }

        $counts = [];
        $liked = [];
        if ($ids) {
            $idPlaceholders = [];
            $idParams = [];
            $i = 0;
            foreach (array_keys($ids) as $id) {
                $key = 'id' . $i++;
                $idPlaceholders[] = ':' . $key;
                $idParams[$key] = $id;
            }

            $countStmt = mp_db()->prepare('SELECT post_id, COUNT(*) AS total FROM ' . mp_table('stream_likes') . ' WHERE post_id IN (' . implode(',', $idPlaceholders) . ') GROUP BY post_id');
            $countStmt->execute($idParams);
            foreach ($countStmt->fetchAll() as $row) {
                $counts[(int)$row['post_id']] = (int)$row['total'];
            }

            $likedParams = $idParams;
            $likedParams['visitor_hash'] = mp_stream_like_visitor_hash();
            $likedStmt = mp_db()->prepare('SELECT post_id FROM ' . mp_table('stream_likes') . ' WHERE visitor_hash = :visitor_hash AND post_id IN (' . implode(',', $idPlaceholders) . ')');
            $likedStmt->execute($likedParams);
            foreach ($likedStmt->fetchAll() as $row) {
                $liked[(int)$row['post_id']] = true;
            }
        }

        $status = [];
        foreach ($cleanSlugs as $slug) {
            $post = $posts[$slug] ?? null;
            $count = $post ? (int)($counts[(int)$post['id']] ?? 0) : 0;
            $isLiked = $post ? !empty($liked[(int)$post['id']]) : false;
            $status[$slug] = [
                'slug' => $slug,
                'liked' => $isLiked,
                'count' => $count,
                'label' => mp_stream_like_label($count),
            ];
        }

        return $status;
    } catch (Throwable $e) {
        return [];
    }
}

function mp_stream_register_like(string $slug): array
{
    $post = mp_stream_post_record_by_slug($slug);
    if (!$post) {
        throw new RuntimeException('Stream post not found.');
    }

    $visitorHash = mp_stream_like_visitor_hash();
    mp_stream_like_enforce_rate_limit($slug, $visitorHash);

    try {
        mp_stream_ensure_likes_table();
        $stmt = mp_db()->prepare('INSERT IGNORE INTO ' . mp_table('stream_likes') . ' (post_id, post_slug, visitor_hash, created_at) VALUES (:post_id, :post_slug, :visitor_hash, NOW())');
        $stmt->execute([
            'post_id' => (int)$post['id'],
            'post_slug' => (string)$post['slug'],
            'visitor_hash' => $visitorHash,
        ]);
    } catch (Throwable $e) {
        throw new RuntimeException('Like could not be saved. Run the Bonumark Stream upgrade, then try again.');
    }

    $status = mp_stream_like_status_for_slugs([(string)$post['slug']]);
    return $status[(string)$post['slug']] ?? [
        'slug' => (string)$post['slug'],
        'liked' => true,
        'count' => mp_stream_like_count_for_slug((string)$post['slug']),
        'label' => mp_stream_like_label(mp_stream_like_count_for_slug((string)$post['slug'])),
    ];
}
