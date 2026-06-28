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
 |  email_verification_helpers.php  - Email              |
 |  verification helpers for registration.               |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/mail_helpers.php';
require_once __DIR__ . '/security.php';

/**
 * Usage: Create the email verification table used by registration and resend flows.
 * Referenced by: token creation, login/API pending checks, resend, and verify helpers.
 *
 * @return bool True when the table exists or was created successfully.
 */
function corebb_email_verification_ensure_schema(): bool
{
    return db_run("CREATE TABLE IF NOT EXISTS `email_verifications` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `userid` INT UNSIGNED NOT NULL,
        `email` VARCHAR(255) NOT NULL,
        `token_hash` CHAR(64) NOT NULL,
        `created_at` VARCHAR(32) NOT NULL,
        `expires_at` INT UNSIGNED NOT NULL,
        `verified_at` VARCHAR(32) DEFAULT NULL,
        `ipaddress` VARCHAR(64) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_email_verifications_userid` (`userid`),
        KEY `idx_email_verifications_token` (`token_hash`),
        KEY `idx_email_verifications_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Usage: Resolve the public site root used when building verification links.
 * Referenced by: corebb_email_verification_url().
 *
 * @return string Absolute public base URL without a trailing slash.
 */
function corebb_email_verification_base_url(): string
{
    if (defined('COREBB_PUBLIC_BASE_URL')) {
        $base = trim((string)COREBB_PUBLIC_BASE_URL);
        if ($base !== '') {
            return rtrim($base, '/');
        }
    }
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    return $scheme . '://' . corebb_mail_public_host();
}

/**
 * Usage: Build the public verification URL sent to a new user.
 * Referenced by: corebb_email_verification_send().
 *
 * @param string $token Raw email verification token.
 * @return string Absolute verification URL containing the encoded token.
 */
function corebb_email_verification_url(string $token): string
{
    return corebb_email_verification_base_url() . '/verify-email/?token=' . rawurlencode($token);
}

/**
 * Usage: Generate or replace a pending verification token for a user account.
 * Referenced by: corebb_email_verification_send().
 *
 * @param int $userid User id attached to the verification request.
 * @param string $email Private email address being verified.
 * @return array{created: bool, token: string, error: string} Token creation result.
 * @throws Random\RandomException When the secure token bytes cannot be generated.
 */
function corebb_email_verification_create_token(int $userid, string $email): array
{
    if ($userid <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['created' => false, 'token' => '', 'error' => 'Invalid verification request.'];
    }
    if (!corebb_email_verification_ensure_schema()) {
        return ['created' => false, 'token' => '', 'error' => 'Unable to prepare email verification table.'];
    }

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $hash = hash('sha256', $token);
    $nowText = convert_to_timestamp_raw(time());
    $expires = time() + 86400;
    $ip = corebb_security_client_ip();

    $ok = db_run(
        "INSERT INTO email_verifications (userid, email, token_hash, created_at, expires_at, verified_at, ipaddress)
         VALUES (?, ?, ?, ?, ?, NULL, ?)
         ON DUPLICATE KEY UPDATE email = VALUES(email), token_hash = VALUES(token_hash), created_at = VALUES(created_at), expires_at = VALUES(expires_at), verified_at = NULL, ipaddress = VALUES(ipaddress)",
        [$userid, $email, $hash, $nowText, $expires, $ip]
    );
    if (!$ok) {
        return ['created' => false, 'token' => '', 'error' => db_error() ?: 'Unable to create verification token.'];
    }

    return ['created' => true, 'token' => $token, 'error' => ''];
}

/**
 * Usage: Create a verification token and send the verification email.
 * Referenced by: registration and resend-verification flows.
 *
 * @param int $userid User id attached to the email address.
 * @param string $username Display name used in the email body.
 * @param string $email Destination email address.
 * @return array<string, mixed> Mail delivery result from corebb_mail_send(), or a token error.
 */
function corebb_email_verification_send(int $userid, string $username, string $email): array
{
    $created = corebb_email_verification_create_token($userid, $email);
    if (empty($created['created'])) {
        return ['sent' => false, 'error' => $created['error'] ?? 'Unable to create verification token.'];
    }

    $url = corebb_email_verification_url((string)$created['token']);
    $boardName = corebb_mail_board_name();
    $subject = 'Verify your ' . $boardName . ' account';
    $body = "Welcome to " . $boardName . ", " . $username . ".\n\n"
        . "Please verify your email address before logging in:\n\n"
        . $url . "\n\n"
        . "This link expires in 24 hours. If you did not create this account, you can ignore this message.\n\n"
        . "- " . $boardName;

    return corebb_mail_send($email, $subject, $body);
}

/**
 * Usage: Determine whether a user must still verify their email before logging in.
 * Referenced by: auth login flow, lib/api/auth.php, and resend verification.
 *
 * @param int $userid User id to inspect.
 * @return bool True when an unverified row exists for the user.
 */
function corebb_email_verification_is_pending(int $userid): bool
{
    if ($userid <= 0 || !corebb_email_verification_ensure_schema()) {
        return false;
    }
    return db_exists('SELECT id FROM email_verifications WHERE userid = ? AND verified_at IS NULL LIMIT 1', [$userid]);
}


/**
 * Usage: Normalize user-supplied email addresses before lookup or validation.
 * Referenced by: email lookup and resend-verification helpers.
 *
 * @param string $email Submitted email address.
 * @return string Lowercase, trimmed email address.
 */
function corebb_email_verification_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

/**
 * Usage: Find the account attached to an email address during resend requests.
 * Referenced by: corebb_email_verification_resend_by_email().
 *
 * @param string $email Email address to locate.
 * @return array<string, mixed>|null Matching user row, or null when none is found.
 */
function corebb_email_verification_lookup_user_by_email(string $email): ?array
{
    $email = corebb_email_verification_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $row = db_one('SELECT id, username, privemail FROM users WHERE LOWER(privemail) = LOWER(?) ORDER BY id ASC LIMIT 1', [$email]);
    return is_array($row) ? $row : null;
}

/**
 * Usage: Throttle verification resends without parsing legacy timestamp strings.
 * Referenced by: corebb_email_verification_resend_by_email().
 *
 * @param int $userid User id attached to the pending verification row.
 * @param int $seconds Minimum age, in seconds, before another resend is allowed.
 * @return bool True when a recent pending resend already exists.
 */
function corebb_email_verification_recent_resend_exists(int $userid, int $seconds = 300): bool
{
    if ($userid <= 0 || !corebb_email_verification_ensure_schema()) {
        return false;
    }

    // Tokens expire 24 hours after creation.  This avoids parsing legacy/VN
    // timestamp strings while still preventing rapid resend spam.
    $freshExpiresAfter = time() + (86400 - $seconds);
    return db_exists(
        'SELECT id FROM email_verifications WHERE userid = ? AND verified_at IS NULL AND expires_at >= ? LIMIT 1',
        [$userid, $freshExpiresAfter]
    );
}

/**
 * Usage: Process a resend request while keeping account existence private.
 * Referenced by: controllers/auth.php action=resend.
 *
 * @param string $email Submitted email address.
 * @return array{ok: bool, message: string, mail_error: string} Public response and optional mail error.
 */
function corebb_email_verification_resend_by_email(string $email): array
{
    $generic = [
        'ok' => true,
        'message' => 'If that email address has an unverified account, a new verification link has been sent.',
        'mail_error' => '',
    ];

    if (!corebb_email_verification_ensure_schema()) {
        return [
            'ok' => false,
            'message' => 'Unable to prepare email verification table.',
            'mail_error' => db_error() ?: '',
        ];
    }

    $email = corebb_email_verification_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $generic;
    }

    $user = corebb_email_verification_lookup_user_by_email($email);
    if (!$user) {
        return $generic;
    }

    $userid = (int)($user['id'] ?? 0);
    if ($userid <= 0 || !corebb_email_verification_is_pending($userid)) {
        return $generic;
    }

    if (corebb_email_verification_recent_resend_exists($userid)) {
        return $generic;
    }

    $mail = corebb_email_verification_send($userid, (string)($user['username'] ?? 'there'), (string)($user['privemail'] ?? $email));
    if (empty($mail['sent'])) {
        return [
            'ok' => false,
            'message' => 'A new verification request was created, but the email could not be sent.',
            'mail_error' => (string)($mail['error'] ?? 'Unknown mail error.'),
        ];
    }

    return $generic;
}


/**
 * Usage: Clean up a token value copied from an email link or pasted URL.
 * Referenced by: corebb_email_verification_verify_token().
 *
 * @param string $token Raw token, pasted URL, or email-client-wrapped token text.
 * @return string Sanitized token candidate.
 */
function corebb_email_verification_normalize_token(string $token): string
{
    $token = html_entity_decode(rawurldecode(trim($token)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Be forgiving if a copied URL or email client artifact is submitted
    // instead of just the token value.
    if (preg_match('/(?:^|[?&])token=([^&\s]+)/i', $token, $m)) {
        $token = (string)$m[1];
        $token = html_entity_decode(rawurldecode(trim($token)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $token = trim($token, " \t\n\r\0\x0B<>\"'`.,;)");
    $token = preg_replace('/\s+/', '', $token) ?? '';
    $token = preg_replace('/[^A-Za-z0-9_\-]/', '', $token) ?? '';
    return $token;
}

/**
 * Usage: Verify an email token, mark it used, and return display-ready status.
 * Referenced by: controllers/auth.php action=verify.
 *
 * @param string $token Raw token from the verification request.
 * @return array<string, mixed> Verification result, user-facing message, and optional detail.
 */
function corebb_email_verification_verify_token(string $token): array
{
    $originalToken = $token;
    $token = corebb_email_verification_normalize_token($token);

    if ($token === '') {
        return [
            'verified' => false,
            'message' => 'Verification token was missing from the link.',
            'detail' => 'Open the full link from the verification email, including everything after ?token=.',
        ];
    }
    if (!preg_match('/^[A-Za-z0-9_\-]{20,}$/', $token)) {
        return [
            'verified' => false,
            'message' => 'Invalid verification token format.',
            'detail' => 'The link may have been wrapped, truncated, or altered by the mail client.',
        ];
    }
    if (!corebb_email_verification_ensure_schema()) {
        return ['verified' => false, 'message' => 'Unable to prepare email verification table.'];
    }

    $hash = hash('sha256', $token);
    $row = db_one('SELECT * FROM email_verifications WHERE token_hash = ? LIMIT 1', [$hash]);

    /*
     * Future-proof fallback: if a developer/testing copy ever stored a raw
     * token in token_hash during early email-verification testing, allow it
     * to verify instead of stranding that account. Normal production rows
     * still use the SHA-256 hash above.
     */
    if (!$row) {
        $row = db_one('SELECT * FROM email_verifications WHERE token_hash = ? LIMIT 1', [$token]);
    }

    if (!$row) {
        $pendingCount = (int)db_value('SELECT COUNT(*) FROM email_verifications WHERE verified_at IS NULL', []);
        $detail = $pendingCount > 0
            ? 'The token in this email does not match any pending verification row. It may be from an older email, a different test account, or a different site/database host.'
            : 'There are no pending email verification rows. This account may already be verified, or the link is reaching a different site/database host than registration used.';
        return [
            'verified' => false,
            'message' => 'Verification link was not found.',
            'detail' => $detail,
        ];
    }
    if ((string)($row['verified_at'] ?? '') !== '') {
        return ['verified' => true, 'message' => 'This email address has already been verified. You may log in.', 'detail' => ''];
    }
    if ((int)($row['expires_at'] ?? 0) < time()) {
        return ['verified' => false, 'message' => 'That verification link has expired.', 'detail' => 'Use the resend verification form to request a fresh link.'];
    }

    $nowText = convert_to_timestamp_raw(time());
    $ok = db_run('UPDATE email_verifications SET verified_at = ? WHERE id = ?', [$nowText, (int)$row['id']]);
    if (!$ok) {
        return ['verified' => false, 'message' => 'Unable to verify email address right now.', 'detail' => db_error() ?: 'Database update failed.'];
    }
    return ['verified' => true, 'message' => 'Email verified. You may now log in.', 'detail' => ''];
}
