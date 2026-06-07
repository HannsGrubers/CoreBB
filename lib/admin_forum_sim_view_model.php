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
 |  admin_forum_sim_view_model.php  - Admin forum       |
 |  simulation/stress-test helpers.                     |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_user_tools_view_model.php';
require_once __DIR__ . '/auth_password_helpers.php';
require_once __DIR__ . '/moderation_helpers.php';
require_once __DIR__ . '/performance_helpers.php';
require_once __DIR__ . '/poll_helpers.php';

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
function corebb_forum_sim_limit_int($value, int $default, int $min, int $max): int
{
    $value = filter_var($value, FILTER_VALIDATE_INT);
    if ($value === false) {
        return $default;
    }
    return max($min, min($max, (int)$value));
}

/**
 * Usage: Return a random integer inside the requested bounds.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $min Minimum value.
 * @param int $max Maximum value.
 * @return int Numeric result for the caller.
 */
function corebb_forum_sim_rand_int(int $min, int $max): int
{
    if ($max <= $min) {
        return $min;
    }
    try {
        return random_int($min, $max);
    } catch (Throwable $e) {
        return mt_rand($min, $max);
    }
}

/**
 * Usage: Resolve a percentage chance roll.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $percent Chance percentage.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_forum_sim_chance(int $percent): bool
{
    return corebb_forum_sim_rand_int(1, 100) <= max(0, min(100, $percent));
}

/**
 * Usage: Assign activity and voting personas to simulated users.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $users Simulator user rows.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_apply_personas(array $users): array
{
    foreach ($users as $idx => $user) {
        $roll = corebb_forum_sim_rand_int(1, 100);
        $activityWeight = match (true) {
            $roll <= 5 => corebb_forum_sim_rand_int(80, 140),
            $roll <= 18 => corebb_forum_sim_rand_int(35, 75),
            $roll <= 62 => corebb_forum_sim_rand_int(8, 30),
            default => corebb_forum_sim_rand_int(1, 7),
        };
        $quoteBias = match (true) {
            $roll <= 8 => corebb_forum_sim_rand_int(55, 85),
            $roll <= 35 => corebb_forum_sim_rand_int(25, 50),
            default => corebb_forum_sim_rand_int(3, 20),
        };
        $voteWeight = corebb_forum_sim_rand_int(1, max(2, (int)round(sqrt($activityWeight) * 5)));

        $users[$idx]['activity_weight'] = $activityWeight;
        $users[$idx]['quote_bias'] = $quoteBias;
        $users[$idx]['vote_weight'] = $voteWeight;
    }

    if ($users) {
        $heavyIdx = corebb_forum_sim_rand_int(0, count($users) - 1);
        $users[$heavyIdx]['activity_weight'] = max((int)($users[$heavyIdx]['activity_weight'] ?? 1), 180);
        $users[$heavyIdx]['quote_bias'] = max((int)($users[$heavyIdx]['quote_bias'] ?? 1), 65);
    }

    return $users;
}

/**
 * Usage: Pick one row using a weighted field.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $rows Weighted row list.
 * @param string $weightKey Weight field name.
 * @param int $fallbackIndex Fallback row index.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_weighted_pick(array $rows, string $weightKey, int $fallbackIndex = 0): array
{
    if (!$rows) {
        return [];
    }
    $total = 0;
    foreach ($rows as $row) {
        $total += max(1, (int)($row[$weightKey] ?? 1));
    }
    if ($total <= 0) {
        return $rows[$fallbackIndex % count($rows)];
    }

    $pick = corebb_forum_sim_rand_int(1, $total);
    foreach ($rows as $row) {
        $pick -= max(1, (int)($row[$weightKey] ?? 1));
        if ($pick <= 0) {
            return $row;
        }
    }

    return $rows[$fallbackIndex % count($rows)];
}

/**
 * Usage: Count records for the admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $maxReplies Maximum reply count.
 * @return int Numeric result for the caller.
 */
function corebb_forum_sim_reply_count(int $maxReplies): int
{
    if ($maxReplies <= 0) {
        return 0;
    }
    $roll = corebb_forum_sim_rand_int(1, 100);
    if ($roll <= 12) {
        return 0;
    }
    if ($roll <= 35) {
        return corebb_forum_sim_rand_int(1, max(1, (int)floor($maxReplies / 2)));
    }
    if ($roll <= 88) {
        return corebb_forum_sim_rand_int(max(1, (int)floor($maxReplies / 2)), $maxReplies);
    }
    return $maxReplies;
}

/**
 * Usage: Read table columns through the shared admin schema helper.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $table Database table name.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_table_columns(string $table): array
{
    return function_exists('corebb_admin_table_columns') ? corebb_admin_table_columns($table) : [];
}

/**
 * Usage: Build a quoted column reference when a simulator column exists.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $columns Existing columns keyed by column name.
 * @param string $alias SQL table alias.
 * @param string $column Database column name.
 * @return string Normalized or display-ready string.
 */
function corebb_forum_sim_column_sql(array $columns, string $alias, string $column): string
{
    if (!isset($columns[$column])) {
        return '';
    }
    return '`' . str_replace('`', '``', $alias) . '`.`' . str_replace('`', '``', $column) . '`';
}

/**
 * Usage: Build SQL conditions that exclude archive/imported rows.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $alias SQL table alias.
 * @param array $columns Existing columns keyed by column name.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_non_archive_conditions(string $alias, array $columns): array
{
    $conditions = [];
    if (isset($columns['legacy_source'])) {
        $col = corebb_forum_sim_column_sql($columns, $alias, 'legacy_source');
        $conditions[] = "({$col} IS NULL OR {$col} = '' OR {$col} NOT IN ('vn_archive', 'vnboards'))";
    }
    if (isset($columns['is_archive_user'])) {
        $col = corebb_forum_sim_column_sql($columns, $alias, 'is_archive_user');
        $conditions[] = "COALESCE({$col}, 0) = 0";
    }
    return $conditions;
}

/**
 * Usage: Fetch boards that may receive simulator content.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_available_boards(): array
{
    $forumColumns = corebb_forum_sim_table_columns('forums');
    $categoryColumns = corebb_forum_sim_table_columns('boards');
    $where = ['f.id > 0'];
    $where = array_merge($where, corebb_forum_sim_non_archive_conditions('f', $forumColumns));
    $where = array_merge($where, corebb_forum_sim_non_archive_conditions('b', $categoryColumns));
    if (isset($forumColumns['private'])) {
        $where[] = 'COALESCE(f.`private`, 0) = 0';
    }
    if (isset($forumColumns['secure_archive'])) {
        $where[] = 'COALESCE(f.`secure_archive`, 0) = 0';
    }
    if (isset($categoryColumns['private'])) {
        $where[] = 'COALESCE(b.`private`, 0) = 0';
    }
    if (isset($categoryColumns['secure_archive'])) {
        $where[] = 'COALESCE(b.`secure_archive`, 0) = 0';
    }

    return db_all(
        'SELECT f.id, f.name, f.categoryid, b.name AS category_name
           FROM forums f
           LEFT JOIN boards b ON b.id = f.categoryid
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY b.position ASC, f.position ASC, f.id ASC'
    );
}

/**
 * Usage: Restrict simulator boards to the selected board when requested.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $boards Board rows to filter.
 * @param int $targetBoardId Target board id.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_filter_target_boards(array $boards, int $targetBoardId): array
{
    if ($targetBoardId <= 0) {
        return $boards;
    }
    return array_values(array_filter($boards, static function (array $board) use ($targetBoardId): bool {
        return (int)($board['id'] ?? 0) === $targetBoardId;
    }));
}

/**
 * Usage: Insert a simulator row and return its id.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $table Database table name.
 * @param array $values Column values keyed by database field.
 * @return int Numeric result for the caller.
 */
function corebb_forum_sim_insert_row(string $table, array $values): int
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return 0;
    }
    $columns = array_keys($values);
    $sqlColumns = implode(', ', array_map(static function (string $column): string {
        return '`' . str_replace('`', '``', $column) . '`';
    }, $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    if (!db_run('INSERT INTO `' . str_replace('`', '``', $table) . '` (' . $sqlColumns . ') VALUES (' . $placeholders . ')', array_values($values))) {
        return 0;
    }
    return db_insert_id();
}

/**
 * Usage: Create one simulator user account.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $username Username value.
 * @param int $level Access level assigned to the generated user.
 * @param string $runId Simulator run identifier.
 * @param int $index Zero-based index.
 * @param array $registeredAt Registration timestamp pair.
 * @return int Numeric result for the caller.
 */
function corebb_forum_sim_create_user(string $username, int $level, string $runId, int $index, array $registeredAt): int
{
    $columns = corebb_forum_sim_table_columns('users');
    $password = corebb_auth_password_hash(bin2hex(random_bytes(12)));
    $email = strtolower($username) . '@sim.corebb.invalid';
    $date = date('M y', (int)$registeredAt['unix']);
    $now = (string)(int)$registeredAt['unix'];
    $values = [
        'username' => $username,
        'password' => $password,
        'accesslevel' => $level,
    ];

    foreach ([
        'posts' => 0,
        'ThreadPages' => 25,
        'BoardPages' => 25,
        'approved' => 1,
        'regdate' => $date,
        'profadded' => $date,
        'lastip' => '127.0.0.' . (($index % 200) + 1),
        'lastlogindate' => $now,
        'lastpstdate' => '',
        'lastpost' => '',
        'privemail' => $email,
        'email' => $email,
        'publicemail' => '',
        'privateemail' => $email,
        'legacy_source' => '',
        'legacy_user_id' => 0,
        'legacy_remote_user_id' => 0,
        'legacy_username' => '',
        'legacy_identity_key' => '',
        'is_archive_user' => 0,
        'profiletitle' => $level >= 3 ? 'Sim Moderator' : 'Simulated Member',
        'title' => $level >= 3 ? 'Sim Moderator' : 'Simulated Member',
    ] as $column => $value) {
        if (isset($columns[$column])) {
            $values[$column] = $value;
        }
    }

    return corebb_forum_sim_insert_row('users', $values);
}

/**
 * Usage: Trim a generated post body for use as a quote.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $body Message body.
 * @return string Normalized or display-ready string.
 */
function corebb_forum_sim_quote_excerpt(string $body): string
{
    $body = preg_replace('~\[(?:/?)[^\]]+\]~', '', $body) ?? $body;
    $body = trim(preg_replace('~\s+~', ' ', $body) ?? $body);
    if ($body === '') {
        return 'That earlier sim post had a useful point for this thread.';
    }
    if (function_exists('mb_strcut')) {
        return mb_strcut($body, 0, 180, 'UTF-8');
    }
    return substr($body, 0, 180);
}

/**
 * Usage: Generate a BBCode-heavy simulator post body.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $seed Deterministic content seed.
 * @param array $quotePool Available quote source rows.
 * @param int $quoteChance Chance of adding a quote.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_bbcode_body(int $seed, array $quotePool = [], int $quoteChance = 30): array
{
    $pieces = [
        'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
        'Integer luctus mauris id arcu posuere, vel tincidunt ipsum laoreet.',
        'Suspendisse potenti. Donec at lectus sed neque blandit consequat.',
        'Curabitur tempor, mauris a feugiat rhoncus, mi lacus facilisis justo, vitae posuere magna est id lorem.',
        'Praesent non justo nec velit dapibus ultricies in at lorem.',
        'Aliquam erat volutpat. Sed at nisi non lacus mattis viverra.',
    ];
    $patterns = [
        "[b]%s[/b]\n\n%s\n\n[link=https://example.invalid/corebb-sim/%d]Sim workload reference[/link]",
        "%s\n\n[i]%s[/i]\n\n[ul][li]Simulated observation[/li][li]Generated forum workload[/li][/ul]",
        "%s\n\n[u]%s[/u]\n\n[code]sim_case_%d = true;[/code]",
        "[color=blue]%s[/color]\n\n%s",
    ];
    $paragraphs = [];
    for ($i = 0; $i < 2; $i++) {
        $paragraphs[] = $pieces[($seed + $i) % count($pieces)];
    }

    $body = sprintf(
        $patterns[$seed % count($patterns)],
        $paragraphs[0],
        $paragraphs[1],
        $seed
    );

    $quotedPost = false;
    if ($quotePool && corebb_forum_sim_chance($quoteChance)) {
        $quoted = $quotePool[corebb_forum_sim_rand_int(0, count($quotePool) - 1)];
        $author = trim((string)($quoted['author'] ?? 'SimUser'));
        $excerpt = corebb_forum_sim_quote_excerpt((string)($quoted['body'] ?? ''));
        if ($author !== '') {
            $body = '[quote=' . $author . ']' . $excerpt . "[/quote]\n\n" . $body;
            $quotedPost = true;
        }
    }

    return [
        'body' => $body . "\n\n[i][sim] CoreBB forum simulation run generated this message.[/i]",
        'quoted' => $quotedPost,
    ];
}

/**
 * Usage: Create a VN-style timestamp pair from an offset.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $offset Timestamp offset.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_timestamp(int $offset): array
{
    return corebb_forum_sim_timestamp_from_unix(time() + $offset);
}

/**
 * Usage: Create a VN-style timestamp pair from Unix time.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $unix Unix timestamp.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_timestamp_from_unix(int $unix): array
{
    return [
        'vn_date' => function_exists('convert_to_timestamp_raw') ? convert_to_timestamp_raw($unix) : date('Y-m-d H:i:s', $unix),
        'unix' => $unix,
        'short_date' => date('m/d/y', $unix),
    ];
}

/**
 * Usage: Spread simulator timestamps across a configured time window.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $index Zero-based index.
 * @param int $total Total item count.
 * @param int $startUnix Start timestamp.
 * @param int $endUnix End timestamp.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_spread_timestamp(int $index, int $total, int $startUnix, int $endUnix): array
{
    $total = max(1, $total);
    $index = max(1, min($total, $index));
    if ($total === 1 || $endUnix <= $startUnix) {
        return corebb_forum_sim_timestamp_from_unix($startUnix);
    }
    $step = ($endUnix - $startUnix) / max(1, $total - 1);
    return corebb_forum_sim_timestamp_from_unix((int)round($startUnix + (($index - 1) * $step)));
}

/**
 * Usage: Place a simulator registration date inside the configured span.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $index Zero-based index.
 * @param int $total Total item count.
 * @param int $registrationSpanDays Registration date spread in days.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_registration_timestamp(int $index, int $total, int $registrationSpanDays): array
{
    $registrationSpanDays = max(1, $registrationSpanDays);
    $startUnix = time() - ($registrationSpanDays * 86400);
    $endUnix = time() - 3600;
    return corebb_forum_sim_spread_timestamp($index, max(1, $total), $startUnix, $endUnix);
}

/**
 * Usage: Create generated poll text and options.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $seed Deterministic content seed.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_poll_payload(int $seed): array
{
    $questions = [
        'Which sim workload feels most realistic?',
        'How should this generated thread be classified?',
        'What kind of forum traffic should this board receive next?',
        'Which moderation action should be tested more often?',
    ];
    $optionSets = [
        ['Mostly replies', 'New topics', 'Poll activity', 'Moderator edits'],
        ['Normal discussion', 'Link-heavy chatter', 'Quote-heavy debate', 'Needs review'],
        ['Light weekday traffic', 'Busy weekend traffic', 'Late-night posting', 'Mixed activity'],
        ['Soft edits', 'Lock checks', 'Sticky checks', 'Report triage'],
    ];
    $idx = $seed % count($questions);
    return ['question' => $questions[$idx], 'options' => $optionSets[$idx]];
}

/**
 * Usage: Create a generated poll and assign simulated votes.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $topicId Topic id.
 * @param int $creatorId Poll creator user id.
 * @param array $users Simulator user rows.
 * @param int $votesPerPoll Votes to assign per poll.
 * @param int $seed Deterministic content seed.
 * @param array $createdAt Created timestamp pair.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_create_poll_with_votes(int $topicId, int $creatorId, array $users, int $votesPerPoll, int $seed, array $createdAt): array
{
    $summary = ['polls' => 0, 'votes' => 0];
    if ($topicId <= 0 || $creatorId <= 0 || $votesPerPoll <= 0 || !$users) {
        return $summary;
    }

    $payload = corebb_forum_sim_poll_payload($seed);
    $poll = corebb_poll_create_for_topic($topicId, $creatorId, (string)$payload['question'], (array)$payload['options']);
    if (empty($poll['ok'])) {
        return $summary;
    }

    $pollId = (int)($poll['pollid'] ?? 0);
    if ($pollId <= 0) {
        return $summary;
    }
    db_run('UPDATE polls SET created_at = ? WHERE id = ?', [(int)$createdAt['unix'], $pollId]);

    $options = db_all('SELECT id FROM poll_options WHERE pollid = ? ORDER BY position ASC, id ASC', [$pollId]);
    if (!$options) {
        return ['polls' => 1, 'votes' => 0];
    }

    $maxVotes = min($votesPerPoll, count($users));
    if ($maxVotes <= 0) {
        $summary['polls'] = 1;
        return $summary;
    }
    $targetVotes = corebb_forum_sim_rand_int(max(1, (int)ceil($maxVotes * 0.35)), $maxVotes);
    $weightedOptions = [];
    foreach ($options as $option) {
        $option['sim_weight'] = corebb_forum_sim_rand_int(1, 20);
        $weightedOptions[] = $option;
    }

    $usedUserIds = [];
    $attempts = 0;
    while ($summary['votes'] < $targetVotes && $attempts < ($targetVotes * 8)) {
        $attempts++;
        $user = corebb_forum_sim_weighted_pick($users, 'vote_weight', $seed + $attempts);
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0 || isset($usedUserIds[$userId])) {
            continue;
        }
        $usedUserIds[$userId] = true;
        $option = corebb_forum_sim_weighted_pick($weightedOptions, 'sim_weight', $seed + $attempts);
        $votedAt = (int)$createdAt['unix'] + (($summary['votes'] + 1) * corebb_forum_sim_rand_int(30, 180));
        if (db_run(
            'INSERT INTO poll_votes (pollid, optionid, userid, voted_at, ip_address) VALUES (?, ?, ?, ?, ?)',
            [$pollId, (int)$option['id'], $userId, $votedAt, '127.0.30.' . (($userId % 200) + 1)]
        )) {
            $summary['votes']++;
        }
    }

    $summary['polls'] = 1;
    return $summary;
}

/**
 * Usage: Build SQL placeholders for an id list.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $ids ID list.
 * @return string Normalized or display-ready string.
 */
function corebb_forum_sim_ids_placeholders(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

/**
 * Usage: Remove content created by previous simulator runs.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_wipe(): array
{
    @set_time_limit(0);
    corebb_poll_ensure_schema();

    $userColumns = corebb_forum_sim_table_columns('users');
    $emailConditions = [];
    foreach (['email', 'privemail', 'privateemail'] as $column) {
        if (isset($userColumns[$column])) {
            $emailConditions[] = '`' . $column . "` LIKE '%@sim.corebb.invalid'";
        }
    }
    $userRows = [];
    if ($emailConditions) {
        $userWhere = "username LIKE 'Sim%' AND (" . implode(' OR ', $emailConditions) . ')';
        $userRows = db_all("SELECT id FROM users WHERE {$userWhere}");
    }
    $userIds = array_values(array_unique(array_filter(array_map(static fn($row): int => (int)($row['id'] ?? 0), $userRows))));

    $topicRows = db_all("SELECT id, boardid FROM topics WHERE title LIKE 'Sim Test % Topic %'");
    $topicIds = array_values(array_unique(array_filter(array_map(static fn($row): int => (int)($row['id'] ?? 0), $topicRows))));
    $boardIds = array_values(array_unique(array_filter(array_map(static fn($row): int => (int)($row['boardid'] ?? 0), $topicRows))));

    $summary = [
        'users' => count($userIds),
        'topics' => count($topicIds),
        'posts' => 0,
        'polls' => 0,
        'votes' => 0,
    ];

    if (!$userIds && !$topicIds) {
        return ['ok' => true, 'messages' => ['No generated forum sim-test rows were found.'], 'errors' => []];
    }
    if (!db_begin()) {
        return ['ok' => false, 'messages' => [], 'errors' => ['Could not start wipe transaction: ' . db_error()]];
    }

    if ($topicIds) {
        $topicPh = corebb_forum_sim_ids_placeholders($topicIds);
        $postRows = db_all("SELECT id FROM posts WHERE threadid IN ({$topicPh})", $topicIds);
        $postIds = array_values(array_unique(array_filter(array_map(static fn($row): int => (int)($row['id'] ?? 0), $postRows))));
        $summary['posts'] = count($postIds);

        $pollRows = db_all("SELECT id FROM polls WHERE topicid IN ({$topicPh})", $topicIds);
        $pollIds = array_values(array_unique(array_filter(array_map(static fn($row): int => (int)($row['id'] ?? 0), $pollRows))));
        $summary['polls'] = count($pollIds);
        if ($pollIds) {
            $pollPh = corebb_forum_sim_ids_placeholders($pollIds);
            $voteRows = db_all("SELECT COUNT(*) AS total FROM poll_votes WHERE pollid IN ({$pollPh})", $pollIds);
            $summary['votes'] = (int)($voteRows[0]['total'] ?? 0);
            if (!db_run("DELETE FROM poll_votes WHERE pollid IN ({$pollPh})", $pollIds)
                || !db_run("DELETE FROM poll_options WHERE pollid IN ({$pollPh})", $pollIds)
                || !db_run("DELETE FROM polls WHERE id IN ({$pollPh})", $pollIds)) {
                db_rollback();
                return ['ok' => false, 'messages' => [], 'errors' => ['Error deleting generated polls: ' . db_error()]];
            }
        }

        if (!db_run("DELETE FROM posts WHERE threadid IN ({$topicPh})", $topicIds)
            || !db_run("DELETE FROM topics WHERE id IN ({$topicPh})", $topicIds)) {
            db_rollback();
            return ['ok' => false, 'messages' => [], 'errors' => ['Error deleting generated topics/posts: ' . db_error()]];
        }
    }

    if ($userIds) {
        $userPh = corebb_forum_sim_ids_placeholders($userIds);
        if (!db_run("DELETE FROM users WHERE id IN ({$userPh})", $userIds)) {
            db_rollback();
            return ['ok' => false, 'messages' => [], 'errors' => ['Error deleting generated users: ' . db_error()]];
        }
    }

    if (!db_commit()) {
        db_rollback();
        return ['ok' => false, 'messages' => [], 'errors' => ['Error finalizing sim wipe: ' . db_error()]];
    }

    foreach ($boardIds as $boardId) {
        $counts = db_one('SELECT COUNT(*) AS topics, COALESCE(SUM(GREATEST(COALESCE(replycount, 0) + 1, 1)), 0) AS posts FROM topics WHERE boardid = ?', [(int)$boardId]);
        $last = db_one('SELECT lastpost, now FROM topics WHERE boardid = ? ORDER BY now DESC, id DESC LIMIT 1', [(int)$boardId]);
        db_run(
            'UPDATE forums SET topiccount = ?, postcount = ?, lastpstdate = ?, lastpstdatets = ? WHERE id = ?',
            [
                (int)($counts['topics'] ?? 0),
                (int)($counts['posts'] ?? 0),
                (string)($last['lastpost'] ?? ''),
                (int)($last['now'] ?? 0),
                (int)$boardId,
            ]
        );
    }

    return [
        'ok' => true,
        'messages' => [
            'Wiped generated forum sim-test rows.',
            'Removed ' . $summary['users'] . ' generated users, ' . $summary['topics'] . ' generated topics, and ' . $summary['posts'] . ' generated posts/replies.',
            'Removed ' . $summary['polls'] . ' generated polls and ' . $summary['votes'] . ' generated poll votes.',
        ],
        'errors' => [],
    ];
}

/**
 * Usage: Create one simulator topic and starter post.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $board Board row.
 * @param array $author Post author row.
 * @param string $title Title text.
 * @param string $body Message body.
 * @param array $now Current timestamp pair.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_create_topic(array $board, array $author, string $title, string $body, array $now): array
{
    $boardId = (int)($board['id'] ?? 0);
    $userId = (int)($author['id'] ?? 0);
    $username = (string)($author['username'] ?? 'SimUser');
    if (!db_begin()) {
        return ['ok' => false, 'message' => 'Could not start topic transaction: ' . db_error()];
    }

    $topicId = corebb_forum_sim_insert_row('topics', [
        'boardid' => $boardId,
        'title' => $title,
        'body' => $body,
        'posterid' => $userId,
        'lastpost' => $now['vn_date'],
        'now' => $now['unix'],
        'time' => $now['unix'],
        'sticky' => 0,
    ]);
    if ($topicId <= 0) {
        db_rollback();
        return ['ok' => false, 'message' => 'Error creating simulated topic: ' . db_error()];
    }

    $postId = corebb_forum_sim_insert_row('posts', [
        'posterid' => $userId,
        'title' => $title,
        'body' => $body,
        'author' => $username,
        'threadid' => $topicId,
        'boardid' => $boardId,
        'ptd' => $now['short_date'],
        'posttime' => $now['vn_date'],
        'posttimeraw' => $now['unix'],
        'postip' => '127.0.10.' . (($userId % 200) + 1),
    ]);
    if ($postId <= 0) {
        db_rollback();
        return ['ok' => false, 'message' => 'Error creating simulated first post: ' . db_error()];
    }

    $updatesOk = db_run('UPDATE forums SET lastpstdate = ?, lastpstdatets = ? WHERE id = ?', [$now['vn_date'], $now['unix'], $boardId])
        && db_run('UPDATE topics SET lastpost = ?, now = ?, postcount = 1, replycount = 0 WHERE id = ?', [$now['vn_date'], $now['unix'], $topicId])
        && db_run('UPDATE users SET posts = COALESCE(posts, 0) + 1, lastpost = ?, lastpstdate = ? WHERE id = ?', [$now['unix'], $now['vn_date'], $userId]);
    if ($updatesOk && function_exists('corebb_perf_cache_ready') && corebb_perf_cache_ready()) {
        $updatesOk = db_run('UPDATE forums SET topiccount = COALESCE(topiccount, 0) + 1, postcount = COALESCE(postcount, 0) + 1 WHERE id = ?', [$boardId]);
    }
    if (!$updatesOk || !db_commit()) {
        db_rollback();
        return ['ok' => false, 'message' => 'Error finalizing simulated topic: ' . db_error()];
    }

    return ['ok' => true, 'topic_id' => $topicId, 'post_id' => $postId];
}

/**
 * Usage: Create one simulator reply.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $topicId Topic id.
 * @param int $boardId Board id.
 * @param array $author Post author row.
 * @param string $title Title text.
 * @param string $body Message body.
 * @param array $now Current timestamp pair.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_create_reply(int $topicId, int $boardId, array $author, string $title, string $body, array $now): array
{
    $userId = (int)($author['id'] ?? 0);
    $username = (string)($author['username'] ?? 'SimUser');
    if (!db_begin()) {
        return ['ok' => false, 'message' => 'Could not start reply transaction: ' . db_error()];
    }

    $postId = corebb_forum_sim_insert_row('posts', [
        'posterid' => $userId,
        'title' => $title,
        'body' => $body,
        'author' => $username,
        'threadid' => $topicId,
        'boardid' => $boardId,
        'ptd' => $now['short_date'],
        'posttime' => $now['vn_date'],
        'posttimeraw' => $now['unix'],
        'postip' => '127.0.20.' . (($userId % 200) + 1),
    ]);
    if ($postId <= 0) {
        db_rollback();
        return ['ok' => false, 'message' => 'Error creating simulated reply: ' . db_error()];
    }

    $updatesOk = db_run('UPDATE forums SET lastpstdate = ?, lastpstdatets = ? WHERE id = ?', [$now['vn_date'], $now['unix'], $boardId])
        && db_run('UPDATE users SET posts = COALESCE(posts, 0) + 1, lastpost = ?, lastpstdate = ? WHERE id = ?', [$now['unix'], $now['vn_date'], $userId])
        && db_run('UPDATE topics SET lastpost = ?, now = ?, postcount = COALESCE(postcount, 0) + 1, replycount = COALESCE(replycount, 0) + 1 WHERE id = ?', [$now['vn_date'], $now['unix'], $topicId]);
    if ($updatesOk && function_exists('corebb_perf_cache_ready') && corebb_perf_cache_ready()) {
        $updatesOk = db_run('UPDATE forums SET postcount = COALESCE(postcount, 0) + 1 WHERE id = ?', [$boardId]);
    }
    if (!$updatesOk || !db_commit()) {
        db_rollback();
        return ['ok' => false, 'message' => 'Error finalizing simulated reply: ' . db_error()];
    }

    return ['ok' => true, 'post_id' => $postId];
}

/**
 * Usage: Apply the requested admin change and return its result state.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $modUsers Moderator simulator users.
 * @param array $postIds Post id list.
 * @param array $topicIds Topic id list.
 * @param int $editCount Number of edit actions to attempt.
 * @param int $topicActionCount Number of topic actions to attempt.
 * @param string $runId Simulator run identifier.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_perform_mod_actions(array $modUsers, array $postIds, array $topicIds, int $editCount, int $topicActionCount, string $runId): array
{
    $summary = ['edits' => 0, 'topic_actions' => 0];
    if (!$modUsers) {
        return $summary;
    }
    corebb_mod_ensure_schema();

    for ($i = 0; $i < $editCount && $postIds; $i++) {
        $postId = $postIds[$i % count($postIds)];
        $mod = $modUsers[$i % count($modUsers)];
        $editDate = function_exists('convert_to_timestamp_raw') ? convert_to_timestamp_raw(time() + 5000 + $i) : date('Y-m-d H:i:s');
        $post = corebb_mod_get_post((int)$postId, true);
        if (!$post || (int)($post['is_deleted'] ?? 0) !== 0) {
            continue;
        }
        $title = (string)($post['title'] ?? 'Simulated message');
        $body = rtrim((string)($post['body'] ?? '')) . "\n\n[i]Moderator simulation edit {$runId}-" . ($i + 1) . '[/i]';
        if (corebb_mod_update_post_with_edit_metadata((int)$postId, $title, $body, (int)$mod['id'], $editDate)) {
            $summary['edits']++;
            if (function_exists('addlogentry')) {
                addlogentry((string)$mod['id'], 3, 'Sim-test moderator edited post ' . (int)$postId, 'forum_sim', 'Forum sim-test moderator edit.');
            }
        }
    }

    for ($i = 0; $i < $topicActionCount && $topicIds; $i++) {
        $topicId = $topicIds[$i % count($topicIds)];
        $mod = $modUsers[$i % count($modUsers)];
        $locked = ($i % 2) === 0 ? 1 : 0;
        $sticky = ($i % 3) === 0 ? 1 : 0;
        if (db_run('UPDATE topics SET locked = ?, sticky = ? WHERE id = ?', [$locked, $sticky, (int)$topicId])) {
            $summary['topic_actions']++;
            if (function_exists('addlogentry')) {
                addlogentry((string)$mod['id'], 3, 'Sim-test moderator updated topic ' . (int)$topicId, 'forum_sim', 'Forum sim-test lock/sticky update.');
            }
        }
    }

    return $summary;
}

/**
 * Usage: Run the full forum simulator job.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $settings Simulator settings.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_forum_sim_run(array $viewer, array $settings): array
{
    @set_time_limit(0);
    corebb_auth_ensure_schema();
    corebb_mod_ensure_schema();

    $targetBoardId = (int)($settings['target_board'] ?? 0);
    $allBoards = corebb_forum_sim_available_boards();
    $boards = corebb_forum_sim_filter_target_boards($allBoards, $targetBoardId);
    if (!$boards) {
        return ['ok' => false, 'messages' => [], 'errors' => [$targetBoardId > 0 ? 'The selected board is not available for simulation.' : 'No public non-archive boards are available for simulation.']];
    }

    $runId = date('ymdHis');
    $userCount = (int)$settings['users'];
    $modCount = (int)$settings['mods'];
    $topicCount = (int)$settings['topics'];
    $repliesPerTopic = (int)$settings['replies'];
    $editCount = (int)$settings['edits'];
    $topicActionCount = (int)$settings['topic_actions'];
    $pollTopicCount = min((int)$settings['poll_topics'], $topicCount);
    $votesPerPoll = (int)$settings['poll_votes'];
    $spanDays = max(1, (int)$settings['span_days']);
    $registrationSpanDays = max(1, (int)$settings['registration_span_days']);
    $totalPostsPlanned = $topicCount * (1 + $repliesPerTopic);
    $activityStartUnix = time() - (($spanDays - 1) * 86400);
    $activityEndUnix = time();
    $sequence = 1;
    $totalUsers = $userCount + $modCount;

    $users = [];
    $modUsers = [];
    for ($i = 1; $i <= $modCount; $i++) {
        $username = 'SimMod' . substr($runId, -6) . sprintf('%02d', $i);
        $registeredAt = corebb_forum_sim_registration_timestamp($i, $totalUsers, $registrationSpanDays);
        $id = corebb_forum_sim_create_user($username, 3, $runId, $i, $registeredAt);
        if ($id <= 0) {
            return ['ok' => false, 'messages' => [], 'errors' => ['Error creating simulated moderator: ' . db_error()]];
        }
        $modUsers[] = ['id' => $id, 'username' => $username];
        $users[] = ['id' => $id, 'username' => $username];
    }

    for ($i = 1; $i <= $userCount; $i++) {
        $username = 'Sim' . substr($runId, -6) . sprintf('%03d', $i);
        $registeredAt = corebb_forum_sim_registration_timestamp($i + $modCount, $totalUsers, $registrationSpanDays);
        $id = corebb_forum_sim_create_user($username, 1, $runId, $i + $modCount, $registeredAt);
        if ($id <= 0) {
            return ['ok' => false, 'messages' => [], 'errors' => ['Error creating simulated user: ' . db_error()]];
        }
        $users[] = ['id' => $id, 'username' => $username];
    }

    $users = corebb_forum_sim_apply_personas($users);
    $replyCounts = [];
    for ($i = 1; $i <= $topicCount; $i++) {
        $replyCounts[$i] = corebb_forum_sim_reply_count($repliesPerTopic);
    }
    $totalPostsPlanned = $topicCount + array_sum($replyCounts);

    $topicIds = [];
    $postIds = [];
    $postSamples = [];
    $postsCreated = 0;
    $quotesCreated = 0;
    $pollsCreated = 0;
    $votesCreated = 0;
    for ($i = 1; $i <= $topicCount; $i++) {
        $board = $boards[($i - 1) % count($boards)];
        $author = corebb_forum_sim_weighted_pick($users, 'activity_weight', $i - 1);
        $title = 'Sim Test ' . $runId . ' Topic ' . $i;
        $topicBody = corebb_forum_sim_bbcode_body($i, $postSamples, (int)($author['quote_bias'] ?? 30));
        $body = (string)$topicBody['body'];
        $quotesCreated += !empty($topicBody['quoted']) ? 1 : 0;
        $topicAt = corebb_forum_sim_spread_timestamp($sequence, $totalPostsPlanned, $activityStartUnix, $activityEndUnix);
        $sequence++;
        $topic = corebb_forum_sim_create_topic($board, $author, $title, $body, $topicAt);
        if (empty($topic['ok'])) {
            return ['ok' => false, 'messages' => [], 'errors' => [(string)($topic['message'] ?? 'Topic creation failed.')]];
        }
        $topicId = (int)$topic['topic_id'];
        $topicIds[] = $topicId;
        $postIds[] = (int)$topic['post_id'];
        $postSamples[] = ['post_id' => (int)$topic['post_id'], 'author' => (string)$author['username'], 'body' => $body];
        $postsCreated++;

        if ($i <= $pollTopicCount && $votesPerPoll > 0) {
            $pollSummary = corebb_forum_sim_create_poll_with_votes($topicId, (int)$author['id'], $users, $votesPerPoll, $i, $topicAt);
            $pollsCreated += (int)$pollSummary['polls'];
            $votesCreated += (int)$pollSummary['votes'];
        }

        $replyCount = (int)($replyCounts[$i] ?? 0);
        for ($r = 1; $r <= $replyCount; $r++) {
            $replyAuthor = corebb_forum_sim_weighted_pick($users, 'activity_weight', $i + $r);
            $replyBodyData = corebb_forum_sim_bbcode_body($i + $r + 100, $postSamples, (int)($replyAuthor['quote_bias'] ?? 30));
            $replyBody = (string)$replyBodyData['body'];
            $quotesCreated += !empty($replyBodyData['quoted']) ? 1 : 0;
            $replyAt = corebb_forum_sim_spread_timestamp($sequence, $totalPostsPlanned, $activityStartUnix, $activityEndUnix);
            $sequence++;
            $reply = corebb_forum_sim_create_reply(
                $topicId,
                (int)$board['id'],
                $replyAuthor,
                'Re: ' . $title,
                $replyBody,
                $replyAt
            );
            if (empty($reply['ok'])) {
                return ['ok' => false, 'messages' => [], 'errors' => [(string)($reply['message'] ?? 'Reply creation failed.')]];
            }
            $postIds[] = (int)$reply['post_id'];
            $postSamples[] = ['post_id' => (int)$reply['post_id'], 'author' => (string)$replyAuthor['username'], 'body' => $replyBody];
            $postsCreated++;
        }
    }

    $modSummary = corebb_forum_sim_perform_mod_actions($modUsers, $postIds, $topicIds, $editCount, $topicActionCount, $runId);
    if (function_exists('addlogentry')) {
        addlogentry(
            (string)($viewer['username'] ?? 'Unknown'),
            (int)($viewer['accesslevel'] ?? 0),
            'Ran forum sim-test ' . $runId,
            'forum_sim',
            'Created ' . count($users) . ' users, ' . count($topicIds) . ' topics, ' . $postsCreated . ' posts.'
        );
    }

    return [
        'ok' => true,
        'messages' => [
            'Forum sim-test run ' . $runId . ' complete.',
            'Target board scope: ' . ($targetBoardId > 0 ? ((string)($boards[0]['category_name'] ?? '') . ' / ' . (string)($boards[0]['name'] ?? 'Selected Board')) : 'All eligible public non-archive boards') . '.',
            'Created ' . count($users) . ' generated users, including ' . count($modUsers) . ' moderator users.',
            'Spread registration dates across ' . $registrationSpanDays . ' day' . ($registrationSpanDays === 1 ? '' : 's') . ' and post dates across ' . $spanDays . ' day' . ($spanDays === 1 ? '' : 's') . '.',
            'Created ' . count($topicIds) . ' generated topics and ' . $postsCreated . ' generated posts/replies.',
            'Generated ' . $quotesCreated . ' quote blocks with randomized per-user quote frequency.',
            'Created ' . $pollsCreated . ' generated polls and ' . $votesCreated . ' generated poll votes.',
            'Performed ' . (int)$modSummary['edits'] . ' moderator edits and ' . (int)$modSummary['topic_actions'] . ' topic lock/sticky updates.',
            'Archive users, archive boards, archive topics, and archive posts were not selected as simulation targets.',
        ],
        'errors' => [],
    ];
}

/**
 * Usage: Build and process the forum sim admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_forum_sim_model(array $viewer, array $request, array $post): array
{
    $defaults = [
        'users' => 25,
        'mods' => 2,
        'topics' => 40,
        'replies' => 5,
        'poll_topics' => 8,
        'poll_votes' => 10,
        'span_days' => 14,
        'registration_span_days' => 365,
        'edits' => 25,
        'topic_actions' => 10,
        'target_board' => 0,
    ];

    $settings = [
        'target_board' => corebb_forum_sim_limit_int($post['target_board'] ?? $request['target_board'] ?? $defaults['target_board'], $defaults['target_board'], 0, 2147483647),
        'users' => corebb_forum_sim_limit_int($post['users'] ?? $request['users'] ?? $defaults['users'], $defaults['users'], 1, 250),
        'mods' => corebb_forum_sim_limit_int($post['mods'] ?? $request['mods'] ?? $defaults['mods'], $defaults['mods'], 1, 2),
        'topics' => corebb_forum_sim_limit_int($post['topics'] ?? $request['topics'] ?? $defaults['topics'], $defaults['topics'], 1, 500),
        'replies' => corebb_forum_sim_limit_int($post['replies'] ?? $request['replies'] ?? $defaults['replies'], $defaults['replies'], 0, 25),
        'poll_topics' => corebb_forum_sim_limit_int($post['poll_topics'] ?? $request['poll_topics'] ?? $defaults['poll_topics'], $defaults['poll_topics'], 0, 250),
        'poll_votes' => corebb_forum_sim_limit_int($post['poll_votes'] ?? $request['poll_votes'] ?? $defaults['poll_votes'], $defaults['poll_votes'], 0, 100),
        'span_days' => corebb_forum_sim_limit_int($post['span_days'] ?? $request['span_days'] ?? $defaults['span_days'], $defaults['span_days'], 1, 90),
        'registration_span_days' => corebb_forum_sim_limit_int($post['registration_span_days'] ?? $request['registration_span_days'] ?? $defaults['registration_span_days'], $defaults['registration_span_days'], 1, 5000),
        'edits' => corebb_forum_sim_limit_int($post['edits'] ?? $request['edits'] ?? $defaults['edits'], $defaults['edits'], 0, 500),
        'topic_actions' => corebb_forum_sim_limit_int($post['topic_actions'] ?? $request['topic_actions'] ?? $defaults['topic_actions'], $defaults['topic_actions'], 0, 250),
    ];

    $messages = [];
    $errors = [];
    $maxPosts = 5000;
    $maxVotes = 10000;
    $plannedPosts = $settings['topics'] * (1 + $settings['replies']);
    $plannedPolls = min($settings['poll_topics'], $settings['topics']);
    $plannedVotes = $plannedPolls * min($settings['poll_votes'], $settings['users'] + $settings['mods']);
    $eligibleBoards = corebb_forum_sim_available_boards();
    $targetBoards = corebb_forum_sim_filter_target_boards($eligibleBoards, (int)$settings['target_board']);

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $action = (string)($post['action'] ?? '');
        if ($action === 'wipe_forum_sim') {
            if (strtoupper(trim((string)($post['wipe_confirm'] ?? ''))) !== 'WIPE SIM') {
                $errors[] = 'Type WIPE SIM to remove generated simulator rows.';
            } else {
                $result = corebb_forum_sim_wipe();
                $messages = $result['messages'] ?? [];
                $errors = $result['errors'] ?? [];
            }
        } elseif ($action !== 'run_forum_sim') {
            $errors[] = 'Unknown simulator action.';
        } elseif ((int)$settings['target_board'] > 0 && !$targetBoards) {
            $errors[] = 'The selected target board is not public/non-archive eligible for simulation.';
        } elseif (strtoupper(trim((string)($post['confirm'] ?? ''))) !== 'SIMULATE') {
            $errors[] = 'Type SIMULATE to confirm real database writes.';
        } elseif ($plannedPosts > $maxPosts) {
            $errors[] = 'Requested workload would create ' . number_format($plannedPosts) . ' posts. Reduce topics/replies below ' . number_format($maxPosts) . ' total posts.';
        } elseif ($plannedVotes > $maxVotes) {
            $errors[] = 'Requested workload would create ' . number_format($plannedVotes) . ' poll votes. Reduce poll threads/votes below ' . number_format($maxVotes) . ' total votes.';
        } else {
            $result = corebb_forum_sim_run($viewer, $settings);
            $messages = $result['messages'] ?? [];
            $errors = $result['errors'] ?? [];
        }
    }

    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'settings' => $settings,
        'planned_posts' => $plannedPosts,
        'planned_polls' => $plannedPolls,
        'planned_votes' => $plannedVotes,
        'max_posts' => $maxPosts,
        'max_votes' => $maxVotes,
        'eligible_boards' => $eligibleBoards,
        'target_boards' => $targetBoards,
        'messages' => $messages,
        'errors' => $errors,
    ];
}
