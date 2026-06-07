<?php
/*                        ''~``
                         ( o o )
 +------------------.oooO--(_)--Oooo.--------------------+
 |                        CoreBB                         |
 |        Developed by -Prismatic- / HannsGruber         |
 |                Copyright (c) 2005 - 2026              |
 |                  All Rights Reserved.                 |
 |                    .oooO                              |
 |                    (   )   Oooo.                      |
 +---------------------\ (----(   )----------------------+
                        \_)    ) /
                              (_/

 +-------------------------------------------------------+
 |  mail_helpers.php  - Small mail helper for CoreBB.    |
 +-------------------------------------------------------+*/

if (!defined('COREBB_MAIL_HELPERS_LOADED')) {
    define('COREBB_MAIL_HELPERS_LOADED', true);
}

/**
 * Read a mail setting from constants, environment, or a supplied default.
 *
 * Usage: central configuration lookup for mail transport, sender, SMTP, and
 * debug settings.
 * Referenced by: mail helpers and password recovery diagnostics.
 *
 * @param string $key Constant/environment variable name.
 * @param mixed $default Value returned when no setting exists.
 * @return mixed Configured value or the supplied default.
 */
function corebb_mail_config(string $key, $default = '')
{
    if (defined($key)) {
        return constant($key);
    }
    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }

    return $default;
}

/**
 * Resolve the best public host name for neutral mail defaults.
 *
 * Usage: avoid shipping provider-specific default sender/SMTP values.
 * Referenced by: sender, Message-ID, and SMTP HELO helpers.
 *
 * @return string Host name safe for headers and HELO fallback.
 */
function corebb_mail_public_host(): string
{
    $host = '';
    $baseUrl = (string)corebb_mail_config('COREBB_PUBLIC_BASE_URL', '');
    if ($baseUrl !== '') {
        $parsedHost = parse_url($baseUrl, PHP_URL_HOST);
        $host = is_string($parsedHost) ? $parsedHost : '';
    }
    if ($host === '') {
        $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    }
    $host = preg_replace('/:\d+$/', '', strtolower(trim($host))) ?? '';
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?? '';
    return $host !== '' ? $host : 'localhost';
}

/**
 * Resolve the configured sender email address.
 *
 * Usage: populate From, Reply-To, and SMTP MAIL FROM values.
 * Referenced by: corebb_mail_send() and corebb_mail_send_smtp().
 *
 * @return string Sender email address.
 */
function corebb_mail_from_address(): string
{
    return (string)corebb_mail_config('COREBB_MAIL_FROM_ADDRESS', 'noreply@' . corebb_mail_public_host());
}

/**
 * Resolve the display name used in forum-generated mail.
 *
 * Usage: keep account, verification, and password reset messages branded to
 * the installed board instead of the source package.
 * Referenced by: sender name, verification mail, and password recovery mail.
 *
 * @return string Installed board name, site name, or a neutral CoreBB fallback.
 */
function corebb_mail_board_name(): string
{
    global $BoardName, $SiteName;

    $name = trim((string)($BoardName ?? $SiteName ?? ''));
    return $name !== '' ? $name : 'CoreBB';
}

/**
 * Resolve the configured sender display name.
 *
 * Usage: populate the From header for outbound forum mail.
 * Referenced by: corebb_mail_send().
 *
 * @return string Sender display name.
 */
function corebb_mail_from_name(): string
{
    return (string)corebb_mail_config('COREBB_MAIL_FROM_NAME', corebb_mail_board_name());
}

/**
 * Check whether private mail debugging is enabled.
 *
 * Usage: guard SMTP/PHP mail diagnostic writes.
 * Referenced by: corebb_mail_debug_log().
 *
 * @return bool True when mail debug logging is enabled.
 */
function corebb_mail_debug_enabled(): bool
{
    return (bool)corebb_mail_config('COREBB_MAIL_DEBUG', false);
}

/**
 * Write a sanitized mail diagnostic line.
 *
 * Usage: trace SMTP command/reply flow and final mail outcomes without logging
 * passwords or full message bodies.
 * Referenced by: PHP mail transport and SMTP helpers.
 *
 * @param string $message Short diagnostic message.
 * @param array<string, mixed> $context Extra non-sensitive context.
 * @return void
 */
function corebb_mail_debug_log(string $message, array $context = []): void
{
    if (!corebb_mail_debug_enabled()) {
        return;
    }

    $path = (string)corebb_mail_config('COREBB_MAIL_DEBUG_LOG', __DIR__ . '/../logs/mail_debug.log');
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    // Never log SMTP passwords or full message bodies.  This log is for SMTP
    // command/reply flow and final accept/reject details only.
    unset($context['password'], $context['body']);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Generate a unique Message-ID header for outbound mail.
 *
 * Usage: give each message a stable RFC-style id derived from the configured
 * public host.
 * Referenced by: corebb_mail_send().
 *
 * @return string Message-ID header value with angle brackets.
 */
function corebb_mail_message_id(): string
{
    $host = corebb_mail_public_host();
    return '<' . bin2hex(random_bytes(16)) . '@' . $host . '>';
}

/**
 * Resolve the optional debug BCC recipient.
 *
 * Usage: copy outbound messages to a test mailbox when explicitly configured.
 * Referenced by: corebb_mail_send() and corebb_mail_send_smtp().
 *
 * @return string Valid email address or an empty string.
 */
function corebb_mail_debug_bcc_address(): string
{
    $address = trim((string)corebb_mail_config('COREBB_MAIL_DEBUG_BCC_ADDRESS', ''));
    return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : '';
}

/**
 * Resolve the active mail transport name.
 *
 * Usage: decide between PHP mail(), SMTP, or disabled transport behavior.
 * Referenced by: corebb_mail_disabled(), corebb_mail_send(), and diagnostics.
 *
 * @return string Lowercase transport name.
 */
function corebb_mail_transport(): string
{
    return strtolower((string)corebb_mail_config('COREBB_MAIL_TRANSPORT', 'mail'));
}


/**
 * Check whether outbound mail should be treated as disabled.
 *
 * Usage: allow local/staging installs to suppress mail while preserving a
 * successful public flow.
 * Referenced by: corebb_mail_send() and password recovery diagnostics.
 *
 * @return bool True when mail should not leave the server.
 */
function corebb_mail_disabled(): bool
{
    if ((bool)corebb_mail_config('COREBB_DISABLE_MAIL', false)) {
        return true;
    }
    if ((bool)corebb_mail_config('COREBB_MAIL_DISABLED', false)) {
        return true;
    }
    return in_array(corebb_mail_transport(), ['disabled', 'none', 'null', 'off'], true);
}

/**
 * Resolve an optional redirect recipient for all outbound mail.
 *
 * Usage: route real messages to a test inbox during staging without changing
 * caller code.
 * Referenced by: corebb_mail_send() and password recovery diagnostics.
 *
 * @return string Valid redirect email address or an empty string.
 */
function corebb_mail_redirect_to(): string
{
    $address = trim((string)corebb_mail_config('COREBB_MAIL_REDIRECT_TO', ''));
    return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : '';
}

/**
 * Strip header-breaking characters from a mail header value.
 *
 * Usage: sanitize user-adjacent header values before composing PHP mail or SMTP
 * payloads.
 * Referenced by: corebb_mail_send() and corebb_mail_send_smtp().
 *
 * @param string $value Header value to sanitize.
 * @return string Header-safe value.
 */
function corebb_mail_header_encode(string $value): string
{
    $value = trim(str_replace(["\r", "\n"], '', $value));
    return $value;
}

/**
 * Send a plain-text outbound mail message through the configured transport.
 *
 * Usage: shared delivery path for account recovery, email verification, and
 * future forum notifications.
 * Referenced by: password recovery and email verification helpers.
 *
 * @param string $to Recipient email address.
 * @param string $subject Message subject.
 * @param string $body Plain-text message body.
 * @param array<string, string> $headers Additional headers to merge.
 * @return array{sent: bool, error: string, detail?: string} Delivery result.
 */
function corebb_mail_send(string $to, string $subject, string $body, array $headers = []): array
{
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'error' => 'Invalid recipient email address.'];
    }

    $redirectTo = corebb_mail_redirect_to();
    $originalTo = $to;
    if ($redirectTo !== '') {
        $to = $redirectTo;
        $body = "[CoreBB mail redirect]\nOriginal recipient: " . $originalTo . "\nOriginal subject: " . $subject . "\nEnvironment: " . (defined('COREBB_ENV') ? (string)COREBB_ENV : 'unknown') . "\n\n" . $body;
    }

    if (corebb_mail_disabled()) {
        corebb_mail_debug_log('Mail suppressed by config', [
            'to' => $to,
            'original_to' => $originalTo,
            'subject' => $subject,
            'environment' => defined('COREBB_ENV') ? (string)COREBB_ENV : 'unknown',
        ]);
        return ['sent' => true, 'error' => '', 'detail' => 'Mail disabled by CoreBB configuration.'];
    }

    $subject = corebb_mail_header_encode($subject);
    $fromAddress = corebb_mail_from_address();
    $fromName = corebb_mail_from_name();
    $baseHeaders = [
        'From' => corebb_mail_header_encode($fromName) . ' <' . corebb_mail_header_encode($fromAddress) . '>',
        'Reply-To' => corebb_mail_header_encode($fromAddress),
        'Date' => date('r'),
        'Message-ID' => corebb_mail_message_id(),
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'X-Mailer' => 'CoreBB',
    ];
    $headers = array_merge($baseHeaders, $headers);

    $debugBcc = corebb_mail_debug_bcc_address();
    if ($debugBcc !== '') {
        $headers['Bcc'] = '<' . corebb_mail_header_encode($debugBcc) . '>';
    }

    if (corebb_mail_transport() === 'smtp') {
        return corebb_mail_send_smtp($to, $subject, $body, $headers);
    }

    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = corebb_mail_header_encode((string)$name) . ': ' . corebb_mail_header_encode((string)$value);
    }

    $sent = @mail($to, $subject, $body, implode("\r\n", $headerLines));
    corebb_mail_debug_log('PHP mail() transport result', [
        'sent' => (bool)$sent,
        'to' => $to,
        'subject' => $subject,
        'from' => $fromAddress,
        'debug_bcc' => $debugBcc,
    ]);
    return ['sent' => (bool)$sent, 'error' => $sent ? '' : 'PHP mail() returned false.'];
}

/**
 * Read one SMTP reply, including continuation lines.
 *
 * Usage: consume server responses after each SMTP command.
 * Referenced by: corebb_mail_smtp_expect().
 *
 * @param resource $socket Open SMTP socket.
 * @return string Raw SMTP reply text.
 */
function corebb_mail_smtp_read($socket): string
{
    $data = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

/**
 * Add timeout context for empty SMTP replies.
 *
 * Usage: make SMTP error messages clearer when the socket timed out.
 * Referenced by: corebb_mail_smtp_expect().
 *
 * @param resource|false $socket SMTP socket or false.
 * @return string Timeout suffix or an empty string.
 */
function corebb_mail_smtp_timeout_error($socket): string
{
    $meta = is_resource($socket) ? stream_get_meta_data($socket) : [];
    return !empty($meta['timed_out']) ? ' timed out waiting for server reply.' : '';
}

/**
 * Build the best available STARTTLS crypto method bitmask.
 *
 * Usage: enable SMTP STARTTLS across PHP versions with different crypto
 * constants.
 * Referenced by: corebb_mail_send_smtp().
 *
 * @return int STREAM_CRYPTO_METHOD_* bitmask.
 */
function corebb_mail_smtp_crypto_method()
{
    $methods = 0;
    foreach ([
        'STREAM_CRYPTO_METHOD_TLS_CLIENT',
        'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT',
        'STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT',
    ] as $constant) {
        if (defined($constant)) {
            $methods |= constant($constant);
        }
    }
    return $methods !== 0 ? $methods : STREAM_CRYPTO_METHOD_TLS_CLIENT;
}

/**
 * Normalize SMTP security mode names.
 *
 * Usage: map configuration aliases and port 465 defaults into the transport
 * mode expected by the SMTP sender.
 * Referenced by: corebb_mail_send_smtp() and password recovery diagnostics.
 *
 * @param string $secure Configured security mode.
 * @param int $port Configured SMTP port.
 * @return string Normalized mode: tls, ssl, plain/empty, or a raw custom value.
 */
function corebb_mail_smtp_normalize_secure(string $secure, int $port): string
{
    $secure = strtolower(trim($secure));
    if ($secure === 'starttls') {
        return 'tls';
    }
    if ($secure === 'smtps' || $secure === 'implicit_tls' || $secure === 'implicit-tls') {
        return 'ssl';
    }

    // Port 465 is implicit SSL/TLS on most shared/cPanel-style hosts.
    // Treat a generic/blank/tls value on 465 as ssl so we do not wait forever for a
    // plaintext greeting that will never arrive.
    if ($port === 465 && ($secure === '' || $secure === 'tls' || $secure === 'auto')) {
        return 'ssl';
    }

    return $secure;
}

/**
 * Write one SMTP command and log a redacted diagnostic copy.
 *
 * Usage: send protocol commands while hiding base64 AUTH payloads from logs.
 * Referenced by: corebb_mail_send_smtp().
 *
 * @param resource $socket Open SMTP socket.
 * @param string $command SMTP command without line ending.
 * @return void
 */
function corebb_mail_smtp_write($socket, string $command): void
{
    $logCommand = $command;
    if (preg_match('/^[A-Za-z0-9+\/]+=*$/', $command)) {
        $logCommand = '[base64-auth-redacted]';
    }
    corebb_mail_debug_log('SMTP C', ['command' => $logCommand]);
    fwrite($socket, $command . "\r\n");
}

/**
 * Read an SMTP reply and verify it matches one of the expected codes.
 *
 * Usage: enforce protocol state transitions and return caller-friendly errors.
 * Referenced by: corebb_mail_send_smtp().
 *
 * @param resource $socket Open SMTP socket.
 * @param array<int, string> $codes Acceptable three-digit SMTP codes.
 * @param string $context Human-readable protocol step.
 * @return array{ok: bool, error: string, reply?: string} Check result.
 */
function corebb_mail_smtp_expect($socket, array $codes, string $context): array
{
    $reply = corebb_mail_smtp_read($socket);
    corebb_mail_debug_log('SMTP S', ['context' => $context, 'reply' => trim($reply)]);
    $code = substr($reply, 0, 3);
    if (!in_array($code, $codes, true)) {
        $detail = trim($reply);
        if ($detail === '') {
            $detail = 'no response from SMTP server;' . corebb_mail_smtp_timeout_error($socket);
        }
        return ['ok' => false, 'error' => $context . ' failed: ' . $detail];
    }
    return ['ok' => true, 'error' => '', 'reply' => $reply];
}

/**
 * Send a plain-text message through an authenticated SMTP connection.
 *
 * Usage: transport implementation selected by corebb_mail_send() when
 * COREBB_MAIL_TRANSPORT is smtp.
 * Referenced by: corebb_mail_send().
 *
 * @param string $to Recipient email address.
 * @param string $subject Sanitized subject.
 * @param string $body Plain-text message body.
 * @param array<string, string> $headers Headers to include in the DATA payload.
 * @return array{sent: bool, error: string, detail?: string} Delivery result.
 */
function corebb_mail_send_smtp(string $to, string $subject, string $body, array $headers): array
{
    $host = (string)corebb_mail_config('COREBB_SMTP_HOST', '');
    $port = (int)corebb_mail_config('COREBB_SMTP_PORT', 587);
    $secure = corebb_mail_smtp_normalize_secure((string)corebb_mail_config('COREBB_SMTP_SECURE', 'tls'), $port);
    $username = (string)corebb_mail_config('COREBB_SMTP_USERNAME', corebb_mail_from_address());
    $password = (string)corebb_mail_config('COREBB_SMTP_PASSWORD', '');
    $helo = (string)corebb_mail_config('COREBB_SMTP_HELO', corebb_mail_public_host());
    $timeout = (int)corebb_mail_config('COREBB_SMTP_TIMEOUT', 15);

    if ($host === '') {
        return ['sent' => false, 'error' => 'SMTP host is not configured.'];
    }
    if ($password === '') {
        return ['sent' => false, 'error' => 'SMTP password is not configured.'];
    }

    $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $context = stream_context_create([
        'ssl' => [
            'SNI_enabled' => true,
            'peer_name' => $host,
            'verify_peer' => (bool)corebb_mail_config('COREBB_SMTP_VERIFY_PEER', true),
            'verify_peer_name' => (bool)corebb_mail_config('COREBB_SMTP_VERIFY_PEER_NAME', true),
            'allow_self_signed' => (bool)corebb_mail_config('COREBB_SMTP_ALLOW_SELF_SIGNED', false),
        ],
    ]);
    $socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        return ['sent' => false, 'error' => 'SMTP connection failed: ' . $errstr];
    }
    stream_set_timeout($socket, $timeout);

    $check = corebb_mail_smtp_expect($socket, ['220'], 'SMTP greeting');
    if (!$check['ok']) { fclose($socket); return ['sent' => false, 'error' => $check['error']]; }

    corebb_mail_smtp_write($socket, 'EHLO ' . preg_replace('/[^A-Za-z0-9.\-]/', '', $helo));
    $check = corebb_mail_smtp_expect($socket, ['250'], 'EHLO');
    if (!$check['ok']) { fclose($socket); return ['sent' => false, 'error' => $check['error']]; }

    if ($secure === 'tls') {
        corebb_mail_smtp_write($socket, 'STARTTLS');
        $check = corebb_mail_smtp_expect($socket, ['220'], 'STARTTLS');
        if (!$check['ok']) { fclose($socket); return ['sent' => false, 'error' => $check['error']]; }
        if (!@stream_socket_enable_crypto($socket, true, corebb_mail_smtp_crypto_method())) {
            fclose($socket);
            return ['sent' => false, 'error' => 'Unable to enable SMTP STARTTLS. If using port 465, set COREBB_SMTP_SECURE to ssl.'];
        }
        corebb_mail_smtp_write($socket, 'EHLO ' . preg_replace('/[^A-Za-z0-9.\-]/', '', $helo));
        $check = corebb_mail_smtp_expect($socket, ['250'], 'EHLO after STARTTLS');
        if (!$check['ok']) { fclose($socket); return ['sent' => false, 'error' => $check['error']]; }
    }

    corebb_mail_smtp_write($socket, 'AUTH LOGIN');
    $check = corebb_mail_smtp_expect($socket, ['334'], 'SMTP AUTH');
    if (!$check['ok']) { fclose($socket); return ['sent' => false, 'error' => $check['error']]; }
    corebb_mail_smtp_write($socket, base64_encode($username));
    $check = corebb_mail_smtp_expect($socket, ['334'], 'SMTP username');
    if (!$check['ok']) { fclose($socket); return ['sent' => false, 'error' => $check['error']]; }
    corebb_mail_smtp_write($socket, base64_encode($password));
    $check = corebb_mail_smtp_expect($socket, ['235'], 'SMTP password');
    if (!$check['ok']) { fclose($socket); return ['sent' => false, 'error' => $check['error']]; }

    $from = corebb_mail_from_address();
    corebb_mail_smtp_write($socket, 'MAIL FROM:<' . $from . '>');
    $check = corebb_mail_smtp_expect($socket, ['250'], 'MAIL FROM');
    if (!$check['ok']) { fclose($socket); return ['sent' => false, 'error' => $check['error']]; }

    $recipients = [$to];
    $debugBcc = corebb_mail_debug_bcc_address();
    if ($debugBcc !== '' && strcasecmp($debugBcc, $to) !== 0) {
        $recipients[] = $debugBcc;
    }
    foreach ($recipients as $recipient) {
        corebb_mail_smtp_write($socket, 'RCPT TO:<' . $recipient . '>');
        $check = corebb_mail_smtp_expect($socket, ['250', '251'], 'RCPT TO ' . $recipient);
        if (!$check['ok']) { fclose($socket); return ['sent' => false, 'error' => $check['error']]; }
    }

    corebb_mail_smtp_write($socket, 'DATA');
    $check = corebb_mail_smtp_expect($socket, ['354'], 'DATA');
    if (!$check['ok']) { fclose($socket); return ['sent' => false, 'error' => $check['error']]; }

    $headerLines = [
        'To: <' . $to . '>',
        'Subject: ' . $subject,
    ];
    foreach ($headers as $name => $value) {
        $headerLines[] = corebb_mail_header_encode((string)$name) . ': ' . corebb_mail_header_encode((string)$value);
    }
    $message = implode("\r\n", $headerLines) . "\r\n\r\n" . str_replace("\n.", "\n..", str_replace("\r\n", "\n", $body));
    fwrite($socket, str_replace("\n", "\r\n", $message) . "\r\n.\r\n");
    $check = corebb_mail_smtp_expect($socket, ['250'], 'message send');
    corebb_mail_smtp_write($socket, 'QUIT');
    fclose($socket);

    if (!$check['ok']) {
        corebb_mail_debug_log('SMTP message rejected', ['to' => $to, 'subject' => $subject, 'error' => $check['error']]);
        return ['sent' => false, 'error' => $check['error']];
    }
    corebb_mail_debug_log('SMTP message accepted', [
        'to' => $to,
        'subject' => $subject,
        'from' => $from,
        'debug_bcc' => corebb_mail_debug_bcc_address(),
        'server_reply' => trim((string)($check['reply'] ?? '')),
    ]);
    return ['sent' => true, 'error' => '', 'detail' => trim((string)($check['reply'] ?? ''))];
}
