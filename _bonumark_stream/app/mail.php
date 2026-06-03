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
        'mail_sendmail_path' => '/usr/sbin/sendmail',
    ];
}

function bms_mail_settings(): array
{
    $settings = [];

    foreach (bms_mail_defaults() as $key => $default) {
        $settings[$key] = (string)bms_setting_or_config($key, $default);
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

function bms_mail_send_sendmail(array $settings, array $message): array
{
    $path = trim((string)($settings['mail_sendmail_path'] ?? '/usr/sbin/sendmail'));

    if ($path === '') {
        throw new RuntimeException('Sendmail path is required.');
    }

    if (!is_executable($path)) {
        throw new RuntimeException('Sendmail path is not executable: ' . $path);
    }

    $recipients = array_merge($message['to'] ?? [], $message['cc'] ?? [], $message['bcc'] ?? []);

    if (!$recipients) {
        throw new RuntimeException('At least one recipient is required.');
    }

    $command = escapeshellcmd($path) . ' -i';

    foreach ($recipients as $recipient) {
        $command .= ' ' . escapeshellarg($recipient);
    }

    $process = proc_open($command, [
        0 => ['pipe', 'w'],
        1 => ['pipe', 'r'],
        2 => ['pipe', 'r'],
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
        'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
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
