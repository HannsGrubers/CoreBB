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
 |  index_view_model.php  - Data loader for the board    |
 |  index template.                                      |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../helpers/performance_helpers.php';
require_once __DIR__ . '/../helpers/moderation_helpers.php';
require_once __DIR__ . '/../helpers/private_board_helpers.php';

/**
 * Usage: Ensure board-index compatibility columns exist before the index loads.
 * Referenced by: corebb_fetch_index_model().
 *
 * @return void
 */
function corebb_index_ensure_schema(): void
{
    corebb_perf_add_column_if_missing('boards', 'default_open', 'TINYINT(1) NOT NULL DEFAULT 0');
}

/**
 * Usage: Build an expanded/default-open category block with visible forums.
 * Referenced by: corebb_fetch_index_model().
 *
 * @param array<string, mixed> $category Raw boards row.
 * @param int $userId Current user id, or 0 for guests.
 * @param int $accessLevel Current user access level.
 * @param bool $showEmptyArchiveBoards Whether empty secure-archive boards should be shown.
 * @return array<string, mixed> Open category model with forums and hidden-board counts.
 */
function corebb_index_build_open_category(array $category, int $userId, int $accessLevel, bool $showEmptyArchiveBoards): array
{
    $hiddenEmptyArchiveBoards = 0;
    $emptySecureArchiveBoards = 0;
    return [
        'category' => corebb_index_prepare_category_row($category),
        'forums' => corebb_index_fetch_forums_for_category(
            (int)$category['id'],
            $userId,
            $accessLevel,
            $showEmptyArchiveBoards,
            $hiddenEmptyArchiveBoards,
            $emptySecureArchiveBoards
        ),
        'hiddenEmptyArchiveBoardCount' => $hiddenEmptyArchiveBoards,
        'emptySecureArchiveBoardCount' => $emptySecureArchiveBoards,
    ];
}

/**
 * Usage: Build the public board-index model.
 * Referenced by: index.php and api/v1/index.php.
 *
 * @param int|null $expandedCategoryId Category id explicitly expanded from the route.
 * @param bool $showEmptyArchiveBoards Whether empty secure-archive boards should be shown.
 * @param int|null $collapsedCategoryId Default-open category id explicitly collapsed by the viewer.
 * @return array<string, mixed> Board-index display state for Twig/API serializers.
 */
function corebb_fetch_index_model(?int $expandedCategoryId = null, bool $showEmptyArchiveBoards = false, ?int $collapsedCategoryId = null): array
{
    global $userlogindata_a, $MyData, $QueryCount;

    corebb_mod_ensure_schema();
    corebb_private_ensure_schema();
    corebb_index_ensure_schema();

    $userId = corebb_load_logged_in_user() ? (int)($MyData['id'] ?? $userlogindata_a['id'] ?? 0) : 0;
    $accessLevel = (int)($userlogindata_a['accesslevel'] ?? 0);

    $model = [
        'expandedCategoryId' => $expandedCategoryId,
        'expandedCategory' => null,
        'expandedForums' => [],
        'openCategories' => [],
        'favorites' => [],
        'collapsedCategories' => [],
        'message' => '',
        'showEmptyArchiveBoards' => $showEmptyArchiveBoards,
        'hiddenEmptyArchiveBoardCount' => 0,
        'emptySecureArchiveBoardCount' => 0,
    ];

    $openedCategoryIds = [];
    if ($expandedCategoryId !== null && $expandedCategoryId > 0) {
        $category = corebb_index_fetch_category($expandedCategoryId);
        if (!$category) {
            $model['message'] = 'Unknown Board!';
        } elseif (!corebb_index_can_view_category($category, $userId, $accessLevel)) {
            $model['message'] = 'You do not have access to that board category.';
        } else {
            $openCategory = corebb_index_build_open_category($category, $userId, $accessLevel, $showEmptyArchiveBoards);
            $model['expandedCategory'] = $openCategory['category'];
            $model['expandedForums'] = $openCategory['forums'];
            $model['hiddenEmptyArchiveBoardCount'] = $openCategory['hiddenEmptyArchiveBoardCount'];
            $model['emptySecureArchiveBoardCount'] = $openCategory['emptySecureArchiveBoardCount'];
            $model['openCategories'][] = $openCategory;
            $openedCategoryIds[(int)$category['id']] = true;
        }
    } else {
        $model['favorites'] = corebb_index_fetch_favorites($userId);
    }

    $categories = [];
    $QueryCount++;
    foreach (db_all('SELECT * FROM boards ORDER BY position ASC, id ASC') as $category) {
        if (!corebb_index_can_view_category($category, $userId, $accessLevel)) {
            continue;
        }

        $categoryId = (int)$category['id'];
        if (isset($openedCategoryIds[$categoryId])) {
            continue;
        }

        if ((int)($category['default_open'] ?? 0) === 1 && $categoryId !== $collapsedCategoryId) {
            $model['openCategories'][] = corebb_index_build_open_category($category, $userId, $accessLevel, $showEmptyArchiveBoards);
            $openedCategoryIds[$categoryId] = true;
            continue;
        }

        $categories[] = corebb_index_prepare_category_row($category);
    }
    $model['collapsedCategories'] = $categories;

    return $model;
}

/**
 * Usage: Load one board category row by id.
 * Referenced by: corebb_fetch_index_model().
 *
 * @param int $categoryId Board category id.
 * @return array<string, mixed>|false|null Category row from db_one().
 */
function corebb_index_fetch_category(int $categoryId)
{
    global $QueryCount;
    $QueryCount++;
    return db_one('SELECT * FROM boards WHERE id = ? LIMIT 1', [$categoryId]);
}

/**
 * Usage: Add template-facing URL/title fields to a category row.
 * Referenced by: index category builders.
 *
 * @param array<string, mixed> $category Raw boards row.
 * @return array<string, mixed> Category row enriched for the index template.
 */
function corebb_index_prepare_category_row(array $category): array
{
    $categoryId = (int)($category['id'] ?? 0);
    $category['category_id'] = $categoryId;
    $category['title'] = (string)($category['name'] ?? 'Board Category');
    $category['expand_url'] = $categoryId > 0 ? '/?b=' . $categoryId : '/';
    $category['collapse_url'] = $categoryId > 0 ? '/?collapse=' . $categoryId : '/';
    $category['hide_empty_url'] = $categoryId > 0 ? '/?b=' . $categoryId : '/';
    $category['show_empty_url'] = $categoryId > 0 ? '/?b=' . $categoryId . '&show_empty=1' : '/?show_empty=1';
    return $category;
}

/**
 * Usage: Check whether a viewer can see a category.
 * Referenced by: corebb_fetch_index_model().
 *
 * @param array<string, mixed> $category Raw boards row.
 * @param int $userId Current user id, or 0 for guests.
 * @param int $accessLevel Current user access level.
 * @return bool True when the category should appear on the index.
 */
function corebb_index_can_view_category(array $category, int $userId, int $accessLevel): bool
{
    global $QueryCount;
    $QueryCount++;
    return corebb_private_category_visible($category, $userId, $accessLevel);
}

/**
 * Usage: Load visible forums for one category and optionally hide empty secure archives.
 * Referenced by: corebb_index_build_open_category().
 *
 * @param int $categoryId Category id to load.
 * @param int|null $userId Current user id, or null to let private-board helpers infer it.
 * @param int|null $accessLevel Current access level, or null to let helpers infer it.
 * @param bool $showEmptyArchiveBoards Whether empty secure-archive boards should be shown.
 * @param int|null $hiddenEmptyArchiveBoards Output count of hidden empty secure-archive boards.
 * @param int|null $emptySecureArchiveBoards Output count of all empty secure-archive boards encountered.
 * @return array<int, array<string, mixed>> Prepared forum rows for the category.
 */
function corebb_index_fetch_forums_for_category(
    int $categoryId,
    ?int $userId = null,
    ?int $accessLevel = null,
    bool $showEmptyArchiveBoards = false,
    ?int &$hiddenEmptyArchiveBoards = null,
    ?int &$emptySecureArchiveBoards = null
): array {
    global $QueryCount;
    $forums = [];
    $hiddenEmptyArchiveBoards = 0;
    $emptySecureArchiveBoards = 0;
    $QueryCount++;
    foreach (corebb_private_fetch_visible_forums_for_category($categoryId, $userId, $accessLevel) as $forum) {
        $prepared = corebb_index_prepare_forum_row($forum);
        if (corebb_index_is_empty_secure_archive_forum($prepared)) {
            $emptySecureArchiveBoards++;
            if (!$showEmptyArchiveBoards) {
                $hiddenEmptyArchiveBoards++;
                continue;
            }
        }
        $forums[] = $prepared;
    }
    return $forums;
}

/**
 * Usage: Determine whether a visible forum is an empty, read-only secure archive.
 * Referenced by: corebb_index_fetch_forums_for_category().
 *
 * @param array<string, mixed> $forum Prepared forum row.
 * @return bool True when the forum is a secure archive with no topics or posts.
 */
function corebb_index_is_empty_secure_archive_forum(array $forum): bool
{
    if (!corebb_secure_archive_board_is_effectively_locked($forum)) {
        return false;
    }

    return corebb_forum_topic_count($forum) <= 0 && corebb_forum_post_count($forum) <= 0;
}

/**
 * Usage: Load the current user's favorite boards for the collapsed index view.
 * Referenced by: corebb_fetch_index_model().
 *
 * @param int $userId Current logged-in user id.
 * @return array<int, array<string, mixed>> Prepared favorite forum rows.
 */
function corebb_index_fetch_favorites(int $userId): array
{
    global $QueryCount;
    if ($userId <= 0) {
        return [];
    }

    $forums = [];
    [$visibleSql, $visibleParams] = corebb_private_sql_visible_board_clause('f', 'b');
    $sql = 'SELECT f.*, b.private AS category_private, b.secure_archive AS category_secure_archive, b.name AS category_name
            FROM favoriteboards fb
            INNER JOIN forums f ON f.id = fb.boardid
            LEFT JOIN boards b ON b.id = f.categoryid
            WHERE fb.ownerid = ? AND ' . $visibleSql . '
            ORDER BY fb.id ASC';
    $QueryCount++;
    foreach (db_all($sql, array_merge([$userId], $visibleParams)) as $forum) {
        $forums[] = corebb_index_prepare_forum_row($forum);
    }
    return $forums;
}

/**
 * Usage: Add template-facing URL/count/new-post fields to a forum row.
 * Referenced by: category and favorite board loaders.
 *
 * @param array<string, mixed> $forum Raw forum row.
 * @return array<string, mixed> Forum row enriched for the index template.
 */
function corebb_index_prepare_forum_row(array $forum): array
{
    $forumId = (int)($forum['id'] ?? 0);
    $name = (string)($forum['name'] ?? 'Untitled Board');
    $lastPost = (string)($forum['lastpstdate'] ?? '');
    $lastPostDisplay = $lastPost === '' ? 'No Posts' : convert_to_vndate($lastPost);
    $legacyNewMark = corebb_index_new_mark($forum);
    $newMark = $legacyNewMark === '!' ? '!' : '';
    $topicCount = corebb_forum_topic_count($forum);
    $postCount = corebb_forum_post_count($forum);
    $secureArchive = corebb_secure_archive_board_is_effectively_locked($forum);

    $forum['forum_id'] = $forumId;
    $forum['title'] = $name;
    $forum['description'] = (string)($forum['description'] ?? '');
    $forum['board_url'] = corebb_board_url($forumId, 1, $name);
    $forum['last_post_display'] = $lastPostDisplay;
    $forum['new_mark'] = $newMark;
    $forum['has_new_posts'] = trim($newMark) !== '';
    $forum['topic_count'] = $topicCount;
    $forum['post_count'] = $postCount;
    $forum['secure_archive'] = $secureArchive;

    $forum['_lastpost_display'] = $lastPostDisplay;
    $forum['_new_mark'] = $legacyNewMark;
    $forum['_topic_count'] = $topicCount;
    $forum['_post_count'] = $postCount;
    $forum['_secure_archive'] = $secureArchive;
    return $forum;
}

/**
 * Usage: Preserve the legacy board-new marker using board read cookies.
 * Referenced by: corebb_index_prepare_forum_row().
 *
 * @param array<string, mixed> $forum Raw or prepared forum row.
 * @return string "!" for new posts or non-breaking-space HTML for no marker.
 */
function corebb_index_new_mark(array $forum): string
{
    $lastPost = (string)($forum['lastpstdate'] ?? '');
    if ($lastPost === '') {
        return '&nbsp;';
    }

    $cookieName = 'bb' . (int)($forum['id'] ?? 0);
    $boardLastPostTs = (int)($forum['lastpstdatets'] ?? 0);

    if (!isset($_COOKIE[$cookieName])) {
        return '!';
    }

    $cookieLastPost = (int)$_COOKIE[$cookieName];
    if ($boardLastPostTs > $cookieLastPost) {
        return '!';
    }

    // Preserve the old index behavior: once the board is not newer than the
    // marker cookie, clear the cookie so the next visit can re-check cleanly.
    setcookie($cookieName, '', time() - 3600);
    return '&nbsp;';
}
