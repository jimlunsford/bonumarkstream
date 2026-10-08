<?php
define('BMS_CONNECT_AUTHORIZATION_SURFACE', true);
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/connect.php';
require_once __DIR__ . '/_layout.php';
bms_connect_headers();
bms_require_installed();
if (!bms_is_logged_in()) {
    // Normal site login receives only a validated local return path.
    bms_redirect(bms_admin_url('login.php') . '?return_to=' . rawurlencode(bms_admin_url('connect-authorize.php') . '?' . http_build_query($_GET)));
}
bms_require_capability('manage_settings');
$ownerId = (int)bms_current_user()['id'];
$error = '';
$request = null;
try {
    bms_connect_rate_limit('authorize');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        bms_verify_csrf();
        if (!in_array($_POST['decision'] ?? null, ['approve', 'deny'], true)) { throw new BMS_Connect_Error('invalid_authorization_request'); }
        $result = bms_connect_decide((string)($_POST['request'] ?? ''), $ownerId, $_POST['decision'] === 'approve', is_array($_POST['scopes'] ?? null) ? $_POST['scopes'] : [], (int)($_POST['lifetime'] ?? 0));
        $callback = $result['redirect_uri'];
        unset($result['redirect_uri']);
        // Some browsers apply form-action to the redirect after a POST as well.
        header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self' " . $callback . "; frame-ancestors 'none'; object-src 'none'");
        header('Location: ' . $callback . '?' . http_build_query($result), true, 303);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { throw new BMS_Connect_Error('method_not_allowed', 405); }
    if (isset($_GET['request'])) {
        $request = bms_connect_transaction(static function () use ($ownerId): array {
            bms_connect_owner($ownerId);
            return bms_connect_request((string)$_GET['request'], $ownerId);
        });
        // Chromium checks the initiating document's form-action across the 303.
        // Use only this owner-bound request's revalidated, provisioned callback;
        // the redirect response alone cannot relax the form document's policy.
        define('BMS_CONNECT_APPROVAL_FORM_CALLBACK', $request['request']['redirect_uri']);
    } else {
        $input = $_GET;
        $input['scopes'] = isset($_GET['scope']) && is_string($_GET['scope']) && $_GET['scope'] !== '' ? explode(' ', $_GET['scope']) : [];
        $request = bms_connect_create_request($ownerId, $input);
        bms_redirect(bms_admin_url('connect-authorize.php') . '?request=' . rawurlencode($request['session_id']));
    }
} catch (Throwable $e) {
    http_response_code($e instanceof BMS_Connect_Error ? $e->httpStatus : 500);
    $error = $e instanceof BMS_Connect_Error ? $e->resultCode : 'server_error';
}
bms_admin_header('Approve Connected Application');
$h = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<?php if ($error !== ''): ?>
<section class="panel settings-attention-panel"><h2>Connection could not be approved</h2><p><?= $h($error) ?>. Return to the connecting application and start a new request.</p></section>
<?php elseif ($request): $r = $request['request']; ?>
<section class="panel settings-section-panel">
  <h2>Connect <?= $h($request['client']['display_name']) ?>?</h2>
  <p>This application is requesting access to <strong class="settings-technical-value"><?= $h($r['canonical_origin'] . $r['base_path']) ?></strong>.</p>
  <p class="meta">Your password stays on this site. You can revoke access here at any time. Approval expires <?= $h(gmdate('Y-m-d H:i:s', (int)$request['expires_at'])) ?> UTC.</p>
  <form method="post" action="<?= $h(bms_admin_url('connect-authorize.php')) ?>">
    <input type="hidden" name="csrf_token" value="<?= $h(bms_csrf_token()) ?>">
    <input type="hidden" name="request" value="<?= $h($request['session_id']) ?>">
    <fieldset><legend>Requested permissions</legend><div class="settings-scope-grid">
    <?php foreach ($r['scopes'] as $scope): ?><label class="settings-scope-card"><input type="checkbox" name="scopes[]" value="<?= $h($scope) ?>" checked><span><?= $h($scope) ?></span></label><?php endforeach; ?>
    </div><p class="field-help">Clearing every permission grants no authority. Publishing is not included. This connection currently supports health checks only.</p></fieldset>
    <label for="connect-lifetime">Access expires after</label>
    <select name="lifetime" id="connect-lifetime"><option value="86400">1 day</option><option value="2592000" selected>30 days</option><option value="7776000">90 days</option></select>
    <div class="settings-form-actions"><button name="decision" value="approve" type="submit">Approve connection</button><button class="button-link secondary" name="decision" value="deny" type="submit">Deny</button></div>
  </form>
</section>
<?php endif; ?>
<?php bms_admin_footer(); ?>
