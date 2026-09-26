<?php
if (!defined('BMS_ADMIN_ACTIVITYPUB_VIEW')) { http_response_code(403); exit; }
?>
<div class="activitypub-admin">
<section class="panel settings-workflow-hero" id="ap-profile">
  <div class="settings-workflow-hero-copy">
    <p class="eyebrow">ActivityPub status</p><h2>Federated profile</h2>
    <p><strong><?= bms_ap_admin_h($owner['display_name'] ?? 'Site owner') ?></strong></p>
    <?php if ($profileHandle !== ''): ?><p><bdi><?= bms_ap_admin_h($profileHandle) ?></bdi></p><?php endif; ?>
    <p class="ap-identity"><a href="<?= bms_ap_admin_h($actorUrl) ?>"><?= bms_ap_admin_h($actorUrl) ?></a></p>
    <p class="meta">Share public Stream posts and join conversations across the fediverse. Your posts and media stay on Bonumark.</p>
  </div>
  <span class="static-pill <?= $operationalState === 'active' && !$deliverySuspended ? 'generated' : 'warning' ?>"><?= bms_ap_admin_h(strtoupper($deliverySuspended && $operationalState === 'active' ? 'delivery suspended' : $operationalState)) ?></span>
</section>
<?php if ($attention['needed']): ?>
<section class="panel ap-attention" aria-labelledby="ap-attention-title">
  <h2 id="ap-attention-title">Federation needs attention</h2>
  <p><?= count($attention['checks']) ?> check(s) need review. <?= (int)$attention['failed'] ?> deliveries are retrying or failed. <?= (int)$attention['issues'] ?> queue issue(s).</p>
  <a href="#ap-diagnostics">Review diagnostics and delivery activity</a>
</section>
<?php endif; ?>
<nav class="ap-section-nav" aria-label="ActivityPub sections">
  <a href="#ap-following">Following</a><a href="#ap-followers">Followers</a><a href="#ap-replies">Reply moderation</a><a href="#ap-settings">Settings</a><a href="#ap-diagnostics">Diagnostics</a>
</nav>
<section id="ap-following" class="panel settings-section-panel">
  <div class="settings-record-heading"><div><p class="eyebrow">Relationship management</p><h2>Following</h2><a href="<?= bms_ap_admin_h(bms_site_url('following/')) ?>">Open Following feed</a><p class="meta">Follow a person by their fediverse handle or profile URL. Their posts appear in your private Following feed.</p></div><span class="static-pill draft"><?= count($following) ?> SHOWN</span></div>
  <form method="post" class="settings-inline-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="follow_actor"><label for="actor_uri">Fediverse handle or profile URL</label><input id="actor_uri" name="actor_uri" type="text" required maxlength="2048" autocapitalize="none" autocomplete="off" spellcheck="false" placeholder="@name@example.com"><button type="submit" class="button-link secondary">Follow</button></form>
  <?php if (!$following): ?><div class="settings-empty-state"><h3>You are not following anyone yet.</h3></div><?php else: ?><div class="ap-record-list"><?php foreach ($following as $relationship): ?>
    <article class="ap-record"><div class="settings-record-cell"><strong><?= htmlspecialchars(trim((string)$relationship['display_name']) ?: trim((string)$relationship['preferred_username']) ?: 'Remote profile', ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string)$relationship['actor_uri'], ENT_QUOTES, 'UTF-8') ?></small></div><div class="settings-record-cell"><span class="static-pill <?= (string)$relationship['state'] === 'accepted' ? 'generated' : ((string)$relationship['state'] === 'pending' ? 'warning' : 'draft') ?>"><?= htmlspecialchars(strtoupper((string)$relationship['state']), ENT_QUOTES, 'UTF-8') ?></span><small><?= htmlspecialchars((string)($relationship['last_error'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></div><div class="settings-record-cell"><?php if ((string)$relationship['state'] !== 'removed'): ?><form method="post" class="settings-inline-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="unfollow_actor"><input type="hidden" name="following_id" value="<?= (int)$relationship['id'] ?>"><button type="submit" class="button-link secondary danger">Unfollow</button></form><?php endif; ?></div></article>
  <?php endforeach; ?></div><?php endif; ?>
  <?php bms_ap_admin_pager('following', $pages); ?>
</section>
<section id="ap-followers" class="panel settings-section-panel">
  <div class="settings-record-heading"><div><p class="eyebrow">Moderation</p><h2>Followers</h2><p class="meta">Review follow requests and manage who follows your federated profile. Only accepted followers appear publicly.</p></div><span class="static-pill draft"><?= count($followers) ?> SHOWN</span></div>
  <?php if (!$followers): ?><div class="settings-empty-state"><h3>No follow requests yet.</h3></div><?php else: ?>
  <div class="ap-record-list"><?php foreach ($followers as $follower): ?>
    <article class="ap-record">
      <div class="settings-record-cell"><strong><?= htmlspecialchars(trim((string)$follower['display_name']) ?: trim((string)$follower['preferred_username']) ?: 'Remote profile', ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string)$follower['actor_uri'], ENT_QUOTES, 'UTF-8') ?></small></div>
      <div class="settings-record-cell"><span class="static-pill <?= (string)$follower['state'] === 'accepted' ? 'generated' : ((string)$follower['state'] === 'pending' ? 'warning' : 'draft') ?>"><?= htmlspecialchars(strtoupper((string)$follower['state']), ENT_QUOTES, 'UTF-8') ?></span></div>
      <div class="settings-record-cell"><form method="post" class="settings-inline-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="moderate"><input type="hidden" name="follower_id" value="<?= (int)$follower['id'] ?>"><button type="submit" name="moderation" value="approve" class="button-link secondary">Approve</button><button type="submit" name="moderation" value="reject" class="button-link secondary">Reject</button><button type="submit" name="moderation" value="block" class="button-link secondary danger">Block</button><button type="submit" name="moderation" value="remove" class="button-link secondary">Remove</button></form></div>
    </article>
  <?php endforeach; ?></div><?php endif; ?>
  <?php bms_ap_admin_pager('followers', $pages); ?>
</section>
<section id="ap-replies" class="panel settings-section-panel">
  <div class="settings-record-heading"><div><p class="eyebrow">Reply moderation</p><h2>Reply moderation</h2><p class="meta">Review replies from the fediverse before they appear in your public conversations.</p></div><span class="static-pill draft"><?= count($remoteReplies) ?> SHOWN</span></div>
  <?php if (!$remoteReplies): ?><div class="settings-empty-state"><h3>No authenticated remote reply has been received.</h3><p class="meta">New remote replies default to pending review.</p></div><?php else: ?>
  <div class="ap-record-list"><?php foreach ($remoteReplies as $reply):
    $replyState = (string)($reply['moderation_state'] ?? 'pending');
    $lifecycleState = (string)($reply['lifecycle_state'] ?? 'active');
    $replyActorName = trim((string)($reply['display_name'] ?? '')) ?: trim((string)($reply['preferred_username'] ?? '')) ?: 'Remote profile';
    $replyPostTitle = trim((string)($reply['post_title'] ?? '')) ?: ('Post #' . (int)($reply['target_post_id'] ?? 0));
  ?>
    <article class="ap-record">
      <div class="settings-record-cell"><strong><?= htmlspecialchars($replyActorName, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string)$reply['actor_uri'], ENT_QUOTES, 'UTF-8') ?></small><p><?= nl2br(htmlspecialchars((string)($reply['content_text'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p></div>
      <div class="settings-record-cell"><strong><?= htmlspecialchars($replyPostTitle, ENT_QUOTES, 'UTF-8') ?></strong><details class="ap-record-details"><summary>Publication details</summary><small>Post #<?= (int)$reply['target_post_id'] ?> · Generation <?= (int)$reply['target_publication_generation'] ?></small><small><?= htmlspecialchars((string)$reply['target_object_uri'], ENT_QUOTES, 'UTF-8') ?></small></details></div>
      <div class="settings-record-cell"><span class="static-pill <?= $replyState === 'approved' && $lifecycleState === 'active' ? 'generated' : ($replyState === 'pending' ? 'warning' : 'draft') ?>"><?= htmlspecialchars(strtoupper($lifecycleState === 'deleted' ? 'deleted' : $replyState), ENT_QUOTES, 'UTF-8') ?></span><details class="ap-record-details"><summary>Protocol identifiers</summary><small>Object: <?= htmlspecialchars((string)$reply['remote_object_uri'], ENT_QUOTES, 'UTF-8') ?></small><small>Last activity: <?= htmlspecialchars((string)$reply['last_activity_uri'], ENT_QUOTES, 'UTF-8') ?></small></details></div>
      <div class="settings-record-cell">
        <?php if ($lifecycleState === 'active' && $replyState !== 'target_retired'): ?><form method="post" class="settings-inline-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="moderate_reply"><input type="hidden" name="reply_id" value="<?= (int)$reply['id'] ?>"><button type="submit" name="moderation" value="approve" class="button-link secondary">Approve</button><button type="submit" name="moderation" value="pending" class="button-link secondary">Pending</button><button type="submit" name="moderation" value="reject" class="button-link secondary">Reject</button><button type="submit" name="moderation" value="hide" class="button-link secondary danger">Hide</button></form><?php endif; ?>
        <form method="post" class="settings-inline-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="block_reply_actor"><input type="hidden" name="actor_uri" value="<?= htmlspecialchars((string)$reply['actor_uri'], ENT_QUOTES, 'UTF-8') ?>"><button type="submit" class="button-link secondary danger">Block profile</button></form>
        <form method="post" class="settings-inline-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="block_reply_domain"><input type="hidden" name="actor_uri" value="<?= htmlspecialchars((string)$reply['actor_uri'], ENT_QUOTES, 'UTF-8') ?>"><button type="submit" class="button-link secondary danger">Block domain</button></form>
      </div>
    </article>
  <?php endforeach; ?></div><?php endif; ?>
  <?php bms_ap_admin_pager('replies', $pages); ?>
</section>
<section id="ap-settings" class="panel settings-section-panel">
  <div class="settings-section-header"><div><p class="eyebrow">Configuration</p><h2>Federation settings</h2><p class="meta">Choose how to approve followers, pause federation, or suspend delivery.</p></div></div>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="activitypub_action" value="save">
    <div class="settings-option-list"><label class="settings-option-card"><input type="checkbox" name="activitypub_enabled" value="1" <?= $enabled ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Enable ActivityPub</strong><small>Use this for initial activation. Once federation history exists, use pause or deliberate deactivation instead of removing an established federated profile.</small></span></label><label class="settings-option-card"><input type="checkbox" name="activitypub_paused" value="1" <?= $operationalState === 'paused' ? 'checked' : '' ?> <?= !$enabled ? 'disabled' : '' ?>><span class="settings-option-copy"><strong>Pause federation</strong><small>Keep profile discovery and your existing identity available, reject new inbox work with a temporary response, stop new publication activities, and leave relationships intact.</small></span></label><label class="settings-option-card"><input type="checkbox" name="activitypub_delivery_suspended" value="1" <?= $deliverySuspended ? 'checked' : '' ?> <?= !$enabled ? 'disabled' : '' ?>><span class="settings-option-copy"><strong>Suspend outbound delivery</strong><small>Continue discovery, inbox processing, and local activity recording, but leave all queued deliveries unclaimed until resumed.</small></span></label></div>
    <div class="settings-field-grid"><div class="settings-field-card"><label for="activitypub_follow_policy">Follower approval</label><select id="activitypub_follow_policy" name="activitypub_follow_policy"><option value="manual" <?= $policy === 'manual' ? 'selected' : '' ?>>Manual approval</option><option value="automatic" <?= $policy === 'automatic' ? 'selected' : '' ?>>Automatic approval</option></select></div></div>
    <div class="settings-save-bar"><div><strong>Save federation settings</strong><p class="meta">Changing this setting does not publish, import, export, or deliver posts.</p></div><button type="submit">Save ActivityPub Settings</button></div>
  </form>
</section>

<section class="panel settings-section-panel" id="ap-diagnostics">
<h2>Diagnostics and delivery activity</h2>
<p><?= count($checks) - count($attention['checks']) ?> of <?= count($checks) ?> checks passing. <?= (int)$attention['delivered'] ?> delivered, <?= (int)$attention['pending'] ?> waiting or processing, <?= (int)$attention['failed'] ?> retrying or failed.</p>
<p class="meta">Detailed checks, delivery records and recovery tools remain available below. Each list shows ten records per page.</p>
<details class="ap-disclosure"<?= count($attention['checks']) > 0 ? ' open' : '' ?>><summary>Federation checks</summary>
<section class="panel settings-section-panel">
  <div class="settings-record-heading"><div><p class="eyebrow">Readiness</p><h3>Federation checks</h3></div></div>
  <div class="ap-record-list"><?php foreach ($checks as $check): ?><article class="ap-record"><div class="settings-record-cell"><strong><?= htmlspecialchars((string)$check['label'], ENT_QUOTES, 'UTF-8') ?></strong></div><div class="settings-record-cell"><span class="static-pill <?= (string)$check['status'] === 'pass' ? 'generated' : ((string)$check['status'] === 'warn' ? 'warning' : 'draft') ?>"><?= htmlspecialchars(strtoupper((string)$check['status']), ENT_QUOTES, 'UTF-8') ?></span></div><div class="settings-record-cell"><?= htmlspecialchars((string)$check['message'], ENT_QUOTES, 'UTF-8') ?></div></article><?php endforeach; ?></div>
</section>

</details>

<details class="ap-disclosure"<?= $attention['failed'] > 0 || $attention['issues'] > 0 || $pages['operations']['number'] > 1 ? ' open' : '' ?>><summary>Queue health and recovery</summary>
<section id="ap-operations" class="panel settings-section-panel">
  <div class="settings-record-heading"><div><p class="eyebrow">Operations</p><h3>Federation queue health</h3><p class="meta">Inspection and repair preserve immutable payloads and audit rows. Reconciliation recovers stale workers and cancels unsafe or orphaned work without deleting history.</p></div><span class="static-pill <?= $queueIssues ? 'warning' : 'generated' ?>"><?= count($queueIssues) ?> ISSUE<?= count($queueIssues) === 1 ? '' : 'S' ?></span></div>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="reconcile_queue"><button type="submit" class="button-link secondary">Reconcile Queue Safely</button></form>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="cleanup_remote_cache"><button type="submit" class="button-link secondary">Clean Remote Cache Safely</button></form>
  <?php if ($queueSummary): ?><div class="ap-record-list"><?php foreach ($queueSummary as $summary): ?><article class="ap-record<?= in_array((string)($summary['status'] ?? ''), ['retry', 'dead'], true) ? ' ap-record-failed' : '' ?>"><div class="settings-record-cell"><strong><?= htmlspecialchars((string)$summary['delivery_type'], ENT_QUOTES, 'UTF-8') ?></strong></div><div class="settings-record-cell"><span class="static-pill <?= (string)$summary['status'] === 'delivered' ? 'generated' : ((string)$summary['status'] === 'dead' ? 'draft' : 'warning') ?>"><?= htmlspecialchars(strtoupper((string)$summary['status']), ENT_QUOTES, 'UTF-8') ?></span></div><div class="settings-record-cell"><small><?= (int)$summary['total'] ?> row(s)</small><small>Oldest available: <?= htmlspecialchars((string)($summary['oldest_available_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></div></article><?php endforeach; ?></div><?php endif; ?>
  <?php if ($queueIssues): ?><h3>Consistency findings</h3><div class="ap-record-list"><?php foreach ($queueIssues as $issue): ?><article class="ap-record<?= in_array((string)($issue['status'] ?? ''), ['retry', 'dead'], true) ? ' ap-record-failed' : '' ?>"><div class="settings-record-cell"><strong>Delivery #<?= (int)$issue['id'] ?></strong><small><?= htmlspecialchars((string)$issue['delivery_type'], ENT_QUOTES, 'UTF-8') ?></small></div><div class="settings-record-cell"><span class="static-pill warning"><?= htmlspecialchars(strtoupper(str_replace('_', ' ', (string)$issue['issue_code'])), ENT_QUOTES, 'UTF-8') ?></span></div><div class="settings-record-cell"><small><?= htmlspecialchars((string)$issue['activity_uri'], ENT_QUOTES, 'UTF-8') ?></small></div></article><?php endforeach; ?></div><?php endif; ?>
  <?php if ($operationalDeliveries): ?><h3>Active, failed, and cancelled delivery records</h3><div class="ap-record-list"><?php foreach ($operationalDeliveries as $delivery): $deliveryStatus = (string)$delivery['status']; $deliveryType = (string)$delivery['delivery_type']; ?><article class="ap-record<?= in_array((string)($deliveryStatus ?? ''), ['retry', 'dead'], true) ? ' ap-record-failed' : '' ?>"><div class="settings-record-cell"><strong>Delivery #<?= (int)$delivery['id'] ?></strong><small><?= htmlspecialchars($deliveryType, ENT_QUOTES, 'UTF-8') ?></small><small><?= htmlspecialchars((string)$delivery['activity_uri'], ENT_QUOTES, 'UTF-8') ?></small></div><div class="settings-record-cell"><span class="static-pill <?= $deliveryStatus === 'dead' || $deliveryStatus === 'cancelled' ? 'draft' : 'warning' ?>"><?= htmlspecialchars(strtoupper($deliveryStatus), ENT_QUOTES, 'UTF-8') ?></span><small>Updated <?= bms_ap_admin_time((string)($delivery['updated_at'] ?? '')) ?></small><small>Attempts: <?= (int)$delivery['attempt_count'] ?><?php if ((int)($delivery['http_status'] ?? 0) > 0): ?> · HTTP <?= (int)$delivery['http_status'] ?><?php endif; ?></small><?php if (trim((string)($delivery['last_error'] ?? '')) !== ''): ?><small><?= htmlspecialchars((string)$delivery['last_error'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></div><div class="settings-record-cell"><div class="settings-inline-actions"><?php if (in_array($deliveryStatus, ['retry', 'dead'], true) && (!is_array($retirement) || $deliveryType === 'actor_delete')): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="retry_delivery"><input type="hidden" name="delivery_id" value="<?= (int)$delivery['id'] ?>"><button type="submit" class="button-link secondary">Retry Safely</button></form><?php endif; ?><?php if ($deliveryType !== 'actor_delete' && in_array($deliveryStatus, ['pending', 'retry', 'processing', 'dead'], true)): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="cancel_delivery"><input type="hidden" name="delivery_id" value="<?= (int)$delivery['id'] ?>"><button type="submit" class="button-link secondary danger">Cancel Permanently</button></form><?php endif; ?></div></div></article><?php endforeach; ?></div><?php else: ?><div class="settings-empty-state"><h3>No active or failed delivery records.</h3></div><?php endif; ?>
  <?php bms_ap_admin_pager('operations', $pages); ?>
</section>
</details>

<details class="ap-disclosure"<?= $pages['deliveries']['number'] > 1 ? ' open' : '' ?>><summary>Publication delivery history</summary>
<section id="ap-deliveries" class="panel settings-section-panel">
  <div class="settings-record-heading"><div><p class="eyebrow">Publication delivery</p><h3>Outbound federation</h3><p class="meta">Each row belongs to one durable local activity. Retries reuse that activity and never recreate the post or expose signing material.</p></div><span class="static-pill draft"><?= count($publicationDeliveries) ?> SHOWN</span></div>
  <?php if (!$publicationDeliveries): ?><div class="settings-empty-state"><h3>No publication delivery has been queued.</h3><p class="meta">Existing historical posts remain discoverable through the outbox but are not backfilled to followers.</p></div><?php else: ?>
  <div class="ap-record-list"><?php foreach ($publicationDeliveries as $delivery):
    $deliveryStatus = (string)($delivery['status'] ?? 'pending');
    $lastError = trim((string)($delivery['last_error'] ?? ''));
  ?>
    <article class="ap-record<?= in_array((string)($deliveryStatus ?? ''), ['retry', 'dead'], true) ? ' ap-record-failed' : '' ?>">
      <div class="settings-record-cell"><strong><?= htmlspecialchars(ucfirst((string)($delivery['event_type'] ?? 'publication')) . ' post #' . (int)($delivery['post_id'] ?? 0), ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string)($delivery['inbox_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></div>
      <div class="settings-record-cell"><span class="static-pill <?= $deliveryStatus === 'delivered' ? 'generated' : ($deliveryStatus === 'dead' ? 'draft' : 'warning') ?>"><?= htmlspecialchars(strtoupper($deliveryStatus), ENT_QUOTES, 'UTF-8') ?></span><small>Updated <?= bms_ap_admin_time((string)($delivery['updated_at'] ?? '')) ?></small><small>Attempts: <?= (int)($delivery['attempt_count'] ?? 0) ?><?php if ((int)($delivery['http_status'] ?? 0) > 0): ?> · HTTP <?= (int)$delivery['http_status'] ?><?php endif; ?></small></div>
      <div class="settings-record-cell"><?php if ($lastError !== ''): ?><small><?= htmlspecialchars($lastError, ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?><?php if (in_array($deliveryStatus, ['retry', 'dead'], true)): ?><form method="post" class="settings-inline-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="retry_publication"><input type="hidden" name="delivery_id" value="<?= (int)$delivery['id'] ?>"><button type="submit" class="button-link secondary">Retry safely</button></form><?php endif; ?></div>
    </article>
  <?php endforeach; ?></div><?php endif; ?>
  <?php bms_ap_admin_pager('deliveries', $pages); ?>
</section>
</details>

<details class="ap-disclosure"<?= empty($keyHealth['ok']) ? ' open' : '' ?>><summary>Signing identity and key history</summary>
<section class="panel settings-section-panel">
  <div class="settings-section-header"><div><p class="eyebrow">Signing identity</p><h3>HTTP signing key</h3><p class="meta">The private key stays encrypted in application storage and is never displayed. Rotation creates and verifies the replacement before atomically retiring the prior key. The stable actor key ID remains unchanged so existing relationships do not need to follow a new identity.</p></div><span class="static-pill <?= !empty($keyHealth['ok']) ? 'generated' : 'warning' ?>"><?= !empty($keyHealth['ok']) ? 'HEALTHY' : 'RECOVERY NEEDED' ?></span></div>
  <p class="meta"><?= htmlspecialchars((string)$keyHealth['message'], ENT_QUOTES, 'UTF-8') ?></p>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="provision_key"><button type="submit" class="button-link secondary"><?= is_array($key) ? 'Rotate Signing Key' : 'Provision Signing Key' ?></button></form>
  <?php if ($keyHistory): ?><div class="ap-record-list"><?php foreach ($keyHistory as $historyKey): ?><article class="ap-record"><div class="settings-record-cell"><strong>Key <?= (int)$historyKey['id'] ?></strong><small><?= htmlspecialchars((string)$historyKey['algorithm'], ENT_QUOTES, 'UTF-8') ?></small></div><div class="settings-record-cell"><span class="static-pill <?= (string)$historyKey['status'] === 'active' ? 'generated' : 'draft' ?>"><?= htmlspecialchars(strtoupper((string)$historyKey['status']), ENT_QUOTES, 'UTF-8') ?></span></div><div class="settings-record-cell"><small>Created <?= htmlspecialchars((string)$historyKey['created_at'], ENT_QUOTES, 'UTF-8') ?></small><?php if (!empty($historyKey['retired_at'])): ?><small>Retired <?= htmlspecialchars((string)$historyKey['retired_at'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?>
</section>

</details>

</section>
<details class="ap-disclosure"<?= false ? ' open' : '' ?>><summary>Danger Zone: permanent federation deactivation</summary>
<section id="ap-danger" class="panel settings-section-panel">
  <div class="settings-section-header"><div><p class="eyebrow">Danger Zone</p><h3>Permanent federation deactivation</h3><p class="meta">This permanently retires the current federated identity, queues a signed Actor Delete for accepted followers, cancels remaining outbound federation work, and prevents this identity from ever being enabled again. It does not delete Bonumark posts, comments, local likes, media, Profile data, Pages, themes, imports, or exports.</p></div><span class="static-pill <?= is_array($retirement) ? 'draft' : 'warning' ?>"><?= is_array($retirement) ? 'IDENTITY RETIRED' : 'IRREVERSIBLE' ?></span></div>
  <?php if (is_array($retirement)): ?><p class="meta">Retired <?= htmlspecialchars((string)$retirement['retired_at'], ENT_QUOTES, 'UTF-8') ?>. Profile URL: <?= htmlspecialchars((string)$retirement['actor_uri'], ENT_QUOTES, 'UTF-8') ?></p><?php else: ?>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="activitypub_action" value="permanent_deactivation"><label for="permanent_deactivation_confirmation">Type <strong>PERMANENTLY DELETE FEDERATED ACTOR</strong> to confirm</label><input id="permanent_deactivation_confirmation" name="permanent_deactivation_confirmation" type="text" required autocomplete="off" spellcheck="false"><button type="submit" class="button-link secondary danger">Permanently deactivate federation</button></form>
  <?php endif; ?>
</section>

</details>
</div>
