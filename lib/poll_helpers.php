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
 |  poll_helpers.php  - Poll helpers for                 |
 |  thread-attached polls.                               |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/private_board_helpers.php';

/**
 * Check whether poll tables are already available for read paths.
 *
 * Usage: avoid creating tables during ordinary board/thread page loads.
 * Referenced by: poll read helpers and board topic flag loading.
 *
 * @return bool True when all poll tables exist.
 */
function corebb_poll_schema_ready(): bool
{
    if (array_key_exists('corebb_poll_schema_ready', $GLOBALS)) {
        return (bool)$GLOBALS['corebb_poll_schema_ready'];
    }
    // Read paths should not create tables on every board/thread page load.
    // Poll tables are created when the first poll is posted.
    $GLOBALS['corebb_poll_schema_ready'] = db_exists("SHOW TABLES LIKE 'polls'")
        && db_exists("SHOW TABLES LIKE 'poll_options'")
        && db_exists("SHOW TABLES LIKE 'poll_votes'");
    return (bool)$GLOBALS['corebb_poll_schema_ready'];
}

/**
 * Ensure poll tables exist before creating or writing polls.
 *
 * Usage: call from new-topic/admin poll creation and vote writes before the
 * first poll exists.
 * Referenced by: post workflow, admin forum simulator, and vote helper.
 *
 * @return void
 */
function corebb_poll_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db_run("CREATE TABLE IF NOT EXISTS `polls` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `topicid` INT(11) NOT NULL DEFAULT 0,
        `question` VARCHAR(255) NOT NULL DEFAULT '',
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `created_at` INT(11) NOT NULL DEFAULT 0,
        `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_polls_topicid` (`topicid`),
        KEY `idx_polls_created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_run("CREATE TABLE IF NOT EXISTS `poll_options` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `pollid` INT(11) NOT NULL DEFAULT 0,
        `option_text` VARCHAR(255) NOT NULL DEFAULT '',
        `position` INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_poll_options_poll_position` (`pollid`, `position`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_run("CREATE TABLE IF NOT EXISTS `poll_votes` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `pollid` INT(11) NOT NULL DEFAULT 0,
        `optionid` INT(11) NOT NULL DEFAULT 0,
        `userid` INT(11) NOT NULL DEFAULT 0,
        `voted_at` INT(11) NOT NULL DEFAULT 0,
        `ip_address` VARCHAR(64) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_poll_votes_user` (`pollid`, `userid`),
        KEY `idx_poll_votes_option` (`optionid`),
        KEY `idx_poll_votes_poll` (`pollid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
    // Reset read-side cache after schema creation.
    corebb_poll_schema_ready_reset();
}


/**
 * Clear the cached poll schema-readiness flag.
 *
 * Usage: force read helpers to re-check table availability after schema setup.
 * Referenced by: corebb_poll_ensure_schema().
 *
 * @return void
 */
function corebb_poll_schema_ready_reset(): void
{
    unset($GLOBALS['corebb_poll_schema_ready']);
}

/**
 * Trim poll text to a byte limit.
 *
 * Usage: fit poll questions and option labels into legacy varchar columns.
 * Referenced by: poll payload and create helpers.
 *
 * @param string $value Raw text.
 * @param int $maxBytes Maximum bytes to keep.
 * @return string Trimmed text.
 */
function corebb_poll_limit_text(string $value, int $maxBytes): string
{
    $value = trim($value);
    if ($maxBytes <= 0) {
        return '';
    }
    if (function_exists('mb_strcut')) {
        return mb_strcut($value, 0, $maxBytes, 'UTF-8');
    }
    return substr($value, 0, $maxBytes);
}

/**
 * Normalize submitted poll option labels.
 *
 * Usage: remove blank options, trim labels, and cap polls at ten options.
 * Referenced by: payload parsing and poll creation.
 *
 * @param array<int, mixed> $rawOptions Submitted option values.
 * @return array<int, string> Clean option labels.
 */
function corebb_poll_normalize_options(array $rawOptions): array
{
    $options = [];
    foreach ($rawOptions as $raw) {
        $option = corebb_poll_limit_text((string)$raw, 255);
        if ($option !== '') {
            $options[] = $option;
        }
        if (count($options) >= 10) {
            break;
        }
    }
    return $options;
}

/**
 * Build a poll creation payload from a post form submission.
 *
 * Usage: validate new-topic poll fields before the topic transaction creates
 * the poll.
 * Referenced by: post_view_model.php new-topic processing.
 *
 * @param array<string, mixed> $post Submitted POST data.
 * @return array{enabled: bool, question: string, options: array<int, string>, error: string} Poll payload.
 */
function corebb_poll_payload_from_post(array $post): array
{
    $question = corebb_poll_limit_text((string)($post['poll_question'] ?? ''), 255);
    $rawOptions = $post['poll_options'] ?? [];
    if (!is_array($rawOptions)) {
        $rawOptions = [];
    }
    $options = corebb_poll_normalize_options($rawOptions);

    $explicitCreate = (string)($post['create_poll'] ?? '') !== '';
    $hasPollContent = $question !== '' || count($options) > 0;
    $enabled = $explicitCreate || $hasPollContent;

    if (!$enabled) {
        return ['enabled' => false, 'question' => '', 'options' => [], 'error' => ''];
    }
    if ($question === '') {
        return ['enabled' => true, 'question' => '', 'options' => $options, 'error' => 'Please enter a poll question.'];
    }
    if (count($options) < 2) {
        return ['enabled' => true, 'question' => $question, 'options' => $options, 'error' => 'Please enter at least two poll choices.'];
    }

    return ['enabled' => true, 'question' => $question, 'options' => $options, 'error' => ''];
}

/**
 * Create a poll and its options for one topic.
 *
 * Usage: attach a poll after a new topic is successfully created.
 * Referenced by: post_view_model.php and admin forum simulator.
 *
 * @param int $topicId Topic id that owns the poll.
 * @param int $userId User id creating the poll.
 * @param string $question Poll question.
 * @param array<int, string> $options Poll option labels.
 * @return array<string, mixed> Creation result with pollid on success.
 */
function corebb_poll_create_for_topic(int $topicId, int $userId, string $question, array $options): array
{
    corebb_poll_ensure_schema();
    $topicId = max(0, $topicId);
    $userId = max(0, $userId);
    $question = corebb_poll_limit_text($question, 255);
    $options = corebb_poll_normalize_options($options);

    if ($topicId <= 0 || $userId <= 0 || $question === '' || count($options) < 2) {
        return ['ok' => false, 'message' => 'Invalid poll information.'];
    }

    if (!db_run(
        'INSERT INTO polls (topicid, question, created_by, created_at, is_closed) VALUES (?, ?, ?, ?, 0)',
        [$topicId, $question, $userId, time()]
    )) {
        return ['ok' => false, 'message' => 'Error creating poll: ' . db_error()];
    }

    $pollId = (int)db_insert_id();
    if ($pollId <= 0) {
        return ['ok' => false, 'message' => 'Error creating poll: missing poll ID.'];
    }

    $position = 1;
    foreach ($options as $option) {
        if (!db_run(
            'INSERT INTO poll_options (pollid, option_text, position) VALUES (?, ?, ?)',
            [$pollId, $option, $position]
        )) {
            return ['ok' => false, 'message' => 'Error creating poll choices: ' . db_error()];
        }
        $position++;
    }

    return ['ok' => true, 'pollid' => $pollId];
}

/**
 * Return topic ids that have attached polls.
 *
 * Usage: mark poll topics on board listings without loading full poll models.
 * Referenced by: board_view_model.php.
 *
 * @param array<int, mixed> $topicIds Topic ids to check.
 * @return array<int, bool> Map of topic id to true when a poll exists.
 */
function corebb_poll_topic_flags(array $topicIds): array
{
    if (!corebb_poll_schema_ready()) {
        return [];
    }
    $topicIds = array_values(array_unique(array_filter(array_map('intval', $topicIds), static fn($id) => $id > 0)));
    if (!$topicIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($topicIds), '?'));
    $rows = db_all("SELECT topicid FROM polls WHERE topicid IN ({$placeholders})", $topicIds);
    $flags = [];
    foreach ($rows as $row) {
        $flags[(int)($row['topicid'] ?? 0)] = true;
    }
    return $flags;
}

/**
 * Resolve the client IP stored with a poll vote.
 *
 * Usage: keep vote audit metadata consistent with other security-aware helpers.
 * Referenced by: corebb_poll_cast_vote().
 *
 * @return string Client IP capped to the poll_votes column length.
 */
function corebb_poll_current_ip(): string
{
    $ip = function_exists('corebb_security_client_ip')
        ? corebb_security_client_ip()
        : trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return substr($ip, 0, 64);
}

/**
 * Fetch the poll attached to one topic.
 *
 * Usage: build the thread poll model and validate vote submissions.
 * Referenced by: thread model and vote helper.
 *
 * @param int $topicId Topic id to inspect.
 * @return array<string, mixed>|false Poll row or false.
 */
function corebb_poll_fetch_thread_poll(int $topicId): array|false
{
    if (!corebb_poll_schema_ready()) {
        return false;
    }
    if ($topicId <= 0) {
        return false;
    }
    return db_one('SELECT * FROM polls WHERE topicid = ? LIMIT 1', [$topicId]);
}

/**
 * Fetch poll options with vote totals.
 *
 * Usage: render thread poll results and percentage bars.
 * Referenced by: corebb_poll_thread_model().
 *
 * @param int $pollId Poll id to load.
 * @return array<int, array<string, mixed>> Option rows with vote counts.
 */
function corebb_poll_fetch_options_with_votes(int $pollId): array
{
    if (!corebb_poll_schema_ready()) {
        return [];
    }
    if ($pollId <= 0) {
        return [];
    }
    return db_all(
        'SELECT o.id, o.pollid, o.option_text, o.position, COUNT(v.id) AS votes
         FROM poll_options o
         LEFT JOIN poll_votes v ON v.optionid = o.id
         WHERE o.pollid = ?
         GROUP BY o.id, o.pollid, o.option_text, o.position
         ORDER BY o.position ASC, o.id ASC',
        [$pollId]
    );
}

/**
 * Fetch a user's vote for one poll.
 *
 * Usage: prevent duplicate votes and highlight the user's selected option.
 * Referenced by: thread model and vote helper.
 *
 * @param int $pollId Poll id to inspect.
 * @param int $userId User id to inspect.
 * @return array<string, mixed>|false Vote row or false.
 */
function corebb_poll_user_vote(int $pollId, int $userId): array|false
{
    if (!corebb_poll_schema_ready()) {
        return false;
    }
    if ($pollId <= 0 || $userId <= 0) {
        return false;
    }
    return db_one('SELECT * FROM poll_votes WHERE pollid = ? AND userid = ? LIMIT 1', [$pollId, $userId]);
}

/**
 * Calculate days remaining before a poll auto-closes.
 *
 * Usage: derive display and closed-state behavior from the poll creation time.
 * Referenced by: thread model and vote helper.
 *
 * @param array<string, mixed> $poll Poll row.
 * @param int $durationDays Poll duration in days.
 * @return int Days remaining, or 0 when expired.
 */
function corebb_poll_days_left(array $poll, int $durationDays = 30): int
{
    $durationDays = max(1, $durationDays);
    $createdAt = (int)($poll['created_at'] ?? 0);
    if ($createdAt <= 0) {
        return $durationDays;
    }
    $secondsLeft = ($createdAt + ($durationDays * 86400)) - time();
    if ($secondsLeft <= 0) {
        return 0;
    }
    return max(1, (int)ceil($secondsLeft / 86400));
}

/**
 * Build the thread poll view model.
 *
 * Usage: render a poll on thread pages, including current vote state and
 * computed percentages.
 * Referenced by: thread_view_model.php.
 *
 * @param int $topicId Topic id whose poll should be displayed.
 * @param int $userId Current user id, or 0 for guests.
 * @return array<string, mixed> Thread poll model.
 */
function corebb_poll_thread_model(int $topicId, int $userId = 0): array
{
    $poll = corebb_poll_fetch_thread_poll($topicId);
    if (!$poll) {
        return ['exists' => false];
    }

    $pollId = (int)($poll['id'] ?? 0);
    $daysLeft = corebb_poll_days_left($poll);
    $isClosed = (int)($poll['is_closed'] ?? 0) === 1 || $daysLeft <= 0;
    $options = corebb_poll_fetch_options_with_votes($pollId);
    $totalVotes = 0;
    foreach ($options as $option) {
        $totalVotes += (int)($option['votes'] ?? 0);
    }

    $vote = $userId > 0 ? corebb_poll_user_vote($pollId, $userId) : false;
    $votedOptionId = $vote ? (int)($vote['optionid'] ?? 0) : 0;
    foreach ($options as $idx => $option) {
        $votes = (int)($option['votes'] ?? 0);
        $options[$idx]['_votes'] = $votes;
        $options[$idx]['_percent'] = $totalVotes > 0 ? round(($votes / $totalVotes) * 100, 1) : 0.0;
        $options[$idx]['_is_user_vote'] = $votedOptionId > 0 && (int)($option['id'] ?? 0) === $votedOptionId;
    }

    return [
        'exists' => true,
        'poll' => $poll,
        'pollId' => $pollId,
        'topicId' => $topicId,
        'question' => (string)($poll['question'] ?? ''),
        'isClosed' => $isClosed,
        'daysLeft' => $daysLeft,
        'options' => $options,
        'totalVotes' => $totalVotes,
        'userId' => $userId,
        'hasVoted' => $votedOptionId > 0,
        'votedOptionId' => $votedOptionId,
    ];
}

/**
 * Cast one user's vote in a topic poll.
 *
 * Usage: public/API vote action with archive, duplicate, option, and closed-poll
 * checks.
 * Referenced by: controllers/poll.php and API v1 poll endpoint.
 *
 * @param int $topicId Topic id containing the poll.
 * @param int $optionId Selected poll option id.
 * @param int $userId Voting user id.
 * @return array{ok: bool, message: string} Vote result.
 */
function corebb_poll_cast_vote(int $topicId, int $optionId, int $userId): array
{
    corebb_poll_ensure_schema();
    if ($topicId <= 0 || $optionId <= 0 || $userId <= 0) {
        return ['ok' => false, 'message' => 'Invalid poll vote.'];
    }

    $poll = corebb_poll_fetch_thread_poll($topicId);
    if (!$poll) {
        return ['ok' => false, 'message' => 'Poll not found.'];
    }
    if (!corebb_secure_archive_user_can_modify_topic_id($topicId)) {
        return ['ok' => false, 'message' => 'Secure Archive poll voting is read-only.'];
    }
    if ((int)($poll['is_closed'] ?? 0) === 1 || corebb_poll_days_left($poll) <= 0) {
        return ['ok' => false, 'message' => 'This poll is closed.'];
    }

    $pollId = (int)($poll['id'] ?? 0);
    $optionExists = db_exists('SELECT id FROM poll_options WHERE id = ? AND pollid = ? LIMIT 1', [$optionId, $pollId]);
    if (!$optionExists) {
        return ['ok' => false, 'message' => 'Unknown poll choice.'];
    }

    if (corebb_poll_user_vote($pollId, $userId)) {
        return ['ok' => false, 'message' => 'You have already voted in this poll.'];
    }

    $ok = db_run(
        'INSERT INTO poll_votes (pollid, optionid, userid, voted_at, ip_address) VALUES (?, ?, ?, ?, ?)',
        [$pollId, $optionId, $userId, time(), corebb_poll_current_ip()]
    );
    if (!$ok) {
        // The unique key is the hard stop for double submits/races.
        if ((int)db_errno() === 1062 || str_contains(strtolower(db_error()), 'duplicate')) {
            return ['ok' => false, 'message' => 'You have already voted in this poll.'];
        }
        return ['ok' => false, 'message' => 'Error recording poll vote: ' . db_error()];
    }

    return ['ok' => true, 'message' => 'Your vote has been recorded.'];
}

/**
 * Translate poll result codes into user-facing messages.
 *
 * Usage: show a flash message after redirecting back to the thread page.
 * Referenced by: thread_view_model.php.
 *
 * @param string $code Poll message code from the query string.
 * @return string User-facing message or empty string.
 */
function corebb_poll_message_from_code(string $code): string
{
    return match ($code) {
        'voted' => 'Your vote has been recorded.',
        'already' => 'You have already voted in this poll.',
        'invalid' => 'Your poll vote could not be recorded.',
        'closed' => 'This poll is closed.',
        'archive' => 'Secure Archive poll voting is read-only.',
        default => '',
    };
}
