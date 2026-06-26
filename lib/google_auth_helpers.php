<?php
/*-------------------------------------------------------
 | google_auth_helpers.php - Google Identity sign-in.
 +-------------------------------------------------------*/

require_once __DIR__ . '/auth_password_helpers.php';
require_once __DIR__ . '/email_verification_helpers.php';
require_once __DIR__ . '/tos_helpers.php';

/**
 * Usage: Read one authentication setting from systemsettings.
 * Referenced by: Google auth configuration helpers.
 */
function corebb_auth_setting_get(string $name, string $default = ''): string
{
    return (string)db_value('SELECT setting FROM systemsettings WHERE name = ? ORDER BY id ASC LIMIT 1', [$name], $default);
}

/**
 * Usage: Read a yes/no authentication setting from systemsettings.
 * Referenced by: Google auth configuration helpers.
 */
function corebb_auth_setting_enabled(string $name, bool $default = false): bool
{
    $value = strtolower(trim(corebb_auth_setting_get($name, $default ? '1' : '0')));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Usage: Read the Google web client id configured in the database.
 * Referenced by: admin auth settings and Google client id resolution.
 */
function corebb_google_system_client_id(): string
{
    return trim(corebb_auth_setting_get('auth_google_client_id', ''));
}

/**
 * Usage: Read the Google web client id configured in private config or env.
 * Referenced by: Google client id resolution and admin validation.
 */
function corebb_google_config_client_id(): string
{
    if (defined('COREBB_GOOGLE_CLIENT_ID')) {
        return trim((string)COREBB_GOOGLE_CLIENT_ID);
    }
    $configured = trim((string)($GLOBALS['GoogleClientID'] ?? ''));
    return $configured !== '' ? $configured : trim((string)(getenv('COREBB_GOOGLE_CLIENT_ID') ?: ''));
}

/**
 * Usage: Read the configured Google web client id from systemsettings, private config, or env.
 * Referenced by: layout, Google callback, and ID-token validation helpers.
 */
function corebb_google_client_id(): string
{
    $systemClientId = corebb_google_system_client_id();
    if ($systemClientId !== '') {
        return $systemClientId;
    }
    return corebb_google_config_client_id();
}

/**
 * Usage: Check whether Google sign-in can be shown or processed.
 * Referenced by: layout and Google controller flow.
 */
function corebb_google_enabled(): bool
{
    $clientId = corebb_google_client_id();
    return corebb_auth_setting_enabled('auth_google_enabled', $clientId !== '') && $clientId !== '';
}

/**
 * Usage: Read the optional Google Workspace hosted-domain restriction.
 * Referenced by: Google ID-token validation and admin settings.
 */
function corebb_google_hosted_domain(): string
{
    return strtolower(trim(corebb_auth_setting_get('auth_google_hosted_domain', '')));
}

/**
 * Usage: Determine whether new Google identities may complete a local account.
 * Referenced by: Google callback and account-completion screen.
 */
function corebb_google_allow_auto_create(): bool
{
    return corebb_auth_setting_enabled('auth_google_allow_auto_create', true);
}

/**
 * Usage: Return the public POST endpoint used by Google Identity Services.
 * Referenced by: layout Google auth model and Twig templates.
 */
function corebb_google_login_url(): string
{
    if (defined('COREBB_PUBLIC_BASE_URL') && trim((string)COREBB_PUBLIC_BASE_URL) !== '') {
        return rtrim((string)COREBB_PUBLIC_BASE_URL, '/') . '/login/google/';
    }

    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?: 'localhost';
    return $scheme . '://' . $host . (function_exists('corebb_public_url') ? corebb_public_url('/login/google/') : '/login/google/');
}
/**
 * Usage: Build Twig-facing Google button configuration for auth pages.
 * Referenced by: controllers/auth.php login and register actions.
 */
function corebb_google_button_model(): array
{
    return [
        'enabled' => corebb_google_enabled(),
        'client_id' => corebb_google_client_id(),
        'login_url' => corebb_google_login_url(),
    ];
}

/**
 * Usage: Create the provider-link table for Google and future external identity providers.
 * Referenced by: Google callback, account creation, and lookup helpers.
 */
function corebb_google_ensure_schema(): bool
{
    return db_run("CREATE TABLE IF NOT EXISTS `user_external_identities` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `provider` VARCHAR(32) NOT NULL,
        `provider_subject` VARCHAR(255) NOT NULL,
        `email` VARCHAR(255) NOT NULL DEFAULT '',
        `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
        `hosted_domain` VARCHAR(255) NOT NULL DEFAULT '',
        `display_name` VARCHAR(255) NOT NULL DEFAULT '',
        `picture_url` VARCHAR(1024) NOT NULL DEFAULT '',
        `created_at` INT UNSIGNED NOT NULL,
        `last_login_at` INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_external_identity` (`provider`, `provider_subject`),
        KEY `idx_external_identity_user` (`user_id`),
        KEY `idx_external_identity_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Usage: Validate the Google Identity Services double-submit CSRF token.
 * Referenced by: corebb_google_handle_callback().
 */
function corebb_google_csrf_valid(array $post): bool
{
    $cookieToken = (string)($_COOKIE['g_csrf_token'] ?? '');
    $bodyToken = (string)($post['g_csrf_token'] ?? '');
    return $cookieToken !== '' && $bodyToken !== '' && hash_equals($cookieToken, $bodyToken);
}

/**
 * Usage: Verify a Google Identity Services ID token through Google's HTTPS tokeninfo endpoint.
 * Referenced by: corebb_google_handle_callback().
 */
function corebb_google_verify_id_token(string $jwt): array
{
    $clientId = corebb_google_client_id();
    if ($clientId === '') {
        return ['ok' => false, 'error' => 'Google sign-in is not configured.'];
    }
    if ($jwt === '' || substr_count($jwt, '.') !== 2 || strlen($jwt) > 8192) {
        return ['ok' => false, 'error' => 'Invalid Google sign-in token.'];
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($jwt);
    $body = @file_get_contents($url, false, $context);
    $claims = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($claims)) {
        return ['ok' => false, 'error' => 'Unable to verify Google sign-in with Google.'];
    }
    if (!empty($claims['error_description'])) {
        return ['ok' => false, 'error' => 'Google sign-in token was rejected.'];
    }

    $issuer = (string)($claims['iss'] ?? '');
    $audience = (string)($claims['aud'] ?? '');
    $expires = (int)($claims['exp'] ?? 0);
    $subject = trim((string)($claims['sub'] ?? ''));
    $email = trim((string)($claims['email'] ?? ''));
    $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL);
    $hostedDomain = strtolower(trim((string)($claims['hd'] ?? '')));
    $requiredHostedDomain = corebb_google_hosted_domain();

    if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)) {
        return ['ok' => false, 'error' => 'Google sign-in token issuer is invalid.'];
    }
    if (!hash_equals($clientId, $audience)) {
        return ['ok' => false, 'error' => 'Google sign-in token was not issued for this forum.'];
    }
    if ($expires <= time()) {
        return ['ok' => false, 'error' => 'Google sign-in token has expired.'];
    }
    if ($subject === '' || $email === '' || !$emailVerified || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Google did not provide a verified email identity.'];
    }
    if ($requiredHostedDomain !== '' && !hash_equals($requiredHostedDomain, $hostedDomain)) {
        return ['ok' => false, 'error' => 'This Google account is not in the allowed hosted domain.'];
    }

    return ['ok' => true, 'claims' => $claims];
}

/**
 * Usage: Normalize Google claims into the subset CoreBB persists.
 * Referenced by: callback and account-completion flows.
 */
function corebb_google_identity_from_claims(array $claims): array
{
    return [
        'provider' => 'google',
        'subject' => (string)($claims['sub'] ?? ''),
        'email' => strtolower(trim((string)($claims['email'] ?? ''))),
        'email_verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0,
        'hosted_domain' => trim((string)($claims['hd'] ?? '')),
        'display_name' => trim((string)($claims['name'] ?? '')),
        'picture_url' => trim((string)($claims['picture'] ?? '')),
    ];
}

/**
 * Usage: Determine whether Google is authoritative for the returned email.
 * Referenced by: safe email-based account linking.
 */
function corebb_google_email_is_authoritative(array $identity): bool
{
    $email = strtolower((string)($identity['email'] ?? ''));
    return str_ends_with($email, '@gmail.com') || (!empty($identity['email_verified']) && trim((string)($identity['hosted_domain'] ?? '')) !== '');
}

/**
 * Usage: Find a local user linked to a Google subject.
 * Referenced by: Google callback.
 */
function corebb_google_user_by_subject(string $subject): ?array
{
    if ($subject === '' || !corebb_google_ensure_schema()) {
        return null;
    }
    $row = db_one(
        "SELECT u.*, ei.id AS external_identity_id
         FROM user_external_identities ei
         INNER JOIN users u ON u.id = ei.user_id
         WHERE ei.provider = 'google' AND ei.provider_subject = ?
         LIMIT 1",
        [$subject]
    );
    return is_array($row) ? $row : null;
}

/**
 * Usage: Find a local user that may safely be auto-linked by verified email.
 * Referenced by: Google callback for existing CoreBB accounts.
 */
function corebb_google_user_by_safe_email(array $identity): ?array
{
    $email = (string)($identity['email'] ?? '');
    if ($email === '' || !corebb_google_email_is_authoritative($identity)) {
        return null;
    }
    $row = db_one('SELECT * FROM users WHERE LOWER(privemail) = LOWER(?) ORDER BY id ASC LIMIT 1', [$email]);
    if (!is_array($row) || (int)($row['is_archive_user'] ?? 0) === 1 || corebb_email_verification_is_pending((int)($row['id'] ?? 0))) {
        return null;
    }
    return $row;
}

/**
 * Usage: Link a verified Google identity to a local CoreBB user.
 * Referenced by: callback auto-link and account-completion creation.
 */
function corebb_google_link_identity(int $userId, array $identity): bool
{
    if ($userId <= 0 || !corebb_google_ensure_schema()) {
        return false;
    }
    return db_run(
        "INSERT INTO user_external_identities
            (user_id, provider, provider_subject, email, email_verified, hosted_domain, display_name, picture_url, created_at, last_login_at)
         VALUES (?, 'google', ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            email = VALUES(email), email_verified = VALUES(email_verified), hosted_domain = VALUES(hosted_domain),
            display_name = VALUES(display_name), picture_url = VALUES(picture_url), last_login_at = VALUES(last_login_at)",
        [$userId, (string)$identity['subject'], (string)$identity['email'], (int)$identity['email_verified'], (string)$identity['hosted_domain'], (string)$identity['display_name'], (string)$identity['picture_url'], time(), time()]
    );
}

/**
 * Usage: Mark a linked Google identity as recently used.
 * Referenced by: Google callback after a successful local login.
 */
function corebb_google_touch_identity(string $subject, array $identity): void
{
    if ($subject === '' || !corebb_google_ensure_schema()) {
        return;
    }
    db_run(
        "UPDATE user_external_identities
         SET email = ?, email_verified = ?, hosted_domain = ?, display_name = ?, picture_url = ?, last_login_at = ?
         WHERE provider = 'google' AND provider_subject = ?",
        [(string)$identity['email'], (int)$identity['email_verified'], (string)$identity['hosted_domain'], (string)$identity['display_name'], (string)$identity['picture_url'], time(), $subject]
    );
}

/**
 * Usage: Issue CoreBB's normal browser login cookie for a trusted Google user.
 * Referenced by: callback and account-completion flows.
 */
function corebb_google_login_user(array $user): string
{
    global $CookieDomain;

    $userId = (int)($user['id'] ?? 0);
    $username = (string)($user['username'] ?? '');
    if ($userId <= 0 || $username === '') {
        return '/login/?msg=' . urlencode('Unable to load the linked CoreBB account.');
    }
    if ((int)($user['is_archive_user'] ?? 0) === 1) {
        return '/login/?msg=' . urlencode('Archived user identities cannot log in.');
    }

    $expiresAt = time() + 2592000;
    $loginToken = corebb_auth_create_login_token($userId, $expiresAt);
    $cookie = corebb_security_sign_login_cookie([
        'userid' => $userId,
        'username' => $username,
        'selector' => $loginToken['selector'],
        'token' => $loginToken['token'],
        'expiretime' => $expiresAt,
    ]);
    if (!corebb_security_set_cookie('BoardCookieV3', serialize($cookie), $expiresAt, '/', $CookieDomain ?: '')) {
        return '/login/?msg=' . urlencode('Unable to set login cookie. Please check your browser cookie settings.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['userid'] = $userId;
    $ip = str_replace('.', '-', corebb_security_client_ip());
    db_run('UPDATE users SET lastlogindate = ?, lastip = ? WHERE id = ?', [convert_to_timestamp_raw(time()), $ip, $userId]);
    return '/';
}

/**
 * Usage: Store a verified but unlinked Google identity until local registration completes.
 * Referenced by: callback and account-completion flows.
 */
function corebb_google_store_pending_identity(array $identity): void
{
    $_SESSION['corebb_google_pending'] = ['identity' => $identity, 'created_at' => time()];
}

/**
 * Usage: Read a not-yet-completed Google identity from session.
 * Referenced by: account-completion model.
 */
function corebb_google_pending_identity(): ?array
{
    $pending = $_SESSION['corebb_google_pending'] ?? null;
    if (!is_array($pending) || !is_array($pending['identity'] ?? null)) {
        return null;
    }
    if ((int)($pending['created_at'] ?? 0) < time() - 900) {
        unset($_SESSION['corebb_google_pending']);
        return null;
    }
    return $pending['identity'];
}

/**
 * Usage: Build a username candidate from Google profile data.
 * Referenced by: account-completion model.
 */
function corebb_google_suggest_username(array $identity): string
{
    $seed = trim((string)($identity['display_name'] ?? ''));
    if ($seed === '') {
        $seed = preg_replace('/@.*$/', '', (string)($identity['email'] ?? '')) ?? '';
    }
    $seed = preg_replace('/[^A-Za-z0-9_\- ]+/', '', $seed) ?? '';
    $seed = preg_replace('/\s+/', ' ', trim($seed)) ?? '';
    if (strlen($seed) < 3) {
        $seed = 'Google User';
    }
    $seed = substr($seed, 0, 20);
    $candidate = $seed;
    for ($i = 2; $i <= 99 && db_exists('SELECT id FROM users WHERE username = ? LIMIT 1', [$candidate]); $i++) {
        $suffix = (string)$i;
        $candidate = substr($seed, 0, 20 - strlen($suffix)) . $suffix;
    }
    return $candidate;
}

/**
 * Usage: Create a local CoreBB user for a verified Google identity.
 * Referenced by: account-completion POST handling.
 */
function corebb_google_create_user(string $username, array $identity): array
{
    $email = (string)($identity['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Google did not provide a usable email address.'];
    }
    if (db_exists('SELECT id FROM users WHERE LOWER(privemail) = LOWER(?) LIMIT 1', [$email])) {
        return ['ok' => false, 'error' => 'A CoreBB account already uses this email address. Log in with your password first, then use Google after the account is linked.'];
    }
    if (!CreateUser($username, $email, corebb_auth_random_token(32))) {
        return ['ok' => false, 'error' => (string)($GLOBALS['CreateUserOut'] ?? 'Unable to create account.')];
    }
    $userId = (int)($GLOBALS['CreateUserID'] ?? 0);
    $user = $userId > 0 ? db_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) : false;
    if (!$user) {
        return ['ok' => false, 'error' => 'Account was created, but CoreBB could not load it.'];
    }
    if (!corebb_google_link_identity($userId, $identity)) {
        return ['ok' => false, 'error' => 'Account was created, but Google sign-in could not be linked.'];
    }
    unset($_SESSION['corebb_google_pending']);
    return ['ok' => true, 'user' => $user];
}

/**
 * Usage: Process the Google Identity Services credential POST.
 * Referenced by: controllers/auth.php action=google.
 */
function corebb_google_handle_callback(array $post): string
{
    if (!corebb_google_enabled()) {
        return '/login/?msg=' . urlencode('Google sign-in is not configured.');
    }
    if (!corebb_google_csrf_valid($post)) {
        return '/login/?msg=' . urlencode('Google sign-in security check failed. Please try again.');
    }
    $verified = corebb_google_verify_id_token((string)($post['credential'] ?? ''));
    if (empty($verified['ok'])) {
        return '/login/?msg=' . urlencode((string)($verified['error'] ?? 'Google sign-in failed.'));
    }

    $identity = corebb_google_identity_from_claims((array)$verified['claims']);
    $user = corebb_google_user_by_subject((string)$identity['subject']);
    if (!$user) {
        $user = corebb_google_user_by_safe_email($identity);
        if ($user) {
            corebb_google_link_identity((int)$user['id'], $identity);
        }
    }
    if ($user) {
        corebb_google_touch_identity((string)$identity['subject'], $identity);
        return corebb_google_login_user($user);
    }

    if (!corebb_google_allow_auto_create()) {
        return '/login/?msg=' . urlencode('Google sign-in is valid, but new account creation is disabled.');
    }

    corebb_google_store_pending_identity($identity);
    return '/login/google/complete/';
}

/**
 * Usage: Build and process the screen-name completion page for new Google users.
 * Referenced by: controllers/auth.php action=google_complete.
 */
function corebb_google_complete_model(array $post = [], string $method = 'GET'): array
{
    $identity = corebb_google_pending_identity();
    $tos = corebb_tos_load_text();
    $model = [
        'available' => $identity !== null && corebb_google_allow_auto_create(),
        'errors' => [],
        'identity' => $identity ?: [],
        'old' => ['username' => $identity ? corebb_google_suggest_username($identity) : ''],
        'tos' => ['body' => $tos !== '' ? $tos : 'By registering, you agree to follow the board rules.', 'body_format' => $tos !== '' ? 'stored_html' : 'plain'],
    ];
    if ($identity && !corebb_google_allow_auto_create()) {
        $model['errors'][] = 'Google account creation is disabled.';
        return $model;
    }
    if (!$identity || strtoupper($method) !== 'POST') {
        return $model;
    }

    $username = trim((string)($post['username'] ?? ''));
    $model['old']['username'] = $username;
    if (trim((string)($post['website'] ?? '')) !== '') {
        $model['errors'][] = 'Registration rejected.';
    }
    if (!preg_match('/^[A-Za-z0-9_\- ]{3,20}$/', $username)) {
        $model['errors'][] = 'Screen name must be 3-20 characters and use only letters, numbers, spaces, underscores, or dashes.';
    }
    if (empty($post['agree_tos'])) {
        $model['errors'][] = 'You must agree to the Terms of Service.';
    }
    if (empty($post['confirm_age_13'])) {
        $model['errors'][] = 'You must confirm that you are at least 13 years old.';
    }
    if (!$model['errors']) {
        $created = corebb_google_create_user($username, $identity);
        if (!empty($created['ok']) && is_array($created['user'] ?? null)) {
            $model['redirect'] = corebb_google_login_user($created['user']);
            return $model;
        }
        $model['errors'][] = (string)($created['error'] ?? 'Unable to create account.');
    }
    return $model;
}
