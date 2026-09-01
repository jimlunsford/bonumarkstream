<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/scheduler.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = strtolower(trim((string)($_POST['activitypub_action'] ?? '')));
    try {
        if ($action === 'save') {
            $enabled = isset($_POST['activitypub_enabled']);
            $policy = (string)($_POST['activitypub_follow_policy'] ?? 'manual');
            if (!in_array($policy, ['manual', 'automatic'], true)) {
                $policy = 'manual';
            }
            if ($enabled) {
                $url = bms_activitypub_configured_base_url();
                $routing = bms_activitypub_webfinger_routing_capability();
                if (empty($url['ok']) || empty($routing['ok']) || !is_array(bms_activitypub_public_owner_user())) {
                    throw new RuntimeException('ActivityPub cannot be enabled until the canonical HTTPS URL, root WebFinger routing, and public owner Profile are ready.');
                }
            }
            bms_set_setting('activitypub_follow_policy', $policy);
            bms_set_setting('activitypub_enabled', $enabled ? '1' : '0');
            bms_flash('ActivityPub settings saved.', 'success');
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
        } elseif ($action === 'follow_actor') {
            bms_activitypub_follow_remote_actor((string)($_POST['actor_uri'] ?? ''));
            bms_flash('The signed Follow is queued. The relationship remains pending until the remote actor accepts or rejects it.', 'success');
        } elseif ($action === 'unfollow_actor') {
            bms_activitypub_unfollow_remote_actor(max(0, (int)($_POST['following_id'] ?? 0)));
            bms_flash('The Following relationship was removed and its exact Undo Follow is queued.', 'success');
        } elseif ($action === 'owner_like' || $action === 'owner_announce') {
            $type = $action === 'owner_like' ? 'Like' : 'Announce';
            bms_activitypub_owner_interact($type, (string)($_POST['object_uri'] ?? ''));
            bms_flash($type . ' queued for signed delivery.', 'success');
        } elseif ($action === 'undo_owner_interaction') {
            bms_activitypub_owner_undo_interaction(max(0, (int)($_POST['interaction_id'] ?? 0)));
            bms_flash('The exact owner interaction Undo is queued.', 'success');
        } elseif ($action === 'owner_reply') {
            $reply = bms_activitypub_create_owner_reply_draft(
                (string)($_POST['object_uri'] ?? ''),
                (string)($_POST['reply_body'] ?? ''),
                (string)($_POST['reply_title'] ?? '')
            );
            bms_flash('A normal Bonumark Stream draft was created with its remote reply target attached.', 'success');
            bms_redirect(bms_admin_url('edit.php?type=draft&file=' . rawurlencode((string)$reply['filename'])));
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
$policy = bms_activitypub_follow_policy();
$key = bms_activitypub_active_signing_key(false);
$followers = bms_activitypub_follower_rows('', 200);
$remoteReplies = bms_activitypub_remote_reply_rows('', 200);
$publicationDeliveries = bms_activitypub_publication_delivery_rows(200);
$following = bms_activitypub_following_rows(200);
$remoteInbox = bms_activitypub_remote_inbox_rows(100);
$checks = bms_activitypub_system_check_items();

bms_admin_header('ActivityPub', [bms_view_site_action()]);
?>
<section class="panel settings-workflow-hero">
  <div class="settings-workflow-hero-copy"><p class="eyebrow">Federation</p><h2>Keep Bonumark as the source of truth.</h2><p class="meta">ActivityPub is optional. Committed public Stream Post transitions are recorded locally, then delivered asynchronously without delaying or rolling back Bonumark publishing.</p></div>
  <span class="static-pill <?= $enabled ? 'generated' : 'draft' ?>"><?= $enabled ? 'ENABLED' : 'DISABLED' ?></span>
</section>

<section class="panel settings-section-panel">
  <div class="settings-record-heading"><div><p class="eyebrow">Owner participation</p><h2>Following</h2><p class="meta">Follow discovery and delivery use the existing bounded federation transport. Remote actors never become Bonumark accounts.</p></div><span class="static-pill draft"><?= count($following) ?> RECORD<?= count($following) === 1 ? '' : 'S' ?></span></div>
  <form method="post" class="settings-inline-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="follow_actor"><label for="actor_uri">Fediverse handle or actor URL</label><input id="actor_uri" name="actor_uri" type="text" required maxlength="2048" autocapitalize="none" autocomplete="off" spellcheck="false" placeholder="@name@example.com"><button type="submit" class="button-link secondary">Follow actor</button></form>
  <?php if (!$following): ?><div class="settings-empty-state"><h3>The owner is not following a remote actor.</h3></div><?php else: ?><div class="settings-record-list"><?php foreach ($following as $relationship): ?>
    <article class="settings-history-record"><div class="settings-record-cell"><strong><?= htmlspecialchars(trim((string)$relationship['display_name']) ?: trim((string)$relationship['preferred_username']) ?: 'Remote actor', ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string)$relationship['actor_uri'], ENT_QUOTES, 'UTF-8') ?></small></div><div class="settings-record-cell"><span class="static-pill <?= (string)$relationship['state'] === 'accepted' ? 'generated' : ((string)$relationship['state'] === 'pending' ? 'warning' : 'draft') ?>"><?= htmlspecialchars(strtoupper((string)$relationship['state']), ENT_QUOTES, 'UTF-8') ?></span><small><?= htmlspecialchars((string)($relationship['last_error'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></div><div class="settings-record-cell"><?php if ((string)$relationship['state'] !== 'removed'): ?><form method="post" class="settings-inline-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="unfollow_actor"><input type="hidden" name="following_id" value="<?= (int)$relationship['id'] ?>"><button type="submit" class="button-link secondary danger">Unfollow</button></form><?php endif; ?></div></article>
  <?php endforeach; ?></div><?php endif; ?>
</section>

<section class="panel settings-section-panel">
  <div class="settings-record-heading"><div><p class="eyebrow">Private owner inbox</p><h2>Remote content</h2><p class="meta">Only sanitized cached Notes from accepted Following relationships appear here. This view is private Admin state and never becomes a public timeline.</p></div><span class="static-pill draft"><?= count($remoteInbox) ?> NOTE<?= count($remoteInbox) === 1 ? '' : 'S' ?></span></div>
  <?php if (!$remoteInbox): ?><div class="settings-empty-state"><h3>No followed-actor Note is cached.</h3></div><?php else: ?><div class="settings-record-list"><?php foreach ($remoteInbox as $remoteNote): ?>
    <article class="settings-history-record">
      <div class="settings-record-cell"><strong><?= htmlspecialchars(trim((string)$remoteNote['display_name']) ?: trim((string)$remoteNote['preferred_username']) ?: 'Remote actor', ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string)$remoteNote['actor_uri'], ENT_QUOTES, 'UTF-8') ?></small><p><?= nl2br(htmlspecialchars((string)$remoteNote['content_text'], ENT_QUOTES, 'UTF-8')) ?></p><small><?= htmlspecialchars((string)$remoteNote['object_uri'], ENT_QUOTES, 'UTF-8') ?></small></div>
      <div class="settings-record-cell"><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="owner_reply"><input type="hidden" name="object_uri" value="<?= htmlspecialchars((string)$remoteNote['object_uri'], ENT_QUOTES, 'UTF-8') ?>"><label>Reply title <input name="reply_title" maxlength="255"></label><label>Reply text <textarea name="reply_body" required maxlength="2097152"></textarea></label><button type="submit" class="button-link secondary">Create reply draft</button></form></div>
      <div class="settings-record-cell"><div class="settings-inline-actions"><?php if ((string)($remoteNote['like_state'] ?? '') === 'active'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="undo_owner_interaction"><input type="hidden" name="interaction_id" value="<?= (int)($remoteNote['like_interaction_id'] ?? 0) ?>"><button type="submit" class="button-link secondary">Unlike</button></form><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="owner_like"><input type="hidden" name="object_uri" value="<?= htmlspecialchars((string)$remoteNote['object_uri'], ENT_QUOTES, 'UTF-8') ?>"><button type="submit" class="button-link secondary">Like</button></form><?php endif; ?><?php if ((string)($remoteNote['announce_state'] ?? '') === 'active'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="undo_owner_interaction"><input type="hidden" name="interaction_id" value="<?= (int)($remoteNote['announce_interaction_id'] ?? 0) ?>"><button type="submit" class="button-link secondary">Unboost</button></form><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="owner_announce"><input type="hidden" name="object_uri" value="<?= htmlspecialchars((string)$remoteNote['object_uri'], ENT_QUOTES, 'UTF-8') ?>"><button type="submit" class="button-link secondary">Boost</button></form><?php endif; ?></div><?php if (trim((string)($remoteNote['like_last_error'] ?? '')) !== ''): ?><small>Like delivery: <?= htmlspecialchars((string)$remoteNote['like_last_error'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?><?php if (trim((string)($remoteNote['announce_last_error'] ?? '')) !== ''): ?><small>Boost delivery: <?= htmlspecialchars((string)$remoteNote['announce_last_error'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></div>
    </article>
  <?php endforeach; ?></div><?php endif; ?>
</section>

<section class="panel settings-section-panel">
  <div class="settings-record-heading"><div><p class="eyebrow">Reply moderation</p><h2>Remote replies</h2><p class="meta">Remote replies remain federation-owned records. Content shown here is sanitized plain text, and protocol identifiers are escaped and bounded.</p></div><span class="static-pill draft"><?= count($remoteReplies) ?> RECORD<?= count($remoteReplies) === 1 ? '' : 'S' ?></span></div>
  <?php if (!$remoteReplies): ?><div class="settings-empty-state"><h3>No authenticated remote reply has been received.</h3><p class="meta">New remote replies default to pending review.</p></div><?php else: ?>
  <div class="settings-record-list"><?php foreach ($remoteReplies as $reply):
    $replyState = (string)($reply['moderation_state'] ?? 'pending');
    $lifecycleState = (string)($reply['lifecycle_state'] ?? 'active');
    $replyActorName = trim((string)($reply['display_name'] ?? '')) ?: trim((string)($reply['preferred_username'] ?? '')) ?: 'Remote actor';
    $replyPostTitle = trim((string)($reply['post_title'] ?? '')) ?: ('Post #' . (int)($reply['target_post_id'] ?? 0));
  ?>
    <article class="settings-history-record">
      <div class="settings-record-cell"><strong><?= htmlspecialchars($replyActorName, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string)$reply['actor_uri'], ENT_QUOTES, 'UTF-8') ?></small><p><?= nl2br(htmlspecialchars((string)($reply['content_text'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p></div>
      <div class="settings-record-cell"><strong><?= htmlspecialchars($replyPostTitle, ENT_QUOTES, 'UTF-8') ?></strong><small>Post #<?= (int)$reply['target_post_id'] ?> · Generation <?= (int)$reply['target_publication_generation'] ?></small><small><?= htmlspecialchars((string)$reply['target_object_uri'], ENT_QUOTES, 'UTF-8') ?></small></div>
      <div class="settings-record-cell"><span class="static-pill <?= $replyState === 'approved' && $lifecycleState === 'active' ? 'generated' : ($replyState === 'pending' ? 'warning' : 'draft') ?>"><?= htmlspecialchars(strtoupper($lifecycleState === 'deleted' ? 'deleted' : $replyState), ENT_QUOTES, 'UTF-8') ?></span><small>Object: <?= htmlspecialchars((string)$reply['remote_object_uri'], ENT_QUOTES, 'UTF-8') ?></small><small>Last activity: <?= htmlspecialchars((string)$reply['last_activity_uri'], ENT_QUOTES, 'UTF-8') ?></small></div>
      <div class="settings-record-cell">
        <?php if ($lifecycleState === 'active' && $replyState !== 'target_retired'): ?><form method="post" class="settings-inline-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="moderate_reply"><input type="hidden" name="reply_id" value="<?= (int)$reply['id'] ?>"><button type="submit" name="moderation" value="approve" class="button-link secondary">Approve</button><button type="submit" name="moderation" value="pending" class="button-link secondary">Pending</button><button type="submit" name="moderation" value="reject" class="button-link secondary">Reject</button><button type="submit" name="moderation" value="hide" class="button-link secondary danger">Hide</button></form><?php endif; ?>
        <form method="post" class="settings-inline-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="block_reply_actor"><input type="hidden" name="actor_uri" value="<?= htmlspecialchars((string)$reply['actor_uri'], ENT_QUOTES, 'UTF-8') ?>"><button type="submit" class="button-link secondary danger">Block actor</button></form>
        <form method="post" class="settings-inline-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="block_reply_domain"><input type="hidden" name="actor_uri" value="<?= htmlspecialchars((string)$reply['actor_uri'], ENT_QUOTES, 'UTF-8') ?>"><button type="submit" class="button-link secondary danger">Block domain</button></form>
      </div>
    </article>
  <?php endforeach; ?></div><?php endif; ?>
</section>

<section class="panel settings-section-panel">
  <div class="settings-section-header"><div><p class="eyebrow">Configuration</p><h2>Identity and follower policy</h2><p class="meta">Manual approval is the safer default. Automatic approval queues a signed Accept as soon as a valid Follow is processed.</p></div></div>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="activitypub_action" value="save">
    <div class="settings-option-list"><label class="settings-option-card"><input type="checkbox" name="activitypub_enabled" value="1" <?= $enabled ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Enable ActivityPub</strong><small>When disabled, federation routes return not found and no response delivery work runs.</small></span></label></div>
    <div class="settings-field-grid"><div class="settings-field-card"><label for="activitypub_follow_policy">Follower approval</label><select id="activitypub_follow_policy" name="activitypub_follow_policy"><option value="manual" <?= $policy === 'manual' ? 'selected' : '' ?>>Manual approval</option><option value="automatic" <?= $policy === 'automatic' ? 'selected' : '' ?>>Automatic approval</option></select></div></div>
    <div class="settings-save-bar"><div><strong>Save federation settings</strong><p class="meta">Changing this setting does not publish, import, export, or deliver posts.</p></div><button type="submit">Save ActivityPub Settings</button></div>
  </form>
</section>

<section class="panel settings-section-panel">
  <div class="settings-section-header"><div><p class="eyebrow">Signing identity</p><h2>HTTP signing key</h2><p class="meta">The private key stays encrypted in application storage and is never displayed. Provisioning a new key retires the previous key.</p></div><span class="static-pill <?= is_array($key) ? 'generated' : 'warning' ?>"><?= is_array($key) ? 'ACTIVE' : 'MISSING' ?></span></div>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="provision_key"><button type="submit" class="button-link secondary"><?= is_array($key) ? 'Rotate Signing Key' : 'Provision Signing Key' ?></button></form>
</section>

<section class="panel settings-section-panel">
  <div class="settings-record-heading"><div><p class="eyebrow">Readiness</p><h2>Federation checks</h2></div></div>
  <div class="settings-record-list"><?php foreach ($checks as $check): ?><article class="settings-history-record"><div class="settings-record-cell"><strong><?= htmlspecialchars((string)$check['label'], ENT_QUOTES, 'UTF-8') ?></strong></div><div class="settings-record-cell"><span class="static-pill <?= (string)$check['status'] === 'pass' ? 'generated' : ((string)$check['status'] === 'warn' ? 'warning' : 'draft') ?>"><?= htmlspecialchars(strtoupper((string)$check['status']), ENT_QUOTES, 'UTF-8') ?></span></div><div class="settings-record-cell"><?= htmlspecialchars((string)$check['message'], ENT_QUOTES, 'UTF-8') ?></div></article><?php endforeach; ?></div>
</section>

<section class="panel settings-section-panel">
  <div class="settings-record-heading"><div><p class="eyebrow">Moderation</p><h2>Remote followers</h2><p class="meta">Only accepted actors appear in the public followers collection. Remote values are escaped and never executed.</p></div><span class="static-pill draft"><?= count($followers) ?> RECORD<?= count($followers) === 1 ? '' : 'S' ?></span></div>
  <?php if (!$followers): ?><div class="settings-empty-state"><h3>No signed Follow has been received.</h3></div><?php else: ?>
  <div class="settings-record-list"><?php foreach ($followers as $follower): ?>
    <article class="settings-history-record">
      <div class="settings-record-cell"><strong><?= htmlspecialchars(trim((string)$follower['display_name']) ?: trim((string)$follower['preferred_username']) ?: 'Remote actor', ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string)$follower['actor_uri'], ENT_QUOTES, 'UTF-8') ?></small></div>
      <div class="settings-record-cell"><span class="static-pill <?= (string)$follower['state'] === 'accepted' ? 'generated' : ((string)$follower['state'] === 'pending' ? 'warning' : 'draft') ?>"><?= htmlspecialchars(strtoupper((string)$follower['state']), ENT_QUOTES, 'UTF-8') ?></span></div>
      <div class="settings-record-cell"><form method="post" class="settings-inline-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="moderate"><input type="hidden" name="follower_id" value="<?= (int)$follower['id'] ?>"><button type="submit" name="moderation" value="approve" class="button-link secondary">Approve</button><button type="submit" name="moderation" value="reject" class="button-link secondary">Reject</button><button type="submit" name="moderation" value="block" class="button-link secondary danger">Block</button><button type="submit" name="moderation" value="remove" class="button-link secondary">Remove</button></form></div>
    </article>
  <?php endforeach; ?></div><?php endif; ?>
</section>

<section class="panel settings-section-panel">
  <div class="settings-record-heading"><div><p class="eyebrow">Publication delivery</p><h2>Outbound federation</h2><p class="meta">Each row belongs to one durable local activity. Retries reuse that activity and never recreate the post or expose signing material.</p></div><span class="static-pill draft"><?= count($publicationDeliveries) ?> RECORD<?= count($publicationDeliveries) === 1 ? '' : 'S' ?></span></div>
  <?php if (!$publicationDeliveries): ?><div class="settings-empty-state"><h3>No publication delivery has been queued.</h3><p class="meta">Existing historical posts remain discoverable through the outbox but are not backfilled to followers.</p></div><?php else: ?>
  <div class="settings-record-list"><?php foreach ($publicationDeliveries as $delivery):
    $deliveryStatus = (string)($delivery['status'] ?? 'pending');
    $lastError = trim((string)($delivery['last_error'] ?? ''));
  ?>
    <article class="settings-history-record">
      <div class="settings-record-cell"><strong><?= htmlspecialchars(ucfirst((string)($delivery['event_type'] ?? 'publication')) . ' post #' . (int)($delivery['post_id'] ?? 0), ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string)($delivery['inbox_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></div>
      <div class="settings-record-cell"><span class="static-pill <?= $deliveryStatus === 'delivered' ? 'generated' : ($deliveryStatus === 'dead' ? 'draft' : 'warning') ?>"><?= htmlspecialchars(strtoupper($deliveryStatus), ENT_QUOTES, 'UTF-8') ?></span><small>Attempts: <?= (int)($delivery['attempt_count'] ?? 0) ?><?php if ((int)($delivery['http_status'] ?? 0) > 0): ?> · HTTP <?= (int)$delivery['http_status'] ?><?php endif; ?></small></div>
      <div class="settings-record-cell"><?php if ($lastError !== ''): ?><small><?= htmlspecialchars($lastError, ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?><?php if (in_array($deliveryStatus, ['retry', 'dead'], true)): ?><form method="post" class="settings-inline-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="retry_publication"><input type="hidden" name="delivery_id" value="<?= (int)$delivery['id'] ?>"><button type="submit" class="button-link secondary">Retry safely</button></form><?php endif; ?></div>
    </article>
  <?php endforeach; ?></div><?php endif; ?>
</section>
<?php bms_admin_footer(); ?>
