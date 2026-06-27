<?php
/*                        ''~``
                         ( o o )
 +------------------.oooO--(_)--Oooo.--------------------+
 |                        CoreBB                         |
 |        Developed by -Prismatic- / HannsGruber         |
 |                Copyright (c) 2005 - 2026              |
 |                  All Rights Reserved.                 |
 +-------------------------------------------------------+
 |  version.php  - Installed CoreBB version constants.   |
 +-------------------------------------------------------+*/

if (!defined('COREBB_VERSION')) {
    define('COREBB_VERSION', '1.1.9');
}

if (!defined('COREBB_SCHEMA_VERSION')) {
    define('COREBB_SCHEMA_VERSION', 1);
}

/**
 * Usage: Return the release label used in public and admin footers.
 * Referenced by: public/admin layout view models.
 */
function corebb_version_label(): string
{
    return 'CoreBB v' . COREBB_VERSION;
}
