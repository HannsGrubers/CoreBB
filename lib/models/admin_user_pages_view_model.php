<?php
require_once __DIR__ . '/../helpers/admin_log_helpers.php';
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
 |  admin_user_pages_view_model.php  - Admin User Pages  |
 |  / User Portal tool.                                  |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_user_tools_view_model.php';
require_once __DIR__ . '/admin_user_notes_view_model.php';
require_once __DIR__ . '/../helpers/moderation_helpers.php';
require_once __DIR__ . '/../helpers/private_board_helpers.php';

const COREBB_ADMIN_USER_PAGES_RECENT_LIMIT = 5;

/**
 * Usage: Check whether a user-pages column exists.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $table Database table name.
 * @param string $column Database column name.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_user_pages_column_exists(string $table, string $column): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }
    return isset(corebb_admin_table_columns($table)[$column]);
}

/**
 * Usage: Return the form token for this admin page.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_user_pages_token(): string
{
    return corebb_security_named_token('admin_user_pages_token');
}

/**
 * Usage: Validate the form token before accepting an admin mutation.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $post Posted form data from admin.php.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_user_pages_token_ok(array $post): bool
{
    return corebb_security_named_token_valid('admin_user_pages_token', $post, 'admin_user_pages_token');
}

/**
 * Usage: Map each inline User Portal write command to the admin tool that owns it.
 * Referenced by: write authorization and template capability flags.
 *
 * @param string $action Human-readable action message.
 * @return string Required admin tool key, or an empty string for unknown actions.
 */
function corebb_user_pages_action_tool(string $action): string
{
    return match ($action) {
        'clear_title' => 'assign_title',
        'clear_signature' => 'edit_profile',
        'remove_icon' => 'manage_icons',
        default => '',
    };
}

/**
 * Usage: Check whether the current admin can use one named tool.
 * Referenced by: User Portal action guards and UI capability flags.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param string $toolKey Permission-tool key.
 * @return bool True when the viewer has level-based or explicit access.
 */
function corebb_user_pages_can_use_tool(array $viewer, string $toolKey): bool
{
    return $toolKey !== '' && corebb_admin_can_access_tool($viewer, $toolKey);
}

/**
 * Usage: Return a human-readable authorization error for an inline write command.
 * Referenced by: corebb_admin_user_pages_model() before any user row is changed.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed>|null $target Target user row.
 * @param string $action Human-readable action message.
 * @return string Empty when allowed, otherwise a user-facing error.
 */
function corebb_user_pages_action_error(array $viewer, ?array $target, string $action): string
{
    $toolKey = corebb_user_pages_action_tool($action);
    if ($toolKey === '') {
        return 'Unknown user page action.';
    }
    if (!corebb_user_pages_can_use_tool($viewer, $toolKey)) {
        return 'You do not have permission to perform this user action.';
    }
    $targetError = corebb_admin_target_content_error($viewer, $target);
    return $targetError !== '' ? $targetError : '';
}

/**
 * Usage: Find the target user for the user portal.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_user_pages_find_user(array $request, array $post): array
{
    $username = trim((string)(
        $request['username']
        ?? $request['usr']
        ?? $request['name']
        ?? $post['username']
        ?? $post['usr']
        ?? ''
    ));

    $userId = trim((string)(
        $request['userid']
        ?? $request['uid']
        ?? $request['usrid']
        ?? $post['userid']
        ?? $post['uid']
        ?? $post['usrid']
        ?? ''
    ));

    $selected = null;
    if ($userId !== '' && ctype_digit($userId)) {
        $selected = corebb_admin_find_user($userId);
    }
    if (!$selected && $username !== '') {
        $selected = corebb_admin_find_user($username);
    }

    return [$username, $userId, $selected];
}

/**
 * Usage: Format a stored value for admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @return string Normalized or display-ready string.
 */
function corebb_user_pages_format_full_date(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }
    return date('n/j/Y g:i:s A', $ts);
}

/**
 * Usage: Render a compact boolean value for the user portal.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param mixed $value Raw value to normalize.
 * @return string Normalized or display-ready string.
 */
function corebb_user_pages_plain_bool($value): string
{
    if (is_bool($value)) {
        return $value ? 'True' : 'False';
    }
    $v = strtolower(trim((string)$value));
    return in_array($v, ['1', 'true', 'yes', 'y', 'on'], true) ? 'True' : 'False';
}

/**
 * Usage: Render a yes/no value for the user portal.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param mixed $value Raw value to normalize.
 * @return string Normalized or display-ready string.
 */
function corebb_user_pages_yes_no($value): string
{
    return corebb_user_pages_plain_bool($value);
}

/**
 * Usage: Normalize an IP value for the user portal.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $ip IP address.
 * @return string Normalized or display-ready string.
 */
function corebb_user_pages_display_ip(string $ip): string
{
    $ip = trim($ip);
    if (preg_match('/^\d{1,3}(?:-\d{1,3}){3}$/', $ip)) {
        return str_replace('-', '.', $ip);
    }
    return $ip;
}

/**
 * Usage: Count records for the admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $userId User id.
 * @return int Numeric result for the caller.
 */
function corebb_user_pages_actual_post_count(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    return (int)db_value('SELECT COUNT(*) FROM posts WHERE posterid = ? AND is_deleted = 0', [$userId], 0);
}

/**
 * Usage: Build a compact display summary for Twig.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $user User row being displayed or edited.
 * @return ?array Data row when found, otherwise null.
 */
function corebb_user_pages_icon_summary(array $user): ?array
{
    $iconId = (int)($user['iconid'] ?? 0);
    if ($iconId <= 0) {
        return null;
    }

    $icon = db_one('SELECT ' . corebb_admin_select_columns('icons', ['id', 'filepath', 'filename', 'approved', 'uploaded'], '') . ' FROM icons WHERE id = ? LIMIT 1', [$iconId]);
    if (!$icon) {
        return ['id' => $iconId, 'filepath' => '', 'filename' => '', 'approved' => 0];
    }

    $approved = array_key_exists('approved', $icon) ? (int)$icon['approved'] : 1;
    return [
        'id' => (int)($icon['id'] ?? $iconId),
        'filepath' => (string)($icon['filepath'] ?? ''),
        'src' => $approved ? corebb_safe_local_image_asset((string)($icon['filepath'] ?? ''), ['images']) : '',
        'filename' => (string)($icon['filename'] ?? ''),
        'approved' => $approved,
        'uploaded' => (int)($icon['uploaded'] ?? 0),
    ];
}

/**
 * Usage: Prepare a user signature preview for the user portal.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $user User row being displayed or edited.
 * @return string Normalized or display-ready string.
 */
function corebb_user_pages_signature(array $user): string
{
    $signature = trim((string)($user['signature'] ?? ''));
    if ($signature !== '') {
        return $signature;
    }

    $lines = [];
    for ($i = 1; $i <= 5; $i++) {
        $value = trim((string)($user['sig' . $i] ?? ''));
        if ($value !== '' && $value !== '$') {
            $lines[] = $value;
        }
    }
    return implode("\n", $lines);
}

/**
 * Usage: Prepare a user title preview for the user portal.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $user User row being displayed or edited.
 * @return string Normalized or display-ready string.
 */
function corebb_user_pages_title(array $user): string
{
    $profileTitle = trim((string)($user['profiletitle'] ?? ''));
    if ($profileTitle !== '') {
        return $profileTitle;
    }
    return trim((string)($user['title'] ?? ''));
}

/**
 * Usage: Resolve the display label for a user access level.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $user User row being displayed or edited.
 * @return string Normalized or display-ready string.
 */
function corebb_user_pages_user_type(array $user): string
{
    $level = (int)($user['accesslevel'] ?? 0);
    return (string)corebb_user_level_label($level);
}

/**
 * Usage: List explicit admin tool grants for a user.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $userId User id.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_user_pages_special_access(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $granted = array_fill_keys(corebb_admin_user_granted_tool_keys($userId), true);
    if (!$granted) {
        return [];
    }

    $tools = [];
    foreach (corebb_admin_tool_catalog() as $group => $items) {
        foreach ($items as $key => $item) {
            if (!isset($granted[$key])) {
                continue;
            }
            $tools[] = [
                'key' => (string)$key,
                'label' => (string)($item['label'] ?? $key),
                'group' => (string)$group,
            ];
        }
    }
    return $tools;
}

/**
 * Usage: Fetch the row used by this admin action.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $userId User id.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_user_pages_fetch_recent_posts(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    [$visibleSql, $visibleParams] = corebb_private_sql_visible_board_clause('f', 'b');
    $sql = 'SELECT ' . corebb_admin_select_columns('posts', ['id', 'threadid', 'boardid', 'title', 'body', 'posttime', 'posttimeraw'], 'p') . ', f.name AS board_name
            FROM posts p
            LEFT JOIN forums f ON f.id = p.boardid
            LEFT JOIN boards b ON b.id = f.categoryid
            WHERE p.posterid = ? AND p.is_deleted = 0 AND ' . $visibleSql . '
            ORDER BY p.id DESC
            LIMIT ' . COREBB_ADMIN_USER_PAGES_RECENT_LIMIT;
    $posts = [];
    foreach (db_all($sql, array_merge([$userId], $visibleParams)) as $row) {
        $postId = (int)($row['id'] ?? 0);
        $threadId = (int)($row['threadid'] ?? 0);
        $boardId = (int)($row['boardid'] ?? 0);
        $posts[] = [
            'id' => $postId,
            'threadid' => $threadId,
            'boardid' => $boardId,
            'title' => (string)($row['title'] ?? ''),
            'body' => (string)($row['body'] ?? ''),
            'board_name' => (string)($row['board_name'] ?? ''),
            'date_raw' => (string)($row['posttime'] ?? $row['posttimeraw'] ?? ''),
            'date_vn' => corebb_user_pages_format_full_date((string)($row['posttime'] ?? $row['posttimeraw'] ?? '')),
            'post_url' => $threadId > 0 ? corebb_thread_url($threadId, $boardId, 1, (string)($row['board_name'] ?? ''), $postId) : '/admin/?act=view_message&method=view&messageid=' . $postId,
            'board_url' => $boardId > 0 ? corebb_board_url($boardId, 1, (string)($row['board_name'] ?? '')) : '#',
            'view_url' => '/admin/?act=view_message&method=view&messageid=' . $postId,
        ];
    }
    return $posts;
}

/**
 * Usage: Fetch the row used by this admin action.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $userId User id.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_user_pages_fetch_recent_notes(int $userId): array
{
    corebb_admin_notes_ensure_schema();
    corebb_mod_ensure_schema();
    if ($userId <= 0) {
        return [];
    }

    $rows = db_all(
        'SELECT n.id, n.userid, n.note, n.reporterid, n.addtime, n.reason, u.username AS author_username, u.id AS author_id
         FROM adminnotes n
         LEFT JOIN users u ON u.id = n.reporterid
         WHERE n.userid = ?
         ORDER BY n.id DESC
         LIMIT ' . COREBB_ADMIN_USER_PAGES_RECENT_LIMIT,
        [$userId]
    );

    $notes = [];
    foreach ($rows as $row) {
        $authorId = (int)($row['author_id'] ?? $row['reporterid'] ?? 0);
        $notes[] = [
            'id' => (int)($row['id'] ?? 0),
            'userid' => (int)($row['userid'] ?? 0),
            'note' => (string)($row['note'] ?? ''),
            'reason' => (string)($row['reason'] ?? 'Misc'),
            'reporterid' => (int)($row['reporterid'] ?? 0),
            'author_id' => $authorId,
            'author_username' => (string)($row['author_username'] ?? ''),
            'addtime' => (string)($row['addtime'] ?? ''),
            'date_vn' => corebb_admin_notes_format_date((string)($row['addtime'] ?? '')),
        ];
    }
    return $notes;
}

/**
 * Usage: Apply one authorized inline User Portal cleanup action.
 * Referenced by: corebb_admin_user_pages_model() after token and permission checks.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $target Target user row to modify.
 * @param string $action Human-readable action message.
 * @return array{0: bool, 1: string} Result flag and user-facing message.
 */
function corebb_user_pages_clear_value(array $viewer, array $target, string $action): array
{
    $userId = (int)($target['id'] ?? 0);
    if ($userId <= 0) {
        return [false, 'Unknown user.'];
    }

    if ($action === 'clear_title') {
        $sets = [];
        $params = [];
        if (corebb_user_pages_column_exists('users', 'profiletitle')) { $sets[] = '`profiletitle` = ?'; $params[] = ''; }
        if (corebb_user_pages_column_exists('users', 'title')) { $sets[] = '`title` = ?'; $params[] = ''; }
        if (!$sets) { return [false, 'No title column exists.']; }
        $params[] = $userId;
        $ok = db_run('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        $msg = 'User title cleared.';
    } elseif ($action === 'clear_signature') {
        $sets = [];
        $params = [];
        if (corebb_user_pages_column_exists('users', 'signature')) { $sets[] = '`signature` = ?'; $params[] = null; }
        for ($i = 1; $i <= 5; $i++) {
            if (corebb_user_pages_column_exists('users', 'sig' . $i)) { $sets[] = '`sig' . $i . '` = ?'; $params[] = '$'; }
        }
        if (!$sets) { return [false, 'No signature columns exist.']; }
        $params[] = $userId;
        $ok = db_run('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        $msg = 'User signature cleared.';
    } elseif ($action === 'remove_icon') {
        $ok = db_run('UPDATE users SET iconid = 0 WHERE id = ?', [$userId]);
        $msg = 'User icon removed.';
    } else {
        return [false, 'Unknown user page action.'];
    }

    if (!$ok) {
        return [false, 'Error updating user: ' . db_error()];
    }

    {
        corebb_adminlog_entry((string)($viewer['username'] ?? $viewer['id'] ?? 'Unknown'), (int)($viewer['accesslevel'] ?? 0), $msg . ' User #' . $userId);
    }

    return [true, $msg];
}

/**
 * Usage: Build a compact display summary for Twig.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $user User row being displayed or edited.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_user_pages_summary(array $user): array
{
    $userId = (int)($user['id'] ?? 0);
    $username = (string)($user['username'] ?? '');
    $isBanned = ((string)($user['status'] ?? '') === '2');
    $isDisabled = array_key_exists('approved', $user) ? ((int)$user['approved'] !== 1) : false;
    $userType = corebb_user_pages_user_type($user);

    return [
        'id' => $userId,
        'username' => $username,
        'username_plain' => $username,
        'profile_url' => '/profile/' . $userId . '/',
        'public_profile_url' => '/profile/' . $userId . '/',
        'appearance_url' => '/admin/?act=user_appearance&userid=' . $userId,
        'remote_user_id' => (string)($user['legacy_remote_user_id'] ?? ''),
        'moderator_role' => $userType,
        'user_type' => $userType,
        'special_access' => corebb_user_pages_special_access($userId),
        'last_ip' => (string)($user['lastip'] ?? ''),
        'last_ip_display' => corebb_user_pages_display_ip((string)($user['lastip'] ?? '')),
        'banned' => $isBanned ? 'True' : 'False',
        'banned_raw' => $isBanned,
        'actual_post_count' => corebb_user_pages_actual_post_count($userId),
        'virtual_post_count' => (int)($user['posts'] ?? 0),
        'title' => corebb_user_pages_title($user),
        'icon' => corebb_user_pages_icon_summary($user),
        'signature' => corebb_user_pages_signature($user),
        'is_disabled' => $isDisabled ? 'True' : 'False',
        'date_account_added' => corebb_user_pages_format_full_date((string)($user['regdate'] ?? '')),
        'last_login_date' => corebb_user_pages_format_full_date((string)($user['lastlogindate'] ?? '')),
        'last_post_date' => corebb_user_pages_format_full_date((string)($user['lastpstdate'] ?? $user['lastpost'] ?? '')),
        'raw' => $user,
    ];
}

/**
 * Usage: Build and process the user pages admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_user_pages_model(array $viewer, array $request, array $post): array
{
    corebb_admin_notes_ensure_schema();
    corebb_mod_ensure_schema();

    $model = corebb_admin_require_model_base($viewer, 'User Portal', $request);
    $model['token'] = corebb_user_pages_token();
    $model['search'] = ['username' => '', 'userid' => ''];
    $model['selected_user'] = null;
    $model['recent_posts'] = [];
    $model['recent_notes'] = [];

    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $action = (string)($post['action'] ?? $request['action'] ?? '');

    [$username, $userId, $selectedUser] = corebb_user_pages_find_user($request, $post);

    if ($isPost && in_array($action, ['clear_title', 'clear_signature', 'remove_icon'], true)) {
        if (!corebb_user_pages_token_ok($post)) {
            $model['errors'][] = 'Security token expired. Please reload the user page and try again.';
        } elseif (!$selectedUser) {
            $model['errors'][] = 'No user selected.';
        } elseif (($error = corebb_user_pages_action_error($viewer, $selectedUser, $action)) !== '') {
            $model['errors'][] = $error;
        } else {
            [$ok, $message] = corebb_user_pages_clear_value($viewer, $selectedUser, $action);
            if ($ok) {
                $model['messages'][] = $message;
            } else {
                $model['errors'][] = $message;
            }
            $selectedUser = corebb_admin_find_user((string)$selectedUser['id']);
        }
    }

    if ($selectedUser) {
        $model['selected_user'] = corebb_user_pages_summary($selectedUser);
        $model['selected_user']['can_assign_title'] = corebb_user_pages_can_use_tool($viewer, 'assign_title')
            && corebb_admin_target_content_error($viewer, $selectedUser) === '';
        $model['selected_user']['can_edit_profile'] = corebb_user_pages_can_use_tool($viewer, 'edit_profile')
            && corebb_admin_target_content_error($viewer, $selectedUser) === '';
        $model['selected_user']['can_manage_icons'] = corebb_user_pages_can_use_tool($viewer, 'manage_icons')
            && corebb_admin_target_content_error($viewer, $selectedUser) === '';
        $model['selected_user']['can_edit_appearance'] = corebb_admin_can_access_tool($viewer, 'user_appearance_admin');
        $model['search'] = [
            'username' => (string)($selectedUser['username'] ?? $username),
            'userid' => (string)($selectedUser['id'] ?? $userId),
        ];
        if ((int)($viewer['accesslevel'] ?? 0) >= 3  && !$isPost) {
            corebb_adminlog_viewer(
                $viewer,
                'Viewed User Portal for user #' . (int)($selectedUser['id'] ?? 0) . ' (' . (string)($selectedUser['username'] ?? 'Unknown') . ')',
                'user_pages'
            );
        }
        $model['recent_posts'] = corebb_user_pages_fetch_recent_posts((int)$selectedUser['id']);
        $model['recent_notes'] = corebb_user_pages_fetch_recent_notes((int)$selectedUser['id']);
    } else {
        $model['search'] = ['username' => $username, 'userid' => $userId];
        if ($username !== '' || $userId !== '') {
            $model['errors'][] = 'No user with the requested name or ID exists.';
        }
    }

    return $model;
}
