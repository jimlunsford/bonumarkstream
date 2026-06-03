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
            bms_flash('Could not save mail settings: ' . $e->getMessage(), 'error');
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
                    bms_mail_record_test_delivery($settings ?? bms_mail_settings(), $message, 'failed', $e->getMessage());
                }
            } catch (Throwable $ignore) {
            }
            bms_flash('Test email failed: ' . $e->getMessage(), 'error');
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

bms_admin_header('Mail Settings', [
    ['label' => 'General Settings', 'href' => bms_admin_url('settings.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Settings</p>
  <h2>Mail delivery</h2>
  <p class="meta">Configure how Bonumark Stream sends email. This is the foundation for test messages, future notifications, and any feature that needs outbound mail.</p>
</section>

<section class="panel settings-panel">
  <h2>Transport settings</h2>
  <form method="post" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="mail_action" value="save">

    <label for="mail_transport">Mail transport</label>
    <select id="mail_transport" name="mail_transport">
      <?php foreach (bms_mail_transport_options() as $value => $label): ?>
        <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $value === $transport ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
      <?php endforeach; ?>
    </select>
    <p class="field-help">Disabled keeps Bonumark from sending mail. Native SMTP does not require PHPMailer. PHPMailer SMTP only works if you install the optional Composer library.</p>

    <label for="mail_from_name">From name</label>
    <input type="text" id="mail_from_name" name="mail_from_name" value="<?= htmlspecialchars((string)$settings['mail_from_name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="160">

    <label for="mail_from_email">From email</label>
    <input type="email" id="mail_from_email" name="mail_from_email" value="<?= htmlspecialchars((string)$settings['mail_from_email'], ENT_QUOTES, 'UTF-8') ?>" maxlength="190">

    <label for="mail_reply_to">Reply-To email</label>
    <input type="email" id="mail_reply_to" name="mail_reply_to" value="<?= htmlspecialchars((string)$settings['mail_reply_to'], ENT_QUOTES, 'UTF-8') ?>" maxlength="190">

    <div class="settings-grid two-column-settings">
      <div>
        <label for="mail_smtp_host">SMTP host</label>
        <input type="text" id="mail_smtp_host" name="mail_smtp_host" value="<?= htmlspecialchars((string)$settings['mail_smtp_host'], ENT_QUOTES, 'UTF-8') ?>" maxlength="190">
      </div>
      <div>
        <label for="mail_smtp_port">SMTP port</label>
        <input type="number" id="mail_smtp_port" name="mail_smtp_port" value="<?= htmlspecialchars((string)$settings['mail_smtp_port'], ENT_QUOTES, 'UTF-8') ?>" min="1" max="65535">
      </div>
    </div>

    <label for="mail_smtp_encryption">SMTP encryption</label>
    <select id="mail_smtp_encryption" name="mail_smtp_encryption">
      <?php foreach (bms_mail_encryption_options() as $value => $label): ?>
        <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $value === $encryption ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
      <?php endforeach; ?>
    </select>

    <label for="mail_smtp_username">SMTP username</label>
    <input type="text" id="mail_smtp_username" name="mail_smtp_username" value="<?= htmlspecialchars((string)$settings['mail_smtp_username'], ENT_QUOTES, 'UTF-8') ?>" maxlength="190" autocomplete="username">

    <label for="mail_smtp_password">SMTP password</label>
    <input type="password" id="mail_smtp_password" name="mail_smtp_password" value="" autocomplete="new-password" placeholder="<?= $hasPassword ? 'Password is saved, enter a new value to replace it' : 'No password saved' ?>">
    <?php if ($hasPassword): ?>
      <label class="checkbox-label"><input type="checkbox" name="clear_smtp_password" value="1"> Clear saved SMTP password</label>
    <?php endif; ?>

    <label for="mail_sendmail_path">Sendmail path</label>
    <input type="text" id="mail_sendmail_path" name="mail_sendmail_path" value="<?= htmlspecialchars((string)$settings['mail_sendmail_path'], ENT_QUOTES, 'UTF-8') ?>" maxlength="255">

    <button type="submit">Save Mail Settings</button>
  </form>
</section>

<section class="panel settings-panel">
  <h2>Send test email</h2>
  <p class="meta">Send a plain-text test message using the saved mail settings.</p>
  <form method="post" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="mail_action" value="test">
    <label for="test_recipient">Recipient</label>
    <input type="email" id="test_recipient" name="test_recipient" value="<?= htmlspecialchars($defaultTestRecipient, ENT_QUOTES, 'UTF-8') ?>" maxlength="190" required>
    <button type="submit">Send Test Email</button>
  </form>
</section>

<section class="panel">
  <h2>Recent test messages</h2>
  <?php if (!$recentTests): ?>
    <p class="meta">No test emails have been recorded yet.</p>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>When</th><th>Recipient</th><th>Transport</th><th>Status</th><th>Details</th></tr></thead>
        <tbody>
          <?php foreach ($recentTests as $test): ?>
            <tr>
              <td><?= htmlspecialchars((string)($test['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($test['recipient_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars(bms_mail_transport_label((string)($test['transport'] ?? 'disabled')), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($test['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($test['error_message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php bms_admin_footer(); ?>
