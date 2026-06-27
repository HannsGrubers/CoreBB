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
 |  usercp_view_model.php  - User CP data loader for     |
 |  CoreBB.                                              |
 +-------------------------------------------------------+*/

if (!defined('IN_BOARDS')) {
    die('Access denied.');
}
require_once __DIR__ . '/moderation_helpers.php';
require_once __DIR__ . '/pm_helpers.php';
require_once __DIR__ . '/notification_helpers.php';
require_once __DIR__ . '/private_board_helpers.php';
require_once __DIR__ . '/usercp_settings_view_model.php';
require_once __DIR__ . '/vip_style_helpers.php';

/**
 * Usage: Load favorite boards for the User CP favorite-board manager.
 * Referenced by: controllers/usercp.php action=favorites.
 *
 * @param int $userId Current logged-in user id.
 * @param array<string, mixed> $viewer Current logged-in user row.
 * @return array<string, mixed> Common User CP chrome plus favorite board rows.
 */
function corebb_usercp_favorites_model(int $userId, array $viewer = []): array
{
    $model = corebb_usercp_base_model($userId);
    $model['favoriteBoards'] = [];
    $model['favoriteBoardCount'] = 0;

    if ($userId <= 0) {
        return $model;
    }

    corebb_private_ensure_schema();
    $accessLevel = (int)($viewer['accesslevel'] ?? 0);
    $rows = db_all(
        'SELECT fb.id AS favorite_id, fb.boardid, fb.adddate,
                f.id AS id, f.id AS forum_id, f.name, f.description, f.topiccount, f.postcount, f.lastpstdate,
                f.private, b.private AS category_private, b.secure_archive AS category_secure_archive,
                b.name AS category_name
           FROM favoriteboards fb
           LEFT JOIN forums f ON f.id = fb.boardid
           LEFT JOIN boards b ON b.id = f.categoryid
          WHERE fb.ownerid = ?
          ORDER BY fb.id ASC',
        [$userId]
    );

    foreach ($rows as $row) {
        $boardId = (int)($row['boardid'] ?? 0);
        $forumId = (int)($row['forum_id'] ?? 0);
        $visible = $forumId > 0 && corebb_private_user_can_view_board_row($row, $userId, $accessLevel);
        $addDate = (string)($row['adddate'] ?? '');
        $lastPost = (string)($row['lastpstdate'] ?? '');
        $name = $visible ? (string)($row['name'] ?? 'Untitled Board') : 'Unavailable Board';

        $model['favoriteBoards'][] = [
            'favoriteId' => (int)($row['favorite_id'] ?? 0),
            'boardId' => $boardId,
            'name' => $name,
            'categoryName' => $visible ? (string)($row['category_name'] ?? '') : '',
            'description' => $visible ? (string)($row['description'] ?? '') : '',
            'topicCount' => $visible ? (int)($row['topiccount'] ?? 0) : 0,
            'postCount' => $visible ? (int)($row['postcount'] ?? 0) : 0,
            'addedDisplay' => $addDate !== '' ? convert_to_vndate($addDate) : '-',
            'lastPostDisplay' => ($visible && $lastPost !== '') ? convert_to_vndate($lastPost) : '-',
            'boardUrl' => $visible ? corebb_board_url($boardId, 1, $name) : '',
            'isVisible' => $visible,
        ];
    }

    $model['favoriteBoardCount'] = count($model['favoriteBoards']);
    return $model;
}

/**
 * Usage: Remove one favorite-board row owned by the current User CP user.
 * Referenced by: controllers/usercp.php action=favorites.
 *
 * @param int $userId Current logged-in user id.
 * @param int $favoriteId favoriteboards.id submitted by the manager form.
 * @param int $boardId Board id fallback for older or malformed rows.
 * @return bool True when an owned favorite row was found and deleted.
 */
function corebb_usercp_remove_favorite_board(int $userId, int $favoriteId, int $boardId = 0): bool
{
    if ($userId <= 0) {
        return false;
    }

    if ($favoriteId > 0) {
        if (!db_exists('SELECT 1 FROM favoriteboards WHERE id = ? AND ownerid = ? LIMIT 1', [$favoriteId, $userId])) {
            return false;
        }
        return db_run('DELETE FROM favoriteboards WHERE id = ? AND ownerid = ? LIMIT 1', [$favoriteId, $userId]);
    }

    if ($boardId > 0) {
        if (!db_exists('SELECT 1 FROM favoriteboards WHERE boardid = ? AND ownerid = ? LIMIT 1', [$boardId, $userId])) {
            return false;
        }
        return db_run('DELETE FROM favoriteboards WHERE boardid = ? AND ownerid = ?', [$boardId, $userId]);
    }

    return false;
}

/**
 * Usage: Build the User CP dashboard model for counts and recent user topics.
 * Referenced by: controllers/usercp.php action=index.
 *
 * @param int $userId Current logged-in user id.
 * @return array<string, mixed> Dashboard counts and recent topic rows for Twig.
 */
function corebb_fetch_usercp_model(int $userId): array
{
    corebb_mod_ensure_schema();
    corebb_private_ensure_schema();

    $model = [
        'userId' => $userId,
        'unreadPmCount' => 0,
        'notificationCount' => 0,
        'canEditAppearance' => false,
        'recentTopics' => [],
    ];

    if ($userId <= 0) {
        return $model;
    }

    $model['unreadPmCount'] = corebb_pm_count($userId, 'unread');
    $model['notificationCount'] = corebb_notifications_uncleared_count($userId, false);
    $model['canEditAppearance'] = corebb_vip_style_user_can_self_manage($userId);

    [$visibleSql, $visibleParams] = corebb_private_sql_visible_board_clause('f', 'b');
    $topics = db_all(
        'SELECT t.id, t.title, t.boardid, t.lastpost, t.time, t.replycount, t.postcount, f.name AS board_name
           FROM topics t
           LEFT JOIN forums f ON f.id = t.boardid
           LEFT JOIN boards b ON b.id = f.categoryid
          WHERE t.posterid = ? AND t.is_deleted = 0 AND ' . $visibleSql . '
          ORDER BY COALESCE(NULLIF(t.lastpost, 0), t.time) DESC, t.id DESC
          LIMIT 5',
        array_merge([$userId], $visibleParams)
    );

    foreach ($topics as $topic) {
        $postCount = isset($topic['postcount']) ? (int)$topic['postcount'] : 0;
        $replyCount = isset($topic['replycount']) ? (int)$topic['replycount'] : max(0, $postCount - 1);
        if ($replyCount < 0) {
            $replyCount = 0;
        }
        $lastPost = (string)($topic['lastpost'] ?? '');
        if ($lastPost === '' || $lastPost === '0') {
            $lastPost = (string)($topic['time'] ?? '');
        }

        $model['recentTopics'][] = [
            'id' => (int)($topic['id'] ?? 0),
            'title' => (string)($topic['title'] ?? 'Untitled Topic'),
            'boardId' => (int)($topic['boardid'] ?? 0),
            'boardName' => (string)($topic['board_name'] ?? 'Unknown Board'),
            'replyCount' => $replyCount,
            'lastPost' => $lastPost,
            'lastPostDisplay' => $lastPost === '' ? '-' : convert_to_vndate($lastPost),
        ];
        $lastIndex = count($model['recentTopics']) - 1;
        $recent = $model['recentTopics'][$lastIndex];
        $model['recentTopics'][$lastIndex]['topicUrl'] = corebb_thread_url((int)$recent['id'], (int)$recent['boardId'], 1, (string)$recent['boardName']);
        $model['recentTopics'][$lastIndex]['boardUrl'] = corebb_board_url((int)$recent['boardId'], 1, (string)$recent['boardName']);
    }

    return $model;
}
