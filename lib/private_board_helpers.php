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
 |  private_board_helpers.php  - Private board/category  |
 |  access helpers.                                      |
 +-------------------------------------------------------+*/

if (!defined('COREBB_PRIVATE_BOARD_HELPERS_LOADED')) {
    define('COREBB_PRIVATE_BOARD_HELPERS_LOADED', true);
}

require_once __DIR__ . '/performance_helpers.php';

/**
 * Usage: Resolve the current logged-in user id for private-board checks.
 * Referenced by: visibility helpers when no explicit user id is supplied.
 *
 * @return int Current user id, or 0 for guests.
 */
function corebb_private_current_user_id(): int
{
    global $userlogindata_a, $MyData;
    if (function_exists('loggedin') && loggedin()) {
        return (int)($MyData['id'] ?? $userlogindata_a['id'] ?? 0);
    }
    return 0;
}

/**
 * Usage: Resolve the current viewer access level for private-board checks.
 * Referenced by: admin and visibility helpers when no explicit level is supplied.
 *
 * @return int Current access level, or 0 when unavailable.
 */
function corebb_private_current_access_level(): int
{
    global $userlogindata_a, $MyData;
    return (int)($MyData['accesslevel'] ?? $userlogindata_a['accesslevel'] ?? 0);
}

/**
 * Usage: Check whether a viewer is an administrator for private-board bypasses.
 * Referenced by: private-board and Secure Archive helpers.
 *
 * @param int|null $accessLevel Explicit access level, or null to use the current viewer.
 * @return bool True when the level is administrator or higher.
 */
function corebb_private_is_admin(?int $accessLevel = null): bool
{
    return (int)($accessLevel ?? corebb_private_current_access_level()) >= 5;
}

/**
 * Usage: Ensure private-board and Secure Archive schema columns/tables exist.
 * Referenced by: all private-board read/write helpers and index/board/thread loaders.
 *
 * @return void
 */
function corebb_private_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Category privacy lives on the legacy `boards` table. Board privacy
    // lives on `forums`. Ensure both columns exist before any Manage Boards
    // add/modify form attempts to read or write them.
    corebb_perf_add_column_if_missing('boards', 'position', 'INT(11) NOT NULL DEFAULT 0');
    corebb_perf_add_index_if_missing('boards', 'idx_boards_position_id', ['position', 'id']);
    corebb_perf_add_column_if_missing('boards', 'private', 'TINYINT(1) NOT NULL DEFAULT 0');
    corebb_perf_add_index_if_missing('boards', 'idx_boards_private_id', ['private', 'id']);
    corebb_perf_add_column_if_missing('boards', 'secure_archive', 'TINYINT(1) NOT NULL DEFAULT 0');
    corebb_perf_add_index_if_missing('boards', 'idx_boards_secure_archive_id', ['secure_archive', 'id']);

    corebb_perf_add_column_if_missing('forums', 'private', 'TINYINT(1) NOT NULL DEFAULT 0');
    corebb_perf_add_index_if_missing('forums', 'idx_forums_private_category', ['private', 'categoryid']);
    corebb_perf_add_column_if_missing('forums', 'secure_archive', 'TINYINT(1) NOT NULL DEFAULT 0');
    corebb_perf_add_index_if_missing('forums', 'idx_forums_secure_archive_category', ['secure_archive', 'categoryid']);

    if (!corebb_perf_table_exists('private_board_access')) {
        db_run("CREATE TABLE IF NOT EXISTS `private_board_access` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `boardid` INT(11) NOT NULL,
            `userid` INT(11) NOT NULL,
            `granted_by` INT(11) NOT NULL DEFAULT 0,
            `created_at` VARCHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_private_board_user` (`boardid`, `userid`),
            KEY `idx_private_user_board` (`userid`, `boardid`),
            KEY `idx_private_board_user` (`boardid`, `userid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

/**
 * Usage: Load one forum row with category privacy/archive fields attached.
 * Referenced by: board/thread/post/moderation visibility and write checks.
 *
 * @param int $boardId Forum/board id.
 * @return array<string, mixed>|false|null Joined board row from db_one().
 */
function corebb_private_board_row(int $boardId)
{
    corebb_private_ensure_schema();
    return db_one(
        'SELECT f.*, b.private AS category_private, b.secure_archive AS category_secure_archive, b.name AS category_name
           FROM forums f
           LEFT JOIN boards b ON b.id = f.categoryid
          WHERE f.id = ?
          LIMIT 1',
        [$boardId]
    );
}


/**
 * Usage: Check whether a viewer can administer Secure Archive content.
 * Referenced by: Secure Archive write/modify helpers.
 *
 * @param int|null $accessLevel Explicit access level, or null to use current viewer.
 * @return bool True when the viewer is an administrator.
 */
function corebb_secure_archive_is_admin(?int $accessLevel = null): bool
{
    return corebb_private_is_admin($accessLevel);
}

/**
 * Usage: Determine whether a board is locked by board or category Secure Archive flags.
 * Referenced by: public read-only checks, index display, and admin board tools.
 *
 * @param array<string, mixed> $board Board row with optional category_secure_archive field.
 * @return bool True when the board is effectively Secure Archive read-only.
 */
function corebb_secure_archive_board_is_effectively_locked(array $board): bool
{
    return ((int)($board['secure_archive'] ?? 0) === 1) || ((int)($board['category_secure_archive'] ?? 0) === 1);
}

/**
 * Usage: Determine whether a category is marked as Secure Archive.
 * Referenced by: category modify permission checks.
 *
 * @param array<string, mixed> $category Category row.
 * @return bool True when the category is Secure Archive locked.
 */
function corebb_secure_archive_category_is_locked(array $category): bool
{
    return ((int)($category['secure_archive'] ?? 0) === 1);
}

/**
 * Usage: Check whether a viewer may modify Secure Archive content.
 * Referenced by: board/category/topic/post Secure Archive write checks.
 *
 * @param int|null $accessLevel Explicit access level, or null to use current viewer.
 * @return bool True when the viewer can modify Secure Archive content.
 */
function corebb_secure_archive_user_can_modify(?int $accessLevel = null): bool
{
    return corebb_secure_archive_is_admin($accessLevel);
}

/**
 * Usage: Check whether a viewer may write to a board row.
 * Referenced by: board, thread, post, poll, and moderation workflows.
 *
 * @param array<string, mixed> $board Board row with Secure Archive flags.
 * @param int|null $accessLevel Explicit access level, or null to use current viewer.
 * @return bool True when writes are allowed.
 */
function corebb_secure_archive_user_can_write_board_row(array $board, ?int $accessLevel = null): bool
{
    if (!corebb_secure_archive_board_is_effectively_locked($board)) {
        return true;
    }
    return corebb_secure_archive_user_can_modify($accessLevel ?? corebb_private_current_access_level());
}

/**
 * Usage: Check whether a viewer may write to a board by id.
 * Referenced by: poll, post, admin, API, and moderation workflows.
 *
 * @param int $boardId Board id.
 * @param int|null $accessLevel Explicit access level, or null to use current viewer.
 * @return bool True when writes are allowed.
 */
function corebb_secure_archive_user_can_write_board_id(int $boardId, ?int $accessLevel = null): bool
{
    if ($boardId <= 0) {
        return false;
    }
    $board = corebb_private_board_row($boardId);
    return is_array($board) && corebb_secure_archive_user_can_write_board_row($board, $accessLevel);
}

/**
 * Usage: Load one category row for Secure Archive checks.
 * Referenced by: corebb_secure_archive_user_can_modify_category_id() and admin board tools.
 *
 * @param int $categoryId Category id.
 * @return array<string, mixed>|false|null Category row from db_one(), or false for invalid ids.
 */
function corebb_secure_archive_category_row(int $categoryId)
{
    corebb_private_ensure_schema();
    if ($categoryId <= 0) {
        return false;
    }
    return db_one('SELECT * FROM boards WHERE id = ? LIMIT 1', [$categoryId]);
}

/**
 * Usage: Check whether a viewer may modify a category by id.
 * Referenced by: admin board/category mutation flows.
 *
 * @param int $categoryId Category id.
 * @param int|null $accessLevel Explicit access level, or null to use current viewer.
 * @return bool True when category modification is allowed.
 */
function corebb_secure_archive_user_can_modify_category_id(int $categoryId, ?int $accessLevel = null): bool
{
    $category = corebb_secure_archive_category_row($categoryId);
    if (!$category) {
        return false;
    }
    if (!corebb_secure_archive_category_is_locked($category)) {
        return true;
    }
    return corebb_secure_archive_user_can_modify($accessLevel ?? corebb_private_current_access_level());
}

/**
 * Usage: Check whether a viewer may modify the board containing a topic.
 * Referenced by: poll helpers and moderation/topic workflows.
 *
 * @param int $topicId Topic id.
 * @param int|null $accessLevel Explicit access level, or null to use current viewer.
 * @return bool True when topic modification is allowed.
 */
function corebb_secure_archive_user_can_modify_topic_id(int $topicId, ?int $accessLevel = null): bool
{
    if ($topicId <= 0) {
        return false;
    }
    $boardId = (int)db_value('SELECT boardid FROM topics WHERE id = ? LIMIT 1', [$topicId], 0);
    return $boardId > 0 && corebb_secure_archive_user_can_write_board_id($boardId, $accessLevel);
}

/**
 * Usage: Check whether a viewer may modify the board containing a post.
 * Referenced by: moderation and deleted-post restore workflows.
 *
 * @param int $postId Post id.
 * @param int|null $accessLevel Explicit access level, or null to use current viewer.
 * @return bool True when post modification is allowed.
 */
function corebb_secure_archive_user_can_modify_post_id(int $postId, ?int $accessLevel = null): bool
{
    if ($postId <= 0) {
        return false;
    }
    $boardId = (int)db_value('SELECT boardid FROM posts WHERE id = ? LIMIT 1', [$postId], 0);
    return $boardId > 0 && corebb_secure_archive_user_can_write_board_id($boardId, $accessLevel);
}

/**
 * Usage: Provide the standard Secure Archive write-denied message.
 * Referenced by: post, poll, moderation, API, and admin board flows.
 *
 * @return string User-facing Secure Archive denial message.
 */
function corebb_secure_archive_denied_message(): string
{
    return 'This board belongs to the Secure Archive. Its contents are public read-only and can only be changed by an administrator.';
}

/**
 * Usage: Determine whether a board is private through board or category flags.
 * Referenced by: visibility checks and admin board detail models.
 *
 * @param array<string, mixed> $board Board row with optional category_private field.
 * @return bool True when the board is effectively private.
 */
function corebb_private_board_is_effectively_private(array $board): bool
{
    return ((int)($board['private'] ?? 0) === 1) || ((int)($board['category_private'] ?? 0) === 1);
}

/**
 * Usage: Check whether a user has an explicit private-board grant.
 * Referenced by: corebb_private_user_can_view_board_row().
 *
 * @param int $boardId Board id.
 * @param int $userId User id.
 * @return bool True when an access grant exists.
 */
function corebb_private_user_has_board_grant(int $boardId, int $userId): bool
{
    corebb_private_ensure_schema();
    if ($boardId <= 0 || $userId <= 0) {
        return false;
    }
    return db_exists('SELECT 1 FROM private_board_access WHERE boardid = ? AND userid = ? LIMIT 1', [$boardId, $userId]);
}

/**
 * Usage: Check whether a user can view a board row.
 * Referenced by: public navigation, board/thread/post views, favorites, and moderation guards.
 *
 * @param array<string, mixed> $board Board row with private/category flags.
 * @param int|null $userId Explicit user id, or null to use current viewer.
 * @param int|null $accessLevel Explicit access level, or null to use current viewer.
 * @return bool True when the board should be visible.
 */
function corebb_private_user_can_view_board_row(array $board, ?int $userId = null, ?int $accessLevel = null): bool
{
    corebb_private_ensure_schema();
    $boardId = (int)($board['id'] ?? 0);
    if ($boardId <= 0) {
        return false;
    }

    $userId = $userId ?? corebb_private_current_user_id();
    $accessLevel = $accessLevel ?? corebb_private_current_access_level();

    if (corebb_private_is_admin($accessLevel)) {
        return true;
    }

    if (!corebb_private_board_is_effectively_private($board)) {
        return true;
    }

    return corebb_private_user_has_board_grant($boardId, (int)$userId);
}

/**
 * Usage: Check whether a user can view a board by id.
 * Referenced by: public routes, API, breadcrumbs, search/profile filters, and moderation guards.
 *
 * @param int $boardId Board id.
 * @param int|null $userId Explicit user id, or null to use current viewer.
 * @param int|null $accessLevel Explicit access level, or null to use current viewer.
 * @return bool True when the board should be visible.
 */
function corebb_private_user_can_view_board_id(int $boardId, ?int $userId = null, ?int $accessLevel = null): bool
{
    $board = corebb_private_board_row($boardId);
    return is_array($board) && corebb_private_user_can_view_board_row($board, $userId, $accessLevel);
}

/**
 * Usage: Build an SQL visibility clause for forum/category joins.
 * Referenced by: index, board, search, profile-content, notifications, admin moderation, and user tools.
 *
 * @param string $forumAlias SQL alias for forums.
 * @param string $categoryAlias SQL alias for boards/categories.
 * @param int|null $userId Explicit user id, or null to use current viewer.
 * @param int|null $accessLevel Explicit access level, or null to use current viewer.
 * @return array{0: string, 1: array<int, mixed>} SQL fragment and bound parameters.
 */
function corebb_private_sql_visible_board_clause(string $forumAlias = 'f', string $categoryAlias = 'b', ?int $userId = null, ?int $accessLevel = null): array
{
    corebb_private_ensure_schema();
    $forumAlias = preg_match('/^[A-Za-z0-9_]+$/', $forumAlias) ? $forumAlias : 'f';
    $categoryAlias = preg_match('/^[A-Za-z0-9_]+$/', $categoryAlias) ? $categoryAlias : 'b';

    $userId = $userId ?? corebb_private_current_user_id();
    $accessLevel = $accessLevel ?? corebb_private_current_access_level();

    if (corebb_private_is_admin($accessLevel)) {
        return ['1=1', []];
    }

    $publicClause = "(COALESCE({$forumAlias}.private,0) = 0 AND COALESCE({$categoryAlias}.private,0) = 0)";
    if ((int)$userId <= 0) {
        return [$publicClause, []];
    }

    return [
        '(' . $publicClause . " OR EXISTS (SELECT 1 FROM private_board_access pba WHERE pba.boardid = {$forumAlias}.id AND pba.userid = ?))",
        [(int)$userId]
    ];
}

/**
 * Usage: Check whether a category should appear to a viewer.
 * Referenced by: board index category filtering.
 *
 * @param array<string, mixed> $category Category row.
 * @param int|null $userId Explicit user id, or null to use current viewer.
 * @param int|null $accessLevel Explicit access level, or null to use current viewer.
 * @return bool True when the category or at least one child board is visible.
 */
function corebb_private_category_visible(array $category, ?int $userId = null, ?int $accessLevel = null): bool
{
    corebb_private_ensure_schema();
    $categoryId = (int)($category['id'] ?? 0);
    if ($categoryId <= 0) {
        return false;
    }

    $userId = $userId ?? corebb_private_current_user_id();
    $accessLevel = $accessLevel ?? corebb_private_current_access_level();
    if (corebb_private_is_admin($accessLevel)) {
        return true;
    }
    if ((int)($category['private'] ?? 0) !== 1) {
        return true;
    }

    [$visibleSql, $params] = corebb_private_sql_visible_board_clause('f', 'b', $userId, $accessLevel);
    array_unshift($params, $categoryId);
    return db_exists(
        'SELECT 1
           FROM forums f
           LEFT JOIN boards b ON b.id = f.categoryid
          WHERE f.categoryid = ? AND ' . $visibleSql . '
          LIMIT 1',
        $params
    );
}

/**
 * Usage: Load visible forums in one category for a viewer.
 * Referenced by: board index open-category builders.
 *
 * @param int $categoryId Category id.
 * @param int|null $userId Explicit user id, or null to use current viewer.
 * @param int|null $accessLevel Explicit access level, or null to use current viewer.
 * @return array<int, array<string, mixed>> Visible forum rows with category flags.
 */
function corebb_private_fetch_visible_forums_for_category(int $categoryId, ?int $userId = null, ?int $accessLevel = null): array
{
    corebb_private_ensure_schema();
    [$visibleSql, $params] = corebb_private_sql_visible_board_clause('f', 'b', $userId, $accessLevel);
    array_unshift($params, $categoryId);
    return db_all(
        'SELECT f.*, b.private AS category_private, b.secure_archive AS category_secure_archive, b.name AS category_name
           FROM forums f
           LEFT JOIN boards b ON b.id = f.categoryid
          WHERE f.categoryid = ? AND ' . $visibleSql . '
          ORDER BY f.position ASC, f.id ASC',
        $params
    );
}

/**
 * Usage: Grant one user access to a private board.
 * Referenced by: admin board access management.
 *
 * @param int $boardId Board id.
 * @param int $userId User id receiving access.
 * @param int $grantedBy Staff user id granting access.
 * @return bool True when the grant is inserted or updated.
 */
function corebb_private_add_board_grant(int $boardId, int $userId, int $grantedBy): bool
{
    corebb_private_ensure_schema();
    if ($boardId <= 0 || $userId <= 0) {
        return false;
    }
    $now = function_exists('convert_date') ? convert_date() : date('Y-m-d H:i:s');
    return db_run(
        'INSERT INTO private_board_access (boardid, userid, granted_by, created_at)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE granted_by = VALUES(granted_by), created_at = VALUES(created_at)',
        [$boardId, $userId, max(0, $grantedBy), $now]
    );
}

/**
 * Usage: Remove one user's access grant from a private board.
 * Referenced by: admin board access management.
 *
 * @param int $boardId Board id.
 * @param int $userId User id losing access.
 * @return bool True when the delete succeeds.
 */
function corebb_private_remove_board_grant(int $boardId, int $userId): bool
{
    corebb_private_ensure_schema();
    if ($boardId <= 0 || $userId <= 0) {
        return false;
    }
    return db_run('DELETE FROM private_board_access WHERE boardid = ? AND userid = ?', [$boardId, $userId]);
}

/**
 * Usage: Load all explicit access grants for a private board.
 * Referenced by: admin board detail/access management.
 *
 * @param int $boardId Board id.
 * @return array<int, array<string, mixed>> Grant rows with user and grantor display names.
 */
function corebb_private_board_grants(int $boardId): array
{
    corebb_private_ensure_schema();
    if ($boardId <= 0) {
        return [];
    }
    return db_all(
        'SELECT pba.boardid, pba.userid, pba.granted_by, pba.created_at,
                u.username, u.accesslevel,
                gu.username AS granted_by_username
           FROM private_board_access pba
           LEFT JOIN users u ON u.id = pba.userid
           LEFT JOIN users gu ON gu.id = pba.granted_by
          WHERE pba.boardid = ?
          ORDER BY u.username ASC, pba.userid ASC',
        [$boardId]
    );
}

/**
 * Usage: Find a user by id or exact username for private-board grants.
 * Referenced by: admin board access management.
 *
 * @param string $needle Submitted id or username.
 * @return array<string, mixed>|false|null Matching user row from db_one().
 */
function corebb_private_find_user_for_grant(string $needle)
{
    $needle = trim($needle);
    if ($needle === '') {
        return false;
    }
    if (ctype_digit($needle)) {
        return db_one('SELECT id, username, accesslevel FROM users WHERE id = ? LIMIT 1', [(int)$needle]);
    }
    return db_one('SELECT id, username, accesslevel FROM users WHERE username = ? LIMIT 1', [$needle]);
}
