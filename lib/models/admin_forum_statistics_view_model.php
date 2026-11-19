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
 |  admin_forum_statistics_view_model.php - Manager      |
 |  reports for board activity statistics.               |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../helpers/forum_statistics_helpers.php';
require_once __DIR__ . '/../helpers/corebb_route_helpers.php';
require_once __DIR__ . '/../helpers/moderation_helpers.php';

/**
 * Usage: Normalize the selected reporting window.
 * Referenced by: corebb_admin_forum_statistics_model().
 *
 * @param mixed $days Requested number of days.
 * @return int Supported day window.
 */
function corebb_admin_forum_stats_days($days): int
{
    $days = (int)$days;
    if (!in_array($days, [7, 30, 90, 365], true)) {
        return 30;
    }
    return $days;
}

/**
 * Usage: Normalize the selected board id.
 * Referenced by: corebb_admin_forum_statistics_model().
 *
 * @param mixed $boardId Requested board id.
 * @return int Board id, or 0 for all boards.
 */
function corebb_admin_forum_stats_board_id($boardId): int
{
    return max(0, (int)$boardId);
}

/**
 * Usage: Convert a value into a whole-number chart percentage.
 * Referenced by: admin statistics chart builders.
 *
 * @param int $value Value being displayed.
 * @param int $max Maximum value in the chart set.
 * @return int Percentage from 0 through 100.
 */
function corebb_admin_forum_stats_percent(int $value, int $max): int
{
    if ($value <= 0 || $max <= 0) {
        return 0;
    }
    return min(100, max(1, (int)round(($value / $max) * 100)));
}

/**
 * Usage: Load board options for the report filter.
 * Referenced by: corebb_admin_forum_statistics_model().
 *
 * @return array<int, array<string, mixed>> Board filter options.
 */
function corebb_admin_forum_stats_board_options(): array
{
    $rows = db_all(
        'SELECT f.id, f.name, b.name AS category_name
           FROM forums f
           LEFT JOIN boards b ON b.id = f.categoryid
          ORDER BY b.position ASC, b.name ASC, f.position ASC, f.name ASC'
    );
    $options = [];
    foreach ($rows as $row) {
        $options[] = [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? 'Unnamed Board'),
            'category_name' => (string)($row['category_name'] ?? ''),
        ];
    }
    return $options;
}

/**
 * Usage: Build SQL filters shared by the stats aggregate queries.
 * Referenced by: admin report query helpers.
 *
 * @param int $boardId Selected board id, or 0 for all boards.
 * @param int $startTs Start timestamp.
 * @return array{where: string, params: array<int, mixed>}
 */
function corebb_admin_forum_stats_event_filter(int $boardId, int $startTs): array
{
    $where = 'occurred_at >= ?';
    $params = [$startTs];
    if ($boardId > 0) {
        $where .= ' AND boardid = ?';
        $params[] = $boardId;
    }
    return ['where' => $where, 'params' => $params];
}

/**
 * Usage: Count statistic events by type.
 * Referenced by: corebb_admin_forum_statistics_model().
 *
 * @param int $boardId Selected board id, or 0 for all boards.
 * @param int $startTs Start timestamp.
 * @return array<string, int> Counts keyed by event type.
 */
function corebb_admin_forum_stats_event_counts(int $boardId, int $startTs): array
{
    $filter = corebb_admin_forum_stats_event_filter($boardId, $startTs);
    $counts = array_fill_keys(corebb_forum_stats_event_types(), 0);
    foreach (db_all('SELECT event_type, COUNT(*) AS total FROM forum_stat_events WHERE ' . $filter['where'] . ' GROUP BY event_type', $filter['params']) as $row) {
        $eventType = (string)($row['event_type'] ?? '');
        if (array_key_exists($eventType, $counts)) {
            $counts[$eventType] = (int)($row['total'] ?? 0);
        }
    }
    return $counts;
}

/**
 * Usage: Load content totals for all boards or a selected board.
 * Referenced by: corebb_admin_forum_statistics_model().
 *
 * @param int $boardId Selected board id, or 0 for all boards.
 * @return array<string, int> Topic/post totals.
 */
function corebb_admin_forum_stats_content_totals(int $boardId): array
{
    $topicWhere = 'is_deleted = 0';
    $postWhere = 'p.is_deleted = 0 AND t.is_deleted = 0';
    $topicParams = [];
    $postParams = [];
    if ($boardId > 0) {
        $topicWhere .= ' AND boardid = ?';
        $postWhere .= ' AND p.boardid = ?';
        $topicParams[] = $boardId;
        $postParams[] = $boardId;
    }

    return [
        'topics' => (int)db_value('SELECT COUNT(*) FROM topics WHERE ' . $topicWhere, $topicParams, 0),
        'posts' => (int)db_value('SELECT COUNT(*) FROM posts p INNER JOIN topics t ON t.id = p.threadid WHERE ' . $postWhere, $postParams, 0),
    ];
}

/**
 * Usage: Build daily activity trend rows.
 * Referenced by: corebb_admin_forum_statistics_model().
 *
 * @param int $boardId Selected board id, or 0 for all boards.
 * @param int $days Number of days in the report window.
 * @param int $startTs Start timestamp.
 * @return array<int, array<string, mixed>> Trend rows oldest first.
 */
function corebb_admin_forum_stats_daily_rows(int $boardId, int $days, int $startTs): array
{
    $filter = corebb_admin_forum_stats_event_filter($boardId, $startTs);
    $raw = [];
    foreach (db_all('SELECT occurred_date, event_type, COUNT(*) AS total FROM forum_stat_events WHERE ' . $filter['where'] . ' GROUP BY occurred_date, event_type ORDER BY occurred_date ASC', $filter['params']) as $row) {
        $date = (string)($row['occurred_date'] ?? '');
        $eventType = (string)($row['event_type'] ?? '');
        if ($date === '' || !in_array($eventType, corebb_forum_stats_event_types(), true)) {
            continue;
        }
        $raw[$date][$eventType] = (int)($row['total'] ?? 0);
    }

    $rows = [];
    $today = strtotime(date('Y-m-d'));
    $engagementTypes = corebb_forum_stats_engagement_event_types();
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', (int)$today - ($i * 86400));
        $events = $raw[$date] ?? [];
        $visits = (int)($events['board_visit'] ?? 0) + (int)($events['topic_view'] ?? 0);
        $engagements = 0;
        foreach ($engagementTypes as $type) {
            $engagements += (int)($events[$type] ?? 0);
        }
        $rows[] = [
            'date' => $date,
            'visits' => $visits,
            'board_visits' => (int)($events['board_visit'] ?? 0),
            'topic_views' => (int)($events['topic_view'] ?? 0),
            'topics' => (int)($events['topic_created'] ?? 0),
            'replies' => (int)($events['reply_created'] ?? 0),
            'engagements' => $engagements,
        ];
    }

    $maxVisits = 0;
    $maxEngagements = 0;
    foreach ($rows as $row) {
        $maxVisits = max($maxVisits, (int)($row['visits'] ?? 0));
        $maxEngagements = max($maxEngagements, (int)($row['engagements'] ?? 0));
    }
    foreach ($rows as $index => $row) {
        $rows[$index]['visit_width'] = corebb_admin_forum_stats_percent((int)($row['visits'] ?? 0), $maxVisits);
        $rows[$index]['engagement_width'] = corebb_admin_forum_stats_percent((int)($row['engagements'] ?? 0), $maxEngagements);
    }
    return $rows;
}

/**
 * Usage: Build per-board activity rows for the report table.
 * Referenced by: corebb_admin_forum_statistics_model().
 *
 * @param int $startTs Start timestamp.
 * @param int $selectedBoardId Selected board id, or 0 for all boards.
 * @return array<int, array<string, mixed>> Per-board rows.
 */
function corebb_admin_forum_stats_board_rows(int $startTs, int $selectedBoardId = 0): array
{
    $boardWhere = '';
    $params = [$startTs];
    if ($selectedBoardId > 0) {
        $boardWhere = 'WHERE f.id = ?';
        $params[] = $selectedBoardId;
    }

    $rows = db_all(
        "SELECT f.id, f.name,
                COALESCE(tc.topic_total, 0) AS topiccount,
                COALESCE(pc.post_total, 0) AS postcount,
                COALESCE(SUM(CASE WHEN e.event_type = 'board_visit' THEN 1 ELSE 0 END), 0) AS board_visits,
                COALESCE(SUM(CASE WHEN e.event_type = 'topic_view' THEN 1 ELSE 0 END), 0) AS topic_views,
                COALESCE(SUM(CASE WHEN e.event_type = 'topic_created' THEN 1 ELSE 0 END), 0) AS topics_created,
                COALESCE(SUM(CASE WHEN e.event_type = 'reply_created' THEN 1 ELSE 0 END), 0) AS replies_created,
                COALESCE(SUM(CASE WHEN e.event_type = 'poll_vote' THEN 1 ELSE 0 END), 0) AS poll_votes,
                COALESCE(SUM(CASE WHEN e.event_type = 'favorite_added' THEN 1 ELSE 0 END), 0) AS favorites_added,
                COUNT(e.id) AS total_activity
           FROM forums f
           LEFT JOIN (
                SELECT boardid, COUNT(*) AS topic_total
                  FROM topics
                 WHERE is_deleted = 0
                 GROUP BY boardid
           ) tc ON tc.boardid = f.id
           LEFT JOIN (
                SELECT p.boardid, COUNT(*) AS post_total
                  FROM posts p
                  INNER JOIN topics t ON t.id = p.threadid AND t.is_deleted = 0
                 WHERE p.is_deleted = 0
                 GROUP BY p.boardid
           ) pc ON pc.boardid = f.id
           LEFT JOIN forum_stat_events e ON e.boardid = f.id AND e.occurred_at >= ?
           {$boardWhere}
          GROUP BY f.id, f.name, tc.topic_total, pc.post_total
          ORDER BY total_activity DESC, f.name ASC",
        $params
    );

    $out = [];
    foreach ($rows as $row) {
        $engagements = (int)($row['topics_created'] ?? 0)
            + (int)($row['replies_created'] ?? 0)
            + (int)($row['poll_votes'] ?? 0)
            + (int)($row['favorites_added'] ?? 0);
        $out[] = [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? 'Unnamed Board'),
            'url' => '/admin/?act=forum_statistics&boardid=' . (int)($row['id'] ?? 0),
            'public_url' => corebb_board_url((int)($row['id'] ?? 0), 1, (string)($row['name'] ?? 'Board')),
            'topiccount' => (int)($row['topiccount'] ?? 0),
            'postcount' => (int)($row['postcount'] ?? 0),
            'board_visits' => (int)($row['board_visits'] ?? 0),
            'topic_views' => (int)($row['topic_views'] ?? 0),
            'visits' => (int)($row['board_visits'] ?? 0) + (int)($row['topic_views'] ?? 0),
            'topics_created' => (int)($row['topics_created'] ?? 0),
            'replies_created' => (int)($row['replies_created'] ?? 0),
            'poll_votes' => (int)($row['poll_votes'] ?? 0),
            'favorites_added' => (int)($row['favorites_added'] ?? 0),
            'engagements' => $engagements,
        ];
    }

    $maxVisits = 0;
    $maxEngagements = 0;
    foreach ($out as $row) {
        $maxVisits = max($maxVisits, (int)($row['visits'] ?? 0));
        $maxEngagements = max($maxEngagements, (int)($row['engagements'] ?? 0));
    }
    foreach ($out as $index => $row) {
        $out[$index]['visit_width'] = corebb_admin_forum_stats_percent((int)($row['visits'] ?? 0), $maxVisits);
        $out[$index]['engagement_width'] = corebb_admin_forum_stats_percent((int)($row['engagements'] ?? 0), $maxEngagements);
    }
    return $out;
}

/**
 * Usage: Build top-level metric bars for the statistics report.
 * Referenced by: corebb_admin_forum_statistics_model().
 *
 * @param array<string, int> $summary Summary totals.
 * @return array<int, array<string, mixed>> Chart rows.
 */
function corebb_admin_forum_stats_metric_chart(array $summary): array
{
    $metrics = [
        ['label' => 'Board Visits', 'value' => (int)($summary['board_visits'] ?? 0), 'class' => 'is-one'],
        ['label' => 'Topic Views', 'value' => (int)($summary['topic_views'] ?? 0), 'class' => 'is-two'],
        ['label' => 'New Topics', 'value' => (int)($summary['topics_created'] ?? 0), 'class' => 'is-three'],
        ['label' => 'Replies', 'value' => (int)($summary['replies_created'] ?? 0), 'class' => 'is-four'],
        ['label' => 'Poll Votes', 'value' => (int)($summary['poll_votes'] ?? 0), 'class' => 'is-five'],
        ['label' => 'Favorites', 'value' => (int)($summary['favorites_added'] ?? 0), 'class' => 'is-six'],
    ];
    $max = 0;
    $total = 0;
    foreach ($metrics as $metric) {
        $max = max($max, (int)$metric['value']);
        $total += (int)$metric['value'];
    }
    $offset = 0.0;
    foreach ($metrics as $index => $metric) {
        $value = (int)$metric['value'];
        $percent = $total > 0 ? round(($value / $total) * 100, 2) : 0.0;
        $metrics[$index]['width'] = corebb_admin_forum_stats_percent($value, $max);
        $metrics[$index]['percent'] = $percent;
        $metrics[$index]['offset'] = 0 - $offset;
        $offset += $percent;
    }
    return ['total' => $total, 'segments' => $metrics];
}

/**
 * Usage: Build SVG paths for the traffic/engagement trend chart.
 * Referenced by: corebb_admin_forum_statistics_model().
 *
 * @param array<int, array<string, mixed>> $dailyRows Daily report rows.
 * @return array<string, mixed> SVG chart data.
 */
function corebb_admin_forum_stats_trend_chart(array $dailyRows): array
{
    $width = 680;
    $height = 240;
    $left = 44;
    $right = 18;
    $top = 18;
    $bottom = 34;
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;
    $count = count($dailyRows);
    $maxValue = 0;
    foreach ($dailyRows as $row) {
        $maxValue = max($maxValue, (int)($row['visits'] ?? 0), (int)($row['engagements'] ?? 0));
    }
    $maxValue = max(1, $maxValue);

    $series = [
        'visits' => [],
        'engagements' => [],
    ];
    $labels = [];
    $labelEvery = max(1, (int)ceil(max(1, $count) / 6));
    foreach ($dailyRows as $index => $row) {
        $x = $left + ($count <= 1 ? ($plotWidth / 2) : (($plotWidth / max(1, $count - 1)) * $index));
        foreach (['visits', 'engagements'] as $key) {
            $value = (int)($row[$key] ?? 0);
            $y = $top + $plotHeight - (($value / $maxValue) * $plotHeight);
            $series[$key][] = [
                'x' => round($x, 2),
                'y' => round($y, 2),
                'value' => $value,
            ];
        }
        if ($index === 0 || $index === $count - 1 || $index % $labelEvery === 0) {
            $labels[] = [
                'x' => round($x, 2),
                'label' => substr((string)($row['date'] ?? ''), 5, 5),
            ];
        }
    }

    $baseline = $top + $plotHeight;
    $makePoints = static function (array $points): string {
        return trim(implode(' ', array_map(static fn($point) => $point['x'] . ',' . $point['y'], $points)));
    };
    $makeArea = static function (array $points) use ($baseline): string {
        if (!$points) {
            return '';
        }
        $first = $points[0];
        $last = $points[count($points) - 1];
        $line = implode(' L ', array_map(static fn($point) => $point['x'] . ' ' . $point['y'], $points));
        return 'M ' . $first['x'] . ' ' . $baseline . ' L ' . $line . ' L ' . $last['x'] . ' ' . $baseline . ' Z';
    };

    return [
        'width' => $width,
        'height' => $height,
        'plot_top' => $top,
        'plot_bottom' => $baseline,
        'plot_left' => $left,
        'plot_right' => $left + $plotWidth,
        'max_value' => $maxValue,
        'half_value' => (int)round($maxValue / 2),
        'visits_points' => $makePoints($series['visits']),
        'engagements_points' => $makePoints($series['engagements']),
        'visits_area' => $makeArea($series['visits']),
        'engagements_area' => $makeArea($series['engagements']),
        'visit_markers' => array_slice($series['visits'], -8),
        'engagement_markers' => array_slice($series['engagements'], -8),
        'labels' => $labels,
    ];
}

/**
 * Usage: Build the manager-facing forum statistics report model.
 * Referenced by: admin route act=forum_statistics.
 *
 * @param array<string, mixed> $viewer Current admin/manager user row.
 * @param array<string, mixed> $get Query parameters.
 * @return array<string, mixed> Twig-ready model.
 */
function corebb_admin_forum_statistics_model(array $viewer, array $get): array
{
    corebb_mod_ensure_schema();
    corebb_forum_stats_ensure_schema();

    $days = corebb_admin_forum_stats_days($get['days'] ?? 30);
    $boardId = corebb_admin_forum_stats_board_id($get['boardid'] ?? 0);
    $startTs = strtotime('-' . ($days - 1) . ' days 00:00:00');
    if ($startTs === false) {
        $startTs = time() - (($days - 1) * 86400);
    }

    $eventCounts = corebb_admin_forum_stats_event_counts($boardId, $startTs);
    $contentTotals = corebb_admin_forum_stats_content_totals($boardId);
    $engagements = 0;
    foreach (corebb_forum_stats_engagement_event_types() as $type) {
        $engagements += (int)($eventCounts[$type] ?? 0);
    }
    $visits = (int)($eventCounts['board_visit'] ?? 0) + (int)($eventCounts['topic_view'] ?? 0);

    $summary = [
        'visits' => $visits,
        'board_visits' => (int)($eventCounts['board_visit'] ?? 0),
        'topic_views' => (int)($eventCounts['topic_view'] ?? 0),
        'topics' => (int)$contentTotals['topics'],
        'posts' => (int)$contentTotals['posts'],
        'topics_created' => (int)($eventCounts['topic_created'] ?? 0),
        'replies_created' => (int)($eventCounts['reply_created'] ?? 0),
        'poll_votes' => (int)($eventCounts['poll_vote'] ?? 0),
        'favorites_added' => (int)($eventCounts['favorite_added'] ?? 0),
        'engagements' => $engagements,
    ];
    $dailyRows = corebb_admin_forum_stats_daily_rows($boardId, $days, $startTs);
    $boardRows = corebb_admin_forum_stats_board_rows($startTs, $boardId);

    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'days' => $days,
        'day_options' => [7, 30, 90, 365],
        'boardid' => $boardId,
        'board_options' => corebb_admin_forum_stats_board_options(),
        'event_labels' => corebb_forum_stats_event_labels(),
        'summary' => $summary,
        'metric_chart' => corebb_admin_forum_stats_metric_chart($summary),
        'trend_chart' => corebb_admin_forum_stats_trend_chart($dailyRows),
        'daily_rows' => $dailyRows,
        'board_rows' => $boardRows,
        'top_board_rows' => array_slice($boardRows, 0, 10),
    ];
}
