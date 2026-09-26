<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__).'/admin/_activitypub-ui.php';
function bms_csrf_token(): string { return 'fixture-csrf'; }
function bms_admin_url(string $path): string { return '/admin/'.$path; }
function bms_site_url(string $path): string { return 'https://local.test/'.$path; }
function ap_check(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
define('BMS_ADMIN_ACTIVITYPUB_VIEW',true);
set_error_handler(static function(int $severity,string $message): bool { if (error_reporting() & $severity) { throw new RuntimeException($message); } return false; });
function ap_render(bool $failed=false,bool $retired=false,array $fixture=[]): string {
    $enabled=!$retired; $operationalState=$retired?'deactivated':'active'; $deliverySuspended=false;
    $retirement=$retired?['retired_at'=>'2026-09-01 00:00:00','actor_uri'=>'https://local.test/activitypub/actor']:null;
    $owner=['display_name'=>'Owner'];$profileHandle='@owner@local.test';$actorUrl='https://local.test/activitypub/actor';$policy='manual';
    $key=['id'=>1];$keyHealth=['ok'=>true,'message'=>'Signing key is healthy.'];$keyHistory=[['id'=>1,'algorithm'=>'rsa-sha256','status'=>'active','created_at'=>'2026-01-01 00:00:00']];
    $checks=[['label'=>'Canonical URL','status'=>$failed?'fail':'pass','message'=>$failed?'Correct the URL.':'Ready.']];
    $queueSummary=[['delivery_type'=>'publication','status'=>$failed?'retry':'delivered','total'=>44,'oldest_available_at'=>'2026-01-01']];
    $queueSummary=$fixture['queueSummary']??$queueSummary;
    $queueIssues=$fixture['queueIssues']??[];
    $deliveryStatus=$fixture['deliveryStatus']??null;
    $attention=bms_ap_admin_attention($checks,$queueSummary,$queueIssues);
    $following=[['id'=>1,'display_name'=>'Remote <script>alert(1)</script>','preferred_username'=>'remote','actor_uri'=>'https://remote.test/users/long-name','state'=>'accepted','last_error'=>'']];
    $followers=$following;
    $remoteReplies=[['id'=>2,'moderation_state'=>'pending','lifecycle_state'=>'active','display_name'=>'Remote','preferred_username'=>'remote','actor_uri'=>'https://remote.test/users/long-name','post_title'=>'Local post','target_post_id'=>1,'target_publication_generation'=>2,'target_object_uri'=>'https://local.test/object/1','content_text'=>'Review this <img src=x onerror=alert(1)> reply.','remote_object_uri'=>'https://remote.test/post/2','last_activity_uri'=>'https://remote.test/activity/2']];
    $delivery=['id'=>3,'delivery_type'=>'publication','status'=>$failed?'retry':'delivered','event_type'=>'published','post_id'=>1,'inbox_url'=>'https://remote.test/inbox','activity_uri'=>'https://local.test/activity/3','attempt_count'=>1,'http_status'=>$failed?503:202,'last_error'=>$failed?'Remote server unavailable':'','updated_at'=>'2026-09-25 13:00:00'];
    $publicationDeliveries=array_fill(0,10,$delivery);$operationalDeliveries=$failed?[$delivery]:[];
    $pages=[];
    foreach (['following'=>$following,'followers'=>$followers,'replies'=>$remoteReplies,'deliveries'=>array_fill(0,11,$delivery),'operations'=>$operationalDeliveries] as $k=>$rows) { $pages[$k]=bms_ap_admin_page($rows,1); }
    ob_start();include dirname(__DIR__).'/admin/_activitypub-view.php';return ob_get_clean();
}
foreach ([false,true] as $failed) {
    $html=ap_render($failed);$doc=new DOMDocument();@$doc->loadHTML($html);$x=new DOMXPath($doc);
    ap_check($x->query('//script')->length===0 && $x->query('//img')->length===0,'Remote values must be escaped');
    $last=-1;
    foreach (['ap-profile','ap-following','ap-followers','ap-replies','ap-settings','ap-diagnostics','ap-danger'] as $id) {
        $pos=strpos($html,'id="'.$id.'"');ap_check($pos!==false && $pos>$last,'Owner workflow order: '.$id);$last=$pos;
    }
    foreach ($x->query('//form') as $form) {
        ap_check($form->getAttribute('method')==='post','Mutations remain POST');
        ap_check($x->query('.//input[@name="csrf_token"]',$form)->length===1,'Every mutation preserves CSRF');
    }
    ap_check($x->query('//section[@id="ap-deliveries"]//article')->length===10,'Publication list is bounded to ten');
    ap_check($x->query('//section[@id="ap-following"]//button[text()="Follow"]')->length===1,'Follow is a primary owner action');
    ap_check($x->query('//section[@id="ap-deliveries"]//a[contains(@href,"deliveries_page=2")]')->length===1,'Older delivery history is reachable');
    ap_check($x->query('//section[@id="ap-danger"]/parent::details[@open]')->length===0,'Danger zone starts collapsed');
    ap_check($x->query('//section[@id="ap-operations"]/parent::details[@open]')->length===($failed?1:0),'Failures expand queue diagnostics');
    ap_check($x->query('//section[contains(@class,"ap-attention")]')->length===($failed?1:0),'Failures surface above workflows');
    ap_check($x->query('//time[@datetime]')->length===10+($failed?1:0),'Delivery times have readable and machine-readable semantics');
    ap_check(str_contains($html,'PERMANENTLY DELETE FEDERATED ACTOR'),'Irreversible confirmation text is preserved');
}
// Mixed states must use each row's status, even with unrelated delivery-loop state.
$summaryRows=[];$issueRows=[];
foreach (['retry','dead','delivered','pending','processing','cancelled'] as $index=>$status) {
    $summaryRows[]=['delivery_type'=>'summary-'.$status,'status'=>$status,'total'=>7,'oldest_available_at'=>'2026-01-01'];
    $issueRows[]=['id'=>100+$index,'delivery_type'=>'publication','status'=>$status,'issue_code'=>'invalid_payload','activity_uri'=>'https://local.test/issue/'.$index];
}
foreach (['delivered','dead'] as $unrelatedStatus) {
    $html=ap_render(false,false,['queueSummary'=>$summaryRows,'queueIssues'=>$issueRows,'deliveryStatus'=>$unrelatedStatus]);
    $doc=new DOMDocument();@$doc->loadHTML($html);$x=new DOMXPath($doc);
    foreach ($summaryRows as $index=>$row) {
        $expected=in_array($row['status'],['retry','dead'],true);
        foreach ([$row['delivery_type'],'Delivery #'.(100+$index)] as $label) {
            $nodes=$x->query('//section[@id="ap-operations"]//article[.//strong[text()="'.$label.'"]]');
            ap_check($nodes->length===1,'Fixture row rendered: '.$label);
            $failedClass=in_array('ap-record-failed',explode(' ',$nodes->item(0)->getAttribute('class')),true);
            ap_check($failedClass===$expected,'Failure styling follows current row status: '.$label);
        }
    }
    ap_check(str_contains($html,'14 deliveries are retrying or failed. 6 queue issue(s).'),'Global counts remain independent of healthy delivery page');
    ap_check($x->query('//section[@id="ap-operations"]/parent::details[@open]')->length===1,'Mixed failed summary opens diagnostics');
    ap_check($x->query('//section[@id="ap-deliveries"]//article[contains(@class,"ap-record-failed")]')->length===0,'Healthy delivery rows retain healthy styling');
}
ap_check(!str_contains(ap_render(false,true),'name="permanent_deactivation_confirmation"'),'Retired identity cannot be deactivated again');
ap_check(bms_ap_admin_page_number(-1)===1 && bms_ap_admin_page_number(9999999)===100000,'Page inputs are bounded');
$pages=['deliveries'=>bms_ap_admin_page(range(1,11),2),'following'=>bms_ap_admin_page([],3)];
ob_start();bms_ap_admin_pager('deliveries',$pages);$pager=ob_get_clean();
ap_check(str_contains($pager,'deliveries_page=1') && str_contains($pager,'deliveries_page=3') && str_contains($pager,'following_page=3'),'Pagination preserves other list positions');
echo "PASS ActivityPub Admin: healthy/failure/retired views, workflow order, escaping, CSRF, disclosures, pagers, timestamps\n";
