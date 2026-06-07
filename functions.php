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
 |  functions.php  - Shared CoreBB function helpers.     |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/lib/vip_style_helpers.php';
require_once __DIR__ . '/lib/auth_password_helpers.php';
require_once __DIR__ . '/lib/corebb_url_helpers.php';

require_once(__DIR__ . '/lib/performance_helpers.php');
require_once(__DIR__ . '/lib/private_board_helpers.php');


/* Pretty URL implementation for the canonical public URL helpers.
 * Pretty URLs make the browser think it is inside /boardname/b1/, so old
 * relative assets like images/foo.gif and style.css break. The public wrappers
 * live in lib/corebb_url_helpers.php; this implementation teaches them the
 * forum's legacy script-to-route mappings after functions.php is loaded.
 */

/**
 * Usage: Translate legacy script/query links into the public pretty URL shape.
 * Referenced by: corebb_public_pretty_url(), templates, and legacy HTML helpers.
 *
 * @param string $path Legacy script path, pretty path, asset path, or absolute URL.
 * @return string Safe public URL, preserving external URLs and fragments.
 */
function corebb_public_pretty_url_impl(string $path): string {
    $path = trim($path);
    if ($path === '') {
        return corebb_public_base_path();
    }
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?|javascript:|mailto:|tel:)~i', $path)) {
        return $path;
    }

    $decoded = html_entity_decode($path, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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

    $remainingQuery = static function (array $params, array $remove = []): string {
        foreach ($remove as $key) {
            unset($params[$key]);
        }
        return $params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '';
    };

    $url = null;
    switch ($script) {
        case 'index.php':
            $url = '/';
            break;
        case 'auth.php':
            $action = strtolower((string)($params['action'] ?? 'login'));
            if ($action === 'login_submit') {
                $url = '/login/submit/';
            } elseif ($action === 'register') {
                $url = '/register/';
            } elseif ($action === 'recover') {
                $url = '/recover-account/';
            } elseif ($action === 'reset') {
                $url = '/reset-password/';
            } elseif ($action === 'verify') {
                $url = '/verify-email/';
            } elseif ($action === 'resend') {
                $url = '/resend-verification/';
            } elseif ($action === 'logout') {
                $url = '/logoff/';
            } else {
                $url = '/login/';
            }
            $query = ltrim($remainingQuery($params, ['action']), '?');
            break;
        case 'support.php':
            $action = strtolower((string)($params['action'] ?? 'denied'));
            if ($action === 'banned') {
                $url = '/banned/';
                $query = ltrim($remainingQuery($params, ['action']), '?');
            } elseif ($action === 'faq') {
                $url = '/board-rules-faq/';
                $query = ltrim($remainingQuery($params, ['action']), '?');
            } elseif ($action === 'contact') {
                $url = '/contact-mods/';
                $query = ltrim($remainingQuery($params, ['action']), '?');
            } elseif ($action === 'report') {
                $postId = isset($params['post']) ? (int)$params['post'] : (isset($params['id']) ? (int)$params['id'] : 0);
                $url = $postId > 0 ? '/report-message/' . $postId . '/' : '/report-message/';
                $query = ltrim($remainingQuery($params, ['action', 'post', 'id']), '?');
            } elseif ($action === 'error') {
                $url = '/err/';
                $query = ltrim($remainingQuery($params, ['action']), '?');
            } else {
                $url = '/denied/';
                $query = ltrim($remainingQuery($params, ['action']), '?');
            }
            break;
        case 'usercp.php':
            $action = strtolower((string)($params['action'] ?? 'index'));
            if ($action === 'notifications') {
                $url = '/notifications/';
            } elseif ($action === 'profile') {
                $url = '/user-cp/profile/';
            } elseif ($action === 'avatar') {
                $url = '/user-cp/avatar/';
            } elseif ($action === 'signature') {
                $url = '/user-cp/signature/';
            } elseif ($action === 'options') {
                $url = '/user-cp/options/';
            } elseif ($action === 'appearance') {
                $url = '/user-cp/appearance/';
            } else {
                $url = '/user-cp/';
            }
            $query = ltrim($remainingQuery($params, ['action']), '?');
            break;
        case 'admin.php':
            $url = '/admin/';
            break;
        case 'blogs.php':
            $action = strtolower((string)($params['action'] ?? 'home'));
            $id = isset($params['id']) ? (int)$params['id'] : 0;
            if ($action === 'my') {
                $url = '/blogs/my/';
                $query = ltrim($remainingQuery($params, ['action']), '?');
            } elseif ($action === 'modify') {
                $url = '/blogs/modify/';
                $query = ltrim($remainingQuery($params, ['action']), '?');
            } elseif ($action === 'viewblog' && $id > 0) {
                $url = '/blogs/user/' . $id . '/';
                $query = ltrim($remainingQuery($params, ['action', 'id']), '?');
            } elseif ($action === 'viewentry' && $id > 0) {
                $url = '/blogs/entry/' . $id . '/';
                $query = ltrim($remainingQuery($params, ['action', 'id']), '?');
            } elseif ($action === 'edit') {
                $url = $id > 0 ? '/blogs/entry/' . $id . '/edit/' : '/blogs/edit/';
                $query = ltrim($remainingQuery($params, ['action', 'id']), '?');
            } elseif ($action === 'delete') {
                $url = $id > 0 ? '/blogs/entry/' . $id . '/delete/' : '/blogs/delete/';
                $query = ltrim($remainingQuery($params, ['action', 'id']), '?');
            } else {
                $url = '/blogs/';
                $query = ltrim($remainingQuery($params, ['action']), '?');
            }
            break;
        case 'content.php':
            $action = strtolower((string)($params['action'] ?? 'search'));
            if ($action === 'profile') {
                $id = isset($params['id']) ? (int)$params['id'] : 0;
                $url = $id > 0 ? '/profile/' . $id . '/' : '/profile/';
                $query = ltrim($remainingQuery($params, ['action', 'id']), '?');
            } elseif ($action === 'profile_content') {
                $id = isset($params['id']) ? (int)$params['id'] : 0;
                $type = isset($params['type']) && (string)$params['type'] === 'posts' ? 'posts' : 'topics';
                $page = isset($params['p']) ? max(1, (int)$params['p']) : 1;
                $url = $id > 0 ? '/profile/' . $id . '/' . $type . '/' . ($page > 1 ? 'p' . $page . '/' : '') : '/profile/';
                $query = ltrim($remainingQuery($params, ['action', 'id', 'type', 'p']), '?');
            } elseif ($action === 'post_id') {
                $id = isset($params['id']) ? (int)$params['id'] : 0;
                $url = $id > 0 ? '/post-id/' . $id . '/' : '/post-id/';
                $query = ltrim($remainingQuery($params, ['action', 'id']), '?');
            } else {
                $url = '/search/';
                $query = ltrim($remainingQuery($params, ['action']), '?');
            }
            break;
        case 'forum.php':
            $action = strtolower((string)($params['action'] ?? 'board'));
            $id = isset($params['id']) ? (int)$params['id'] : 0;
            $page = isset($params['p']) ? max(1, (int)$params['p']) : max(1, (int)($params['page'] ?? 1));
            $boardId = isset($params['brd']) ? (int)$params['brd'] : (isset($params['boardid']) ? (int)$params['boardid'] : 0);
            if ($action === 'thread' && $id > 0) {
                if ($boardId <= 0) {
                    $boardId = (int)db_value("SELECT boardid FROM topics WHERE id = ? LIMIT 1", [$id], 0);
                }
                if ($boardId > 0 && (!function_exists('corebb_private_user_can_view_board_id') || corebb_private_user_can_view_board_id($boardId))) {
                    $boardName = (string)db_value("SELECT name FROM forums WHERE id = ? LIMIT 1", [$boardId], '');
                    $url = function_exists('corebb_thread_url')
                        ? corebb_thread_url($id, $boardId, $page, $boardName)
                        : '/topic/' . $id . ($page > 1 ? '/p' . $page : '') . '/';
                    $query = ltrim($remainingQuery($params, ['action', 'id', 'p', 'page', 'brd', 'boardid']), '?');
                }
            } elseif ($action === 'favorite') {
                $boardId = $boardId > 0 ? $boardId : $id;
                if ($boardId > 0) {
                    $url = '/board/' . $boardId . '/favorite/';
                    $query = ltrim($remainingQuery($params, ['action', 'brd', 'boardid', 'id']), '?');
                }
            } else {
                $boardId = $id > 0 ? $id : $boardId;
                if ($boardId > 0 && (!function_exists('corebb_private_user_can_view_board_id') || corebb_private_user_can_view_board_id($boardId))) {
                    $boardName = (string)db_value("SELECT name FROM forums WHERE id = ? LIMIT 1", [$boardId], '');
                    $url = function_exists('corebb_board_url')
                        ? corebb_board_url($boardId, $page, $boardName)
                        : '/board/' . $boardId . ($page > 1 ? '/p' . $page : '') . '/';
                    $query = ltrim($remainingQuery($params, ['action', 'id', 'brd', 'boardid', 'p', 'page']), '?');
                }
            }
            break;
        case 'messages.php':
            $action = strtolower((string)($params['action'] ?? 'folder'));
            $folder = strtolower((string)($params['folder'] ?? 'unread'));
            if ($action === 'send') {
                $recipient = isset($params['usr']) ? trim((string)$params['usr']) : '';
                $url = $recipient !== '' ? '/private-messages/send/' . rawurlencode($recipient) . '/' : '/private-messages/send/';
                $query = ltrim($remainingQuery($params, ['action', 'usr']), '?');
            } elseif ($action === 'view') {
                $pmId = isset($params['pm']) ? (int)$params['pm'] : 0;
                $method = isset($params['method']) ? trim((string)$params['method']) : 'read';
                $safeMethod = preg_replace('~[^a-zA-Z0-9_-]~', '', $method) ?: 'read';
                if ($pmId > 0) {
                    $url = '/private-messages/message/' . $pmId . '/' . $safeMethod . '/';
                    $query = ltrim($remainingQuery($params, ['action', 'pm', 'method']), '?');
                }
            } elseif ($folder === 'read') {
                $url = '/private-messages/read/';
                $query = ltrim($remainingQuery($params, ['action', 'folder']), '?');
            } elseif ($folder === 'sent') {
                $url = '/private-messages/sent/';
                $query = ltrim($remainingQuery($params, ['action', 'folder']), '?');
            } else {
                $url = '/private-messages/';
                $query = ltrim($remainingQuery($params, ['action', 'folder']), '?');
            }
            break;
        case 'poll.php':
            $url = '/poll/vote/';
            $query = ltrim($remainingQuery($params, []), '?');
            break;
        case 'post.php':
            $act = isset($params['act']) ? strtolower(trim((string)$params['act'])) : '';
            $boardId = isset($params['boardid']) ? (int)$params['boardid'] : 0;
            $topicId = isset($params['id']) ? (int)$params['id'] : 0;
            $editId = isset($params['edit']) ? (int)$params['edit'] : 0;
            $quoteId = isset($params['quote']) ? (int)$params['quote'] : 0;
            $brd = isset($params['brd']) ? (int)$params['brd'] : 0;
            if ($act === 'image_upload') {
                $url = '/post/image-upload/';
                $query = ltrim($remainingQuery($params, ['act']), '?');
            } elseif ($act === 'blog') {
                $url = '/blogs/new/';
                $query = ltrim($remainingQuery($params, ['act']), '?');
            } elseif ($editId > 0) {
                $url = '/post/edit/' . $editId . '/';
                $query = ltrim($remainingQuery($params, ['edit']), '?');
            } elseif ($act === 'reply' && $topicId > 0 && $brd > 0) {
                $url = '/post/reply/' . $topicId . '/b' . $brd . '/' . ($quoteId > 0 ? 'q' . $quoteId . '/' : '');
                $query = ltrim($remainingQuery($params, ['id', 'brd', 'act', 'quote']), '?');
            } elseif ($act === 'new' && $boardId > 0) {
                $url = '/post/new/b' . $boardId . '/' . (!empty($params['poll']) ? 'poll/' : '');
                $query = ltrim($remainingQuery($params, ['boardid', 'act', 'poll']), '?');
            } else {
                $url = '/post/submit/';
            }
            break;
        case 'moderation.php':
            $url = '/moderator/';
            $query = ltrim($remainingQuery($params, []), '?');
            break;
    }

    if ($url !== null) {
        if ($query !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . $query;
        }
        return (function_exists('corebb_public_join_base_path') ? corebb_public_join_base_path($url) : $url) . $fragment;
    }

    if ($path[0] === '/') {
        return function_exists('corebb_public_join_base_path') ? corebb_public_join_base_path($path) : $path;
    }
    return corebb_public_base_path() . ltrim($path, '/');
}




/**
 * Usage: Return a root-relative URL for local image assets only.
 * Referenced by: user icon rendering and BBCode image fallbacks.
 *
 * Used for DB-backed avatar/icon paths so an old or poisoned icons.filepath
 * value cannot become javascript:, data:, protocol-relative, traversal, or a
 * non-image file reference in an <img> tag.
 *
 * @param string $path Stored image path to validate.
 * @param array<int, string> $allowedTopDirs Allowed first path segments.
 * @return string Root-relative image URL, or an empty string when rejected.
 */
function corebb_safe_local_image_asset(string $path, array $allowedTopDirs = ['images']): string {
    $path = html_entity_decode($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $path = trim(str_replace('\\', '/', $path));
    $path = preg_replace('/[\x00-\x1F\x7F]+/', '', $path) ?? '';
    if ($path === '' || strlen($path) > 512) {
        return '';
    }
    if (str_starts_with($path, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path)) {
        return '';
    }
    if (str_contains($path, '?') || str_contains($path, '#')) {
        return '';
    }

    $local = ltrim(preg_replace('~^\./+~', '', $path) ?? $path, '/');
    if ($local === '' || str_contains($local, '..')) {
        return '';
    }
    if (!preg_match('~^[A-Za-z0-9._/-]+$~', $local)) {
        return '';
    }

    $top = explode('/', $local, 2)[0] ?? '';
    $allowed = array_map(static fn($dir) => trim((string)$dir, '/'), $allowedTopDirs);
    if (!in_array($top, $allowed, true)) {
        return '';
    }

    $ext = strtolower((string)pathinfo($local, PATHINFO_EXTENSION));
    if (!in_array($ext, ['gif', 'jpg', 'jpeg', 'png', 'webp'], true)) {
        return '';
    }

    return corebb_public_asset($local);
}


/* PHP 8 migration helpers for user profile/forum option columns. */
/**
 * Usage: Check whether an optional user-profile column already exists.
 * Referenced by: corebb_user_add_column() and profile migration guards.
 *
 * @param string $column Column name to check in the users table.
 * @return bool True when the column is present.
 */
function corebb_user_column_exists(string $column): bool {
    return db_exists("SHOW COLUMNS FROM `users` LIKE ?", [$column]);
}

/**
 * Usage: Add a missing optional users column during lightweight migrations.
 * Referenced by: corebb_user_ensure_profile_columns().
 *
 * @param string $column Safe users-table column name.
 * @param string $definition SQL column definition to append to ALTER TABLE.
 * @return void
 */
function corebb_user_add_column(string $column, string $definition): void {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return;
    }
    if (!corebb_user_column_exists($column)) {
        db_run("ALTER TABLE `users` ADD COLUMN `" . str_replace('`', '``', $column) . "` " . $definition);
    }
}

/**
 * Usage: Ensure profile paging and signature columns exist before reads/writes.
 * Referenced by: signature helpers and profile/user-control workflows.
 *
 * @return void
 */
function corebb_user_ensure_profile_columns(): void {
    corebb_user_add_column('ThreadPages', 'INT NOT NULL DEFAULT 25');
    corebb_user_add_column('BoardPages', 'INT NOT NULL DEFAULT 25');
    corebb_user_add_column('signature', 'TEXT NULL');
    for ($i = 1; $i <= 5; $i++) {
        corebb_user_add_column('sig' . $i, "VARCHAR(255) NOT NULL DEFAULT ''");
    }
}

/**
 * Usage: Build the display signature from either legacy sig1-sig5 fields or signature,
 * which is just the new line separated full signature text.
 * Referenced by: CreateUserSignature() and profile view models.
 *
 * @param array<string, mixed> $row User row containing legacy or current signature fields.
 * @return string Plain signature text ready for the markup pipeline.
 */
function corebb_user_signature_from_row(array $row): string {
    $lines = [];
    for ($i = 1; $i <= 5; $i++) {
        $value = trim((string)($row['sig' . $i] ?? ''));
        if ($value !== '' && $value !== '$') {
            $lines[] = $value;
        }
    }
    if ($lines) {
        return implode("\n", $lines);
    }

    $signature = trim((string)($row['signature'] ?? ''));
    return $signature;
}

/**
 * Usage: Convert a post count into the CoreBB star rank number.
 * Referenced by: CreateUserStarFromPostCount().
 *
 * @param int $postcount Total posts credited to the user.
 * @return int Star level from 0 through 10.
 */
function corebb_star_level_for_posts(int $postcount): int{
    $postcount = max(0, $postcount);
    if ($postcount >= 50000) { return 10; }
    if ($postcount >= 40000) { return 9; }
    if ($postcount >= 30000) { return 8; }
    if ($postcount >= 20000) { return 7; }
    if ($postcount >= 10000) { return 6; }
    if ($postcount >= 5000) { return 5; }
    if ($postcount >= 1000) { return 4; }
    if ($postcount >= 500) { return 3; }
    if ($postcount >= 250) { return 2; }
    if ($postcount >= 50) { return 1; }
    return 0;
}

/**
 * Usage: Resolve a star level to its image filename.
 * Referenced by: CreateUserStarFromPostCount().
 *
 * @param int $level Star level from corebb_star_level_for_posts().
 * @return string Star image filename, or an empty string for no star.
 */
function corebb_star_filename_for_level(int $level): string{
    return match ($level) {
        1 => 'star.gif',
        2 => 'star2.gif',
        3 => 'star3.gif',
        4 => 'star4.gif',
        5 => 'star5.gif',
        6 => 'star6.gif',
        7 => 'star7.gif',
        8 => 'star8.gif',
        9 => 'star9.gif',
        10 => 'star10.gif',
        default => '',
    };
}

/**
 * Usage: Render the star image fragment for a known post count.
 * Referenced by: CreateUserStar() and legacy profile/topic helpers.
 *
 * @param mixed $postcount Numeric post count from a user row or aggregate query.
 * @return string HTML image fragment, or an empty string when no star is earned.
 */
function CreateUserStarFromPostCount($postcount): string{
    $level = corebb_star_level_for_posts((int)$postcount);
    if ($level <= 0) {
        return '';
    }

    $filename = corebb_star_filename_for_level($level);
    if ($filename === '') {
        return '';
    }

    $src = function_exists('corebb_public_asset') ? corebb_public_asset('images/stars/' . $filename) : '/images/stars/' . $filename;
    $label = $level === 1 ? '1 star' : $level . ' stars';
    return "&nbsp;<img src='" . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . "' style='vertical-align:middle;' alt='" . $label . "' title='" . $label . "'>";
}

/**
 * Usage: Render the star image fragment for a user id or username.
 * Referenced by: profile pages, postbit helpers, and older templates.
 *
 * @param mixed $userid User id by default, or username when non-numeric/forced.
 * @param bool $force_str Treat $userid as a username even when it is numeric text.
 * @return string HTML image fragment, or an empty string when the user is missing.
 */
function CreateUserStar($userid, $force_str = false) {
    if (!is_numeric($userid) || $force_str) {
        $user = (string)$userid;
        $row = db_one("SELECT id, posts FROM `users` WHERE `username` = ? LIMIT 1", [$user]);
    } else {
        $user = (int)$userid;
        $row = db_one("SELECT id, posts FROM `users` WHERE `id` = ? LIMIT 1", [$user]);
    }

    if (!$row) {
        return '';
    }

    return CreateUserStarFromPostCount((int)($row['posts'] ?? 0));
}

/**
 * Usage: Format a username with optional VIP styling and profile link.
 * Referenced by: board rows, profile pages, blogs, PMs, and admin log viewers.
 *
 * @param mixed $userid User id by default, or username when non-numeric/forced.
 * @param bool $style When false, return the raw username without a link.
 * @param bool $force_str Treat $userid as a username even when it is numeric text.
 * @return string Username text or linked/styled HTML fragment.
 */
function CreateUsername($userid, $style = true, $force_str = false) {
    if (!is_numeric($userid) || $force_str) {
        $user = (string)$userid;
        $isnumeric = false;
    } else {
        $user = (int)$userid;
        $isnumeric = true;
    }

    if (!$isnumeric) {
        $userdata = db_one("SELECT * FROM `users` WHERE `username` = ? LIMIT 1", [$user]);
    } else {
        $userdata = db_one("SELECT * FROM `users` WHERE `id` = ? LIMIT 1", [$user]);
    }

    if (!$userdata) {
        return '';
    }

    $username = htmlspecialchars((string)$userdata['username'], ENT_QUOTES, 'UTF-8');
    $profileId = (int)$userdata['id'];

    if (!$style) {
        return (string)$userdata['username'];
    }

    $styleAttr = corebb_vip_style_attr_for_user($userdata);
    $profileUrl = htmlspecialchars(corebb_public_url('content.php?action=profile&id=' . $profileId), ENT_QUOTES, 'UTF-8');

    if ($styleAttr !== '') {
        return "<a class='AuthorLink' $styleAttr href='$profileUrl'>$username</a>";
    }

    return "<a class='AuthorLink' href='$profileUrl'>$username</a>";
}

/**
 * Usage: Render a user's approved avatar/icon fragment.
 * Referenced by: profile pages, blog listings, and legacy postbit helpers.
 *
 * @param mixed $userid User id to load.
 * @param bool $blog Use the compact blog icon dimensions and fallback.
 * @return string HTML icon wrapper, or an empty string when no icon should show.
 */
function CreateUserIcon($userid, $blog = false) {
    $userid = (int)$userid;
    if ($userid <= 0) {
        return '';
    }

    $userdata = db_one('SELECT id, iconid FROM `users` WHERE `id` = ? LIMIT 1', [$userid]);
    if (!$userdata) {
        return '';
    }

    $user_icon = (int)($userdata['iconid'] ?? 0);
    $img = '';
    if ($blog) {
        if (!$user_icon) {
            $img = "<img src='/images/noiconblog.gif' height='40' width='40' alt='No icon'>";
        } else {
            $icon = db_one('SELECT * FROM `icons` WHERE `id` = ? LIMIT 1', [$user_icon]);
            $approved = !$icon || !array_key_exists('approved', $icon) ? 1 : (int)$icon['approved'];
            $path = ($icon && $approved) ? htmlspecialchars(corebb_safe_local_image_asset((string)($icon['filepath'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
            $alt = $icon ? htmlspecialchars((string)($icon['filename'] ?? 'User icon'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'User icon';
            if ($path !== '') {
                $img = "<img src='$path' height='40' width='40' alt='$alt'>";
            }
        }
    } else {
        if (!$user_icon) {
            $sessionUserId = (int)($_SESSION['userid'] ?? 0);
            if ($userid === $sessionUserId) {
                $img = "<br/>&nbsp;&nbsp;&nbsp;[<a href=\"" . corebb_public_url('usercp.php?action=avatar') . "\">your icon here</a>]";
            }
        } else {
            $icon = db_one('SELECT * FROM `icons` WHERE `id` = ? LIMIT 1', [$user_icon]);
            $approved = !$icon || !array_key_exists('approved', $icon) ? 1 : (int)$icon['approved'];
            $path = ($icon && $approved) ? htmlspecialchars(corebb_safe_local_image_asset((string)($icon['filepath'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
            $alt = $icon ? htmlspecialchars((string)($icon['filename'] ?? 'User icon'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'User icon';
            if ($path !== '') {
                $img = "<img src='$path' alt='$alt'>";
            }
        }
    }

    if ($img === '') {
        return '';
    }
    return "<span class='wb-user-icon-frame'>" . $img . "</span>\n";
}

/**
 * Usage: Format a user's stored or recalculated post count.
 * Referenced by: profile, member, and postbit display helpers.
 *
 * @param mixed $userid User id to count posts for.
 * @param bool $real When true, count rows in posts instead of reading users.posts.
 * @return string Number-formatted post count.
 */
function CreateUserPostcount($userid, $real = false) {
    $userid = (int)$userid;

    if ($real) {
        $query_num = (int)db_value("SELECT COUNT(*) FROM `posts` WHERE `posterid` = ?", [$userid], 0);
        return number_format($query_num);
    }

    $query_num = (int)db_value("SELECT posts FROM `users` WHERE `id` = ? LIMIT 1", [$userid], 0);
    return number_format($query_num);
}

/**
 * Usage: Convert a Unix timestamp to the stored VN/CoreBB datetime string.
 * Referenced by: import and legacy timestamp conversion code.
 *
 * @param mixed $timestamp Unix timestamp accepted by date().
 * @return string Datetime string in YYYY-M-D HH:MM:SS form.
 */
function convert_to_timestamp_raw($timestamp)
{
    // Set to the hour difference from PST when importing timestamps from another server timezone.
    $timeZone = "0";

    $datearr[5] = date("s", $timestamp - ($timeZone * 3600));
    $datearr[4] = date("i", $timestamp - ($timeZone * 3600));
    $datearr[3] = date("H", $timestamp - ($timeZone * 3600));
    $datearr[2] = date("y", $timestamp - ($timeZone * 3600));
    $datearr[1] = date("d", $timestamp - ($timeZone * 3600));
    $datearr[0] = date("n", $timestamp - ($timeZone * 3600));

    return "20" . $datearr[2] . "-" . $datearr[0] . "-" . $datearr[1] . " " . $datearr[3] . ":" . $datearr[4] . ":" . $datearr[5];
}

/**
 * Usage: Convert a VN-style display date back to a database datetime string.
 * Referenced by: archive importers and legacy edit paths.
 *
 * @param mixed $vndate VN-style date such as "6/5 1:25pm" or "1:25pm".
 * @return string Datetime string in YYYY-MM-DD HH:MM:00 form.
 */
function convert_to_timestamp($vndate)
{
    // Set to the hour difference from PST when importing timestamps from another server timezone.
    $timeZone = "0";

    $datearr = preg_split("/[\s\/:\s]+/", $vndate);

    if (count($datearr) == 2) {
        $datearr[4] = $datearr[1];
        $datearr[3] = $datearr[0];
        $datearr[2] = date("y", time() - ($timeZone * 3600));
        $datearr[1] = date("d", time() - ($timeZone * 3600));
        $datearr[0] = date("n", time() - ($timeZone * 3600));
    } elseif (count($datearr) == 4) {
        $datearr[4] = $datearr[3];
        $datearr[3] = $datearr[2];
        $datearr[2] = date("y", time() - ($timeZone * 3600));
        $datearr[1] = $datearr[1];
        $datearr[0] = $datearr[0];
    }

    for ($d = 0; $d < count($datearr); $d++) {
        if (substr($datearr[$d], strlen($datearr[$d]) - 1, 1) == "m" && strlen($datearr[$d]) != 2) {
            $datearr[$d + 1] = substr($datearr[$d], strlen($datearr[$d]) - 2, 2);
            $datearr[$d] = substr($datearr[$d], 0, strlen($datearr[$d]) - 2);
        }
    }

    if ($datearr[5] == "pm") {
        if ($datearr[3] != 12) {
            $datearr[3] = $datearr[3] + 12;
        }
    } else {
        if ($datearr[3] == 12) {
            $datearr[3] = 0;
        }
    }

    if ($datearr[0] < 10) {
        $datearr[0] = "0" . $datearr[0];
    }
    if ($datearr[1] < 10) {
        $datearr[1] = "0" . $datearr[1];
    }
    if ($datearr[3] < 10) {
        $datearr[3] = "0" . $datearr[3];
    }

    return "20" . $datearr[2] . "-" . $datearr[0] . "-" . $datearr[1] . " " . $datearr[3] . ":" . $datearr[4] . ":00";
}

/**
 * Usage: Convert a database datetime into the short VN-style display date.
 * Referenced by: legacy topic, post, PM, and profile display code.
 *
 * @param mixed $timestamp Datetime string accepted by strtotime().
 * @return string Short date/time label for the current year/day context.
 */
function convert_to_vndate($timestamp)
{
    $timestamp = strtotime($timestamp);
    $timeZone = "0";

    if (date("y", time() - ($timeZone * 3600)) != date("y", $timestamp)) {
        return date("n/j/y g:ia", $timestamp);
    }
    if (
        date("y", time() - ($timeZone * 3600)) == date("y", $timestamp)
        && date("n", time() - ($timeZone * 3600)) == date("n", $timestamp)
        && date("d", time() - ($timeZone * 3600)) == date("d", $timestamp)
    ) {
        return date("g:ia", $timestamp);
    }
    return date("n/j g:ia", $timestamp);
}

/**
 * Usage: Strip non-id characters from an admin-entered user id search value.
 * Referenced by: legacy admin lookup forms.
 *
 * @param mixed $userid Raw user id text from a request or form field.
 * @return string Sanitized id string after legacy character stripping.
 */
function fixuserid($userid) {
    $userid = strtolower($userid);
    $disallowedchars = array(
        "1" => "a", "2" => "b", "3" => "c", "4" => "d", "5" => "e", "6" => "f", "7" => "g", "8" => "h",
        "9" => "i", "10" => "j", "11" => "k", "12" => "l", "13" => "m", "14" => "n", "15" => "o", "16" => "p",
        "17" => "q", "18" => "r", "19" => "s", "20" => "t", "21" => "u", "22" => "v", "23" => "w", "24" => "x",
        "25" => "y", "26" => "z", "27" => "!", "28" => "@", "29" => "#", "30" => "$", "31" => "%", "32" => "^",
        "33" => "&", "34" => "*", "35" => "(", "36" => ")", "37" => "_", "38" => "-", "39" => "+", "40" => "=",
        "41" => "?", "42" => "/", "43" => ">", "43" => ".", "44" => "<", "45" => ",", "46" => "'", "47" => ":",
        "48" => ";", "49" => "}", "50" => "]", "51" => "{", "52" => "[", "53" => "|"
    );
    $charcount = count($disallowedchars);

    for ($i = 1; $i <= $charcount; $i++) {
        $userid = str_replace($disallowedchars[$i], "", $userid);
    }

    return $userid;
}

/*
/*+--------------------------------------------------------------------------------+
  |  MarkUp function                                                               |
  |-------------------------                                                       |
  | @param string $text string to apply markup engine to                           |
  | @param string $permissions determines which markup code to process             |
  |--------------------------------------------------------------------------------|
  |Key                                                                             |
  |  B -> Bold                                                                     |
  |  I -> Italics                                                                  |
  |  Q -> Quote                                                                    |
  |  B -> Bold                                                                     |
  |  I -> Italics                                                                  |
  |  Q -> Quote                                                                    |
  |  U -> Underline                                                                |
  |  O -> Overline                                                                 |
  |  BQ -> Block Quote                                                             |
  |  S -> Spaces                                                                   |
  |  HR -> Horizontal Rule                                                         |
  |  UL -> UL                                                                      |
  |  OL -> OL                                                                      |
  |  LI -> Li                                                                      |
  |  ST -> Strike Through                                                          |
  |  SP -> Spoiler Text                                                            |
  |  BL -> Blinking Text                                                           |
  |  CT -> Center Text                                                             |
  |  F -> Faces                                                                    |
  |  LL -> Lazy Links                                                              |
  |  FC -> Font Color                                                              |
  |  FG -> Font Glow                                                               |
  |  FH -> Font Highlight                                                          |
  |  FS -> Font Size                                                               |
  |  FB -> Font Border                                                             |
  |  FD -> Font Dashed Border                                                      |
  |  FR -> Font Right Border                                                       |
  |  FL -> Font Left Border                                                        |
  |  FT -> Font Top Border                                                         |
  |  FBB -> Font Bottom Border                                                     |
  |  CB -> Code Block                                                              |
  |  IMG -> Image                                                                  |
  +--------------------------------------------------------------------------------+*
*/
/**
 * Usage: Escape user text before the BBCode renderer adds approved HTML.
 * Referenced by: MarkUp() and the smaller BBCode render helpers.
 *
 * @param mixed $value Raw value to escape.
 * @return string HTML-escaped text using UTF-8 substitution.
 */
function corebb_markup_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
}

/**
 * Usage: Validate a BBCode color token before placing it in inline CSS.
 * Referenced by: MarkUp() color, glow, highlight, and border handlers.
 *
 * @param mixed $value Raw color value from BBCode.
 * @return string Safe named or hex color, or an empty string when rejected.
 */
function corebb_markup_safe_color($value): string
{
    $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $value = trim($value);
    if (preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $value)) {
        return $value;
    }
    if (preg_match('/^[a-z]{3,20}$/i', $value)) {
        return strtolower($value);
    }
    return '';
}

/**
 * Usage: Normalize legacy BBCode font-size tokens.
 * Referenced by: corebb_markup_font_size_style().
 *
 * @param mixed $value Raw size token from [size=...].
 * @return string Legacy size key, or an empty string when rejected.
 */
function corebb_markup_safe_size($value): string
{
    $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $value = strtolower(trim($value));
    $value = preg_replace('/\s+/', '', $value) ?? '';

    // HTML font sizes intentionally match the old VNBoards-era behavior:
    // absolute 1-7 and relative +/-1 through +/-6 are allowed, nothing else.
    if (preg_match('/^[1-7]$/', $value)) {
        return $value;
    }
    if (preg_match('/^[+-][1-6]$/', $value)) {
        return $value;
    }

    $namedSizes = [
        'tiny' => '1',
        'small' => '2',
        'normal' => '3',
        'medium' => '3',
        'large' => '4',
        'x-large' => '+2',
        'xlarge' => '+2',
        'xx-large' => '+3',
        'xxlarge' => '+3',
        'huge' => '+3',
    ];

    return $namedSizes[$value] ?? '';
}

/**
 * Usage: Convert a validated BBCode size token to a CSS font-size value.
 * Referenced by: MarkUp() when rendering [size=...].
 *
 * @param mixed $value Raw or normalized size token.
 * @return string CSS size value, or an empty string when rejected.
 */
function corebb_markup_font_size_style($value): string
{
    $size = corebb_markup_safe_size($value);
    $map = [
        '1' => '0.63em',
        '2' => '0.82em',
        '3' => '1em',
        '4' => '1.13em',
        '5' => '1.5em',
        '6' => '2em',
        '7' => '3em',
        '+1' => '1.13em',
        '+2' => '1.5em',
        '+3' => '2em',
        '+4' => '2.5em',
        '+5' => '3em',
        '+6' => '3.5em',
        '-1' => '0.82em',
        '-2' => '0.7em',
        '-3' => '0.63em',
        '-4' => '0.55em',
        '-5' => '0.5em',
        '-6' => '0.45em',
    ];

    return $map[$size] ?? '';
}

/**
 * Usage: Validate a remote URL before rendering it as a post/profile link.
 * Referenced by: MarkUp(), image rendering, YouTube detection, and import links.
 *
 * @param mixed $value Raw URL from content or imported markup.
 * @param array<int, string> $allowedSchemes Allowed URL schemes.
 * @return string Valid URL, or an empty string when rejected.
 */
function corebb_markup_safe_url($value, array $allowedSchemes = ['http', 'https']): string
{
    $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $value = trim($value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/', '', $value);
    if ($value === '' || strlen($value) > 2048) {
        return '';
    }

    $parts = parse_url($value);
    if (!is_array($parts) || empty($parts['scheme'])) {
        return '';
    }

    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, $allowedSchemes, true)) {
        return '';
    }

    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return '';
    }

    return $value;
}

/**
 * Usage: Validate smilie or face asset URLs before rendering image tags.
 * Referenced by: MarkUp() emoticon parsing.
 *
 * @param mixed $value Stored asset path or remote URL.
 * @return string Safe local/remote asset URL, or an empty string when rejected.
 */
function corebb_markup_safe_asset_url($value): string
{
    $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $value = trim($value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/', '', $value);
    if ($value === '' || strlen($value) > 1024) {
        return '';
    }

    $remote = corebb_markup_safe_url($value, ['http', 'https']);
    if ($remote !== '') {
        return $remote;
    }

    // Local/site-relative smilie paths only. Do not allow protocol-relative,
    // backslash, data:, javascript:, or quote/event-handler style payloads.
    if (str_starts_with($value, '//') || str_contains($value, '\\') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) {
        return '';
    }
    if (preg_match('~^/?[A-Za-z0-9._/%-]+(?:\?[A-Za-z0-9._\~!$&()*+,;=:@/%-]*)?$~', $value)) {
        return $value;
    }

    return '';
}

/**
 * Usage: Extract a YouTube video id from a safe YouTube URL.
 * Referenced by: MarkUp() lazy-link rendering.
 *
 * @param mixed $value Raw URL candidate from a post body.
 * @return string Eleven-character YouTube id, or an empty string.
 */
function corebb_youtube_video_id_from_url($value): string
{
    $url = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $url = trim($url);
    $url = rtrim($url, ".,!?;:");
    $url = corebb_markup_safe_url($url, ['http', 'https']);
    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }

    $host = strtolower((string)$parts['host']);
    $host = preg_replace('/^www\./', '', $host) ?? $host;
    $path = trim((string)($parts['path'] ?? ''), '/');
    $videoId = '';

    if ($host === 'youtu.be') {
        $segments = explode('/', $path);
        $videoId = (string)($segments[0] ?? '');
    } elseif ($host === 'youtube.com' || $host === 'm.youtube.com' || $host === 'music.youtube.com') {
        if ($path === 'watch') {
            parse_str((string)($parts['query'] ?? ''), $query);
            $videoId = (string)($query['v'] ?? '');
        } elseif (preg_match('~^(?:embed|shorts|live)/([^/?#]+)~i', $path, $m)) {
            $videoId = (string)$m[1];
        }
    }

    return preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) ? $videoId : '';
}

/**
 * Usage: Render the approved iframe wrapper for a YouTube video id.
 * Referenced by: MarkUp() when a lazy link points to YouTube.
 *
 * @param string $videoId Eleven-character YouTube video id.
 * @return string HTML embed fragment, or an empty string for invalid ids.
 */
function corebb_render_youtube_embed(string $videoId): string
{
    if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
        return '';
    }

    $safeId = corebb_markup_escape($videoId);
    $src = 'https://www.youtube-nocookie.com/embed/' . $safeId;
    return "<div class='bbcode-youtube-embed' style='max-width:560px; margin:6px 0;'>"
        . "<iframe width='560' height='315' src='" . $src . "' title='YouTube video player' loading='lazy' referrerpolicy='strict-origin-when-cross-origin' allow='accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share' allowfullscreen style='max-width:100%; border:0;'></iframe>"
        . '</div>';
}

/**
 * Usage: Sanitize quote attribution text before it becomes a quote label.
 * Referenced by: quote extraction and quote block rendering helpers.
 *
 * @param mixed $attribution Raw username/label from quote markup.
 * @return string Cleaned attribution, capped at 100 characters.
 */
function corebb_clean_quote_attribution($attribution): string
{
    $attribution = html_entity_decode((string)$attribution, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $attribution = preg_replace('~\[/?(?:b|i|u|strong|em)\]~i', '', $attribution) ?? $attribution;
    $attribution = preg_replace('~\[/?color(?:=[^\]]*)?\]~i', '', $attribution) ?? $attribution;
    $attribution = strip_tags($attribution);
    $attribution = preg_replace('/[\x00-\x1F\x7F\[\]]+/', ' ', $attribution) ?? $attribution;
    $attribution = preg_replace('/\s+/', ' ', $attribution) ?? $attribution;
    $attribution = trim($attribution);
    if ($attribution === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($attribution, 0, 100);
    }
    return substr($attribution, 0, 100);
}

/**
 * Usage: Pull a VN archive "User posted:" prefix into quote attribution.
 * Referenced by: corebb_render_quote_markup().
 *
 * VN's archived quote HTML often imported as:
 * [quote][b]User[/b] posted:quoted text[/quote]
 * Promote that first-line prefix into proper quote attribution so the old
 * quote body starts with the quoted text instead of a fake header line.
 *
 * @param string $body Escaped quote body being rendered.
 * @return array{attribution: string, body: string}|null Extracted quote data or null.
 */
function corebb_extract_legacy_quote_prefix(string $body): ?array
{
    $patterns = [
        '~^\s*\[b\]\s*([^\[\]\r\n]{1,100})\s*\[/b\]\s*posted:\s*~i',
        '~^\s*([^\[\]\r\n]{1,100})\s+posted:\s*~i',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match($pattern, $body, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        $author = corebb_clean_quote_attribution((string)$m[1][0]);
        if ($author === '') {
            continue;
        }
        $prefixLen = strlen((string)$m[0][0]);
        return [
            'attribution' => $author,
            'body' => ltrim(substr($body, $prefixLen)),
        ];
    }

    return null;
}


/**
 * Usage: Normalize obvious imported quote headers before balanced parsing.
 * Referenced by: corebb_render_quote_markup().
 *
 * Renderer-only cleanup for old VN quote markup.  The archive contains many
 * nested quote chains that start as [quote][b]User[/b] posted:.  Normalize
 * the safe, obvious opening-attribution form before the balanced quote
 * parser runs.  This intentionally does not write anything back to the DB.
 *
 * @param string $text Escaped markup text about to enter quote parsing.
 * @return string Text with supported legacy quote headers normalized.
 */
function corebb_normalize_legacy_quote_markup_for_render(string $text): string
{
    if ($text === '' || stripos($text, '[quote]') === false || stripos($text, 'posted:') === false) {
        return $text;
    }

    return preg_replace_callback(
        '~\[quote\]\s*\[b\]\s*([^\[\]\r\n]{1,100})\s*\[/b\]\s*posted:\s*~i',
        static function (array $m): string {
            $author = corebb_clean_quote_attribution((string)$m[1]);
            return $author !== '' ? '[quote=' . $author . ']' : (string)$m[0];
        },
        $text
    ) ?? $text;
}

/**
 * Usage: Render VN-style quote blocks, including nested attributed quotes.
 * Referenced by: MarkUp().
 *
 * The input is already HTML-escaped by MarkUp(), so quote bodies may contain
 * allowed BBCode but never raw HTML from user text.
 *
 * @param mixed $text Escaped text that may contain [quote] markup.
 * @param int $depth Internal recursion depth guard.
 * @return string Text with balanced quote blocks rendered as HTML.
 */
function corebb_render_quote_markup($text, $depth = 0) {
    $text = (string)$text;
    if ($depth === 0) {
        $text = corebb_normalize_legacy_quote_markup_for_render($text);
    }
    if ($text === '' || $depth > 25 || stripos($text, '[quote') === false) {
        return $text;
    }

    $output = '';
    $offset = 0;
    $openPattern = '~\[quote(?:=([^\]\r\n]{0,100}))?\]~i';
    $tokenPattern = '~\[quote(?:=[^\]\r\n]{0,100})?\]|\[/quote\]~i';

    while (preg_match($openPattern, $text, $open, PREG_OFFSET_CAPTURE, $offset)) {
        $openText = $open[0][0];
        $openPos = $open[0][1];
        $attribution = isset($open[1][0]) ? (string)$open[1][0] : '';
        $innerStart = $openPos + strlen($openText);

        $output .= substr($text, $offset, $openPos - $offset);

        $searchPos = $innerStart;
        $quoteDepth = 1;
        $closePos = null;
        $closeLen = 0;

        while (preg_match($tokenPattern, $text, $token, PREG_OFFSET_CAPTURE, $searchPos)) {
            $tokenText = $token[0][0];
            $tokenPos = $token[0][1];
            $searchPos = $tokenPos + strlen($tokenText);

            if (stripos($tokenText, '[/quote]') === 0) {
                $quoteDepth--;
                if ($quoteDepth === 0) {
                    $closePos = $tokenPos;
                    $closeLen = strlen($tokenText);
                    break;
                }
            }
            else {
                $quoteDepth++;
            }
        }

        // Preserve malformed/unclosed quote markup rather than swallowing text.
        if ($closePos === null) {
            $output .= substr($text, $openPos);
            return $output;
        }

        $inner = substr($text, $innerStart, $closePos - $innerStart);
        if (trim($attribution) === '') {
            $legacy = corebb_extract_legacy_quote_prefix($inner);
            if (is_array($legacy)) {
                $attribution = $legacy['attribution'];
                $inner = $legacy['body'];
            }
        }

        $output .= corebb_render_quote_block($attribution, corebb_render_quote_markup($inner, $depth + 1));
        $offset = $closePos + $closeLen;
    }

    $output .= substr($text, $offset);
    return $output;
}

/**
 * Usage: Render the opening HTML for a quote block.
 * Referenced by: corebb_render_quote_block() and malformed-quote fallback code.
 *
 * @param mixed $attribution Optional quote author/label.
 * @return string Opening quote HTML fragment.
 */
function corebb_render_quote_open_block($attribution) {
    $attribution = corebb_clean_quote_attribution($attribution);
    if ($attribution !== '') {
        $label = corebb_markup_escape($attribution) . ' posted:';
    }
    else {
        $label = 'Quote:';
    }

    return "<div class='QuotedText'><strong>" . $label . "</strong><br><hr class='bbcode-rule'>";
}

/**
 * Usage: Render the closing HTML for a quote block.
 * Referenced by: corebb_render_quote_block() and legacy quote fallback code.
 *
 * @return string Closing quote HTML fragment.
 */
function corebb_render_quote_close_block() {
    return "<hr class='bbcode-rule'></div>";
}

/**
 * Usage: Wrap rendered quote body text in the standard quote container.
 * Referenced by: corebb_render_quote_markup().
 *
 * @param mixed $attribution Optional quote author/label.
 * @param mixed $body Already-rendered quote body.
 * @return string Complete quote HTML fragment.
 */
function corebb_render_quote_block($attribution, $body) {
    return corebb_render_quote_open_block($attribution) . $body . corebb_render_quote_close_block();
}



/**
 * Usage: Return the supported [face_name] to image-file lookup table.
 * Referenced by: face rendering and legacy boardface conversion helpers.
 *
 * @return array<string, string> Map of normalized face names to gif filenames.
 */
function corebb_face_name_file_map(): array
{
    return [
        'thinking' => '33.gif',
        'monkey' => '38.gif',
        'tired' => '31.gif',
        'confused' => '6.gif',
        'hugs' => '60.gif',
        'not_talking' => '28.gif',
        'alien_1' => '47.gif',
        'nerd' => '22.gif',
        'alien_2' => '48.gif',
        'money_eyes' => '53.gif',
        'beatup' => '56.gif',
        'shock' => '11.gif',
        'cry' => '17.gif',
        'dancing' => '59.gif',
        'plain' => '19.gif',
        'kiss' => '10.gif',
        'blush' => '8.gif',
        'idea' => '45.gif',
        'angel' => '21.gif',
        'praying' => '51.gif',
        'angry' => '12.gif',
        'liarliar' => '55.gif',
        'worried' => '15.gif',
        'rolling_eyes' => '25.gif',
        'skull' => '46.gif',
        'frustrated' => '49.gif',
        'raised_brow' => '20.gif',
        'love' => '7.gif',
        'shame_on_you' => '58.gif',
        'coffee' => '44.gif',
        'sick' => '26.gif',
        'silly' => '30.gif',
        'talk_hand' => '23.gif',
        'drooling' => '32.gif',
        'rose' => '40.gif',
        'tongue' => '9.gif',
        'grin' => '4.gif',
        'sad' => '2.gif',
        'doh!' => '34.gif',
        'wink' => '3.gif',
        'mischief' => '13.gif',
        'chicken' => '39.gif',
        'applause' => '35.gif',
        'clown' => '29.gif',
        'batting' => '5.gif',
        'laugh' => '18.gif',
        'devil' => '16.gif',
        'cowboy' => '50.gif',
        'pig' => '36.gif',
        'whistling' => '54.gif',
        'pumpkin' => '43.gif',
        'flag' => '42.gif',
        'cool' => '14.gif',
        'cow' => '37.gif',
        'hypnotized' => '52.gif',
        'happy' => '1.gif',
        'shhh' => '27.gif',
        'peace' => '57.gif',
        'good_luck' => '41.gif',
        'sleep' => '24.gif',
    ];
}

/**
 * Usage: Build the reverse image-file to face-name lookup table.
 * Referenced by: corebb_legacy_boardface_url_to_name().
 *
 * @return array<string, string> Map of gif filenames to normalized face names.
 */
function corebb_face_file_name_map(): array
{
    $byFile = [];
    foreach (corebb_face_name_file_map() as $name => $file) {
        $byFile[strtolower($file)] = $name;
    }
    return $byFile;
}

/**
 * Usage: Convert old boardfaces image URLs into CoreBB face names.
 * Referenced by: MarkUp() image rendering for imported archive content.
 *
 * @param string $url Stored or imported boardface image URL.
 * @return string Face name, or an empty string when the URL is not recognized.
 */
function corebb_legacy_boardface_url_to_name(string $url): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url) ?? '';
    if ($url === '') {
        return '';
    }

    $file = '';
    if (preg_match('~^(?:https?:)?//[^\s/]+/boardfaces/([1-9]|[1-5][0-9]|60)\.gif(?:[?#].*)?$~i', $url, $m)) {
        $file = $m[1] . '.gif';
    } elseif (preg_match('~^/images/faces/([1-9]|[1-5][0-9]|60)\.gif(?:[?#].*)?$~i', $url, $m)) {
        $file = $m[1] . '.gif';
    }

    if ($file === '') {
        return '';
    }

    $byFile = corebb_face_file_name_map();
    return $byFile[strtolower($file)] ?? '';
}

/**
 * Usage: Render a known board face by normalized name.
 * Referenced by: MarkUp() face tags and legacy boardface image conversion.
 *
 * @param string $name Normalized face name.
 * @return string HTML image fragment, or an empty string when unknown.
 */
function corebb_render_face_image_by_name(string $name): string
{
    $name = strtolower(trim($name));
    $faceNames = corebb_face_name_file_map();
    if (!isset($faceNames[$name])) {
        return '';
    }
    return "<img src='/images/faces/" . $faceNames[$name] . "' style='vertical-align:top;' alt=''>";
}

/**
 * Usage: Normalize a [code=language] token for display and CSS class names.
 * Referenced by: corebb_render_code_block().
 *
 * @param mixed $value Raw language token.
 * @return array{label: string, class: string} Display label and CSS class suffix.
 */
function corebb_code_block_language($value): array
{
    $raw = trim((string)$value);
    $raw = html_entity_decode($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $raw = preg_replace('/[\x00-\x1F\x7F]+/', '', $raw) ?? '';
    $raw = trim($raw);

    if ($raw === '') {
        return ['label' => 'Code', 'class' => ''];
    }

    if (function_exists('mb_substr')) {
        $raw = mb_substr($raw, 0, 32);
    } else {
        $raw = substr($raw, 0, 32);
    }

    if (!preg_match('/^[A-Za-z0-9#+._-]{1,32}$/', $raw)) {
        return ['label' => 'Code', 'class' => ''];
    }

    $key = strtolower($raw);
    $labels = [
        'html' => 'HTML', 'htm' => 'HTML',
        'php' => 'PHP',
        'css' => 'CSS',
        'js' => 'JavaScript', 'javascript' => 'JavaScript',
        'sql' => 'SQL',
        'xml' => 'XML',
        'json' => 'JSON',
        'bash' => 'Bash', 'sh' => 'Shell', 'shell' => 'Shell',
        'txt' => 'Text', 'text' => 'Text',
        'py' => 'Python', 'python' => 'Python',
        'c' => 'C', 'cpp' => 'C++', 'c++' => 'C++',
        'cs' => 'C#', 'c#' => 'C#', 'csharp' => 'C#',
        'java' => 'Java',
        'ini' => 'INI', 'diff' => 'Diff',
    ];
    $classes = [
        'htm' => 'html',
        'js' => 'javascript',
        'sh' => 'bash', 'shell' => 'bash',
        'txt' => 'text',
        'py' => 'python',
        'c++' => 'cpp',
        'cs' => 'csharp', 'c#' => 'csharp',
    ];

    $label = $labels[$key] ?? strtoupper($raw);
    $class = $classes[$key] ?? preg_replace('/[^a-z0-9_-]+/', '-', $key);
    $class = trim((string)$class, '-_');

    return [
        'label' => $label !== '' ? $label : 'Code',
        'class' => $class !== '' ? $class : '',
    ];
}

/**
 * Usage: Render a BBCode code block without letting user text become HTML.
 * Referenced by: MarkUp() before normal BBCode parsing begins.
 *
 * @param mixed $code Raw code body.
 * @param mixed $language Optional language token from [code=...].
 * @return string HTML code-block fragment.
 */
function corebb_render_code_block($code, $language = ''): string
{
    $code = (string)$code;
    $code = str_replace(["\r\n", "\r"], "\n", $code);
    // Most users put the opening/closing BBCode tags on their own lines.
    // Trim one wrapper newline on each side without stripping intentional
    // indentation or multiple blank lines inside the code body.
    if (str_starts_with($code, "\n")) {
        $code = substr($code, 1);
    }
    if (str_ends_with($code, "\n")) {
        $code = substr($code, 0, -1);
    }

    $lang = corebb_code_block_language($language);
    $label = corebb_markup_escape($lang['label']);
    $class = $lang['class'] !== '' ? ' language-' . corebb_markup_escape($lang['class']) : '';
    $display = corebb_markup_escape($code);
    $display = str_replace("\t", "    ", $display);
    // MarkUp() output is commonly wrapped in nl2br() by callers. Do not
    // leave literal newlines in the block HTML or code will double-space.
    $display = str_replace("\n", '<br>', $display);
    if ($display === '') {
        $display = '&nbsp;';
    }

    return "<div class='QuotedText bbcode-code-block'><strong>" . $label . ":</strong><br><hr class='bbcode-rule'>"
        . "<pre class='bbcode-code-pre'><code class='bbcode-code-content" . $class . "'>" . $display . "</code></pre>"
        . "<hr class='bbcode-rule'></div>";
}

/**
 * Usage: Load the shared Parsedown instance only when Markdown BBCode is used.
 * Referenced by: corebb_render_markdown_block().
 *
 * @return object|null Parsedown parser in safe mode, or null when unavailable.
 */
function corebb_markdown_parser(): ?object
{
    static $parser = null;
    static $loaded = false;

    if ($loaded) {
        return $parser;
    }

    $loaded = true;
    if (!class_exists('Parsedown')) {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    if (!class_exists('Parsedown')) {
        return null;
    }

    $parser = new Parsedown();
    if (method_exists($parser, 'setSafeMode')) {
        $parser->setSafeMode(true);
    }

    return $parser;
}

/**
 * Usage: Normalize anchors emitted by Parsedown to the forum's safe link policy.
 * Referenced by: corebb_render_markdown_block().
 *
 * @param string $html Parsedown-generated HTML.
 * @return string HTML with unsafe links degraded to plain label text.
 */
function corebb_sanitize_markdown_links(string $html): string
{
    return preg_replace_callback('~<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>(.*?)</a>~is', static function ($m): string {
        $rawUrl = html_entity_decode((string)($m[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = corebb_markup_safe_url($rawUrl, ['http', 'https']);
        if ($url === '') {
            return (string)($m[3] ?? '');
        }

        return "<a class='AuthorLink' href='" . corebb_markup_escape($url)
            . "' target='_blank' rel='noopener noreferrer'>" . (string)($m[3] ?? '') . '</a>';
    }, $html) ?? $html;
}

/**
 * Usage: Render [md]...[/md] content through Parsedown without accepting raw HTML.
 * Referenced by: MarkUp() before regular BBCode parsing begins.
 *
 * @param mixed $markdown Raw Markdown body between [md] tags.
 * @return string Sanitized HTML fragment.
 */
function corebb_render_markdown_block($markdown): string
{
    $markdown = (string)$markdown;
    $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
    if (str_starts_with($markdown, "\n")) {
        $markdown = substr($markdown, 1);
    }
    if (str_ends_with($markdown, "\n")) {
        $markdown = substr($markdown, 0, -1);
    }

    $parser = corebb_markdown_parser();
    if ($parser === null || !method_exists($parser, 'text')) {
        $fallback = corebb_markup_escape($markdown);
        $fallback = str_replace("\n", '<br>', $fallback);
        return "<div class='bbcode-markdown-block'>" . ($fallback !== '' ? $fallback : '&nbsp;') . '</div>';
    }

    $html = (string)$parser->text($markdown);
    $html = corebb_sanitize_markdown_links($html);
    // Keep image rendering inside the existing [image] and [a_image] policies.
    $html = preg_replace('~<img\b[^>]*>~i', '', $html) ?? $html;
    $html = preg_replace_callback('~<pre><code([^>]*)>(.*?)</code></pre>~is', static function ($m): string {
        $code = str_replace(["\r\n", "\r", "\n"], '<br>', (string)($m[2] ?? ''));
        return '<pre><code' . (string)($m[1] ?? '') . '>' . $code . '</code></pre>';
    }, $html) ?? $html;
    // MarkUp() output is usually passed through nl2br(); avoid double breaks.
    $html = str_replace(["\r\n", "\r", "\n"], '', $html);

    return "<div class='bbcode-markdown-block'>" . ($html !== '' ? $html : '&nbsp;') . '</div>';
}

/**
 * Usage: Convert trusted BBCode tags in forum text into sanitized display HTML.
 * Referenced by: posts, PMs, profiles, signatures, blogs, imports, and previews.
 *
 * @param mixed $text Raw user/content text to render.
 * @param mixed $permissions Dash-delimited BBCode permission list.
 * @return string Sanitized HTML fragment for display through Twig/formatted content.
 */
function MarkUp($text, $permissions){
    $PermissionsArr = array_map('strtoupper', explode('-', (string)$permissions));
    $hasPermission = static function (string $perm) use ($PermissionsArr): bool {
        return in_array($perm, $PermissionsArr, true);
    };

    // MarkUp() is the public renderer for posts, PMs, signatures, bios, notes,
    // and import previews. Treat all input as untrusted raw text.
    $text = (string)$text;

    $formatBlocks = [];
    if (($hasPermission('CB') || $hasPermission('MD')) && preg_match('~\[(?:bbcode|code|md)(?:=[^\]]{0,32})?\]~i', $text)) {
        $text = preg_replace_callback('~\[(bbcode|code|md)(?:=([^\]]{0,32}))?\](.*?)\[/\1\]~is', static function ($m) use (&$formatBlocks, $hasPermission): string {
            $tag = strtolower((string)($m[1] ?? 'code'));
            if ($tag === 'md') {
                if (!$hasPermission('MD')) {
                    return (string)($m[0] ?? '');
                }

                $value = (string)($m[3] ?? '');
                $key = '%%COREBB_FORMAT_BLOCK_' . count($formatBlocks) . '_' . md5($value . count($formatBlocks)) . '%%';
                $formatBlocks[$key] = corebb_render_markdown_block($value);
                return $key;
            }

            if (!$hasPermission('CB')) {
                return (string)($m[0] ?? '');
            }

            $language = $tag === 'code' ? (string)($m[2] ?? '') : '';
            $value = (string)($m[3] ?? '');
            $key = '%%COREBB_FORMAT_BLOCK_' . count($formatBlocks) . '_' . md5($value . count($formatBlocks)) . '%%';
            $formatBlocks[$key] = corebb_render_code_block($value, $language);
            return $key;
        }, $text) ?? $text;
    }

    // BBCode tags outside extracted code blocks are parsed after escaping so
    // user-supplied HTML/event handlers stay text.
    $text = corebb_markup_escape($text);

    if($hasPermission('Q')){
        $text = corebb_render_quote_markup($text);
        // Fallback for malformed/unbalanced attributed archive quotes that the
        // balanced parser deliberately preserved.  Plain [quote] fallbacks are
        // handled by the legacy replacement table below.
        if (stripos($text, '[quote=') !== false) {
            $text = preg_replace_callback('~\[quote=([^\]\r\n]{0,100})\]~i', static function ($m): string {
                return corebb_render_quote_open_block((string)($m[1] ?? ''));
            }, $text) ?? $text;
        }
    }

    $replace = [];
    // [BR] is used by the VN archive importer, especially for multi-line
    // legacy user titles. It is safe to support globally because MarkUp()
    // has already escaped user-supplied HTML at this point.
    $replace[] = ['[br]', '<br>'];
    if($hasPermission('B')){
        $replace[] = ['[b]', '<strong>'];
        $replace[] = ['[/b]', '</strong>'];
    }
    if($hasPermission('I')){
        $replace[] = ['[i]', '<em>'];
        $replace[] = ['[/i]', '</em>'];
    }
    if($hasPermission('Q')){
        // Fallback for malformed legacy quote tags that were not consumed by the
        // balanced parser above.
        $replace[] = ['[quote]', corebb_render_quote_open_block('')];
        $replace[] = ['[/quote]', corebb_render_quote_close_block()];
    }
    if($hasPermission('U')){
        $replace[] = ['[u]', '<u>'];
        $replace[] = ['[/u]', '</u>'];
    }
    if($hasPermission('O')){
        $replace[] = ['[o]', "<span style='text-decoration:overline;'>"];
        $replace[] = ['[/o]', '</span>'];
    }
    if($hasPermission('BQ')){
        $replace[] = ['[blockquote]', '<blockquote>'];
        $replace[] = ['[/blockquote]', '</blockquote>'];
        $replace[] = ['[bq]', '<blockquote>'];
        $replace[] = ['[/bq]', '</blockquote>'];
    }
    if($hasPermission('S')){
        $replace[] = ['[spaces]', "<span style='white-space: pre'>"];
        $replace[] = ['[/spaces]', '</span>'];
    }
    if($hasPermission('HR')){
        $replace[] = ['[hr]', "<hr class='bbcode-rule'>"];
    }
    if($hasPermission('UL')){
        // Common forum shorthand for an unordered list. This keeps public
        // release posts compatible with [list][*]Item[/list] style BBCode.
        $replace[] = ['[list]', '<ul>'];
        $replace[] = ['[/list]', '</ul>'];
        $replace[] = ['[ul]', '<ul>'];
        $replace[] = ['[/ul]', '</ul>'];
    }
    if($hasPermission('OL')){
        $replace[] = ['[ol]', '<ol>'];
        $replace[] = ['[/ol]', '</ol>'];
    }
    if($hasPermission('LI')){
        $replace[] = ['[li]', '<li>'];
        $replace[] = ['[/li]', '</li>'];
        // Legacy VN toolbar emits [bullet]...[/bullet] instead of [li]...[/li].
        // Treat it as a list item so old/new posts and the toolbar button render.
        $replace[] = ['[bullet]', '<li>'];
        $replace[] = ['[/bullet]', '</li>'];
        // Common shorthand used by some imported/forum BBCode variants.
        $replace[] = ['[*]', '<li>'];
    }
    if($hasPermission('ST')){
        $replace[] = ['[strike]', "<span style='text-decoration: line-through'>"];
        $replace[] = ['[/strike]', '</span>'];
    }
    if($hasPermission('SP')){
        $replace[] = ['[spoiler]', "<span style='color: black; background-color: black; border-right: 1px dashed blue; border-left: 1px dashed blue; border-bottom: 1px dashed blue; border-top: 1px dashed blue;'>"];
        $replace[] = ['[/spoiler]', '</span>'];
    }
    if($hasPermission('BL')){
        $replace[] = ['[blink]', "<span class='bbcode-blink'>"];
        $replace[] = ['[/blink]', '</span>'];
    }
    if($hasPermission('CT')){
        $replace[] = ['[center]', "<span class='bbcode-center'>"];
        $replace[] = ['[/center]', '</span>'];
    }

    foreach ($replace as $rule) {
        $text = str_ireplace($rule[0], $rule[1], $text);
    }

    if($hasPermission('F')){
        // Now for the face symbol things.
        $faces = [
            "*-:)" => '45.gif', ":-?" => '33.gif', "/:)" => '20.gif', ":@:)" => '2.gif', "[:D]" => '36.gif',
            ":^O" => '60.gif', "=P~" => '32.gif', "]):)" => '50.gif', "O:)" => '21.gif', "I-)" => '24.gif',
            ":-L" => '49.gif', ":-s" => '15.gif', ":)" => '1.gif', "[-X" => '58.gif', "]-}" => '48.gif',
            "=}=" => '57.gif', ":O" => '11.gif', "=:}" => '47.gif', "8-}" => '30.gif', ";;)" => '5.gif',
            ":^o" => '55.gif', "**==" => '42.gif', "8-|" => '25.gif', ":-oo" => '54.gif', "]:)" => '16.gif',
            ":-8" => '26.gif', "[-(" => '28.gif', ":*" => '10.gif', ":_|" => '17.gif', ":o)" => '29.gif',
            "[-o|" => '51.gif', ";Y" => '13.gif', ":8}" => '8.gif', "$-)" => '53.gif', "X-(" => '12.gif',
            "(~~)" => '43.gif', "(:|" => '31.gif', ":|" => '19.gif', "B-)" => '14.gif', "=;" => '23.gif',
            ":x" => '7.gif', ":D" => '4.gif', "@};-" => '40.gif', ":-B" => '22.gif', "\:D/" => '59.gif',
            "=D=" => '35.gif', "@-)" => '52.gif', ":p" => '9.gif', ":P" => '9.gif', "=p" => '9.gif',
            "3:-O" => '37.gif', "%%-" => '41.gif', ":-$" => '27.gif', ":{|}" => '38.gif', "~:-" => '39.gif',
            "~o)" => '44.gif', "b-(" => '56.gif', "#-o" => '34.gif', ":-/" => '6.gif', ";)" => '3.gif',
        ];
        $faceNames = corebb_face_name_file_map();
        $text = preg_replace_callback('~\[face_([a-z0-9_!\-]{1,32})\]~i', static function ($m) use ($faceNames): string {
            $name = strtolower((string)$m[1]);
            if (!isset($faceNames[$name])) {
                return $m[0];
            }
            return corebb_render_face_image_by_name($name);
        }, $text);

        foreach ($faces as $token => $file) {
            $text = str_replace($token, "<img src='/images/faces/" . $file . "' style='vertical-align:top;' alt=''>", $text);
        }
    }

    if($hasPermission('FC') && stripos($text, '[color') !== false){
        $text = preg_replace_callback('~\[color=([^\]]{1,32})\](.*?)\[/(?:color|hl)\]~is', static function ($m): string {
            $color = corebb_markup_safe_color($m[1]);
            return $color === '' ? $m[2] : "<SPAN STYLE='color: " . corebb_markup_escape($color) . ";'>" . $m[2] . '</SPAN>';
        }, $text);
    }
    if($hasPermission('FG') && stripos($text, '[glow') !== false){
        $text = preg_replace_callback('~\[glow=([^\]]{1,32})\](.*?)\[/glow\]~is', static function ($m): string {
            $color = corebb_markup_safe_color($m[1]);
            return $color === '' ? $m[2] : "<SPAN STYLE='height:2;filter: glow(color=" . corebb_markup_escape($color) . ", strength=2);'>" . $m[2] . '</SPAN>';
        }, $text);
    }
    if($hasPermission('FH') && stripos($text, '[hl') !== false){
        $text = preg_replace_callback('~\[hl=([^\]]{1,32})\](.*?)\[/hl\]~is', static function ($m): string {
            $color = corebb_markup_safe_color($m[1]);
            return $color === '' ? $m[2] : "<SPAN STYLE='background-color: " . corebb_markup_escape($color) . ";'>" . $m[2] . '</SPAN>';
        }, $text);
    }

    if($hasPermission('FS') && stripos($text, '[size') !== false){
        $text = preg_replace_callback('~\[size=([^\]]{1,32})\](.*?)\[/size\]~is', static function ($m): string {
            $sizeStyle = corebb_markup_font_size_style($m[1]);
            return $sizeStyle === '' ? $m[2] : "<span style='font-size:" . corebb_markup_escape($sizeStyle) . ";'>" . $m[2] . '</span>';
        }, $text);
    }

    $borderMap = [
        'border' => 'border-right: 1px solid %s; border-left: 1px solid %s; border-bottom: 1px solid %s; border-top: 1px solid %s;',
        'dashedborder' => 'border-right: 1px dashed %s; border-left: 1px dashed %s; border-bottom: 1px dashed %s; border-top: 1px dashed %s;',
        'right-border' => 'border-right: 1px solid %s;',
        'left-border' => 'border-left: 1px solid %s;',
        'top-border' => 'border-top: 1px solid %s;',
        'bottom-border' => 'border-bottom: 1px solid %s;',
    ];
    foreach ($borderMap as $tag => $stylePattern) {
        $perm = match ($tag) {
            'border' => 'FB',
            'dashedborder' => 'FD',
            'right-border' => 'FR',
            'left-border' => 'FL',
            'top-border' => 'FT',
            'bottom-border' => 'FBB',
            default => '',
        };
        if (!$hasPermission($perm) || stripos($text, '[' . $tag) === false) {
            continue;
        }
        $text = preg_replace_callback('~\[' . preg_quote($tag, '~') . '=([^\]]{1,32})\](.*?)\[/' . preg_quote($tag, '~') . '\]~is', static function ($m) use ($stylePattern): string {
            $color = corebb_markup_safe_color($m[1]);
            if ($color === '') {
                return $m[2];
            }
            $safeColor = corebb_markup_escape($color);
            $count = substr_count($stylePattern, '%s');
            return "<SPAN STYLE='" . vsprintf($stylePattern, array_fill(0, $count, $safeColor)) . "'>" . $m[2] . '</SPAN>';
        }, $text);
    }

    if($hasPermission('IMG') && (stripos($text, '[image') !== false || stripos($text, '[a_image') !== false || stripos($text, '[img]') !== false)){
        $renderImage = static function ($rawValue, bool $fullSize = false): string {
            $rawUrl = html_entity_decode((string)$rawValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $faceName = corebb_legacy_boardface_url_to_name($rawUrl);
            if ($faceName !== '') {
                return corebb_render_face_image_by_name($faceName);
            }

            $url = corebb_markup_safe_url($rawUrl, ['http', 'https']);
            if ($url === '' && function_exists('corebb_safe_local_image_asset')) {
                $url = corebb_safe_local_image_asset($rawUrl, ['images']);
            }
            if ($url === '') {
                return '';
            }

            $safeUrl = corebb_markup_escape($url);
            if ($fullSize) {
                return "<a class='BoardRowBLink' target='_blank' rel='noopener noreferrer' href='" . $safeUrl . "'><img class='bbcode-post-image-admin' src='" . $safeUrl . "' alt='' style='border:1px solid currentColor; margin:5px; max-width:100%; height:auto; vertical-align:top;'></a>";
            }

            return "<a class='BoardRowBLink' target='_blank' rel='noopener noreferrer' href='" . $safeUrl . "'><img class='bbcode-post-image' src='" . $safeUrl . "' alt='' height='120' width='160' style='border:1px solid currentColor; margin:5px;'></a>";
        };

        $text = preg_replace_callback('~\[a_image\s*=\s*([^\]\s]{1,2048})\]~i', static function ($m) use ($renderImage, $hasPermission): string {
            return $renderImage($m[1] ?? '', $hasPermission('AIMG'));
        }, $text);
        $text = preg_replace_callback('~\[image\s*=\s*([^\]\s]{1,2048})\]~i', static function ($m) use ($renderImage): string {
            return $renderImage($m[1] ?? '', false);
        }, $text);
        $text = preg_replace_callback('~\[img\](.*?)\[/img\]~is', static function ($m) use ($renderImage): string {
            return $renderImage(trim((string)($m[1] ?? '')), false);
        }, $text);
    }

    if($hasPermission('LL') && stripos($text, '[link=') !== false){
        // The VN archive importer converts safe HTML anchors to [link=url]text[/link].
        // Render them here instead of leaving imported signatures/posts as raw BBCode.
        $text = preg_replace_callback('~\[link\s*=\s*([^\]]{1,2048})\](.*?)\[/link\]~is', static function ($m): string {
            $url = corebb_markup_safe_url(html_entity_decode((string)$m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ['http', 'https']);
            if ($url === '') {
                return $m[2];
            }
            $safeUrl = corebb_markup_escape($url);
            $label = trim((string)$m[2]) !== '' ? (string)$m[2] : $safeUrl;
            return "<A class='AuthorLink' HREF='" . $safeUrl . "' TARGET='_blank' rel='noopener noreferrer'>" . $label . '</A>';
        }, $text);
    }

    if($hasPermission('LL')){
        $text = preg_replace_callback('~(^|[\s(])((?:https?)://[^\s<>{}\[\]"\']{3,2048})~i', static function ($m): string {
            $rawUrl = (string)$m[2];
            $trimmedUrl = rtrim($rawUrl, ".,!?;:");
            $trailing = substr($rawUrl, strlen($trimmedUrl));
            $url = corebb_markup_safe_url($trimmedUrl, ['http', 'https']);
            if ($url === '') {
                return $m[0];
            }
            $videoId = corebb_youtube_video_id_from_url($url);
            if ($videoId !== '') {
                return $m[1] . corebb_render_youtube_embed($videoId) . $trailing;
            }
            $safeUrl = corebb_markup_escape($url);
            return $m[1] . "<A class='AuthorLink' HREF='" . $safeUrl . "' TARGET='_blank' rel='noopener noreferrer'>" . $safeUrl . '</A>' . $trailing;
        }, $text);
    }

    if (stripos($text, '<li>') !== false && stripos($text, '<ul>') === false && stripos($text, '<ol>') === false) {
        $text = str_ireplace('<li>', '<span class="bbcode-list-item">', $text);
        $text = str_ireplace('</li>', '</span>', $text);
    }

    if (!empty($formatBlocks)) {
        $text = strtr($text, $formatBlocks);
    }

    return $text;
}

/**
 * Usage: Normalize post text before storage and balance code tags.
 * Referenced by: post, blog, and signature save workflows.
 *
 * @param mixed $data Raw textarea/body content.
 * @return string Normalized text ready for database writes.
 */
function PreparePostData($data){
    $data = (string)$data;

    // Normalize browser/OS line endings before storing. This keeps textarea
    // edits from turning into visible literal \r\n text after prepared writes.
    $data = str_replace(["\r\n", "\r"], "\n", $data);

    /* Fix Code tags, this includes finding open tags and closing them (that's really it =P ) */
    foreach (['bbcode', 'code', 'md'] as $codeTag) {
        $TagsOpen = preg_match_all('~(\[' . $codeTag . '(?:=[^\]]+)?\])~is', $data, $dummy);
        $TagsClosed = preg_match_all('~(\[/' . $codeTag . '\])~is', $data, $dummy);

        /* Perform the fixes */
        if ($TagsOpen > $TagsClosed){
            $data .= str_repeat('[/' . $codeTag . ']', $TagsOpen - $TagsClosed);
        }
        elseif ($TagsClosed > $TagsOpen){
            $data = str_repeat('[' . $codeTag . ']', $TagsClosed - $TagsOpen) . $data;
        }
    }

    return $data;
}

/**
 * Usage: Compatibility wrapper for prepared-statement write paths.
 * Referenced by: newer post/blog/profile write code that already uses bindings.
 *
 * @param mixed $data Raw textarea/body content.
 * @return string Normalized text ready for database writes.
 */
function CleanPostDataForPrepared($data){
    // Use this when the SQL write uses prepared statements.
    return PreparePostData($data);
}

/**
 * Usage: Load a user's stored signature text.
 * Referenced by: profile pages, postbit helpers, and signature previews.
 *
 * @param mixed $userid User id by default, or username when non-numeric.
 * @return string Plain signature text after legacy slash cleanup.
 */
function CreateUserSignature($userid){
    corebb_user_ensure_profile_columns();
	$sigoutput = "";
	$usrsig = is_numeric($userid) ? db_one("SELECT * FROM `users` WHERE `id` = ? LIMIT 1", [(int)$userid]) : db_one("SELECT * FROM `users` WHERE `username` = ? LIMIT 1", [(string)$userid]);
	if($usrsig){
		$sigoutput = corebb_user_signature_from_row($usrsig);
	}
	return stripslashes($sigoutput);
}


/**
 * Usage: Render text with the standard broad BBCode permission set.
 * Referenced by: legacy display paths that do not pass a custom permission list.
 *
 * @param mixed $text Raw content to render.
 * @return string Sanitized HTML fragment from MarkUp().
 */
function addmarkupcode($text){
    return MarkUp($text, "B-I-Q-U-O-BQ-S-HR-UL-OL-LI-ST-SP-BL-CT-F-LL-FC-FG-FH-FB-FD-FL-FT-FBB-FS-CB-MD-IMG");
}


/**
 * Usage: Build the compact numeric page sequence for large archive lists.
 * Referenced by: compact pagination and topic bracket helpers.
 *
 * Old VN-style pages listed every page number, which becomes unreadable with
 * hundreds of archive pages. This keeps first/last, nearby pages, and ellipses.
 *
 * @param mixed $currentPage Current 1-based page number.
 * @param mixed $totalPages Total page count.
 * @param mixed $radius Number of pages to keep on each side of current.
 * @return array<int, int|string> Ordered page numbers with "..." gap markers.
 */
function corebb_compact_page_sequence($currentPage, $totalPages, $radius = 2){
    $currentPage = max(1, (int)$currentPage);
    $totalPages = max(1, (int)$totalPages);
    $radius = max(0, (int)$radius);

    $wanted = [1 => true, $totalPages => true];
    for($i = $currentPage - $radius; $i <= $currentPage + $radius; $i++){
        if($i >= 1 && $i <= $totalPages){
            $wanted[$i] = true;
        }
    }

    ksort($wanted, SORT_NUMERIC);
    $out = [];
    $last = 0;
    foreach(array_keys($wanted) as $page){
        if($last && $page > $last + 1){
            $out[] = '...';
        }
        $out[] = $page;
        $last = $page;
    }
    return $out;
}

/**
 * Usage: Render compact linked pagination for boards, topics, and archive lists.
 * Referenced by: board/thread view helpers and fallback page builders.
 *
 * @param mixed $urlPattern URL containing a {page} placeholder.
 * @param mixed $currentPage Current 1-based page number.
 * @param mixed $totalPages Total page count.
 * @param string $linkClass CSS class applied to links and labels.
 * @param string $label Optional leading label.
 * @param string $separator HTML/text separator between page links.
 * @param mixed $radius Number of nearby pages to show.
 * @return string Pagination HTML fragment, or an empty string for one page.
 */
function corebb_compact_pagination_html($urlPattern, $currentPage, $totalPages, $linkClass = 'MainMenuFont', $label = 'Pages:', $separator = ' | ', $radius = 2){
    $currentPage = max(1, (int)$currentPage);
    $totalPages = max(1, (int)$totalPages);
    if($totalPages <= 1){
        return '';
    }

    $safeClass = htmlspecialchars($linkClass, ENT_QUOTES);
    $html = '';
    if($label !== ''){
        $html .= "<a class='" . $safeClass . "'><b>" . htmlspecialchars($label, ENT_QUOTES) . "</b></a> ";
    }

    $parts = [];
    foreach(corebb_compact_page_sequence($currentPage, $totalPages, $radius) as $page){
        if($page === '...'){
            $parts[] = "<a class='" . $safeClass . "'>...</a>";
            continue;
        }
        $page = (int)$page;
        if($page === $currentPage){
            $parts[] = "<a class='" . $safeClass . "'><b>$page</b></a>";
        }else{
            $url = str_replace('{page}', (string)$page, $urlPattern);
            $parts[] = "<a class='" . $safeClass . "' href='" . htmlspecialchars($url, ENT_QUOTES) . "'>$page</a>";
        }
    }

    return $html . implode("<a class='" . $safeClass . "'>$separator</a>", $parts);
}

/**
 * Usage: Render the small bracketed page links shown beside topic titles.
 * Referenced by: buildpages() and topic-list view models.
 *
 * @param mixed $urlPattern URL containing a {page} placeholder.
 * @param mixed $totalPages Total page count.
 * @param string $linkClass CSS class applied to bracket/link text.
 * @param mixed $radius Number of early pages to expose around page one.
 * @return string Bracketed pagination HTML fragment, or empty for one page.
 */
function corebb_vn_topic_page_brackets_html($urlPattern, $totalPages, $linkClass = 'SmallText', $radius = 1){
    $totalPages = max(1, (int)$totalPages);
    if($totalPages <= 1){
        return '';
    }

    $safeClass = htmlspecialchars($linkClass, ENT_QUOTES);
    $sequence = function_exists('corebb_compact_page_sequence')
        ? corebb_compact_page_sequence(1, $totalPages, $radius)
        : range(1, $totalPages);

    $parts = [];
    foreach($sequence as $page){
        if($page === '...'){
            $parts[] = "<span class='" . $safeClass . "'>...</span>";
            continue;
        }
        $page = (int)$page;
        $url = str_replace('{page}', (string)$page, $urlPattern);
        $parts[] = "<a class='" . $safeClass . "' href='" . htmlspecialchars($url, ENT_QUOTES) . "'>" . $page . "</a>";
    }

    return " <span class='" . $safeClass . "'>[</span>"
        . implode("<span class='" . $safeClass . "'>, </span>", $parts)
        . "<span class='" . $safeClass . "'>]</span>";
}

/**
 * Usage: Render previous/next/reload controls for paged legacy views.
 * Referenced by: board and topic pagination templates.
 *
 * @param mixed $urlPattern URL containing a {page} placeholder.
 * @param mixed $currentPage Current 1-based page number.
 * @param mixed $totalPages Total page count.
 * @param string $linkClass CSS class applied to controls.
 * @return string Previous/next/reload HTML fragment, or empty for one page.
 */
function corebb_prev_next_reload_html($urlPattern, $currentPage, $totalPages, $linkClass = 'MainMenuFont'){
    $currentPage = max(1, (int)$currentPage);
    $totalPages = max(1, (int)$totalPages);
    if($totalPages <= 1){
        return '';
    }

    $safeClass = htmlspecialchars($linkClass, ENT_QUOTES);
    $prev = $currentPage - 1;
    $next = $currentPage + 1;
    $reloadUrl = str_replace('{page}', (string)$currentPage, $urlPattern);

    $html = "&nbsp;<a class='$safeClass'>-</a>&nbsp;";
    if($prev >= 1){
        $prevUrl = str_replace('{page}', (string)$prev, $urlPattern);
        $html .= "<a href='" . htmlspecialchars($prevUrl, ENT_QUOTES) . "' class='$safeClass'>Previous</a>";
    }else{
        $html .= "<a class='$safeClass'><strike>Previous</strike></a>";
    }
    $html .= " <a class='$safeClass'>|</a> ";
    if($next <= $totalPages){
        $nextUrl = str_replace('{page}', (string)$next, $urlPattern);
        $html .= "<a href='" . htmlspecialchars($nextUrl, ENT_QUOTES) . "' class='$safeClass'>Next</a>";
    }else{
        $html .= "<a class='$safeClass'><strike>Next</strike></a>";
    }
    $html .= " <a class='$safeClass'>|</a> <a class='$safeClass' href='" . htmlspecialchars($reloadUrl, ENT_QUOTES) . "'>Reload</a>";
    return $html;
}



/* VN-style pretty URL helpers.
 * Querystring-style internal route targets still work; these keep public-facing
 * links on rewritten board, topic, and post paths in normal browsing.
 */
/**
 * Usage: Detect the installed forum base path from SCRIPT_NAME.
 * Referenced by: corebb_pretty_path().
 *
 * @return string Base path without a trailing slash, or empty at web root.
 */
function corebb_url_base_path(): string {
    if (function_exists('corebb_public_base_path')) {
        $base = rtrim(corebb_public_base_path(), '/');
        return $base === '/' ? '' : $base;
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = trim(dirname($script), '/');
    if ($dir === '.' || $dir === '') {
        return '';
    }
    foreach (['controllers', 'mobile'] as $internalDir) {
        if ($dir === $internalDir) {
            return '';
        }
        if (str_ends_with($dir, '/' . $internalDir)) {
            $dir = substr($dir, 0, -1 * (strlen($internalDir) + 1));
        }
    }
    if ($dir === 'api/v1') {
        $dir = '';
    } elseif (str_ends_with($dir, '/api/v1')) {
        $dir = trim(substr($dir, 0, -7), '/');
    }
    if ($dir === '') {
        return '';
    }
    return '/' . $dir;
}

/**
 * Usage: Prefix a pretty-route path with the detected forum base path.
 * Referenced by: board/thread/post URL builders.
 *
 * @param string $path Pretty route path relative to the forum root.
 * @return string Root-relative pretty URL.
 */
function corebb_pretty_path(string $path): string {
    return corebb_url_base_path() . '/' . ltrim($path, '/');
}

/**
 * Usage: Collapse a board/topic label into the historical compact URL slug.
 * Referenced by: corebb_board_url() and corebb_thread_url().
 *
 * @param string $name Display name to slugify.
 * @param string $fallback Slug to use when the name has no valid characters.
 * @return string Lowercase alphanumeric slug.
 */
function corebb_url_slug(string $name, string $fallback = 'boards'): string {
    $name = strtolower(trim(strip_tags($name)));
    $name = preg_replace('/&[a-z0-9#]+;/i', '', $name);
    $name = preg_replace('/[^a-z0-9]+/i', '', $name);
    return $name !== '' ? $name : $fallback;
}

/**
 * Usage: Build a pretty URL for a board page.
 * Referenced by: board listings, breadcrumbs, and legacy URL normalization.
 *
 * @param int $boardId Forum board id.
 * @param int $page 1-based page number.
 * @param string $boardName Optional display name for the compact slug.
 * @return string Pretty board URL, or index.php for invalid board ids.
 */
function corebb_board_url(int $boardId, int $page = 1, string $boardName = ''): string {
    $boardId = max(0, $boardId);
    $page = max(1, $page);
    if ($boardId <= 0) {
        return 'index.php';
    }
    $slug = corebb_url_slug($boardName, 'board');
    $suffix = $page > 1 ? 'p' . $page . '/' : '';
    return corebb_pretty_path($slug . '/b' . $boardId . '/' . $suffix);
}

/**
 * Usage: Build a pretty URL for a topic page, optionally anchored to a post.
 * Referenced by: topic lists, post links, redirects, and URL normalization.
 *
 * @param int $topicId Topic id.
 * @param int $boardId Board id; resolved when omitted and possible.
 * @param int $page 1-based page number.
 * @param string $boardName Optional display name for the compact slug.
 * @param int $postId Optional post id anchor.
 * @return string Pretty topic URL.
 */
function corebb_thread_url(int $topicId, int $boardId = 0, int $page = 1, string $boardName = '', int $postId = 0): string {
    $topicId = max(0, $topicId);
    $boardId = max(0, $boardId);
    $page = max(1, $page);
    if ($topicId <= 0) {
        return 'index.php';
    }
    if ($boardId <= 0) {
        $boardId = function_exists('corebb_topic_board_id') ? (int)corebb_topic_board_id($topicId) : 0;
    }
    if ($boardId <= 0) {
        $url = corebb_pretty_path('topic/' . $topicId . '/p' . $page . '/');
        return $postId > 0 ? $url . '#post' . $postId : $url;
    }
    $slug = corebb_url_slug($boardName, 'board');
    $url = corebb_pretty_path($slug . '/b' . $boardId . '/' . $topicId . '/p' . $page . '/');
    return $postId > 0 ? $url . '#post' . $postId : $url;
}

/**
 * Usage: Build the reply composer URL for a topic.
 * Referenced by: thread action links and quote-reply controls.
 *
 * @param int $topicId Topic id being replied to.
 * @param int $boardId Board id containing the topic.
 * @param int $quotePostId Optional post id to quote.
 * @return string Pretty reply URL, or legacy fallback when ids are invalid.
 */
function corebb_reply_url(int $topicId, int $boardId, int $quotePostId = 0): string {
    $topicId = max(0, $topicId);
    $boardId = max(0, $boardId);
    if ($topicId <= 0 || $boardId <= 0) {
        return corebb_pretty_path('post/reply/' . $topicId . '/b' . $boardId . '/' . ($quotePostId > 0 ? 'q' . (int)$quotePostId . '/' : ''));
    }
    $path = 'post/reply/' . $topicId . '/b' . $boardId . '/';
    if ($quotePostId > 0) {
        $path .= 'q' . (int)$quotePostId . '/';
    }
    return corebb_pretty_path($path);
}

/**
 * Usage: Build the new-topic composer URL for a board.
 * Referenced by: board action links.
 *
 * @param int $boardId Board id receiving the new topic.
 * @return string Pretty new-topic URL.
 */
function corebb_new_topic_url(int $boardId): string {
    $boardId = max(0, $boardId);
    return corebb_pretty_path('post/new/b' . $boardId . '/');
}

/**
 * Usage: Build the new-poll composer URL for a board.
 * Referenced by: board action links.
 *
 * @param int $boardId Board id receiving the new poll.
 * @return string Pretty new-poll URL.
 */
function corebb_new_poll_url(int $boardId): string {
    $boardId = max(0, $boardId);
    return corebb_pretty_path('post/new/b' . $boardId . '/poll/');
}

/**
 * Usage: Build the post edit URL and preserve moderator mode when needed.
 * Referenced by: post action links and moderator workflows.
 *
 * @param int $postId Post id to edit.
 * @param bool $moderator Whether to append moderator-edit mode.
 * @return string Pretty edit-post URL.
 */
function corebb_edit_post_url(int $postId, bool $moderator = false): string {
    $postId = max(0, $postId);
    $url = corebb_pretty_path('post/edit/' . $postId . '/');
    return $moderator ? $url . '?mod=1' : $url;
}

/**
 * Usage: Render the compact page links beside topic titles.
 * Referenced by: legacy board/topic listing code.
 *
 * @param mixed $resultsper Posts per page.
 * @param mixed $threadid Topic id to count posts for.
 * @return string Topic-page link HTML fragment, or empty for single-page topics.
 */
function buildpages($resultsper, $threadid){
    $resultsperpage = max(1, (int)$resultsper);
    $threadid = (int)$threadid;
    $resultcount = corebb_topic_post_count($threadid);
    if($resultcount <= $resultsperpage){
        return "";
    }

    $boardid = corebb_topic_board_id($threadid);
    $totalPages = (int)ceil($resultcount / $resultsperpage);
    $urlPattern = str_replace('/p999999/', '/p{page}/', corebb_thread_url($threadid, $boardid, 999999));
    return function_exists('corebb_vn_topic_page_brackets_html')
        ? corebb_vn_topic_page_brackets_html($urlPattern, $totalPages, 'SmallText', 1)
        : corebb_compact_pagination_html($urlPattern, 1, $totalPages, 'SmallText', '', ' ', 1);
}

/**
 * Usage: Run a scalar COUNT-style query for admin-log schema checks.
 * Referenced by: adminlogs table, column, key, and index detection helpers.
 *
 * @param string $sql SQL returning a scalar numeric value.
 * @param array<int, mixed> $params Bound query parameters.
 * @return int Scalar result cast to an integer.
 */
function corebb_adminlogs_scalar_count(string $sql, array $params = []): int
{
    return (int)db_value($sql, $params, 0);
}

/**
 * Usage: Check whether the adminlogs table exists.
 * Referenced by: corebb_adminlogs_ensure_schema().
 *
 * @return bool True when adminlogs is available in the current database.
 */
function corebb_adminlogs_table_exists(): bool
{
    $count = corebb_adminlogs_scalar_count(
        'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        ['adminlogs']
    );
    if ($count > 0) {
        return true;
    }

    // Fallback for hosts with restricted INFORMATION_SCHEMA access.
    return db_exists('SHOW TABLES LIKE ?', ['adminlogs']);
}

/**
 * Usage: Check whether an adminlogs column exists before selecting or writing it.
 * Referenced by: schema migration, insert filtering, and select expression helpers.
 *
 * @param string $column Adminlogs column name.
 * @return bool True when the column exists.
 */
function corebb_adminlogs_column_exists(string $column): bool
{
    $column = trim($column);
    if ($column === '') {
        return false;
    }

    $count = corebb_adminlogs_scalar_count(
        'SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['adminlogs', $column]
    );
    if ($count > 0) {
        return true;
    }

    // Do not use result-row counting for SHOW COLUMNS here; PDO rowCount can be
    // unreliable for metadata SELECT-like statements.
    return db_exists('SHOW COLUMNS FROM `adminlogs` LIKE ?', [$column]);
}

/**
 * Usage: Detect whether adminlogs already has a primary key.
 * Referenced by: corebb_adminlogs_ensure_schema().
 *
 * @return bool True when a primary key is present.
 */
function corebb_adminlogs_primary_key_exists(): bool
{
    return corebb_adminlogs_scalar_count(
        "SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'adminlogs' AND INDEX_NAME = 'PRIMARY'"
    ) > 0;
}

/**
 * Usage: Detect whether a named adminlogs index exists.
 * Referenced by: corebb_adminlogs_ensure_schema().
 *
 * @param string $index Index name to check.
 * @return bool True when the index exists.
 */
function corebb_adminlogs_index_exists(string $index): bool
{
    $index = trim($index);
    if ($index === '') {
        return false;
    }
    return corebb_adminlogs_scalar_count(
        'SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
        ['adminlogs', $index]
    ) > 0;
}

/**
 * Usage: Run best-effort adminlogs migration SQL.
 * Referenced by: corebb_adminlogs_ensure_schema().
 *
 * @param string $sql DDL statement to execute.
 * @return bool True when the statement succeeds.
 */
function corebb_adminlogs_try_exec(string $sql): bool
{
    return db_run($sql);
}

/**
 * Usage: Create or upgrade adminlogs so current admin pages can read/write it.
 * Referenced by: corebb_adminlogs_insert() and admin log viewers.
 *
 * @return void
 */
function corebb_adminlogs_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!corebb_adminlogs_table_exists()) {
        db_run("CREATE TABLE IF NOT EXISTS `adminlogs` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `userid` INT NOT NULL DEFAULT 0,
            `userlevel` VARCHAR(255) NOT NULL DEFAULT '',
            `action` TEXT NULL,
            `admin_username` VARCHAR(255) NOT NULL DEFAULT '',
            `ip_address` VARCHAR(64) NOT NULL DEFAULT '',
            `action_type` VARCHAR(80) NOT NULL DEFAULT '',
            `description` TEXT NULL,
            `date_performed` DATETIME NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return;
    }

    /*
     * Old CoreBB/VN imports may already have adminlogs, but without the id or
     * metadata columns that the rebuilt dashboard/action-log pages now read.
     * A previous version used result-row counting on SHOW COLUMNS/TABLES, which can
     * report 0 under PDO even when the row exists. Use INFORMATION_SCHEMA above
     * and make the migration idempotent here. Lipstick on a pig!
     */
    if (!corebb_adminlogs_column_exists('id')) {
        if (corebb_adminlogs_primary_key_exists()) {
            corebb_adminlogs_try_exec("ALTER TABLE `adminlogs` ADD `id` INT NOT NULL AUTO_INCREMENT FIRST, ADD KEY `idx_adminlogs_id` (`id`)");
        } else {
            $addedPrimaryId = corebb_adminlogs_try_exec("ALTER TABLE `adminlogs` ADD `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
            if (!$addedPrimaryId && !corebb_adminlogs_column_exists('id')) {
                corebb_adminlogs_try_exec("ALTER TABLE `adminlogs` ADD `id` INT NOT NULL AUTO_INCREMENT FIRST, ADD KEY `idx_adminlogs_id` (`id`)");
            }
        }
    } elseif (!corebb_adminlogs_index_exists('idx_adminlogs_id') && !corebb_adminlogs_primary_key_exists()) {
        corebb_adminlogs_try_exec("ALTER TABLE `adminlogs` ADD KEY `idx_adminlogs_id` (`id`)");
    }

    $columns = [
        'userid' => "ALTER TABLE `adminlogs` ADD `userid` INT NOT NULL DEFAULT 0",
        'userlevel' => "ALTER TABLE `adminlogs` ADD `userlevel` VARCHAR(255) NOT NULL DEFAULT ''",
        'action' => "ALTER TABLE `adminlogs` ADD `action` TEXT NULL",
        'admin_username' => "ALTER TABLE `adminlogs` ADD `admin_username` VARCHAR(255) NOT NULL DEFAULT ''",
        'ip_address' => "ALTER TABLE `adminlogs` ADD `ip_address` VARCHAR(64) NOT NULL DEFAULT ''",
        'action_type' => "ALTER TABLE `adminlogs` ADD `action_type` VARCHAR(80) NOT NULL DEFAULT ''",
        'description' => "ALTER TABLE `adminlogs` ADD `description` TEXT NULL",
        'date_performed' => "ALTER TABLE `adminlogs` ADD `date_performed` DATETIME NULL",
    ];

    foreach ($columns as $column => $sql) {
        if (!corebb_adminlogs_column_exists($column)) {
            corebb_adminlogs_try_exec($sql);
        }
    }

    if (corebb_adminlogs_column_exists('description') && corebb_adminlogs_column_exists('action')) {
        db_run("UPDATE `adminlogs` SET `description` = `action` WHERE (`description` IS NULL OR `description` = '') AND `action` IS NOT NULL");
    }
    if (corebb_adminlogs_column_exists('admin_username') && corebb_adminlogs_column_exists('userid')) {
        db_run("UPDATE `adminlogs` al JOIN `users` u ON u.id = al.userid SET al.admin_username = u.username WHERE al.admin_username = '' AND al.userid > 0");
    }
}

/**
 * Usage: Insert an admin log row while tolerating older partial schemas.
 * Referenced by: addlogentry().
 *
 * @param array<string, mixed> $values Column/value pairs for the new row.
 * @return bool True when an insert was attempted and succeeded.
 */
function corebb_adminlogs_insert(array $values): bool
{
    corebb_adminlogs_ensure_schema();

    $columns = [];
    $params = [];
    foreach ($values as $column => $value) {
        if (corebb_adminlogs_column_exists((string)$column)) {
            $columns[] = '`' . str_replace('`', '``', (string)$column) . '`';
            $params[] = $value;
        }
    }

    if (!$columns) {
        return false;
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO `adminlogs` (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
    return db_run($sql, $params);
}

/**
 * Usage: Choose a safe SELECT expression for optional adminlogs columns.
 * Referenced by: admin log list queries.
 *
 * @param string $column Preferred adminlogs column name.
 * @param string $fallbackSql SQL expression to use when the column is absent.
 * @return string SQL expression suitable for a SELECT list.
 */
function corebb_adminlogs_select_expr(string $column, string $fallbackSql): string
{
    return corebb_adminlogs_column_exists($column)
        ? 'al.`' . str_replace('`', '``', $column) . '`'
        : $fallbackSql;
}

/**
 * Usage: Return the admin-log date expression used by list/read queries.
 * Referenced by: admin log view models.
 *
 * @param string $alias Table alias kept for call-site readability.
 * @return string SQL expression for the log date, or an empty fallback.
 */
function corebb_adminlogs_effective_date_expr(string $alias = 'al'): string
{
    return corebb_adminlogs_select_expr('date_performed', "''");
}

/**
 * Usage: Pick a stable ORDER BY clause for whatever adminlogs schema exists.
 * Referenced by: admin log listing queries.
 *
 * @return string SQL ORDER BY expression, without the ORDER BY keyword.
 */
function corebb_adminlogs_order_sql(): string
{
    if (corebb_adminlogs_column_exists('id')) {
        return 'al.`id` DESC';
    }
    if (corebb_adminlogs_column_exists('date_performed')) {
        return 'al.`date_performed` DESC';
    }
    if (corebb_adminlogs_column_exists('userid')) {
        return 'al.`userid` DESC';
    }
    return '1 DESC';
}

/**
 * Usage: Convert a human admin action label into a compact action type key.
 * Referenced by: addlogentry() when callers do not provide an explicit type.
 *
 * @param string $action Human-readable admin action.
 * @return string Stable action type key up to 80 characters.
 */
function corebb_adminlog_action_type(string $action): string
{
    $text = strtolower(trim($action));
    if ($text === '') {
        return 'admin_action';
    }

    $map = [
        'unban request' => 'unban_request_response',
        'unbanned user' => 'unban_user',
        'banned user' => 'ban_user',
        'ban user' => 'ban_user',
        'added admin note' => 'admin_note',
        'admin note' => 'admin_note',
        'avatar' => 'manage_user_icons',
        'icon' => 'manage_user_icons',
        'global message' => 'global_message',
        'modified system settings' => 'edit_system_settings',
        'modified the system tos' => 'edit_system_tos',
        'modified the system style' => 'edit_system_style',
        'destroyed admin session' => 'admin_session',
        'viewed admin message' => 'view_message',
        'viewed post message' => 'view_message',
        'viewed user portal' => 'user_pages',
        'user ip check' => 'user_ip_check',
        'host address lookup' => 'host_lookup',
        'assigned title' => 'assign_title',
        'cleared title' => 'assign_title',
        'user title cleared' => 'user_pages',
        'user signature cleared' => 'user_pages',
        'user icon removed' => 'user_pages',
        'removed post' => 'moderate_content',
        'removed topic' => 'moderate_content',
        'moved post' => 'moderate_content',
        'moved topic' => 'moderate_content',
        'restored deleted post' => 'moderate_content',
        'permanently purged deleted post' => 'moderate_content',
        'locked topic' => 'moderate_content',
        'unlocked topic' => 'moderate_content',
        'closed mod request' => 'mod_request_closed',
        'reopened mod request' => 'mod_request_reopened',
        'changed vip username colors' => 'change_user_style',
        'private message report' => 'pm_moderation',
        'deleted private message' => 'pm_delete',
        'restored private message' => 'pm_restore',
        'cancelled private message report' => 'pm_report_cancelled',
        'changed user' => 'edit_user',
        'added new user' => 'add_user',
        'added board' => 'add_board',
        'modified board' => 'modify_board',
        'deleted board' => 'delete_board',
        'moved board' => 'move_board',
        'moved contents' => 'move_board',
        'added category' => 'add_category',
        'modified category' => 'modify_category',
        'deleted category' => 'delete_category',
    ];

    foreach ($map as $needle => $type) {
        if (strpos($text, $needle) !== false) {
            return $type;
        }
    }

    $slug = preg_replace('/[^a-z0-9]+/', '_', $text);
    $slug = trim((string)$slug, '_');
    if ($slug === '') {
        return 'admin_action';
    }
    $parts = array_slice(explode('_', $slug), 0, 5);
    return substr(implode('_', $parts), 0, 80);
}

/**
 * Usage: Read the current request IP for admin log metadata.
 * Referenced by: addlogentry().
 *
 * @return string Remote IP capped for the adminlogs.ip_address column.
 */
function corebb_adminlog_remote_ip(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? substr($ip, 0, 64) : 'Unknown';
}

/**
 * Usage: Resolve a log actor id/name from either a user id or username.
 * Referenced by: addlogentry().
 *
 * @param mixed $userid User id, username, or legacy caller value.
 * @return array{0: int, 1: string} Actor id and display username.
 */
function corebb_adminlog_resolve_actor($userid): array
{
    $actorId = 0;
    $actorName = trim((string)$userid);

    if ($actorName !== '' && ctype_digit($actorName)) {
        $actorId = (int)$actorName;
        $row = db_one('SELECT username FROM users WHERE id = ? LIMIT 1', [$actorId]);
        if ($row && !empty($row['username'])) {
            $actorName = (string)$row['username'];
        }
    } elseif ($actorName !== '') {
        $row = db_one('SELECT id, username FROM users WHERE username = ? LIMIT 1', [$actorName]);
        if ($row) {
            $actorId = (int)($row['id'] ?? 0);
            $actorName = (string)($row['username'] ?? $actorName);
        }
    }

    if ($actorName === '') {
        $actorName = 'Unknown';
    }

    return [$actorId, $actorName];
}

/**
 * Usage: Write an admin log entry for an already-loaded viewer row.
 * Referenced by: admin controllers that keep the viewer record in scope.
 *
 * @param array<string, mixed> $viewer Current admin user/session row.
 * @param string $action Human-readable action label.
 * @param string $actionType Optional stable action type key.
 * @param string $description Optional detailed description.
 * @return void
 */
function corebb_adminlog_viewer(array $viewer, string $action, string $actionType = '', string $description = ''): void
{
    if (!function_exists('addlogentry')) {
        return;
    }
    $level = (int)($viewer['accesslevel'] ?? 0);
    $actor = $viewer['id'] ?? $viewer['userid'] ?? $viewer['username'] ?? 'Unknown';
    addlogentry((string)$actor, $level, $action, $actionType, $description);
}

/**
 * Usage: Record an admin action in the normalized adminlogs table.
 * Referenced by: legacy admin pages and newer admin controllers.
 *
 * @param mixed $userid User id or username performing the action.
 * @param mixed $userlevel Actor access level at the time of the action.
 * @param mixed $action Human-readable action label.
 * @param string $actionType Optional stable action type key.
 * @param string $description Optional detailed description.
 * @return void
 */
function addlogentry($userid, $userlevel, $action, $actionType = '', $description = ''){
    [$actorId, $actorName] = corebb_adminlog_resolve_actor($userid);
    $level = (string)$userlevel;
    $action = (string)$action;
    $description = trim((string)$description) !== '' ? (string)$description : $action;
    $actionType = trim((string)$actionType) !== '' ? trim((string)$actionType) : corebb_adminlog_action_type($action);
    $ip = corebb_adminlog_remote_ip();
    $date = date('Y-m-d H:i:s');

    corebb_adminlogs_insert([
        'userid' => $actorId,
        'userlevel' => $level,
        'action' => $action,
        'admin_username' => $actorName,
        'ip_address' => $ip,
        'action_type' => $actionType,
        'description' => $description,
        'date_performed' => $date,
    ]);
}

/**
 * Usage: Convert a numeric access level into a profile/admin display label.
 * Referenced by: profile display and legacy admin user views.
 *
 * @param mixed $level Numeric access level.
 * @return string Human-readable level label.
 */
function LoadUserLevel($level){
	if($level == 5){
		return "Administrator";
	}
	else if($level == 4){
		return "Manager";
	}
	else if($level == 3){
		return "Moderator";
	}
	else if($level == 2){
		return "VIP";
	}
	else if($level == 1){
		return "User";
	}
	else{
		return "Unknown";
	}
}

/**
 * Usage: Load one user row by id or username.
 * Referenced by: profile, login/session, admin, and legacy helper code.
 *
 * @param mixed $user User id by default, or username when non-numeric/forced.
 * @param bool $force_str Treat $user as a username even when numeric text.
 * @return array<string, mixed>|false User row or false when not found.
 */
function LoadUserData($user, $force_str = false){
	if (!is_numeric($user) || $force_str){
		$isnumeric = false;
	}
	else{
		$user = intval($user);
		$isnumeric = true;
	}
	//It's the username, not a number.
	if(!$isnumeric){
		$User_Data_Query_String = "SELECT * FROM `users` WHERE `username` = ?";
		$User_Data_Query_Params = [(string)$user];
	}
	//It is a number.
	else if($isnumeric){
		$User_Data_Query_String = "SELECT * FROM `users` WHERE `id` = ?";
		$User_Data_Query_Params = [(int)$user];
	}
	else{
		die("Unexpected error in LoadUserData function!");
	}
	return db_one($User_Data_Query_String . ' LIMIT 1', $User_Data_Query_Params);
}

/**
 * Usage: Echo the request referer for old diagnostic/admin pages.
 * Referenced by: legacy utilities.
 *
 * @return void
 */
function LoadReferer(){
    echo htmlspecialchars($_SERVER['HTTP_REFERER'] ?? 'No referer supplied.', ENT_QUOTES, 'UTF-8');
}

/**
 * Usage: Check whether a short text value contains only the legacy allowed set.
 * Referenced by: older form-validation paths.
 *
 * @param mixed $text Text to validate.
 * @return bool True when the text matches the legacy character whitelist.
 */
function validate($text){ 
	if(preg_match("/[^a-zA-Z0-9\s.,!'_]/i", (string)$text)){
		return false; 
	} 
	else{ 
		return true;
	} 
}


/**
 * Usage: Set a long-lived forum cookie through the shared security helper when available.
 * Referenced by: login/session compatibility code.
 *
 * @param mixed $Name Cookie name.
 * @param mixed $Data Cookie value.
 * @return bool True when PHP accepts the cookie header.
 */
function MakeCookie($Name, $Data){
	$time = "1029600";
	$expiretime = time() + $time;
	
	if(function_exists('corebb_security_set_cookie') ? corebb_security_set_cookie($Name, (string)$Data, $expiretime, "/", $CookieDomain ?? '') : setcookie($Name,$Data, $expiretime, "/", $CookieDomain ?? '') ){
		return true;
	}
	else{
		return false;
	}
}

/**
 * Usage: Read a boolean board/system setting from the database.
 * Referenced by: global feature toggles and legacy settings checks.
 *
 * @param mixed $setting Setting name in systemsettings.name.
 * @return bool True for enabled truthy values; false for missing/disabled values.
 */
function LoadBoardSetting($setting){
	$value = db_value("SELECT setting FROM `systemsettings` WHERE `name` = ? LIMIT 1", [(string)$setting], null);
	if($value === null){
		return false;
	}
	return ($value === true || $value === 1 || $value === '1' || strtolower((string)$value) === 'true' || strtolower((string)$value) === 'yes' || strtolower((string)$value) === 'on');
}


/**
 * Usage: Create a forum user from registration/admin-supplied basics.
 * Referenced by: registration and legacy admin user creation paths.
 *
 * @param mixed $Name Requested username.
 * @param mixed $Email Private/account email address.
 * @param mixed $Password Raw password to hash.
 * @return bool True when the user row is created.
 */
function CreateUser($Name, $Email, $Password){
	global $CreateUserOut;

	$Name = trim((string)$Name);
	$Email = trim((string)$Email);
	$PasswordRaw = (string)$Password;

	if ($Name === '' || $Email === '' || $PasswordRaw === '') {
		$CreateUserOut = "One or more required fields were left empty.";
		return false;
	}
	if (!preg_match('/^[A-Za-z0-9_\- ]{3,20}$/', $Name)) {
		$CreateUserOut = "Invalid username.";
		return false;
	}
	if (!filter_var($Email, FILTER_VALIDATE_EMAIL)) {
		$CreateUserOut = "Invalid email address.";
		return false;
	}

	corebb_auth_ensure_schema();
	$PasswordHash = corebb_auth_password_hash($PasswordRaw);
	$CurrentDate = date('M y');

	if(db_exists("SELECT id FROM users WHERE username = ? LIMIT 1", [$Name])){
		$CreateUserOut = "Username already exists!";
		return false;
	}

	if(db_exists("SELECT id FROM users WHERE privemail = ? LIMIT 1", [$Email])){
		$CreateUserOut = "User with that email address already exists!";
		return false;
	}

	$queryStr = "INSERT INTO users (username, password, regdate, accesslevel, privemail, ThreadPages, BoardPages, posts) VALUES (?, ?, ?, 1, ?, 25, 25, 0)";
	if(db_run($queryStr, [$Name, $PasswordHash, $CurrentDate, $Email])){
		$GLOBALS['CreateUserID'] = (int)db_insert_id();
		$CreateUserOut = "";
		return true;
	}

	$CreateUserOut = "Error running query: ". db_error();
	return false;
}

/**
 * Usage: Return the first N whitespace-separated words plus the legacy ellipsis.
 * Referenced by: older summary/list display code.
 *
 * @param string $String Text to shorten.
 * @param int $Limit Maximum number of words to keep.
 * @return string Shortened text with "....." appended.
 */
function TrimStr($String, $Limit){
    $StringArr = explode(" ", $String);
    for($i=0; $i <= $Limit-1; $i++){
        $TrimStr .= $StringArr[$i] ." ";
    }
	$TrimStr = trim($TrimStr);
    return $TrimStr . ".....";
}

/**
 * Usage: Convert a numeric user level into the legacy chat prefix.
 * Referenced by: chat system compatibility code.
 *
 * @param mixed $userlevel Numeric access level.
 * @return string|null Chat prefix, or null for unknown levels.
 */
function decipherlevel($userlevel){
	if($userlevel >= 5){
		return "[Admin]";
	}
	elseif($userlevel = 4){
		return "[Manager]";
	}
	elseif($userlevel = 3){
		return "[Mod]";
	}
	elseif($userlevel = 2){
		return "[VIP]";
	}
	elseif($userlevel = 1){
		return "";
	}
}
?>
