<?php
/**
 * Password/account recovery helpers for CoreBB.
 */
require_once __DIR__ . '/auth_password_helpers.php';
require_once __DIR__ . '/mail_helpers.php';

if (!defined('COREBB_PASSWORD_RECOVERY_HELPERS_LOADED')) {
    define('COREBB_PASSWORD_RECOVERY_HELPERS_LOADED', true);
}

/**
 * Ensure the password reset token table exists.
 *
 * Usage: call before issuing, pruning, looking up, or completing reset tokens.
 * Referenced by: password recovery request, token lookup, pruning, and complete
 * helpers.
 *
 * @return void
 */
function corebb_password_recovery_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    db_run("CREATE TABLE IF NOT EXISTS password_resets (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        email VARCHAR(255) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        expires_at INT UNSIGNED NOT NULL,
        used_at DATETIME NULL DEFAULT NULL,
        request_ip VARCHAR(64) NULL DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY token_hash (token_hash),
        KEY email (email),
        KEY user_id (user_id),
        KEY expires_at (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Return the current Unix timestamp for recovery expiry checks.
 *
 * Usage: keep token creation and validation time reads consistent.
 * Referenced by: pruning, rate limiting, token creation, and token status.
 *
 * @return int Current Unix timestamp.
 */
function corebb_password_recovery_now(): int
{
    return time();
}

/**
 * Resolve the public base URL used in reset links.
 *
 * Usage: build absolute reset URLs for email messages.
 * Referenced by: corebb_password_recovery_url().
 *
 * @return string Public site base URL without a trailing slash.
 */
function corebb_password_recovery_public_base_url(): string
{
    $base = trim((string)corebb_password_recovery_mail_config('COREBB_PUBLIC_BASE_URL', ''));
    if ($base !== '') {
        return rtrim($base, '/');
    }

    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    return $scheme . '://' . (function_exists('corebb_mail_public_host') ? corebb_mail_public_host() : 'localhost');
}

/**
 * Build a public reset-password URL for one raw token.
 *
 * Usage: include the reset link in outbound recovery mail.
 * Referenced by: corebb_password_recovery_send_request().
 *
 * @param string $token Raw reset token.
 * @return string Absolute reset URL.
 */
function corebb_password_recovery_url(string $token): string
{
    return corebb_password_recovery_public_base_url() . '/reset-password/?token=' . rawurlencode($token);
}

/**
 * Normalize an email address for lookup and storage.
 *
 * Usage: compare private email addresses without preserving user input casing
 * or whitespace.
 * Referenced by: lookup and recovery request helpers.
 *
 * @param string $email Email address from form input or database.
 * @return string Lowercase trimmed email address.
 */
function corebb_password_recovery_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

/**
 * Find the first user account matching a private email address.
 *
 * Usage: identify the account that should receive a reset token while keeping
 * public responses generic.
 * Referenced by: corebb_password_recovery_send_request().
 *
 * @param string $email Submitted email address.
 * @return array<string, mixed>|null Matching user row or null.
 */
function corebb_password_recovery_lookup_user_by_email(string $email): ?array
{
    $email = corebb_password_recovery_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $row = db_one('SELECT id, username, privemail, status FROM users WHERE LOWER(privemail) = LOWER(?) ORDER BY id ASC LIMIT 1', [$email]);
    return is_array($row) ? $row : null;
}

/**
 * Resolve the request IP for password recovery records.
 *
 * Usage: store support/audit context with issued reset tokens.
 * Referenced by: corebb_password_recovery_send_request().
 *
 * @return string Client IP from the shared security helper or REMOTE_ADDR.
 */
function corebb_password_recovery_request_ip(): string
{
    if (function_exists('corebb_security_client_ip')) {
        return (string)corebb_security_client_ip();
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Check whether a user already has a recent unused reset request.
 *
 * Usage: suppress repeated recovery emails within a short cooldown.
 * Referenced by: corebb_password_recovery_send_request().
 *
 * @param int $userId User id to check.
 * @param int $seconds Cooldown window in seconds.
 * @return bool True when a recent unused request exists.
 */
function corebb_password_recovery_recent_request_exists(int $userId, int $seconds = 300): bool
{
    corebb_password_recovery_ensure_schema();
    $cutoff = date('Y-m-d H:i:s', corebb_password_recovery_now() - $seconds);
    return db_exists('SELECT id FROM password_resets WHERE user_id = ? AND used_at IS NULL AND created_at >= ? LIMIT 1', [$userId, $cutoff]);
}

/**
 * Delete stale password reset rows.
 *
 * Usage: clean used and expired reset tokens before issuing a new request.
 * Referenced by: corebb_password_recovery_send_request().
 *
 * @return void
 */
function corebb_password_recovery_prune_old(): void
{
    corebb_password_recovery_ensure_schema();
    db_run('DELETE FROM password_resets WHERE used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
    db_run('DELETE FROM password_resets WHERE expires_at < ?', [corebb_password_recovery_now() - 86400]);
}

/**
 * Interpret common config truthy values.
 *
 * Usage: parse verbose-status configuration from constants or environment.
 * Referenced by: corebb_password_recovery_verbose_status_enabled().
 *
 * @param mixed $value Config value to parse.
 * @return bool True for 1/true/yes/on and true booleans.
 */
function corebb_password_recovery_truthy($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value !== 0;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Check whether password recovery should expose diagnostic status rows.
 *
 * Usage: show mail/setup diagnostics on staging while suppressing them on live.
 * Referenced by: status row helpers and diagnostics builder.
 *
 * @return bool True when debug status rows may be returned.
 */
function corebb_password_recovery_verbose_status_enabled(): bool
{
    // Never show recovery/mail diagnostics on the live production environment.
    if (defined('COREBB_ENV') && (string)COREBB_ENV === 'live') {
        return false;
    }

    $verbose = corebb_password_recovery_mail_config('COREBB_PASSWORD_RECOVERY_VERBOSE_STATUS', null);
    if ($verbose !== null) {
        return corebb_password_recovery_truthy($verbose);
    }

    // Staging is the diagnostics environment, and it is normally directory protected.
    return defined('COREBB_ENV') && (string)COREBB_ENV === 'staging';
}

/**
 * Build one recovery diagnostic row.
 *
 * Usage: keep debug/status rows consistently shaped for the Twig view.
 * Referenced by: diagnostic collection and status-add helpers.
 *
 * @param string $step Diagnostic step name.
 * @param string $status Short status value.
 * @param string $detail Optional supporting detail.
 * @return array{step: string, status: string, detail: string} Status row.
 */
function corebb_password_recovery_status_row(string $step, string $status, string $detail = ''): array
{
    return [
        'step' => $step,
        'status' => $status,
        'detail' => $detail,
    ];
}

/**
 * Append a diagnostic row when verbose recovery status is enabled.
 *
 * Usage: collect local/staging setup notes without changing live responses.
 * Referenced by: corebb_password_recovery_send_request().
 *
 * @param array<int, array<string, string>> $statusRows Status rows by reference.
 * @param string $step Diagnostic step name.
 * @param string $status Short status value.
 * @param string $detail Optional supporting detail.
 * @return void
 */
function corebb_password_recovery_status_add(array &$statusRows, string $step, string $status, string $detail = ''): void
{
    if (!corebb_password_recovery_verbose_status_enabled()) {
        return;
    }
    $statusRows[] = corebb_password_recovery_status_row($step, $status, $detail);
}

/**
 * Attach diagnostic rows to a recovery result when allowed.
 *
 * Usage: keep the public response shape clean on live while exposing staging
 * mail diagnostics.
 * Referenced by: corebb_password_recovery_send_request().
 *
 * @param array<string, mixed> $result Public recovery result.
 * @param array<int, array<string, string>> $statusRows Diagnostic rows.
 * @return array<string, mixed> Result with optional debug_status.
 */
function corebb_password_recovery_return(array $result, array $statusRows): array
{
    if (corebb_password_recovery_verbose_status_enabled()) {
        $result['debug_status'] = $statusRows;
    }
    return $result;
}

/**
 * Read mail configuration through the shared mail helper.
 *
 * Usage: keep password recovery mail setup aligned with the rest of CoreBB.
 * Referenced by: recovery URL and mail diagnostic helpers.
 *
 * @param string $key Constant/environment variable name.
 * @param mixed $default Fallback value.
 * @return mixed Configured value or fallback.
 */
function corebb_password_recovery_mail_config(string $key, $default = '')
{
    return corebb_mail_config($key, $default);
}

/**
 * Build password recovery mail diagnostics for staging/local troubleshooting.
 *
 * Usage: explain mail transport, redirect, debug-log, and SMTP configuration
 * state after a recovery request.
 * Referenced by: corebb_password_recovery_send_request().
 *
 * @return array<int, array<string, string>> Diagnostic rows, or empty on live.
 */
function corebb_password_recovery_mail_diagnostics_rows(): array
{
    if (!corebb_password_recovery_verbose_status_enabled()) {
        return [];
    }

    $rows = [];
    $env = defined('COREBB_ENV') ? (string)COREBB_ENV : 'unknown';
    $rows[] = corebb_password_recovery_status_row('Environment', $env, 'Recovery diagnostics are suppressed automatically on live.');

    $transport = corebb_mail_transport();
    $disabled = corebb_mail_disabled();
    $redirect = corebb_mail_redirect_to();
    $debug = corebb_mail_debug_enabled();
    $debugLog = (string)corebb_password_recovery_mail_config('COREBB_MAIL_DEBUG_LOG', __DIR__ . '/../logs/mail_debug.log');

    $rows[] = corebb_password_recovery_status_row('Mail disabled', $disabled ? 'yes' : 'no', 'COREBB_DISABLE_MAIL / COREBB_MAIL_DISABLED / disabled transport check.');
    $rows[] = corebb_password_recovery_status_row('Mail transport', $transport !== '' ? $transport : 'mail', 'COREBB_MAIL_TRANSPORT.');
    $rows[] = corebb_password_recovery_status_row('Mail redirect', $redirect !== '' ? 'enabled' : 'off', $redirect !== '' ? 'All outbound mail redirects to ' . $redirect . '.' : 'No COREBB_MAIL_REDIRECT_TO address is configured.');
    $rows[] = corebb_password_recovery_status_row('Mail debug log', $debug ? 'enabled' : 'off', $debug ? $debugLog : 'Set COREBB_MAIL_DEBUG true to write SMTP/PHP mail flow to a private log.');

    if ($transport === 'smtp') {
        $host = (string)corebb_password_recovery_mail_config('COREBB_SMTP_HOST', '');
        $port = (int)corebb_password_recovery_mail_config('COREBB_SMTP_PORT', 587);
        $secureRaw = (string)corebb_password_recovery_mail_config('COREBB_SMTP_SECURE', 'tls');
        $secure = corebb_mail_smtp_normalize_secure($secureRaw, $port);
        $username = (string)corebb_password_recovery_mail_config('COREBB_SMTP_USERNAME', corebb_mail_from_address());
        $password = (string)corebb_password_recovery_mail_config('COREBB_SMTP_PASSWORD', '');

        $rows[] = corebb_password_recovery_status_row('SMTP target', $host . ':' . $port, 'secure=' . ($secure !== '' ? $secure : 'plain') . ' (raw setting: ' . ($secureRaw !== '' ? $secureRaw : 'blank') . ')');
        $rows[] = corebb_password_recovery_status_row('SMTP username', $username !== '' ? 'configured' : 'missing', $username !== '' ? 'Username value is set; hidden from debug output.' : 'COREBB_SMTP_USERNAME is empty.');
        $rows[] = corebb_password_recovery_status_row('SMTP password', $password !== '' ? 'configured' : 'missing', $password !== '' ? 'Password value is set; hidden from debug output.' : 'COREBB_SMTP_PASSWORD is empty.');
    } else {
        $rows[] = corebb_password_recovery_status_row('PHP mail()', function_exists('mail') ? 'available' : 'missing', 'Current transport is not SMTP.');
    }

    return $rows;
}

/**
 * Issue a password reset request for an email address.
 *
 * Usage: public recover-account form action; always uses a generic success
 * response for account privacy unless mail delivery itself fails.
 * Referenced by: controllers/auth.php action=recover.
 *
 * @param string $email Submitted email address.
 * @return array<string, mixed> Public result with optional mail_error/debug rows.
 */
function corebb_password_recovery_send_request(string $email): array
{
    $statusRows = corebb_password_recovery_mail_diagnostics_rows();
    corebb_password_recovery_status_add($statusRows, 'Schema', 'checking', 'Ensuring password_resets table exists and pruning old requests.');

    corebb_password_recovery_ensure_schema();
    corebb_password_recovery_prune_old();
    corebb_password_recovery_status_add($statusRows, 'Schema', 'ok', 'password_resets table is available.');

    $generic = [
        'ok' => true,
        'message' => 'If that email address belongs to an account, a password reset link has been sent.',
        'mail_error' => '',
    ];

    $email = corebb_password_recovery_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Keep the same public result shape. The form can still nudge the user
        // client-side/visually, but do not reveal anything about accounts here.
        corebb_password_recovery_status_add($statusRows, 'Input email', 'invalid', 'No mail attempted because the submitted value is not a valid email address.');
        return corebb_password_recovery_return($generic, $statusRows);
    }
    corebb_password_recovery_status_add($statusRows, 'Input email', 'valid', 'Email normalized and passed validation.');

    $user = corebb_password_recovery_lookup_user_by_email($email);
    if (!$user) {
        corebb_password_recovery_status_add($statusRows, 'Account lookup', 'no match', 'No user row matched that private email address. Public message remains generic.');
        return corebb_password_recovery_return($generic, $statusRows);
    }
    corebb_password_recovery_status_add($statusRows, 'Account lookup', 'matched', 'Matched user id ' . (int)($user['id'] ?? 0) . '.');

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        corebb_password_recovery_status_add($statusRows, 'Account lookup', 'invalid user id', 'Matched row did not contain a usable user id.');
        return corebb_password_recovery_return($generic, $statusRows);
    }

    if (corebb_password_recovery_recent_request_exists($userId)) {
        corebb_password_recovery_status_add($statusRows, 'Rate limit', 'suppressed', 'A recent unused password reset request already exists for this user, so no new email was sent. Wait about 5 minutes or use a different test account.');
        return corebb_password_recovery_return($generic, $statusRows);
    }
    corebb_password_recovery_status_add($statusRows, 'Rate limit', 'ok', 'No recent unused reset request blocked this send.');

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = corebb_password_recovery_now() + 3600;
    $created = date('Y-m-d H:i:s', corebb_password_recovery_now());
    $ip = corebb_password_recovery_request_ip();

    // Only one active reset link per user keeps testing and support sane.
    db_run('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
    db_run('INSERT INTO password_resets (user_id, email, token_hash, created_at, expires_at, request_ip) VALUES (?, ?, ?, ?, ?, ?)', [
        $userId,
        $email,
        $tokenHash,
        $created,
        $expires,
        $ip,
    ]);

    $dbError = (string)db_error();
    if ($dbError !== '') {
        corebb_password_recovery_status_add($statusRows, 'Reset token', 'database warning', $dbError);
    } else {
        corebb_password_recovery_status_add($statusRows, 'Reset token', 'created', 'Token row inserted and previous unused tokens were marked used.');
    }

    $resetUrl = corebb_password_recovery_url($token);
    $username = (string)($user['username'] ?? 'there');
    $boardName = function_exists('corebb_mail_board_name') ? corebb_mail_board_name() : 'CoreBB';
    $body = "Hello " . $username . ",\n\n"
        . "A password reset was requested for your " . $boardName . " account.\n\n"
        . "Reset your password here:\n"
        . $resetUrl . "\n\n"
        . "This link expires in 1 hour. If you did not request this, you can ignore this message.\n\n"
        . "- " . $boardName;

    corebb_password_recovery_status_add($statusRows, 'Mail send', 'attempting', 'Calling CoreBB mail helper. Message body and reset token are hidden from debug output.');
    $mail = corebb_mail_send($email, 'Reset your ' . $boardName . ' password', $body);
    if (empty($mail['sent'])) {
        corebb_password_recovery_status_add($statusRows, 'Mail send', 'failed', (string)($mail['error'] ?? 'Unknown mail error.'));
        // Keep account existence private, but expose the send failure because the
        // site owner needs it while wiring this up.
        return corebb_password_recovery_return([
            'ok' => false,
            'message' => 'A reset request was created, but the email could not be sent.',
            'mail_error' => (string)($mail['error'] ?? 'Unknown mail error.'),
        ], $statusRows);
    }

    corebb_password_recovery_status_add($statusRows, 'Mail send', 'accepted', (string)($mail['detail'] ?? 'Mail helper reported sent.'));
    return corebb_password_recovery_return($generic, $statusRows);
}

/**
 * Extract and normalize a reset token from user input or a pasted URL.
 *
 * Usage: accept either a raw token or a full reset URL on the reset-password
 * form.
 * Referenced by: controllers/auth.php action=reset and token lookup.
 *
 * @param string $token Raw token or URL containing token=.
 * @return string Normalized token candidate.
 */
function corebb_password_recovery_clean_token(string $token): string
{
    $token = trim($token);
    if (preg_match('~[?&]token=([^&\s]+)~', $token, $m)) {
        $token = (string)$m[1];
    }
    $token = rawurldecode($token);
    $token = preg_replace('/\s+/', '', $token) ?? '';
    $token = trim($token, " \t\n\r\0\x0B<>\"'.,;)");
    return $token;
}

/**
 * Look up a password reset row by raw token.
 *
 * Usage: validate reset links and recover the target account row.
 * Referenced by: corebb_password_recovery_token_status().
 *
 * @param string $token Raw reset token or URL containing token=.
 * @return array<string, mixed>|null Reset row with username or null.
 */
function corebb_password_recovery_lookup_token(string $token): ?array
{
    corebb_password_recovery_ensure_schema();
    $token = corebb_password_recovery_clean_token($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return null;
    }

    $hash = hash('sha256', $token);
    $row = db_one('SELECT pr.*, u.username FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token_hash = ? LIMIT 1', [$hash]);
    return is_array($row) ? $row : null;
}

/**
 * Determine whether a reset token is usable.
 *
 * Usage: drive the reset-password form state before accepting a new password.
 * Referenced by: controllers/auth.php action=reset and complete helper.
 *
 * @param string $token Raw reset token or URL containing token=.
 * @return array{valid: bool, row: array<string, mixed>|null, message: string} Token status.
 */
function corebb_password_recovery_token_status(string $token): array
{
    $row = corebb_password_recovery_lookup_token($token);
    if (!$row) {
        return ['valid' => false, 'row' => null, 'message' => 'This password reset link is invalid.'];
    }
    if (!empty($row['used_at'])) {
        return ['valid' => false, 'row' => $row, 'message' => 'This password reset link has already been used.'];
    }
    if ((int)($row['expires_at'] ?? 0) < corebb_password_recovery_now()) {
        return ['valid' => false, 'row' => $row, 'message' => 'This password reset link has expired.'];
    }
    return ['valid' => true, 'row' => $row, 'message' => 'Enter a new password for your account.'];
}

/**
 * Complete a password reset with a valid token and matching new passwords.
 *
 * Usage: reset-password form action; updates the account password, revokes
 * persistent-login tokens, and marks reset tokens used in one transaction.
 * Referenced by: controllers/auth.php action=reset.
 *
 * @param string $token Raw reset token or URL containing token=.
 * @param string $pass1 New password.
 * @param string $pass2 Confirmation password.
 * @return array{ok: bool, message: string} Completion result.
 */
function corebb_password_recovery_complete(string $token, string $pass1, string $pass2): array
{
    $status = corebb_password_recovery_token_status($token);
    if (empty($status['valid']) || empty($status['row']) || !is_array($status['row'])) {
        return ['ok' => false, 'message' => (string)($status['message'] ?? 'This password reset link is invalid.')];
    }

    if ($pass1 === '' || $pass2 === '') {
        return ['ok' => false, 'message' => 'Please enter and confirm your new password.'];
    }
    if ($pass1 !== $pass2) {
        return ['ok' => false, 'message' => 'The new passwords did not match.'];
    }
    if (strlen($pass1) < 6) {
        return ['ok' => false, 'message' => 'Password must be at least 6 characters.'];
    }

    $row = $status['row'];
    $userId = (int)($row['user_id'] ?? 0);
    $resetId = (int)($row['id'] ?? 0);
    if ($userId <= 0 || $resetId <= 0) {
        return ['ok' => false, 'message' => 'This password reset link is invalid.'];
    }

    corebb_auth_ensure_schema();
    $hash = corebb_auth_password_hash($pass1);

    db_begin();
    try {
        db_run('UPDATE users SET password = ? WHERE id = ? LIMIT 1', [$hash, $userId]);
        corebb_auth_revoke_user_login_tokens($userId);
        db_run('UPDATE password_resets SET used_at = NOW() WHERE id = ? LIMIT 1', [$resetId]);
        db_run('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        return ['ok' => false, 'message' => 'Unable to update the password. Please try again.'];
    }

    return ['ok' => true, 'message' => 'Your password has been reset. You may now log in.'];
}
