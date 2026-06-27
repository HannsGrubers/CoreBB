<?php
require_once __DIR__ . '/security.php';
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
 |  auth_password_helpers.php  - Modern password and     |
 |  persistent-login helpers.                            |
 +-------------------------------------------------------+*/

if (!defined('COREBB_AUTH_PASSWORD_HELPERS_LOADED')) {
    define('COREBB_AUTH_PASSWORD_HELPERS_LOADED', true);
}

/**
 * Ensure modern password and persistent-login storage exists.
 *
 * Usage: call before login, password reset, admin password changes, or token
 * operations touch modern password/login-token fields.
 * Referenced by: auth login flow, browser helper registration, API auth,
 * password recovery, admin user tools, and helpers in this file.
 *
 * @return void
 */
function corebb_auth_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // PASSWORD_DEFAULT output can grow over time.  Keep the old column name
    // for compatibility with the rest of the codebase, but make it large
    // enough for modern password_hash() values.
    @db_run("ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL DEFAULT ''");

    // Archive/imported identities are display-only accounts.  Keep the
    // schema available before login checks query this column.
    @db_run("ALTER TABLE users ADD COLUMN is_archive_user TINYINT(1) NOT NULL DEFAULT 0");
    @db_run("ALTER TABLE users ADD COLUMN legacy_identity_key VARCHAR(255) NOT NULL DEFAULT ''");

    @db_run("CREATE TABLE IF NOT EXISTS user_login_tokens (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        selector VARCHAR(64) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at INT UNSIGNED NOT NULL,
        created_at INT UNSIGNED NOT NULL,
        last_used_at INT UNSIGNED NOT NULL DEFAULT 0,
        ip VARCHAR(64) NOT NULL DEFAULT '',
        user_agent VARCHAR(255) NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        UNIQUE KEY selector (selector),
        KEY user_id (user_id),
        KEY expires_at (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Hash a plaintext password using PHP's current recommended algorithm.
 *
 * Usage: create or replace account passwords for registration, resets, and
 * admin password changes.
 * Referenced by: browser helper registration, auth login upgrade path,
 * password recovery, API auth, and admin tools.
 *
 * @param string $password Plaintext password from a trusted validation path.
 * @return string Password hash suitable for the users.password column.
 *
 * @throws RuntimeException When PHP cannot produce a password hash.
 */
function corebb_auth_password_hash(string $password): string
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('Unable to hash password.');
    }
    return $hash;
}

/**
 * Verify a plaintext password against a modern stored hash.
 *
 * Usage: authenticate web and API logins while rejecting unsupported legacy
 * MD5-era hashes.
 * Referenced by: auth login flow and API auth.
 *
 * @param string $password Plaintext password submitted by the user.
 * @param string $storedHash Stored hash from users.password.
 * @return bool True when the password matches a supported hash.
 */
function corebb_auth_password_verify(string $password, string $storedHash): bool
{
    $storedHash = trim($storedHash);
    if ($storedHash === '') {
        return false;
    }

    // This release intentionally does not accept legacy MD5 hashes.  The
    // database is being wiped before public use, so all accounts must be
    // created/reset with password_hash().
    if (preg_match('/^[a-f0-9]{32}$/i', $storedHash)) {
        return false;
    }

    return password_verify($password, $storedHash);
}

/**
 * Generate a URL-safe random token.
 *
 * Usage: create persistent-login selectors and verifier tokens.
 * Referenced by: corebb_auth_create_login_token().
 *
 * @param int $bytes Number of random bytes before base64url encoding.
 * @return string URL-safe token string without padding.
 */
function corebb_auth_random_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/**
 * Delete expired persistent-login tokens.
 *
 * Usage: keep the login-token table small before issuing a fresh token.
 * Referenced by: corebb_auth_create_login_token().
 *
 * @return void
 */
function corebb_auth_prune_login_tokens(): void
{
    corebb_auth_ensure_schema();
    @db_run('DELETE FROM user_login_tokens WHERE expires_at < ?', [time()]);
}

/**
 * Resolve the current client IP for login-token metadata.
 *
 * Usage: capture helpful audit context when a remember-me token is created.
 * Referenced by: corebb_auth_create_login_token().
 *
 * @return string Client IP from the shared security helper or REMOTE_ADDR.
 */
function corebb_auth_client_ip(): string
{
    return (string)corebb_security_client_ip();
}

/**
 * Create a persistent-login selector/token pair for a user.
 *
 * Usage: issue the remember-me cookie payload after successful web/API login.
 * Referenced by: auth login flow and API auth.
 *
 * @param int $userId User id receiving the token.
 * @param int $expiresAt Unix timestamp when the token should expire.
 * @return array{selector: string, token: string} Raw cookie payload components.
 */
function corebb_auth_create_login_token(int $userId, int $expiresAt): array
{
    corebb_auth_ensure_schema();
    corebb_auth_prune_login_tokens();

    $selector = corebb_auth_random_token(18);
    $token = corebb_auth_random_token(32);
    $tokenHash = hash('sha256', $token);
    $ip = corebb_auth_client_ip();
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $now = time();

    db_run(
        'INSERT INTO user_login_tokens (user_id, selector, token_hash, expires_at, created_at, last_used_at, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$userId, $selector, $tokenHash, $expiresAt, $now, $now, $ip, $ua]
    );

    return [
        'selector' => $selector,
        'token' => $token,
    ];
}

/**
 * Verify a persistent-login selector/token pair for one user.
 *
 * Usage: authenticate remember-me cookies and revoke stale or failed tokens.
 * Referenced by: auth session helpers and API bootstrap.
 *
 * @param int $userId User id claimed by the cookie.
 * @param string $selector Public selector from the cookie.
 * @param string $token Private verifier token from the cookie.
 * @return bool True when the token is current and matches.
 */
function corebb_auth_verify_login_token(int $userId, string $selector, string $token): bool
{
    if ($userId <= 0 || $selector === '' || $token === '') {
        return false;
    }

    $row = db_one('SELECT id, token_hash, expires_at FROM user_login_tokens WHERE user_id = ? AND selector = ? LIMIT 1', [$userId, $selector]);
    if (!$row) {
        return false;
    }

    if ((int)($row['expires_at'] ?? 0) < time()) {
        corebb_auth_revoke_login_token($selector);
        return false;
    }

    $expected = (string)($row['token_hash'] ?? '');
    $actual = hash('sha256', $token);
    if (!hash_equals($expected, $actual)) {
        corebb_auth_revoke_login_token($selector);
        return false;
    }

    @db_run('UPDATE user_login_tokens SET last_used_at = ? WHERE id = ?', [time(), (int)$row['id']]);
    return true;
}

/**
 * Revoke one persistent-login token by selector.
 *
 * Usage: clear a remember-me cookie server-side after logout or failed token
 * verification.
 * Referenced by: auth session helpers, auth logout flow, API auth, and API bootstrap.
 *
 * @param string $selector Token selector to delete.
 * @return void
 */
function corebb_auth_revoke_login_token(string $selector): void
{
    if ($selector !== '') {
        @db_run('DELETE FROM user_login_tokens WHERE selector = ?', [$selector]);
    }
}

/**
 * Revoke every persistent-login token for a user.
 *
 * Usage: invalidate remembered sessions after password reset or admin password
 * change.
 * Referenced by: password recovery and admin user tools.
 *
 * @param int $userId User id whose tokens should be deleted.
 * @return void
 */
function corebb_auth_revoke_user_login_tokens(int $userId): void
{
    corebb_auth_ensure_schema();
    if ($userId > 0) {
        @db_run('DELETE FROM user_login_tokens WHERE user_id = ?', [$userId]);
    }
}

?>
