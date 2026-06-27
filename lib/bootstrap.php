<?php
/*-------------------------------------------------------
 | bootstrap.php - Browser route bootstrap entrypoint.
 |
 | This file is the modern include target for public,
 | admin, and upgrade entrypoints. It wires shared
 | security, database, session, and legacy global state
 | before loading the current browser user.
 +-------------------------------------------------------*/

if (defined('COREBB_BOOTSTRAP_LOADED')) {
    return;
}

define('COREBB_BOOTSTRAP_LOADED', true);

require_once __DIR__ . '/security.php';
corebb_security_start_output_filter();

if (!defined('IN_BOARDS')) {
    define('IN_BOARDS', true);
}

/* Counting DB queries */
$QueryCount = 0;

/* Security/user state */
$MyData = [];
$userlogindata_a = [];
$time = time();

/* Shared footer/layout defaults for browser-facing routes. */
$currentserver = $_ENV['COMPUTERNAME'] ?? '';
$protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
$copyright = 'CoreBB';

include_once __DIR__ . '/../config.php';
corebb_security_bootstrap();
require_once __DIR__ . '/../dbheader.php';
require_once __DIR__ . '/auth_password_helpers.php';

/* GeSHi Code Syntax Highlighter */
if (file_exists(__DIR__ . '/../geshi/geshi.php')) {
    include_once __DIR__ . '/../geshi/geshi.php';
}

/* Session variables */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

corebb_security_enforce_csrf_if_needed();

require_once __DIR__ . '/auth_session_helpers.php';
require_once __DIR__ . '/corebb_browser_helpers.php';
corebb_load_logged_in_user();
