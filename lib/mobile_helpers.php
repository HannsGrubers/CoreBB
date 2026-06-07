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
 |  mobile_helpers.php  - Mobile view routing helpers.   |
 +-------------------------------------------------------+*/

const COREBB_VIEW_MODE_COOKIE = 'corebb_view_mode';

/**
 * Check whether the current request can safely redirect to the mobile shell.
 *
 * Usage: preserve form submissions and other non-idempotent requests on the
 * original page.
 * Referenced by: corebb_mobile_should_use_mobile().
 *
 * @return bool True for GET and HEAD requests.
 */
function corebb_mobile_request_method_allows_redirect(): bool
{
    // Never redirect POST/PUT-style requests into the mobile shell; preserving
    // form submissions is more important than device detection.
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    return $method === 'GET' || $method === 'HEAD';
}

/**
 * Resolve an explicit or cookie-stored view mode.
 *
 * Usage: honor ?view=mobile/desktop/classic and persist the preference.
 * Referenced by: corebb_mobile_should_use_mobile().
 *
 * @return string "mobile", "desktop", or an empty string for auto-detect.
 */
function corebb_mobile_requested_view_mode(): string
{
    $requested = strtolower(trim((string)($_GET['view'] ?? '')));
    if (in_array($requested, ['mobile', 'desktop', 'classic'], true)) {
        $mode = $requested === 'classic' ? 'desktop' : $requested;
        if (!headers_sent()) {
            setcookie(COREBB_VIEW_MODE_COOKIE, $mode, [
                'expires' => time() + 60 * 60 * 24 * 90,
                'path' => '/',
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
        return $mode;
    }

    $stored = strtolower(trim((string)($_COOKIE[COREBB_VIEW_MODE_COOKIE] ?? '')));
    if ($stored === 'mobile') {
        return 'mobile';
    }
    if ($stored === 'desktop' && !corebb_mobile_user_agent_is_mobile()) {
        return 'desktop';
    }
    return '';
}

/**
 * Detect mobile/tablet browsers from request headers.
 *
 * Usage: decide whether a GET/HEAD request should be redirected to /mobile/.
 * Referenced by: corebb_mobile_should_use_mobile().
 *
 * @return bool True when headers indicate a mobile or tablet client.
 */
function corebb_mobile_user_agent_is_mobile(): bool
{
    $deviceType = strtolower(trim((string)(
        $_SERVER['HTTP_CF_DEVICE_TYPE']
        ?? $_SERVER['HTTP_X_DEVICE_TYPE']
        ?? $_SERVER['HTTP_X_UA_DEVICE']
        ?? ''
    )));
    if (in_array($deviceType, ['mobile', 'tablet'], true)) {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_WAP_PROFILE']) || !empty($_SERVER['HTTP_PROFILE'])) {
        return true;
    }

    $clientHintMobile = strtolower(trim((string)($_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? '')));
    if ($clientHintMobile === '?1' || $clientHintMobile === '1') {
        return true;
    }

    $clientHintPlatform = strtolower((string)($_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ?? ''));
    if ($clientHintPlatform !== '' && preg_match('/android|ios|ipad|iphone/', $clientHintPlatform)) {
        return true;
    }

    $agent = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($agent === '') {
        return false;
    }

    // Search/social crawlers should see stable public URLs instead of the
    // JavaScript mobile shell.
    if (preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|discordbot|twitterbot/', $agent)) {
        return false;
    }

    return (bool)preg_match(
        '/android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini|mobile|phone|tablet|kindle|silk|windows phone|mobile safari/',
        $agent
    );
}

/**
 * Decide whether the current request should use the mobile shell.
 *
 * Usage: send client hints, respect explicit view mode, and combine method and
 * user-agent checks.
 * Referenced by: corebb_mobile_redirect().
 *
 * @return bool True when a redirect to /mobile/ should occur.
 */
function corebb_mobile_should_use_mobile(): bool
{
    if (!headers_sent()) {
        header('Accept-CH: Sec-CH-UA-Mobile, Sec-CH-UA-Platform');
        header('Vary: Sec-CH-UA-Mobile, Sec-CH-UA-Platform', false);
    }

    if (!corebb_mobile_request_method_allows_redirect()) {
        return false;
    }

    $mode = corebb_mobile_requested_view_mode();
    if ($mode === 'desktop') {
        return false;
    }
    if ($mode === 'mobile') {
        return true;
    }

    return corebb_mobile_user_agent_is_mobile();
}

/**
 * Return the current URL used for the mobile Classic link.
 *
 * Usage: let the mobile shell send users back to the exact desktop page.
 * Referenced by: corebb_mobile_redirect().
 *
 * @return string Current request URI or site root.
 */
function corebb_mobile_current_return_url(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    return $uri !== '' ? $uri : '/';
}

/**
 * Redirect eligible requests to the mobile shell.
 *
 * Usage: call near public page entry points with the matching mobile screen and
 * route parameters.
 * Referenced by: mobile-aware public scripts.
 *
 * @param string $screen Mobile screen name.
 * @param array<string, mixed> $params Additional mobile query parameters.
 * @return void
 */
function corebb_mobile_redirect(string $screen = 'index', array $params = []): void
{
    if (!corebb_mobile_should_use_mobile()) {
        return;
    }

    // Preserve the original URL so the Classic link can return users to the
    // exact desktop page that triggered mobile routing.
    $params = ['screen' => $screen] + $params;
    $params['return'] = corebb_mobile_current_return_url();
    $target = '/mobile/?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    header('Location: ' . $target);
    exit;
}
