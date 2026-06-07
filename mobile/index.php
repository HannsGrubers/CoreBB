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
 |  index.php  - Mobile API-backed forum shell.          |
 +-------------------------------------------------------+*/

if (!defined('IN_BOARDS')) {
    define('IN_BOARDS', true);
}
require_once dirname(__DIR__) . '/config.php';

$screen = preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['screen'] ?? 'index')) ?: 'index';
$returnUrl = (string)($_GET['return'] ?? '/');
// The return URL is only used for the Classic link; keep it site-local so a
// mobile redirect cannot become an open redirect.
if ($returnUrl === '' || $returnUrl[0] !== '/') {
    $returnUrl = '/';
}
$desktopJoin = str_contains($returnUrl, '?') ? '&' : '?';
$desktopUrl = $returnUrl . $desktopJoin . 'view=desktop';
$mobileTitle = trim((string)($BoardName ?? 'CoreBB'));
if ($mobileTitle === '') {
    $mobileTitle = 'CoreBB';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($mobileTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> Mobile</title>
    <link rel="stylesheet" href="/mobile/style.css">
</head>
<body data-initial-screen="<?= htmlspecialchars($screen, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <header class="mobile-topbar">
        <button type="button" class="icon-button" data-action="back" aria-label="Back">&lsaquo;</button>
        <a class="brand" href="/mobile/?screen=index&view=mobile" title="<?= htmlspecialchars($mobileTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($mobileTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
        <a class="classic-link" href="<?= htmlspecialchars($desktopUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Classic</a>
    </header>
    <nav id="mobileNav" class="mobile-nav" aria-label="CoreBB mobile navigation"></nav>
    <div id="mobileSession" class="mobile-session"></div>
    <main id="mobileApp" class="mobile-main" aria-live="polite">
        <div class="loading">Loading CoreBB...</div>
    </main>
    <script src="/mobile/app.js"></script>
</body>
</html>
