<?php
/*-------------------------------------------------------
 | corebb_browser_helpers.php - Shared browser helpers.
 |
 | Browser routes, admin pages, and view models include
 | this when they need the common CoreBB helper set.
 +-------------------------------------------------------*/

if (defined('COREBB_BROWSER_HELPERS_LOADED')) {
    return;
}

define('COREBB_BROWSER_HELPERS_LOADED', true);

require_once __DIR__ . '/../../core/version.php';
require_once __DIR__ . '/vip_style_helpers.php';
require_once __DIR__ . '/auth_password_helpers.php';
require_once __DIR__ . '/corebb_url_helpers.php';
require_once __DIR__ . '/corebb_route_helpers.php';
require_once __DIR__ . '/corebb_image_helpers.php';
require_once __DIR__ . '/user_display_helpers.php';
require_once __DIR__ . '/corebb_markup_helpers.php';
require_once __DIR__ . '/corebb_date_helpers.php';
require_once __DIR__ . '/performance_helpers.php';
require_once __DIR__ . '/pagination_helpers.php';
require_once __DIR__ . '/admin_log_helpers.php';
require_once __DIR__ . '/private_board_helpers.php';
require_once __DIR__ . '/corebb_forum_helpers.php';

function corebb_browser_version_label(): string
{
    if (defined('COREBB_ENV') && COREBB_ENV === 'staging' && COREBB_VERSION === '1.0.0') {
        $installed = (string)db_value('SELECT setting FROM systemsettings WHERE name=? ORDER BY id LIMIT 1', ['installed_version'], '');
        return $installed !== '' ? 'CoreBB v' . $installed . ' · Development preview' : 'CoreBB Development preview';
    }
    return corebb_version_label();
}
