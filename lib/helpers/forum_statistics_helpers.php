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
 |  forum_statistics_helpers.php - Board statistics      |
 |  event tracking and reporting helpers.                |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/performance_helpers.php';

/**
 * Usage: Return event types accepted by the statistics tracker.
 * Referenced by: record and reporting helpers.
 *
 * @return array<int, string> Known forum statistic event types.
 */
function corebb_forum_stats_event_types(): array
{
    return [
        'board_visit',
        'topic_view',
        'topic_created',
        'reply_created',
        'poll_vote',
        'favorite_added',
    ];
}

/**
 * Usage: Ensure the forum statistics event table exists.
 * Referenced by: public tracking hooks and admin stats reports.
 *
 * @return void
 */
function corebb_forum_stats_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db_run("CREATE TABLE IF NOT EXISTS `forum_stat_events` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `boardid` INT(11) NOT NULL DEFAULT 0,
        `topicid` INT(11) NOT NULL DEFAULT 0,
        `postid` INT(11) NOT NULL DEFAULT 0,
        `userid` INT(11) NOT NULL DEFAULT 0,
        `event_type` VARCHAR(32) NOT NULL DEFAULT '',
        `occurred_at` INT(11) NOT NULL DEFAULT 0,
        `occurred_date` CHAR(10) NOT NULL DEFAULT '',
        `ip_hash` CHAR(64) NOT NULL DEFAULT '',
        `user_agent_hash` CHAR(64) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `idx_forum_stat_board_event_date` (`boardid`, `event_type`, `occurred_date`),
        KEY `idx_forum_stat_event_date` (`event_type`, `occurred_date`),
        KEY `idx_forum_stat_board_time` (`boardid`, `occurred_at`),
        KEY `idx_forum_stat_topic_event` (`topicid`, `event_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

/**
 * Usage: Hash request metadata so reports never store raw visitor identifiers.
 * Referenced by: corebb_forum_stats_record_event().
 *
 * @param string $value Request value to hash.
 * @return string SHA-256 hash or an empty string.
 */
function corebb_forum_stats_hash_value(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $salt = defined('COREBB_STATS_HASH_SALT')
        ? (string)COREBB_STATS_HASH_SALT
        : (string)($GLOBALS['BoardURL'] ?? $GLOBALS['SiteURL'] ?? 'corebb');
    return hash('sha256', $salt . '|' . $value);
}

/**
 * Usage: Record one board-scoped statistics event.
 * Referenced by: board/thread views, post writes, favorites, and poll votes.
 *
 * @param int $boardId Forum board id.
 * @param string $eventType One of corebb_forum_stats_event_types().
 * @param array<string, mixed> $context Optional topic/post/user context.
 * @return bool True when the event was stored or safely skipped.
 */
function corebb_forum_stats_record_event(int $boardId, string $eventType, array $context = []): bool
{
    if ($boardId <= 0 || !in_array($eventType, corebb_forum_stats_event_types(), true)) {
        return false;
    }

    corebb_forum_stats_ensure_schema();
    if (!corebb_perf_table_exists('forum_stat_events')) {
        return false;
    }

    $now = time();
    $userId = (int)($context['userid'] ?? 0);
    if ($userId <= 0 && function_exists('corebb_load_logged_in_user') && corebb_load_logged_in_user()) {
        global $userlogindata_a;
        $userId = (int)($userlogindata_a['id'] ?? 0);
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return db_run(
        'INSERT INTO forum_stat_events (boardid, topicid, postid, userid, event_type, occurred_at, occurred_date, ip_hash, user_agent_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $boardId,
            (int)($context['topicid'] ?? 0),
            (int)($context['postid'] ?? 0),
            $userId,
            $eventType,
            $now,
            date('Y-m-d', $now),
            corebb_forum_stats_hash_value($ip),
            corebb_forum_stats_hash_value($userAgent),
        ]
    );
}

/**
 * Usage: Labels for event metrics in admin reports.
 * Referenced by: admin forum statistics model.
 *
 * @return array<string, string> Event labels keyed by event type.
 */
function corebb_forum_stats_event_labels(): array
{
    return [
        'board_visit' => 'Board Visits',
        'topic_view' => 'Topic Views',
        'topic_created' => 'New Topics',
        'reply_created' => 'Replies',
        'poll_vote' => 'Poll Votes',
        'favorite_added' => 'Favorites',
    ];
}

/**
 * Usage: Event types counted as engagement in rollups.
 * Referenced by: admin forum statistics model.
 *
 * @return array<int, string> Engagement event types.
 */
function corebb_forum_stats_engagement_event_types(): array
{
    return ['topic_created', 'reply_created', 'poll_vote', 'favorite_added'];
}
