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
 |  admin_deleted_posts_view_model.php  - Deleted Posts  |
 |  moderation bin.                                      |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/moderation_helpers.php';
require_once __DIR__ . '/private_board_helpers.php';
require_once __DIR__ . '/pagination_helpers.php';
require_once __DIR__ . '/admin_board_filter_helpers.php';

/**
 * Usage: Return the form token for this admin page.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_deleted_posts_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['admin_deleted_posts_token'])) {
        $_SESSION['admin_deleted_posts_token'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['admin_deleted_posts_token'];
}

/**
 * Usage: Validate the form token before accepting an admin mutation.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $post Posted form data from admin.php.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_deleted_posts_token_ok(array $post): bool
{
    $got = (string)($post['admin_deleted_posts_token'] ?? '');
    return $got !== '' && hash_equals(corebb_deleted_posts_token(), $got);
}

/**
 * Usage: Check whether this admin action is allowed.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $row Database row being normalized for display.
 * @return string Normalized or display-ready string.
 */
function corebb_deleted_posts_can_modify(array $viewer, array $row): string
{
    $viewerId = (int)($viewer['id'] ?? 0);
    $viewerLevel = (int)($viewer['accesslevel'] ?? 0);
    $authorId = (int)($row['posterid'] ?? 0);
    $authorLevel = (int)($row['author_accesslevel'] ?? 0);

    if ($viewerLevel < 3) {
        return 'Moderator access is required.';
    }
    if ($viewerId > 0 && $authorId > 0 && $viewerId === $authorId) {
        return '';
    }
    if ($authorLevel >= $viewerLevel) {
        return 'You cannot restore or purge a post written by a user with equal or higher rights.';
    }
    return '';
}

/**
 * Usage: Fetch the row used by this admin action.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $postId Post id.
 * @return array|false Data row when found, otherwise false.
 */
function corebb_deleted_posts_fetch_row(int $postId): array|false
{
    corebb_mod_ensure_schema();
    return db_one(
        'SELECT p.id, p.posterid, p.author, p.title, p.body, p.threadid, p.boardid, p.posttime, p.posttimeraw, p.is_deleted, p.deleted_at, p.deleted_by, p.delete_reason,
                author.username AS author_username, author.accesslevel AS author_accesslevel,
                deleter.username AS deleted_by_username,
                t.title AS topic_title, t.is_deleted AS topic_is_deleted,
                f.name AS board_name
         FROM posts p
         LEFT JOIN users author ON author.id = p.posterid
         LEFT JOIN users deleter ON deleter.id = p.deleted_by
         LEFT JOIN topics t ON t.id = p.threadid
         LEFT JOIN forums f ON f.id = p.boardid
         LEFT JOIN boards b ON b.id = f.categoryid
         WHERE p.id = ? AND p.is_deleted = 1
         LIMIT 1',
        [$postId]
    );
}

/**
 * Usage: Prepare a compact body preview for the deleted-posts list.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $body Message body.
 * @return string Normalized or display-ready string.
 */
function corebb_deleted_posts_preview(string $body): string
{
    $body = trim(strip_tags($body));
    if (function_exists('mb_substr')) {
        return mb_substr($body, 0, 240, 'UTF-8');
    }
    return substr($body, 0, 240);
}

/**
 * Usage: Build and process the deleted posts admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_deleted_posts_model(array $viewer, array $get, array $post): array
{
    corebb_mod_ensure_schema();

    $messages = [];
    $errors = [];
    $actionResult = null;
    $filterContext = corebb_admin_board_filter_context($viewer, array_merge($post, $get));
    $selectedBoardId = (int)$filterContext['selected_board_id'];
    $page = max(1, (int)($get['p'] ?? $post['p'] ?? 1));
    $perPage = 50;

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $action = (string)($post['action'] ?? '');
        $postId = (int)($post['postid'] ?? 0);
        if (!corebb_deleted_posts_token_ok($post)) {
            $errors[] = 'Security token expired. Please reload the Deleted Posts page and try again.';
        } elseif (!in_array($action, ['restore', 'purge'], true)) {
            $errors[] = 'Unknown deleted-posts action.';
        } elseif ($postId <= 0) {
            $errors[] = 'Unknown post ID.';
        } else {
            $row = corebb_deleted_posts_fetch_row($postId);
            if (!$row || !corebb_private_user_can_view_board_id((int)($row['boardid'] ?? 0), (int)($viewer['id'] ?? 0), (int)($viewer['accesslevel'] ?? 0))) {
                $errors[] = 'That post is not in the deleted-posts bin.';
            } else {
                $rightsError = corebb_deleted_posts_can_modify($viewer, $row);
                if ($rightsError !== '') {
                    $errors[] = $rightsError;
                } elseif ($action === 'restore') {
                    $actionResult = corebb_mod_restore_post($postId);
                } elseif ($action === 'purge') {
                    $actionResult = corebb_mod_purge_post($postId);
                }

                if (is_array($actionResult)) {
                    if (!empty($actionResult['ok'])) {
                        $messages[] = (string)($actionResult['message'] ?? 'Action completed.');
                    } else {
                        $errors[] = (string)($actionResult['message'] ?? 'Action failed.');
                    }
                }
            }
        }
    }

    [$visibleSql, $visibleParams] = corebb_private_sql_visible_board_clause('f', 'b', (int)($viewer['id'] ?? 0), (int)($viewer['accesslevel'] ?? 0));
    $where = ['p.is_deleted = 1', $visibleSql];
    $params = $visibleParams;
    if ($selectedBoardId > 0) {
        $where[] = 'p.boardid = ?';
        $params[] = $selectedBoardId;
    }
    $whereSql = implode(' AND ', $where);
    $total = (int)db_value('SELECT COUNT(*) FROM posts p LEFT JOIN forums f ON f.id = p.boardid LEFT JOIN boards b ON b.id = f.categoryid WHERE ' . $whereSql, $params, 0);
    $totalPages = max(1, (int)ceil(max(1, $total) / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $rows = db_all(
        'SELECT p.id, p.posterid, p.author, p.title, p.body, p.threadid, p.boardid, p.posttime, p.posttimeraw, p.deleted_at, p.deleted_by, p.delete_reason,
                author.username AS author_username, author.accesslevel AS author_accesslevel,
                deleter.username AS deleted_by_username,
                t.title AS topic_title, t.is_deleted AS topic_is_deleted,
                f.name AS board_name
         FROM posts p
         LEFT JOIN users author ON author.id = p.posterid
         LEFT JOIN users deleter ON deleter.id = p.deleted_by
         LEFT JOIN topics t ON t.id = p.threadid
         LEFT JOIN forums f ON f.id = p.boardid
         LEFT JOIN boards b ON b.id = f.categoryid
         WHERE ' . $whereSql . '
         ORDER BY p.deleted_at DESC, p.id DESC
         LIMIT ' . (int)$offset . ', ' . (int)$perPage,
        $params
    );

    $prepared = [];
    foreach ($rows as $row) {
        $postId = (int)($row['id'] ?? 0);
        $topicId = (int)($row['threadid'] ?? 0);
        $boardId = (int)($row['boardid'] ?? 0);
        $authorId = (int)($row['posterid'] ?? 0);
        $authorName = trim((string)($row['author_username'] ?? $row['author'] ?? ''));
        $row['_author_name'] = $authorName !== '' ? $authorName : ($authorId > 0 ? 'User #' . $authorId : 'Unknown');
        $row['_author_url'] = $authorId > 0 ? '/admin/?act=user_pages&userid=' . $authorId : '';
        $row['_deleted_by_display'] = (int)($row['deleted_by'] ?? 0) > 0
            ? ((string)($row['deleted_by_username'] ?? '') !== '' ? (string)$row['deleted_by_username'] : '#' . (int)$row['deleted_by'])
            : 'Unknown';
        $row['_preview'] = corebb_deleted_posts_preview((string)($row['body'] ?? ''));
        $row['_has_more_preview'] = strlen((string)($row['body'] ?? '')) > strlen((string)$row['_preview']);
        $row['_view_url'] = '/admin/?act=view_message&method=view&messageid=' . $postId;
        $row['_topic_url'] = $topicId > 0 ? '/topic/' . $topicId . '/p1/#post' . $postId : '';
        $row['_board_url'] = $boardId > 0 ? '/board/' . $boardId . '/' : '';
        $row['_rights_error'] = corebb_deleted_posts_can_modify($viewer, $row);
        $prepared[] = $row;
    }

    $baseParams = ['act' => 'deleted_posts'];
    if ($selectedBoardId > 0) {
        $baseParams['boardid'] = $selectedBoardId;
    }
    $pageParams = $baseParams;
    $pageParams['p'] = '{page}';
    $pageBase = '/admin/?' . http_build_query($pageParams, '', '&', PHP_QUERY_RFC3986);
    $actionParams = $baseParams;
    $actionParams['p'] = $page;
    $actionUrl = '/admin/?' . http_build_query($actionParams, '', '&', PHP_QUERY_RFC3986);
    if (is_array($actionResult) && (string)($actionResult['redirect'] ?? '') !== '') {
        $actionResult['redirect'] = $actionUrl;
    }

    return [
        'messages' => $messages,
        'errors' => $errors,
        'token' => corebb_deleted_posts_token(),
        'rows' => $prepared,
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => $totalPages,
        'selected_board_id' => $selectedBoardId,
        'selected_board_label' => (string)$filterContext['selected_board_label'],
        'board_options' => $filterContext['board_options'],
        'action_url' => $actionUrl,
        'pagination' => corebb_pagination_model($totalPages > 1 ? $pageBase : '', $page, $totalPages, 'BoardRowBLink'),
        'action_result' => $actionResult,
    ];
}
?>
