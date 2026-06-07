<?php
/*-------------------------------------------------------
 | auth_flow_helpers.php - Login/logout process helpers.
 +-------------------------------------------------------*/

require_once __DIR__ . '/rate_limit_helpers.php';
require_once __DIR__ . '/email_verification_helpers.php';
require_once __DIR__ . '/auth_password_helpers.php';

/**
 * Usage: Send an auth workflow to its next public URL and stop execution.
 * Referenced by: controllers/auth.php.
 *
 * @param string $url Absolute public path or URL for the Location header.
 * @return void
 */
function corebb_auth_redirect(string $url): void
{
    if (function_exists('corebb_public_url')) {
        $url = corebb_public_url($url);
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Usage: Normalize the selected persistent-login lifetime.
 * Referenced by: corebb_auth_login_submit_redirect().
 *
 * @param mixed $value Submitted expiry value in seconds.
 * @return int Allowed lifetime in seconds.
 */
function corebb_auth_login_expiry_seconds($value): int
{
    $expiry = (int)$value;
    $allowed = [
        900, 1800, 3600, 7200, 14400, 28800,
        43200, 86400, 172800, 604800, 1209600,
        2592000, 31536000,
    ];
    return in_array($expiry, $allowed, true) ? $expiry : 28800;
}

/**
 * Usage: Attempt a username/password login and return the next page.
 * Referenced by: controllers/auth.php action=login_submit.
 *
 * @param array<string, mixed> $post Submitted login form fields.
 * @return string Public redirect path after the attempt.
 */
function corebb_auth_login_submit_redirect(array $post): string
{
    global $CookieDomain;

    $username = trim((string)($post['username'] ?? ''));
    $password = (string)($post['password'] ?? '');
    if ($username === '' || $password === '') {
        return '/login/?msg=' . urlencode('Please enter your details to login.');
    }

    $rate = corebb_rate_limit_login_attempt($username);
    if (empty($rate['allowed'])) {
        return '/login/?msg=' . urlencode(corebb_rate_limit_message($rate, 'login attempts'));
    }

    corebb_auth_ensure_schema();
    $userRow = db_one(
        'SELECT id, username, password, status, is_archive_user FROM users WHERE username = ? LIMIT 1',
        [$username]
    );

    if ($userRow && (int)($userRow['is_archive_user'] ?? 0) === 1) {
        return '/login/?msg=' . urlencode('Archived user identities cannot log in.');
    }

    if (!$userRow || !corebb_auth_password_verify($password, (string)($userRow['password'] ?? ''))) {
        return '/login/?msg=' . urlencode('Incorrect Login Details: Unknown Account');
    }

    $userId = (int)$userRow['id'];
    if (password_needs_rehash((string)($userRow['password'] ?? ''), PASSWORD_DEFAULT)) {
        db_run('UPDATE users SET password = ? WHERE id = ?', [corebb_auth_password_hash($password), $userId]);
    }

    if (corebb_email_verification_is_pending($userId)) {
        return '/login/?msg=' . urlencode('Please verify your email address before logging in. Check your email for the verification link.');
    }

    $expiresAt = time() + corebb_auth_login_expiry_seconds($post['expiry'] ?? 0);
    $loginToken = corebb_auth_create_login_token($userId, $expiresAt);
    $cookiePayload = [
        'userid' => $userId,
        'username' => (string)$userRow['username'],
        'selector' => $loginToken['selector'],
        'token' => $loginToken['token'],
        'expiretime' => $expiresAt,
    ];
    $cookiePayload = corebb_security_sign_login_cookie($cookiePayload);

    $cookieSet = corebb_security_set_cookie(
        'BoardCookieV3',
        serialize($cookiePayload),
        $expiresAt,
        '/',
        $CookieDomain ?: ''
    );
    if (!$cookieSet) {
        return '/login/?msg=' . urlencode('Unable to set login cookie. Please check your browser cookie settings.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $ip = str_replace('.', '-', corebb_security_client_ip());
    db_run(
        'UPDATE users SET lastlogindate = ?, lastip = ? WHERE id = ?',
        [convert_to_timestamp_raw(time()), $ip, $userId]
    );
    corebb_rate_limit_login_success($username);

    return '/';
}

/**
 * Usage: Build the host/domain variants that may hold login cookies.
 * Referenced by: corebb_auth_logout_redirect().
 *
 * @param string $configuredDomain Cookie domain from configuration.
 * @param string $host Current HTTP host.
 * @return array<int, string> Domains to clear.
 */
function corebb_auth_cookie_domains_to_clear(string $configuredDomain, string $host): array
{
    $domains = [''];
    if ($configuredDomain !== '') {
        $domains[] = $configuredDomain;
    }

    $host = strtolower(preg_replace('/:\d+$/', '', $host) ?: '');
    if ($host !== '') {
        $domains[] = $host;
        if ($host[0] !== '.') {
            $domains[] = '.' . $host;
        }
    }

    return array_values(array_unique(array_filter($domains, static fn($domain): bool => is_string($domain))));
}

/**
 * Usage: Revoke login tokens, clear cookies/session state, and return home.
 * Referenced by: controllers/auth.php action=logout.
 *
 * @return string Public redirect path after logout.
 */
function corebb_auth_logout_redirect(): string
{
    global $CookieDomain;

    $cookie = corebb_read_serialized_cookie('BoardCookieV3');
    if ($cookie && !empty($cookie['selector'])) {
        corebb_auth_revoke_login_token((string)$cookie['selector']);
    }

    foreach (corebb_auth_cookie_domains_to_clear((string)($CookieDomain ?? ''), (string)($_SERVER['HTTP_HOST'] ?? '')) as $domain) {
        corebb_security_clear_cookie('BoardCookieV3', '/', $domain);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            corebb_security_clear_cookie(session_name(), $params['path'] ?? '/', $params['domain'] ?? '');
        }
        session_destroy();
    }

    return '/?msg=Successfully+logged+out!';
}
