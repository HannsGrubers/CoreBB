<?php
/*-------------------------------------------------------
 | auth_session_helpers.php - Browser login session.
 |
 | Loads the current browser user from CoreBB's signed
 | persistent-login cookie.
 +-------------------------------------------------------*/

if (!defined('COREBB_AUTH_SESSION_HELPERS_LOADED')) {
    define('COREBB_AUTH_SESSION_HELPERS_LOADED', true);
}

/**
 * Usage: Hydrate the current user globals from the signed persistent-login cookie.
 * Referenced by: corebb_load_logged_in_user() and API bootstrap.
 */
function corebb_auth_load_cookie_user(): bool
{
    global $MyData, $userlogindata_a, $QueryCount;

    static $loaded = false;
    static $ok = false;
    if ($loaded) {
        return $ok;
    }
    $loaded = true;

    $cookie = corebb_read_serialized_cookie('BoardCookieV3');
    if (!$cookie || !corebb_security_verify_login_cookie($cookie) || empty($cookie['userid']) || empty($cookie['selector']) || empty($cookie['token']))
    {
        $_SESSION['userid'] = '';
        return false;
    }

    $userId = (int)$cookie['userid'];
    $selector = (string)$cookie['selector'];
    $token = (string)$cookie['token'];
    $expires = (int)($cookie['expiretime'] ?? 0);

    if ($expires > 0 && $expires < time())
    {
        corebb_auth_revoke_login_token($selector);
        corebb_clear_cookie('BoardCookieV3');
        $_SESSION['userid'] = '';
        return false;
    }

    if (!corebb_auth_verify_login_token($userId, $selector, $token))
    {
        corebb_clear_cookie('BoardCookieV3');
        $_SESSION['userid'] = '';
        return false;
    }

    $userlogindata_a = db_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]);
    $QueryCount++;
    if (!$userlogindata_a)
    {
        corebb_clear_cookie('BoardCookieV3');
        $_SESSION['userid'] = '';
        return false;
    }

    $MyData = $userlogindata_a;
    $_SESSION['userid'] = $userlogindata_a['id'] ?? '';
    $ok = true;

    return true;
}

/**
 * Usage: Load the current user from the signed persistent-login cookie.
 * Referenced by: bootstrap.php, controllers, API bootstrap, and view models.
 */
function corebb_load_logged_in_user(): bool
{
    if (!corebb_auth_load_cookie_user()) {
        return false;
    }

    $userlogindata_a = $GLOBALS['userlogindata_a'] ?? [];
    if (defined('COREBB_API') && COREBB_API) {
        return true;
    }

    if (($userlogindata_a['status'] ?? '') == '2')
    {
        $currentfile = basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
        $currentAction = strtolower(trim((string)($_GET['action'] ?? '')));
        $requestPath = rtrim((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''), '/');

        // Banned users should be blocked from the board, but they still need
        // to be able to view the ban page and log out/reset their cookie.
        $isBannedPage = ($currentfile === 'support.php' && $currentAction === 'banned')
            || $requestPath === '/banned';
        $isAuthLogout = ($currentfile === 'auth.php' && $currentAction === 'logout')
            || in_array($requestPath, ['/logoff', '/logout'], true);
        if (!$isBannedPage && !$isAuthLogout)
        {
            header('Location: /banned/');
            exit;
        }
    }

    return true;
}
