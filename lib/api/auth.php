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
 |  auth.php  - JSON API authentication helpers.         |
 +-------------------------------------------------------+*/

require_once dirname(__DIR__) . '/email_verification_helpers.php';

/**
 * Normalize an API login expiry duration.
 *
 * Usage: accept only explicit session-duration choices instead of arbitrary
 * client-provided timestamps.
 * Referenced by: corebb_api_auth_login().
 *
 * @param mixed $value Requested duration in seconds.
 * @return int Allowed duration in seconds.
 */
function corebb_api_auth_expiry($value): int
{
    $expiry = (int)$value;
    // Keep mobile session duration choices explicit instead of accepting an
    // arbitrary client-provided timestamp or number of seconds.
    $allowed = [3600, 86400, 604800, 2592000, 31536000];
    return in_array($expiry, $allowed, true) ? $expiry : 86400;
}

/**
 * Refresh historical user globals after API login.
 *
 * Usage: keep shared CoreBB helpers that still read MyData/userlogindata_a in
 * sync with the authenticated API viewer.
 * Referenced by: corebb_api_auth_login().
 *
 * @param int $userId Authenticated user id.
 * @return array<string, mixed> Refreshed user row.
 */
function corebb_api_auth_refresh_viewer(int $userId): array
{
    global $MyData, $userlogindata_a, $QueryCount;

    // Desktop helpers still read the historical globals, so refresh them after
    // API login before returning viewer-dependent payloads.
    $userlogindata_a = db_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?: [];
    $QueryCount++;
    $MyData = $userlogindata_a;
    $_SESSION['userid'] = $userlogindata_a['id'] ?? '';
    return is_array($userlogindata_a) ? $userlogindata_a : [];
}

/**
 * Authenticate an API login request and issue the signed CoreBB login cookie.
 *
 * Usage: API auth/login endpoint handler.
 * Referenced by: API v1 front controller.
 *
 * @param array<string, mixed> $data Submitted login payload.
 * @return array<string, mixed> Login payload with expiry and viewer data.
 */
function corebb_api_auth_login(array $data): array
{
    global $CookieDomain;

    $username = trim((string)($data['username'] ?? $data['user'] ?? ''));
    $password = (string)($data['password'] ?? '');
    if ($username === '' || $password === '') {
        corebb_api_error('login_missing_fields', 'Username and password are required.', 400);
    }

    $rate = corebb_rate_limit_login_attempt($username);
    corebb_api_rate_limit_headers($rate);
    if (empty($rate['allowed'])) {
        corebb_api_error('login_rate_limited', corebb_rate_limit_message($rate, 'login attempts'), 429, [
            'retryAfter' => (int)($rate['retry_after'] ?? 60),
        ]);
    }

    corebb_auth_ensure_schema();
    $userRow = db_one('SELECT id, username, password, status, is_archive_user FROM users WHERE username = ? LIMIT 1', [$username]);
    if (!$userRow || !corebb_auth_password_verify($password, (string)($userRow['password'] ?? ''))) {
        corebb_api_error('login_failed', 'Incorrect login details.', 401);
    }

    $userId = (int)($userRow['id'] ?? 0);
    if ((int)($userRow['is_archive_user'] ?? 0) === 1) {
        corebb_api_error('archive_user_login_denied', 'Archived user identities cannot log in.', 403);
    }
    if (corebb_email_verification_is_pending($userId)) {
        corebb_api_error('email_verification_required', 'Please verify your email address before logging in.', 403);
    }

    if (password_needs_rehash((string)($userRow['password'] ?? ''), PASSWORD_DEFAULT)) {
        db_run('UPDATE users SET password = ? WHERE id = ?', [corebb_auth_password_hash($password), $userId]);
    }

    $now = convert_to_timestamp_raw(time());
    $ip = str_replace('.', '-', corebb_security_client_ip());
    $duration = corebb_api_auth_expiry($data['expiry'] ?? $data['expires_in'] ?? 86400);
    $expiresAt = time() + $duration;
    $loginToken = corebb_auth_create_login_token($userId, $expiresAt);

    $cookie = corebb_security_sign_login_cookie([
        'userid' => $userId,
        'username' => (string)($userRow['username'] ?? $username),
        'selector' => $loginToken['selector'],
        'token' => $loginToken['token'],
        'expiretime' => $expiresAt,
    ]);

    if (!corebb_security_set_cookie('BoardCookieV3', serialize($cookie), $expiresAt, '/', $CookieDomain ?: '')) {
        corebb_api_error('login_cookie_failed', 'Unable to set login cookie.', 500);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    db_run('UPDATE users SET lastlogindate = ?, lastip = ? WHERE id = ?', [$now, $ip, $userId]);
    corebb_rate_limit_login_success($username);
    corebb_api_auth_refresh_viewer($userId);

    return [
        'expiresAt' => $expiresAt,
        'viewer' => corebb_api_viewer_payload(),
    ];
}

/**
 * Revoke the current API login cookie and clear session viewer globals.
 *
 * Usage: API auth/logout endpoint handler.
 * Referenced by: API v1 front controller.
 *
 * @return array{authenticated: bool} Logout state.
 */
function corebb_api_auth_logout(): array
{
    global $CookieDomain;

    $cookie = corebb_read_serialized_cookie('BoardCookieV3');
    if ($cookie && !empty($cookie['selector'])) {
        corebb_auth_revoke_login_token((string)$cookie['selector']);
    }

    // Clear the signed login cookie across both configured and request hosts.
    $domainsToClear = [''];
    if (!empty($CookieDomain)) {
        $domainsToClear[] = (string)$CookieDomain;
    }
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?: '';
    if ($host !== '') {
        $domainsToClear[] = $host;
        if ($host[0] !== '.') {
            $domainsToClear[] = '.' . $host;
        }
    }
    $domainsToClear = array_values(array_unique(array_filter($domainsToClear, static fn($d) => is_string($d))));

    foreach ($domainsToClear as $domain) {
        corebb_security_clear_cookie('BoardCookieV3', '/', $domain);
    }

    $_SESSION['userid'] = '';
    $GLOBALS['MyData'] = [];
    $GLOBALS['userlogindata_a'] = [];

    return ['authenticated' => false];
}
