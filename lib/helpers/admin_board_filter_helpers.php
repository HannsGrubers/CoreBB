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
 |  admin_board_filter_helpers.php - Shared board        |
 |  filter helpers for admin moderation views.           |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/private_board_helpers.php';

/**
 * Usage: Read a board filter id from admin request values.
 * Referenced by: admin moderation pages that narrow results by board.
 *
 * @param array<string, mixed> $request GET/POST values.
 * @param string $key Primary request key to inspect.
 * @return int Positive board id, or 0 for all boards.
 */
function corebb_admin_board_filter_id(array $request, string $key = 'boardid'): int
{
    $value = $request[$key] ?? $request['board'] ?? 0;
    $boardId = (int)$value;
    return $boardId > 0 ? $boardId : 0;
}

/**
 * Usage: List boards visible to the current admin/moderator for filter menus.
 * Referenced by: mod-request and deleted-post admin models.
 *
 * @param array<string, mixed> $viewer Current admin/moderator user row.
 * @return array<int, array<string, mixed>> Visible board options keyed by board id.
 */
function corebb_admin_board_filter_options(array $viewer): array
{
    $viewerId = (int)($viewer['id'] ?? 0);
    $accessLevel = (int)($viewer['accesslevel'] ?? 0);
    [$visibleSql, $params] = corebb_private_sql_visible_board_clause('f', 'b', $viewerId, $accessLevel);

    $rows = db_all(
        'SELECT f.id, f.name, f.categoryid, f.private, f.secure_archive,
                b.name AS category_name, b.private AS category_private, b.secure_archive AS category_secure_archive
           FROM forums f
           LEFT JOIN boards b ON b.id = f.categoryid
          WHERE ' . $visibleSql . '
          ORDER BY COALESCE(b.position, 0) ASC, b.id ASC, COALESCE(f.position, 0) ASC, f.id ASC',
        $params
    );

    $options = [];
    foreach ($rows as $row) {
        $boardId = (int)($row['id'] ?? 0);
        if ($boardId <= 0) {
            continue;
        }

        $categoryName = trim((string)($row['category_name'] ?? ''));
        $boardName = trim((string)($row['name'] ?? ''));
        $label = $categoryName !== '' ? $categoryName . ' / ' . $boardName : $boardName;

        $options[$boardId] = [
            'id' => $boardId,
            'name' => $boardName,
            'category_id' => (int)($row['categoryid'] ?? 0),
            'category_name' => $categoryName,
            'label' => $label !== '' ? $label : 'Board #' . $boardId,
            'private' => (int)($row['private'] ?? 0),
            'category_private' => (int)($row['category_private'] ?? 0),
            'secure_archive' => (int)($row['secure_archive'] ?? 0),
            'category_secure_archive' => (int)($row['category_secure_archive'] ?? 0),
        ];
    }

    return $options;
}

/**
 * Usage: Build a complete visible-board filter context for an admin page.
 * Referenced by: admin moderation models before applying SQL filters.
 *
 * @param array<string, mixed> $viewer Current admin/moderator user row.
 * @param array<string, mixed> $request GET/POST values.
 * @param string $key Primary board id request key.
 * @return array{selected_board_id: int, selected_board_label: string, board_options: array<int, array<string, mixed>>}
 */
function corebb_admin_board_filter_context(array $viewer, array $request, string $key = 'boardid'): array
{
    $options = corebb_admin_board_filter_options($viewer);
    $selectedBoardId = corebb_admin_board_filter_id($request, $key);
    if ($selectedBoardId > 0 && !isset($options[$selectedBoardId])) {
        $selectedBoardId = 0;
    }

    return [
        'selected_board_id' => $selectedBoardId,
        'selected_board_label' => $selectedBoardId > 0 ? (string)($options[$selectedBoardId]['label'] ?? '') : '',
        'board_options' => $options,
    ];
}
?>
