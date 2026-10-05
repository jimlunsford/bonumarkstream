<?php
require_once __DIR__ . '/database.php';

class BMS_Stream_Page_Out_Of_Range extends OutOfRangeException {}

/** Published rows only. No authorization or transport projection is performed here. */
function bms_stream_find_published(int $postId): ?array
{
    if ($postId <= 0) {
        throw new InvalidArgumentException('A positive Stream post ID is required.');
    }
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('posts') . ' WHERE id = :id AND post_type = :post_type AND status = :status LIMIT 1');
    $stmt->execute(['id' => $postId, 'post_type' => 'stream', 'status' => 'published']);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Explicit validated options; database failures must propagate to the adapter. */
function bms_stream_query_published(array $options = []): array
{
    $page = $options['page'] ?? 1;
    $perPage = $options['per_page'] ?? 50;
    $order = $options['order'] ?? 'asc';
    $orderby = $options['orderby'] ?? 'id';
    $modifiedAfter = $options['modified_after'] ?? '';
    if (!is_int($page) || $page < 1 || $page > 1000000
        || !is_int($perPage) || $perPage < 1 || $perPage > 100
        || !in_array($order, ['asc', 'desc'], true)
        || !in_array($orderby, ['id', 'created_at', 'updated_at', 'published_at'], true)
        || !is_string($modifiedAfter)) {
        throw new InvalidArgumentException('Invalid published Stream query options.');
    }
    $where = ['post_type = :post_type', 'status = :status'];
    $params = ['post_type' => 'stream', 'status' => 'published'];
    if ($modifiedAfter !== '') {
        $where[] = 'updated_at > :modified_after';
        $params['modified_after'] = $modifiedAfter;
    }
    $whereSql = implode(' AND ', $where);
    $count = bms_db()->prepare('SELECT COUNT(*) FROM ' . bms_table('posts') . ' WHERE ' . $whereSql);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($page > $totalPages && $total > 0) {
        throw new BMS_Stream_Page_Out_Of_Range('Requested page is outside the available catalog.');
    }
    $orderColumns = [
        'id' => 'id',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'published_at' => 'published_at',
    ];
    $offset = ($page - 1) * $perPage;
    $sql = 'SELECT * FROM ' . bms_table('posts') . ' WHERE ' . $whereSql
        . ' ORDER BY ' . $orderColumns[$orderby] . ' ' . strtoupper($order) . ', id ' . strtoupper($order)
        . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $stmt = bms_db()->prepare($sql);
    $stmt->execute($params);
    $rows = array_values(array_filter($stmt->fetchAll(), 'is_array'));
    return ['rows' => $rows, 'pagination' => [
        'page' => $page, 'per_page' => $perPage, 'returned' => count($rows),
        'total' => $total, 'total_pages' => $totalPages,
    ]];
}
