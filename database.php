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
 |  database.php  - PHP 8 PDO bootstrap.                 |
 +-------------------------------------------------------+*/

if (!defined('IN_BOARDS')) {
    define('IN_BOARDS', true);
}
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/lib/db.php');

$link = db_connect($MySQL_Host, $MySQL_User, $MySQL_Pass, $MySQL_Database);
if (!$link) {
    die('Unable to connect to database server: ' . htmlspecialchars(db_error(), ENT_QUOTES, 'UTF-8'));
}
?>
