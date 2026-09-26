<?php
/** Core owner-facing ActivityPub presentation helpers. */
function bms_ap_admin_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function bms_ap_admin_page_number($value): int { return max(1, min(100000, (int)$value)); }
function bms_ap_admin_page(array $rows, int $number): array {
    return ['number'=>$number, 'has_next'=>count($rows)>10, 'rows'=>array_slice($rows,0,10)];
}
function bms_ap_admin_pager(string $key, array $pages): void {
    $page=$pages[$key]; $query=[];
    foreach ($pages as $name=>$state) { if ($state['number']>1) { $query[$name.'_page']=$state['number']; } }
    echo '<nav class="ap-pagination" aria-label="'.bms_ap_admin_h(ucfirst($key).' pages').'">';
    foreach (['Previous'=>-1,'Next'=>1] as $label=>$step) {
        if (($step<0 && $page['number']<=1) || ($step>0 && !$page['has_next'])) { continue; }
        $next=$query; $next[$key.'_page']=$page['number']+$step;
        echo '<a class="button-link secondary" href="'.bms_ap_admin_h(bms_admin_url('activitypub.php?'.http_build_query($next)).'#ap-'.$key).'">'.$label.'</a>';
    }
    echo '<span>Page '.(int)$page['number'].' · '.count($page['rows']).' shown</span></nav>';
}
function bms_ap_admin_time(string $value): string {
    if (trim($value)==='') { return ''; }
    try { $date=new DateTimeImmutable($value,new DateTimeZone('UTC')); }
    catch (Throwable $e) { return bms_ap_admin_h($value); }
    return '<time datetime="'.bms_ap_admin_h($date->format(DATE_ATOM)).'">'.bms_ap_admin_h($date->format('M j, Y, g:i A T')).'</time>';
}
function bms_ap_admin_attention(array $checks, array $queueSummary, array $queueIssues): array {
    $failures=array_values(array_filter($checks, static fn(array $c): bool => ($c['status']??'')!=='pass'));
    $failed=0; $pending=0; $delivered=0;
    foreach ($queueSummary as $row) {
        if (in_array($row['status'],['retry','dead'],true)) { $failed+=(int)$row['total']; }
        elseif (in_array($row['status'],['pending','processing'],true)) { $pending+=(int)$row['total']; }
        elseif ($row['status']==='delivered') { $delivered+=(int)$row['total']; }
    }
    return ['checks'=>$failures,'failed'=>$failed,'pending'=>$pending,'delivered'=>$delivered,'issues'=>count($queueIssues),
        'needed'=>count($failures)>0 || $failed>0 || count($queueIssues)>0];
}
