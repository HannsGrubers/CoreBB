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
require_once dirname(__DIR__) . '/helpers/db.php';

if (!empty($BoardLockdown)) {
    corebb_api_error('board_locked', 'The board is currently unavailable.', 503);
}

$link = db_connect($MySQL_Host ?? null, $MySQL_User ?? null, $MySQL_Pass ?? null, $MySQL_Database ?? null);
if (!$link) {
    corebb_api_error('database_unavailable', 'Unable to connect to the database.', 503);
}

require_once dirname(__DIR__) . '/helpers/security.php';
require_once dirname(__DIR__) . '/helpers/auth_password_helpers.php';
require_once dirname(__DIR__) . '/helpers/corebb_browser_helpers.php';
require_once dirname(__DIR__) . '/helpers/auth_session_helpers.php';
require_once dirname(__DIR__) . '/helpers/view.php';
require_once __DIR__ . '/guardrails.php';

corebb_security_bootstrap();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
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
