<?php
/** System presentation only. Security evaluation and scheduler behavior stay in core. */
function bms_system_check_summary(?array $checks): array
{
    $counts = ['pass' => 0, 'warning' => 0, 'fail' => 0];
    foreach ($checks ?? [] as $check) {
        $status = strtolower((string)($check['status'] ?? 'warning'));
        $counts[$status === 'pass' ? 'pass' : (in_array($status, ['fail', 'error'], true) ? 'fail' : 'warning')]++;
    }
    $label = $checks === null ? 'Checks unavailable' : ($checks === [] ? 'No check results' : ($counts['fail'] > 0 ? 'Needs attention' : ($counts['warning'] > 0 ? 'Review warnings' : 'Healthy')));
    $class = $counts['fail'] > 0 ? 'is-destructive' : ($counts['warning'] > 0 ? 'is-warning' : (empty($checks) ? '' : 'is-safe'));
    return ['counts' => $counts, 'label' => $label, 'class' => $class];
}

function bms_system_read_checks(): ?array
{
    if (!function_exists('bms_security_status')) {
        return null;
    }
    try {
        return bms_security_status();
    } catch (Throwable $e) {
        // Do not expose exception details or turn missing results into an all-clear.
        return null;
    }
}
