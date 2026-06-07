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
 |  CookieEngine.php  - Cookie engine.                   |
 +-------------------------------------------------------+*/

/*
 * Shared bootstrap for browser-facing forum routes.
 *
 * Most public and admin PHP entrypoints include this file before doing any work.
 * It wires security headers, CSRF enforcement, the database connection, session
 * state, and the logged-in user globals used by the older CoreBB code.
 */
require_once __DIR__ . '/lib/security.php';
corebb_security_start_output_filter();

/* For security reasons */
define('IN_BOARDS', true);

/* Counting DB queries */
$QueryCount = 0;

/* Security/user state */
$MyData = array();
$userlogindata_a = array();
$time = time();

/* Shared footer/layout defaults for browser-facing routes. */
$currentserver = $_ENV['COMPUTERNAME'] ?? '';
$protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
$copyright = 'CoreBB';

include_once __DIR__ . '/config.php';
corebb_security_bootstrap();
require_once __DIR__ . '/dbheader.php';
require_once __DIR__ . '/lib/auth_password_helpers.php';

/* GeSHi Code Syntax Highlighter */
if (file_exists(__DIR__ . '/geshi/geshi.php')) { include_once(__DIR__ . '/geshi/geshi.php'); }

/* Session variables */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

//CSRF
corebb_security_enforce_csrf_if_needed();

/**
 * Usage: Load the current user from the signed persistent-login cookie.
 * Referenced by: CookieEngine.php bootstrap, loggedin(), and legacy globals.
 */
function corebb_load_logged_in_user(): bool
{
    global $MyData, $userlogindata_a, $QueryCount;

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

    //Expired cookie
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

    if (($userlogindata_a['status'] ?? '') == '2')
    {
        $currentfile = basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
        $currentAction = strtolower(trim((string)($_GET['action'] ?? '')));
        $requestPath = rtrim((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''), '/');
        
        // Banned users should be blocked from the board, but they still need
        // to be able to view the ban page and log out/reset their cookie.
        $banAllowList = [];
        $isBannedPage = ($currentfile === 'support.php' && $currentAction === 'banned')
            || $requestPath === '/banned';
        $isAuthLogout = ($currentfile === 'auth.php' && $currentAction === 'logout')
            || in_array($requestPath, ['/logoff', '/logout'], true);
        if (!in_array($currentfile, $banAllowList, true) && !$isBannedPage && !$isAuthLogout)
        {
            header('Location: /banned/');
            exit;
        }
    }

    return true;
}

/**
 * Usage: Compatibility check for older route/view code that asks if a user is signed in.
 * Referenced by: most public controllers, admin controllers, and helper layers.
 */
function loggedin(): bool
{
    return corebb_load_logged_in_user();
}

corebb_load_logged_in_user();
?>
