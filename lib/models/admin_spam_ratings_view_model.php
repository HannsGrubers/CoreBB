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
 |  admin_spam_ratings_view_model.php  - Moderator      |
 |  spam-ratings BBCode generator.                      |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_user_tools_view_model.php';

/**
 * Usage: Normalize input before it is displayed or saved.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param mixed $value Raw value to normalize.
 * @param int $default Fallback value.
 * @param int $min Minimum value.
 * @param int $max Maximum value.
 * @return int Numeric result for the caller.
 */
function corebb_spam_ratings_limit_int($value, int $default, int $min, int $max): int
{
    $value = filter_var($value, FILTER_VALIDATE_INT);
    if ($value === false) {
        return $default;
    }
    return max($min, min($max, (int)$value));
}

/**
 * Usage: Fetch boards available to the spam-ratings report.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_spam_ratings_boards(): array
{
    return db_all(
        'SELECT f.id, f.name, b.name AS category_name
           FROM forums f
           LEFT JOIN boards b ON b.id = f.categoryid
          ORDER BY b.position ASC, f.position ASC, f.name ASC'
    );
}

/**
 * Usage: Return the post timestamp expression used by spam ratings.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_spam_ratings_post_time_expr(): string
{
    return "(CASE WHEN p.posttimeraw REGEXP '^[0-9]+$' THEN CAST(p.posttimeraw AS UNSIGNED) ELSE UNIX_TIMESTAMP(p.posttime) END)";
}

/**
 * Usage: Return the topic timestamp expression used by spam ratings.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_spam_ratings_topic_time_expr(): string
{
    return "(CASE WHEN t.`time` REGEXP '^[0-9]+$' THEN CAST(t.`time` AS UNSIGNED) ELSE UNIX_TIMESTAMP(t.lastpost) END)";
}

/**
 * Usage: Build the SQL fragment for an admin query.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $table Database table name.
 * @param string $alias SQL table alias.
 * @return string Normalized or display-ready string.
 */
function corebb_spam_ratings_visible_clause(string $table, string $alias): string
{
    $columns = corebb_admin_table_columns($table);
    if (!isset($columns['is_deleted'])) {
        return '1 = 1';
    }
    return '`' . str_replace('`', '``', $alias) . '`.`is_deleted` = 0';
}

/**
 * Usage: Estimate account age in days for spam scoring.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $user User row being displayed or edited.
 * @return int Numeric result for the caller.
 */
function corebb_spam_ratings_parse_user_age_days(array $user): int
{
    $created = trim((string)($user['regdate'] ?? ''));
    $ts = $created !== '' ? strtotime($created) : false;
    if ($ts === false && preg_match('/^[A-Za-z]{3}\s+\d{2}$/', $created)) {
        $ts = strtotime('01 ' . $created);
    }
    if ($ts === false || $ts <= 0) {
        return 1;
    }
    return max(1, (int)floor((time() - $ts) / 86400));
}

/**
 * Usage: Normalize input before it is displayed or saved.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $text Raw text.
 * @return string Normalized or display-ready string.
 */
function corebb_spam_ratings_clean_text(string $text): string
{
    $text = preg_replace('~\[(?:/?)[^\]]+\]~', '', $text) ?? $text;
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('~\s+~', ' ', $text) ?? $text);
}

/**
 * Usage: Prepare post text for safe spam-rating snippets.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $text Raw text.
 * @return string Normalized or display-ready string.
 */
function corebb_spam_ratings_safe_bbcode_text(string $text): string
{
    return str_replace(["\r", "\n", '[', ']'], ['', ' ', '(', ')'], trim($text));
}

/**
 * Usage: Render a username sample with optional style highlighting.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $user User row being displayed or edited.
 * @param string $overrideHl Optional highlight color override.
 * @return string Normalized or display-ready string.
 */
function corebb_spam_ratings_username_bbcode(array $user, string $overrideHl = ''): string
{
    $name = corebb_spam_ratings_safe_bbcode_text((string)($user['username'] ?? 'Unknown'));
    if ($name === '') {
        $name = 'Unknown';
    }
    if ($overrideHl !== '') {
        return '[HL=' . $overrideHl . '][B]' . $name . '[/B][/HL]';
    }

    $bg = trim((string)($user['vip_bg_color'] ?? ''));
    $fg = trim((string)($user['vip_text_color'] ?? ''));
    $italic = (int)($user['vip_italic'] ?? 0) === 1;

    $name = '[B]' . $name . '[/B]';
    if ($italic) {
        $name = '[I]' . $name . '[/I]';
    }
    $name = '[color=' . ($fg !== '' ? $fg : 'blue') . ']' . $name . '[/color]';
    if ($bg !== '') {
        $name = '[HL=' . $bg . ']' . $name . '[/HL]';
    }
    return $name;
}

/**
 * Usage: Sort statistics rows by one metric descending.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $rows Weighted row list.
 * @param string $key Request or filter key.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_spam_ratings_sort_desc(array $rows, string $key): array
{
    usort($rows, static function (array $a, array $b) use ($key): int {
        return ((float)($b[$key] ?? 0) <=> (float)($a[$key] ?? 0))
            ?: strcasecmp((string)($a['username'] ?? ''), (string)($b['username'] ?? ''));
    });
    return $rows;
}

/**
 * Usage: Pick the top user row for one spam-rating metric.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $stats Calculated statistics rows.
 * @param string $key Request or filter key.
 * @return ?array Data row when found, otherwise null.
 */
function corebb_spam_ratings_top_user(array $stats, string $key): ?array
{
    $rows = array_values(array_filter($stats, static fn(array $row): bool => (float)($row[$key] ?? 0) > 0));
    if (!$rows) {
        return null;
    }
    $rows = corebb_spam_ratings_sort_desc($rows, $key);
    return $rows[0] ?? null;
}

/**
 * Usage: Build rank lookups for spam-rating metrics.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $stats Calculated statistics rows.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_spam_ratings_rank_map(array $stats): array
{
    $rows = corebb_spam_ratings_sort_desc(array_values($stats), 'period_posts');
    $rank = [];
    $pos = 1;
    foreach ($rows as $row) {
        $rank[(int)($row['id'] ?? 0)] = $pos++;
    }
    return $rank;
}

/**
 * Usage: Choose a poll-bar color for spam-ratings output.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $index Zero-based index.
 * @return string Normalized or display-ready string.
 */
function corebb_spam_ratings_poll_color(int $index): string
{
    $colors = [
        '#0000CC',
        '#FF0000',
        '#009900',
        '#9900CC',
        '#FF9900',
        '#0099CC',
        '#777777',
        '#333399',
        '#CC3399',
        '#336600',
    ];
    return $colors[$index % count($colors)];
}

/**
 * Usage: Render a compact percentage bar for spam-ratings output.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $votes Vote count.
 * @param int $totalVotes Total vote count.
 * @param string $color Display color.
 * @param int $width Bar width in pixels.
 * @return string Normalized or display-ready string.
 */
function corebb_spam_ratings_bar(int $votes, int $totalVotes, string $color, int $width = 36): string
{
    $totalVotes = max(1, $totalVotes);
    $filled = $votes > 0 ? max(1, (int)round(($votes / $totalVotes) * $width)) : 0;
    $filled = min($width, $filled);
    $empty = max(0, $width - $filled);
    $filledBar = $filled > 0 ? '[HL=' . $color . '][color=' . $color . ']' . str_repeat('M', $filled) . '[/color][/HL]' : '';
    $emptyBar = $empty > 0 ? '[HL=#999999][color=#999999]' . str_repeat('M', $empty) . '[/color][/HL]' : '';
    return $filledBar . $emptyBar;
}

/**
 * Usage: Build the optional SELECT fragment for user style columns.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $alias SQL table alias.
 * @return string Normalized or display-ready string.
 */
function corebb_spam_ratings_user_style_select(string $alias = 'u'): string
{
    $columns = corebb_admin_table_columns('users');
    $parts = [];
    foreach (['vip_bg_color', 'vip_text_color', 'vip_bold', 'vip_italic'] as $column) {
        $parts[] = isset($columns[$column])
            ? ('`' . $alias . '`.`' . $column . '` AS `' . $column . '`')
            : (($column === 'vip_bold' || $column === 'vip_italic' ? '0' : "''") . ' AS `' . $column . '`');
    }
    return implode(', ', $parts);
}

/**
 * Usage: Fetch the row used by this admin action.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $boardId Board id.
 * @param int $startUnix Start timestamp.
 * @param int $endUnix End timestamp.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_spam_ratings_fetch_posts(int $boardId, int $startUnix, int $endUnix): array
{
    $timeExpr = corebb_spam_ratings_post_time_expr();
    $userCols = corebb_admin_user_select_list('u');
    $userStyleCols = corebb_spam_ratings_user_style_select('u');
    $postColumns = corebb_admin_table_columns('posts');
    $editSelect = [];
    foreach (['wasedited', 'editedby', 'editdate', 'editcount'] as $column) {
        $editSelect[] = isset($postColumns[$column])
            ? ('p.`' . $column . '` AS `' . $column . '`')
            : (($column === 'editdate' ? "''" : '0') . ' AS `' . $column . '`');
    }
    return db_all(
        "SELECT p.id, p.posterid, p.threadid, p.title AS post_title, p.body, p.posttime, p.posttimeraw,
                " . implode(', ', $editSelect) . ",
                t.title AS topic_title, t.posterid AS topic_posterid, t.replycount,
                {$timeExpr} AS post_unix,
                {$userStyleCols},
                {$userCols}
           FROM posts p
           INNER JOIN topics t ON t.id = p.threadid
           LEFT JOIN users u ON u.id = p.posterid
          WHERE p.boardid = ?
            AND " . corebb_spam_ratings_visible_clause('posts', 'p') . "
            AND " . corebb_spam_ratings_visible_clause('topics', 't') . "
            AND {$timeExpr} BETWEEN ? AND ?
          ORDER BY p.threadid ASC, {$timeExpr} ASC, p.id ASC",
        [$boardId, $startUnix, $endUnix]
    );
}

/**
 * Usage: Fetch the row used by this admin action.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $boardId Board id.
 * @param int $startUnix Start timestamp.
 * @param int $endUnix End timestamp.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_spam_ratings_fetch_topics(int $boardId, int $startUnix, int $endUnix): array
{
    $timeExpr = corebb_spam_ratings_topic_time_expr();
    return db_all(
        "SELECT t.id, t.title, t.posterid, t.replycount, t.postcount, {$timeExpr} AS topic_unix
           FROM topics t
          WHERE t.boardid = ?
            AND " . corebb_spam_ratings_visible_clause('topics', 't') . "
            AND {$timeExpr} BETWEEN ? AND ?
          ORDER BY {$timeExpr} ASC, t.id ASC",
        [$boardId, $startUnix, $endUnix]
    );
}

/**
 * Usage: Fetch poll summaries for spam-ratings topic rows.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $topicIds Topic id list.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_spam_ratings_poll_lines(array $topicIds): array
{
    if (!$topicIds
        || !db_exists("SHOW TABLES LIKE 'polls'")
        || !db_exists("SHOW TABLES LIKE 'poll_options'")
        || !db_exists("SHOW TABLES LIKE 'poll_votes'")) {
        return [];
    }
    $topicIds = array_values(array_unique(array_filter(array_map('intval', $topicIds), static fn(int $id): bool => $id > 0)));
    if (!$topicIds) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($topicIds), '?'));
    $pollRows = db_all(
        "SELECT pl.id, pl.topicid, pl.question, t.title
           FROM polls pl
           INNER JOIN topics t ON t.id = pl.topicid
          WHERE pl.topicid IN ({$ph})
          ORDER BY pl.created_at DESC, pl.id DESC
          LIMIT 20",
        $topicIds
    );
    if (!$pollRows) {
        return [];
    }

    $lines = ["", "[I]POLL RESULTS[/I]"];
    foreach ($pollRows as $poll) {
        $options = db_all(
            'SELECT o.option_text, COUNT(v.id) AS votes
               FROM poll_options o
               LEFT JOIN poll_votes v ON v.optionid = o.id
              WHERE o.pollid = ?
              GROUP BY o.id, o.option_text, o.position
              ORDER BY o.position ASC, o.id ASC',
            [(int)$poll['id']]
        );
        if (!$options) {
            continue;
        }
        $total = 0;
        foreach ($options as $option) {
            $votes = (int)($option['votes'] ?? 0);
            $total += $votes;
        }
        if ($total <= 0) {
            continue;
        }
        $lines[] = "[color=#0000CC][B]" . corebb_spam_ratings_safe_bbcode_text((string)($poll['title'] ?? $poll['question'] ?? 'Poll')) . "[/B][/color]";
        foreach ($options as $idx => $option) {
            $votes = (int)($option['votes'] ?? 0);
            $percent = (int)round(($votes / max(1, $total)) * 100);
            $lines[] = '[B]' . corebb_spam_ratings_safe_bbcode_text((string)($option['option_text'] ?? 'Choice')) . '[/B] with ' . number_format($votes) . ' vote' . ($votes === 1 ? '' : 's');
            $lines[] = corebb_spam_ratings_bar($votes, $total, corebb_spam_ratings_poll_color((int)$idx)) . ' ' . $percent . '%';
        }
        $lines[] = 'Total Votes: ' . number_format($total);
        $lines[] = '';
    }
    return $lines;
}

/**
 * Usage: Generate the spam-ratings report for a board and time period.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $boardId Board id.
 * @param int $periodDays Analysis period in days.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_spam_ratings_generate(int $boardId, int $periodDays): array
{
    $board = db_one('SELECT f.id, f.name, b.name AS category_name FROM forums f LEFT JOIN boards b ON b.id = f.categoryid WHERE f.id = ? LIMIT 1', [$boardId]);
    if (!$board) {
        return ['ok' => false, 'bbcode' => '', 'message' => 'Unknown board selected.'];
    }

    $periodDays = max(1, min(365, $periodDays));
    $endUnix = time();
    $startUnix = $endUnix - ($periodDays * 86400);
    $posts = corebb_spam_ratings_fetch_posts($boardId, $startUnix, $endUnix);
    $topics = corebb_spam_ratings_fetch_topics($boardId, $startUnix, $endUnix);

    if (!$posts) {
        return ['ok' => true, 'bbcode' => '', 'message' => 'No visible posts were found for this board and period.'];
    }

    $stats = [];
    $threads = [];
    $periodTopicIds = [];
    foreach ($topics as $topic) {
        $periodTopicIds[] = (int)$topic['id'];
        $uid = (int)($topic['posterid'] ?? 0);
        if ($uid > 0) {
            $stats[$uid] ??= ['id' => $uid, 'username' => '', 'period_posts' => 0, 'topics_started' => 0, 'first_replies' => 0, 'thread_killers' => 0, 'lonely_threads' => 0, 'short_posts' => 0, 'mod_edits' => 0, 'keeper_replies' => 0, 'popular_thread_replies' => 0, 'total_posts' => 0, 'age_days' => 1];
            $stats[$uid]['topics_started']++;
        }
    }

    foreach ($posts as $post) {
        $uid = (int)($post['posterid'] ?? 0);
        if ($uid <= 0) {
            continue;
        }
        $stats[$uid] ??= ['id' => $uid, 'username' => '', 'period_posts' => 0, 'topics_started' => 0, 'first_replies' => 0, 'thread_killers' => 0, 'lonely_threads' => 0, 'short_posts' => 0, 'mod_edits' => 0, 'keeper_replies' => 0, 'popular_thread_replies' => 0, 'total_posts' => 0, 'age_days' => 1];
        $stats[$uid]['username'] = (string)($post['username'] ?? ('User ' . $uid));
        $stats[$uid]['period_posts']++;
        $stats[$uid]['total_posts'] = (int)($post['posts'] ?? 0);
        $stats[$uid]['age_days'] = corebb_spam_ratings_parse_user_age_days($post);
        foreach (['vip_bg_color', 'vip_text_color', 'vip_bold', 'vip_italic'] as $styleKey) {
            $stats[$uid][$styleKey] = $post[$styleKey] ?? '';
        }
        if (strlen(corebb_spam_ratings_clean_text((string)($post['body'] ?? ''))) <= 24) {
            $stats[$uid]['short_posts']++;
        }
        $threadId = (int)($post['threadid'] ?? 0);
        $threads[$threadId] ??= ['posts' => [], 'title' => (string)($post['topic_title'] ?? '')];
        $threads[$threadId]['posts'][] = $post;
    }

    foreach ($threads as $thread) {
        $threadPosts = $thread['posts'];
        $count = count($threadPosts);
        if ($count <= 0) {
            continue;
        }
        $first = $threadPosts[0];
        $starterId = (int)($first['posterid'] ?? 0);
        $replies = max(0, $count - 1);
        if ($starterId > 0 && isset($stats[$starterId])) {
            $stats[$starterId]['keeper_replies'] += $replies;
            $stats[$starterId]['popular_thread_replies'] = max((int)$stats[$starterId]['popular_thread_replies'], $replies);
            if ($replies === 0) {
                $stats[$starterId]['lonely_threads']++;
            }
        }
        if ($count >= 2) {
            $firstReplyId = (int)($threadPosts[1]['posterid'] ?? 0);
            if ($firstReplyId > 0 && isset($stats[$firstReplyId])) {
                $stats[$firstReplyId]['first_replies']++;
            }
        }
        $last = $threadPosts[$count - 1];
        $lastId = (int)($last['posterid'] ?? 0);
        if ($lastId > 0 && $lastId !== $starterId && isset($stats[$lastId])) {
            $stats[$lastId]['thread_killers']++;
        }
    }

    $editColumns = corebb_admin_table_columns('posts');
    if (isset($editColumns['editedby'])) {
        foreach ($posts as $post) {
            $editorId = (int)($post['editedby'] ?? 0);
            if ($editorId <= 0) {
                continue;
            }
            $editTs = strtotime((string)($post['editdate'] ?? '')) ?: 0;
            if ($editTs > 0 && $editTs < $startUnix) {
                continue;
            }
            $editor = db_one('SELECT ' . corebb_admin_user_select_list('u') . ', ' . corebb_spam_ratings_user_style_select('u') . ' FROM users u WHERE u.id = ? LIMIT 1', [$editorId]);
            if ($editor) {
                $stats[$editorId] ??= ['id' => $editorId, 'username' => (string)($editor['username'] ?? ('User ' . $editorId)), 'period_posts' => 0, 'topics_started' => 0, 'first_replies' => 0, 'thread_killers' => 0, 'lonely_threads' => 0, 'short_posts' => 0, 'mod_edits' => 0, 'keeper_replies' => 0, 'popular_thread_replies' => 0, 'total_posts' => (int)($editor['posts'] ?? 0), 'age_days' => corebb_spam_ratings_parse_user_age_days($editor)];
                foreach (['vip_bg_color', 'vip_text_color', 'vip_bold', 'vip_italic'] as $styleKey) {
                    $stats[$editorId][$styleKey] = $editor[$styleKey] ?? '';
                }
            }
            $stats[$editorId]['mod_edits']++;
        }
    }

    $rank = corebb_spam_ratings_rank_map($stats);
    $rows = array_values($stats);
    $totalPosts = count($posts);
    $uniqueUsers = count(array_filter($stats, static fn(array $row): bool => (int)($row['period_posts'] ?? 0) > 0));
    $topicCount = count($threads);
    $boardAvg = $totalPosts / max(1, $periodDays);
    $meanPosts = $uniqueUsers > 0 ? $totalPosts / $uniqueUsers : 0;
    $meanAge = $uniqueUsers > 0 ? array_sum(array_map(static fn(array $row): int => (int)($row['age_days'] ?? 1), $rows)) / $uniqueUsers : 0;

    $bestower = corebb_spam_ratings_top_user($stats, 'topics_started');
    $keeper = corebb_spam_ratings_top_user($stats, 'keeper_replies');
    $quickest = corebb_spam_ratings_top_user($stats, 'first_replies');
    $killer = corebb_spam_ratings_top_user($stats, 'thread_killers');
    $lonely = corebb_spam_ratings_top_user($stats, 'lonely_threads');
    $short = corebb_spam_ratings_top_user($stats, 'short_posts');
    $mod = corebb_spam_ratings_top_user($stats, 'mod_edits');

    $lines = [];
    $lines[] = '[B]** ' . corebb_spam_ratings_safe_bbcode_text((string)$board['name']) . ' Spam Ratings **[/B]';
    $lines[] = '';
    $lines[] = number_format($uniqueUsers) . ' unique names sampled from ' . number_format($topicCount) . ' topics over ' . number_format($periodDays) . ' days.';
    $lines[] = number_format($totalPosts) . ' total posts were made on this board over ' . number_format($periodDays) . ' days for a board average of ' . number_format($boardAvg, 2) . ' posts per day.';
    $lines[] = 'Mean post average: ' . number_format($meanPosts, 2) . ' posts per poster.';
    $lines[] = 'Mean login age: ' . number_format($meanAge, 1) . ' days.';
    $lines[] = '';

    if ($bestower) {
        $lines[] = 'Post Bestower: ' . corebb_spam_ratings_username_bbcode($bestower, '#ff6666') . ' with ' . number_format((int)$bestower['topics_started']) . ' threads posted.';
    }
    if ($keeper) {
        $lines[] = 'Post Keeper: ' . corebb_spam_ratings_username_bbcode($keeper, '#ffff66') . ', whose ' . number_format((int)$keeper['topics_started']) . ' threads attracted ' . number_format((int)$keeper['keeper_replies']) . ' total replies.';
    }
    if ($quickest) {
        $lines[] = 'Quickest Draw: ' . corebb_spam_ratings_username_bbcode($quickest, '#66ff66') . ', who replied first on ' . number_format((int)$quickest['first_replies']) . ' threads.';
    }
    if ($killer) {
        $lines[] = 'Thread Killer: ' . corebb_spam_ratings_username_bbcode($killer, '#ff9999') . ', who closed out ' . number_format((int)$killer['thread_killers']) . ' threads.';
    }
    if ($lonely) {
        $lines[] = 'The Loneliest Number: ' . corebb_spam_ratings_username_bbcode($lonely, '#ccccff') . ', who created ' . number_format((int)$lonely['lonely_threads']) . ' threads with no replies.';
    }
    if ($short) {
        $lines[] = 'One-Word Wonder: ' . corebb_spam_ratings_username_bbcode($short, '#ffcc66') . ', who contributed ' . number_format((int)$short['short_posts']) . ' extremely compact posts.';
    }
    if ($mod) {
        $lines[] = 'Ownage by Authority: ' . corebb_spam_ratings_username_bbcode($mod, '#66ffff') . ', who performed ' . number_format((int)$mod['mod_edits']) . ' moderator edits.';
    }

    $lines[] = '';
    $modRows = array_slice(corebb_spam_ratings_sort_desc(array_values(array_filter($stats, static fn(array $row): bool => (int)($row['mod_edits'] ?? 0) > 0)), 'mod_edits'), 0, 10);
    if ($modRows) {
        $lines[] = '[I]MOD SQUAD[/I]';
        foreach ($modRows as $i => $row) {
            $lines[] = ($i + 1) . '. ' . corebb_spam_ratings_username_bbcode($row) . ', who moderated ' . number_format((int)$row['mod_edits']) . ' post' . ((int)$row['mod_edits'] === 1 ? '' : 's') . '.';
        }
        $lines[] = '';
    }

    $topPeriod = array_slice(corebb_spam_ratings_sort_desc($rows, 'period_posts'), 0, 15);
    $lines[] = '[I]' . number_format($periodDays) . '-DAY BOARD TROLLS[/I]';
    foreach ($topPeriod as $i => $row) {
        $ppd = ((int)$row['period_posts']) / max(1, $periodDays);
        $lines[] = ($i + 1) . '. ' . corebb_spam_ratings_username_bbcode($row) . ' - ' . number_format($ppd, 2) . ' posts per day.';
    }
    $lines[] = '';

    $kingRows = $rows;
    foreach ($kingRows as $idx => $row) {
        $kingRows[$idx]['ppd_life'] = ((int)$row['total_posts']) / max(1, (int)$row['age_days']);
    }
    $kingRows = array_slice(corebb_spam_ratings_sort_desc($kingRows, 'ppd_life'), 0, 15);
    $lines[] = '[I]TOP 15 POST KINGS[/I]';
    foreach ($kingRows as $i => $row) {
        $lines[] = ($i + 1) . '. ' . corebb_spam_ratings_username_bbcode($row) . ' - ' . number_format((float)$row['ppd_life'], 2) . ' posts per day over ' . number_format((int)$row['age_days']) . ' days.';
    }
    $lines[] = '';

    usort($rows, static fn(array $a, array $b): int => (int)($b['age_days'] ?? 0) <=> (int)($a['age_days'] ?? 0));
    $lines[] = '[I]TOP 15 OLD FOGIES[/I]';
    foreach (array_slice($rows, 0, 15) as $i => $row) {
        $lines[] = ($i + 1) . '. ' . corebb_spam_ratings_username_bbcode($row) . ' - ' . number_format((int)$row['age_days']) . ' days old.';
    }
    $lines[] = '';

    $lordRows = array_slice(corebb_spam_ratings_sort_desc($rows, 'total_posts'), 0, 15);
    $lines[] = '[I]TOP 15 BOARD LORDS[/I]';
    foreach ($lordRows as $i => $row) {
        $lines[] = ($i + 1) . '. ' . corebb_spam_ratings_username_bbcode($row) . ' - ' . number_format((int)$row['total_posts']) . ' total posts.';
    }

    $lines = array_merge($lines, corebb_spam_ratings_poll_lines(array_merge($periodTopicIds, array_keys($threads))));

    $lines[] = '';
    $lines[] = 'The following list is sorted by name.';
    $alpha = $rows;
    usort($alpha, static fn(array $a, array $b): int => strcasecmp((string)($a['username'] ?? ''), (string)($b['username'] ?? '')));
    foreach ($alpha as $row) {
        $ppd = ((int)$row['period_posts']) / max(1, $periodDays);
        $lifePpd = ((int)$row['total_posts']) / max(1, (int)$row['age_days']);
        $plain = corebb_spam_ratings_safe_bbcode_text((string)($row['username'] ?? 'Unknown'));
        $lines[] = '-' . $plain . '- ' . number_format($ppd, 3) . ' ppd. ' . number_format((int)$row['period_posts']) . ' in ' . number_format($periodDays) . ' days. (' . number_format($lifePpd, 2) . ' over ' . number_format((int)$row['age_days']) . ' days).';
        $lines[] = 'Threads posted: ' . number_format((int)$row['topics_started']) . '; Most popular thread: ' . number_format((int)$row['popular_thread_replies']) . ' replies.';
        $lines[] = 'Threads as first replier: ' . number_format((int)$row['first_replies']) . '; Mod edits: ' . number_format((int)$row['mod_edits']) . '. (Rank: ' . number_format((int)($rank[(int)$row['id']] ?? 0)) . ')';
    }

    return ['ok' => true, 'bbcode' => implode("\n", $lines), 'message' => 'Spam Ratings BBCode generated.'];
}

/**
 * Usage: Build and process the spam ratings admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_spam_ratings_model(array $viewer, array $request, array $post): array
{
    $unit = (string)($request['unit'] ?? $post['unit'] ?? 'days');
    if (!in_array($unit, ['days', 'weeks'], true)) {
        $unit = 'days';
    }
    $periodMax = $unit === 'weeks' ? 52 : 365;
    $period = corebb_spam_ratings_limit_int($request['period'] ?? $post['period'] ?? 7, 7, 1, $periodMax);
    $periodDays = $unit === 'weeks' ? ($period * 7) : $period;
    $boardId = corebb_spam_ratings_limit_int($request['board'] ?? $post['board'] ?? 0, 0, 0, 2147483647);
    $model = [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'boards' => corebb_spam_ratings_boards(),
        'selected_board' => $boardId,
        'period' => $period,
        'unit' => $unit,
        'period_days' => $periodDays,
        'bbcode' => '',
        'messages' => [],
        'errors' => [],
    ];

    if ($boardId > 0) {
        $result = corebb_spam_ratings_generate($boardId, $periodDays);
        if (!empty($result['ok'])) {
            $model['bbcode'] = (string)($result['bbcode'] ?? '');
            if (($result['message'] ?? '') !== '') {
                $model['messages'][] = (string)$result['message'];
            }
        } else {
            $model['errors'][] = (string)($result['message'] ?? 'Unable to generate Spam Ratings.');
        }
    }

    return $model;
}
