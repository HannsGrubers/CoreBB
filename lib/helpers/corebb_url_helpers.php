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

if (defined('COREBB_URL_HELPERS_LOADED')) {
    return;
}

define('COREBB_URL_HELPERS_LOADED', true);

/**
 * Usage: Return the public root used by link and asset builders.
 * Referenced by: corebb_public_join_base_path(), route builders, and mobile/API helpers.
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
        $dir = trim(str_replace('\\', '/', dirname($script)), '/');
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
 * Referenced by: route builders, templates, redirects, and asset helpers.
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
