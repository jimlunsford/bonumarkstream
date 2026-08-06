<?php

function bms_comments_admin_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'Unknown';
    }

    try {
        return (new DateTimeImmutable($value, bms_site_timezone()))->format('M j, Y g:i A');
    } catch (Throwable $e) {
        return $value;
    }
}

function bms_comments_admin_status_class(string $status): string
{
    return match ($status) {
        'approved' => 'published',
        'trash' => 'trash',
        default => 'draft',
    };
}

function bms_comments_admin_return_url(string $status, string $query = ''): string
{
    $args = ['status' => $status];
    if ($query !== '') {
        $args['q'] = $query;
    }
    return bms_admin_url('comments.php?' . http_build_query($args));
}
