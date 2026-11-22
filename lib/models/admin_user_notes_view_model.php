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
 |  admin_user_notes_view_model.php  - Admin user notes  |
 |  tool.                                                |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_user_tools_view_model.php';

const COREBB_ADMIN_NOTES_PER_PAGE = 20;

/**
 * Usage: Check whether an admin-notes table exists.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $table Database table name.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_admin_notes_table_exists(string $table): bool
{
    $db = corebb_db_connection_name();
    if ($db === '') {
        return false;
    }
    return db_exists(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
        [$db, $table]
    );
}

/**
 * Usage: Ensure required database structures exist before this admin page runs.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return void No return value.
 */
function corebb_admin_notes_ensure_schema(): void
{
    if (!corebb_admin_notes_table_exists('adminnotes')) {
        db_run("CREATE TABLE IF NOT EXISTS `adminnotes` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `userid` INT NOT NULL DEFAULT 0,
            `note` TEXT NULL,
            `reporterid` INT NOT NULL DEFAULT 0,
            `addtime` VARCHAR(64) NOT NULL DEFAULT '',
            `reason` TEXT NULL,
            PRIMARY KEY (`id`),
            KEY `userid` (`userid`),
            KEY `reporterid` (`reporterid`),
            KEY `addtime` (`addtime`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

/**
 * Usage: Format a stored value for admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_notes_format_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    /* VN-style: current year omits year; today only shows time. */
    if (date('y') !== date('y', $timestamp)) {
        return strtolower(date('n/j/y g:ia', $timestamp));
    }
    if (date('Y-m-d') === date('Y-m-d', $timestamp)) {
        return strtolower(date('g:ia', $timestamp));
    }
    return strtolower(date('n/j g:ia', $timestamp));
}

function corebb_admin_notes_note_types(): array
{
    return [
        'Misc' => 'Miscellaneous',
        'Ban_Related' => 'Ban related',
        'Language' => 'Language',
        'Spamming' => 'Spamming',
        'Trolling' => 'Trolling',
        'Harassment' => 'Harassment',
        'TOS_Violation' => 'Terms of service violation',
    ];
}

function corebb_admin_user_notes_model(array $viewer, array $request, array $post): array
{
    $model = [
        'selected_user' => null, 'search_value' => '', 'notes' => [], 'errors' => [], 'messages' => [],
        'saved' => false, 'draft' => '', 'selected_type' => 'Misc', 'note_types' => corebb_admin_notes_note_types(),
    ];
    $actorId = corebb_admin_viewer_id($viewer);
    if ($actorId <= 0 || !corebb_admin_can_access_tool($viewer, 'admin_notes')) {
        $model['errors'][] = 'You do not have access to staff notes.';
        return $model;
    }

    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $action = $isPost ? ($post['action'] ?? $request['action'] ?? '') : '';
    if ($action !== '') {
        if ($action !== 'add_note' || !corebb_security_csrf_valid($post)
            || !corebb_security_named_token_valid('admin_user_notes_token', $post, 'admin_user_notes_token')) {
            $model['errors'][] = 'Find the member again before adding a note.';
            return $model;
        }
    }
    $id = $request['userid'] ?? $request['uid'] ?? $request['usrid'] ?? $post['userid'] ?? $post['uid'] ?? $post['usrid'] ?? '';
    $name = $request['user'] ?? $request['username'] ?? $request['usr'] ?? $request['name'] ?? $post['username'] ?? $post['usr'] ?? '';
    $id = is_scalar($id) ? trim((string)$id) : '';
    $name = is_string($name) ? trim($name) : '';
    $model['search_value'] = ctype_digit($id) ? $id : $name;
    if ($action === 'add_note' && (!ctype_digit($id) || !is_scalar($post['userid'] ?? null) || (string)$post['userid'] !== $id)) {
        $model['errors'][] = 'Select the member again before adding this note.';
        return $model;
    }
    if ($model['search_value'] === '' && $id === '') { return $model; }
    $member = corebb_admin_find_user($model['search_value']);
    if (!$member) {
        $model['errors'][] = 'No member found for that username or ID.';
        return $model;
    }
    $memberId = (int)$member['id'];
    $model['selected_user'] = corebb_admin_user_summary($member);
    $model['search_value'] = $member['username'];
    $model['can_view_member'] = corebb_admin_can_access_tool($viewer, 'user_pages');
    $model['token'] = corebb_security_named_token('admin_user_notes_token');
    corebb_admin_notes_ensure_schema();
    if ($action === 'add_note') {
        $model['draft'] = is_string($post['note'] ?? null) ? $post['note'] : '';
        $body = trim(strip_tags($model['draft']));
        $type = $post['note_type'] ?? 'Misc';
        if (is_string($type) && isset($model['note_types'][$type])) { $model['selected_type'] = $type; }
        if ($body === '') {
            $model['errors'][] = 'Enter a note before saving.';
        } elseif (strlen($body) > 65535) {
            $model['errors'][] = 'This note is too long. Shorten it before saving.';
        } elseif (!db_run('INSERT INTO adminnotes (userid, note, reporterid, addtime, reason) VALUES (?, ?, ?, ?, ?)',
            [$memberId, $body, $actorId, date('Y-m-d H:i:s'), $model['selected_type']])) {
            $model['errors'][] = 'Could not save this note. Please try again.';
        } else {
            corebb_adminlog_viewer($viewer, 'Added admin note for user #' . $memberId);
            $model['saved'] = true;
            $model['messages'][] = 'Staff note added for ' . $member['username'] . '.';
            return $model;
        }
    }

    $total = db_value('SELECT COUNT(*) FROM adminnotes WHERE userid = ?', [$memberId]);
    if ($total === null || db_error() !== '') {
        $model['errors'][] = 'The note history could not be loaded.';
        return $model;
    }
    $model['total'] = (int)$total;
    $model['pages'] = max(1, (int)ceil($total / COREBB_ADMIN_NOTES_PER_PAGE));
    $model['page'] = min($model['pages'], max(1, (int)filter_var($request['page'] ?? 1, FILTER_VALIDATE_INT)));
    $offset = ($model['page'] - 1) * COREBB_ADMIN_NOTES_PER_PAGE;
    $rows = db_all('SELECT n.note, n.addtime, n.reason, n.reporterid, u.id AS author_id, u.username AS author
        FROM adminnotes n LEFT JOIN users u ON u.id = n.reporterid
        WHERE n.userid = ? ORDER BY n.id DESC LIMIT ' . COREBB_ADMIN_NOTES_PER_PAGE . ' OFFSET ' . $offset, [$memberId]);
    if (db_error() !== '') {
        $model['errors'][] = 'The note history could not be loaded.';
        return $model;
    }
    foreach ($rows as $row) {
        $reason = trim((string)$row['reason']);
        $row['reason'] = $model['note_types'][$reason] ?? ($reason === '' ? $model['note_types']['Misc'] : $reason);
        $row['author'] = $row['author'] ?? ($row['reporterid'] > 0 ? 'User #' . $row['reporterid'] : 'Unknown author');
        $row['date'] = corebb_admin_notes_format_date((string)$row['addtime']);
        $model['notes'][] = $row;
    }
    $model['history_loaded'] = true;
    return $model;
}
