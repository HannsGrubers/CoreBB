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
 |  bootstrap.php  - JSON API bootstrap and auth.        |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/response.php';

if (!defined('IN_BOARDS')) {
    define('IN_BOARDS', true);
}
if (!defined('COREBB_API')) {
    define('COREBB_API', true);
}

$QueryCount = 0;
$MyData = [];
$userlogindata_a = [];

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

if (!empty($BoardLockdown)) {
    corebb_api_error('board_locked', 'The board is currently unavailable.', 503);
}

$link = db_connect($MySQL_Host ?? null, $MySQL_User ?? null, $MySQL_Pass ?? null, $MySQL_Database ?? null);
if (!$link) {
    corebb_api_error('database_unavailable', 'Unable to connect to the database.', 503);
}

require_once dirname(__DIR__) . '/security.php';
require_once dirname(__DIR__) . '/auth_password_helpers.php';
require_once dirname(__DIR__) . '/corebb_browser_helpers.php';
require_once dirname(__DIR__) . '/view.php';
require_once __DIR__ . '/guardrails.php';

corebb_security_bootstrap();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * Load the API viewer from the signed persistent-login cookie once per request.
 *
 * Usage: give API endpoints the same authentication state as browser pages
 * without including the browser bootstrap.
 * Referenced by: bootstrap initialization, API guardrails, and shared view models.
 *
 * @return bool True when a valid user session was loaded.
 */
function corebb_load_logged_in_user(): bool
{
    global $MyData, $userlogindata_a, $QueryCount;

    static $loaded = false;
    static $ok = false;
    if ($loaded) {
        return $ok;
    }
    $loaded = true;

    // API requests authenticate with the same signed persistent-login cookie
    // as desktop pages. That keeps account state, token revocation, and
    // "remember me" behavior centralized.
    $cookie = corebb_read_serialized_cookie('BoardCookieV3');
    if (!$cookie || !corebb_security_verify_login_cookie($cookie) || empty($cookie['userid']) || empty($cookie['selector']) || empty($cookie['token'])) {
        $_SESSION['userid'] = '';
        return false;
    }

    $userId = (int)$cookie['userid'];
    $selector = (string)$cookie['selector'];
    $token = (string)$cookie['token'];
    $expires = (int)($cookie['expiretime'] ?? 0);

    if ($expires > 0 && $expires < time()) {
        corebb_auth_revoke_login_token($selector);
        corebb_clear_cookie('BoardCookieV3');
        $_SESSION['userid'] = '';
        return false;
    }

    if (!corebb_auth_verify_login_token($userId, $selector, $token)) {
        corebb_clear_cookie('BoardCookieV3');
        $_SESSION['userid'] = '';
        return false;
    }

    $userlogindata_a = db_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]);
    $QueryCount++;
    if (!$userlogindata_a) {
        corebb_clear_cookie('BoardCookieV3');
        $_SESSION['userid'] = '';
        return false;
    }

    $MyData = $userlogindata_a;
    $_SESSION['userid'] = $userlogindata_a['id'] ?? '';
    $ok = true;
    return true;
}

corebb_load_logged_in_user();

/**
 * Return the loaded API viewer row.
 *
 * Usage: read authenticated user data without touching globals directly.
 * Referenced by: API serializers, auth helpers, and guardrails.
 *
 * @return array<string, mixed> Current user row or empty array.
 */
function corebb_api_viewer(): array
{
    return is_array($GLOBALS['userlogindata_a'] ?? null) ? $GLOBALS['userlogindata_a'] : [];
}

/**
 * Check whether the loaded API viewer is banned.
 *
 * Usage: gate endpoints that should reject banned accounts.
 * Referenced by: API front controller.
 *
 * @return bool True when the current viewer has banned status.
 */
function corebb_api_viewer_is_banned(): bool
{
    $viewer = corebb_api_viewer();
    return $viewer && (string)($viewer['status'] ?? '') === '2';
}
