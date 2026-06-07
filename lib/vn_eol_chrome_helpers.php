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
 |  vn_eol_chrome_helpers.php  - VN EOL chrome model     |
 |  helpers for Twig-rendered public/admin chrome.       |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/theme_helpers.php';

if (!defined('COREBB_VN_EOL_CHROME_HELPERS_LOADED')) {
    define('COREBB_VN_EOL_CHROME_HELPERS_LOADED', true);
}

/**
 * Usage: Build the classic-theme chrome links for VN EOL layouts.
 * Referenced by: public layout view model and legacy admin header/footer.
 *
 * @param string $fallbackSiteUrl Legacy site URL, retained for call compatibility.
 * @param string $fallbackSiteName Legacy site name, retained for call compatibility.
 * @param string $fallbackBoardUrl Legacy board URL, retained for call compatibility.
 * @param string $fallbackBoardName Legacy board name, retained for call compatibility.
 * @return array<string, string> Chrome URLs and labels.
 */
function corebb_vn_eol_chrome_links(string $fallbackSiteUrl = '', string $fallbackSiteName = '', string $fallbackBoardUrl = '', string $fallbackBoardName = ''): array
{
    $siteUrl = trim($fallbackSiteUrl) !== '' ? rtrim($fallbackSiteUrl, '/') : corebb_theme_url('index.php');
    $siteName = trim($fallbackSiteName) !== '' ? trim($fallbackSiteName) : 'CoreBB';
    $boardUrl = trim($fallbackBoardUrl) !== '' ? rtrim($fallbackBoardUrl, '/') : corebb_theme_url('index.php');
    $boardName = trim($fallbackBoardName) !== '' ? trim($fallbackBoardName) : 'CoreBB Forum';

    return [
        'site_url' => $siteUrl,
        'site_name' => $siteName,
        'board_url' => $boardUrl,
        'board_name' => $boardName,
    ];
}

/**
 * Usage: Resolve an image asset inside the VN EOL theme folder.
 * Referenced by: lib/layout_view_model.php.
 *
 * @param string $path Theme image path relative to images/vn_eol/.
 * @return string Public theme asset URL.
 */
function corebb_theme_asset(string $path): string
{
    return corebb_theme_url('images/vn_eol/' . ltrim($path, '/'));
}
