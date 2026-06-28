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
 * @param string $siteUrlOverride Site URL supplied by the active installation.
 * @param string $siteNameOverride Site name supplied by the active installation.
 * @param string $boardUrlOverride Board URL supplied by the active installation.
 * @param string $boardNameOverride Board name supplied by the active installation.
 * @return array<string, string> Chrome URLs and labels.
 */
function corebb_vn_eol_chrome_links(string $siteUrlOverride = '', string $siteNameOverride = '', string $boardUrlOverride = '', string $boardNameOverride = ''): array
{
    $siteUrl = trim($siteUrlOverride) !== '' ? rtrim($siteUrlOverride, '/') : corebb_theme_url('/');
    $siteName = trim($siteNameOverride) !== '' ? trim($siteNameOverride) : 'CoreBB';
    $boardUrl = trim($boardUrlOverride) !== '' ? rtrim($boardUrlOverride, '/') : corebb_theme_url('/');
    $boardName = trim($boardNameOverride) !== '' ? trim($boardNameOverride) : 'CoreBB Forum';

    return [
        'site_url' => $siteUrl,
        'site_name' => $siteName,
        'board_url' => $boardUrl,
        'board_name' => $boardName,
    ];
}

/**
 * Usage: Resolve an image asset inside the VN EOL theme folder.
 * Referenced by: lib/models/layout_view_model.php.
 *
 * @param string $path Theme image path relative to images/vn_eol/.
 * @return string Public theme asset URL.
 */
function corebb_theme_asset(string $path): string
{
    return corebb_theme_url('images/vn_eol/' . ltrim($path, '/'));
}
