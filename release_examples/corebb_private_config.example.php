<?php
/*
 * CoreBB private configuration example.
 *
 * Copy this file outside the public web root, then point CoreBB at it with
 * COREBB_PRIVATE_CONFIG, or place it in the private path selected by the
 * installer/config loader.
 */

$MySQL_Host = 'localhost';
$MySQL_User = 'corebb_user';
$MySQL_Pass = 'change-me';
$MySQL_Database = 'corebb';

$SiteName = 'CoreBB';
$SiteURL = 'https://example.com';
$BoardName = 'CoreBB Forum';
$BoardURL = 'https://example.com/forum';

$CookieDomain = '';
$SQLPrefix = '';
$BoardLockdown = '0';

// Optional mail settings. Configure real values from Admin -> Mail Services.
defined('COREBB_SMTP_HOST') || define('COREBB_SMTP_HOST', '');
defined('COREBB_SMTP_PORT') || define('COREBB_SMTP_PORT', 587);
defined('COREBB_SMTP_USERNAME') || define('COREBB_SMTP_USERNAME', '');
defined('COREBB_SMTP_PASSWORD') || define('COREBB_SMTP_PASSWORD', '');
defined('COREBB_SMTP_SECURE') || define('COREBB_SMTP_SECURE', 'tls');
defined('COREBB_MAIL_FROM') || define('COREBB_MAIL_FROM', 'no-reply@example.com');
