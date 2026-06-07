<?php
if (!defined('COREBB_VIEW_LOADED')) {
    require_once __DIR__ . '/view.php';
}
require_once __DIR__ . '/pagination_helpers.php';
require_once __DIR__ . '/private_board_helpers.php';
require_once __DIR__ . '/performance_helpers.php';

/**
 * Usage: Normalize a user-entered search field to one line and cap its length.
 * Referenced by: corebb_search_input_model().
 *
 * @param string $value Raw query or author field.
 * @param int $limit Maximum character length to keep.
 * @return string Cleaned search field.
 */
function corebb_search_clean_query(string $value, int $limit = 80): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit, 'UTF-8');
    }
    return substr($value, 0, $limit);
}

/**
 * Usage: Normalize the selected search tab/type.
 * Referenced by: corebb_search_input_model().
 *
 * @param string $value Submitted search type.
 * @return string One of "posts", "topics", or "users".
 */
function corebb_search_type(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['posts', 'topics', 'users'], true) ? $value : 'posts';
}

/**
 * Usage: Escape user text for SQL LIKE matching.
 * Referenced by: search author, post, topic, and user query builders.
 *
 * @param string $value Literal value to search for.
 * @return string LIKE pattern with escaped wildcard characters.
 */
function corebb_search_like(string $value): string
{
    return '%' . addcslashes($value, "\\%_") . '%';
}

/**
 * Usage: Convert a plain query into a MySQL boolean full-text query.
 * Referenced by: corebb_search_fulltext_query().
 *
 * @param string $value Search query text.
 * @return string Boolean full-text query, or an empty string when no usable tokens exist.
 */
function corebb_search_boolean_query(string $value): string
{
    $tokens = [];
    if (preg_match_all('/[\pL\pN_]{3,}/u', $value, $matches)) {
        foreach ($matches[0] as $token) {
            $token = trim((string)$token);
            if ($token === '') {
                continue;
            }
            $tokens[strtolower($token)] = $token;
            if (count($tokens) >= 8) {
                break;
            }
        }
    }

    if (!$tokens) {
        return '';
    }

    $parts = [];
    foreach ($tokens as $token) {
        $parts[] = '+' . $token . '*';
    }
    return implode(' ', $parts);
}

/**
 * Usage: Check whether the optional full-text search indexes are ready.
 * Referenced by: corebb_search_fulltext_query().
 *
 * @return bool True when full-text search can be used.
 */
function corebb_search_fulltext_enabled(): bool
{
    return function_exists('corebb_perf_search_fulltext_ready') && corebb_perf_search_fulltext_ready();
}

/**
 * Usage: Build a full-text query only when the search indexes are available.
 * Referenced by: post and topic search loaders.
 *
 * @param string $q User keyword query.
 * @return string Full-text query string, or an empty string to fall back to LIKE.
 */
function corebb_search_fulltext_query(string $q): string
{
    if (!corebb_search_fulltext_enabled()) {
        return '';
    }
    return corebb_search_boolean_query($q);
}

/**
 * Usage: Load visible boards for the search board filter.
 * Referenced by: corebb_search_model().
 *
 * @return array<int, array<string, mixed>> Visible board rows.
 */
function corebb_search_boards(): array
{
    [$visibleSql, $visibleParams] = corebb_private_sql_visible_board_clause('f', 'b');
    return db_all('SELECT f.id, f.name FROM forums f LEFT JOIN boards b ON b.id = f.categoryid WHERE ' . $visibleSql . ' ORDER BY f.name ASC', $visibleParams);
}

/**
 * Usage: Build a clean search URL from current filters and page number.
 * Referenced by: corebb_search_model().
 *
 * @param array<string, mixed> $params Search filters.
 * @param int $page Target page number.
 * @return string Public search URL.
 */
function corebb_search_url(array $params, int $page = 1): string
{
    $params['p'] = max(1, $page);
    if ((int)$params['p'] <= 1) {
        unset($params['p']);
    }
    $clean = [];
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        if ($key === 'boardid' && (int)$value <= 0) {
            continue;
        }
        if ($key === 'type' && $value === 'posts') {
            // Keep the default URL short.
            continue;
        }
        $clean[$key] = $value;
    }
    $query = http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    return '/search/' . ($query !== '' ? '?' . $query : '');
}

/**
 * Usage: Build a plain-text result excerpt around the keyword when possible.
 * Referenced by: corebb_search_posts().
 *
 * @param string $text Source post body.
 * @param string $needle Keyword to center the excerpt around.
 * @param int $limit Maximum excerpt length.
 * @return string Plain-text excerpt.
 */
function corebb_search_excerpt(string $text, string $needle = '', int $limit = 260): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('~\[[^\]]{1,40}\]~', '', $text) ?? $text;
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($text === '') {
        return '';
    }

    $start = 0;
    if ($needle !== '') {
        $pos = function_exists('mb_stripos') ? mb_stripos($text, $needle, 0, 'UTF-8') : stripos($text, $needle);
        if ($pos !== false) {
            $start = max(0, (int)$pos - 80);
            // Back up to a nearby word boundary so snippets do not start in the
            // middle of useful context unless the hit is very early in the text.
            if ($start > 0) {
                $prefixChunk = function_exists('mb_substr') ? mb_substr($text, 0, $start, 'UTF-8') : substr($text, 0, $start);
                $spacePos = strrpos($prefixChunk, ' ');
                if ($spacePos !== false && $spacePos > max(0, $start - 35)) {
                    $start = $spacePos + 1;
                }
            }
        }
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        $prefix = $start > 0 ? '...' : '';
        $slice = mb_substr($text, $start, $limit, 'UTF-8');
        $suffix = (mb_strlen($text, 'UTF-8') > $start + $limit) ? '...' : '';
        return $prefix . $slice . $suffix;
    }

    $prefix = $start > 0 ? '...' : '';
    $slice = substr($text, $start, $limit);
    $suffix = strlen($text) > $start + $limit ? '...' : '';
    return $prefix . $slice . $suffix;
}


/**
 * Usage: Highlight the search term inside already-selected result text.
 * Referenced by: formatted_content_html() through search_highlight_model().
 *
 * @param string $text Result text to display.
 * @param string $needle Search term to highlight.
 * @return string Escaped HTML with highlight spans around matches.
 */
function corebb_search_highlight_html(string $text, string $needle): string
{
    if ($text === '') {
        return '';
    }
    $needle = trim($needle);
    if ($needle === '') {
        return corebb_h($text);
    }

    // Capture the matching term so preg_split returns it as its own part.
    // PREG_SPLIT_DELIM_CAPTURE only works when the regex has a capturing group.
    $pattern = '/(' . preg_quote($needle, '/') . ')/iu';
    $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts) || count($parts) <= 1) {
        return corebb_h($text);
    }

    $html = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        if (preg_match($pattern, $part) === 1) {
            $html .= '<span style="background:#ffff66; color:#000000; font-weight:bold;">' . corebb_h($part) . '</span>';
        } else {
            $html .= corebb_h($part);
        }
    }
    return $html;
}

/**
 * Usage: Build a deep link to a matching post within thread pagination.
 * Referenced by: corebb_search_posts().
 *
 * @param int $topicId Parent topic id.
 * @param int $boardId Parent board id.
 * @param int $postId Matching post id.
 * @param string $boardName Parent board name used for canonical URLs.
 * @return string Public post URL with an anchor when available.
 */
function corebb_search_post_url(int $topicId, int $boardId, int $postId, string $boardName = ''): string
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
 * Usage: Normalize raw search request input into a typed model.
 * Referenced by: corebb_search_model().
 *
 * @param array<string, mixed> $source Query parameters from the search route.
 * @return array{q: string, author: string, type: string, boardid: int, page: int} Clean search input.
 */
function corebb_search_input_model(array $source): array
{
    $query = corebb_search_clean_query((string)($source['q'] ?? ''));
    $author = corebb_search_clean_query((string)($source['author'] ?? ''), 50);
    $type = corebb_search_type((string)($source['type'] ?? 'posts'));
    $boardId = max(0, (int)($source['boardid'] ?? 0));
    $page = max(1, (int)($source['p'] ?? 1));

    return [
        'q' => $query,
        'author' => $author,
        'type' => $type,
        'boardid' => $boardId,
        'page' => $page,
    ];
}

/**
 * Usage: Build the public search page model and dispatch to the selected result loader.
 * Referenced by: controllers/content.php?action=search.
 *
 * @param array<string, mixed> $source Query parameters from the search route.
 * @return array<string, mixed> Search form state, result rows, pagination, and message text.
 */
function corebb_search_model(array $source): array
{
    $input = corebb_search_input_model($source);
    $boards = corebb_search_boards();
    $perPage = 25;
    $message = '';
    $items = [];
    $total = 0;
    $searched = false;

    $q = $input['q'];
    $author = $input['author'];
    $type = $input['type'];
    $boardId = (int)$input['boardid'];
    $page = (int)$input['page'];

    if ($q !== '' || $author !== '') {
        $searched = true;
    }

    if ($searched) {
        if ($q !== '' && (function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q)) < 3) {
            $message = 'Please enter at least 3 characters for keyword searches.';
        } elseif ($q === '' && $author === '') {
            $message = 'Please enter a keyword or username.';
        } elseif ($type === 'users' && $q === '') {
            $message = 'Please enter a username to search for.';
        } else {
            if ($type === 'users') {
                [$items, $total, $page] = corebb_search_users($q, $page, $perPage);
            } elseif ($type === 'topics') {
                [$items, $total, $page] = corebb_search_topics($q, $author, $boardId, $page, $perPage);
            } else {
                [$items, $total, $page] = corebb_search_posts($q, $author, $boardId, $page, $perPage);
            }
            if ($message === '' && $searched && $total === 0) {
                $message = 'No matching results were found.';
            }
        }
    }

    $totalPages = max(1, (int)ceil(max(1, $total) / $perPage));
    $urlParams = [
        'q' => $q,
        'type' => $type,
        'boardid' => $boardId,
        'author' => $author,
    ];
    $urlPattern = str_replace('p=999999', 'p={page}', corebb_search_url($urlParams, 999999));
    if (!str_contains($urlPattern, '{page}')) {
        $urlPattern .= (str_contains($urlPattern, '?') ? '&' : '?') . 'p={page}';
    }
    $pagination = corebb_pagination_model($totalPages > 1 ? $urlPattern : '', $page, $totalPages, 'MainMenuFont');

    return [
        'input' => array_merge($input, ['page' => $page]),
        'boards' => $boards,
        'searched' => $searched,
        'message' => $message,
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'pagination' => $pagination,
    ];
}

/**
 * Usage: Add an optional username LIKE clause to a search query.
 * Referenced by: post and topic search loaders.
 *
 * @param string $author Cleaned author search text.
 * @param array<int, mixed> $params Query parameters passed by reference.
 * @param string $alias SQL alias for the users table.
 * @return string SQL fragment for the author filter.
 */
function corebb_search_author_clause(string $author, array &$params, string $alias = 'u'): string
{
    if ($author === '') {
        return '';
    }
    $params[] = corebb_search_like($author);
    return ' AND ' . $alias . '.username LIKE ?';
}

/**
 * Usage: Add an optional board id clause to a search query.
 * Referenced by: post and topic search loaders.
 *
 * @param int $boardId Board filter id.
 * @param array<int, mixed> $params Query parameters passed by reference.
 * @param string $topicAlias SQL alias for the topics table.
 * @return string SQL fragment for the board filter.
 */
function corebb_search_board_clause(int $boardId, array &$params, string $topicAlias = 't'): string
{
    if ($boardId <= 0) {
        return '';
    }
    $params[] = $boardId;
    return ' AND ' . $topicAlias . '.boardid = ?';
}

/**
 * Usage: Search visible posts by keyword, author, and board filter.
 * Referenced by: corebb_search_model().
 *
 * @param string $q Cleaned keyword query.
 * @param string $author Cleaned author filter.
 * @param int $boardId Board filter id.
 * @param int $page Requested page number.
 * @param int $perPage Results per page.
 * @return array{0: array<int, array<string, mixed>>, 1: int, 2: int} Items, total count, and resolved page.
 */
function corebb_search_posts(string $q, string $author, int $boardId, int $page, int $perPage): array
{
    $params = [];
    $where = ' WHERE p.is_deleted = 0 AND t.is_deleted = 0';
    $fulltextQuery = '';

    if ($q !== '') {
        $fulltextQuery = corebb_search_fulltext_query($q);
        if ($fulltextQuery !== '') {
            $where .= ' AND (MATCH(p.title, p.body) AGAINST (? IN BOOLEAN MODE) OR MATCH(t.title) AGAINST (? IN BOOLEAN MODE))';
            array_push($params, $fulltextQuery, $fulltextQuery);
        } else {
            $like = corebb_search_like($q);
            $where .= ' AND (p.title LIKE ? OR p.body LIKE ? OR t.title LIKE ?)';
            array_push($params, $like, $like, $like);
        }
    }
    $where .= corebb_search_author_clause($author, $params, 'u');
    $where .= corebb_search_board_clause($boardId, $params, 't');
    [$visibleSql, $visibleParams] = corebb_private_sql_visible_board_clause('f', 'b');
    $where .= ' AND ' . $visibleSql;
    $params = array_merge($params, $visibleParams);

    $countSql = 'SELECT COUNT(*) FROM posts p INNER JOIN topics t ON t.id = p.threadid LEFT JOIN forums f ON f.id = t.boardid LEFT JOIN boards b ON b.id = f.categoryid LEFT JOIN users u ON u.id = p.posterid ' . $where;
    $total = (int)db_value($countSql, $params, 0);
    $totalPages = max(1, (int)ceil(max(1, $total) / $perPage));
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $perPage;

    $orderSql = 'ORDER BY p.id DESC';
    $selectParams = $params;
    if ($fulltextQuery !== '') {
        $orderSql = 'ORDER BY (MATCH(p.title, p.body) AGAINST (? IN BOOLEAN MODE) + MATCH(t.title) AGAINST (? IN BOOLEAN MODE)) DESC, p.id DESC';
        $selectParams[] = $fulltextQuery;
        $selectParams[] = $fulltextQuery;
    }

    $sql = 'SELECT p.id, p.threadid, p.title AS post_title, p.body, p.posttime, p.posttimeraw, p.posterid,
                   t.title AS topic_title, t.boardid,
                   f.name AS board_name,
                   u.username
            FROM posts p
            INNER JOIN topics t ON t.id = p.threadid
            LEFT JOIN forums f ON f.id = t.boardid
            LEFT JOIN boards b ON b.id = f.categoryid
            LEFT JOIN users u ON u.id = p.posterid
            ' . $where . '
            ' . $orderSql . '
            LIMIT ' . (int)$offset . ', ' . (int)$perPage;
    $rows = db_all($sql, $selectParams);

    $items = [];
    foreach ($rows as $row) {
        $postId = (int)($row['id'] ?? 0);
        $topicId = (int)($row['threadid'] ?? 0);
        $boardId = (int)($row['boardid'] ?? 0);
        $boardName = (string)($row['board_name'] ?? 'Board');
        $stamp = (string)($row['posttimeraw'] ?? ($row['posttime'] ?? ''));
        $items[] = [
            'kind' => 'post',
            'title' => (string)($row['post_title'] ?: ($row['topic_title'] ?? 'Untitled Topic')),
            'topic_title' => (string)($row['topic_title'] ?? 'Untitled Topic'),
            'url' => corebb_search_post_url($topicId, $boardId, $postId, $boardName),
            'board_name' => $boardName,
            'board_url' => function_exists('corebb_board_url') ? corebb_board_url($boardId, 1, $boardName) : '/board/' . $boardId . '/',
            'author' => (string)($row['username'] ?? 'Unknown'),
            'author_url' => '/profile/' . (int)($row['posterid'] ?? 0) . '/',
            'date' => $stamp !== '' && function_exists('convert_to_vndate') ? convert_to_vndate($stamp) : $stamp,
            'excerpt' => corebb_search_excerpt((string)($row['body'] ?? ''), $q),
        ];
    }

    return [$items, $total, $page];
}

/**
 * Usage: Search visible topics by keyword, author, and board filter.
 * Referenced by: corebb_search_model().
 *
 * @param string $q Cleaned keyword query.
 * @param string $author Cleaned author filter.
 * @param int $boardId Board filter id.
 * @param int $page Requested page number.
 * @param int $perPage Results per page.
 * @return array{0: array<int, array<string, mixed>>, 1: int, 2: int} Items, total count, and resolved page.
 */
function corebb_search_topics(string $q, string $author, int $boardId, int $page, int $perPage): array
{
    $params = [];
    $where = ' WHERE t.is_deleted = 0 AND EXISTS (SELECT 1 FROM posts pvis WHERE pvis.threadid = t.id AND pvis.is_deleted = 0)';
    $fulltextQuery = '';

    if ($q !== '') {
        $fulltextQuery = corebb_search_fulltext_query($q);
        if ($fulltextQuery !== '') {
            $where .= ' AND MATCH(t.title) AGAINST (? IN BOOLEAN MODE)';
            $params[] = $fulltextQuery;
        } else {
            $where .= ' AND t.title LIKE ?';
            $params[] = corebb_search_like($q);
        }
    }
    $where .= corebb_search_author_clause($author, $params, 'u');
    $where .= corebb_search_board_clause($boardId, $params, 't');
    [$visibleSql, $visibleParams] = corebb_private_sql_visible_board_clause('f', 'b');
    $where .= ' AND ' . $visibleSql;
    $params = array_merge($params, $visibleParams);

    $countSql = 'SELECT COUNT(*) FROM topics t LEFT JOIN forums f ON f.id = t.boardid LEFT JOIN boards b ON b.id = f.categoryid LEFT JOIN users u ON u.id = t.posterid ' . $where;
    $total = (int)db_value($countSql, $params, 0);
    $totalPages = max(1, (int)ceil(max(1, $total) / $perPage));
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $perPage;

    $orderSql = "ORDER BY CAST(COALESCE(NULLIF(t.lastpost, ''), NULLIF(t.time, ''), '0') AS UNSIGNED) DESC, t.id DESC";
    $selectParams = $params;
    if ($fulltextQuery !== '') {
        $orderSql = "ORDER BY MATCH(t.title) AGAINST (? IN BOOLEAN MODE) DESC, CAST(COALESCE(NULLIF(t.lastpost, ''), NULLIF(t.time, ''), '0') AS UNSIGNED) DESC, t.id DESC";
        $selectParams[] = $fulltextQuery;
    }

    $sql = 'SELECT t.id, t.title, t.boardid, t.posterid, t.time, t.lastpost, t.locked, t.sticky,
                   f.name AS board_name,
                   u.username,
                   COUNT(p.id) AS post_count
            FROM topics t
            LEFT JOIN forums f ON f.id = t.boardid
            LEFT JOIN boards b ON b.id = f.categoryid
            LEFT JOIN users u ON u.id = t.posterid
            LEFT JOIN posts p ON p.threadid = t.id AND p.is_deleted = 0
            ' . $where . '
            GROUP BY t.id, t.title, t.boardid, t.posterid, t.time, t.lastpost, t.locked, t.sticky, f.name, u.username
            ' . $orderSql . '
            LIMIT ' . (int)$offset . ', ' . (int)$perPage;
    $rows = db_all($sql, $selectParams);

    $items = [];
    foreach ($rows as $row) {
        $topicId = (int)($row['id'] ?? 0);
        $boardId = (int)($row['boardid'] ?? 0);
        $boardName = (string)($row['board_name'] ?? 'Board');
        $stamp = (string)($row['lastpost'] ?? ($row['time'] ?? ''));
        $items[] = [
            'kind' => 'topic',
            'title' => (string)($row['title'] ?? 'Untitled Topic'),
            'url' => function_exists('corebb_thread_url') ? corebb_thread_url($topicId, $boardId, 1, $boardName) : '/topic/' . $topicId . '/p1/',
            'board_name' => $boardName,
            'board_url' => function_exists('corebb_board_url') ? corebb_board_url($boardId, 1, $boardName) : '/board/' . $boardId . '/',
            'author' => (string)($row['username'] ?? 'Unknown'),
            'author_url' => '/profile/' . (int)($row['posterid'] ?? 0) . '/',
            'date' => $stamp !== '' && function_exists('convert_to_vndate') ? convert_to_vndate($stamp) : $stamp,
            'replies' => max(0, (int)($row['post_count'] ?? 0) - 1),
            'locked' => (int)($row['locked'] ?? 0) === 1,
            'sticky' => (int)($row['sticky'] ?? 0) === 1,
            'excerpt' => '',
        ];
    }

    return [$items, $total, $page];
}

/**
 * Usage: Search users by username.
 * Referenced by: corebb_search_model().
 *
 * @param string $q Cleaned username query.
 * @param int $page Requested page number.
 * @param int $perPage Results per page.
 * @return array{0: array<int, array<string, mixed>>, 1: int, 2: int} Items, total count, and resolved page.
 */
function corebb_search_users(string $q, int $page, int $perPage): array
{
    $params = [corebb_search_like($q)];
    $where = ' WHERE username LIKE ?';
    $total = (int)db_value('SELECT COUNT(*) FROM users ' . $where, $params, 0);
    $totalPages = max(1, (int)ceil(max(1, $total) / $perPage));
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $perPage;

    $rows = db_all(
        'SELECT id, username, posts, regdate FROM users ' . $where . ' ORDER BY username ASC LIMIT ' . (int)$offset . ', ' . (int)$perPage,
        $params
    );

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'kind' => 'user',
            'title' => (string)($row['username'] ?? 'Unknown'),
            'url' => '/profile/' . (int)($row['id'] ?? 0) . '/',
            'posts' => (int)($row['posts'] ?? 0),
            'date' => (string)($row['regdate'] ?? ''),
        ];
    }

    return [$items, $total, $page];
}
