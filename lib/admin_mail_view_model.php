<?php
require_once __DIR__ . '/admin_log_helpers.php';
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

require_once __DIR__ . '/mail_helpers.php';

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
 * Usage: Decode simple scalar literals from private config define() calls.
 * Referenced by: corebb_admin_mail_file_defines().
 *
 * @param string $literal PHP literal from a one-line define() call.
 * @return mixed Decoded scalar value.
 */
function corebb_admin_mail_parse_literal(string $literal)
{
    $literal = trim($literal);
    $lower = strtolower($literal);
    if ($lower === 'true') { return true; }
    if ($lower === 'false') { return false; }
    if ($lower === 'null') { return null; }
    if (preg_match('/^-?\d+$/', $literal)) { return (int)$literal; }
    if (strlen($literal) >= 2 && (($literal[0] === "'" && substr($literal, -1) === "'") || ($literal[0] === '"' && substr($literal, -1) === '"'))) {
        return stripcslashes(substr($literal, 1, -1));
    }
    return $literal;
}

/**
 * Usage: Read current managed mail constants directly from the private config.
 * Referenced by: password preservation and page status helpers.
 *
 * @param string $path Private config file path.
 * @return array<string, mixed> Constants found in the config file.
 */
function corebb_admin_mail_file_defines(string $path): array
{
    if ($path === '' || !is_file($path)) {
        return [];
    }

    $content = (string)file_get_contents($path);
    $keys = array_fill_keys(corebb_admin_mail_managed_keys(), true);
    $values = [];
    if (preg_match_all('/define\s*\(\s*([\'"])([A-Z0-9_]+)\1\s*,\s*(.*?)\s*\)\s*;/s', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $key = (string)$match[2];
            if (isset($keys[$key])) {
                $values[$key] = corebb_admin_mail_parse_literal((string)$match[3]);
            }
        }
    }
    return $values;
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
    global $BoardName;

    return [
        'transport' => corebb_mail_transport(),
        'from_address' => (string)corebb_mail_config('COREBB_MAIL_FROM_ADDRESS', corebb_mail_from_address()),
        'from_name' => (string)corebb_mail_config('COREBB_MAIL_FROM_NAME', (string)($BoardName ?? 'CoreBB')),
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
 * @param array<string, mixed> $fileDefines Managed constants currently in the config file.
 * @return array<string, mixed> Normalized settings plus smtp_password.
 */
function corebb_admin_mail_normalize_post(array $post, array $fileDefines): array
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
    } elseif (array_key_exists('COREBB_SMTP_PASSWORD', $fileDefines)) {
        $password = (string)$fileDefines['COREBB_SMTP_PASSWORD'];
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
function corebb_admin_mail_strip_managed_config(string $content): string
{
    $content = preg_replace('~/\* COREBB MAIL CONFIG START \*/.*?/\* COREBB MAIL CONFIG END \*/\s*~s', '', $content) ?? $content;
    foreach (corebb_admin_mail_managed_keys() as $key) {
        $quoted = preg_quote($key, '/');
        $content = preg_replace('/^\s*define\s*\(\s*([\'"])' . $quoted . '\1\s*,\s*.*?\)\s*;\s*$/mi', '', $content) ?? $content;
    }
    return (string)preg_replace('/\?>\s*$/', '', $content);
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
        return ['ok' => false, 'message' => 'Private config file is not writable: ' . $path];
    }

    $content = (string)file_get_contents($path);
    $backupPath = $path . '.bak-' . date('YmdHis');
    @copy($path, $backupPath);

    $content = rtrim(corebb_admin_mail_strip_managed_config($content));
    $block = corebb_admin_mail_config_block(corebb_admin_mail_constants($values));
    $newContent = $content . "\n\n" . $block . "\n";
    if (@file_put_contents($path, $newContent, LOCK_EX) === false) {
        return ['ok' => false, 'message' => 'Unable to write private config file.'];
    }

    return ['ok' => true, 'message' => 'Mail services configuration saved. Use Send Test after this page reloads to test the saved constants.'];
}

/**
 * Usage: Save posted Mail Services settings and record the admin action.
 * Referenced by: corebb_admin_mail_model().
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $post Raw POST data.
 * @return array{values: array<string, mixed>, messages: array<int, string>, errors: array<int, string>}
 */
function corebb_admin_mail_save(array $viewer, array $post): array
{
    $path = corebb_admin_mail_config_path();
    $fileDefines = corebb_admin_mail_file_defines($path);
    $values = corebb_admin_mail_normalize_post($post, $fileDefines);
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

    return ['values' => $values, 'messages' => $messages, 'errors' => $errors];
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

    $subject = 'CoreBB mail services test';
    $body = "CoreBB test email\n\n"
        . "This message confirms that the currently loaded CoreBB mail configuration can send mail.\n"
        . "Transport: " . corebb_mail_transport() . "\n"
        . "Environment: " . (defined('COREBB_ENV') ? (string)COREBB_ENV : 'unknown') . "\n"
        . "Sent: " . date('Y-m-d H:i:s') . "\n";
    $result = corebb_mail_send($to, $subject, $body);
    if (!empty($result['sent'])) {
        {
            corebb_adminlog_entry(
                (string)($viewer['username'] ?? 'Unknown'),
                (int)($viewer['accesslevel'] ?? 0),
                'Sent mail services test email',
                'mail_services',
                'Sent a mail configuration test message to ' . $to . '.'
            );
        }
        return ['messages' => ['Test email accepted for delivery to ' . $to . '.'], 'errors' => []];
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
    $fileDefines = corebb_admin_mail_file_defines($path);

    $isPost = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    $action = (string)($post['action'] ?? '');
    if ($isPost && $action === 'save_mail') {
        $result = corebb_admin_mail_save($viewer, $post);
        $values = $result['values'];
        $messages = array_merge($messages, $result['messages']);
        $errors = array_merge($errors, $result['errors']);
    } elseif ($isPost && $action === 'send_test') {
        $result = corebb_admin_mail_send_test($viewer, $post);
        $messages = array_merge($messages, $result['messages']);
        $errors = array_merge($errors, $result['errors']);
    }

    $passwordConfigured = trim((string)corebb_mail_config('COREBB_SMTP_PASSWORD', '')) !== ''
        || trim((string)($fileDefines['COREBB_SMTP_PASSWORD'] ?? '')) !== '';

    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'config_path' => $path,
        'config_writable' => $path !== '' && is_file($path) && is_writable($path),
        'values' => $values,
        'messages' => $messages,
        'errors' => $errors,
        'password_configured' => $passwordConfigured,
        'loaded_transport' => corebb_mail_transport(),
        'test_email' => (string)($viewer['privemail'] ?? ''),
    ];
}
