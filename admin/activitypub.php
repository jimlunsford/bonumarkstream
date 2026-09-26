<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/scheduler.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_activitypub-ui.php';
bms_require_login();
bms_require_capability('manage_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = strtolower(trim((string)($_POST['activitypub_action'] ?? '')));
    try {
        if ($action === 'save') {
            $enabled = isset($_POST['activitypub_enabled']);
            $paused = isset($_POST['activitypub_paused']);
            $deliverySuspended = isset($_POST['activitypub_delivery_suspended']);
            $policy = (string)($_POST['activitypub_follow_policy'] ?? 'manual');
            if (!in_array($policy, ['manual', 'automatic'], true)) {
                $policy = 'manual';
            }
            if ($enabled && bms_activitypub_actor_is_retired()) {
                throw new RuntimeException('This ActivityPub actor URI is permanently retired and cannot be re-enabled.');
            }
            if ($enabled) {
                $url = bms_activitypub_configured_base_url();
                $routing = bms_activitypub_webfinger_routing_capability();
                if (empty($url['ok']) || empty($routing['ok']) || !is_array(bms_activitypub_public_owner_user())) {
                    throw new RuntimeException('ActivityPub cannot be enabled until the canonical HTTPS URL, root WebFinger routing, and public owner Profile are ready.');
                }
            } elseif (bms_activitypub_enabled() && bms_activitypub_has_federation_history()) {
                throw new RuntimeException('An established federation identity cannot be disabled as though it were unused. Pause federation instead, or use deliberate permanent deactivation.');
            }
            bms_set_setting('activitypub_follow_policy', $policy);
            bms_set_setting('activitypub_enabled', $enabled ? '1' : '0');
            bms_set_setting('activitypub_paused', $enabled && $paused ? '1' : '0');
            bms_set_setting('activitypub_delivery_suspended', $enabled && $deliverySuspended ? '1' : '0');
            if ($enabled) {
                bms_set_setting('activitypub_deactivated', '0');
            }
            bms_flash('ActivityPub settings saved.', 'success');
        } elseif ($action === 'permanent_deactivation') {
            $confirmation = trim((string)($_POST['permanent_deactivation_confirmation'] ?? ''));
            if (!hash_equals('PERMANENTLY DELETE FEDERATED ACTOR', $confirmation)) {
                throw new RuntimeException('The permanent-deactivation confirmation text did not match.');
            }
            $result = bms_activitypub_permanently_deactivate();
            bms_flash(!empty($result['idempotent']) ? 'The ActivityPub actor was already permanently retired.' : 'The ActivityPub actor is permanently retired. Its signed Actor Delete is queued for former followers.', 'success');
        } elseif ($action === 'provision_key') {
            bms_activitypub_create_signing_key();
            bms_flash('A new ActivityPub signing key is active. The prior key, if any, was retired.', 'success');
        } elseif ($action === 'moderate') {
            $followerId = max(0, (int)($_POST['follower_id'] ?? 0));
            $moderation = (string)($_POST['moderation'] ?? '');
            bms_activitypub_moderate_follower($followerId, $moderation);
            bms_flash('Follower state updated. Any required signed response is queued for the durable task runner.', 'success');
        } elseif ($action === 'moderate_reply') {
            $replyId = max(0, (int)($_POST['reply_id'] ?? 0));
            $moderation = (string)($_POST['moderation'] ?? '');
            $user = bms_current_user();
            bms_activitypub_moderate_remote_reply($replyId, $moderation, (int)($user['id'] ?? 0));
            bms_flash('Remote reply moderation state updated.', 'success');
        } elseif ($action === 'block_reply_actor') {
            $actorUri = (string)($_POST['actor_uri'] ?? '');
            bms_activitypub_block_actor($actorUri, 'Blocked from remote reply moderation.');
            bms_flash('The remote actor is blocked and its visible federation interactions are hidden.', 'success');
        } elseif ($action === 'block_reply_domain') {
            $actorUri = (string)($_POST['actor_uri'] ?? '');
            $domain = bms_activitypub_block_domain_for_actor($actorUri, 'Blocked from remote reply moderation.');
            bms_flash('The remote domain ' . $domain . ' is blocked and its visible federation interactions are hidden.', 'success');
        } elseif ($action === 'retry_publication') {
            $deliveryId = max(0, (int)($_POST['delivery_id'] ?? 0));
            if (!bms_activitypub_manual_retry_publication_delivery($deliveryId)) {
                throw new RuntimeException('The publication delivery is not eligible for manual retry.');
            }
            bms_flash('Publication delivery queued for a safe retry.', 'success');
        } elseif ($action === 'retry_delivery') {
            if (!bms_activitypub_retry_delivery(max(0, (int)($_POST['delivery_id'] ?? 0)))) {
                throw new RuntimeException('The delivery is not eligible for safe retry.');
            }
            bms_flash('The immutable delivery was queued for retry.', 'success');
        } elseif ($action === 'cancel_delivery') {
            if (!bms_activitypub_cancel_delivery(max(0, (int)($_POST['delivery_id'] ?? 0)))) {
                throw new RuntimeException('The delivery is not eligible for permanent cancellation.');
            }
            bms_flash('The delivery was permanently cancelled. Its audit row was preserved.', 'success');
        } elseif ($action === 'reconcile_queue') {
            $result = bms_activitypub_reconcile_queue();
            bms_flash('Queue reconciliation recovered ' . (int)$result['recovered'] . ' stale row(s) and cancelled ' . (int)$result['cancelled'] . ' unsafe or orphaned row(s).', 'success');
        } elseif ($action === 'cleanup_remote_cache') {
            $result = bms_activitypub_cleanup_remote_cache();
            bms_flash('Remote cache cleanup cleared ' . (int)$result['blocked_content_cleared'] . ' blocked object(s) and removed ' . (int)$result['unreferenced_objects_removed'] . ' expired, unreferenced object(s). Tombstones and referenced identities were retained.', 'success');
        } elseif ($action === 'follow_actor') {
            bms_activitypub_follow_remote_actor((string)($_POST['actor_uri'] ?? ''));
            bms_flash('The signed Follow is queued. The relationship remains pending until the remote actor accepts or rejects it.', 'success');
        } elseif ($action === 'unfollow_actor') {
            bms_activitypub_unfollow_remote_actor(max(0, (int)($_POST['following_id'] ?? 0)));
            bms_flash('The Following relationship was removed and its exact Undo Follow is queued.', 'success');
        } else {
            throw new RuntimeException('The ActivityPub action was not recognized.');
        }
    } catch (Throwable $e) {
        bms_log_admin_exception('activitypub', $e);
        bms_flash('The ActivityPub change could not be completed. Review federation readiness and try again.', 'error');
    }
    bms_redirect(bms_admin_url('activitypub.php'));
}

$enabled = bms_activitypub_enabled();
$operationalState = bms_activitypub_operational_state();
$deliverySuspended = bms_activitypub_delivery_suspended();
$retirement = bms_activitypub_actor_retirement();
$policy = bms_activitypub_follow_policy();
$key = bms_activitypub_active_signing_key(false);
$keyHealth = bms_activitypub_signing_key_health();
$keyHistory = bms_activitypub_signing_key_rows();
$pages = [];
foreach (['following', 'followers', 'replies', 'deliveries', 'operations'] as $section) {
    $pages[$section] = ['number' => bms_ap_admin_page_number($_GET[$section . '_page'] ?? 1)];
}
$pages['following'] = bms_ap_admin_page(bms_activitypub_following_rows(11, ($pages['following']['number'] - 1) * 10), $pages['following']['number']);
$pages['followers'] = bms_ap_admin_page(bms_activitypub_follower_rows('', 11, ($pages['followers']['number'] - 1) * 10), $pages['followers']['number']);
$pages['replies'] = bms_ap_admin_page(bms_activitypub_remote_reply_rows('', 11, ($pages['replies']['number'] - 1) * 10), $pages['replies']['number']);
$pages['deliveries'] = bms_ap_admin_page(bms_activitypub_publication_delivery_rows(11, ($pages['deliveries']['number'] - 1) * 10), $pages['deliveries']['number']);
$pages['operations'] = bms_ap_admin_page(bms_activitypub_operational_delivery_rows(11, ($pages['operations']['number'] - 1) * 10), $pages['operations']['number']);
$following = $pages['following']['rows'];
$followers = $pages['followers']['rows'];
$remoteReplies = $pages['replies']['rows'];
$publicationDeliveries = $pages['deliveries']['rows'];
$operationalDeliveries = $pages['operations']['rows'];
$checks = bms_activitypub_system_check_items();
$queueSummary = bms_activitypub_queue_summary();
$queueIssues = bms_activitypub_queue_issues();
$attention = bms_ap_admin_attention($checks, $queueSummary, $queueIssues);
$owner = bms_activitypub_public_owner_user() ?? [];
$actorUrl = bms_activitypub_actor_url();
$profileHandle = $owner ? '@' . substr(bms_activitypub_account_subject($owner), 5) : '';

bms_admin_header('ActivityPub', [bms_view_site_action()]);
define('BMS_ADMIN_ACTIVITYPUB_VIEW', true);
require __DIR__ . '/_activitypub-view.php';
bms_admin_footer();
