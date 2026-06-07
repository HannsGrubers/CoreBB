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
 |  theme_helpers.php  - Public theme settings and URL   |
 |  helpers for CoreBB.                                  |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/corebb_url_helpers.php';

if (!defined('COREBB_THEME_HELPERS_LOADED')) {
    define('COREBB_THEME_HELPERS_LOADED', true);
}

/**
 * Usage: Interpret a system setting value as a boolean flag.
 * Referenced by: corebb_theme_is_vn_eol().
 *
 * @param mixed $value Stored setting value.
 * @return bool True for common truthy values.
 */
function corebb_theme_setting_truthy($value): bool
{
    return ($value === true || $value === 1 || $value === '1'
        || strtolower((string)$value) === 'true'
        || strtolower((string)$value) === 'yes'
        || strtolower((string)$value) === 'on');
}

/**
 * Usage: Determine whether public pages should use the VN EOL theme.
 * Referenced by: public layout rendering.
 *
 * @return bool True when the VN EOL public theme is enabled.
 */
function corebb_theme_is_vn_eol(): bool
{
    /* Keep the already-matched VN-style admin pages on their lean admin chrome. */
    if (defined('IN_ADMIN')) {
        return false;
    }

    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (!function_exists('db_one')) {
        return true;
    }

    $row = db_one('SELECT setting FROM systemsettings WHERE name = ? LIMIT 1', ['theme_vn_eol']);

    /* New installs of this patch should visibly switch to the new theme. */
    if (!$row || !array_key_exists('setting', $row)) {
        $cached = true;
        return $cached;
    }

    $cached = corebb_theme_setting_truthy($row['setting']);
    return $cached;
}


/**
 * Usage: Return the public asset base path for theme URLs.
 * Referenced by: corebb_theme_url().
 *
 * @return string Public asset base path.
 */
function corebb_theme_base_path(): string
{
    /* Keep public assets anchored at the configured forum base path so pretty
     * URLs like /generaldiscussions/b1/ do not make the browser request
     * /generaldiscussions/b1/style_vn_eol.css.
     */
    return function_exists('corebb_public_base_path') ? corebb_public_base_path() : '/';
}

/**
 * Usage: Resolve a theme asset path against the public site root.
 * Referenced by: admin layout, layout view model, and VN EOL chrome helpers.
 *
 * @param string $path Relative, absolute, external, or anchor asset path.
 * @return string Resolved theme URL.
 */
function corebb_theme_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return corebb_theme_base_path();
    }
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#)~i', $path)) {
        return $path;
    }
    if (function_exists('corebb_public_pretty_url')) {
        return corebb_public_pretty_url($path);
    }
    if ($path[0] === '/') {
        return $path;
    }
    return corebb_theme_base_path() . ltrim($path, '/');
}
