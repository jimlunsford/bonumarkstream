<?php
require_once __DIR__ . '/database.php';

function bms_mail_defaults(): array
{
    return [
        'mail_transport' => 'disabled',
        'mail_from_name' => 'Bonumark Stream',
        'mail_from_email' => '',
        'mail_reply_to' => '',
        'mail_smtp_host' => '',
        'mail_smtp_port' => '587',
        'mail_smtp_encryption' => 'tls',
        'mail_smtp_username' => '',
        'mail_smtp_password' => '',
    ];
}

function bms_mail_sendmail_default_path(): string
{
    return '/usr/sbin/sendmail';
}

/**
 * The Sendmail program is read only from config.php (mail_sendmail_path).
 * It is never taken from database settings or from Admin input.
 */
function bms_mail_sendmail_path(): string
{
    $configured = trim((string)(bms_config()['mail_sendmail_path'] ?? ''));
    return $configured !== '' ? $configured : bms_mail_sendmail_default_path();
}

/**
 * The SMTP password is stored encrypted, the same way ActivityPub signing keys
 * are: AES-256-GCM with a key derived from the installation security salt in
 * config.php. The settings value carries a prefix, so a password an earlier
 * version saved without encryption still works; it is encrypted the next time
 * it is read. Exports never include it.
 */
function bms_mail_secret_prefix(): string
{
    return 'bms-enc:v1:';
}

function bms_mail_secret_key(): string
{
    $salt = trim((string)(bms_config()['security_salt'] ?? ''));
    if (strlen($salt) < 32) {
        throw new RuntimeException('Bonumark Stream stores the SMTP password only when _bonumark_stream/config.php has a security_salt of at least 32 characters.');
    }
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
        throw new RuntimeException('Bonumark Stream needs the PHP OpenSSL extension to store the SMTP password.');
    }
    return hash_hkdf('sha256', $salt, 32, 'bonumark-stream-smtp-password-v1');
}

function bms_mail_secret_storage_available(): bool
{
    try {
        bms_mail_secret_key();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function bms_mail_is_encrypted_secret(string $stored): bool
{
    return str_starts_with($stored, bms_mail_secret_prefix());
}

function bms_mail_encrypt_secret(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $key = bms_mail_secret_key();
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'bonumark-stream-smtp-password-v1', 16);
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('The SMTP password could not be encrypted.');
    }
    $payload = json_encode([
        'version' => 1,
        'cipher' => 'aes-256-gcm',
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'ciphertext' => base64_encode($ciphertext),
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('The encrypted SMTP password could not be encoded.');
    }
    return bms_mail_secret_prefix() . $payload;
}

/** The plain SMTP password for a stored value. A value without the prefix is returned as it is. */
function bms_mail_decrypt_secret(string $stored): string
{
    if (!bms_mail_is_encrypted_secret($stored)) {
        return $stored;
    }
    $decoded = json_decode(substr($stored, strlen(bms_mail_secret_prefix())), true);
    if (!is_array($decoded) || (int)($decoded['version'] ?? 0) !== 1 || (string)($decoded['cipher'] ?? '') !== 'aes-256-gcm') {
        throw new RuntimeException('The saved SMTP password has an unknown format.');
    }
    $iv = base64_decode((string)($decoded['iv'] ?? ''), true);
    $tag = base64_decode((string)($decoded['tag'] ?? ''), true);
    $ciphertext = base64_decode((string)($decoded['ciphertext'] ?? ''), true);
    if (!is_string($iv) || strlen($iv) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($ciphertext)) {
        throw new RuntimeException('The saved SMTP password is damaged.');
    }
    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', bms_mail_secret_key(), OPENSSL_RAW_DATA, $iv, $tag, 'bonumark-stream-smtp-password-v1');
    if (!is_string($plain)) {
        throw new RuntimeException('The saved SMTP password could not be decrypted with this installation\'s security salt.');
    }
    return $plain;
}

/** The SMTP password exactly as stored in settings, or '' when none is saved there. */
function bms_mail_stored_smtp_password(): string
{
    if (!function_exists('bms_setting') || !bms_is_installed() || !bms_has_database_config()) {
        return '';
    }
    try {
        $stmt = bms_db()->prepare('SELECT setting_value FROM ' . bms_table('settings') . ' WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute(['setting_key' => 'mail_smtp_password']);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? '' : (string)$value;
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * How the saved SMTP password is stored: 'none', 'encrypted', 'plaintext'
 * (saved by an earlier version and not yet encrypted), or 'unreadable'
 * (encrypted with another security salt, or damaged).
 */
function bms_mail_smtp_password_state(): string
{
    $stored = bms_mail_stored_smtp_password();
    if ($stored === '') {
        return 'none';
    }
    if (!bms_mail_is_encrypted_secret($stored)) {
        return 'plaintext';
    }
    try {
        bms_mail_decrypt_secret($stored);
        return 'encrypted';
    } catch (Throwable $e) {
        return 'unreadable';
    }
}

/**
 * Encrypt an SMTP password an earlier version saved without encryption. Safe
 * to repeat; only the exact value read is replaced. Returns the resulting
 * state, as bms_mail_smtp_password_state() reports it.
 */
function bms_mail_protect_stored_smtp_password(): string
{
    $stored = bms_mail_stored_smtp_password();
    if ($stored === '' || bms_mail_is_encrypted_secret($stored)) {
        return bms_mail_smtp_password_state();
    }
    if (!bms_mail_secret_storage_available()) {
        return 'plaintext';
    }
    try {
        $stmt = bms_db()->prepare('UPDATE ' . bms_table('settings') . ' SET setting_value = :encrypted, updated_at = NOW() WHERE setting_key = :setting_key AND setting_value = :stored');
        $stmt->execute(['encrypted' => bms_mail_encrypt_secret($stored), 'setting_key' => 'mail_smtp_password', 'stored' => $stored]);
    } catch (Throwable $e) {
        error_log('Bonumark Stream could not encrypt the stored SMTP password: ' . $e->getMessage());
        return 'plaintext';
    }
    return bms_mail_smtp_password_state();
}

function bms_mail_settings(): array
{
    $settings = [];

    foreach (bms_mail_defaults() as $key => $default) {
        $settings[$key] = (string)bms_setting_or_config($key, $default);
    }
    $settings['mail_sendmail_path'] = bms_mail_sendmail_path();

    // Transports receive the plain password. A value saved by an earlier
    // version without encryption keeps working and is encrypted now.
    $storedPassword = $settings['mail_smtp_password'];
    if (bms_mail_is_encrypted_secret($storedPassword)) {
        try {
            $settings['mail_smtp_password'] = bms_mail_decrypt_secret($storedPassword);
        } catch (Throwable $e) {
            $settings['mail_smtp_password'] = '';
            $settings['mail_smtp_password_unreadable'] = '1';
        }
    } elseif ($storedPassword !== '' && bms_mail_stored_smtp_password() === $storedPassword) {
        bms_mail_protect_stored_smtp_password();
    }

    if ($settings['mail_from_name'] === '') {
        $settings['mail_from_name'] = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
    }

    if ($settings['mail_from_email'] === '') {
        $settings['mail_from_email'] = (string)bms_setting_or_config('site_admin_email', '');
    }

    return $settings;
}

function bms_mail_transport_options(): array
{
    return [
        'disabled' => 'Disabled',
        'php_mail' => 'PHP Mail',
        'smtp' => 'Native SMTP',
        'smtp_phpmailer' => 'SMTP via PHPMailer',
        'sendmail' => 'Sendmail',
    ];
}

function bms_mail_encryption_options(): array
{
    return [
        'none' => 'None',
        'tls' => 'TLS',
        'ssl' => 'SSL',
    ];
}

function bms_mail_transport_label(string $transport): string
{
    $options = bms_mail_transport_options();
    return $options[$transport] ?? $transport;
}

function bms_mail_normalize_email_list(string $emails): string
{
    $emails = str_replace(["\r\n", "\r", "\n", ';'], ',', $emails);
    $parts = array_filter(array_map('trim', explode(',', $emails)));
    $clean = [];

    foreach ($parts as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email address: ' . $email);
        }
        $clean[] = strtolower($email);
    }

    return implode(', ', array_values(array_unique($clean)));
}

function bms_mail_parse_email_list(string $emails): array
{
    $normalized = bms_mail_normalize_email_list($emails);

    if ($normalized === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $normalized))));
}

function bms_mail_validate_header_value(string $value): string
{
    return str_replace(["\r", "\n"], '', $value);
}

function bms_mail_boundary(): string
{
    return 'bonumark_' . bin2hex(random_bytes(16));
}

function bms_mail_has_attachments(array $message): bool
{
    return !empty($message['attachments']) && is_array($message['attachments']);
}

function bms_mail_content_type_for_body(string $bodyFormat): string
{
    return $bodyFormat === 'html'
        ? 'text/html; charset=UTF-8'
        : 'text/plain; charset=UTF-8';
}

function bms_mail_build_headers_array(array $message, bool $includeRecipientsAndSubject = false, ?string $boundary = null, bool $includeBccHeader = false): array
{
    $headers = [];

    if ($includeRecipientsAndSubject) {
        $headers[] = 'To: ' . implode(', ', $message['to'] ?? []);

        if (!empty($message['cc'])) {
            $headers[] = 'Cc: ' . implode(', ', $message['cc']);
        }

        if ($includeBccHeader && !empty($message['bcc'])) {
            $headers[] = 'Bcc: ' . implode(', ', $message['bcc']);
        }

        $headers[] = 'Subject: ' . bms_mail_validate_header_value((string)($message['subject'] ?? 'Bonumark Stream'));
        $headers[] = 'Date: ' . date('r');
    } else {
        if (!empty($message['cc'])) {
            $headers[] = 'Cc: ' . implode(', ', $message['cc']);
        }

        if (!empty($message['bcc'])) {
            $headers[] = 'Bcc: ' . implode(', ', $message['bcc']);
        }
    }

    $fromName = bms_mail_validate_header_value((string)($message['from_name'] ?? 'Bonumark Stream'));
    $fromEmail = bms_mail_validate_header_value((string)($message['from_email'] ?? ''));

    if ($fromEmail !== '') {
        $headers[] = 'From: ' . ($fromName !== '' ? '"' . addcslashes($fromName, '"\\') . '" ' : '') . '<' . $fromEmail . '>';
    }

    $replyTo = bms_mail_validate_header_value((string)($message['reply_to'] ?? ''));
    if ($replyTo !== '') {
        $headers[] = 'Reply-To: <' . $replyTo . '>';
    }

    $headers[] = 'MIME-Version: 1.0';

    if ($boundary !== null) {
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
    } else {
        $headers[] = 'Content-Type: ' . bms_mail_content_type_for_body((string)($message['body_format'] ?? 'plain_text'));
    }

    $headers[] = 'X-Mailer: Bonumark Stream';

    return $headers;
}

function bms_mail_build_headers(array $message, string $lineEnding = "\r\n"): string
{
    $boundary = bms_mail_has_attachments($message)
        ? ($message['_boundary'] ?? bms_mail_boundary())
        : null;

    return implode($lineEnding, bms_mail_build_headers_array($message, false, $boundary, true));
}

function bms_mail_render_body(array $message, ?string $boundary = null): string
{
    $body = (string)($message['body'] ?? '');

    if ($boundary === null || !bms_mail_has_attachments($message)) {
        return $body;
    }

    $lineEnding = "\r\n";
    $parts = [];

    $parts[] = '--' . $boundary;
    $parts[] = 'Content-Type: ' . bms_mail_content_type_for_body((string)($message['body_format'] ?? 'plain_text'));
    $parts[] = 'Content-Transfer-Encoding: 8bit';
    $parts[] = '';
    $parts[] = $body;

    foreach ($message['attachments'] as $attachment) {
        $filename = bms_mail_validate_header_value((string)($attachment['filename'] ?? 'attachment.txt'));
        $contentType = bms_mail_validate_header_value((string)($attachment['content_type'] ?? 'application/octet-stream'));
        $content = (string)($attachment['content'] ?? '');

        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: ' . $contentType . '; name="' . addcslashes($filename, '"\\') . '"';
        $parts[] = 'Content-Transfer-Encoding: base64';
        $parts[] = 'Content-Disposition: attachment; filename="' . addcslashes($filename, '"\\') . '"';
        $parts[] = '';
        $parts[] = chunk_split(base64_encode($content));
    }

    $parts[] = '--' . $boundary . '--';
    $parts[] = '';

    return implode($lineEnding, $parts);
}

function bms_mail_build_full_message(array $message, bool $includeBccHeader = false): string
{
    $boundary = bms_mail_has_attachments($message) ? bms_mail_boundary() : null;

    if ($boundary !== null) {
        $message['_boundary'] = $boundary;
    }

    $headers = bms_mail_build_headers_array($message, true, $boundary, $includeBccHeader);
    $body = bms_mail_render_body($message, $boundary);

    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

function bms_mail_message_from_settings(array $settings, string $to, string $subject, string $body, string $bodyFormat = 'plain_text', array $attachments = []): array
{
    $recipients = bms_mail_parse_email_list($to);

    if (!$recipients) {
        throw new RuntimeException('At least one recipient is required.');
    }

    $fromEmail = trim((string)($settings['mail_from_email'] ?? ''));
    if ($fromEmail === '') {
        throw new RuntimeException('From Email is required before sending mail.');
    }
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('From Email is invalid.');
    }

    $replyTo = trim((string)($settings['mail_reply_to'] ?? ''));
    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Reply-To Email is invalid.');
    }

    return [
        'to' => $recipients,
        'cc' => [],
        'bcc' => [],
        'from_name' => trim((string)($settings['mail_from_name'] ?? 'Bonumark Stream')),
        'from_email' => $fromEmail,
        'reply_to' => $replyTo,
        'subject' => $subject,
        'body' => $body,
        'body_format' => $bodyFormat === 'html' ? 'html' : 'plain_text',
        'attachments' => $attachments,
    ];
}

function bms_mail_send(array $settings, array $message): array
{
    $transport = (string)($settings['mail_transport'] ?? 'disabled');

    return match ($transport) {
        'php_mail' => bms_mail_send_php_mail($message),
        'smtp' => bms_mail_send_smtp($settings, $message),
        'smtp_phpmailer' => bms_mail_send_phpmailer($settings, $message),
        'sendmail' => bms_mail_send_sendmail($settings, $message),
        'disabled' => throw new RuntimeException('Mail transport is disabled. Choose PHP Mail, SMTP, or Sendmail to send email.'),
        default => throw new RuntimeException('Unsupported mail transport: ' . $transport),
    };
}

function bms_mail_send_php_mail(array $message): array
{
    $boundary = bms_mail_has_attachments($message) ? bms_mail_boundary() : null;

    if ($boundary !== null) {
        $message['_boundary'] = $boundary;
    }

    $headers = implode("\r\n", bms_mail_build_headers_array($message, false, $boundary, true));
    $to = implode(', ', $message['to'] ?? []);
    $subject = bms_mail_validate_header_value((string)($message['subject'] ?? 'Bonumark Stream'));
    $body = bms_mail_render_body($message, $boundary);

    $sent = mail($to, $subject, $body, $headers);

    if (!$sent) {
        throw new RuntimeException('PHP mail() returned false.');
    }

    return [
        'transport' => 'php_mail',
        'message' => 'PHP mail accepted the message.',
    ];
}

/**
 * Recipients are passed to Sendmail as arguments. A recipient must not be
 * readable as an option or as a file or program delivery.
 */
function bms_mail_sendmail_recipient_is_safe(string $recipient): bool
{
    return $recipient !== ''
        && !str_contains($recipient, '/')
        && !str_starts_with($recipient, '-')
        && preg_match('/[\x00-\x20\x7F|]/', $recipient) !== 1
        && filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false;
}

function bms_mail_send_sendmail(array $settings, array $message): array
{
    $path = trim((string)($settings['mail_sendmail_path'] ?? bms_mail_sendmail_path()));

    if ($path === '') {
        throw new RuntimeException('Sendmail path is required.');
    }

    if (!str_starts_with($path, '/')) {
        throw new RuntimeException('Sendmail path must be an absolute path set as mail_sendmail_path in config.php.');
    }

    if (!is_file($path) || !is_executable($path)) {
        throw new RuntimeException('Sendmail path is not executable: ' . $path);
    }

    $recipients = array_merge($message['to'] ?? [], $message['cc'] ?? [], $message['bcc'] ?? []);

    if (!$recipients) {
        throw new RuntimeException('At least one recipient is required.');
    }

    foreach ($recipients as $recipient) {
        if (!bms_mail_sendmail_recipient_is_safe((string)$recipient)) {
            throw new RuntimeException('A recipient address cannot be passed to Sendmail safely.');
        }
    }

    // An argument list runs the program directly, with no shell.
    $command = array_merge([$path, '-i'], array_map('strval', array_values($recipients)));

    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start sendmail process.');
    }

    $payload = bms_mail_build_full_message($message, false);

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException('Sendmail failed with exit code ' . $exitCode . '. ' . trim((string)$stderr));
    }

    return [
        'transport' => 'sendmail',
        'message' => trim((string)$stdout) !== '' ? trim((string)$stdout) : 'Sendmail accepted the message.',
    ];
}

function bms_smtp_read_response($socket): array
{
    $lines = [];

    while (($line = fgets($socket, 515)) !== false) {
        $lines[] = rtrim($line, "\r\n");

        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }

    if (!$lines) {
        throw new RuntimeException('SMTP server did not respond.');
    }

    $last = end($lines);
    $code = (int)substr((string)$last, 0, 3);

    return [
        'code' => $code,
        'lines' => $lines,
        'text' => implode("\n", $lines),
    ];
}

function bms_smtp_expect($socket, array $expectedCodes, string $context): array
{
    $response = bms_smtp_read_response($socket);

    if (!in_array($response['code'], $expectedCodes, true)) {
        throw new RuntimeException('SMTP error during ' . $context . ': ' . $response['text']);
    }

    return $response;
}

function bms_smtp_write($socket, string $command): void
{
    fwrite($socket, $command . "\r\n");
}

function bms_smtp_escape_data(string $data): string
{
    $data = str_replace(["\r\n", "\r"], "\n", $data);
    $lines = explode("\n", $data);

    foreach ($lines as &$line) {
        if (str_starts_with($line, '.')) {
            $line = '.' . $line;
        }
    }

    return implode("\r\n", $lines);
}

function bms_mail_send_smtp(array $settings, array $message): array
{
    $host = trim((string)($settings['mail_smtp_host'] ?? ''));
    $port = (int)($settings['mail_smtp_port'] ?? 587);
    $encryption = strtolower(trim((string)($settings['mail_smtp_encryption'] ?? 'tls')));
    $username = trim((string)($settings['mail_smtp_username'] ?? ''));
    $password = (string)($settings['mail_smtp_password'] ?? '');

    if ($host === '') {
        throw new RuntimeException('SMTP host is required.');
    }

    if ($port <= 0 || $port > 65535) {
        throw new RuntimeException('SMTP port is invalid.');
    }

    if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
        throw new RuntimeException('SMTP encryption must be none, TLS, or SSL.');
    }

    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);

    if (!$socket) {
        throw new RuntimeException('Could not connect to SMTP server: ' . $errstr);
    }

    stream_set_timeout($socket, 20);

    try {
        bms_smtp_expect($socket, [220], 'connect');

        $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';

        bms_smtp_write($socket, 'EHLO ' . $serverName);
        bms_smtp_expect($socket, [250], 'EHLO');

        if ($encryption === 'tls') {
            bms_smtp_write($socket, 'STARTTLS');
            bms_smtp_expect($socket, [220], 'STARTTLS');

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not enable SMTP TLS encryption.');
            }

            bms_smtp_write($socket, 'EHLO ' . $serverName);
            bms_smtp_expect($socket, [250], 'EHLO after STARTTLS');
        }

        if ($username !== '') {
            bms_smtp_write($socket, 'AUTH LOGIN');
            bms_smtp_expect($socket, [334], 'AUTH LOGIN');

            bms_smtp_write($socket, base64_encode($username));
            bms_smtp_expect($socket, [334], 'SMTP username');

            bms_smtp_write($socket, base64_encode($password));
            bms_smtp_expect($socket, [235], 'SMTP password');
        }

        bms_smtp_write($socket, 'MAIL FROM:<' . $message['from_email'] . '>');
        bms_smtp_expect($socket, [250], 'MAIL FROM');

        $recipients = array_merge($message['to'] ?? [], $message['cc'] ?? [], $message['bcc'] ?? []);

        foreach ($recipients as $recipient) {
            bms_smtp_write($socket, 'RCPT TO:<' . $recipient . '>');
            bms_smtp_expect($socket, [250, 251], 'RCPT TO ' . $recipient);
        }

        bms_smtp_write($socket, 'DATA');
        bms_smtp_expect($socket, [354], 'DATA');

        $payload = bms_mail_build_full_message($message, false);

        bms_smtp_write($socket, bms_smtp_escape_data($payload) . "\r\n.");
        bms_smtp_expect($socket, [250], 'message body');

        bms_smtp_write($socket, 'QUIT');

        return [
            'transport' => 'smtp',
            'message' => 'SMTP server accepted the message.',
        ];
    } finally {
        fclose($socket);
    }
}

function bms_mail_load_phpmailer(): void
{
    if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        return;
    }

    $autoloads = [
        bms_root_path('vendor/autoload.php'),
        dirname(bms_root_path()) . '/vendor/autoload.php',
    ];

    foreach ($autoloads as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
                return;
            }
        }
    }
}

function bms_mail_send_phpmailer(array $settings, array $message): array
{
    bms_mail_load_phpmailer();

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        throw new RuntimeException('PHPMailer is not installed. Install phpmailer/phpmailer with Composer or choose Native SMTP, PHP Mail, or Sendmail.');
    }

    $mailerClass = '\\PHPMailer\\PHPMailer\\PHPMailer';
    $mailer = new $mailerClass(true);

    $host = trim((string)($settings['mail_smtp_host'] ?? ''));
    $port = (int)($settings['mail_smtp_port'] ?? 587);
    $encryption = strtolower(trim((string)($settings['mail_smtp_encryption'] ?? 'tls')));
    $username = trim((string)($settings['mail_smtp_username'] ?? ''));
    $password = (string)($settings['mail_smtp_password'] ?? '');

    if ($host === '') {
        throw new RuntimeException('SMTP host is required.');
    }

    $mailer->isSMTP();
    $mailer->Host = $host;
    $mailer->Port = $port;
    $mailer->CharSet = 'UTF-8';

    if ($encryption === 'tls') {
        $mailer->SMTPSecure = 'tls';
    } elseif ($encryption === 'ssl') {
        $mailer->SMTPSecure = 'ssl';
    }

    if ($username !== '') {
        $mailer->SMTPAuth = true;
        $mailer->Username = $username;
        $mailer->Password = $password;
    }

    $mailer->setFrom((string)$message['from_email'], (string)($message['from_name'] ?? 'Bonumark Stream'));

    $replyTo = trim((string)($message['reply_to'] ?? ''));
    if ($replyTo !== '') {
        $mailer->addReplyTo($replyTo);
    }

    foreach ($message['to'] ?? [] as $recipient) {
        $mailer->addAddress($recipient);
    }

    foreach ($message['cc'] ?? [] as $recipient) {
        $mailer->addCC($recipient);
    }

    foreach ($message['bcc'] ?? [] as $recipient) {
        $mailer->addBCC($recipient);
    }

    $mailer->Subject = bms_mail_validate_header_value((string)($message['subject'] ?? 'Bonumark Stream'));

    if (($message['body_format'] ?? 'plain_text') === 'html') {
        $mailer->isHTML(true);
        $mailer->Body = (string)($message['body'] ?? '');
        $mailer->AltBody = strip_tags((string)($message['body'] ?? ''));
    } else {
        $mailer->isHTML(false);
        $mailer->Body = (string)($message['body'] ?? '');
    }

    foreach ($message['attachments'] ?? [] as $attachment) {
        $mailer->addStringAttachment(
            (string)($attachment['content'] ?? ''),
            (string)($attachment['filename'] ?? 'attachment.txt'),
            'base64',
            (string)($attachment['content_type'] ?? 'application/octet-stream')
        );
    }

    $mailer->send();

    return [
        'transport' => 'smtp_phpmailer',
        'message' => 'PHPMailer SMTP accepted the message.',
    ];
}

function bms_mail_record_test_delivery(array $settings, array $message, string $status, ?string $errorMessage = null): int
{
    $stmt = bms_db()->prepare(
        'INSERT INTO ' . bms_table('mail_test_deliveries') . '
            (transport, body_format, recipient_to, subject, status, error_message, sent_at, triggered_by, created_at)
         VALUES
            (:transport, :body_format, :recipient_to, :subject, :status, :error_message, :sent_at, :triggered_by, NOW())'
    );

    $stmt->execute([
        'transport' => (string)($settings['mail_transport'] ?? 'disabled'),
        'body_format' => (string)($message['body_format'] ?? 'plain_text'),
        'recipient_to' => implode(', ', $message['to'] ?? []),
        'subject' => (string)($message['subject'] ?? 'Bonumark Stream Test Email'),
        'status' => in_array($status, ['sent', 'failed'], true) ? $status : 'failed',
        'error_message' => $errorMessage,
        'sent_at' => $status === 'sent' ? gmdate('Y-m-d H:i:s') : null,
        'triggered_by' => function_exists('bms_current_user') ? (int)(bms_current_user()['id'] ?? 0) : null,
    ]);

    return (int)bms_db()->lastInsertId();
}

function bms_mail_recent_test_deliveries(int $limit = 8): array
{
    $limit = max(1, min(25, $limit));

    try {
        $stmt = bms_db()->query('SELECT * FROM ' . bms_table('mail_test_deliveries') . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit);
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}
