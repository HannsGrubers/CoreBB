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
 |  admin_user_notes_view_model.php  - Admin user notes  |
 |  tool.                                                |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_user_tools_view_model.php';

const COREBB_ADMIN_NOTES_PER_PAGE = 50;

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
 * Usage: Return the form token for this admin page.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_admin_notes_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['admin_user_notes_token'])) {
        $_SESSION['admin_user_notes_token'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['admin_user_notes_token'];
}

/**
 * Usage: Validate the form token before accepting an admin mutation.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $post Posted form data from admin.php.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_admin_notes_token_ok(array $post): bool
{
    $expected = corebb_admin_notes_token();
    $got = (string)($post['admin_user_notes_token'] ?? '');
    return $got !== '' && hash_equals($expected, $got);
}

/**
 * Usage: Find a user for the admin notes page.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $username Username value.
 * @param string $userId User id.
 * @return ?array Data row when found, otherwise null.
 */
function corebb_admin_notes_find_user(string $username, string $userId): ?array
{
    $username = substr(trim($username), 0, 255);
    $userId = trim($userId);

    if ($userId !== '' && ctype_digit($userId)) {
        return corebb_admin_find_user((string)(int)$userId);
    }

    if ($username !== '') {
        return corebb_admin_find_user($username);
    }

    return null;
}

/**
 * Usage: Resolve the selected notes user from GET or POST input.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_notes_find_user_from_request(array $request, array $post): array
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

    return [$username, $userId, corebb_admin_notes_find_user($username, $userId)];
}

/**
 * Usage: Build a compact display summary for Twig.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $user User row being displayed or edited.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_notes_user_summary(array $user): array
{
    return [
        'id' => (int)($user['id'] ?? 0),
        'username' => (string)($user['username'] ?? ''),
        'profile_url' => '/profile/' . (int)($user['id'] ?? 0) . '/',
        'accesslevel' => (int)($user['accesslevel'] ?? 0),
        'posts' => (int)($user['posts'] ?? 0),
        'status' => (string)($user['status'] ?? ''),
        'raw' => $user,
    ];
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

/**
 * Usage: Fetch the row used by this admin action.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $userId User id.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_notes_fetch_notes(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $rows = db_all(
        'SELECT n.id, n.userid, n.note, n.reporterid, n.addtime, n.reason, u.username AS author_username, u.id AS author_id
         FROM adminnotes n
         LEFT JOIN users u ON u.id = n.reporterid
         WHERE n.userid = ?
         ORDER BY n.id DESC
         LIMIT ' . COREBB_ADMIN_NOTES_PER_PAGE,
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
            'author_profile_url' => $authorId > 0 ? '/profile/' . $authorId . '/' : '',
            'addtime' => (string)($row['addtime'] ?? ''),
            'date_vn' => corebb_admin_notes_format_date((string)($row['addtime'] ?? '')),
        ];
    }
    return $notes;
}

/**
 * Usage: Return available admin-note reason types.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_notes_note_types(): array
{
    return [
        'Misc' => 'Misc.',
        'Ban_Related' => 'Ban Related',
        'Language' => 'Language',
        'Spamming' => 'Spamming',
        'Trolling' => 'Trolling',
        'Harassment' => 'Harassment',
        'TOS_Violation' => 'TOS Violation',
    ];
}

/**
 * Usage: Add a note to a user account.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param int $userId User id.
 * @param string $note Admin note or resolution text.
 * @param string $reason Moderation/admin reason text.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_notes_add(array $viewer, int $userId, string $note, string $reason): array
{
    corebb_admin_notes_ensure_schema();

    $note = trim($note);
    $reason = trim($reason);
    $types = corebb_admin_notes_note_types();

    if ($userId <= 0) {
        return [false, 'No user selected.'];
    }
    if ($note === '') {
        return [false, 'Required Input. Please enter a note.'];
    }
    if (!array_key_exists($reason, $types)) {
        $reason = 'Misc';
    }

    /* The VN page says board codes are allowed and HTML is not. */
    $note = substr(strip_tags($note), 0, 65535);

    $ok = db_run(
        'INSERT INTO adminnotes (userid, note, reporterid, addtime, reason) VALUES (?, ?, ?, ?, ?)',
        [$userId, $note, (int)($viewer['id'] ?? 0), date('Y-m-d H:i:s'), $reason]
    );

    if (!$ok) {
        return [false, 'Error adding admin note: ' . db_error()];
    }

    if (function_exists('addlogentry')) {
        addlogentry((string)($viewer['username'] ?? $viewer['id'] ?? 'Unknown'), (int)($viewer['accesslevel'] ?? 0), 'Added admin note for user #' . $userId);
    }

    return [true, 'Admin note added.'];
}

/**
 * Usage: Build and process the user notes admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_user_notes_model(array $viewer, array $request, array $post): array
{
    corebb_admin_notes_ensure_schema();

    $model = corebb_admin_require_model_base($viewer, "User's Admin Notes", $request);
    $model['token'] = corebb_admin_notes_token();
    $model['note_types'] = corebb_admin_notes_note_types();
    $model['selected_type'] = 'Misc';
    $model['search'] = [
        'username' => '',
        'userid' => '',
    ];
    $model['selected_user'] = null;
    $model['notes'] = [];

    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $action = (string)($post['action'] ?? $request['action'] ?? '');

    [$username, $userId, $selectedUser] = corebb_admin_notes_find_user_from_request($request, $post);

    if ($isPost && $action === 'add_note') {
        if (!corebb_admin_notes_token_ok($post)) {
            $model['errors'][] = 'Security token expired. Please reload the admin notes page and try again.';
        } elseif (!$selectedUser) {
            $model['errors'][] = 'No user selected.';
        } else {
            $type = (string)($post['note_type'] ?? 'Misc');
            [$ok, $message] = corebb_admin_notes_add($viewer, (int)$selectedUser['id'], (string)($post['note'] ?? ''), $type);
            if ($ok) {
                $model['messages'][] = $message;
            } else {
                $model['errors'][] = $message;
            }
            $model['selected_type'] = array_key_exists($type, $model['note_types']) ? $type : 'Misc';
        }
    }

    /* Re-read the selected user after changes so the screen stays on that user. */
    [$username, $userId, $selectedUser] = corebb_admin_notes_find_user_from_request($request, $post);

    $model['search'] = [
        'username' => $username,
        'userid' => $userId,
    ];

    if ($selectedUser) {
        $model['selected_user'] = corebb_admin_notes_user_summary($selectedUser);
        $model['search']['username'] = (string)($selectedUser['username'] ?? $username);
        $model['search']['userid'] = (string)($selectedUser['id'] ?? $userId);
        $model['notes'] = corebb_admin_notes_fetch_notes((int)$selectedUser['id']);
    } elseif ($username !== '' || $userId !== '') {
        $model['errors'][] = 'No user with the requested name or ID exists.';
    }

    return $model;
}
