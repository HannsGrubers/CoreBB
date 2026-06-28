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
 |  security.php  - Security helpers.                    |
 +-------------------------------------------------------+*/

if (!defined('COREBB_SECURITY_LOADED')) {
    define('COREBB_SECURITY_LOADED', true);
}

require_once __DIR__ . '/corebb_route_helpers.php';

/**
 * Usage: Determine whether the current request should receive secure cookies.
 * Referenced by: corebb_security_bootstrap() and corebb_security_set_cookie().
 */
function corebb_security_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (!empty($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') {
        return true;
    }

    if (corebb_security_trust_proxy_headers() && !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0] ?? ''));
        if ($proto === 'https') {
            return true;
        }
    }
    return false;
}

/**
 * Usage: Decide whether reverse-proxy HTTPS headers can be trusted.
 * Referenced by: corebb_security_is_https().
 */
function corebb_security_trust_proxy_headers(): bool
{
    if (defined('COREBB_TRUST_PROXY_HEADERS')) {
        return (bool)COREBB_TRUST_PROXY_HEADERS;
    }

    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote === '') {
        return false;
    }
    if (in_array($remote, ['127.0.0.1', '::1'], true)) {
        return true;
    }
    if (!filter_var($remote, FILTER_VALIDATE_IP)) {
        return false;
    }
    return filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * Usage: Apply shared browser security headers and session cookie policy.
 * Referenced by: lib/helpers/bootstrap.php and lib/api/bootstrap.php.
 */
function corebb_security_bootstrap(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $params = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => (int)($params['lifetime'] ?? 0),
            'path' => (string)($params['path'] ?? '/'),
            'domain' => (string)($params['domain'] ?? ''),
            'secure' => corebb_security_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    if (!headers_sent()) {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    }
}

/**
 * Usage: Start the legacy output filter that normalizes local URLs and CSRF fields.
 * Referenced by: lib/helpers/bootstrap.php before public/admin routes render output.
 */
function corebb_security_start_output_filter(): void
{
    if (corebb_security_output_filter_active()) {
        return;
    }

    ob_start('corebb_security_output_filter');
}

/**
 * Usage: Check whether the CoreBB output filter has already been registered.
 * Referenced by: corebb_security_start_output_filter().
 */
function corebb_security_output_filter_active(): bool
{
    foreach (ob_list_handlers() as $handler) {
        if (stripos((string)$handler, 'corebb_security_output_filter') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Usage: Mark a response as explicitly carrying its own CSRF fields.
 * Referenced by: corebb_render_public() for Twig-rendered public pages.
 */
function corebb_security_set_explicit_csrf_fields(bool $explicit = true): void
{
    $GLOBALS['COREBB_EXPLICIT_CSRF_FIELDS'] = $explicit;
}

/**
 * Usage: Tell the output filter whether it should skip automatic CSRF injection.
 * Referenced by: corebb_security_output_filter().
 */
function corebb_security_uses_explicit_csrf_fields(): bool
{
    return !empty($GLOBALS['COREBB_EXPLICIT_CSRF_FIELDS']);
}

/**
 * Usage: Return the current session CSRF token, creating one when needed.
 * Referenced by: Twig csrf_token(), API guardrails, and legacy form helpers.
 */
function corebb_security_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['corebb_csrf_token']) || !is_string($_SESSION['corebb_csrf_token'])) {
        if (!empty($_SESSION['wb_csrf_token']) && is_string($_SESSION['wb_csrf_token'])) {
            $_SESSION['corebb_csrf_token'] = (string)$_SESSION['wb_csrf_token'];
        } else {
            $_SESSION['corebb_csrf_token'] = bin2hex(random_bytes(32));
        }
    }
    return (string)$_SESSION['corebb_csrf_token'];
}

/**
 * Usage: Render a hidden CSRF input for legacy PHP forms.
 * Referenced by: archive tools and the automatic output-filter injector.
 */
function corebb_security_csrf_field(string $name = 'corebb_csrf_token'): string
{
    return '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="'
        . htmlspecialchars(corebb_security_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Usage: Validate normal form POST tokens against the session token.
 * Referenced by: corebb_security_enforce_csrf_if_needed().
 */
function corebb_security_csrf_valid(array $post): bool
{
    $token = (string)($post['corebb_csrf_token'] ?? $post['wb_csrf_token'] ?? '');
    return $token !== '' && hash_equals(corebb_security_csrf_token(), $token);
}

/**
 * Usage: Return a reusable session token for a named feature form.
 * Referenced by: feature-specific token wrappers in admin and public models.
 */
function corebb_security_named_token(string $sessionKey, int $bytes = 16, string $legacySessionKey = ''): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    if (empty($_SESSION[$sessionKey]) || !is_string($_SESSION[$sessionKey])) {
        if ($legacySessionKey !== '' && !empty($_SESSION[$legacySessionKey]) && is_string($_SESSION[$legacySessionKey])) {
            $_SESSION[$sessionKey] = (string)$_SESSION[$legacySessionKey];
        } else {
            $_SESSION[$sessionKey] = bin2hex(random_bytes(max(16, $bytes)));
        }
    }
    return (string)$_SESSION[$sessionKey];
}

/**
 * Usage: Validate a submitted feature-form token against a named session token.
 * Referenced by: feature-specific token wrappers in admin and public models.
 */
function corebb_security_named_token_valid(string $sessionKey, array $post, string $postKey = 'token', string $legacySessionKey = ''): bool
{
    $token = (string)($post[$postKey] ?? '');
    return $token !== '' && hash_equals(corebb_security_named_token($sessionKey, 16, $legacySessionKey), $token);
}

/**
 * Usage: Read the current script name in one compatibility-safe place.
 * Referenced by: future route-specific security decisions.
 */
function corebb_security_current_script(): string
{
    return basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
}

/**
 * Usage: Central hook for rare POST endpoints that must opt out of form CSRF.
 * Referenced by: corebb_security_enforce_csrf_if_needed().
 */
function corebb_security_post_exempt(): bool
{
    $script = corebb_security_current_script();
    $action = strtolower(trim((string)($_GET['action'] ?? '')));

    // Google Identity Services posts its own double-submit CSRF token to this
    // callback. Route code validates g_csrf_token before accepting the ID token.
    return $script === 'auth.php' && $action === 'google';
}

/**
 * Usage: Stop a bad form POST with a small standalone error page.
 * Referenced by: corebb_security_enforce_csrf_if_needed().
 */
function corebb_security_reject_bad_csrf(): never
{
    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Security Check Failed</title></head><body>';
    echo '<table class="wb-table wb-w-full wb-cellspace-1 wb-cellpad-4"><tr><td class="BoardColumn">Security Check Failed</td></tr>';
    echo '<tr><td class="BoardRowB">Your form token was missing or expired. Please go back, reload the page, and try again.</td></tr></table>';
    echo '</body></html>';
    exit;
}

/**
 * Usage: Reject normal POST requests before route code mutates state.
 * Referenced by: lib/helpers/bootstrap.php after the session starts.
 */
function corebb_security_enforce_csrf_if_needed(): void
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }
    if (corebb_security_post_exempt()) {
        return;
    }
    if (!corebb_security_csrf_valid($_POST)) {
        corebb_security_reject_bad_csrf();
    }
}


/**
 * Usage: Rebuild a query string while dropping parameters consumed by pretty URLs.
 * Referenced by: corebb_security_pretty_href_url().
 */
function corebb_security_query_without(array $params, array $remove = []): string
{
    foreach ($remove as $key) {
        unset($params[$key]);
    }
    return $params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '';
}


/**
 * Usage: Rewrite recognized admin.php links to the canonical /admin/ route.
 * Referenced by: corebb_security_pretty_href_url().
 */
function corebb_security_admin_pretty_href_url(string $script, array $params, string $fragment = ''): ?string
{
    $script = strtolower(basename($script));
    if ($script !== 'admin.php') {
        return null;
    }

    $act = strtolower(trim((string)($params['act'] ?? '')));
    if ($act === '') {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return '/admin/' . ($query !== '' ? '?' . $query : '') . $fragment;
    }

    $knownActs = [
        'version_history', 'edit_settings', 'auth_settings', 'mail_services', 'administrator_tools', 'database_tools',
        'api_explorer', 'forum_sim', 'edit_tos', 'edit_style', 'edit_rights',
        'change_user_password', 'add_user', 'view_message', 'global_message',
        'edit_global_message', 'remove_global_message', 'manageboards', 'movebrd',
        'movecat', 'add_category', 'delete_category', 'manageboards_cat', 'addboard',
        'modifyboard', 'manage_icons', 'moderation', 'admin_notes', 'action_log',
        'user_pages', 'user_ip_check', 'host_lookup', 'mod_requests',
        'moderate_message', 'deleted_posts', 'spam_ratings', 'pm_reports',
        'pm_history', 'contact_mods', 'latest_users', 'assign_title',
        'edit_profile', 'user_appearance',
    ];

    if (!in_array($act, $knownActs, true)) {
        return null;
    }

    $canonical = ['act' => $act];
    unset($params['act']);

    if ($act === 'moderation') {
        $mode = strtolower(trim((string)($params['mode'] ?? '')));
        if (in_array($mode, ['ban', 'requests', 'unban'], true)) {
            $canonical['mode'] = $mode;
            unset($params['mode']);
        }
    }

    $query = http_build_query($canonical + $params, '', '&', PHP_QUERY_RFC3986);
    return '/admin/' . ($query !== '' ? '?' . $query : '') . $fragment;
}

/**
 * Usage: Convert legacy local PHP links into the public pretty URL scheme.
 * Referenced by: corebb_security_root_relative_html_urls().
 */
function corebb_security_pretty_href_url(string $url): string
{
    $original = $url;
    $url = trim($url);
    if ($url === '') {
        return $original;
    }

    if ($url[0] === '#' || $url[0] === '?' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|javascript:|mailto:|tel:)~i', $url)) {
        return $original;
    }

    $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $fragment = '';
    $hashPos = strpos($decoded, '#');
    if ($hashPos !== false) {
        $fragment = substr($decoded, $hashPos);
        $decoded = substr($decoded, 0, $hashPos);
    }

    $query = '';
    $qPos = strpos($decoded, '?');
    if ($qPos !== false) {
        $query = substr($decoded, $qPos + 1);
        $decoded = substr($decoded, 0, $qPos);
    }

    $localPath = ltrim(preg_replace('~^\./+~', '', $decoded) ?? $decoded, '/');
    $script = strtolower(basename($localPath));
    parse_str(str_replace('&amp;', '&', $query), $params);

    $adminPretty = corebb_security_admin_pretty_href_url($script, $params, $fragment);
    if ($adminPretty !== null) {
        return $adminPretty;
    }

    $pretty = null;
    switch ($script) {
        case 'index.php':
            $pretty = '/';
            break;
        case 'auth.php':
            $action = strtolower((string)($params['action'] ?? 'login'));
            if ($action === 'login_submit') {
                $pretty = '/login/submit/';
            } elseif ($action === 'register') {
                $pretty = '/register/';
            } elseif ($action === 'recover') {
                $pretty = '/recover-account/';
            } elseif ($action === 'reset') {
                $pretty = '/reset-password/';
            } elseif ($action === 'verify') {
                $pretty = '/verify-email/';
            } elseif ($action === 'resend') {
                $pretty = '/resend-verification/';
            } elseif ($action === 'logout') {
                $pretty = '/logoff/';
            } else {
                $pretty = '/login/';
            }
            $query = ltrim(corebb_security_query_without($params, ['action']), '?');
            break;
        case 'support.php':
            $action = strtolower((string)($params['action'] ?? 'denied'));
            if ($action === 'banned') {
                $pretty = '/banned/';
                $query = ltrim(corebb_security_query_without($params, ['action']), '?');
            } elseif ($action === 'faq') {
                $pretty = '/board-rules-faq/';
                $query = ltrim(corebb_security_query_without($params, ['action']), '?');
            } elseif ($action === 'security') {
                $pretty = '/security/';
                $query = ltrim(corebb_security_query_without($params, ['action']), '?');
            } elseif ($action === 'contact') {
                $pretty = '/contact-mods/';
                $query = ltrim(corebb_security_query_without($params, ['action']), '?');
            } elseif ($action === 'report') {
                $postId = isset($params['post']) ? (int)$params['post'] : (isset($params['id']) ? (int)$params['id'] : 0);
                $pretty = $postId > 0 ? '/report-message/' . $postId . '/' : '/report-message/';
                $query = ltrim(corebb_security_query_without($params, ['action', 'post', 'id']), '?');
            } elseif ($action === 'error') {
                $pretty = '/err/';
                $query = ltrim(corebb_security_query_without($params, ['action']), '?');
            } else {
                $pretty = '/denied/';
                $query = ltrim(corebb_security_query_without($params, ['action']), '?');
            }
            break;
        case 'usercp.php':
            $action = strtolower((string)($params['action'] ?? 'index'));
            if ($action === 'notifications') {
                $pretty = '/notifications/';
            } elseif ($action === 'profile') {
                $pretty = '/user-cp/profile/';
            } elseif ($action === 'avatar') {
                $pretty = '/user-cp/avatar/';
            } elseif ($action === 'signature') {
                $pretty = '/user-cp/signature/';
            } elseif ($action === 'options') {
                $pretty = '/user-cp/options/';
            } elseif ($action === 'favorites') {
                $pretty = '/user-cp/favorites/';
            } elseif ($action === 'appearance') {
                $pretty = '/user-cp/appearance/';
            } else {
                $pretty = '/user-cp/';
            }
            $query = ltrim(corebb_security_query_without($params, ['action']), '?');
            break;
        case 'admin.php':
            $pretty = '/admin/';
            break;
        case 'blogs.php':
            $action = strtolower((string)($params['action'] ?? 'home'));
            $id = isset($params['id']) ? (int)$params['id'] : 0;
            if ($action === 'my') {
                $pretty = '/blogs/my/';
                $query = ltrim(corebb_security_query_without($params, ['action']), '?');
            } elseif ($action === 'modify') {
                $pretty = '/blogs/modify/';
                $query = ltrim(corebb_security_query_without($params, ['action']), '?');
            } elseif ($action === 'viewblog' && $id > 0) {
                $pretty = '/blogs/user/' . $id . '/';
                $query = ltrim(corebb_security_query_without($params, ['action', 'id']), '?');
            } elseif ($action === 'viewentry' && $id > 0) {
                $pretty = '/blogs/entry/' . $id . '/';
                $query = ltrim(corebb_security_query_without($params, ['action', 'id']), '?');
            } elseif ($action === 'edit') {
                $pretty = $id > 0 ? '/blogs/entry/' . $id . '/edit/' : '/blogs/edit/';
                $query = ltrim(corebb_security_query_without($params, ['action', 'id']), '?');
            } elseif ($action === 'delete') {
                $pretty = $id > 0 ? '/blogs/entry/' . $id . '/delete/' : '/blogs/delete/';
                $query = ltrim(corebb_security_query_without($params, ['action', 'id']), '?');
            } else {
                $pretty = '/blogs/';
                $query = ltrim(corebb_security_query_without($params, ['action']), '?');
            }
            break;
        case 'content.php':
            $action = strtolower((string)($params['action'] ?? 'search'));
            if ($action === 'profile') {
                $id = isset($params['id']) ? (int)$params['id'] : 0;
                $pretty = $id > 0 ? '/profile/' . $id . '/' : '/profile/';
                $query = ltrim(corebb_security_query_without($params, ['action', 'id']), '?');
            } elseif ($action === 'profile_content') {
                $id = isset($params['id']) ? (int)$params['id'] : 0;
                $type = isset($params['type']) && (string)$params['type'] === 'posts' ? 'posts' : 'topics';
                $page = isset($params['p']) ? max(1, (int)$params['p']) : 1;
                $pretty = $id > 0 ? '/profile/' . $id . '/' . $type . '/' . ($page > 1 ? 'p' . $page . '/' : '') : '/profile/';
                $query = ltrim(corebb_security_query_without($params, ['action', 'id', 'type', 'p']), '?');
            } elseif ($action === 'post_id') {
                $id = isset($params['id']) ? (int)$params['id'] : 0;
                $pretty = $id > 0 ? '/post-id/' . $id . '/' : '/post-id/';
                $query = ltrim(corebb_security_query_without($params, ['action', 'id']), '?');
            } else {
                $pretty = '/search/';
                $query = ltrim(corebb_security_query_without($params, ['action']), '?');
            }
            break;
        case 'poll.php':
            $pretty = '/poll/vote/';
            $query = ltrim(corebb_security_query_without($params, []), '?');
            break;
        case 'forum.php':
            $action = strtolower((string)($params['action'] ?? 'board'));
            $id = isset($params['id']) ? (int)$params['id'] : 0;
            $page = isset($params['p']) ? max(1, (int)$params['p']) : max(1, (int)($params['page'] ?? 1));
            $boardId = isset($params['brd']) ? (int)$params['brd'] : (isset($params['boardid']) ? (int)$params['boardid'] : 0);
            if ($action === 'thread' && $id > 0) {
                $pretty = corebb_thread_url($id, $boardId, $page, '');
                $query = ltrim(corebb_security_query_without($params, ['action', 'id', 'p', 'page', 'brd', 'boardid']), '?');
            } elseif ($action === 'favorite') {
                $boardId = $boardId > 0 ? $boardId : $id;
                if ($boardId > 0) {
                    $pretty = '/board/' . $boardId . '/favorite/';
                    $query = ltrim(corebb_security_query_without($params, ['action', 'brd', 'boardid', 'id']), '?');
                }
            } else {
                $boardId = $id > 0 ? $id : $boardId;
                if ($boardId > 0) {
                    $pretty = corebb_board_url($boardId, $page, '');
                    $query = ltrim(corebb_security_query_without($params, ['action', 'id', 'brd', 'boardid', 'p', 'page']), '?');
                }
            }
            break;
        case 'messages.php':
            $action = strtolower((string)($params['action'] ?? 'folder'));
            $folder = strtolower((string)($params['folder'] ?? 'unread'));
            if ($action === 'send') {
                $recipient = isset($params['usr']) ? trim((string)$params['usr']) : '';
                $pretty = $recipient !== '' ? '/private-messages/send/' . rawurlencode($recipient) . '/' : '/private-messages/send/';
                $query = ltrim(corebb_security_query_without($params, ['action', 'usr']), '?');
            } elseif ($action === 'view') {
                $pmId = isset($params['pm']) ? (int)$params['pm'] : 0;
                $method = isset($params['method']) ? trim((string)$params['method']) : 'read';
                $method = preg_replace('~[^a-zA-Z0-9_-]~', '', $method) ?: 'read';
                if ($pmId > 0) {
                    $pretty = '/private-messages/message/' . $pmId . '/' . $method . '/';
                    $query = ltrim(corebb_security_query_without($params, ['action', 'pm', 'method']), '?');
                }
            } elseif ($folder === 'read') {
                $pretty = '/private-messages/read/';
                $query = ltrim(corebb_security_query_without($params, ['action', 'folder']), '?');
            } elseif ($folder === 'sent') {
                $pretty = '/private-messages/sent/';
                $query = ltrim(corebb_security_query_without($params, ['action', 'folder']), '?');
            } else {
                $pretty = '/private-messages/';
                $query = ltrim(corebb_security_query_without($params, ['action', 'folder']), '?');
            }
            break;
        case 'post.php':
            $act = isset($params['act']) ? strtolower(trim((string)$params['act'])) : '';
            $boardId = isset($params['boardid']) ? (int)$params['boardid'] : 0;
            $topicId = isset($params['id']) ? (int)$params['id'] : 0;
            $editId = isset($params['edit']) ? (int)$params['edit'] : 0;
            $quoteId = isset($params['quote']) ? (int)$params['quote'] : 0;
            $brd = isset($params['brd']) ? (int)$params['brd'] : 0;
            if ($act === 'image_upload') {
                $pretty = '/post/image-upload/';
                $query = ltrim(corebb_security_query_without($params, ['act']), '?');
            } elseif ($act === 'blog') {
                $pretty = '/blogs/new/';
                $query = ltrim(corebb_security_query_without($params, ['act']), '?');
            } elseif ($editId > 0) {
                $pretty = '/post/edit/' . $editId . '/';
                $query = ltrim(corebb_security_query_without($params, ['edit']), '?');
            } elseif ($act === 'reply' && $topicId > 0 && $brd > 0) {
                $pretty = '/post/reply/' . $topicId . '/b' . $brd . '/' . ($quoteId > 0 ? 'q' . $quoteId . '/' : '');
                $query = ltrim(corebb_security_query_without($params, ['id', 'brd', 'act', 'quote']), '?');
            } elseif ($act === 'new' && $boardId > 0) {
                $pretty = '/post/new/b' . $boardId . '/' . (!empty($params['poll']) ? 'poll/' : '');
                $query = ltrim(corebb_security_query_without($params, ['boardid', 'act', 'poll']), '?');
            } else {
                $pretty = '/post/submit/';
            }
            break;
        case 'moderation.php':
            $pretty = '/moderator/';
            $query = ltrim(corebb_security_query_without($params, []), '?');
            break;
    }

    if ($pretty === null) {
        return $original;
    }

    if ($query !== '') {
        $pretty .= (str_contains($pretty, '?') ? '&' : '?') . $query;
    }
    return $pretty . $fragment;
}

/**
 * Usage: Decide whether a URL can be safely converted to a root-relative path.
 * Referenced by: corebb_security_root_relative_url().
 */
function corebb_security_is_local_relative_url(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }

    // Do not alter anchors, query-only URLs, absolute/root URLs, protocol URLs,
    // data URIs, mailto/tel links, or JavaScript handlers.
    if ($url[0] === '/' || $url[0] === '#' || $url[0] === '?') {
        return false;
    }
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url)) {
        return false;
    }

    return true;
}

/**
 * Usage: Keep local asset/form paths working from rewritten pretty URLs.
 * Referenced by: corebb_security_root_relative_html_urls().
 */
function corebb_security_root_relative_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || $url[0] === '#' || $url[0] === '?' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url)) {
        return $url;
    }

    // Pretty URLs put the browser in /boardname/b1/...; base-prefixed local
    // paths keep page, asset, and form links working from root or /forum installs.
    $url = preg_replace('~^\./+~', '', $url) ?? $url;
    $rootPath = '/' . ltrim($url, '/');
    return corebb_public_join_base_path($rootPath);
}

/**
 * Usage: Normalize href/src/action attributes in legacy-rendered HTML.
 * Referenced by: corebb_security_output_filter().
 */
function corebb_security_root_relative_html_urls(string $html): string
{
    if ($html === '' || !preg_match('~\b(?:href|src|action)\s*=~i', $html)) {
        return $html;
    }

    return preg_replace_callback(
        '~\b(href|src|action)\s*=\s*(["\'])([^"\']*)\2~i',
        static function (array $m): string {
            $attr = strtolower($m[1]);
            $rawUrl = htmlspecialchars_decode($m[3], ENT_QUOTES);
            $url = ($attr === 'href' || $attr === 'action') ? corebb_security_pretty_href_url($rawUrl) : corebb_security_root_relative_url($rawUrl);
            if ($attr === 'href' || $attr === 'action') {
                $url = corebb_security_root_relative_url($url);
            }
            return $m[1] . '=' . $m[2] . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $m[2];
        },
        $html
    ) ?? $html;
}

/**
 * Usage: Add hidden CSRF fields to legacy POST forms that do not render one.
 * Referenced by: corebb_security_output_filter().
 */
function corebb_security_inject_csrf_fields(string $html): string
{
    if ($html === '' || stripos($html, '<form') === false || stripos($html, 'method') === false) {
        return $html;
    }

    $field = corebb_security_csrf_field();
    $injectClosedForms = preg_replace_callback(
        '~(<form\b(?=[^>]*\bmethod\s*=\s*(["\']?)post\2)[^>]*>)(.*?</form>)~is',
        static function (array $m) use ($field): string {
            $formHtml = $m[0];
            if (preg_match('~<input\b[^>]*\bname\s*=\s*(["\']?)(?:corebb_csrf_token|wb_csrf_token)\1~i', $formHtml)) {
                return $formHtml;
            }
            return $m[1] . $field . $m[3];
        },
        $html,
        -1,
        $count
    );
    if ($injectClosedForms !== null && $count > 0) {
        return $injectClosedForms;
    }

    return preg_replace_callback(
        '~(<form\b(?=[^>]*\bmethod\s*=\s*(["\']?)post\2)[^>]*>)(?!\s*<input\b[^>]*\bname\s*=\s*(["\']?)(?:corebb_csrf_token|wb_csrf_token)\3)~i',
        static fn(array $m): string => $m[1] . $field,
        $html
    ) ?? $html;
}

/**
 * Usage: Final pass over browser HTML before it leaves PHP.
 * Referenced by: ob_start() in corebb_security_start_output_filter().
 */
function corebb_security_output_filter(string $html): string
{
    $html = corebb_security_root_relative_html_urls($html);
    if (corebb_security_uses_explicit_csrf_fields()) {
        return $html;
    }
    return corebb_security_inject_csrf_fields($html);
}

/**
 * Usage: Set cookies with the shared CoreBB security defaults.
 * Referenced by: auth helpers through corebb_set_cookie()/clear wrappers.
 */
function corebb_security_set_cookie(string $name, string $value, int $expires, string $path = '/', string $domain = ''): bool
{
    $options = [
        'expires' => $expires,
        'path' => $path,
        'secure' => corebb_security_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if ($domain !== '') {
        $options['domain'] = $domain;
    }
    return setcookie($name, $value, $options);
}

/**
 * Usage: Expire a browser cookie and remove it from the current request.
 * Referenced by: auth/logout helpers.
 */
function corebb_security_clear_cookie(string $name, string $path = '/', string $domain = ''): void
{
    corebb_security_set_cookie($name, '', time() - 3600, $path, $domain);
    unset($_COOKIE[$name]);
}

/**
 * Usage: Resolve the HMAC secret used for persistent login cookies.
 * Referenced by: corebb_security_sign_login_cookie() and verify counterpart.
 */
function corebb_security_login_cookie_secret(): string
{
    if (defined('COREBB_COOKIE_SECRET')) {
        $secret = trim((string)COREBB_COOKIE_SECRET);
        if ($secret !== '') {
            return $secret;
        }
    }

    $envSecret = trim((string)(getenv('COREBB_COOKIE_SECRET') ?: ''));
    if ($envSecret !== '') {
        return $envSecret;
    }

    if (defined('WB_COOKIE_SECRET')) {
        $secret = trim((string)WB_COOKIE_SECRET);
        if ($secret !== '') {
            return $secret;
        }
    }

    $legacyEnvSecret = trim((string)(getenv('WB_COOKIE_SECRET') ?: ''));
    if ($legacyEnvSecret !== '') {
        return $legacyEnvSecret;
    }

    // Legacy fallback only. New installs should define COREBB_COOKIE_SECRET in the
    // private config so login-cookie trust is not tied to DB credentials.
    $parts = [];
    foreach (['MySQL_Host', 'MySQL_User', 'MySQL_Pass', 'MySQL_Database'] as $key) {
        if (isset($GLOBALS[$key])) {
            $parts[] = (string)$GLOBALS[$key];
        }
    }
    $base = implode('|', $parts);
    return $base !== '' ? hash('sha256', $base) : 'corebbboards-local-cookie-secret';
}

/**
 * Usage: Attach an HMAC signature to the persistent-login cookie payload.
 * Referenced by: auth_password_helpers.php when issuing login tokens.
 */
function corebb_security_sign_login_cookie(array $payload): array
{
    unset($payload['sig']);
    $payload['sig'] = hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES), corebb_security_login_cookie_secret());
    return $payload;
}

/**
 * Usage: Verify that a persistent-login cookie payload was issued by CoreBB.
 * Referenced by: auth session helpers and API bootstrap login loading.
 */
function corebb_security_verify_login_cookie(array $payload): bool
{
    if (empty($payload['sig'])) {
        return false;
    }
    $sig = (string)$payload['sig'];
    unset($payload['sig']);
    $expected = hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES), corebb_security_login_cookie_secret());
    return hash_equals($expected, $sig);
}

/**
 * Usage: Return the request IP in a bounded format for logs and rate limits.
 * Referenced by: API guardrails and moderation/contact helpers.
 */
function corebb_security_client_ip(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '') {
        return 'Unknown';
    }
    return substr($ip, 0, 64);
}
?>
