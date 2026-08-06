<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/mail.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_settings');

function bms_admin_mail_clean_transport(string $transport): string
{
    return array_key_exists($transport, bms_mail_transport_options()) ? $transport : 'disabled';
}

function bms_admin_mail_clean_encryption(string $encryption): string
{
    return array_key_exists($encryption, bms_mail_encryption_options()) ? $encryption : 'tls';
}

$settings = bms_mail_settings();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['mail_action'] ?? 'save');

    if ($action === 'save') {
        $transport = bms_admin_mail_clean_transport((string)($_POST['mail_transport'] ?? 'disabled'));
        $fromName = trim((string)($_POST['mail_from_name'] ?? 'Bonumark Stream'));
        $fromEmail = trim((string)($_POST['mail_from_email'] ?? ''));
        $replyTo = trim((string)($_POST['mail_reply_to'] ?? ''));
        $smtpHost = trim((string)($_POST['mail_smtp_host'] ?? ''));
        $smtpPort = (int)($_POST['mail_smtp_port'] ?? 587);
        $smtpEncryption = bms_admin_mail_clean_encryption((string)($_POST['mail_smtp_encryption'] ?? 'tls'));
        $smtpUsername = trim((string)($_POST['mail_smtp_username'] ?? ''));
        $smtpPassword = (string)($_POST['mail_smtp_password'] ?? '');
        $sendmailPath = trim((string)($_POST['mail_sendmail_path'] ?? '/usr/sbin/sendmail'));
        $clearPassword = !empty($_POST['clear_smtp_password']);
        if ($fromName === '') {
            $fromName = 'Bonumark Stream';
        }
        if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            bms_flash('Enter a valid From Email address or leave it blank until you are ready to send mail.', 'error');
            bms_redirect(bms_admin_url('mail.php'));
        }
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            bms_flash('Enter a valid Reply-To Email address or leave it blank.', 'error');
            bms_redirect(bms_admin_url('mail.php'));
        }
        if ($smtpPort <= 0 || $smtpPort > 65535) {
            bms_flash('SMTP port must be between 1 and 65535.', 'error');
            bms_redirect(bms_admin_url('mail.php'));
        }
        try {
            bms_set_setting('mail_transport', $transport);
            bms_set_setting('mail_from_name', $fromName);
            bms_set_setting('mail_from_email', $fromEmail);
            bms_set_setting('mail_reply_to', $replyTo);
            bms_set_setting('mail_smtp_host', $smtpHost);
            bms_set_setting('mail_smtp_port', (string)$smtpPort);
            bms_set_setting('mail_smtp_encryption', $smtpEncryption);
            bms_set_setting('mail_smtp_username', $smtpUsername);
            if ($clearPassword) {
                bms_set_setting('mail_smtp_password', '');
            } elseif ($smtpPassword !== '') {
                bms_set_setting('mail_smtp_password', $smtpPassword);
            }
            bms_set_setting('mail_sendmail_path', $sendmailPath !== '' ? $sendmailPath : '/usr/sbin/sendmail');
            bms_flash('Mail settings saved.', 'success');
            bms_redirect(bms_admin_url('mail.php'));
        } catch (Throwable $e) {
            bms_log_admin_exception('mail', $e);
            bms_flash('Could not save mail settings. Please try again.', 'error');
            bms_redirect(bms_admin_url('mail.php'));
        }
    }

    if ($action === 'test') {
        $recipient = trim((string)($_POST['test_recipient'] ?? ''));
        if ($recipient === '') {
            $recipient = (string)(bms_current_user()['email'] ?? '');
        }
        try {
            $settings = bms_mail_settings();
            $siteName = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
            $body = "This is a Bonumark Stream test email.\n\n";
            $body .= 'Site: ' . $siteName . "\n";
            $body .= 'Version: ' . bms_version() . "\n";
            $body .= 'Transport: ' . bms_mail_transport_label((string)($settings['mail_transport'] ?? 'disabled')) . "\n";
            $body .= 'Sent at: ' . date('Y-m-d H:i:s T') . "\n";
            $body .= "\nIf you received this, Bonumark Stream can send mail with the current configuration.";
            $message = bms_mail_message_from_settings($settings, $recipient, 'Bonumark Stream Test Email', $body, 'plain_text');
            $result = bms_mail_send($settings, $message);
            bms_mail_record_test_delivery($settings, $message, 'sent');
            bms_flash('Test email sent. ' . (string)($result['message'] ?? ''), 'success');
            bms_redirect(bms_admin_url('mail.php'));
        } catch (Throwable $e) {
            try {
                if (isset($message) && is_array($message)) {
                    bms_mail_record_test_delivery($settings ?? bms_mail_settings(), $message, 'failed', 'Mail transport reported an internal error.');
                }
            } catch (Throwable $ignore) {
            }
            bms_log_admin_exception('mail', $e);
            bms_flash('Test email failed. Please try again.', 'error');
            bms_redirect(bms_admin_url('mail.php'));
        }
    }
}

$settings = bms_mail_settings();
$transport = (string)($settings['mail_transport'] ?? 'disabled');
$encryption = (string)($settings['mail_smtp_encryption'] ?? 'tls');
$hasPassword = trim((string)($settings['mail_smtp_password'] ?? '')) !== '';
$recentTests = bms_mail_recent_test_deliveries(8);
$defaultTestRecipient = (string)(bms_current_user()['email'] ?? '');
$fromEmail = trim((string)($settings['mail_from_email'] ?? ''));
$mailReady = $transport !== 'disabled' && $fromEmail !== '';
$mailStateLabel = $mailReady ? 'Ready' : ($transport === 'disabled' ? 'Disabled' : 'Needs a From address');

bms_admin_header('Mail Settings', [
    ['label' => 'Send Test', 'href' => '#mail-test', 'style' => 'secondary'],
    ['label' => 'Registration', 'href' => bms_admin_url('registration.php'), 'style' => 'secondary'],
]);
?>
<section class="panel settings-workflow-hero">
  <div class="settings-workflow-hero-copy"><p class="eyebrow">Settings</p><h2>Configure outbound email.</h2><p class="meta">Mail supports verification, password recovery, tests, and future notifications. Save the transport first, then prove it with a test message.</p></div>
  <span class="static-pill <?= $mailReady ? 'generated' : ($transport === 'disabled' ? 'draft' : 'warning') ?>"><?= htmlspecialchars(strtoupper($mailStateLabel), ENT_QUOTES, 'UTF-8') ?></span>
</section>

<section class="panel settings-summary-panel"><div class="settings-summary-grid">
  <div><span>Transport</span><strong><?= htmlspecialchars(bms_mail_transport_label($transport), ENT_QUOTES, 'UTF-8') ?></strong></div>
  <div><span>Delivery state</span><strong><?= htmlspecialchars($mailStateLabel, ENT_QUOTES, 'UTF-8') ?></strong></div>
  <div><span>From identity</span><strong><?= htmlspecialchars($fromEmail !== '' ? $fromEmail : 'Not set', ENT_QUOTES, 'UTF-8') ?></strong></div>
  <div><span>Recent tests</span><strong><?= count($recentTests) ?></strong></div>
</div></section>

<?php if ($transport !== 'disabled' && !$mailReady): ?>
<section class="panel settings-attention-panel"><p class="eyebrow">Needs attention</p><h2>Add a valid From email before relying on Mail.</h2><p class="meta">The selected transport cannot support registration verification or password recovery until a sender address is configured.</p></section>
<?php endif; ?>

<div class="settings-workflow-grid">
  <section class="panel settings-section-panel">
    <div class="settings-section-header"><div><p class="eyebrow">Delivery</p><h2>Transport and sender identity</h2><p class="meta">Choose the delivery method and the identity recipients will see.</p></div></div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="mail_action" value="save">
      <div class="settings-field-grid">
        <div class="settings-field-card">
          <label for="mail_transport">Mail transport</label>
          <select id="mail_transport" name="mail_transport"><?php foreach (bms_mail_transport_options() as $value => $label): ?><option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $value === $transport ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
          <p class="field-help">Native SMTP needs no optional library. PHPMailer SMTP requires the optional Composer package.</p>
        </div>
        <div class="settings-field-card">
          <label for="mail_from_name">From name</label>
          <input type="text" id="mail_from_name" name="mail_from_name" value="<?= htmlspecialchars((string)$settings['mail_from_name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="160">
          <p class="field-help">Usually the site name or a recognizable sender name.</p>
        </div>
        <div class="settings-field-card">
          <label for="mail_from_email">From email</label>
          <input type="email" id="mail_from_email" name="mail_from_email" value="<?= htmlspecialchars((string)$settings['mail_from_email'], ENT_QUOTES, 'UTF-8') ?>" maxlength="190">
          <p class="field-help">Required before verification and recovery messages can be sent.</p>
        </div>
        <div class="settings-field-card">
          <label for="mail_reply_to">Reply-To email</label>
          <input type="email" id="mail_reply_to" name="mail_reply_to" value="<?= htmlspecialchars((string)$settings['mail_reply_to'], ENT_QUOTES, 'UTF-8') ?>" maxlength="190">
          <p class="field-help">Optional address for recipient replies.</p>
        </div>
      </div>

      <div class="settings-section-header"><div><p class="eyebrow">SMTP</p><h2>Server credentials</h2><p class="meta">These values are used by Native SMTP and PHPMailer SMTP.</p></div></div>
      <div class="settings-field-grid">
        <div class="settings-field-card"><label for="mail_smtp_host">SMTP host</label><input type="text" id="mail_smtp_host" name="mail_smtp_host" value="<?= htmlspecialchars((string)$settings['mail_smtp_host'], ENT_QUOTES, 'UTF-8') ?>" maxlength="190"></div>
        <div class="settings-field-card"><label for="mail_smtp_port">SMTP port</label><input type="number" id="mail_smtp_port" name="mail_smtp_port" value="<?= htmlspecialchars((string)$settings['mail_smtp_port'], ENT_QUOTES, 'UTF-8') ?>" min="1" max="65535"></div>
        <div class="settings-field-card"><label for="mail_smtp_encryption">SMTP encryption</label><select id="mail_smtp_encryption" name="mail_smtp_encryption"><?php foreach (bms_mail_encryption_options() as $value => $label): ?><option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $value === $encryption ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <div class="settings-field-card"><label for="mail_smtp_username">SMTP username</label><input type="text" id="mail_smtp_username" name="mail_smtp_username" value="<?= htmlspecialchars((string)$settings['mail_smtp_username'], ENT_QUOTES, 'UTF-8') ?>" maxlength="190" autocomplete="username"></div>
        <div class="settings-field-card">
          <label for="mail_smtp_password">SMTP password</label>
          <input type="password" id="mail_smtp_password" name="mail_smtp_password" value="" autocomplete="new-password" placeholder="<?= $hasPassword ? 'Password is saved, enter a new value to replace it' : 'No password saved' ?>">
          <p class="field-help"><?= $hasPassword ? 'A password is stored. Leave this blank to keep it.' : 'No SMTP password is stored.' ?></p>
        </div>
        <div class="settings-field-card"><label for="mail_sendmail_path">Sendmail path</label><input type="text" id="mail_sendmail_path" name="mail_sendmail_path" value="<?= htmlspecialchars((string)$settings['mail_sendmail_path'], ENT_QUOTES, 'UTF-8') ?>" maxlength="255"><p class="field-help">Used only when Sendmail is selected.</p></div>
      </div>
      <?php if ($hasPassword): ?><label class="settings-option-card"><input type="checkbox" name="clear_smtp_password" value="1"><span class="settings-option-copy"><strong>Clear the saved SMTP password</strong><small>The password is removed when these settings are saved.</small></span></label><?php endif; ?>
      <div class="settings-save-bar"><div><strong>Save Mail Settings</strong><p class="meta">Test delivery always uses the saved configuration.</p></div><button type="submit">Save Mail Settings</button></div>
    </form>
  </section>

  <aside class="settings-workflow-rail is-sticky">
    <section class="panel settings-section-panel" id="mail-test">
      <p class="eyebrow">Test delivery</p><h2>Send a test email</h2><p class="meta">A plain-text message will be sent with the saved transport settings.</p>
      <form method="post" class="settings-section-list">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="mail_action" value="test">
        <div class="settings-field-card"><label for="test_recipient">Recipient</label><input type="email" id="test_recipient" name="test_recipient" value="<?= htmlspecialchars($defaultTestRecipient, ENT_QUOTES, 'UTF-8') ?>" maxlength="190" required></div>
        <button type="submit">Send Test Email</button>
      </form>
    </section>
  </aside>
</div>

<section class="panel settings-section-panel">
  <div class="settings-record-heading"><div><p class="eyebrow">Test history</p><h2>Recent test messages</h2><p class="meta">The last eight delivery attempts are retained for troubleshooting.</p></div><span class="static-pill draft"><?= count($recentTests) ?> RECORD<?= count($recentTests) === 1 ? '' : 'S' ?></span></div>
  <?php if (!$recentTests): ?><div class="settings-empty-state"><h3>No test emails recorded yet.</h3><p class="meta">Save a transport and send a test message to confirm delivery.</p></div><?php else: ?>
  <div class="settings-record-header settings-mail-record"><span>When</span><span>Recipient</span><span>Transport</span><span>Status</span><span>Details</span></div>
  <div class="settings-record-list"><?php foreach ($recentTests as $test): ?>
    <article class="settings-mail-record">
      <div class="settings-record-cell"><span class="settings-mobile-label">When</span><?= htmlspecialchars((string)($test['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
      <div class="settings-record-cell"><span class="settings-mobile-label">Recipient</span><?= htmlspecialchars((string)($test['recipient_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
      <div class="settings-record-cell"><span class="settings-mobile-label">Transport</span><?= htmlspecialchars(bms_mail_transport_label((string)($test['transport'] ?? 'disabled')), ENT_QUOTES, 'UTF-8') ?></div>
      <div class="settings-record-cell"><span class="settings-mobile-label">Status</span><span class="static-pill <?= (string)($test['status'] ?? '') === 'sent' ? 'generated' : 'warning' ?>"><?= htmlspecialchars(strtoupper((string)($test['status'] ?? 'unknown')), ENT_QUOTES, 'UTF-8') ?></span></div>
      <div class="settings-record-cell"><span class="settings-mobile-label">Details</span><?= htmlspecialchars(trim((string)($test['error_message'] ?? '')) !== '' ? (string)$test['error_message'] : 'No error reported.', ENT_QUOTES, 'UTF-8') ?></div>
    </article>
  <?php endforeach; ?></div><?php endif; ?>
</section>
<?php bms_admin_footer(); ?>
