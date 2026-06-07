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
 |  corebb_url_helpers.php  - Canonical CoreBB public    |
 |  URL helpers.                                         |
 +-------------------------------------------------------+*/

if (!defined('COREBB_URL_HELPERS_LOADED')) {
    define('COREBB_URL_HELPERS_LOADED', true);
}

/**
 * Usage: Return the public root used by link and asset builders.
 * Referenced by: corebb_public_pretty_url() and functions.php's pretty URL implementation.
 *
 * @return string Root-relative base path with a trailing slash.
 */
function corebb_public_base_path(): string
{
    if (defined('COREBB_PUBLIC_BASE_PATH')) {
        $base = trim((string)COREBB_PUBLIC_BASE_PATH);
    } elseif (defined('COREBB_PUBLIC_BASE_URL')) {
        $base = (string)(parse_url((string)COREBB_PUBLIC_BASE_URL, PHP_URL_PATH) ?: '');
    } else {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = trim(dirname($script), '/');
        foreach (['controllers', 'mobile'] as $internalDir) {
            if ($dir === $internalDir) {
                $dir = '';
                break;
            }
            if (str_ends_with($dir, '/' . $internalDir)) {
                $dir = substr($dir, 0, -1 * (strlen($internalDir) + 1));
                break;
            }
        }
        if ($dir === 'api/v1') {
            $dir = '';
        } elseif (str_ends_with($dir, '/api/v1')) {
            $dir = trim(substr($dir, 0, -7), '/');
        }
        $base = $dir !== '' && $dir !== '.' ? '/' . trim($dir, '/') : '';
    }

    $base = '/' . trim((string)$base, '/');
    return $base === '/' ? '/' : $base . '/';
}

/**
 * Usage: Prefix a local URL with the configured forum base path.
 * Referenced by: corebb_public_pretty_url() and functions.php's pretty URL implementation.
 *
 * @param string $path Local path, query string, fragment, or absolute URL.
 * @return string Public URL rooted at the forum base path.
 */
function corebb_public_join_base_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return corebb_public_base_path();
    }
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?|javascript:|mailto:|tel:)~i', $path)) {
        return $path;
    }

    $base = rtrim(corebb_public_base_path(), '/');
    if ($base === '') {
        return '/' . ltrim($path, '/');
    }

    if ($path[0] === '/') {
        if ($path === $base || str_starts_with($path, $base . '/')) {
            return $path;
        }
        return $base . $path;
    }

    return $base . '/' . ltrim($path, '/');
}

/**
 * Usage: Normalize a legacy/public path into the forum's public URL shape.
 * Referenced by: corebb_public_url(), templates, and theme helpers.
 *
 * When functions.php is loaded, corebb_public_pretty_url_impl() performs the
 * full legacy script-to-route mapping. When this lightweight helper file is
 * loaded by itself, this function still anchors relative paths at the web root.
 *
 * @param string $path Link path to normalize.
 * @return string Public URL suitable for href/src attributes after escaping.
 */
function corebb_public_pretty_url(string $path): string
{
    if (function_exists('corebb_public_pretty_url_impl')) {
        return corebb_public_pretty_url_impl($path);
    }

    $path = trim($path);
    if ($path === '') {
        return corebb_public_base_path();
    }
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?|javascript:|mailto:|tel:)~i', $path)) {
        return $path;
    }
    return corebb_public_join_base_path($path);
}

/**
 * Usage: Short alias for building public forum URLs.
 * Referenced by: legacy helpers and Twig-facing view data.
 *
 * @param string $path Link path to normalize.
 * @return string Public URL rooted at the forum base path.
 */
function corebb_public_url(string $path): string
{
    return corebb_public_pretty_url($path);
}

/**
 * Usage: Normalize local asset paths before they are placed in templates.
 * Referenced by: avatar/icon/star helpers and stylesheet/image view data.
 *
 * @param string $path Asset path relative to the forum root, or an absolute URL.
 * @return string Public asset URL.
 */
function corebb_public_asset(string $path): string
{
    return corebb_public_url($path);
}
