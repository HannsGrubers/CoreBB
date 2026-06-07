<?php
if (!defined('COREBB_VIEW_LOADED')) {
    require_once __DIR__ . '/view.php';
}
require_once __DIR__ . '/pagination_helpers.php';
require_once __DIR__ . '/private_board_helpers.php';
require_once __DIR__ . '/board_view_model.php';

/**
 * Usage: Normalize profile-content routes to the supported content types.
 * Referenced by: content URL and model builders.
 *
 * @param string $type Requested content type.
 * @return string Either "topics" or "posts".
 */
function corebb_profile_content_type(string $type): string
{
    $type = strtolower(trim($type));
    return $type === 'posts' ? 'posts' : 'topics';
}

/**
 * Usage: Load the user whose topics or posts are being listed.
 * Referenced by: corebb_profile_content_model().
 *
 * @param int $userId Profile user id.
 * @return array<string, mixed>|null User row, or null when not found.
 */
function corebb_profile_content_user(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $row = db_one('SELECT id, username, posts FROM users WHERE id = ? LIMIT 1', [$userId]);
    return is_array($row) ? $row : null;
}

/**
 * Usage: Produce a short plain-text excerpt from a post body.
 * Referenced by: corebb_profile_posts_model().
 *
 * @param string $text Stored post body.
 * @param int $limit Maximum excerpt length.
 * @return string Plain-text excerpt, with ellipsis when trimmed.
 */
function corebb_profile_content_excerpt(string $text, int $limit = 240): string
{
    $text = trim(strip_tags($text));
    $text = preg_replace('~\[[^\]]{1,40}\]~', '', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') > $limit) {
            return mb_substr($text, 0, $limit, 'UTF-8') . '...';
        }
        return $text;
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

/**
 * Usage: Convert mixed legacy date strings into a comparable timestamp.
 * Referenced by: topic and post activity sorting callbacks.
 *
 * @param string ...$values Candidate date values, in priority order.
 * @return int Unix timestamp, or 0 when none can be parsed.
 */
function corebb_profile_content_sort_timestamp(string ...$values): int
{
    foreach ($values as $value) {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            continue;
        }

        if (preg_match('/^[0-9]{9,}$/', $value) === 1) {
            return (int)$value;
        }

        $normalized = preg_replace('/\s+/', ' ', $value) ?? $value;
        $timestamp = strtotime($normalized);
        if ($timestamp !== false && $timestamp > 0) {
            return (int)$timestamp;
        }
    }

    return 0;
}

/**
 * Usage: Format the best available activity date for profile content rows.
 * Referenced by: corebb_profile_topics_model() and corebb_profile_posts_model().
 *
 * @param string $preferred Preferred raw date value.
 * @param string $fallback Fallback display date value.
 * @return string Display-ready date, or an empty string.
 */
function corebb_profile_content_display_date(string $preferred, string $fallback = ''): string
{
    $preferred = trim($preferred);
    $fallback = trim($fallback);
    $value = $preferred !== '' ? $preferred : $fallback;

    if ($value !== '' && preg_match('/^[0-9]{9,}$/', $value) === 1) {
        $value = date('Y-n-j H:i:s', (int)$value);
    }

    return $value !== '' && function_exists('convert_to_vndate') ? convert_to_vndate($value) : $value;
}

/**
 * Usage: Build a profile content list URL for topics or posts.
 * Referenced by: corebb_profile_content_model().
 *
 * @param int $userId Profile user id.
 * @param string $type Requested content type.
 * @param int $page Page number to link to.
 * @return string Public profile-content URL.
 */
function corebb_profile_content_url(int $userId, string $type, int $page = 1): string
{
    $type = corebb_profile_content_type($type);
    $page = max(1, $page);
    return '/profile/' . max(0, $userId) . '/' . $type . '/' . ($page > 1 ? 'p' . $page . '/' : '');
}

/**
 * Usage: Build a deep link to a specific post within its topic pagination.
 * Referenced by: corebb_profile_posts_model().
 *
 * @param int $topicId Parent topic id.
 * @param int $boardId Parent board id.
 * @param int $postId Post id to anchor.
 * @param string $boardName Parent board name used for canonical URLs.
 * @return string Public post URL with a post anchor when available.
 */
function corebb_profile_content_post_url(int $topicId, int $boardId, int $postId, string $boardName = ''): string
{
    $perPage = function_exists('corebb_current_thread_posts_per_page') ? corebb_current_thread_posts_per_page() : 25;
    $position = (int)db_value('SELECT COUNT(*) FROM posts WHERE threadid = ? AND is_deleted = 0 AND id <= ?', [$topicId, $postId], 1);
    $page = max(1, (int)ceil(max(1, $position) / max(1, $perPage)));
    if (function_exists('corebb_thread_url')) {
        return corebb_thread_url($topicId, $boardId, $page, $boardName, $postId);
    }
    return '/topic/' . $topicId . '/p' . $page . '/#post' . $postId;
}

/**
 * Usage: Load and paginate public topics created by a profile user.
 * Referenced by: corebb_profile_content_model().
 *
 * @param array<string, mixed> $user Profile user row.
 * @param int $page Requested page number.
 * @param int $perPage Items per page.
 * @return array{0: array<int, array<string, mixed>>, 1: int, 2: int, 3: int} Items, total, current page, and total pages.
 */
function corebb_profile_topics_model(array $user, int $page, int $perPage): array
{
    $userId = (int)$user['id'];
    [$visibleSql, $visibleParams] = corebb_private_sql_visible_board_clause('f', 'b');

    $rows = db_all(
        'SELECT t.id, t.title, t.boardid, t.time, t.now, t.lastpost, t.posterid, t.locked, t.sticky,
                f.name AS board_name,
                COUNT(p.id) AS post_count
         FROM topics t
         LEFT JOIN forums f ON f.id = t.boardid
         LEFT JOIN boards b ON b.id = f.categoryid
         LEFT JOIN posts p ON p.threadid = t.id AND p.is_deleted = 0
         WHERE t.posterid = ? AND t.is_deleted = 0 AND ' . $visibleSql . '
         GROUP BY t.id, t.title, t.boardid, t.time, t.now, t.lastpost, t.posterid, t.locked, t.sticky, f.name
         ORDER BY t.id DESC',
        array_merge([$userId], $visibleParams)
    );

    usort($rows, static function (array $a, array $b): int {
        $aStamp = corebb_profile_content_sort_timestamp(
            (string)($a['time'] ?? ''),
            (string)($a['now'] ?? ''),
            (string)($a['lastpost'] ?? '')
        );
        $bStamp = corebb_profile_content_sort_timestamp(
            (string)($b['time'] ?? ''),
            (string)($b['now'] ?? ''),
            (string)($b['lastpost'] ?? '')
        );

        if ($aStamp !== $bStamp) {
            return $bStamp <=> $aStamp;
        }

        return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
    });

    $total = count($rows);
    $totalPages = max(1, (int)ceil(max(1, $total) / $perPage));
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $perPage;
    $rows = array_slice($rows, $offset, $perPage);

    $topicIds = array_map(static fn($row) => (int)($row['id'] ?? 0), $rows);
    $latestPosts = function_exists('corebb_board_latest_posts_by_topic') ? corebb_board_latest_posts_by_topic($topicIds) : [];
    $topicPostCounts = function_exists('corebb_board_post_counts_by_topic') ? corebb_board_post_counts_by_topic($topicIds) : [];

    $items = [];
    foreach ($rows as $row) {
        $topicId = (int)($row['id'] ?? 0);
        $boardId = (int)($row['boardid'] ?? 0);
        $boardName = (string)($row['board_name'] ?? 'Board');
        $postCount = isset($topicPostCounts[$topicId]) ? (int)$topicPostCounts[$topicId] : (int)($row['post_count'] ?? 0);
        $lastPost = $latestPosts[$topicId] ?? null;
        $lastPostRaw = $lastPost ? (string)($lastPost['posttimeraw'] ?? '') : (string)($row['lastpost'] ?? '');
        $lastPostDisplay = $lastPost ? (string)($lastPost['posttime'] ?? '') : (string)($row['lastpost'] ?? '');
        $lastPosterId = $lastPost ? (int)($lastPost['posterid'] ?? 0) : 0;
        $lastPostId = $lastPost ? (int)($lastPost['id'] ?? 0) : 0;
        $threadPerPage = function_exists('corebb_current_thread_posts_per_page') ? corebb_current_thread_posts_per_page() : 25;
        $lastPage = max(1, (int)ceil(max(1, $postCount) / max(1, $threadPerPage)));

        $items[] = [
            'title' => (string)($row['title'] ?? 'Untitled Topic'),
            'url' => function_exists('corebb_thread_url') ? corebb_thread_url($topicId, $boardId, 1, $boardName) : '/topic/' . $topicId . '/p1/',
            'topic_pages' => function_exists('corebb_board_topic_pages_model') ? corebb_board_topic_pages_model($topicId, $boardId, $postCount, $threadPerPage, $boardName) : ['visible' => false, 'items' => []],
            'board_name' => $boardName,
            'board_url' => function_exists('corebb_board_url') ? corebb_board_url($boardId, 1, $boardName) : '/board/' . $boardId . '/',
            'date' => corebb_profile_content_display_date($lastPostRaw, $lastPostDisplay),
            'last_post_url' => function_exists('corebb_thread_url') ? corebb_thread_url($topicId, $boardId, $lastPage, $boardName, $lastPostId) : '/topic/' . $topicId . '/p' . $lastPage . '/' . ($lastPostId > 0 ? '#post' . $lastPostId : ''),
            'last_poster_id' => $lastPosterId,
            'last_poster_name' => $lastPost ? (string)($lastPost['author'] ?? '') : '',
            'replies' => max(0, $postCount - 1),
            'locked' => (int)($row['locked'] ?? 0) === 1,
            'sticky' => (int)($row['sticky'] ?? 0) === 1,
        ];
    }

    return [$items, $total, $page, $totalPages];
}

/**
 * Usage: Load and paginate public posts created by a profile user.
 * Referenced by: corebb_profile_content_model().
 *
 * @param array<string, mixed> $user Profile user row.
 * @param int $page Requested page number.
 * @param int $perPage Items per page.
 * @return array{0: array<int, array<string, mixed>>, 1: int, 2: int, 3: int} Items, total, current page, and total pages.
 */
function corebb_profile_posts_model(array $user, int $page, int $perPage): array
{
    $userId = (int)$user['id'];
    [$visibleSql, $visibleParams] = corebb_private_sql_visible_board_clause('f', 'b');

    $rows = db_all(
        'SELECT p.id, p.threadid, p.title AS post_title, p.body, p.posttime, p.posttimeraw,
                t.title AS topic_title, t.boardid,
                f.name AS board_name
         FROM posts p
         INNER JOIN topics t ON t.id = p.threadid AND t.is_deleted = 0
         LEFT JOIN forums f ON f.id = t.boardid
         LEFT JOIN boards b ON b.id = f.categoryid
         WHERE p.posterid = ? AND p.is_deleted = 0 AND ' . $visibleSql . '
         ORDER BY p.id DESC',
        array_merge([$userId], $visibleParams)
    );

    usort($rows, static function (array $a, array $b): int {
        $aStamp = corebb_profile_content_sort_timestamp(
            (string)($a['posttimeraw'] ?? ''),
            (string)($a['posttime'] ?? '')
        );
        $bStamp = corebb_profile_content_sort_timestamp(
            (string)($b['posttimeraw'] ?? ''),
            (string)($b['posttime'] ?? '')
        );

        if ($aStamp !== $bStamp) {
            return $bStamp <=> $aStamp;
        }

        return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
    });

    $total = count($rows);
    $totalPages = max(1, (int)ceil(max(1, $total) / $perPage));
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $perPage;
    $rows = array_slice($rows, $offset, $perPage);

    $items = [];
    foreach ($rows as $row) {
        $postId = (int)($row['id'] ?? 0);
        $topicId = (int)($row['threadid'] ?? 0);
        $boardId = (int)($row['boardid'] ?? 0);
        $boardName = (string)($row['board_name'] ?? 'Board');
        $stamp = (string)($row['posttimeraw'] ?? '');
        $stampDisplay = (string)($row['posttime'] ?? '');
        $items[] = [
            'post_id' => $postId,
            'post_title' => (string)($row['post_title'] ?? ''),
            'topic_title' => (string)($row['topic_title'] ?? 'Untitled Topic'),
            'url' => corebb_profile_content_post_url($topicId, $boardId, $postId, $boardName),
            'board_name' => $boardName,
            'board_url' => function_exists('corebb_board_url') ? corebb_board_url($boardId, 1, $boardName) : '/board/' . $boardId . '/',
            'date' => corebb_profile_content_display_date($stamp, $stampDisplay),
            'excerpt' => corebb_profile_content_excerpt((string)($row['body'] ?? '')),
        ];
    }

    return [$items, $total, $page, $totalPages];
}

/**
 * Usage: Build the profile topics/posts page model, including login and pagination state.
 * Referenced by: controllers/content.php?action=profile_content.
 *
 * @param int $userId Profile user id.
 * @param string $type Requested content type.
 * @param int $page Requested page number.
 * @return array<string, mixed> Profile content display state for Twig.
 */
function corebb_profile_content_model(int $userId, string $type, int $page): array
{
    if (!function_exists('loggedin') || !loggedin()) {
        return [
            'found' => false,
            'login_required' => true,
            'message' => 'Please log in to view a member\'s topics and posts.',
        ];
    }

    $user = corebb_profile_content_user($userId);
    if (!$user) {
        return [
            'found' => false,
            'login_required' => false,
            'message' => 'Unknown User.',
        ];
    }

    $type = corebb_profile_content_type($type);
    $perPage = 25;
    if ($type === 'posts') {
        [$items, $total, $page, $totalPages] = corebb_profile_posts_model($user, $page, $perPage);
    } else {
        [$items, $total, $page, $totalPages] = corebb_profile_topics_model($user, $page, $perPage);
    }

    $urlPattern = corebb_profile_content_url((int)$user['id'], $type, 999999);
    $urlPattern = str_replace('/p999999/', '/p{page}/', $urlPattern);
    $pagination = corebb_pagination_model($totalPages > 1 ? $urlPattern : '', $page, $totalPages, 'MainMenuFont');

    return [
        'found' => true,
        'login_required' => false,
        'type' => $type,
        'user' => $user,
        'username_plain' => (string)($user['username'] ?? 'Unknown'),
        'profile_url' => '/profile/' . (int)$user['id'] . '/',
        'topics_url' => corebb_profile_content_url((int)$user['id'], 'topics'),
        'posts_url' => corebb_profile_content_url((int)$user['id'], 'posts'),
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
        'pagination' => $pagination,
    ];
}
