<?php
require_once __DIR__ . '/../helpers/admin_log_helpers.php';
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
 |  admin_mail_view_model.php  - Mail service admin      |
 |  configuration model.                                 |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../helpers/mail_helpers.php';

/**
 * Usage: List private-config constants managed by the Mail Services admin page.
 * Referenced by: config parsing and config writing helpers in this file.
 *
 * @return array<int, string> Constant names.
 */
function corebb_admin_mail_managed_keys(): array
{
    return [
        'COREBB_MAIL_TRANSPORT',
        'COREBB_MAIL_FROM_ADDRESS',
        'COREBB_MAIL_FROM_NAME',
        'COREBB_SMTP_HOST',
        'COREBB_SMTP_PORT',
        'COREBB_SMTP_SECURE',
        'COREBB_SMTP_USERNAME',
        'COREBB_SMTP_PASSWORD',
        'COREBB_SMTP_HELO',
        'COREBB_SMTP_TIMEOUT',
        'COREBB_SMTP_VERIFY_PEER',
        'COREBB_SMTP_VERIFY_PEER_NAME',
        'COREBB_SMTP_ALLOW_SELF_SIGNED',
        'COREBB_MAIL_REDIRECT_TO',
        'COREBB_MAIL_DEBUG',
        'COREBB_MAIL_DEBUG_LOG',
        'COREBB_MAIL_DEBUG_BCC_ADDRESS',
    ];
}

/**
 * Usage: Locate the private config file loaded for the current forum instance.
 * Referenced by: Mail Services read/write helpers and template model.
 *
 * @return string Absolute config path, or an empty string if unavailable.
 */
function corebb_admin_mail_config_path(): string
{
    return defined('COREBB_PRIVATE_CONFIG_FILE') ? (string)COREBB_PRIVATE_CONFIG_FILE : '';
}

/**
 * Usage: Convert mixed checkbox/string input into a real boolean.
 * Referenced by: POST normalization and current setting reads.
 *
 * @param mixed $value Raw value.
 * @return bool True for common enabled values.
 */
function corebb_admin_mail_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Usage: Return the current loaded mail settings for display.
 * Referenced by: corebb_admin_mail_model().
 *
 * @return array<string, mixed> Template-facing mail settings.
 */
function corebb_admin_mail_current_values(): array
{
    return [
        'transport' => corebb_mail_transport(),
        'from_address' => corebb_mail_from_address(),
        'from_name' => corebb_mail_from_name(),
        'smtp_host' => (string)corebb_mail_config('COREBB_SMTP_HOST', ''),
        'smtp_port' => (int)corebb_mail_config('COREBB_SMTP_PORT', 587),
        'smtp_secure' => (string)corebb_mail_config('COREBB_SMTP_SECURE', 'tls'),
        'smtp_username' => (string)corebb_mail_config('COREBB_SMTP_USERNAME', corebb_mail_from_address()),
        'smtp_helo' => (string)corebb_mail_config('COREBB_SMTP_HELO', (string)($_SERVER['SERVER_NAME'] ?? 'localhost')),
        'smtp_timeout' => (int)corebb_mail_config('COREBB_SMTP_TIMEOUT', 15),
        'smtp_verify_peer' => corebb_admin_mail_bool(corebb_mail_config('COREBB_SMTP_VERIFY_PEER', true)),
        'smtp_verify_peer_name' => corebb_admin_mail_bool(corebb_mail_config('COREBB_SMTP_VERIFY_PEER_NAME', true)),
        'smtp_allow_self_signed' => corebb_admin_mail_bool(corebb_mail_config('COREBB_SMTP_ALLOW_SELF_SIGNED', false)),
        'redirect_to' => (string)corebb_mail_config('COREBB_MAIL_REDIRECT_TO', ''),
        'debug' => corebb_admin_mail_bool(corebb_mail_config('COREBB_MAIL_DEBUG', false)),
        'debug_log' => (string)corebb_mail_config('COREBB_MAIL_DEBUG_LOG', dirname(corebb_admin_mail_config_path()) . '/mail_debug.log'),
        'debug_bcc' => (string)corebb_mail_config('COREBB_MAIL_DEBUG_BCC_ADDRESS', ''),
    ];
}

/**
 * Usage: Normalize Mail Services POST data before validation and persistence.
 * Referenced by: save handling in corebb_admin_mail_model().
 *
 * @param array<string, mixed> $post Raw POST data.
 * @return array<string, mixed> Normalized settings plus smtp_password.
 */
function corebb_admin_mail_normalize_post(array $post): array
{
    $transport = strtolower(trim((string)($post['transport'] ?? 'mail')));
    if (!in_array($transport, ['mail', 'smtp', 'disabled'], true)) {
        $transport = 'mail';
    }

    $smtpSecure = strtolower(trim((string)($post['smtp_secure'] ?? 'tls')));
    if (!in_array($smtpSecure, ['tls', 'ssl', 'plain'], true)) {
        $smtpSecure = 'tls';
    }

    $passwordInput = (string)($post['smtp_password'] ?? '');
    $password = null;
    if (!empty($post['clear_smtp_password'])) {
        $password = '';
    } elseif ($passwordInput !== '') {
        $password = $passwordInput;
    }

    return [
        'transport' => $transport,
        'from_address' => trim((string)($post['from_address'] ?? '')),
        'from_name' => trim((string)($post['from_name'] ?? '')),
        'smtp_host' => trim((string)($post['smtp_host'] ?? '')),
        'smtp_port' => max(1, min(65535, (int)($post['smtp_port'] ?? 587))),
        'smtp_secure' => $smtpSecure,
        'smtp_username' => trim((string)($post['smtp_username'] ?? '')),
        'smtp_password' => $password,
        'smtp_helo' => trim((string)($post['smtp_helo'] ?? '')),
        'smtp_timeout' => max(1, min(120, (int)($post['smtp_timeout'] ?? 15))),
        'smtp_verify_peer' => !empty($post['smtp_verify_peer']),
        'smtp_verify_peer_name' => !empty($post['smtp_verify_peer_name']),
        'smtp_allow_self_signed' => !empty($post['smtp_allow_self_signed']),
        'redirect_to' => trim((string)($post['redirect_to'] ?? '')),
        'debug' => !empty($post['debug']),
        'debug_log' => trim((string)($post['debug_log'] ?? '')),
        'debug_bcc' => trim((string)($post['debug_bcc'] ?? '')),
    ];
}

/**
 * Usage: Validate normalized Mail Services settings before writing private config.
 * Referenced by: corebb_admin_mail_save().
 *
 * @param array<string, mixed> $values Normalized settings.
 * @return array<int, string> Validation errors.
 */
function corebb_admin_mail_validate(array $values): array
{
    $errors = [];
    if (!filter_var((string)$values['from_address'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'From address must be a valid email address.';
    }
    if ((string)$values['from_name'] === '') {
        $errors[] = 'From name is required.';
    }

    foreach (['redirect_to' => 'Mail redirect address', 'debug_bcc' => 'Debug BCC address'] as $key => $label) {
        $value = trim((string)($values[$key] ?? ''));
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $label . ' must be a valid email address or blank.';
        }
    }

    if ((string)$values['transport'] === 'smtp') {
        if ((string)$values['smtp_host'] === '') {
            $errors[] = 'SMTP host is required when SMTP transport is selected.';
        }
        if ((string)$values['smtp_username'] === '') {
            $errors[] = 'SMTP username is required when SMTP transport is selected.';
        }
        $password = $values['smtp_password'];
        $passwordAvailable = $password !== null
            ? (string)$password !== ''
            : trim((string)corebb_mail_config('COREBB_SMTP_PASSWORD', '')) !== '';
        if (!$passwordAvailable) {
            $errors[] = 'SMTP password is required when SMTP transport is selected.';
        }
    }

    return $errors;
}

/**
 * Usage: Convert normalized settings into private-config constant values.
 * Referenced by: corebb_admin_mail_write_config().
 *
 * @param array<string, mixed> $values Normalized settings.
 * @return array<string, mixed> Constant values keyed by constant name.
 */
function corebb_admin_mail_constants(array $values): array
{
    $constants = [
        'COREBB_MAIL_TRANSPORT' => (string)$values['transport'],
        'COREBB_MAIL_FROM_ADDRESS' => (string)$values['from_address'],
        'COREBB_MAIL_FROM_NAME' => (string)$values['from_name'],
        'COREBB_SMTP_HOST' => (string)$values['smtp_host'],
        'COREBB_SMTP_PORT' => (int)$values['smtp_port'],
        'COREBB_SMTP_SECURE' => (string)$values['smtp_secure'],
        'COREBB_SMTP_USERNAME' => (string)$values['smtp_username'],
        'COREBB_SMTP_HELO' => (string)$values['smtp_helo'],
        'COREBB_SMTP_TIMEOUT' => (int)$values['smtp_timeout'],
        'COREBB_SMTP_VERIFY_PEER' => (bool)$values['smtp_verify_peer'],
        'COREBB_SMTP_VERIFY_PEER_NAME' => (bool)$values['smtp_verify_peer_name'],
        'COREBB_SMTP_ALLOW_SELF_SIGNED' => (bool)$values['smtp_allow_self_signed'],
        'COREBB_MAIL_REDIRECT_TO' => (string)$values['redirect_to'],
        'COREBB_MAIL_DEBUG' => (bool)$values['debug'],
        'COREBB_MAIL_DEBUG_LOG' => (string)$values['debug_log'],
        'COREBB_MAIL_DEBUG_BCC_ADDRESS' => (string)$values['debug_bcc'],
    ];

    if ($values['smtp_password'] !== null) {
        $constants['COREBB_SMTP_PASSWORD'] = (string)$values['smtp_password'];
    }

    return $constants;
}

/**
 * Usage: Remove old managed mail definitions before appending the new block.
 * Referenced by: corebb_admin_mail_write_config().
 *
 * @param string $content Current private config file contents.
 * @return string Config contents without the managed mail block or old one-line mail defines.
 */
function corebb_admin_mail_strip_managed_config(string $content, bool $keepPassword): string
{
    // Token boundaries keep quoted passwords, comments and unrelated settings intact.
    $managed = array_fill_keys(corebb_admin_mail_managed_keys(), true);
    $tokens = PhpToken::tokenize($content);
    $output = '';
    $inBlock = false;
    $skipThrough = -1;
    foreach ($tokens as $index => $token) {
        if ($index <= $skipThrough) {
            continue;
        }
        if ($token->is(T_COMMENT) && $token->text === '/* COREBB MAIL CONFIG START */') {
            $inBlock = true;
            continue;
        }
        if ($token->is(T_COMMENT) && $token->text === '/* COREBB MAIL CONFIG END */') {
            $inBlock = false;
            continue;
        }
        if ($token->is(T_STRING) && strtolower($token->text) === 'define'
            && preg_match('/(?:^|\n)\h*$/D', substr($content, 0, $token->pos))
            && preg_match('/\Gdefine\s*\(\s*([\'"])([A-Z0-9_]+)\1\s*,/i', $content, $match, 0, $token->pos)
            && isset($managed[$match[2]])) {
            // Find the end of the call without interpreting its value.
            $depth = 0;
            for ($end = $index + 1; isset($tokens[$end]); ++$end) {
                $part = $tokens[$end];
                if ($part->is('(')) { ++$depth; }
                if ($part->is(')') && --$depth === 0) {
                    do { ++$end; } while (isset($tokens[$end]) && $tokens[$end]->is(T_WHITESPACE));
                    if (!isset($tokens[$end]) || !$tokens[$end]->is(';')) {
                        break;
                    }
                    $skipThrough = $end;
                    if ($keepPassword && $match[2] === 'COREBB_SMTP_PASSWORD') {
                        $output .= substr($content, $token->pos, $tokens[$end]->pos + 1 - $token->pos) . "\n";
                    } elseif (!$inBlock) {
                        $output .= ';';
                    }
                    continue 2;
                }
            }
        }
        if (!$inBlock) {
            $output .= $token->text;
        }
    }
    return (string)preg_replace('/\?>\s*$/', '', $output);
}

/**
 * Usage: Build the marked private-config block owned by the admin mail tool.
 * Referenced by: corebb_admin_mail_write_config().
 *
 * @param array<string, mixed> $constants Constant values to write.
 * @return string PHP config block.
 */
function corebb_admin_mail_config_block(array $constants): string
{
    $lines = [
        '/* COREBB MAIL CONFIG START */',
        '/* Managed by Admin > Mail Services. Keep this file outside web access. */',
    ];
    foreach ($constants as $key => $value) {
        $lines[] = 'define(' . var_export($key, true) . ', ' . var_export($value, true) . ');';
    }
    $lines[] = '/* COREBB MAIL CONFIG END */';
    return implode("\n", $lines);
}

/**
 * Usage: Persist normalized mail settings to the loaded private config file.
 * Referenced by: corebb_admin_mail_save().
 *
 * @param string $path Private config file path.
 * @param array<string, mixed> $values Normalized settings.
 * @return array{ok: bool, message: string}
 */
function corebb_admin_mail_write_config(string $path, array $values): array
{
    if ($path === '' || !is_file($path)) {
        return ['ok' => false, 'message' => 'Private config file could not be located.'];
    }
    if (!is_writable($path)) {
        return ['ok' => false, 'message' => 'The private configuration file is read-only.'];
    }

    $content = @file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return ['ok' => false, 'message' => 'Unable to read the private configuration file.'];
    }
    if (!class_exists(PhpToken::class)) {
        return ['ok' => false, 'message' => 'Saving mail settings requires the PHP tokenizer extension.'];
    }
    $content = rtrim(corebb_admin_mail_strip_managed_config($content, $values['smtp_password'] === null));
    $block = corebb_admin_mail_config_block(corebb_admin_mail_constants($values));
    $newContent = $content . "\n\n" . $block . "\n";
    try {
        PhpToken::tokenize($newContent, TOKEN_PARSE);
    } catch (ParseError $error) {
        return ['ok' => false, 'message' => 'The private configuration could not be updated safely. Check its PHP syntax.'];
    }
    $backupPath = $path . '.bak-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    if (!@copy($path, $backupPath)) {
        return ['ok' => false, 'message' => 'Unable to back up the private configuration file.'];
    }

    if (@file_put_contents($path, $newContent, LOCK_EX) === false) {
        return ['ok' => false, 'message' => 'Unable to write private config file.'];
    }

    return ['ok' => true, 'message' => 'Mail settings saved. Open Test delivery to try the saved settings.'];
}

/**
 * Usage: Save posted Mail Services settings and record the admin action.
 * Referenced by: corebb_admin_mail_model().
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $post Raw POST data.
 * @return array{values: array<string, mixed>, password_configured: bool, messages: array<int, string>, errors: array<int, string>}
 */
function corebb_admin_mail_save(array $viewer, array $post): array
{
    $path = corebb_admin_mail_config_path();
    $values = corebb_admin_mail_normalize_post($post);
    $errors = corebb_admin_mail_validate($values);
    $messages = [];

    if (!$errors) {
        $write = corebb_admin_mail_write_config($path, $values);
        if (!empty($write['ok'])) {
            $messages[] = $write['message'];
            {
                corebb_adminlog_entry(
                    (string)($viewer['username'] ?? 'Unknown'),
                    (int)($viewer['accesslevel'] ?? 0),
                    'Updated mail services configuration',
                    'mail_services',
                    'Updated private config mail transport settings.'
                );
            }
        } else {
            $errors[] = $write['message'];
        }
    }

    $passwordConfigured = (string)corebb_mail_config('COREBB_SMTP_PASSWORD', '') !== '';
    if (!$errors && $values['smtp_password'] !== null) {
        $passwordConfigured = $values['smtp_password'] !== '';
    }
    unset($values['smtp_password']);
    return ['values' => $values, 'password_configured' => $passwordConfigured, 'messages' => $messages, 'errors' => $errors];
}

/**
 * Usage: Send a small diagnostic email using the currently loaded config.
 * Referenced by: corebb_admin_mail_model().
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $post Raw POST data.
 * @return array{messages: array<int, string>, errors: array<int, string>}
 */
function corebb_admin_mail_send_test(array $viewer, array $post): array
{
    $to = trim((string)($post['test_email'] ?? $viewer['privemail'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['messages' => [], 'errors' => ['Enter a valid test recipient email address.']];
    }

    if (corebb_mail_disabled()) {
        return ['messages' => [], 'errors' => ['Outgoing mail is disabled. No test message was sent.']];
    }

    $subject = 'CoreBB mail services test';
    $body = "CoreBB test email\n\n"
        . "This message confirms that the currently loaded CoreBB mail configuration can send mail.\n"
        . "Transport: " . corebb_mail_transport() . "\n"
        . "Environment: " . (defined('COREBB_ENV') ? (string)COREBB_ENV : 'unknown') . "\n"
        . "Sent: " . date('Y-m-d H:i:s') . "\n";
    $result = corebb_mail_send($to, $subject, $body);
    if (!empty($result['sent'])) {
        $destination = corebb_mail_redirect_to() ?: $to;
        corebb_adminlog_entry(
            (string)($viewer['username'] ?? 'Unknown'),
            (int)($viewer['accesslevel'] ?? 0),
            'Sent mail services test email',
            'mail_services',
            'Sent a mail configuration test message to ' . $destination . '.'
        );
        return ['messages' => ['Test email accepted for delivery to ' . $destination . '.'], 'errors' => []];
    }

    return ['messages' => [], 'errors' => ['Test email failed: ' . (string)($result['error'] ?? 'Unknown mail error.')]];
}

/**
 * Usage: Build and process the Mail Services admin page model.
 * Referenced by: admin route handlers.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $request Query/request values from admin.php.
 * @param array<string, mixed> $post Posted form data from admin.php.
 * @return array<string, mixed> Data prepared for the admin template.
 */
function corebb_admin_mail_model(array $viewer, array $request, array $post): array
{
    $messages = [];
    $errors = [];
    $values = corebb_admin_mail_current_values();
    $path = corebb_admin_mail_config_path();

    $section = ($request['section'] ?? '') === 'test' ? 'test' : 'settings';
    $passwordConfigured = (string)corebb_mail_config('COREBB_SMTP_PASSWORD', '') !== '';
    $isPost = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    $action = (string)($post['action'] ?? '');
    if ($isPost && $action === 'save_mail') {
        $result = corebb_admin_mail_save($viewer, $post);
        $section = 'settings';
        $passwordConfigured = $result['password_configured'];
        $values = $result['values'];
        $messages = array_merge($messages, $result['messages']);
        $errors = array_merge($errors, $result['errors']);
    } elseif ($isPost && $action === 'send_test') {
        $section = 'test';
        $result = corebb_admin_mail_send_test($viewer, $post);
        $messages = array_merge($messages, $result['messages']);
        $errors = array_merge($errors, $result['errors']);
    }

    return [
        'section' => $section,
        'config_writable' => $path !== '' && is_file($path) && is_writable($path),
        'values' => $values,
        'messages' => $messages,
        'errors' => $errors,
        'password_configured' => $passwordConfigured,
        'delivery_disabled' => corebb_mail_disabled(),
        'delivery_suppressed' => (bool)corebb_mail_config('COREBB_DISABLE_MAIL', false)
            || (bool)corebb_mail_config('COREBB_MAIL_DISABLED', false),
        'redirect_to' => corebb_mail_redirect_to(),
        'debug_bcc' => corebb_mail_debug_bcc_address(),
        'test_email' => (string)($post['test_email'] ?? $viewer['privemail'] ?? ''),
    ];
}
