<?php
require_once __DIR__ . '/corebb_date_helpers.php';
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
 |  notification_helpers.php  - Lightweight user         |
 |  notification helpers for CoreBB.                     |
 +-------------------------------------------------------+*/

if (!defined('COREBB_NOTIFICATION_HELPERS_LOADED')) {
    define('COREBB_NOTIFICATION_HELPERS_LOADED', true);
}

require_once __DIR__ . '/pagination_helpers.php';
require_once __DIR__ . '/corebb_route_helpers.php';
require_once __DIR__ . '/private_board_helpers.php';

/**
 * Usage: Validate and quote a database identifier used by notification helpers.
 * Referenced by: table and column existence checks.
 *
 * @param string $identifier Raw table or column identifier.
 * @return string Backtick-quoted identifier.
 * @throws InvalidArgumentException When the identifier contains unsafe characters.
 */
function corebb_notifications_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Usage: Check whether a notification table exists before optional reads.
 * Referenced by: corebb_notifications_uncleared_count().
 *
 * @param string $table Table name to inspect.
 * @return bool True when the table exists in the active database.
 */
function corebb_notifications_table_exists(string $table): bool
{
    corebb_notifications_identifier($table);
    $schema = (string)db_value('SELECT DATABASE()', [], '');
    if ($schema !== '') {
        return db_exists(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
            [$schema, $table]
        );
    }
    return db_exists('SHOW TABLES LIKE ?', [$table]);
}

/**
 * Usage: Check whether a compatibility column exists before ALTER TABLE or conditional SQL.
 * Referenced by: schema setup and user/archive filters.
 *
 * @param string $table Table name to inspect.
 * @param string $column Column name to inspect.
 * @return bool True when the column exists.
 */
function corebb_notifications_column_exists(string $table, string $column): bool
{
    $tableSafe = corebb_notifications_identifier($table);
    return db_exists("SHOW COLUMNS FROM {$tableSafe} LIKE ?", [$column]);
}

/**
 * Usage: Ensure notification tables and compatibility columns exist.
 * Referenced by: all notification read/write helpers and the notification center model.
 *
 * @return void
 */
function corebb_notifications_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db_run("CREATE TABLE IF NOT EXISTS `user_notifications` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `user_id` INT(11) NOT NULL DEFAULT 0,
        `actor_user_id` INT(11) NOT NULL DEFAULT 0,
        `notification_type` VARCHAR(40) NOT NULL DEFAULT 'general',
        `title` VARCHAR(160) NOT NULL DEFAULT '',
        `body` TEXT NULL,
        `target_url` VARCHAR(255) NOT NULL DEFAULT '',
        `subject_type` VARCHAR(40) NOT NULL DEFAULT '',
        `subject_id` INT(11) NOT NULL DEFAULT 0,
        `event_count` INT(11) NOT NULL DEFAULT 1,
        `created_at` VARCHAR(64) NOT NULL DEFAULT '',
        `read_at` VARCHAR(64) NOT NULL DEFAULT '',
        `cleared_at` VARCHAR(64) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `idx_notifications_user_clear` (`user_id`, `cleared_at`, `id`),
        KEY `idx_notifications_user_created` (`user_id`, `created_at`),
        KEY `idx_notifications_type` (`notification_type`),
        KEY `idx_notifications_subject` (`user_id`, `notification_type`, `subject_type`, `subject_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [
        'user_id' => "ALTER TABLE `user_notifications` ADD `user_id` INT(11) NOT NULL DEFAULT 0",
        'actor_user_id' => "ALTER TABLE `user_notifications` ADD `actor_user_id` INT(11) NOT NULL DEFAULT 0",
        'notification_type' => "ALTER TABLE `user_notifications` ADD `notification_type` VARCHAR(40) NOT NULL DEFAULT 'general'",
        'title' => "ALTER TABLE `user_notifications` ADD `title` VARCHAR(160) NOT NULL DEFAULT ''",
        'body' => "ALTER TABLE `user_notifications` ADD `body` TEXT NULL",
        'target_url' => "ALTER TABLE `user_notifications` ADD `target_url` VARCHAR(255) NOT NULL DEFAULT ''",
        'subject_type' => "ALTER TABLE `user_notifications` ADD `subject_type` VARCHAR(40) NOT NULL DEFAULT ''",
        'subject_id' => "ALTER TABLE `user_notifications` ADD `subject_id` INT(11) NOT NULL DEFAULT 0",
        'event_count' => "ALTER TABLE `user_notifications` ADD `event_count` INT(11) NOT NULL DEFAULT 1",
        'created_at' => "ALTER TABLE `user_notifications` ADD `created_at` VARCHAR(64) NOT NULL DEFAULT ''",
        'read_at' => "ALTER TABLE `user_notifications` ADD `read_at` VARCHAR(64) NOT NULL DEFAULT ''",
        'cleared_at' => "ALTER TABLE `user_notifications` ADD `cleared_at` VARCHAR(64) NOT NULL DEFAULT ''",
    ];

    foreach ($columns as $column => $sql) {
        if (!corebb_notifications_column_exists('user_notifications', $column)) {
            db_run($sql);
        }
    }


    db_run("CREATE TABLE IF NOT EXISTS `user_notification_settings` (
        `user_id` INT(11) NOT NULL DEFAULT 0,
        `notifications_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `updated_at` VARCHAR(64) NOT NULL DEFAULT '',
        PRIMARY KEY (`user_id`),
        KEY `idx_notification_settings_enabled` (`notifications_enabled`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_run("CREATE TABLE IF NOT EXISTS `user_notification_mutes` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `user_id` INT(11) NOT NULL DEFAULT 0,
        `notification_type` VARCHAR(40) NOT NULL DEFAULT '',
        `subject_type` VARCHAR(40) NOT NULL DEFAULT '',
        `subject_id` INT(11) NOT NULL DEFAULT 0,
        `created_at` VARCHAR(64) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_notification_mute` (`user_id`, `notification_type`, `subject_type`, `subject_id`),
        KEY `idx_notification_mutes_user` (`user_id`, `id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

/**
 * Usage: Trim notification text to a database-safe byte length.
 * Referenced by: type/title/body/url cleaning and moderation reason handling.
 *
 * @param string $value Text to trim.
 * @param int $maxBytes Maximum byte length.
 * @return string Trimmed text without control characters.
 */
function corebb_notifications_limit_text(string $value, int $maxBytes): string
{
    $value = trim($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $value) ?? '';
    if ($maxBytes > 0 && strlen($value) > $maxBytes) {
        return function_exists('mb_strcut') ? mb_strcut($value, 0, $maxBytes, 'UTF-8') : substr($value, 0, $maxBytes);
    }
    return $value;
}

/**
 * Usage: Normalize a notification type key.
 * Referenced by: notification creation, mute, fetch, and collapse helpers.
 *
 * @param string $type Raw notification type.
 * @return string Safe notification type, defaulting to "general".
 */
function corebb_notifications_clean_type(string $type): string
{
    $type = strtolower(trim($type));
    $type = preg_replace('/[^a-z0-9_\-]/', '', $type) ?? '';
    return $type !== '' ? corebb_notifications_limit_text($type, 40) : 'general';
}

/**
 * Usage: Normalize the subject type used for notification stream grouping.
 * Referenced by: notification creation, mute, fetch, and collapse helpers.
 *
 * @param string $subjectType Raw subject type.
 * @return string Safe subject type.
 */
function corebb_notifications_clean_subject_type(string $subjectType): string
{
    $subjectType = strtolower(trim($subjectType));
    $subjectType = preg_replace('/[^a-z0-9_\-]/', '', $subjectType) ?? '';
    return corebb_notifications_limit_text($subjectType, 40);
}

/**
 * Usage: Keep notification target URLs local and database-safe.
 * Referenced by: notification creation and fetch normalization.
 *
 * @param string $url Raw notification target URL.
 * @return string Safe local URL, anchor, or an empty string.
 */
function corebb_notifications_normalize_url(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '' || preg_match('/[\r\n]/', $url)) {
        return '';
    }
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url)) {
        return '';
    }
    if ($url[0] !== '/' && $url[0] !== '#') {
        $url = '/' . ltrim($url, '/');
    }
    return corebb_notifications_limit_text($url, 255);
}

/**
 * Usage: Produce a stored notification timestamp.
 * Referenced by: notification settings, mutes, clears, and inserts.
 *
 * @return string Current timestamp in CoreBB/VN format when available.
 */
function corebb_notifications_now(): string
{
    return convert_to_timestamp_raw(time());
}

/**
 * Usage: Load per-user notification settings.
 * Referenced by: notification center and delivery checks.
 *
 * @param int $userId User id whose settings should be loaded.
 * @return array{notifications_enabled: int} Notification settings row with defaults.
 */
function corebb_notifications_settings(int $userId): array
{
    $userId = max(0, $userId);
    if ($userId <= 0) {
        return ['notifications_enabled' => 0];
    }
    corebb_notifications_ensure_schema();
    $row = db_one('SELECT notifications_enabled FROM user_notification_settings WHERE user_id = ? LIMIT 1', [$userId]);
    if (!$row) {
        return ['notifications_enabled' => 1];
    }
    return ['notifications_enabled' => (int)($row['notifications_enabled'] ?? 1) === 1 ? 1 : 0];
}

/**
 * Usage: Toggle whether new notifications are created for a user.
 * Referenced by: notification center POST actions.
 *
 * @param int $userId User id to update.
 * @param bool $enabled Whether notifications should be enabled.
 * @return bool True when the setting is stored.
 */
function corebb_notifications_set_enabled(int $userId, bool $enabled): bool
{
    $userId = max(0, $userId);
    if ($userId <= 0) {
        return false;
    }
    corebb_notifications_ensure_schema();
    return db_run(
        'INSERT INTO user_notification_settings (user_id, notifications_enabled, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE notifications_enabled = VALUES(notifications_enabled), updated_at = VALUES(updated_at)',
        [$userId, $enabled ? 1 : 0, corebb_notifications_now()]
    );
}

/**
 * Usage: Check whether a user currently accepts new notifications.
 * Referenced by: corebb_notifications_should_deliver().
 *
 * @param int $userId User id to inspect.
 * @return bool True when notification creation is enabled.
 */
function corebb_notifications_enabled(int $userId): bool
{
    $settings = corebb_notifications_settings($userId);
    return (int)($settings['notifications_enabled'] ?? 1) === 1;
}

/**
 * Usage: Check whether a user muted one notification stream.
 * Referenced by: corebb_notifications_should_deliver().
 *
 * @param int $userId User id to inspect.
 * @param string $type Notification type.
 * @param string $subjectType Subject type, such as "topic".
 * @param int $subjectId Subject id.
 * @return bool True when the stream is muted.
 */
function corebb_notifications_is_muted(int $userId, string $type, string $subjectType = '', int $subjectId = 0): bool
{
    $userId = max(0, $userId);
    $type = corebb_notifications_clean_type($type);
    $subjectType = corebb_notifications_clean_subject_type($subjectType);
    $subjectId = max(0, $subjectId);
    if ($userId <= 0 || $subjectType === '' || $subjectId <= 0) {
        return false;
    }
    corebb_notifications_ensure_schema();
    return db_exists(
        'SELECT 1 FROM user_notification_mutes WHERE user_id = ? AND notification_type = ? AND subject_type = ? AND subject_id = ? LIMIT 1',
        [$userId, $type, $subjectType, $subjectId]
    );
}

/**
 * Usage: Decide whether a notification should be created for a user.
 * Referenced by: single and bulk notification creation helpers.
 *
 * @param int $userId Target user id.
 * @param string $type Notification type.
 * @param string $subjectType Subject type used for mute lookup.
 * @param int $subjectId Subject id used for mute lookup.
 * @return bool True when settings and mutes allow delivery.
 */
function corebb_notifications_should_deliver(int $userId, string $type, string $subjectType = '', int $subjectId = 0): bool
{
    $userId = max(0, $userId);
    if ($userId <= 0) {
        return false;
    }
    if (!corebb_notifications_enabled($userId)) {
        return false;
    }
    return !corebb_notifications_is_muted($userId, $type, $subjectType, $subjectId);
}

/**
 * Usage: Determine whether a notification row can create a user mute.
 * Referenced by: notification fetch and silence actions.
 *
 * @param string $type Notification type.
 * @param string $subjectType Subject type.
 * @param int $subjectId Subject id.
 * @return bool True when the stream can be silenced.
 */
function corebb_notifications_can_silence_stream(string $type, string $subjectType = '', int $subjectId = 0): bool
{
    $type = corebb_notifications_clean_type($type);
    $subjectType = corebb_notifications_clean_subject_type($subjectType);
    $subjectId = max(0, $subjectId);
    if ($subjectType === '' || $subjectId <= 0) {
        return false;
    }
    return in_array($type, ['topic_reply', 'mention', 'mention_all'], true);
}

/**
 * Usage: Build a human-readable label for a muteable notification stream.
 * Referenced by: mute fetch, notification fetch, and silence actions.
 *
 * @param string $type Notification type.
 * @param string $subjectType Subject type.
 * @param int $subjectId Subject id.
 * @param string $topicTitle Optional topic title for stream labels.
 * @return string Display label for the notification stream.
 */
function corebb_notifications_silence_label(string $type, string $subjectType = '', int $subjectId = 0, string $topicTitle = ''): string
{
    $type = corebb_notifications_clean_type($type);
    $subjectType = corebb_notifications_clean_subject_type($subjectType);
    $topicTitle = trim($topicTitle);
    if ($subjectType === 'topic' && $subjectId > 0) {
        if ($type === 'topic_reply') {
            return $topicTitle !== '' ? 'Replies to "' . $topicTitle . '"' : 'Replies to this thread';
        }
        if ($type === 'mention' || $type === 'mention_all') {
            return $topicTitle !== '' ? 'Mentions in "' . $topicTitle . '"' : 'Mentions in this thread';
        }
    }
    return 'This notification stream';
}

/**
 * Usage: Load a user's muted notification streams for the notification center.
 * Referenced by: corebb_notifications_model().
 *
 * @param int $userId User id whose mutes should be loaded.
 * @return array<int, array<string, mixed>> Prepared mute rows.
 */
function corebb_notifications_fetch_mutes(int $userId): array
{
    $userId = max(0, $userId);
    if ($userId <= 0) {
        return [];
    }
    corebb_notifications_ensure_schema();
    $rows = db_all(
        "SELECT m.*, t.title AS topic_title
           FROM user_notification_mutes m
           LEFT JOIN topics t ON m.subject_type = 'topic' AND t.id = m.subject_id
          WHERE m.user_id = ?
          ORDER BY m.id DESC
          LIMIT 100",
        [$userId]
    );
    foreach ($rows as &$row) {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['user_id'] = (int)($row['user_id'] ?? 0);
        $row['notification_type'] = corebb_notifications_clean_type((string)($row['notification_type'] ?? ''));
        $row['subject_type'] = corebb_notifications_clean_subject_type((string)($row['subject_type'] ?? ''));
        $row['subject_id'] = (int)($row['subject_id'] ?? 0);
        $row['label'] = corebb_notifications_silence_label($row['notification_type'], $row['subject_type'], $row['subject_id'], (string)($row['topic_title'] ?? ''));
        $createdAt = (string)($row['created_at'] ?? '');
        $row['created_display'] = $createdAt !== '' ? convert_to_vndate($createdAt) : $createdAt;
    }
    unset($row);
    return $rows;
}

/**
 * Usage: Remove one muted notification stream for a user.
 * Referenced by: notification center POST actions.
 *
 * @param int $userId User id who owns the mute.
 * @param int $muteId Mute id to delete.
 * @return bool True when the delete succeeds.
 */
function corebb_notifications_unsilence(int $userId, int $muteId): bool
{
    $userId = max(0, $userId);
    $muteId = max(0, $muteId);
    if ($userId <= 0 || $muteId <= 0) {
        return false;
    }
    corebb_notifications_ensure_schema();
    return db_run('DELETE FROM user_notification_mutes WHERE id = ? AND user_id = ?', [$muteId, $userId]);
}

/**
 * Usage: Mute the stream represented by one notification row and clear matching active rows.
 * Referenced by: notification center POST actions.
 *
 * @param int $userId User id who owns the notification.
 * @param int $notificationId Notification id to silence from.
 * @return array{ok: bool, message: string} Result message for the notification center.
 */
function corebb_notifications_silence_notification(int $userId, int $notificationId): array
{
    $userId = max(0, $userId);
    $notificationId = max(0, $notificationId);
    if ($userId <= 0 || $notificationId <= 0) {
        return ['ok' => false, 'message' => 'Unknown notification.'];
    }
    corebb_notifications_ensure_schema();
    $row = db_one('SELECT * FROM user_notifications WHERE id = ? AND user_id = ? AND cleared_at = ? LIMIT 1', [$notificationId, $userId, '']);
    if (!$row) {
        return ['ok' => false, 'message' => 'Unable to find that notification.'];
    }
    $type = corebb_notifications_clean_type((string)($row['notification_type'] ?? ''));
    $subjectType = corebb_notifications_clean_subject_type((string)($row['subject_type'] ?? ''));
    $subjectId = (int)($row['subject_id'] ?? 0);
    if (!corebb_notifications_can_silence_stream($type, $subjectType, $subjectId)) {
        return ['ok' => false, 'message' => 'That notification cannot be silenced.'];
    }

    $ok = db_run(
        'INSERT IGNORE INTO user_notification_mutes (user_id, notification_type, subject_type, subject_id, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $type, $subjectType, $subjectId, corebb_notifications_now()]
    );
    if (!$ok) {
        return ['ok' => false, 'message' => 'Unable to silence that notification.'];
    }

    db_run(
        'UPDATE user_notifications SET cleared_at = ? WHERE user_id = ? AND notification_type = ? AND subject_type = ? AND subject_id = ? AND cleared_at = ?',
        [corebb_notifications_now(), $userId, $type, $subjectType, $subjectId, '']
    );

    $topicTitle = '';
    if ($subjectType === 'topic' && $subjectId > 0) {
        $topicTitle = (string)db_value('SELECT title FROM topics WHERE id = ? LIMIT 1', [$subjectId], '');
    }
    return ['ok' => true, 'message' => 'Silenced: ' . corebb_notifications_silence_label($type, $subjectType, $subjectId, $topicTitle) . '.'];
}


/**
 * Usage: Decide whether repeated notifications should collapse into one row.
 * Referenced by: corebb_notifications_add().
 *
 * @param string $type Notification type.
 * @param string $subjectType Subject type.
 * @param int $subjectId Subject id.
 * @return bool True when new events should update an existing notification row.
 */
function corebb_notifications_should_collapse(string $type, string $subjectType = '', int $subjectId = 0): bool
{
    $type = corebb_notifications_clean_type($type);
    $subjectType = corebb_notifications_clean_subject_type($subjectType);
    $subjectId = max(0, $subjectId);
    return $type === 'topic_reply' && $subjectType === 'topic' && $subjectId > 0;
}

/**
 * Usage: Build the title for a collapsed notification row.
 * Referenced by: corebb_notifications_add().
 *
 * @param string $type Notification type.
 * @param string $fallbackTitle Original notification title.
 * @param int $eventCount Number of events represented by the row.
 * @return string Collapsed title text.
 */
function corebb_notifications_collapsed_title(string $type, string $fallbackTitle, int $eventCount): string
{
    $type = corebb_notifications_clean_type($type);
    $eventCount = max(1, $eventCount);
    if ($type === 'topic_reply' && $eventCount > 1) {
        return $eventCount . ' new replies to your topic';
    }
    return $fallbackTitle !== '' ? $fallbackTitle : 'New notification';
}

/**
 * Usage: Build the body for a collapsed notification row.
 * Referenced by: corebb_notifications_add().
 *
 * @param string $type Notification type.
 * @param string $body Original notification body.
 * @param int $eventCount Number of events represented by the row.
 * @return string Collapsed body text.
 */
function corebb_notifications_collapsed_body(string $type, string $body, int $eventCount): string
{
    return $body;
}

/**
 * Usage: Create one notification for one user.
 * Referenced by: moderation notification helpers and external notification callers.
 *
 * @param int $userId Target user id.
 * @param string $type Notification type.
 * @param string $title Notification title.
 * @param string $body Notification body.
 * @param string $targetUrl Local URL opened from the notification.
 * @param int $actorUserId User id responsible for the event.
 * @param string $subjectType Subject type used for collapsing/mutes.
 * @param int $subjectId Subject id used for collapsing/mutes.
 * @return bool True when a notification row is created or collapsed successfully.
 */
function corebb_notifications_add(int $userId, string $type, string $title, string $body = '', string $targetUrl = '', int $actorUserId = 0, string $subjectType = '', int $subjectId = 0): bool
{
    $userId = max(0, $userId);
    if ($userId <= 0) {
        return false;
    }

    corebb_notifications_ensure_schema();

    $type = corebb_notifications_clean_type($type);
    $subjectType = corebb_notifications_clean_subject_type($subjectType);
    $subjectId = max(0, $subjectId);
    if (!corebb_notifications_should_deliver($userId, $type, $subjectType, $subjectId)) {
        return false;
    }
    $title = corebb_notifications_limit_text(strip_tags($title), 160);
    $body = corebb_notifications_limit_text(strip_tags(str_ireplace(['<br />', '<br/>', '<br>'], "\n", $body)), 2000);
    $targetUrl = corebb_notifications_normalize_url($targetUrl);
    $actorUserId = max(0, $actorUserId);
    if ($title === '') {
        $title = 'New notification';
    }
    $now = corebb_notifications_now();

    if (corebb_notifications_should_collapse($type, $subjectType, $subjectId)) {
        $existing = db_one(
            'SELECT id, event_count FROM user_notifications WHERE user_id = ? AND notification_type = ? AND subject_type = ? AND subject_id = ? AND cleared_at = ? ORDER BY id DESC LIMIT 1',
            [$userId, $type, $subjectType, $subjectId, '']
        );
        if ($existing) {
            $notificationId = (int)($existing['id'] ?? 0);
            $eventCount = max(1, (int)($existing['event_count'] ?? 1)) + 1;
            $collapsedTitle = corebb_notifications_limit_text(corebb_notifications_collapsed_title($type, $title, $eventCount), 160);
            $collapsedBody = corebb_notifications_limit_text(corebb_notifications_collapsed_body($type, $body, $eventCount), 2000);

            if ($notificationId > 0) {
                return db_run(
                    'UPDATE user_notifications SET actor_user_id = ?, title = ?, body = ?, target_url = ?, created_at = ?, read_at = ?, event_count = ? WHERE id = ? AND user_id = ? AND cleared_at = ?',
                    [$actorUserId, $collapsedTitle, $collapsedBody, $targetUrl, $now, '', $eventCount, $notificationId, $userId, '']
                );
            }
        }
    }

    return db_run(
        'INSERT INTO user_notifications (user_id, actor_user_id, notification_type, title, body, target_url, subject_type, subject_id, event_count, created_at, read_at, cleared_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$userId, $actorUserId, $type, $title, $body, $targetUrl, $subjectType, $subjectId, 1, $now, '', '']
    );
}

/**
 * Usage: Count active notifications for badges without forcing schema creation.
 * Referenced by: layout, User CP, and notification center counts.
 *
 * @param int $userId User id to count for.
 * @param bool $ensureSchema Whether to create/repair the notification schema before counting.
 * @return int Active notification count.
 */
function corebb_notifications_uncleared_count(int $userId, bool $ensureSchema = false): int
{
    $userId = max(0, $userId);
    if ($userId <= 0) {
        return 0;
    }
    if ($ensureSchema) {
        corebb_notifications_ensure_schema();
    } elseif (!corebb_notifications_table_exists('user_notifications')) {
        return 0;
    }
    return (int)db_value('SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND cleared_at = ?', [$userId, ''], 0);
}

/**
 * Usage: Clear one active notification.
 * Referenced by: notification center POST actions.
 *
 * @param int $userId User id who owns the notification.
 * @param int $notificationId Notification id to clear.
 * @return bool True when the notification is marked cleared.
 */
function corebb_notifications_clear_one(int $userId, int $notificationId): bool
{
    $userId = max(0, $userId);
    $notificationId = max(0, $notificationId);
    if ($userId <= 0 || $notificationId <= 0) {
        return false;
    }
    corebb_notifications_ensure_schema();
    return db_run(
        'UPDATE user_notifications SET cleared_at = ? WHERE id = ? AND user_id = ? AND cleared_at = ?',
        [corebb_notifications_now(), $notificationId, $userId, '']
    );
}

/**
 * Usage: Clear all active notifications for a user.
 * Referenced by: notification center POST actions.
 *
 * @param int $userId User id whose notifications should be cleared.
 * @return bool True when the update succeeds.
 */
function corebb_notifications_clear_all(int $userId): bool
{
    $userId = max(0, $userId);
    if ($userId <= 0) {
        return false;
    }
    corebb_notifications_ensure_schema();
    return db_run(
        'UPDATE user_notifications SET cleared_at = ? WHERE user_id = ? AND cleared_at = ?',
        [corebb_notifications_now(), $userId, '']
    );
}

/**
 * Usage: Fetch active notifications for the notification center.
 * Referenced by: corebb_notifications_model().
 *
 * @param int $userId User id whose notifications should be loaded.
 * @param int $limit Maximum number of rows to return.
 * @return array<int, array<string, mixed>> Prepared notification rows.
 */
function corebb_notifications_fetch(int $userId, int $limit = 100): array
{
    $userId = max(0, $userId);
    if ($userId <= 0) {
        return [];
    }
    corebb_notifications_ensure_schema();
    $limit = max(1, min(200, $limit));

    $rows = db_all(
        'SELECT n.*, u.username AS actor_username
           FROM user_notifications n
           LEFT JOIN users u ON u.id = n.actor_user_id
          WHERE n.user_id = ? AND n.cleared_at = ?
          ORDER BY n.id DESC
          LIMIT ' . (int)$limit,
        [$userId, '']
    );

    foreach ($rows as &$row) {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['user_id'] = (int)($row['user_id'] ?? 0);
        $row['actor_user_id'] = (int)($row['actor_user_id'] ?? 0);
        $row['title'] = (string)($row['title'] ?? 'Notification');
        $row['body'] = (string)($row['body'] ?? '');
        $row['target_url'] = corebb_notifications_normalize_url((string)($row['target_url'] ?? ''));
        $row['notification_type'] = corebb_notifications_clean_type((string)($row['notification_type'] ?? ''));
        $row['subject_type'] = corebb_notifications_clean_subject_type((string)($row['subject_type'] ?? ''));
        $row['subject_id'] = (int)($row['subject_id'] ?? 0);
        $row['event_count'] = max(1, (int)($row['event_count'] ?? 1));
        $row['can_silence'] = corebb_notifications_can_silence_stream($row['notification_type'], $row['subject_type'], $row['subject_id']);
        $topicTitle = '';
        if ($row['subject_type'] === 'topic' && $row['subject_id'] > 0) {
            $topicTitle = (string)db_value('SELECT title FROM topics WHERE id = ? LIMIT 1', [$row['subject_id']], '');
        }
        $row['silence_label'] = corebb_notifications_silence_label($row['notification_type'], $row['subject_type'], $row['subject_id'], $topicTitle);
        $createdAt = (string)($row['created_at'] ?? '');
        $row['created_display'] = $createdAt !== '' ? convert_to_vndate($createdAt) : $createdAt;
        $row['actor_username'] = (string)($row['actor_username'] ?? '');
    }
    unset($row);

    return $rows;
}

/**
 * Usage: Build a target URL for a reply/mention notification.
 * Referenced by: mention and moderation notification helpers.
 *
 * @param int $topicId Topic id.
 * @param int $boardId Board id.
 * @param int $postId Post id to anchor, or 0 for topic-level links.
 * @param string $boardName Board name used by canonical URL helpers.
 * @return string Public thread/post URL.
 */
function corebb_notifications_reply_target_url(int $topicId, int $boardId, int $postId, string $boardName = ''): string
{
    $page = 1;
    if ($postId > 0) {
        $perPage = corebb_current_thread_posts_per_page();
        $position = (int)db_value(
            'SELECT COUNT(*) FROM posts WHERE threadid = ? AND is_deleted = 0 AND id <= ?',
            [$topicId, $postId],
            1
        );
        $page = max(1, (int)ceil(max(1, $position) / max(1, $perPage)));
    }
    return corebb_thread_url($topicId, $boardId, $page, $boardName, $postId);
}


/**
 * Usage: Check whether a users-table column exists for notification filters.
 * Referenced by: corebb_notifications_user_is_archive_condition().
 *
 * @param string $column Users-table column name.
 * @return bool True when the column exists.
 */
function corebb_notifications_user_column_exists(string $column): bool
{
    return corebb_notifications_column_exists('users', $column);
}

/**
 * Usage: Build SQL that excludes legacy archive users from mention/broadcast targets.
 * Referenced by: mention lookup and broadcast helpers.
 *
 * @param string $alias SQL alias for the users table.
 * @return string SQL condition for non-archive users.
 */
function corebb_notifications_user_is_archive_condition(string $alias = 'u'): string
{
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'u';
    if (corebb_notifications_user_column_exists('is_archive_user')) {
        return 'COALESCE(' . $alias . '.is_archive_user, 0) = 0';
    }
    if (corebb_notifications_user_column_exists('legacy_source')) {
        return "COALESCE(" . $alias . ".legacy_source, '') <> 'vn_archive'";
    }
    return '1 = 1';
}

/**
 * Usage: Check whether an access level may use @All broadcasts.
 * Referenced by: mention extraction and mention notification delivery.
 *
 * @param int $accessLevel Actor access level.
 * @return bool True when the actor can broadcast to all eligible users.
 */
function corebb_notifications_is_admin_broadcast_level(int $accessLevel): bool
{
    return $accessLevel >= 5;
}

/**
 * Usage: Clean one parsed mention name before lookup.
 * Referenced by: mention extraction and mention user lookup.
 *
 * @param string $name Raw mention name.
 * @return string Clean mention name capped to lookup length.
 */
function corebb_notifications_mention_clean_name(string $name): string
{
    $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $name = trim($name);
    $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?? '';
    return corebb_notifications_limit_text($name, 64);
}

/**
 * Usage: Extract @mentions and optional admin @All broadcast intent from a post body.
 * Referenced by: corebb_notifications_notify_post_mentions().
 *
 * @param string $body Post body text.
 * @param int $actorAccessLevel Actor access level for @All eligibility.
 * @return array{names: array<int, string>, broadcast_all: bool} Mention names and broadcast flag.
 */
function corebb_notifications_extract_mentions(string $body, int $actorAccessLevel = 0): array
{
    $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $names = [];
    $seen = [];
    $broadcastAll = false;
    $isAdmin = corebb_notifications_is_admin_broadcast_level($actorAccessLevel);

    $addName = static function (string $name) use (&$names, &$seen): void {
        $name = corebb_notifications_mention_clean_name($name);
        if ($name === '') {
            return;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $names[] = $name;
        }
    };

    if (preg_match_all('/(^|[^A-Za-z0-9_])@\[([^\]\r\n]{1,64})\]/u', $body, $bracketMatches)) {
        foreach ($bracketMatches[2] as $name) {
            $addName((string)$name);
        }
    }

    if (preg_match_all('/(^|[^A-Za-z0-9_.+\-])@([A-Za-z0-9_\-]{1,40})/u', $body, $simpleMatches)) {
        foreach ($simpleMatches[2] as $name) {
            $name = corebb_notifications_mention_clean_name((string)$name);
            if ($name === '') {
                continue;
            }
            if (strcasecmp($name, 'All') === 0 && $isAdmin) {
                $broadcastAll = true;
                continue;
            }
            $addName($name);
        }
    }

    return ['names' => $names, 'broadcast_all' => $broadcastAll];
}

/**
 * Usage: Resolve a parsed mention name to a user row.
 * Referenced by: corebb_notifications_notify_post_mentions().
 *
 * @param string $name Mention name.
 * @param bool $excludeArchive Whether legacy archive users should be excluded.
 * @return array<string, mixed>|false Matched user row, or false when unknown.
 */
function corebb_notifications_lookup_user_by_mention(string $name, bool $excludeArchive = true): array|false
{
    $name = corebb_notifications_mention_clean_name($name);
    if ($name === '') {
        return false;
    }

    $candidates = [$name];
    if (str_starts_with($name, '-') && !str_ends_with($name, '-')) {
        $candidates[] = $name . '-';
    }
    $trimmed = trim($name, '-');
    if ($trimmed !== '' && $trimmed !== $name) {
        $candidates[] = '-' . $trimmed . '-';
    }

    $seen = [];
    foreach ($candidates as $candidate) {
        $candidate = corebb_notifications_mention_clean_name($candidate);
        if ($candidate === '') {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($candidate, 'UTF-8') : strtolower($candidate);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $where = ['u.username = ?'];
        $params = [$candidate];
        if ($excludeArchive) {
            $where[] = corebb_notifications_user_is_archive_condition('u');
        }
        $row = db_one('SELECT u.id, u.username, u.accesslevel FROM users u WHERE ' . implode(' AND ', $where) . ' LIMIT 1', $params);
        if ($row) {
            return $row;
        }
    }

    return false;
}

/**
 * Usage: Check whether a mentioned user can view the board where the mention occurred.
 * Referenced by: corebb_notifications_notify_post_mentions().
 *
 * @param int $boardId Board id.
 * @param int $userId Mentioned user id.
 * @param int $accessLevel Mentioned user's access level.
 * @return bool True when the user can see the board.
 */
function corebb_notifications_user_can_see_board(int $boardId, int $userId, int $accessLevel = 0): bool
{
    if ($boardId <= 0 || $userId <= 0) {
        return false;
    }
    return corebb_private_user_can_view_board_id($boardId, $userId, $accessLevel);
}

/**
 * Usage: Build a board URL for moderation notifications when a thread disappears.
 * Referenced by: corebb_notifications_notify_moderated_post().
 *
 * @param int $boardId Board id.
 * @param string $boardName Board name used by canonical URL helpers.
 * @return string Public board URL, or "/" for invalid boards.
 */
function corebb_notifications_board_url(int $boardId, string $boardName = ''): string
{
    if ($boardId <= 0) {
        return '/';
    }
    return corebb_board_url($boardId, 1, $boardName);
}

/**
 * Usage: Create the same notification for many users.
 * Referenced by: mention and broadcast helpers.
 *
 * @param array<int, mixed> $userIds Target user ids.
 * @param string $type Notification type.
 * @param string $title Notification title.
 * @param string $body Notification body.
 * @param string $targetUrl Local target URL.
 * @param int $actorUserId User id responsible for the event.
 * @param string $subjectType Subject type used for mutes.
 * @param int $subjectId Subject id used for mutes.
 * @return int Number of notification rows inserted.
 */
function corebb_notifications_add_many(array $userIds, string $type, string $title, string $body = '', string $targetUrl = '', int $actorUserId = 0, string $subjectType = '', int $subjectId = 0): int
{
    $unique = [];
    foreach ($userIds as $userId) {
        $userId = (int)$userId;
        if ($userId > 0) {
            $unique[$userId] = $userId;
        }
    }
    if (!$unique) {
        return 0;
    }

    corebb_notifications_ensure_schema();
    $type = corebb_notifications_clean_type($type);
    $subjectType = corebb_notifications_clean_subject_type($subjectType);
    $subjectId = max(0, $subjectId);
    foreach ($unique as $userId) {
        if (!corebb_notifications_should_deliver((int)$userId, $type, $subjectType, $subjectId)) {
            unset($unique[$userId]);
        }
    }
    if (!$unique) {
        return 0;
    }
    $title = corebb_notifications_limit_text(strip_tags($title), 160);
    $body = corebb_notifications_limit_text(strip_tags(str_ireplace(['<br />', '<br/>', '<br>'], "\n", $body)), 2000);
    $targetUrl = corebb_notifications_normalize_url($targetUrl);
    $actorUserId = max(0, $actorUserId);
    $now = corebb_notifications_now();
    if ($title === '') {
        $title = 'New notification';
    }

    $created = 0;
    $ids = array_values($unique);
    foreach (array_chunk($ids, 100) as $chunk) {
        $values = [];
        $params = [];
        foreach ($chunk as $userId) {
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push($params, (int)$userId, $actorUserId, $type, $title, $body, $targetUrl, $subjectType, $subjectId, 1, $now, '', '');
        }
        if (db_run('INSERT INTO user_notifications (user_id, actor_user_id, notification_type, title, body, target_url, subject_type, subject_id, event_count, created_at, read_at, cleared_at) VALUES ' . implode(', ', $values), $params)) {
            $created += corebb_db_last_affected_rows();
        }
    }
    return $created;
}

/**
 * Usage: Broadcast one notification to all eligible, non-archive users.
 * Referenced by: corebb_notifications_notify_post_mentions().
 *
 * @param string $type Notification type.
 * @param string $title Notification title.
 * @param string $body Notification body.
 * @param string $targetUrl Local target URL.
 * @param int $actorUserId User id responsible for the event.
 * @param int $boardId Board id used to respect private-board visibility.
 * @param string $subjectType Subject type used for mutes.
 * @param int $subjectId Subject id used for mutes.
 * @return int Number of notification rows inserted.
 */
function corebb_notifications_broadcast_all(string $type, string $title, string $body = '', string $targetUrl = '', int $actorUserId = 0, int $boardId = 0, string $subjectType = '', int $subjectId = 0): int
{
    corebb_notifications_ensure_schema();
    $type = corebb_notifications_clean_type($type);
    $subjectType = corebb_notifications_clean_subject_type($subjectType);
    $subjectId = max(0, $subjectId);
    $title = corebb_notifications_limit_text(strip_tags($title), 160);
    $body = corebb_notifications_limit_text(strip_tags(str_ireplace(['<br />', '<br/>', '<br>'], "\n", $body)), 2000);
    $targetUrl = corebb_notifications_normalize_url($targetUrl);
    $actorUserId = max(0, $actorUserId);
    $now = corebb_notifications_now();
    if ($title === '') {
        $title = 'New notification';
    }

    $where = ['u.id <> ?', corebb_notifications_user_is_archive_condition('u'), 'COALESCE(ns.notifications_enabled, 1) = 1'];
    $params = [$actorUserId];

    if ($subjectType !== '' && $subjectId > 0) {
        $where[] = 'NOT EXISTS (SELECT 1 FROM user_notification_mutes nm WHERE nm.user_id = u.id AND nm.notification_type = ? AND nm.subject_type = ? AND nm.subject_id = ? LIMIT 1)';
        array_push($params, $type, $subjectType, $subjectId);
    }

    if ($boardId > 0) {
        $board = corebb_private_board_row($boardId);
        $isPrivate = is_array($board) && (((int)($board['private'] ?? 0) === 1) || ((int)($board['category_private'] ?? 0) === 1));
        if ($isPrivate) {
            corebb_private_ensure_schema();
            $where[] = '(u.accesslevel >= 5 OR EXISTS (SELECT 1 FROM private_board_access pba WHERE pba.boardid = ? AND pba.userid = u.id LIMIT 1))';
            $params[] = $boardId;
        }
    }

    $sql = 'INSERT INTO user_notifications (user_id, actor_user_id, notification_type, title, body, target_url, subject_type, subject_id, event_count, created_at, read_at, cleared_at) '
         . 'SELECT u.id, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? FROM users u '
         . 'LEFT JOIN user_notification_settings ns ON ns.user_id = u.id '
         . 'WHERE ' . implode(' AND ', $where);
    $insertParams = array_merge([$actorUserId, $type, $title, $body, $targetUrl, $subjectType, $subjectId, 1, $now, '', ''], $params);
    if (!db_run($sql, $insertParams)) {
        return 0;
    }
    return corebb_db_last_affected_rows();
}

/**
 * Usage: Notify users mentioned in a post body, including admin @All broadcasts.
 * Referenced by: post workflow after creating/updating visible posts.
 *
 * @param int $actorUserId Posting user id.
 * @param string $actorUsername Posting username.
 * @param int $actorAccessLevel Posting user's access level.
 * @param int $boardId Board id where the post lives.
 * @param int $topicId Topic id where the post lives.
 * @param int $postId Post id that triggered mentions.
 * @param string $topicTitle Topic title for message text.
 * @param string $body Post body to scan.
 * @param string $boardName Board name used by canonical URL helpers.
 * @return array{mentions: int, broadcast: int} Counts of mention and broadcast notifications created.
 */
function corebb_notifications_notify_post_mentions(int $actorUserId, string $actorUsername, int $actorAccessLevel, int $boardId, int $topicId, int $postId, string $topicTitle, string $body, string $boardName = ''): array
{
    $actorUserId = max(0, $actorUserId);
    $actorUsername = trim($actorUsername) !== '' ? trim($actorUsername) : 'Someone';
    $topicTitle = trim($topicTitle) !== '' ? trim($topicTitle) : 'a thread';
    $targetUrl = corebb_notifications_reply_target_url($topicId, $boardId, $postId, $boardName);
    $mentions = corebb_notifications_extract_mentions($body, $actorAccessLevel);
    $created = ['mentions' => 0, 'broadcast' => 0];

    if (!empty($mentions['broadcast_all']) && corebb_notifications_is_admin_broadcast_level($actorAccessLevel)) {
        $created['broadcast'] = corebb_notifications_broadcast_all(
            'mention_all',
            'New site-wide mention',
            $actorUsername . ' mentioned everyone in "' . $topicTitle . '".',
            $targetUrl,
            $actorUserId,
            $boardId,
            'topic',
            $topicId
        );
    }

    $recipientIds = [];
    if (empty($mentions['broadcast_all'])) {
        foreach ((array)($mentions['names'] ?? []) as $name) {
            $recipient = corebb_notifications_lookup_user_by_mention((string)$name, true);
            if (!$recipient) {
                continue;
            }
            $recipientId = (int)($recipient['id'] ?? 0);
            if ($recipientId <= 0 || $recipientId === $actorUserId) {
                continue;
            }
            if (!corebb_notifications_user_can_see_board($boardId, $recipientId, (int)($recipient['accesslevel'] ?? 0))) {
                continue;
            }
            $recipientIds[$recipientId] = $recipientId;
        }
    }

    if ($recipientIds) {
        $created['mentions'] = corebb_notifications_add_many(
            array_values($recipientIds),
            'mention',
            'You were mentioned in a thread',
            $actorUsername . ' mentioned you in "' . $topicTitle . '".',
            $targetUrl,
            $actorUserId,
            'topic',
            $topicId
        );
    }

    return $created;
}

/**
 * Usage: Notify a post owner when a moderator edits or deletes their post/thread.
 * Referenced by: moderation helpers after edit/delete actions.
 *
 * @param array<string, mixed> $post Moderated post row.
 * @param string $action Moderation action, currently "edited" or "deleted".
 * @param int $actorUserId Moderator user id.
 * @param string $actorUsername Moderator display name.
 * @param string $reason Optional moderation reason.
 * @return bool True when a notification is created.
 */
function corebb_notifications_notify_moderated_post(array $post, string $action, int $actorUserId, string $actorUsername = '', string $reason = ''): bool
{
    $recipientId = (int)($post['posterid'] ?? 0);
    if ($recipientId <= 0 || $recipientId === $actorUserId) {
        return false;
    }

    $topicId = (int)($post['threadid'] ?? 0);
    $boardId = (int)($post['boardid'] ?? 0);
    $subject = trim((string)($post['title'] ?? ''));
    if ($subject === '') {
        $subject = 'your post';
    }
    $board = $boardId > 0 ? corebb_private_board_row($boardId) : false;
    $boardName = is_array($board) ? (string)($board['name'] ?? '') : '';
    $firstPostId = $topicId > 0 ? (int)db_value('SELECT id FROM posts WHERE threadid = ? ORDER BY id ASC LIMIT 1', [$topicId], 0) : 0;
    $isThreadStarter = $firstPostId > 0 && $firstPostId === (int)($post['id'] ?? 0);
    $actorUsername = trim($actorUsername) !== '' ? trim($actorUsername) : 'A moderator';
    $reason = corebb_notifications_limit_text(trim($reason), 255);

    if ($action === 'edited') {
        $targetUrl = corebb_notifications_reply_target_url($topicId, $boardId, (int)($post['id'] ?? 0), $boardName);
        $title = $isThreadStarter ? 'Your thread was edited by a moderator' : 'Your post was edited by a moderator';
        $body = $actorUsername . ' edited ' . ($isThreadStarter ? 'your thread' : 'your post') . ' "' . $subject . '".';
        if ($reason !== '') {
            $body .= ' Reason: ' . $reason;
        }
        return corebb_notifications_add($recipientId, 'moderation_edit', $title, $body, $targetUrl, $actorUserId);
    }

    if ($action === 'deleted') {
        $visiblePosts = $topicId > 0 ? (int)db_value('SELECT COUNT(*) FROM posts WHERE threadid = ? AND is_deleted = 0', [$topicId], 0) : 0;
        $targetUrl = $visiblePosts > 0 ? corebb_notifications_reply_target_url($topicId, $boardId, 0, $boardName) : corebb_notifications_board_url($boardId, $boardName);
        $title = $isThreadStarter || $visiblePosts <= 0 ? 'Your thread was deleted by a moderator' : 'Your post was deleted by a moderator';
        $body = $actorUsername . ' deleted ' . (($isThreadStarter || $visiblePosts <= 0) ? 'your thread' : 'your post') . ' "' . $subject . '".';
        if ($reason !== '') {
            $body .= ' Reason: ' . $reason;
        }
        return corebb_notifications_add($recipientId, 'moderation_delete', $title, $body, $targetUrl, $actorUserId);
    }

    return false;
}

?>
