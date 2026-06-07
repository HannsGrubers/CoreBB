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
 |  admin_boards_view_model.php  - Admin board/category  |
 |  management models.                                   |
 +-------------------------------------------------------+*/

if (!defined('COREBB_ADMIN_BOARDS_LOADED')) {
    define('COREBB_ADMIN_BOARDS_LOADED', true);
}

require_once __DIR__ . '/admin_helpers.php';
require_once __DIR__ . '/performance_helpers.php';
require_once __DIR__ . '/moderation_helpers.php';
require_once __DIR__ . '/private_board_helpers.php';

/**
 * Usage: Ensure required database structures exist before this admin page runs.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return void No return value.
 */
function corebb_admin_boards_ensure_schema(): void
{
    corebb_private_ensure_schema();
    if (function_exists('corebb_perf_add_column_if_missing')) {
        corebb_perf_add_column_if_missing('boards', 'default_open', 'TINYINT(1) NOT NULL DEFAULT 0');
    }
}

/**
 * Usage: Load categories and boards in display order for the board manager.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_boards_structure(): array
{
    corebb_admin_boards_ensure_schema();
    $categories = [];
    foreach (db_all('SELECT * FROM boards ORDER BY position ASC, id ASC') as $cat) {
        $catId = (int)($cat['id'] ?? 0);
        if ($catId <= 0) { continue; }
        $categories[$catId] = [
            'id' => $catId,
            'name' => (string)($cat['name'] ?? ''),
            'private' => (int)($cat['private'] ?? 0),
            'secure_archive' => (int)($cat['secure_archive'] ?? 0),
            'default_open' => (int)($cat['default_open'] ?? 0),
            'position' => (int)($cat['position'] ?? 0),
            'boards' => [],
        ];
    }

    foreach (db_all('SELECT * FROM forums ORDER BY categoryid ASC, position ASC, id ASC') as $board) {
        $catId = (int)($board['categoryid'] ?? 0);
        if (!isset($categories[$catId])) {
            $categories[$catId] = [
                'id' => $catId,
                'name' => 'Unknown Category ' . $catId,
                'private' => 0,
                'secure_archive' => 0,
                'default_open' => 0,
                'position' => 0,
                'boards' => [],
            ];
        }
        $categories[$catId]['boards'][] = [
            'id' => (int)($board['id'] ?? 0),
            'name' => (string)($board['name'] ?? ''),
            'description' => (string)($board['description'] ?? ''),
            'position' => (int)($board['position'] ?? 0),
            'categoryid' => $catId,
            'topiccount' => (int)($board['topiccount'] ?? 0),
            'postcount' => (int)($board['postcount'] ?? 0),
            'lastpstdate' => (string)($board['lastpstdate'] ?? ''),
            'private' => (int)($board['private'] ?? 0),
            'secure_archive' => (int)($board['secure_archive'] ?? 0),
            'effective_private' => ((int)($board['private'] ?? 0) === 1 || (int)($categories[$catId]['private'] ?? 0) === 1) ? 1 : 0,
            'effective_secure_archive' => ((int)($board['secure_archive'] ?? 0) === 1 || (int)($categories[$catId]['secure_archive'] ?? 0) === 1) ? 1 : 0,
        ];
    }

    return array_values($categories);
}

/**
 * Usage: Add admin-only display controls to the board/category tree.
 * Referenced by: corebb_admin_boards_base() and post-action refreshes.
 *
 * @param array $viewer Current admin user row.
 * @return array<int, array<string, mixed>> Categories with link and control metadata.
 */
function corebb_admin_boards_structure_for_view(array $viewer): array
{
    $categories = corebb_admin_boards_structure();
    $canManageSecureArchive = (int)($viewer['accesslevel'] ?? 0) >= 5;
    $categoryTotal = count($categories);

    foreach ($categories as $catIndex => $category) {
        $categoryLocked = !empty($category['secure_archive']) && !$canManageSecureArchive;
        $prevCategory = $catIndex > 0 ? ($categories[$catIndex - 1] ?? null) : null;
        $nextCategory = $catIndex < $categoryTotal - 1 ? ($categories[$catIndex + 1] ?? null) : null;
        $prevLocked = is_array($prevCategory) && !empty($prevCategory['secure_archive']) && !$canManageSecureArchive;
        $nextLocked = is_array($nextCategory) && !empty($nextCategory['secure_archive']) && !$canManageSecureArchive;

        $categories[$catIndex]['can_move_up'] = $catIndex > 0 && !$categoryLocked && !$prevLocked;
        $categories[$catIndex]['can_move_down'] = $catIndex < $categoryTotal - 1 && !$categoryLocked && !$nextLocked;
        $categories[$catIndex]['can_delete'] = !$categoryLocked;
        $categories[$catIndex]['can_add_board'] = !$categoryLocked;

        $boards = $category['boards'] ?? [];
        $boardTotal = count($boards);
        $topicTotal = 0;
        $postTotal = 0;
        foreach ($boards as $boardIndex => $board) {
            $boardLocked = !empty($board['effective_secure_archive']) && !$canManageSecureArchive;
            $boardId = (int)($board['id'] ?? 0);
            $boardName = (string)($board['name'] ?? 'Board');
            $topicTotal += (int)($board['topiccount'] ?? 0);
            $postTotal += (int)($board['postcount'] ?? 0);

            $categories[$catIndex]['boards'][$boardIndex]['url'] = corebb_board_url($boardId, 1, $boardName);
            $categories[$catIndex]['boards'][$boardIndex]['can_move_up'] = $boardTotal > 1 && $boardIndex > 0 && !$boardLocked;
            $categories[$catIndex]['boards'][$boardIndex]['can_move_down'] = $boardTotal > 1 && $boardIndex < $boardTotal - 1 && !$boardLocked;
            $categories[$catIndex]['boards'][$boardIndex]['can_manage_access'] = !$boardLocked;
            $categories[$catIndex]['boards'][$boardIndex]['can_delete'] = !$boardLocked;
        }
        $categories[$catIndex]['board_count'] = $boardTotal;
        $categories[$catIndex]['topic_total'] = $topicTotal;
        $categories[$catIndex]['post_total'] = $postTotal;
    }

    return $categories;
}

/**
 * Usage: Build aggregate counts for the Manage Boards toolbar.
 * Referenced by: corebb_admin_boards_base().
 *
 * @param array<int, array<string, mixed>> $categories Category tree for display.
 * @return array<string, int> Counts shown above the board manager.
 */
function corebb_admin_boards_summary(array $categories): array
{
    $boardCount = 0;
    $topicCount = 0;
    $postCount = 0;
    foreach ($categories as $category) {
        $boardCount += (int)($category['board_count'] ?? count((array)($category['boards'] ?? [])));
        $topicCount += (int)($category['topic_total'] ?? 0);
        $postCount += (int)($category['post_total'] ?? 0);
    }
    return [
        'category_count' => count($categories),
        'board_count' => $boardCount,
        'topic_count' => $topicCount,
        'post_count' => $postCount,
    ];
}

/**
 * Usage: Create the shared model payload for admin board/category templates.
 * Referenced by: every model builder in this file.
 *
 * @param array $viewer Current admin user row.
 * @param string $mode Admin page mode.
 * @return array<string, mixed> Base template model.
 */
function corebb_admin_boards_base(array $viewer, string $mode = 'manage'): array
{
    $categories = corebb_admin_boards_structure_for_view($viewer);
    return [
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'viewer' => $viewer,
        'can_manage_secure_archive' => ((int)($viewer['accesslevel'] ?? 0) >= 5),
        'mode' => $mode,
        'messages' => [],
        'categories' => $categories,
        'summary' => corebb_admin_boards_summary($categories),
    ];
}

/**
 * Usage: Move a category up or down while respecting Secure Archive rules.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $categoryId Category id.
 * @param string $direction Move direction.
 * @param array $viewer Current admin user row.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_boards_move_category(int $categoryId, string $direction, array $viewer): array
{
    if ($categoryId <= 0) {
        return [false, 'Unknown category.'];
    }
    if (!in_array($direction, ['up', 'down'], true)) {
        return [false, 'Unknown category movement direction.'];
    }

    corebb_admin_reindex_category_positions();

    $current = db_one('SELECT id, name, position, secure_archive FROM boards WHERE id = ? LIMIT 1', [$categoryId]);
    if (!$current) {
        return [false, 'Unknown category.'];
    }

    $position = (int)($current['position'] ?? 0);
    $targetPosition = $direction === 'up' ? $position - 1 : $position + 1;
    $swap = db_one('SELECT id, name, position, secure_archive FROM boards WHERE position = ? LIMIT 1', [$targetPosition]);
    if (!$swap) {
        return [false, 'That category is already at the edge of the list.'];
    }

    $accessLevel = (int)($viewer['accesslevel'] ?? 0);
    if ($accessLevel < 5 && ((int)($current['secure_archive'] ?? 0) === 1 || (int)($swap['secure_archive'] ?? 0) === 1)) {
        return [false, corebb_secure_archive_denied_message()];
    }

    db_run('UPDATE boards SET position = ? WHERE id = ?', [$position, (int)$swap['id']]);
    $ok = db_run('UPDATE boards SET position = ? WHERE id = ?', [$targetPosition, $categoryId]);
    corebb_admin_reindex_category_positions();

    if ($ok) {
        if (function_exists('addlogentry')) {
            addlogentry((string)($viewer['username'] ?? ''), $accessLevel, "Moved category {$categoryId} {$direction}");
        }
        return [true, 'Successfully changed category position.'];
    }
    return [false, 'Error changing category position: ' . db_error()];
}

/**
 * Usage: Move a board up or down within its category.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $forumId Forum/board id.
 * @param string $direction Move direction.
 * @param array $viewer Current admin user row.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_boards_move_board(int $forumId, string $direction, array $viewer): array
{
    if ($forumId <= 0) {
        return [false, 'Unknown board.'];
    }
    if (!in_array($direction, ['up', 'down'], true)) {
        return [false, 'Unknown board movement direction.'];
    }

    $current = db_one('SELECT f.id, f.categoryid, f.position, f.name, f.secure_archive, b.secure_archive AS category_secure_archive FROM forums f LEFT JOIN boards b ON b.id = f.categoryid WHERE f.id = ? LIMIT 1', [$forumId]);
    if (!$current) {
        return [false, 'Unknown board.'];
    }
    if (corebb_secure_archive_board_is_effectively_locked($current) && (int)($viewer['accesslevel'] ?? 0) < 5) {
        return [false, corebb_secure_archive_denied_message()];
    }

    $categoryId = (int)($current['categoryid'] ?? 0);
    corebb_admin_reindex_forum_positions($categoryId);

    $current = db_one('SELECT id, categoryid, position, name FROM forums WHERE id = ? LIMIT 1', [$forumId]);
    $position = (int)($current['position'] ?? 0);
    $targetPosition = $direction === 'up' ? $position - 1 : $position + 1;

    $swap = db_one(
        'SELECT id, position FROM forums WHERE categoryid = ? AND position = ? LIMIT 1',
        [$categoryId, $targetPosition]
    );
    if (!$swap) {
        return [false, 'That board is already at the edge of its category.'];
    }

    db_run('UPDATE forums SET position = ? WHERE id = ?', [$position, (int)$swap['id']]);
    $ok = db_run('UPDATE forums SET position = ? WHERE id = ?', [$targetPosition, $forumId]);
    corebb_admin_reindex_forum_positions($categoryId);

    if ($ok) {
        if (function_exists('addlogentry')) {
            addlogentry((string)($viewer['username'] ?? ''), (int)($viewer['accesslevel'] ?? 0), "Moved board {$forumId} {$direction}");
        }
        return [true, 'Successfully changed board position.'];
    }
    return [false, 'Error changing board position: ' . db_error()];
}

/**
 * Usage: Build and process the manage boards admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_manage_boards_model(array $viewer, array $get, array $post): array
{
    $model = corebb_admin_boards_base($viewer, 'manage');

    $act = (string)($get['act'] ?? 'manageboards');
    if ($act === 'movebrd') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $model['messages'][] = 'Use the move controls to reorder boards.';
            return $model;
        }
        [$ok, $message] = corebb_admin_boards_move_board((int)($get['id'] ?? 0), (string)($get['pos'] ?? ''), $viewer);
        $model['messages'][] = $message;
        $model['categories'] = corebb_admin_boards_structure_for_view($viewer);
    } elseif ($act === 'movecat') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $model['messages'][] = 'Use the move controls to reorder categories.';
            return $model;
        }
        [$ok, $message] = corebb_admin_boards_move_category((int)($get['id'] ?? 0), (string)($get['pos'] ?? ''), $viewer);
        $model['messages'][] = $message;
        $model['categories'] = corebb_admin_boards_structure_for_view($viewer);
    }

    if (isset($get['msg']) && (string)$get['msg'] !== '') {
        $model['messages'][] = (string)$get['msg'];
    }

    return $model;
}

/**
 * Usage: Build and process the add category admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_add_category_model(array $viewer, array $get, array $post): array
{
    $model = corebb_admin_boards_base($viewer, 'add_category');
    $model['catname'] = '';
    $model['private'] = 0;
    $model['secure_archive'] = 0;
    $model['default_open'] = 0;
    $model['step'] = 'form';

    $method = (string)($get['method'] ?? '');
    if ($method === 'post') {
        $catname = trim((string)($post['catname'] ?? ''));
        $isPrivate = (int)($post['RadioGroup1'] ?? 0) ? 1 : 0;
        $isSecureArchive = (int)($post['secure_archive'] ?? 0) ? 1 : 0;
        $defaultOpen = (int)($post['default_open'] ?? 0) ? 1 : 0;
        if ($isSecureArchive) {
            $isPrivate = 0;
        }
        $model['catname'] = $catname;
        $model['private'] = $isPrivate;
        $model['secure_archive'] = $isSecureArchive;
        $model['default_open'] = $defaultOpen;

        if ($isSecureArchive && (int)($viewer['accesslevel'] ?? 0) < 5) {
            $model['messages'][] = 'Only administrators can create Secure Archive categories.';
            $model['step'] = 'form';
            return $model;
        }

        if ($catname === '' || (function_exists('validate') && !validate($catname))) {
            $model['messages'][] = 'Sorry, you may only use letters, numbers, spaces, punctuation, and underscores in category names.';
            $model['step'] = 'form';
            return $model;
        }

        if (!isset($post['confirmed'])) {
            $model['step'] = 'confirm';
            return $model;
        }

        if (corebb_admin_add_category($catname, $isPrivate, $isSecureArchive, $defaultOpen)) {
            if (function_exists('addlogentry')) {
                addlogentry((string)($viewer['username'] ?? ''), (int)($viewer['accesslevel'] ?? 0), "Added category: {$catname}");
            }
            $model['messages'][] = 'Category added successfully.';
            $model['step'] = 'done';
            $model['catname'] = '';
            $model['private'] = 0;
            $model['secure_archive'] = 0;
            $model['default_open'] = 0;
            $model['categories'] = corebb_admin_boards_structure_for_view($viewer);
        } else {
            $model['messages'][] = 'Error adding category: ' . db_error();
            $model['step'] = 'form';
        }
    }

    return $model;
}

/**
 * Usage: Build and process the delete category admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_delete_category_model(array $viewer, array $get, array $post): array
{
    $model = corebb_admin_boards_base($viewer, 'delete_category');
    $id = (int)($get['id'] ?? $post['id'] ?? 0);
    $method = (string)($get['method'] ?? '');
    $model['category'] = null;
    $model['board_count'] = 0;
    $model['deleted'] = false;

    if ($id <= 0) {
        $model['messages'][] = 'Unknown category.';
        return $model;
    }

    $cat = db_one('SELECT * FROM boards WHERE id = ? LIMIT 1', [$id]);
    if (!$cat) {
        $model['messages'][] = 'Unknown category.';
        return $model;
    }

    if ((int)($cat['secure_archive'] ?? 0) === 1 && (int)($viewer['accesslevel'] ?? 0) < 5) {
        $model['messages'][] = corebb_secure_archive_denied_message();
        return $model;
    }

    $boardCount = (int)db_value('SELECT COUNT(*) FROM forums WHERE categoryid = ?', [$id], 0);
    $model['category'] = [
        'id' => $id,
        'name' => (string)($cat['name'] ?? ''),
        'private' => (int)($cat['private'] ?? 0),
        'secure_archive' => (int)($cat['secure_archive'] ?? 0),
    ];
    $model['board_count'] = $boardCount;

    if ($method === 'delete') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $model['messages'][] = 'Use the confirmation button to delete this category.';
            return $model;
        }
        if ($boardCount > 0) {
            $model['messages'][] = 'This category still contains boards. Move or delete those boards first.';
            return $model;
        }
        if (db_run('DELETE FROM boards WHERE id = ?', [$id])) {
            if (function_exists('addlogentry')) {
                addlogentry((string)($viewer['username'] ?? ''), (int)($viewer['accesslevel'] ?? 0), "Deleted category: {$id}");
            }
            $model['messages'][] = 'Category deleted.';
            $model['deleted'] = true;
            $model['categories'] = corebb_admin_boards_structure_for_view($viewer);
        } else {
            $model['messages'][] = 'Error deleting category: ' . db_error();
        }
    }

    return $model;
}

/**
 * Usage: Return option rows used by an admin form.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_boards_category_options(): array
{
    $rows = [];
    foreach (db_all('SELECT id, name, private, secure_archive FROM boards ORDER BY position ASC, id ASC') as $row) {
        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'private' => (int)($row['private'] ?? 0),
            'secure_archive' => (int)($row['secure_archive'] ?? 0),
        ];
    }
    return $rows;
}

/**
 * Usage: Return option rows used by an admin form.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $excludeId Board id to omit from the option list.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_boards_forum_options(int $excludeId = 0): array
{
    $rows = [];
    $sql = 'SELECT id, name, categoryid FROM forums';
    $params = [];
    if ($excludeId > 0) {
        $sql .= ' WHERE id <> ?';
        $params[] = $excludeId;
    }
    $sql .= ' ORDER BY categoryid ASC, position ASC, id ASC';
    foreach (db_all($sql, $params) as $row) {
        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'categoryid' => (int)($row['categoryid'] ?? 0),
        ];
    }
    return $rows;
}

/**
 * Usage: Build and process the modify category admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_modify_category_model(array $viewer, array $get, array $post): array
{
    $model = corebb_admin_boards_base($viewer, 'modify_category');
    $id = (int)($get['id'] ?? $post['boardid'] ?? 0);
    $model['category'] = null;
    $model['saved'] = false;

    if ($id <= 0) {
        $model['messages'][] = 'Unknown category.';
        return $model;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($post['newcatname'])) {
        $name = trim((string)($post['newcatname'] ?? ''));
        $private = (int)($post['private'] ?? 0) ? 1 : 0;
        $secureArchive = (int)($post['secure_archive'] ?? 0) ? 1 : 0;
        $defaultOpen = (int)($post['default_open'] ?? 0) ? 1 : 0;
        if ($secureArchive) {
            $private = 0;
        }
        $currentCategory = corebb_secure_archive_category_row($id);
        if (!$currentCategory) {
            $model['messages'][] = 'Unknown category.';
        } elseif (((int)($currentCategory['secure_archive'] ?? 0) === 1 || $secureArchive === 1) && (int)($viewer['accesslevel'] ?? 0) < 5) {
            $model['messages'][] = corebb_secure_archive_denied_message();
        } elseif ($name === '') {
            $model['messages'][] = 'Category name may not be blank.';
        } elseif (function_exists('validate') && !validate($name)) {
            $model['messages'][] = 'Sorry, you may only use letters, numbers, spaces, punctuation, and underscores in category names.';
        } else {
            $ok = db_run('UPDATE boards SET name = ?, private = ?, secure_archive = ?, default_open = ? WHERE id = ?', [$name, $private, $secureArchive, $defaultOpen, $id]);
            if ($ok) {
                $model['messages'][] = 'Category successfully updated.';
                $model['saved'] = true;
                if (function_exists('addlogentry')) {
                    addlogentry((string)($viewer['username'] ?? ''), (int)($viewer['accesslevel'] ?? 0), "Modified category: {$id}");
                }
            } else {
                $model['messages'][] = 'Error updating category: ' . db_error();
            }
        }
    }

    $cat = db_one('SELECT * FROM boards WHERE id = ? LIMIT 1', [$id]);
    if (!$cat) {
        $model['messages'][] = 'Unknown category.';
        return $model;
    }

    $boardCount = (int)db_value('SELECT COUNT(*) FROM forums WHERE categoryid = ?', [$id], 0);
    $model['category'] = [
        'id' => $id,
        'name' => (string)($cat['name'] ?? ''),
        'private' => (int)($cat['private'] ?? 0),
        'secure_archive' => (int)($cat['secure_archive'] ?? 0),
        'default_open' => (int)($cat['default_open'] ?? 0),
        'board_count' => $boardCount,
    ];
    return $model;
}

/**
 * Usage: Build and process the add board admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_add_board_model(array $viewer, array $get, array $post): array
{
    $model = corebb_admin_boards_base($viewer, 'add_board');
    $catId = (int)($get['catid'] ?? $post['catid'] ?? 0);
    $model['categories'] = corebb_admin_boards_category_options();
    $model['catid'] = $catId;
    $model['newboardname'] = '';
    $model['newboarddescription'] = '';
    $model['boardtimer'] = 30;
    $model['private'] = 0;
    $model['added'] = false;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($post['newboardname'])) {
        $name = trim((string)($post['newboardname'] ?? ''));
        $desc = trim((string)($post['newboarddescription'] ?? ''));
        $timer = max(0, min(999, (int)($post['boardtimer'] ?? 30)));
        $catId = (int)($post['catid'] ?? 0);
        $isPrivate = (int)($post['private'] ?? 0) ? 1 : 0;
        $model['catid'] = $catId;
        $model['newboardname'] = $name;
        $model['newboarddescription'] = $desc;
        $model['boardtimer'] = $timer;
        $model['private'] = $isPrivate;

        $cat = db_one('SELECT id, secure_archive FROM boards WHERE id = ? LIMIT 1', [$catId]);
        if (!$cat) {
            $model['messages'][] = 'Unknown category.';
        } elseif ((int)($cat['secure_archive'] ?? 0) === 1 && (int)($viewer['accesslevel'] ?? 0) < 5) {
            $model['messages'][] = corebb_secure_archive_denied_message();
        } elseif ($name === '') {
            $model['messages'][] = 'Board name may not be blank.';
        } else {
            $position = corebb_admin_next_forum_position($catId);
            $ok = db_run(
                'INSERT INTO forums (categoryid, name, description, edittimer, position, private) VALUES (?, ?, ?, ?, ?, ?)',
                [$catId, $name, $desc, $timer, $position, $isPrivate]
            );
            if ($ok) {
                $model['messages'][] = 'Successfully added board.';
                $model['added'] = true;
                $model['newboardname'] = '';
                $model['newboarddescription'] = '';
                $model['boardtimer'] = 30;
                $model['private'] = 0;
                if (function_exists('addlogentry')) {
                    addlogentry((string)($viewer['username'] ?? ''), (int)($viewer['accesslevel'] ?? 0), "Added board: {$name}");
                }
            } else {
                $model['messages'][] = 'Error adding board: ' . db_error();
            }
        }
    }

    return $model;
}

/**
 * Usage: Build and process the modify board admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_modify_board_model(array $viewer, array $get, array $post): array
{
    $method = (string)($get['method'] ?? 'view');
    $id = (int)($get['id'] ?? $post['forumid'] ?? $post['editboardid'] ?? $post['sourceforumid'] ?? 0);
    $model = corebb_admin_boards_base($viewer, 'modify_board');
    $model['method'] = $method;
    $model['board'] = null;
    $model['categories'] = corebb_admin_boards_category_options();
    $model['target_boards'] = [];
    $model['access_users'] = [];
    $model['done'] = false;

    if ($method === 'move_delete') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $model['messages'][] = 'Use the board deletion form to move contents and delete a board.';
            return $model;
        }
        $source = (int)($post['sourceforumid'] ?? 0);
        $target = (int)($post['targetboardid'] ?? 0);
        if (!corebb_secure_archive_user_can_write_board_id($source, (int)($viewer['accesslevel'] ?? 0)) || !corebb_secure_archive_user_can_write_board_id($target, (int)($viewer['accesslevel'] ?? 0))) {
            [$ok, $message] = [false, corebb_secure_archive_denied_message()];
        } else {
            [$ok, $message] = corebb_admin_move_board_contents_and_delete($source, $target);
        }
        $model['messages'][] = $message;
        $model['done'] = $ok;
        if ($ok && function_exists('addlogentry')) {
            addlogentry((string)($viewer['username'] ?? ''), (int)($viewer['accesslevel'] ?? 0), "Moved contents from board {$source} to {$target} and deleted source board");
        }
        return $model;
    }

    if ($method === 'fulldelete' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($post['imsure'])) {
        $forumId = (int)($post['forumid'] ?? 0);
        if (!corebb_secure_archive_user_can_write_board_id($forumId, (int)($viewer['accesslevel'] ?? 0))) {
            [$ok, $message] = [false, corebb_secure_archive_denied_message()];
        } else {
            [$ok, $message] = corebb_admin_delete_board_full($forumId);
        }
        $model['messages'][] = $message;
        $model['done'] = $ok;
        if ($ok && function_exists('addlogentry')) {
            addlogentry((string)($viewer['username'] ?? ''), (int)($viewer['accesslevel'] ?? 0), "Deleted board {$forumId}");
        }
        return $model;
    }

    if ($id <= 0) {
        $model['messages'][] = 'Unknown board.';
        return $model;
    }

    if ($method === 'edit' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($post['newboardname'])) {
        $name = trim((string)($post['newboardname'] ?? ''));
        $desc = trim((string)($post['newboarddesc'] ?? ''));
        $timer = max(0, min(999, (int)($post['boardtimer'] ?? 30)));
        $newCatId = (int)($post['categoryid'] ?? 0);
        $isPrivate = (int)($post['private'] ?? 0) ? 1 : 0;
        $current = db_one('SELECT f.categoryid, f.secure_archive, b.secure_archive AS category_secure_archive FROM forums f LEFT JOIN boards b ON b.id = f.categoryid WHERE f.id = ? LIMIT 1', [$id]);
        if (!$current) {
            $model['messages'][] = 'Unknown board.';
        } elseif ($name === '') {
            $model['messages'][] = 'Board name may not be blank.';
        } elseif (!db_exists('SELECT id FROM boards WHERE id = ? LIMIT 1', [$newCatId])) {
            $model['messages'][] = 'Unknown category.';
        } elseif (corebb_secure_archive_board_is_effectively_locked($current) && (int)($viewer['accesslevel'] ?? 0) < 5) {
            $model['messages'][] = corebb_secure_archive_denied_message();
        } elseif (!corebb_secure_archive_user_can_modify_category_id($newCatId, (int)($viewer['accesslevel'] ?? 0))) {
            $model['messages'][] = corebb_secure_archive_denied_message();
        } else {
            $oldCatId = (int)($current['categoryid'] ?? 0);
            $position = $newCatId === $oldCatId ? null : corebb_admin_next_forum_position($newCatId);
            if ($position === null) {
                $ok = db_run('UPDATE forums SET name = ?, description = ?, edittimer = ?, categoryid = ?, private = ? WHERE id = ?', [$name, $desc, $timer, $newCatId, $isPrivate, $id]);
            } else {
                $ok = db_run('UPDATE forums SET name = ?, description = ?, edittimer = ?, categoryid = ?, position = ?, private = ? WHERE id = ?', [$name, $desc, $timer, $newCatId, $position, $isPrivate, $id]);
                corebb_admin_reindex_forum_positions($oldCatId);
                corebb_admin_reindex_forum_positions($newCatId);
            }
            if ($ok) {
                $model['messages'][] = 'Successfully edited the board.';
                if (function_exists('addlogentry')) {
                    addlogentry((string)($viewer['username'] ?? ''), (int)($viewer['accesslevel'] ?? 0), "Modified board {$id}");
                }
            } else {
                $model['messages'][] = 'Error updating board: ' . db_error();
            }
        }
    }

    if ($method === 'access' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!corebb_secure_archive_user_can_write_board_id($id, (int)($viewer['accesslevel'] ?? 0))) {
            $model['messages'][] = corebb_secure_archive_denied_message();
        } else {
        $action = (string)($post['access_action'] ?? '');
        if ($action === 'add') {
            $grantUser = corebb_private_find_user_for_grant((string)($post['access_user'] ?? ''));
            if (!$grantUser) {
                $model['messages'][] = 'Unknown user. Enter an exact username or user ID.';
            } else {
                $grantUserId = (int)($grantUser['id'] ?? 0);
                if (corebb_private_add_board_grant($id, $grantUserId, (int)($viewer['id'] ?? 0))) {
                    $model['messages'][] = 'Private board access added for ' . (string)($grantUser['username'] ?? ('User ' . $grantUserId)) . '.';
                    if (function_exists('addlogentry')) {
                        addlogentry((string)($viewer['username'] ?? ''), (int)($viewer['accesslevel'] ?? 0), "Granted private board {$id} access to user {$grantUserId}");
                    }
                } else {
                    $model['messages'][] = 'Error adding private board access: ' . db_error();
                }
            }
        } elseif ($action === 'remove') {
            $grantUserId = (int)($post['userid'] ?? 0);
            if ($grantUserId > 0 && corebb_private_remove_board_grant($id, $grantUserId)) {
                $model['messages'][] = 'Private board access removed.';
                if (function_exists('addlogentry')) {
                    addlogentry((string)($viewer['username'] ?? ''), (int)($viewer['accesslevel'] ?? 0), "Removed private board {$id} access from user {$grantUserId}");
                }
            } else {
                $model['messages'][] = 'Error removing private board access: ' . db_error();
            }
        }
        }
    }

    $board = corebb_private_board_row($id);
    if (!$board) {
        $model['messages'][] = 'Unknown board.';
        return $model;
    }

    corebb_mod_ensure_schema();
    $topicCount = (int)db_value('SELECT COUNT(DISTINCT t.id) FROM topics t INNER JOIN posts p ON p.threadid = t.id AND p.is_deleted = 0 WHERE t.boardid = ? AND t.is_deleted = 0', [$id], 0);
    $postCount = (int)db_value('SELECT COUNT(*) FROM posts WHERE boardid = ? AND is_deleted = 0', [$id], 0);
    $model['board'] = [
        'id' => $id,
        'name' => (string)($board['name'] ?? ''),
        'description' => (string)($board['description'] ?? ''),
        'categoryid' => (int)($board['categoryid'] ?? 0),
        'edittimer' => (int)($board['edittimer'] ?? 30),
        'private' => (int)($board['private'] ?? 0),
        'category_private' => (int)($board['category_private'] ?? 0),
        'category_secure_archive' => (int)($board['category_secure_archive'] ?? 0),
        'category_name' => (string)($board['category_name'] ?? ''),
        'secure_archive' => (int)($board['secure_archive'] ?? 0),
        'effective_private' => corebb_private_board_is_effectively_private($board) ? 1 : 0,
        'effective_secure_archive' => corebb_secure_archive_board_is_effectively_locked($board) ? 1 : 0,
        'can_modify_secure_archive' => corebb_secure_archive_user_can_write_board_row($board, (int)($viewer['accesslevel'] ?? 0)) ? 1 : 0,
        'topic_count' => $topicCount,
        'post_count' => $postCount,
    ];

    if ($method === 'delete') {
        $model['target_boards'] = corebb_admin_boards_forum_options($id);
    }
    if ($method === 'access') {
        $model['access_users'] = corebb_private_board_grants($id);
    }

    return $model;
}
