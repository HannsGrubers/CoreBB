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

require_once __DIR__ . '/../helpers/moderation_helpers.php';
require_once __DIR__ . '/../helpers/private_board_helpers.php';
require_once __DIR__ . '/../helpers/pagination_helpers.php';
require_once __DIR__ . '/../helpers/admin_board_filter_helpers.php';
require_once __DIR__ . '/admin_user_tools_view_model.php';

/**
 * Usage: Return the form token for this admin page.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_deleted_posts_token(): string
{
    return corebb_security_named_token('admin_deleted_posts_token');
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
    return corebb_security_named_token_valid('admin_deleted_posts_token', $post, 'admin_deleted_posts_token');
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
    if (!corebb_secure_archive_user_can_write_board_row($row, $viewerLevel)) {
        return corebb_secure_archive_denied_message();
    }
    if ($viewerId > 0 && $authorId > 0 && $viewerId === $authorId) {
        return '';
    }
    if ($authorLevel >= $viewerLevel) {
        return 'You cannot restore or purge a post written by a user with equal or higher rights.';
    }
    return '';
}

/** Fetch the same visible deletion record for display and for moderation checks. */
function corebb_deleted_posts_query(string $where): string
{
    return 'SELECT p.id, p.posterid, p.author, p.title, p.body, p.threadid, p.boardid, p.deleted_at, p.deleted_by, p.delete_reason,
                author.username AS author_username, author.accesslevel AS author_accesslevel, deleter.username AS deleted_by_username,
                t.id AS topic_id, t.title AS topic_title, t.is_deleted AS topic_is_deleted,
                CASE WHEN t.is_deleted=1 THEN (SELECT MIN(op.id) FROM posts op WHERE op.threadid=t.id) ELSE 0 END AS original_id,
                f.name AS board_name, f.secure_archive, b.secure_archive AS category_secure_archive
            FROM posts p JOIN forums f ON f.id=p.boardid LEFT JOIN boards b ON b.id=f.categoryid
            LEFT JOIN users author ON author.id=p.posterid LEFT JOIN users deleter ON deleter.id=p.deleted_by
            LEFT JOIN topics t ON t.id=p.threadid AND t.boardid=p.boardid
            WHERE ' . $where;
}

function corebb_admin_deleted_posts_model(array $viewer, array $get, array $post): array
{
    if (corebb_admin_viewer_id($viewer) <= 0 || !corebb_admin_can_access_tool($viewer, 'deleted_posts')) {
        return ['errors'=>['You do not have access to Deleted Posts.']];
    }
    corebb_mod_ensure_schema();
    $input = $get + $post;
    $context = corebb_admin_board_filter_context($viewer, $input);
    $selected = (int)$context['selected_board_id'];
    $page = max(1,(int)($input['p'] ?? 1));
    $model = $context + [
        'can_view_message'=>corebb_admin_can_access_tool($viewer,'view_message'),
        'can_view_member'=>corebb_admin_can_access_tool($viewer,'user_pages'),
        'can_view_requests'=>corebb_admin_can_access_tool($viewer,'mod_requests'),
        'token'=>corebb_deleted_posts_token(), 'messages'=>[], 'errors'=>[], 'rows'=>[],
    ];
    if (corebb_admin_board_filter_id($input) !== $selected) {
        $model['errors'][] = 'This board is unavailable. Choose a visible board to review.';
        return $model;
    }
    [$visible,$params] = corebb_private_sql_visible_board_clause('f','b',(int)$viewer['id'],(int)$viewer['accesslevel']);
    $where = 'p.is_deleted=1 AND (' . $visible . ')';
    $base = '/admin/?act=deleted_posts';
    if ($selected) {
        $where .= ' AND p.boardid=?';
        $params[] = $selected;
        $base .= '&boardid=' . $selected;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = $post['action'] ?? '';
        $id = filter_var($post['postid'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!corebb_security_csrf_valid($post) || !corebb_deleted_posts_token_ok($post)) {
            $error = 'Your form expired. Reopen Deleted Posts before continuing.';
        } elseif (!$id || !in_array($action,['restore','purge'],true)) {
            $error = 'Select a deleted post and an available action.';
        } elseif ($action === 'purge' && ($post['confirm_purge'] ?? '') !== '1') {
            $error = 'Review the permanent deletion and confirm it first.';
        } else {
            $row = db_one(corebb_deleted_posts_query($where . ' AND p.id=?'), [...$params,$id]);
            $error = $row ? corebb_deleted_posts_can_modify($viewer,$row) : 'That post is no longer available in this queue.';
            if ($row && $error === '') {
                if ($action === 'restore') {
                    $whole = ($post['restore_scope'] ?? 'post') === 'topic';
                    $result = corebb_mod_restore_post($id, $whole);
                } else {
                    $result = corebb_mod_purge_post($id);
                }
                if ($result['ok']) {
                    $model['notice'] = "Post #$id · " . $result['message'];
                    $model['return_url'] = $base . '&p=' . $page;
                    return $model;
                }
                $error = $result['message'];
            }
        }
        $model['errors'][] = $error;
    }
    $count = db_value('SELECT COUNT(*) FROM posts p JOIN forums f ON f.id=p.boardid LEFT JOIN boards b ON b.id=f.categoryid WHERE ' . $where, $params);
    if ($count === null) { $model['errors'][] = 'Could not load the deleted posts queue.'; return $model; }
    $total = (int)$count;
    $perPage = 50;
    $pages = max(1,(int)ceil($total/$perPage));
    $page = min($page,$pages);
    $offset = ($page-1)*$perPage;
    $rows = db_all(corebb_deleted_posts_query($where) . ' ORDER BY p.deleted_at DESC, p.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,$params);
    if (db_error() !== '') { $model['errors'][] = 'Could not load the deleted posts queue.'; return $model; }
    foreach ($rows as $row) {
        $body = trim(strip_tags((string)$row['body']));
        $body = preg_replace('~\[/?(?:b|i|u|s|quote|color|url|size)(?:=[^\]]*)?\]~i', '', $body) ?? $body;
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);
        // Cut at a Unicode character boundary, even on hosts without mbstring.
        preg_match('/\\A.{0,240}/us', $body, $preview);
        $row['preview'] = $preview[0] ?? '';
        if (strlen($row['preview']) < strlen($body)) { $row['preview'] .= '…'; }
        unset($row['body']);
        $topic = trim((string)$row['topic_title']);
        $subject = preg_replace('/^(?:re\s*:\s*)+/i', '', trim((string)$row['title']));
        $row['context_topic'] = strcasecmp($subject, $topic) === 0 ? '' : $topic;
        $row['author_label'] = trim((string)($row['author_username'] ?? $row['author'])) ?: ($row['posterid'] ? 'User #' . $row['posterid'] : 'Unknown author');
        $row['deleted_by_label'] = $row['deleted_by_username'] ?: ($row['deleted_by'] ? 'User #' . $row['deleted_by'] : 'Unknown staff member');
        $row['board_url'] = corebb_board_url((int)$row['boardid'],1,(string)$row['board_name']);
        $row['restore_topic'] = (int)$row['original_id'] === (int)$row['id'];
        $row['blocked'] = corebb_deleted_posts_can_modify($viewer,$row);
        $model['rows'][] = $row;
    }
    return $model + [
        'total'=>$total, 'first'=>$offset+1, 'last'=>$offset+count($rows),
        'action_url'=>$base . '&p=' . $page,
        'pagination'=>corebb_pagination_model($pages>1 ? $base . '&p={page}' : '',$page,$pages),
    ];
}
