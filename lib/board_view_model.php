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
 |  board_view_model.php  - Data loader for the          |
 |  board/topic-list template.                           |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/performance_helpers.php';
require_once __DIR__ . '/poll_helpers.php';
require_once __DIR__ . '/pagination_helpers.php';
require_once __DIR__ . '/moderation_helpers.php';
require_once __DIR__ . '/private_board_helpers.php';

/**
 * Usage: Build board-scoped admin moderation links for moderators.
 * Referenced by: corebb_board_fetch_model() and views/pages/board.twig.
 *
 * @param int $boardId Board id from the current board page.
 * @return array{visible: bool, mod_requests_url: string, deleted_posts_url: string}
 */
function corebb_board_moderation_links_model(int $boardId): array
{
    if ($boardId <= 0 || !function_exists('corebb_mod_can_moderate') || !corebb_mod_can_moderate()) {
        return [
            'visible' => false,
            'mod_requests_url' => '',
            'deleted_posts_url' => '',
        ];
    }

    return [
        'visible' => true,
        'mod_requests_url' => '/admin/?act=mod_requests&boardid=' . $boardId,
        'deleted_posts_url' => '/admin/?act=deleted_posts&boardid=' . $boardId,
    ];
}

/**
 * Usage: Build the board/topic-list page model.
 * Referenced by: controllers/forum.php?action=board and api/v1/index.php.
 *
 * @param int $boardId Board id from the route.
 * @param int $page Requested page number.
 * @param string $boardScript Fallback route used when pretty URL helpers are unavailable.
 * @return array<string, mixed> Board display state, topics, pagination, and permission flags.
 */
function corebb_board_fetch_model(int $boardId, int $page, string $boardScript = 'controllers/forum.php?action=board'): array
{
    global $userlogindata_a, $QueryCount, $debug_desc;

    corebb_mod_ensure_schema();
    corebb_private_ensure_schema();

    if ($boardId <= 0) {
        return [
            'missing' => true,
            'message' => 'Board does not exist.',
        ];
    }

    $board = corebb_private_board_row($boardId);
    $QueryCount++;
    if (!$board || !corebb_private_user_can_view_board_row($board)) {
        return [
            'missing' => true,
            'message' => 'Board does not exist.',
        ];
    }

    $archiveReadOnly = !corebb_secure_archive_user_can_write_board_row($board, (int)($userlogindata_a['accesslevel'] ?? 0));

    $perPage = corebb_current_board_topics_per_page();

    $currentPage = max(1, $page);
    $topicCount = max(0, corebb_forum_topic_count($board));
    $totalPages = max(1, (int)ceil(max(1, $topicCount) / $perPage));
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $perPage;

    $cookName = 'bb' . $boardId;
    $cookieSet = false;
    if (function_exists('MakeCookie')) {
        $cookieSet = (bool)MakeCookie($cookName, $board['lastpstdatets'] ?? '');
        $debug_desc .= "<tr><td class='wb-bg-debug-label'> <b>Set Cookie for board ID:</b> {$boardId} <b>Name:</b> " . htmlspecialchars($cookName, ENT_QUOTES) . "</td><td class='wb-bg-debug-value'> " . ($cookieSet ? 'True' : 'False') . '</td></tr>';
    }

    $isFavorite = false;
    if (loggedin()) {
        $isFavorite = db_exists('SELECT 1 FROM favoriteboards WHERE ownerid = ? AND boardid = ? LIMIT 1', [(int)($userlogindata_a['id'] ?? 0), $boardId]);
        $QueryCount++;
    }

    $rawTopics = [];
    $topicSql = "SELECT t.* FROM topics t WHERE t.boardid = ? AND t.is_deleted = 0 AND EXISTS (SELECT 1 FROM posts p WHERE p.threadid = t.id AND p.is_deleted = 0 LIMIT 1) ORDER BY t.sticky DESC, t.lastpost DESC LIMIT " . (int)$offset . ', ' . (int)$perPage;
    $rawTopics = db_all($topicSql, [$boardId]);
    $QueryCount++;

    $topicIds = array_map(static fn($topic) => (int)($topic['id'] ?? 0), $rawTopics);
    $latestPosts = corebb_board_latest_posts_by_topic($topicIds);
    $topicPostCounts = corebb_board_post_counts_by_topic($topicIds);
    $pollTopicFlags = corebb_poll_topic_flags($topicIds);

    $topics = [];
    foreach ($rawTopics as $topic) {
        $topicId = (int)($topic['id'] ?? 0);
        $prepared = corebb_board_prepare_topic_row($topic, $board, $latestPosts[$topicId] ?? null, $topicPostCounts[$topicId] ?? null);
        $prepared['_has_poll'] = !empty($pollTopicFlags[$topicId]);
        $prepared['has_poll'] = !empty($pollTopicFlags[$topicId]);
        $topics[] = $prepared;
    }

    $pagination = corebb_board_pagination_model('', $currentPage, $totalPages);
    if ($topicCount > $perPage) {
        $separator = str_contains($boardScript, '?') ? '&' : '?';
        $boardPageUrl = function_exists('corebb_board_url') ? str_replace('/p999999/', '/p{page}/', corebb_board_url($boardId, 999999, (string)($board['name'] ?? 'Board'))) : $boardScript . $separator . 'id=' . $boardId . '&p={page}';
        $pagination = corebb_board_pagination_model($boardPageUrl, $currentPage, $totalPages);
    }

    return [
        'missing' => false,
        'boardId' => $boardId,
        'board' => $board,
        'boardName' => (string)($board['name'] ?? 'Board'),
        'boardDescription' => (string)($board['description'] ?? ''),
        'perPage' => $perPage,
        'topicCount' => $topicCount,
        'totalPages' => $totalPages,
        'currentPage' => $currentPage,
        'topics' => $topics,
        'isLoggedIn' => function_exists('loggedin') && loggedin(),
        'newTopicUrl' => function_exists('corebb_new_topic_url') ? corebb_new_topic_url($boardId) : '/post/new/b' . $boardId . '/',
        'newPollUrl' => function_exists('corebb_new_poll_url') ? corebb_new_poll_url($boardId) : '/post/new/b' . $boardId . '/poll/',
        'favoriteUrl' => function_exists('corebb_public_url') ? corebb_public_url('board/' . $boardId . '/favorite/') : '/board/' . $boardId . '/favorite/',
        'loginUrl' => function_exists('corebb_public_url') ? corebb_public_url('auth.php?action=login') : '/login/',
        'pagination' => $pagination,
        'isFavorite' => $isFavorite,
        'cookieName' => $cookName,
        'cookieSet' => $cookieSet,
        'archiveReadOnly' => $archiveReadOnly,
        'moderation' => corebb_board_moderation_links_model($boardId),
    ];
}


/**
 * Usage: Build board pagination via the shared pagination helper.
 * Referenced by: corebb_board_fetch_model().
 *
 * @param string $urlPattern URL containing a {page} placeholder.
 * @param int $currentPage Current board page.
 * @param int $totalPages Total board pages.
 * @param string $class CSS class used by the pagination partial.
 * @return array<string, mixed> Pagination model for Twig.
 */
function corebb_board_pagination_model(string $urlPattern, int $currentPage, int $totalPages, string $class = 'MainMenuFont'): array
{
    return corebb_pagination_model($urlPattern, $currentPage, $totalPages, $class);
}


/**
 * Usage: Load the latest visible post row for each topic in a board/topic list.
 * Referenced by: board, search, and profile-content view models.
 *
 * @param array<int, mixed> $topicIds Topic ids to inspect.
 * @return array<int, array<string, mixed>> Latest post rows keyed by topic id.
 */
function corebb_board_latest_posts_by_topic(array $topicIds): array
{
    global $QueryCount;

    $topicIds = array_values(array_unique(array_filter(array_map('intval', $topicIds), static fn($id) => $id > 0)));
    if (!$topicIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($topicIds), '?'));
    $sql = <<<SQL
SELECT p.*
FROM posts p
INNER JOIN (
    SELECT threadid, MAX(id) AS max_id
    FROM posts
    WHERE threadid IN ({$placeholders}) AND is_deleted = 0
    GROUP BY threadid
) lp ON lp.threadid = p.threadid AND lp.max_id = p.id
SQL;
    $rows = db_all($sql, $topicIds);
    $QueryCount++;

    $latest = [];
    foreach ($rows as $row) {
        $latest[(int)($row['threadid'] ?? 0)] = $row;
    }
    return $latest;
}

/**
 * Usage: Count visible posts for a batch of topics.
 * Referenced by: board, search, and profile-content view models.
 *
 * @param array<int, mixed> $topicIds Topic ids to count.
 * @return array<int, int> Post counts keyed by topic id.
 */
function corebb_board_post_counts_by_topic(array $topicIds): array
{
    global $QueryCount;

    $topicIds = array_values(array_unique(array_filter(array_map('intval', $topicIds), static fn($id) => $id > 0)));
    if (!$topicIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($topicIds), '?'));
    $rows = db_all(
        "SELECT threadid, COUNT(*) AS post_count FROM posts WHERE threadid IN ({$placeholders}) AND is_deleted = 0 GROUP BY threadid",
        $topicIds
    );
    $QueryCount++;

    $counts = [];
    foreach ($rows as $row) {
        $counts[(int)($row['threadid'] ?? 0)] = (int)($row['post_count'] ?? 0);
    }

    foreach ($topicIds as $topicId) {
        $counts[$topicId] = $counts[$topicId] ?? 0;
    }
    return $counts;
}

/**
 * Usage: Build compact per-topic page links shown on board/topic rows.
 * Referenced by: board, search, and profile-content view models.
 *
 * @param int $topicId Topic id being linked.
 * @param int $boardId Parent board id.
 * @param int $postCount Total visible posts in the topic.
 * @param int $perPage Posts per thread page.
 * @param string $boardName Parent board name used for pretty URL slugs.
 * @return array<string, mixed> Topic-page link model for Twig.
 */
function corebb_board_topic_pages_model(int $topicId, int $boardId, int $postCount, int $perPage, string $boardName = ''): array
{
    $postCount = max(0, $postCount);
    $perPage = max(1, $perPage);
    if ($postCount <= $perPage) {
        return ['visible' => false, 'items' => []];
    }

    $totalPages = (int)ceil($postCount / $perPage);
    $urlPattern = function_exists('corebb_thread_url')
        ? str_replace('/p999999/', '/p{page}/', corebb_thread_url($topicId, $boardId, 999999, $boardName))
        : "/topic/{$topicId}/p{page}/";
    $sequence = function_exists('corebb_compact_page_sequence')
        ? corebb_compact_page_sequence(1, $totalPages, 1)
        : range(1, $totalPages);

    $items = [];
    foreach ($sequence as $page) {
        if ($page === '...') {
            $items[] = ['type' => 'ellipsis'];
            continue;
        }
        $page = (int)$page;
        $items[] = [
            'type' => 'page',
            'page' => $page,
            'url' => str_replace('{page}', (string)$page, $urlPattern),
        ];
    }

    return [
        'visible' => true,
        'items' => $items,
    ];
}

/**
 * Usage: Decorate a raw topic row with display fields and canonical URLs.
 * Referenced by: corebb_board_fetch_model().
 *
 * @param array<string, mixed> $topic Raw topic row.
 * @param array<string, mixed> $board Parent board row.
 * @param array<string, mixed>|null $latestPost Latest post row for the topic.
 * @param int|null $actualPostCount Preloaded post count, or null to count on demand.
 * @return array<string, mixed> Topic row enriched for board_topic_row.twig.
 */
function corebb_board_prepare_topic_row(array $topic, array $board = [], ?array $latestPost = null, ?int $actualPostCount = null): array
{
    $topicId = (int)($topic['id'] ?? 0);
    $lastPostRaw = (string)($topic['lastpost'] ?? '');
    $lastPostDisplay = $lastPostRaw === '' ? '-' : convert_to_vndate($lastPostRaw);
    $topic['_lastpost_display'] = $lastPostDisplay;
    $postCount = $actualPostCount ?? corebb_topic_post_count($topicId);
    $locked = ((int)($topic['locked'] ?? 0) === 1);
    $sticky = ((int)($topic['sticky'] ?? 0) === 1);
    $replyCount = max(0, (int)$postCount - 1);
    $boardId = (int)($board['id'] ?? ($topic['boardid'] ?? 0));
    $boardName = (string)($board['name'] ?? 'Board');
    $threadUrl = function_exists('corebb_thread_url') ? corebb_thread_url($topicId, $boardId, 1, $boardName) : '/topic/' . $topicId . '/';

    $topic['_locked'] = $locked;
    $topic['_sticky'] = $sticky;
    $topic['_reply_count'] = $replyCount;
    $topic['_board_id'] = $boardId;
    $topic['_board_name'] = $boardName;
    $topic['_thread_url'] = $threadUrl;

    $lastPosterId = (int)($topic['posterid'] ?? 0);
    $lastPosterName = (string)($topic['author'] ?? '');
    $lastPostId = 0;
    if ($latestPost) {
        $lastPosterId = (int)($latestPost['posterid'] ?? $lastPosterId);
        $lastPosterName = (string)($latestPost['author'] ?? $lastPosterName);
        $lastPostId = (int)($latestPost['id'] ?? 0);
    }

    $threadPerPage = corebb_current_thread_posts_per_page();
    $lastPostPage = max(1, (int)ceil(max(1, (int)$postCount) / $threadPerPage));
    $topic['_last_post_url'] = function_exists('corebb_thread_url')
        ? corebb_thread_url($topicId, (int)$topic['_board_id'], $lastPostPage, (string)$topic['_board_name'], $lastPostId)
        : '/topic/' . $topicId . '/p' . $lastPostPage . '/' . ($lastPostId > 0 ? '#post' . $lastPostId : '');
    $topic['topic_id'] = $topicId;
    $topic['subject'] = (string)($topic['title'] ?? 'Untitled Topic');
    $topic['thread_url'] = $threadUrl;
    $topic['poster_id'] = (int)($topic['posterid'] ?? 0);
    $topic['poster_name'] = (string)($topic['author'] ?? '');
    $topic['last_poster_id'] = $lastPosterId;
    $topic['last_poster_name'] = $lastPosterName;
    $topic['last_post_url'] = (string)$topic['_last_post_url'];
    $topic['last_post_display'] = $lastPostDisplay;
    $topic['is_locked'] = $locked;
    $topic['is_sticky'] = $sticky;
    $topic['has_poll'] = !empty($topic['_has_poll']);
    $topic['reply_count'] = $replyCount;
    $topic['topic_pages'] = corebb_board_topic_pages_model($topicId, $boardId, (int)$postCount, $threadPerPage, $boardName);
    return $topic;
}
