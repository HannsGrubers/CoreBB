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
 |  layout_view_model.php  - Public forum chrome model.  |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/vn_eol_chrome_helpers.php';
require_once __DIR__ . '/performance_helpers.php';
require_once __DIR__ . '/private_board_helpers.php';
require_once __DIR__ . '/../functions.php';

/**
 * Convert an internal route/path into a public URL.
 *
 * Usage: central URL wrapper for public layout links.
 * Referenced by: chrome, menu, breadcrumb, viewer, and footer model builders.
 *
 * @param string $path Legacy script path, pretty path, asset path, or absolute URL.
 * @return string Public URL.
 */
function corebb_layout_url(string $path): string
{
    return corebb_public_url($path);
}

/**
 * Convert an asset path into a browser-safe public asset URL.
 *
 * Usage: expose non-theme assets needed by the public layout.
 * Referenced by: corebb_public_layout_model().
 *
 * @param string $path Asset path.
 * @return string Public asset URL.
 */
function corebb_layout_asset(string $path): string
{
    return corebb_public_asset($path);
}

/**
 * Return the current entry script filename.
 *
 * Usage: choose breadcrumbs and mobile fallback screens for the active page.
 * Controller dispatchers may set $GLOBALS['corebb_layout_script'] when several
 * public routes share one physical PHP file.
 * Referenced by: breadcrumb and mobile fallback builders.
 *
 * @return string Script filename or controller route id such as forum:board.
 */
function corebb_layout_current_script(): string
{
    $override = trim((string)($GLOBALS['corebb_layout_script'] ?? ''));
    if ($override !== '') {
        return str_contains($override, ':') ? $override : basename($override);
    }
    return basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? 'index.php'));
}

/**
 * Build optional debug rows for the current layout style.
 *
 * Usage: show style-selection diagnostics to privileged users when ?debug=yes.
 * Referenced by: corebb_public_layout_model().
 *
 * @param array<string, mixed> $viewer Current user row.
 * @return array<int, array{label: string, value: string}> Debug rows.
 */
function corebb_layout_debug_rows(array $viewer): array
{
    $rows = [];
    $style = 'style_ign.css';
    if (loggedin() && !empty($viewer['userstyle'])) {
        $styleRow = db_one('SELECT file FROM systemstyles WHERE id = ? LIMIT 1', [(int)$viewer['userstyle']]) ?: [];
        $style = (string)($styleRow['file'] ?? $style);
        $rows[] = ['label' => 'System Style Init', 'value' => 'User custom style: ' . $style];
    } else {
        $style = (string)db_value('SELECT setting FROM systemsettings WHERE id = ?', [1], $style);
        $rows[] = ['label' => 'System Style Init', 'value' => 'Default style: ' . $style];
    }
    return $rows;
}

/**
 * Build the classic public chrome link model.
 *
 * Usage: hand public layout templates the site/board labels and URLs.
 * Referenced by: corebb_public_layout_model().
 *
 * @param string $siteUrl Legacy fallback site URL.
 * @param string $siteName Legacy fallback site name.
 * @param string $boardUrl Legacy fallback board URL.
 * @param string $boardName Legacy fallback board name.
 * @return array<string, string> Chrome link model.
 */
function corebb_layout_chrome_links(string $siteUrl = '', string $siteName = '', string $boardUrl = '', string $boardName = ''): array
{
    return corebb_vn_eol_chrome_links($siteUrl, $siteName, $boardUrl, $boardName);
}

/**
 * Normalize a breadcrumb target into a public link.
 *
 * Usage: preserve absolute URLs and convert relative CoreBB paths.
 * Referenced by: corebb_layout_breadcrumbs().
 *
 * @param string $path Breadcrumb path.
 * @return string Public breadcrumb URL.
 */
function corebb_layout_breadcrumb_link_url(string $path): string
{
    if (str_starts_with($path, '/') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $path)) {
        return $path;
    }
    return corebb_layout_url($path);
}

/**
 * Return the breadcrumb label for a blogs page model.
 *
 * Usage: keep blog action labels out of the Twig layout.
 * Referenced by: corebb_layout_breadcrumbs().
 *
 * @param array<string, mixed> $model Blogs page view model.
 * @return string Breadcrumb label.
 */
function corebb_layout_blog_label(array $model): string
{
    return match ((string)($model['page'] ?? 'home')) {
        'viewblog', 'viewentry' => 'Viewing Blog',
        'edit' => 'Edit Blog',
        'delete' => 'Delete Blog',
        default => 'Blogs',
    };
}

/**
 * Return the breadcrumb label for a private-message page model.
 *
 * Usage: keep private-message folder/action labels centralized.
 * Referenced by: corebb_layout_breadcrumbs().
 *
 * @param array<string, mixed> $model Private-message page view model.
 * @return string Breadcrumb label.
 */
function corebb_layout_private_message_label(array $model): string
{
    global $MyData;

    return match ((string)($model['page'] ?? 'folder')) {
        'send' => 'Send Private Message',
        'view' => trim((string)($model['title'] ?? '')) !== ''
            ? trim((string)$model['title'])
            : 'Private Message',
        default => match ((string)($model['folder'] ?? 'unread')) {
            'sent' => (($MyData['username'] ?? 'User') . "'s sent messages"),
            'read' => (($MyData['username'] ?? 'User') . "'s read messages"),
            default => (($MyData['username'] ?? 'User') . "'s unread messages"),
        },
    };
}

/**
 * Build public page breadcrumbs and the document title.
 *
 * Usage: derive layout navigation from the current script, route ids, and page
 * view model without embedding SQL or routing logic in Twig.
 * Referenced by: corebb_public_layout_model().
 *
 * @param array<string, mixed> $vars Page variables passed to the renderer.
 * @param string $boardName Board display name.
 * @return array{items: array<int, array<string, mixed>>, title: string} Breadcrumb model.
 */
function corebb_layout_breadcrumbs(array $vars, string $boardName): array
{
    global $TopicBoardID, $MyData, $QueryCount;

    $script = corebb_layout_current_script();
    $model = is_array($vars['model'] ?? null) ? $vars['model'] : [];
    $crumbs = [];
    $title = $boardName;
    $tail = '';

    $isBoardRoute = $script === 'forum:board';
    $isThreadRoute = $script === 'forum:thread';

    if ($isBoardRoute || $isThreadRoute) {
        $boardId = $isBoardRoute ? (int)($_GET['id'] ?? $model['boardId'] ?? 0) : (int)($TopicBoardID ?? $model['boardId'] ?? 0);
        if ($boardId <= 0 && $isThreadRoute) {
            $topicId = (int)($_GET['id'] ?? $model['topicId'] ?? 0);
            if ($topicId > 0) {
                $row = db_one('SELECT boardid FROM topics WHERE id = ? LIMIT 1', [$topicId]);
                $QueryCount++;
                $boardId = (int)($row['boardid'] ?? 0);
            }
        }
        if ($boardId > 0) {
            $board = corebb_private_board_row($boardId);
            $QueryCount++;
            if ($board && corebb_private_user_can_view_board_row($board)) {
                $category = db_one('SELECT id, name FROM boards WHERE id = ? LIMIT 1', [(int)($board['categoryid'] ?? 0)]);
                $QueryCount++;
                if ($category) {
                    $crumbs[] = ['label' => (string)$category['name'], 'url' => corebb_layout_breadcrumb_link_url('index.php?b=' . (int)$category['id']), 'current' => false];
                }
                if ($isBoardRoute) {
                    $tail = (string)($board['name'] ?? 'Board');
                    $crumbs[] = ['label' => $tail, 'url' => '', 'current' => true];
                } else {
                    $crumbs[] = [
                        'label' => (string)($board['name'] ?? 'Board'),
                        'url' => corebb_board_url((int)$board['id'], 1, (string)($board['name'] ?? 'Board')),
                        'current' => false,
                    ];
                }
            }
        }
        if ($isThreadRoute) {
            $topicTitle = trim((string)($model['topicTitle'] ?? ''));
            if ($topicTitle === '') {
                $topicId = (int)($_GET['id'] ?? $model['topicId'] ?? 0);
                $topic = $topicId > 0 ? db_one('SELECT title, boardid FROM topics WHERE id = ? LIMIT 1', [$topicId]) : false;
                $QueryCount++;
                if ($topic && corebb_private_user_can_view_board_id((int)($topic['boardid'] ?? 0))) {
                    $topicTitle = (string)($topic['title'] ?? '');
                }
            }
            $tail = $topicTitle !== '' ? $topicTitle : 'Missing Topic';
            $crumbs[] = ['label' => $tail, 'url' => '', 'current' => true];
        }
    }

    $scriptLabels = [
        'messages:folder' => corebb_layout_private_message_label($model),
        'messages:send' => corebb_layout_private_message_label($model),
        'index.php' => 'Choose A Board',
        'usercp:index' => 'User Control Panel',
        'usercp:notifications' => 'Notifications',
        'usercp:profile' => 'Edit Your Profile',
        'usercp:avatar' => 'Edit Avatar',
        'usercp:signature' => 'Edit Signature',
        'usercp:options' => 'Edit Forum Options',
        'usercp:appearance' => 'Username Appearance',
        'blogs.php' => corebb_layout_blog_label($model),
        'support:banned' => 'Account Banned',
        'support:denied' => 'Access Denied',
        'support:faq' => 'Board Rules/FAQ',
        'support:contact' => 'Contact Mods',
        'support:report' => 'Report Message',
        'auth:login' => 'Login',
        'auth:recover' => 'Account Recovery',
        'auth:reset' => 'Reset Password',
        'auth:resend' => 'Resend Verification Email',
        'auth:verify' => 'Email Verification',
        'auth:register' => 'Member Registration',
        'content:search' => 'Search',
        'content:profile_content' => trim((string)($model['username_plain'] ?? 'User')) . "'s " . ((string)($model['type'] ?? 'topics') === 'posts' ? 'posts' : 'topics'),
        'moderation:main' => 'Moderation',
        'post:main' => 'Post',
    ];

    if ($script === 'messages:view' && (string)($model['page'] ?? '') === 'view') {
        $tail = trim((string)($model['title'] ?? ''));
        if ($tail === '') {
            $tail = 'Private Message: ' . (int)($_GET['pm'] ?? 0);
        }
        $crumbs[] = ['label' => $tail, 'url' => '', 'current' => true];
    } elseif ($script === 'content:profile') {
        $profileName = trim((string)($model['username_plain'] ?? $model['user']['username'] ?? ''));
        if ($profileName === '') {
            $userId = (int)($_GET['id'] ?? 0);
            $row = $userId > 0 ? db_one('SELECT username FROM users WHERE id = ? LIMIT 1', [$userId]) : false;
            $profileName = $row ? (string)$row['username'] : 'User';
        }
        $tail = $profileName . "'s profile";
        $title = $boardName . ' - ' . $profileName . "'s Profile";
        $crumbs[] = ['label' => $tail, 'url' => '', 'current' => true];
    } elseif (isset($scriptLabels[$script]) && !$isBoardRoute && !$isThreadRoute) {
        $tail = $scriptLabels[$script];
        $crumbs[] = ['label' => $tail, 'url' => '', 'current' => true];
    }

    if ($title === $boardName && $tail !== '') {
        $title = $boardName . ' - ' . $tail;
    }

    return ['items' => $crumbs, 'title' => $title];
}

/**
 * Build a mobile-shell fallback payload for the current page.
 *
 * Usage: give the public layout enough context for mobile navigation when the
 * full mobile redirect did not happen.
 * Referenced by: corebb_public_layout_model().
 *
 * @return array<string, mixed> Mobile fallback payload.
 */
function corebb_layout_mobile_fallback_payload(): array
{
    $script = corebb_layout_current_script();
    $payload = ['screen' => 'index'];
    switch ($script) {
        case 'forum:board':
            $payload = ['screen' => 'board', 'id' => (int)($_GET['id'] ?? 0), 'page' => max(1, (int)($_GET['p'] ?? $_GET['page'] ?? 1))];
            break;
        case 'forum:thread':
            $payload = ['screen' => 'thread', 'id' => (int)($_GET['id'] ?? 0), 'page' => max(1, (int)($_GET['p'] ?? $_GET['page'] ?? 1))];
            break;
        case 'content:profile':
            $payload = ['screen' => 'profile', 'id' => (int)($_GET['id'] ?? 0)];
            break;
        case 'auth:login':
            $payload = ['screen' => 'login'];
            break;
        case 'auth:register':
            $payload = ['screen' => 'register'];
            break;
        case 'messages:folder':
        case 'messages:send':
        case 'messages:view':
            $action = strtolower((string)($_GET['action'] ?? 'folder'));
            if ($action === 'send') {
                $payload = ['screen' => 'pm-send', 'to' => (string)($_GET['usr'] ?? '')];
            } elseif ($action === 'view') {
                $payload = ['screen' => 'pm-message', 'id' => (int)($_GET['pm'] ?? 0), 'folder' => (string)($_GET['method'] ?? '')];
            } else {
                $folder = strtolower((string)($_GET['folder'] ?? 'unread'));
                $payload = ['screen' => 'pm', 'folder' => $folder === 'unread' ? 'inbox' : $folder];
            }
            break;
    }
    return array_filter($payload, static fn($value): bool => $value !== '' && $value !== 0);
}

/**
 * Build the viewer sub-model for public layout chrome.
 *
 * Usage: expose login state, profile link, access level, and optional VIP style
 * CSS to the Twig layout.
 * Referenced by: corebb_public_layout_model().
 *
 * @param array<string, mixed> $viewer Current user row.
 * @return array<string, mixed> Viewer layout model.
 */
function corebb_layout_viewer(array $viewer): array
{
    $loggedIn = loggedin();
    $userId = (int)($viewer['id'] ?? 0);
    $styleCss = '';
    if ($loggedIn && (int)($viewer['accesslevel'] ?? 0) >= 2) {
        $styleCss = corebb_vip_style_css_from_values(corebb_vip_style_values_from_user($viewer));
    }
    return [
        'logged_in' => $loggedIn,
        'id' => $userId,
        'username' => (string)($viewer['username'] ?? 'User'),
        'profile_url' => $userId > 0 ? corebb_layout_url('content.php?action=profile&id=' . $userId) : '',
        'style_css' => $styleCss,
        'access_level' => (int)($viewer['accesslevel'] ?? 0),
    ];
}

/**
 * Build the full public layout model shared by Twig-rendered pages.
 *
 * Usage: assemble chrome links, menu counts, breadcrumbs, alerts, footer stats,
 * assets, and viewer metadata in one display boundary.
 * Referenced by: lib/view.php public renderer.
 *
 * @param array<string, mixed> $vars Page variables passed to the renderer.
 * @param array<string, mixed> $context Optional render context reserved for callers.
 * @return array<string, mixed> Public layout view model.
 */
function corebb_public_layout_model(array $vars = [], array $context = []): array
{
    global $SiteURL, $SiteName, $BoardURL, $BoardName, $ShortPHP, $userlogindata_a;

    require_once __DIR__ . '/notification_helpers.php';
    require_once __DIR__ . '/pm_helpers.php';
    require_once __DIR__ . '/contact_mods_helpers.php';
    require_once __DIR__ . '/vip_style_helpers.php';

    $viewerRow = is_array($userlogindata_a ?? null) ? $userlogindata_a : [];
    $viewer = corebb_layout_viewer($viewerRow);
    $chrome = corebb_layout_chrome_links((string)($SiteURL ?? ''), (string)($SiteName ?? ''), (string)($BoardURL ?? ''), (string)($BoardName ?? ''));
    $SiteURL = $chrome['site_url'];
    $SiteName = $chrome['site_name'];
    $BoardURL = $chrome['board_url'];
    $BoardName = $chrome['board_name'];
    $ShortPHP = $ShortPHP ?? '.php';

    $breadcrumbs = corebb_layout_breadcrumbs($vars, (string)$BoardName);
    $loggedIn = !empty($viewer['logged_in']);
    $adminVisible = false;
    if ($loggedIn) {
        $adminVisible = (int)($viewerRow['accesslevel'] ?? 0) > 1;
        if (!$adminVisible) {
            require_once __DIR__ . '/admin_user_tools_view_model.php';
            $adminVisible = corebb_admin_can_access_admin($viewerRow);
        }
    }

    $notificationCount = $loggedIn ? corebb_notifications_uncleared_count((int)$viewer['id'], false) : 0;
    $pmUnread = $loggedIn ? corebb_pm_count((int)$viewer['id'], 'unread') : 0;
    $contactModsUrl = corebb_layout_url('support.php?action=contact');
    $contactModsLabel = 'Contact Mods';
    $flash = ['messages' => [], 'errors' => []];
    if ($loggedIn) {
        if ((int)($viewerRow['accesslevel'] ?? 0) >= 3) {
            $contactModsUrl = '/admin/?act=contact_mods';
            $count = corebb_contact_mods_new_count(false);
            if ($count > 0) {
                $contactModsLabel .= ' (' . $count . ' New)';
            }
        } else {
            $contactModsUrl = corebb_contact_mods_url_with_return($contactModsUrl, corebb_contact_mods_current_return_url());
        }
        $flash = corebb_contact_mods_flash_pull();
    }

    $alerts = [];
    $msg = trim(str_replace('+', ' ', strip_tags((string)($_GET['msg'] ?? ''))));
    if ($msg !== '') {
        $alerts[] = ['label' => 'System Message:', 'message' => $msg, 'class' => 'MainMenuRowAlt'];
    }
    foreach (($flash['messages'] ?? []) as $message) {
        $alerts[] = ['label' => 'Contact Mods Result:', 'message' => (string)$message, 'class' => 'MainMenuRowAlt'];
    }
    foreach (($flash['errors'] ?? []) as $message) {
        $alerts[] = ['label' => 'Contact Mods Error:', 'message' => (string)$message, 'class' => 'MainMenuRowAlt'];
    }
    foreach (db_all('SELECT id, message, poster FROM globalmessages ORDER BY id ASC') as $globalMessage) {
        $text = trim((string)($globalMessage['message'] ?? ''));
        if ($text !== '') {
            $alerts[] = ['label' => 'Global Message:', 'message' => $text, 'poster' => (string)($globalMessage['poster'] ?? ''), 'class' => 'MainMenuRowAlt'];
        }
    }

    $stats = '';
    if (LoadBoardSetting('showbasicstats')) {
        $today = date('m/d/y');
        $stats = number_format((int)corebb_perf_total_forums()) . ' Boards | '
            . number_format((int)corebb_perf_total_posts()) . ' Messages ('
            . number_format((int)corebb_perf_today_posts($today)) . ' Today)';
    }

    return [
        'head' => [
            'title' => (string)$breadcrumbs['title'],
            'stylesheets' => [corebb_theme_url('style_vn_eol.css'), corebb_theme_url('style_theme.css')],
            'scripts' => [
                corebb_theme_url('scripts/minmax.js'),
                corebb_theme_url('scripts/repopulate2.js'),
                corebb_theme_url('scripts/vendor/prism/prism-corebb.min.js'),
                corebb_theme_url('scripts/corebb_prism_codeblocks.js'),
            ],
            'mobile_fallback' => corebb_layout_mobile_fallback_payload(),
            'body_class' => 'wb-vn-eol',
            'staging' => defined('COREBB_ENV') && (string)COREBB_ENV === 'staging',
        ],
        'chrome' => [
            'site_url' => $SiteURL,
            'site_name' => $SiteName,
            'board_url' => $BoardURL,
            'board_name' => $BoardName,
            'assets' => [
                'header_bg' => corebb_theme_asset('HeaderBackgroundVN.png'),
                'header_left' => corebb_theme_asset('HeaderLeftVN.png'),
                'header_site' => corebb_theme_asset('HeaderSiteLogoVN.png'),
                'header_right' => corebb_theme_asset('HeaderRightVN.png'),
                'spacer' => corebb_theme_asset('spacer.gif'),
                'pixy' => corebb_theme_asset('pixy.gif'),
                'notification' => corebb_layout_asset('images/notification.gif'),
            ],
        ],
        'viewer' => $viewer,
        'menu' => [
            'pm_unread' => $pmUnread,
            'notification_count' => $notificationCount,
            'admin_visible' => $adminVisible,
            'login_url' => corebb_layout_url('auth.php?action=login'),
            'register_url' => corebb_layout_url('auth.php?action=register'),
            'logout_url' => corebb_layout_url('auth.php?action=logout'),
            'pm_url' => corebb_layout_url('messages.php?action=folder&folder=unread'),
            'search_url' => corebb_layout_url('content.php?action=search'),
            'usercp_url' => corebb_layout_url('usercp.php?action=index'),
            'admin_url' => corebb_layout_url('admin.php'),
            'blogs_url' => corebb_layout_url('blogs.php'),
            'rules_url' => corebb_layout_url('support.php?action=faq'),
            'contact_mods_url' => $contactModsUrl,
            'contact_mods_label' => $contactModsLabel,
        ],
        'breadcrumbs' => $breadcrumbs['items'],
        'alerts' => $alerts,
        'footer' => [
            'stats' => $stats,
            'version_label' => 'CoreBB Initial Release v1.0.0',
            'copyright_start' => 2005,
            'copyright_year' => date('Y'),
            'copyright_name' => 'CoreBB',
            'rules_url' => corebb_layout_url('support.php?action=faq') . '#tos',
            'debug_allowed' => (int)($viewerRow['accesslevel'] ?? 0) >= 5 && (string)($_GET['debug'] ?? '') === 'yes',
            'debug_rows' => corebb_layout_debug_rows($viewerRow),
        ],
    ];
}
