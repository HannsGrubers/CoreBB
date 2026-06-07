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
require_once __DIR__ . '/vip_style_helpers.php';

/**
 * Usage: Build a topic URL for the User CP recent-topics list.
 * Referenced by: corebb_fetch_usercp_model().
 *
 * @param int $topicId Topic id to link to.
 * @param int $boardId Parent board id used by the canonical URL helper.
 * @param string $boardName Parent board name used for URL slugs when available.
 * @return string Public topic URL, or "#" when the topic id is invalid.
 */
function corebb_usercp_thread_url(int $topicId, int $boardId, string $boardName = ''): string
{
    if ($topicId <= 0) {
        return '#';
    }

    return function_exists('corebb_thread_url')
        ? corebb_thread_url($topicId, $boardId, 1, $boardName)
        : '/topic/' . $topicId . '/';
}

/**
 * Usage: Build a board URL for the User CP recent-topics list.
 * Referenced by: corebb_fetch_usercp_model().
 *
 * @param int $boardId Board id to link to.
 * @param string $boardName Board name used for URL slugs when available.
 * @return string Public board URL, or "#" when the board id is invalid.
 */
function corebb_usercp_board_url(int $boardId, string $boardName = ''): string
{
    if ($boardId <= 0) {
        return '#';
    }

    return function_exists('corebb_board_url')
        ? corebb_board_url($boardId, 1, $boardName)
        : '/board/' . $boardId . '/';
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
        $model['recentTopics'][$lastIndex]['topicUrl'] = corebb_usercp_thread_url((int)$recent['id'], (int)$recent['boardId'], (string)$recent['boardName']);
        $model['recentTopics'][$lastIndex]['boardUrl'] = corebb_usercp_board_url((int)$recent['boardId'], (string)$recent['boardName']);
    }

    return $model;
}
