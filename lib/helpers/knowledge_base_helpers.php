<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Apply topic fields first so a board can only be enabled once its article fields exist.
function corebb_kb_prepare_schema(): bool
{
    $tables = [
        'topics' => [
            'kb_summary' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'kb_category' => "VARCHAR(80) NOT NULL DEFAULT ''",
            'kb_versions' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'kb_verified' => 'DATE DEFAULT NULL',
        ],
        'forums' => ['board_type' => "VARCHAR(20) NOT NULL DEFAULT 'discussion'"],
    ];
    foreach ($tables as $table => $definitions) {
        $columns = array_column(db_all("SHOW COLUMNS FROM `$table`"), 'Field');
        foreach ($definitions as $name => $definition) {
            if (!in_array($name, $columns, true)
                && !db_run("ALTER TABLE `$table` ADD COLUMN `$name` $definition")
                && !db_one("SHOW COLUMNS FROM `$table` WHERE Field = ?", [$name])) {
                return false;
            }
        }
    }
    return true;
}

function corebb_kb_board(array $board): bool
{
    return ($board['board_type'] ?? '') === 'knowledge_base';
}

function corebb_kb_opener(int $topicId): int
{
    return (int)db_value('SELECT MIN(id) FROM posts WHERE threadid = ?', [$topicId], 0);
}

// Validate optional editor fields before starting a topic or edit transaction.
function corebb_kb_metadata(array $post): array
{
    $fields = [];
    foreach (['kb_summary' => 255, 'kb_category' => 80, 'kb_versions' => 100] as $name => $limit) {
        $value = $post[$name] ?? '';
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > $limit) {
            throw new InvalidArgumentException('Article details exceed the field length.');
        }
        $fields[] = trim($value);
    }
    $date = $post['kb_verified'] ?? '';
    if (!is_string($date) || ($date !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m)
        || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 1000))) {
        throw new InvalidArgumentException('Enter a valid verification date.');
    }
    $fields[] = $date === '' ? null : $date;
    return $fields;
}

function corebb_kb_save_metadata(int $topicId, array $values): bool
{
    return db_run('UPDATE topics SET kb_summary = ?, kb_category = ?, kb_versions = ?, kb_verified = ? WHERE id = ?',
        [...$values, $topicId]);
}

// Called after the board's normal visibility check. Only the original post can supply article content.
function corebb_kb_index(array $board, int $page, string $query, string $category): array
{
    $boardId = (int)$board['id'];
    $query = mb_substr(trim($query), 0, 160, 'UTF-8');
    $category = mb_substr(trim($category), 0, 80, 'UTF-8');
    $from = ' FROM topics t JOIN posts p ON p.id = (SELECT MIN(o.id) FROM posts o WHERE o.threadid = t.id)
        WHERE t.boardid = ? AND t.is_deleted = 0 AND p.boardid = t.boardid AND p.is_deleted = 0';
    $categories = array_column(db_all("SELECT DISTINCT t.kb_category $from AND t.kb_category <> '' ORDER BY t.kb_category", [$boardId]), 'kb_category');
    $params = [$boardId];
    if ($category !== '') {
        $from .= ' AND t.kb_category = ?';
        $params[] = $category;
    }
    $order = 't.sticky DESC, p.title ASC, t.id ASC';
    $orderParams = [];
    if ($query !== '') {
        $pattern = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $from .= " AND (p.title LIKE ? ESCAPE '!' OR t.kb_summary LIKE ? ESCAPE '!' OR p.body LIKE ? ESCAPE '!')";
        array_push($params, $pattern, $pattern, $pattern);
        $order = "CASE WHEN p.title LIKE ? ESCAPE '!' THEN 0 WHEN t.kb_summary LIKE ? ESCAPE '!' THEN 1 ELSE 2 END, " . $order;
        $orderParams = [$pattern, $pattern];
    }
    $count = (int)db_value("SELECT COUNT(*) $from", $params, 0);
    $limit = max(1, corebb_current_board_topics_per_page());
    $pages = max(1, (int)ceil($count / $limit));
    $page = max(1, min($pages, $page));
    $offset = ($page - 1) * $limit;
    $articles = db_all("SELECT t.id, p.title, p.author, t.kb_summary, t.kb_category, t.kb_versions, t.kb_verified
        $from ORDER BY $order LIMIT $limit OFFSET $offset", [...$params, ...$orderParams]);
    foreach ($articles as &$article) {
        $article['url'] = corebb_thread_url((int)$article['id'], $boardId, 1, $board['name']);
    }
    unset($article);
    $url = corebb_board_url($boardId, 1, $board['name']);
    $pattern = str_replace('/p999999/', '/p{page}/', corebb_board_url($boardId, 999999, $board['name']));
    $filters = http_build_query(array_filter(['q' => $query, 'category' => $category], static fn($v) => $v !== ''));
    return [
        'boardName' => $board['name'], 'boardDescription' => $board['description'], 'boardUrl' => $url,
        'articles' => $articles, 'categories' => $categories, 'query' => $query, 'category' => $category,
        'count' => $count, 'pagination' => corebb_pagination_model($pattern . ($filters === '' ? '' : '?' . $filters), $page, $pages),
        'newTopicUrl' => corebb_new_topic_url($boardId),
        'canPublish' => corebb_mod_can_moderate() && corebb_secure_archive_user_can_write_board_row($board, (int)($GLOBALS['userlogindata_a']['accesslevel'] ?? 0)),
    ];
}
