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
 |  moderation_helpers.php  - PHP 8 moderation helpers   |
 |  for CoreBB.                                          |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/private_board_helpers.php';
require_once __DIR__ . '/notification_helpers.php';

/**
 * Escape a scalar for plain moderator output.
 *
 * Usage: keep as the local escape helper when moderation-only views need a
 * simple string escaped before display.
 * Referenced by: moderation templates/helpers as needed.
 *
 * @param mixed $value Value to escape.
 * @return string HTML-safe text.
 */
function corebb_mod_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Quote a trusted table, column, or index identifier for direct SQL fragments.
 *
 * Usage: call before composing schema-maintenance SQL with dynamic identifiers.
 * Referenced by: moderation schema helpers.
 *
 * @param string $identifier Database identifier without quoting.
 * @return string Backtick-quoted identifier.
 *
 * @throws InvalidArgumentException When the identifier contains unsafe bytes.
 */
function corebb_mod_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Resolve the current client IP using the shared security helper when present.
 *
 * Usage: store moderator/reporting IP metadata without duplicating request
 * parsing rules.
 * Referenced by: post creation, contact-moderators, and mod-request flows.
 *
 * @return string Client IP capped to the database field length.
 */
function corebb_mod_current_ip(): string
{
    $ip = function_exists('corebb_security_client_ip')
        ? corebb_security_client_ip()
        : trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return substr($ip, 0, 255);
}

/**
 * Check whether the current session has moderator-level access.
 *
 * Usage: gate moderator controller actions and API moderation endpoints.
 * Referenced by: controllers/moderation.php, API serializers, board/thread/post view models.
 *
 * @return bool True for access level 3 and higher.
 */
function corebb_mod_can_moderate(): bool
{
    global $userlogindata_a;
    return isset($userlogindata_a['accesslevel']) && (int)$userlogindata_a['accesslevel'] >= 3;
}

/**
 * Build a public topic redirect for moderation action results.
 *
 * Usage: return users to the affected topic after lock, sticky, delete, and
 * restore operations without scattering route strings through action code.
 * Referenced by: moderation mutation helpers in this file.
 *
 * @param int $topicId Topic id to display.
 * @param int $postId Optional post anchor id.
 * @param int $page Topic page number.
 * @return string Public topic URL or index.php for invalid topics.
 */
function corebb_mod_thread_redirect(int $topicId, int $postId = 0, int $page = 1): string
{
    if ($topicId <= 0) {
        return 'index.php';
    }

    $page = max(1, $page);
    $path = 'controllers/forum.php?action=thread&id=' . $topicId . '&p=' . $page;
    if ($postId > 0) {
        $path .= '#post' . $postId;
    }

    return function_exists('corebb_public_url') ? corebb_public_url($path) : '/topic/' . $topicId . '/p' . $page . '/' . ($postId > 0 ? '#post' . $postId : '');
}

/**
 * Build a public board redirect for moderation action results.
 *
 * Usage: return users to the affected board after topic-level operations.
 * Referenced by: moderation mutation helpers in this file.
 *
 * @param int $boardId Board id to display.
 * @return string Public board URL or index.php for invalid boards.
 */
function corebb_mod_board_redirect(int $boardId): string
{
    if ($boardId <= 0) {
        return 'index.php';
    }

    $path = 'controllers/forum.php?action=board&id=' . $boardId;
    return function_exists('corebb_public_url') ? corebb_public_url($path) : '/board/' . $boardId . '/';
}

/**
 * Determine the active database name for INFORMATION_SCHEMA lookups.
 *
 * Usage: schema helpers call this before checking columns or indexes.
 * Referenced by: corebb_mod_column_exists() and corebb_mod_index_exists().
 *
 * @return string Current database/schema name.
 */
function corebb_mod_db_name(): string
{
    $db = corebb_db_connection_name();
    if ($db === '') {
        $db = (string)db_value('SELECT DATABASE()', [], '');
    }
    return $db;
}

/**
 * Check whether a column exists on the current database schema.
 *
 * Usage: keep moderation migrations idempotent when older installs are opened.
 * Referenced by: corebb_mod_ensure_schema(), ban/unban metadata updates.
 *
 * @param string $table Table name to inspect.
 * @param string $column Column name to inspect.
 * @return bool True when the column exists.
 */
function corebb_mod_column_exists(string $table, string $column): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }

    return ((int)db_value(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [corebb_mod_db_name(), $table, $column],
        0
    )) > 0;
}

/**
 * Check whether an index exists on the current database schema.
 *
 * Usage: keep moderation schema upgrades repeatable across partially upgraded
 * installations.
 * Referenced by: corebb_mod_ensure_schema().
 *
 * @param string $table Table name to inspect.
 * @param string $index Index name to inspect.
 * @return bool True when the index exists.
 */
function corebb_mod_index_exists(string $table, string $index): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $index)) {
        return false;
    }

    return ((int)db_value(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [corebb_mod_db_name(), $table, $index],
        0
    )) > 0;
}

/**
 * Ensure moderation columns and indexes exist before moderation logic runs.
 *
 * Usage: call near public entry points that read or write deleted/locked/edit
 * metadata.
 * Referenced by: board/thread/post/usercp view models, admin repair tools, API
 * moderation endpoints, and helpers in this file.
 *
 * @return void
 */
function corebb_mod_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (!corebb_mod_column_exists('topics', 'locked')) {
        db_run("ALTER TABLE `topics` ADD `locked` TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!corebb_mod_column_exists('topics', 'is_deleted')) {
        db_run("ALTER TABLE `topics` ADD `is_deleted` TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!corebb_mod_column_exists('topics', 'deleted_at')) {
        db_run("ALTER TABLE `topics` ADD `deleted_at` VARCHAR(64) NOT NULL DEFAULT ''");
    }
    if (!corebb_mod_column_exists('topics', 'deleted_by')) {
        db_run("ALTER TABLE `topics` ADD `deleted_by` INT(11) NOT NULL DEFAULT 0");
    }
    if (!corebb_mod_column_exists('topics', 'delete_reason')) {
        db_run("ALTER TABLE `topics` ADD `delete_reason` TEXT NULL");
    }
    if (!corebb_mod_index_exists('topics', 'idx_topics_deleted_board')) {
        db_run("ALTER TABLE `topics` ADD INDEX `idx_topics_deleted_board` (`is_deleted`, `boardid`)");
    }

    if (!corebb_mod_column_exists('posts', 'postip')) {
        db_run("ALTER TABLE `posts` ADD `postip` VARCHAR(255) NOT NULL DEFAULT ''");
    }
    if (!corebb_mod_column_exists('posts', 'editcount')) {
        if (db_run("ALTER TABLE `posts` ADD `editcount` INT UNSIGNED NOT NULL DEFAULT 0")) {
            // Imported/older rows only knew whether a post had ever been edited.
            // Seed those as one known edit so the next edit becomes 2 total.
            if (corebb_mod_column_exists('posts', 'wasedited')) {
                db_run("UPDATE `posts` SET `editcount` = 1 WHERE `wasedited` = 1 AND `editcount` = 0");
            }
        }
    }
    if (!corebb_mod_column_exists('posts', 'is_deleted')) {
        db_run("ALTER TABLE `posts` ADD `is_deleted` TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!corebb_mod_column_exists('posts', 'deleted_at')) {
        db_run("ALTER TABLE `posts` ADD `deleted_at` VARCHAR(64) NOT NULL DEFAULT ''");
    }
    if (!corebb_mod_column_exists('posts', 'deleted_by')) {
        db_run("ALTER TABLE `posts` ADD `deleted_by` INT(11) NOT NULL DEFAULT 0");
    }
    if (!corebb_mod_column_exists('posts', 'delete_reason')) {
        db_run("ALTER TABLE `posts` ADD `delete_reason` TEXT NULL");
    }
    if (!corebb_mod_index_exists('posts', 'idx_posts_deleted_thread')) {
        db_run("ALTER TABLE `posts` ADD INDEX `idx_posts_deleted_thread` (`is_deleted`, `threadid`)");
    }
    if (!corebb_mod_index_exists('posts', 'idx_posts_deleted_board')) {
        db_run("ALTER TABLE `posts` ADD INDEX `idx_posts_deleted_board` (`is_deleted`, `boardid`)");
    }
    $done = true;
}

/**
 * Fetch one database row for moderation helpers.
 *
 * Usage: keep single-row moderation reads routed through the shared DB wrapper.
 * Referenced by: post, topic, user, and count refresh helpers in this file.
 *
 * @param string $sql Parameterized SQL query.
 * @param array<int, mixed> $params Query parameters.
 * @return array<string, mixed>|false Row data or false when no row matched.
 */
function corebb_mod_fetch_one(string $sql, array $params = []): array|false
{
    return db_one($sql, $params);
}

/**
 * Return the current moderation timestamp.
 *
 * Usage: stamp delete/restore/ban records consistently.
 * Referenced by: delete, restore, ban, and topic visibility helpers.
 *
 * @return string Timestamp in database display format.
 */
function corebb_mod_now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Resolve the logged-in user id for moderation actions.
 *
 * Usage: record who performed deletes, restores, bans, and related actions.
 * Referenced by: rank checks and moderation write helpers.
 *
 * @return int Current user id or 0 for an anonymous/unknown context.
 */
function corebb_mod_current_user_id(): int
{
    global $userlogindata_a;
    return (int)($userlogindata_a['id'] ?? 0);
}

/**
 * Resolve the logged-in username for moderation notifications and logs.
 *
 * Usage: provide the actor label for moderation notices.
 * Referenced by: post/topic delete notification flows.
 *
 * @return string Username, user id fallback, or "unknown".
 */
function corebb_mod_current_username(): string
{
    global $userlogindata_a;
    return (string)($userlogindata_a['username'] ?? $userlogindata_a['id'] ?? 'unknown');
}

/**
 * Resolve the current actor's access level.
 *
 * Usage: compare moderator permissions and Secure Archive write rights.
 * Referenced by: moderator controller, post workflow, admin tools, and helpers
 * in this file.
 *
 * @return int Current access level or 0 when unknown.
 */
function corebb_mod_actor_level(): int
{
    global $userlogindata_a;
    return (int)($userlogindata_a['accesslevel'] ?? 0);
}

/**
 * Check whether the current moderator may act on one author's content.
 *
 * Usage: prevent moderators from acting on their peers or higher-ranked users.
 * Referenced by: post delete, restore, purge, and user moderation helpers.
 *
 * @param int $authorId User id for the content author.
 * @return string Empty string when allowed, otherwise a user-facing error.
 */
function corebb_mod_author_rank_error(int $authorId): string
{
    $viewerId = corebb_mod_current_user_id();
    $viewerLevel = corebb_mod_actor_level();
    if ($viewerLevel < 3) {
        return 'Moderator access is required.';
    }
    if ($authorId <= 0 || ($viewerId > 0 && $viewerId === $authorId)) {
        return '';
    }
    $author = corebb_mod_get_user($authorId);
    if ($author && (int)($author['accesslevel'] ?? 0) >= $viewerLevel) {
        return 'You cannot moderate content posted by a user with equal or higher rights.';
    }
    return '';
}

/**
 * Check whether a topic contains content above the moderator's rank.
 *
 * Usage: block whole-topic actions when any visible post belongs to an equal or
 * higher access-level user.
 * Referenced by: topic delete and sticky helpers.
 *
 * @param int $topicId Topic id to inspect.
 * @return string Empty string when allowed, otherwise a user-facing error.
 */
function corebb_mod_topic_rank_error(int $topicId): string
{
    $viewerId = corebb_mod_current_user_id();
    $viewerLevel = corebb_mod_actor_level();
    if ($viewerLevel < 3) {
        return 'Moderator access is required.';
    }
    $row = db_one(
        'SELECT p.posterid, u.username, u.accesslevel
         FROM posts p
         INNER JOIN users u ON u.id = p.posterid
         WHERE p.threadid = ? AND p.is_deleted = 0 AND p.posterid <> ? AND u.accesslevel >= ?
         ORDER BY u.accesslevel DESC, p.id ASC
         LIMIT 1',
        [$topicId, $viewerId, $viewerLevel]
    );
    if ($row) {
        return 'You cannot moderate a topic containing posts by a user with equal or higher rights.';
    }
    return '';
}

/**
 * Normalize moderation reason text to the storage limit.
 *
 * Usage: trim delete, ban, unban, and moderation-request notes before writing.
 * Referenced by: delete, restore, ban/unban, and mod-request helpers.
 *
 * @param string $reason Raw reason text from a form or API payload.
 * @return string Trimmed text capped to 4096 bytes.
 */
function corebb_mod_limit_reason(string $reason): string
{
    $reason = trim($reason);
    if ($reason === '') {
        return '';
    }
    if (function_exists('mb_strcut')) {
        return mb_strcut($reason, 0, 4096, 'UTF-8');
    }
    return substr($reason, 0, 4096);
}

/**
 * Fetch one post with moderation metadata.
 *
 * Usage: load the canonical post row for edit, delete, restore, purge, and
 * moderator view-model operations.
 * Referenced by: post workflow, admin tools, moderator view model, API
 * endpoints, and helpers in this file.
 *
 * @param int $postId Post id to fetch.
 * @param bool $includeDeleted Include deleted-bin posts when true.
 * @return array<string, mixed>|false Post row or false when unavailable.
 */
function corebb_mod_get_post(int $postId, bool $includeDeleted = false): array|false
{
    corebb_mod_ensure_schema();
    $where = 'id = ?';
    if (!$includeDeleted) {
        $where .= ' AND is_deleted = 0';
    }
    return corebb_mod_fetch_one(
        'SELECT id, posterid, author, title, body, threadid, boardid, ptd, posttime, posttimeraw, postip, wasedited, editedby, editdate, editcount, is_deleted, deleted_at, deleted_by, delete_reason FROM posts WHERE ' . $where . ' LIMIT 1',
        [$postId]
    );
}

/**
 * Update a post and maintain edit metadata in one write.
 *
 * Usage: shared edit path for user edits, moderator edits, and admin forum
 * simulation edits.
 * Referenced by: post_view_model.php and admin user/forum tools.
 *
 * @param int $postId Post id to update.
 * @param string $subject New post title.
 * @param string $body New post body.
 * @param int $editorId User id credited for the edit.
 * @param string $editDate Display timestamp for the edit.
 * @return bool True when the write succeeds and the post is writable.
 */
function corebb_mod_update_post_with_edit_metadata(int $postId, string $subject, string $body, int $editorId, string $editDate): bool
{
    corebb_mod_ensure_schema();

    $post = corebb_mod_get_post($postId);
    if (!$post) {
        return false;
    }

    $editorLevel = corebb_mod_actor_level();
    if ($editorId > 0 && $editorId !== corebb_mod_current_user_id()) {
        $editorLevel = (int)db_value('SELECT accesslevel FROM users WHERE id = ? LIMIT 1', [$editorId], $editorLevel);
    }
    if (!corebb_secure_archive_user_can_modify_post_id($postId, $editorLevel)) {
        return false;
    }

    $pdo = corebb_db_connection();
    $startedTransaction = false;
    if ($pdo instanceof PDO && !$pdo->inTransaction()) {
        if (!db_begin()) {
            return false;
        }
        $startedTransaction = true;
    }

    $postUpdated = db_run(
        'UPDATE posts '
        . 'SET title = ?, body = ?, '
        . 'editcount = CASE WHEN wasedited = 1 AND COALESCE(editcount, 0) < 1 THEN 2 ELSE COALESCE(editcount, 0) + 1 END, '
        . 'wasedited = 1, editedby = ?, editdate = ? '
        . 'WHERE id = ?',
        [$subject, $body, $editorId, $editDate, $postId]
    );
    if (!$postUpdated) {
        if ($startedTransaction) {
            db_rollback();
        }
        return false;
    }

    $topicId = (int)($post['threadid'] ?? 0);
    if ($topicId <= 0) {
        if ($startedTransaction && !db_commit()) {
            return false;
        }
        return true;
    }

    $firstPostId = (int)db_value(
        'SELECT id FROM posts WHERE threadid = ? AND is_deleted = 0 ORDER BY id ASC LIMIT 1',
        [$topicId],
        0
    );
    if ($firstPostId !== $postId) {
        if ($startedTransaction && !db_commit()) {
            return false;
        }
        return true;
    }

    $topicUpdated = db_run(
        'UPDATE topics SET title = ?, body = ? WHERE id = ?',
        [$subject, $body, $topicId]
    );
    if (!$topicUpdated) {
        if ($startedTransaction) {
            db_rollback();
        }
        return false;
    }

    if ($startedTransaction && !db_commit()) {
        return false;
    }
    return true;
}

/**
 * Fetch one topic with moderation metadata.
 *
 * Usage: load topic state for lock/sticky checks, posting rules, and count
 * repair paths.
 * Referenced by: post workflow, thread view model, moderator view model, admin
 * tools, and helpers in this file.
 *
 * @param int $topicId Topic id to fetch.
 * @param bool $includeDeleted Include deleted-bin topics when true.
 * @return array<string, mixed>|false Topic row or false when unavailable.
 */
function corebb_mod_get_topic(int $topicId, bool $includeDeleted = false): array|false
{
    corebb_mod_ensure_schema();
    $where = 'id = ?';
    if (!$includeDeleted) {
        $where .= ' AND is_deleted = 0';
    }
    return corebb_mod_fetch_one(
        'SELECT id, boardid, title, body, posterid, lastpost, time, now, sticky, locked, replycount, postcount, is_deleted, deleted_at, deleted_by, delete_reason FROM topics WHERE ' . $where . ' LIMIT 1',
        [$topicId]
    );
}

/**
 * Fetch one user row needed by moderation flows.
 *
 * Usage: load rank/status/IP data for ban checks and post author checks.
 * Referenced by: moderator view model, ban/unban helpers, and rank guards.
 *
 * @param int $userId User id to fetch.
 * @return array<string, mixed>|false User row or false when unavailable.
 */
function corebb_mod_get_user(int $userId): array|false
{
    return corebb_mod_fetch_one(
        'SELECT id, username, accesslevel, status, posts, lastpost, lastpstdate, lastip FROM users WHERE id = ? LIMIT 1',
        [$userId]
    );
}

/**
 * Check whether a topic is locked.
 *
 * Usage: block ordinary replies while allowing moderators through.
 * Referenced by: post_view_model.php reply validation.
 *
 * @param int $topicId Topic id to inspect.
 * @return bool True when the topic is locked.
 */
function corebb_mod_topic_is_locked(int $topicId): bool
{
    corebb_mod_ensure_schema();
    $topic = corebb_mod_get_topic($topicId, true);
    return $topic ? ((int)($topic['locked'] ?? 0) === 1) : false;
}

/**
 * Write a moderation action to the admin log when logging is available.
 *
 * Usage: record successful moderator writes without coupling this helper to the
 * admin log implementation.
 * Referenced by: delete, restore, purge, lock/sticky, ban/unban, and admin edit
 * paths.
 *
 * @param string $action Human-readable action description.
 * @return void
 */
function corebb_mod_log(string $action): void
{
    global $userlogindata_a;
    if (function_exists('addlogentry')) {
        addlogentry((string)($userlogindata_a['username'] ?? $userlogindata_a['id'] ?? 'unknown'), (int)($userlogindata_a['accesslevel'] ?? 0), $action);
    }
}

/**
 * Recount one user's visible post total from the posts table.
 *
 * Usage: settle counters after moderation visibility changes.
 * Referenced by: corebb_mod_settle_visible_counts().
 *
 * @param int $userId User id to recount.
 * @return void
 */
function corebb_mod_recount_user_posts(int $userId): void
{
    corebb_mod_ensure_schema();
    $count = (int)db_value('SELECT COUNT(*) FROM posts WHERE posterid = ? AND is_deleted = 0', [$userId], 0);
    db_run('UPDATE users SET posts = ? WHERE id = ?', [$count, $userId]);
}

/**
 * Count visible posts in a topic.
 *
 * Usage: compute reply counts and decide whether a topic should remain visible.
 * Referenced by: topic count refresh, delete, restore, and visibility repair
 * helpers.
 *
 * @param int $topicId Topic id to count.
 * @return int Number of non-deleted posts.
 */
function corebb_mod_visible_post_count_for_topic(int $topicId): int
{
    return (int)db_value('SELECT COUNT(*) FROM posts WHERE threadid = ? AND is_deleted = 0', [$topicId], 0);
}

/**
 * Count all posts in a topic, including deleted-bin posts.
 *
 * Usage: decide whether purging the last remaining post should remove the
 * topic row.
 * Referenced by: corebb_mod_purge_post().
 *
 * @param int $topicId Topic id to count.
 * @return int Total post rows for the topic.
 */
function corebb_mod_total_post_count_for_topic(int $topicId): int
{
    return (int)db_value('SELECT COUNT(*) FROM posts WHERE threadid = ?', [$topicId], 0);
}

/**
 * Refresh cached topic post/reply counts from visible posts.
 *
 * Usage: repair topic counters after visibility or restore operations.
 * Referenced by: topic refresh helpers.
 *
 * @param int $topicId Topic id to refresh.
 * @return void
 */
function corebb_mod_refresh_topic_counts(int $topicId): void
{
    corebb_mod_ensure_schema();
    if ($topicId <= 0) {
        return;
    }

    $postCount = corebb_mod_visible_post_count_for_topic($topicId);
    $replyCount = max(0, $postCount - 1);
    db_run('UPDATE topics SET postcount = ?, replycount = ? WHERE id = ?', [$postCount, $replyCount, $topicId]);
}

/**
 * Refresh cached board topic/post counts from visible forum content.
 *
 * Usage: repair a single board after moderation changes without running the
 * full admin rebuild.
 * Referenced by: corebb_mod_settle_visible_counts().
 *
 * @param int $boardId Board id to refresh.
 * @return void
 */
function corebb_mod_refresh_board_counts(int $boardId): void
{
    corebb_mod_ensure_schema();
    if ($boardId <= 0) {
        return;
    }

    // This is a targeted board-only resync, not a whole-site recount.  It is used
    // after moderation visibility changes so the index counters fall immediately
    // without waiting for the Administrator Tools rebuild.
    $topicCount = (int)db_value(
        'SELECT COUNT(DISTINCT t.id)
           FROM topics t
           INNER JOIN posts p ON p.threadid = t.id AND p.is_deleted = 0
          WHERE t.boardid = ? AND t.is_deleted = 0',
        [$boardId],
        0
    );
    $postCount = (int)db_value(
        'SELECT COUNT(*) FROM posts WHERE boardid = ? AND is_deleted = 0',
        [$boardId],
        0
    );

    db_run('UPDATE forums SET topiccount = ?, postcount = ? WHERE id = ?', [$topicCount, $postCount, $boardId]);
}


/**
 * Apply quick cached count deltas for one visibility-changing moderation event.
 *
 * Usage: keep topic, board, and user counters responsive inside delete/restore
 * transactions before the targeted recount settles exact values.
 * Referenced by: post/topic delete and post restore helpers.
 *
 * @param int $boardId Board id affected by the moderation event.
 * @param int $topicId Topic id affected by the moderation event.
 * @param int $posterId Post author id affected by the moderation event.
 * @param int $visiblePostDelta Change in visible post count.
 * @param int $visibleTopicDelta Change in visible topic count.
 * @return void
 */
function corebb_mod_apply_visible_count_delta(int $boardId, int $topicId, int $posterId, int $visiblePostDelta, int $visibleTopicDelta): void
{
    corebb_mod_ensure_schema();

    $visiblePostDelta = (int)$visiblePostDelta;
    $visibleTopicDelta = (int)$visibleTopicDelta;
    if ($visiblePostDelta === 0 && $visibleTopicDelta === 0) {
        return;
    }

    // Keep the cached counters in step with the moderation event that just changed visibility.
    // This avoids a full recount on every delete/restore while the Admin rebuild tool remains
    // available as the exact resync/repair path for already-stale counters.
    if ($topicId > 0 && $visiblePostDelta !== 0) {
        db_run(
            'UPDATE topics SET postcount = GREATEST(COALESCE(postcount, 0) + ?, 0) WHERE id = ?',
            [$visiblePostDelta, $topicId]
        );
        db_run(
            'UPDATE topics SET replycount = GREATEST(COALESCE(postcount, 0) - 1, 0) WHERE id = ?',
            [$topicId]
        );
    }

    if ($boardId > 0) {
        if ($visibleTopicDelta !== 0 || $visiblePostDelta !== 0) {
            db_run(
                'UPDATE forums
                    SET topiccount = GREATEST(COALESCE(topiccount, 0) + ?, 0),
                        postcount = GREATEST(COALESCE(postcount, 0) + ?, 0)
                  WHERE id = ?',
                [$visibleTopicDelta, $visiblePostDelta, $boardId]
            );
        }
    }

    if ($posterId > 0 && $visiblePostDelta !== 0) {
        db_run(
            'UPDATE users SET posts = GREATEST(COALESCE(posts, 0) + ?, 0) WHERE id = ?',
            [$visiblePostDelta, $posterId]
        );
    }
}

/**
 * Recount only the topic, board, and users affected by a moderation event.
 *
 * Usage: follow the transaction-local delta write with an exact targeted
 * recount, avoiding a full-site rebuild.
 * Referenced by: delete, restore, and purge helpers.
 *
 * @param int $boardId Board id to settle.
 * @param int $topicId Optional topic id to settle.
 * @param array<int, int> $userIds User ids to recount.
 * @return void
 */
function corebb_mod_settle_visible_counts(int $boardId, int $topicId = 0, array $userIds = []): void
{
    // Moderation visibility changes are rare compared to ordinary page loads,
    // so after the cheap delta update we also resync only the affected topic,
    // board, and users.  This avoids a whole-site rebuild while preventing
    // drift if counters were already stale or multiple moderators act close
    // together.
    corebb_mod_ensure_schema();

    if ($topicId > 0) {
        corebb_mod_refresh_topic_from_posts($topicId);
    }
    if ($boardId > 0) {
        corebb_mod_refresh_board_counts($boardId);
        corebb_mod_refresh_board_lastpost($boardId);
    }

    foreach (array_unique(array_map('intval', $userIds)) as $uid) {
        if ($uid > 0) {
            corebb_mod_recount_user_posts($uid);
        }
    }
}

/**
 * Refresh a board's cached last-post fields from visible posts.
 *
 * Usage: remove stale last-post pointers after deletes/restores affect a board.
 * Referenced by: corebb_mod_settle_visible_counts().
 *
 * @param int $boardId Board id to refresh.
 * @return void
 */
function corebb_mod_refresh_board_lastpost(int $boardId): void
{
    corebb_mod_ensure_schema();
    $latest = corebb_mod_fetch_one('SELECT id, posttime, posttimeraw FROM posts WHERE boardid = ? AND is_deleted = 0 ORDER BY CAST(posttimeraw AS UNSIGNED) DESC, id DESC LIMIT 1', [$boardId]);
    if ($latest) {
        db_run('UPDATE forums SET lastpstdate = ?, lastpstdatets = ? WHERE id = ?', [
            (string)($latest['posttime'] ?? ''),
            (string)($latest['posttimeraw'] ?? ''),
            $boardId,
        ]);
    } else {
        db_run("UPDATE forums SET lastpstdate = '', lastpstdatets = '' WHERE id = ?", [$boardId]);
    }
}

/**
 * Mark a topic deleted when no visible posts remain, or clear deletion metadata
 * when visible posts still exist.
 *
 * Usage: reconcile topic visibility after post-level changes.
 * Referenced by: corebb_mod_refresh_topic_from_posts().
 *
 * @param int $topicId Topic id to reconcile.
 * @param int $deletedBy Moderator id to record when hiding the topic.
 * @param string $deletedAt Deletion timestamp, or empty to use now.
 * @param string $reason Deletion reason to store.
 * @return void
 */
function corebb_mod_mark_topic_deleted_if_empty(int $topicId, int $deletedBy = 0, string $deletedAt = '', string $reason = ''): void
{
    corebb_mod_ensure_schema();
    if ($topicId <= 0) {
        return;
    }
    if (corebb_mod_visible_post_count_for_topic($topicId) > 0) {
        db_run("UPDATE topics SET is_deleted = 0, deleted_at = '', deleted_by = 0, delete_reason = NULL WHERE id = ?", [$topicId]);
        return;
    }

    $existing = corebb_mod_get_topic($topicId, true);
    if ($existing && (int)($existing['is_deleted'] ?? 0) === 1 && $deletedBy <= 0 && $deletedAt === '' && $reason === '') {
        return;
    }

    $deletedAt = $deletedAt !== '' ? $deletedAt : corebb_mod_now();
    db_run(
        'UPDATE topics SET is_deleted = 1, deleted_at = ?, deleted_by = ?, delete_reason = ? WHERE id = ?',
        [$deletedAt, $deletedBy, corebb_mod_limit_reason($reason), $topicId]
    );
}

/**
 * Rebuild topic denormalized fields from its first and latest visible posts.
 *
 * Usage: repair topic title/body/author/last-post/count metadata after edits,
 * deletes, restores, or admin interventions.
 * Referenced by: post_view_model.php, admin tools, and count settlement.
 *
 * @param int $topicId Topic id to rebuild.
 * @return void
 */
function corebb_mod_refresh_topic_from_posts(int $topicId): void
{
    corebb_mod_ensure_schema();
    $first = corebb_mod_fetch_one('SELECT id, posterid, title, body, boardid FROM posts WHERE threadid = ? AND is_deleted = 0 ORDER BY id ASC LIMIT 1', [$topicId]);
    $latest = corebb_mod_fetch_one('SELECT id, posttime, posttimeraw FROM posts WHERE threadid = ? AND is_deleted = 0 ORDER BY CAST(posttimeraw AS UNSIGNED) DESC, id DESC LIMIT 1', [$topicId]);
    if (!$first || !$latest) {
        corebb_mod_refresh_topic_counts($topicId);
        corebb_mod_mark_topic_deleted_if_empty($topicId);
        return;
    }

    $postCount = corebb_mod_visible_post_count_for_topic($topicId);
    $replyCount = max(0, $postCount - 1);
    db_run(
        "UPDATE topics SET title = ?, body = ?, posterid = ?, boardid = ?, lastpost = ?, now = ?, postcount = ?, replycount = ?, is_deleted = 0, deleted_at = '', deleted_by = 0, delete_reason = NULL WHERE id = ?",
        [
            (string)($first['title'] ?? ''),
            (string)($first['body'] ?? ''),
            (int)($first['posterid'] ?? 0),
            (int)($first['boardid'] ?? 0),
            (string)($latest['posttime'] ?? ''),
            (string)($latest['posttimeraw'] ?? ''),
            $postCount,
            $replyCount,
            $topicId,
        ]
    );
}

/**
 * Move one visible post to the deleted-posts bin.
 *
 * Usage: public moderator/API/admin action for soft-deleting a post while
 * preserving restore and audit metadata.
 * Referenced by: moderator view model, API moderation endpoint, admin
 * mod-request/user tools, and first-post delete escalation.
 *
 * @param int $postId Post id to soft-delete.
 * @param string $reason Moderator-provided reason.
 * @return array{ok: bool, message: string, redirect: string} Operation result.
 */
function corebb_mod_remove_post(int $postId, string $reason = ''): array
{
    corebb_mod_ensure_schema();
    $post = corebb_mod_get_post($postId, true);
    if (!$post) {
        return ['ok' => false, 'message' => 'Unknown post ID.', 'redirect' => 'index.php'];
    }
    if ((int)($post['is_deleted'] ?? 0) === 1) {
        return ['ok' => true, 'message' => 'Post is already in the deleted-posts bin.', 'redirect' => '/admin/?act=deleted_posts'];
    }
    if (!corebb_secure_archive_user_can_modify_post_id($postId, corebb_mod_actor_level())) {
        return ['ok' => false, 'message' => corebb_secure_archive_denied_message(), 'redirect' => corebb_mod_thread_redirect((int)($post['threadid'] ?? 0))];
    }

    $topicId = (int)($post['threadid'] ?? 0);
    $boardId = (int)($post['boardid'] ?? 0);
    if ($boardId <= 0 && $topicId > 0) {
        $boardId = (int)db_value('SELECT boardid FROM topics WHERE id = ? LIMIT 1', [$topicId], 0);
    }
    $posterId = (int)($post['posterid'] ?? 0);
    $rankError = corebb_mod_author_rank_error($posterId);
    if ($rankError !== '') {
        return ['ok' => false, 'message' => $rankError, 'redirect' => corebb_mod_thread_redirect($topicId)];
    }
    $topic = corebb_mod_get_topic($topicId, true);
    $visiblePostsBefore = corebb_mod_visible_post_count_for_topic($topicId);
    $reason = corebb_mod_limit_reason($reason);
    $firstVisiblePostId = (int)db_value(
        'SELECT id FROM posts WHERE threadid = ? AND is_deleted = 0 ORDER BY id ASC LIMIT 1',
        [$topicId],
        0
    );
    // In CoreBB the first visible post is the topic anchor. Removing it should
    // move the whole thread to the bin, not leave orphaned replies behind.
    if ($firstVisiblePostId === $postId) {
        return corebb_mod_remove_topic($topicId, $reason);
    }

    $topicWasVisible = $topic && ((int)($topic['is_deleted'] ?? 0) === 0) && $visiblePostsBefore > 0;
    $topicDelta = ($topicWasVisible && $visiblePostsBefore <= 1) ? -1 : 0;
    $deletedAt = corebb_mod_now();
    $deletedBy = corebb_mod_current_user_id();

    if (!db_begin()) {
        return ['ok' => false, 'message' => 'Could not start delete transaction: ' . db_error(), 'redirect' => corebb_mod_thread_redirect($topicId)];
    }
    $ok = db_run(
        'UPDATE posts SET is_deleted = 1, deleted_at = ?, deleted_by = ?, delete_reason = ? WHERE id = ? AND is_deleted = 0',
        [$deletedAt, $deletedBy, $reason, $postId]
    );
    if (!$ok) {
        db_rollback();
        return ['ok' => false, 'message' => 'Error moving post to deleted-posts bin: ' . db_error(), 'redirect' => corebb_mod_thread_redirect($topicId)];
    }
    if ($visiblePostsBefore <= 1) {
        db_run('UPDATE topics SET is_deleted = 1, deleted_at = ?, deleted_by = ?, delete_reason = ? WHERE id = ?', [$deletedAt, $deletedBy, $reason, $topicId]);
    }
    corebb_mod_apply_visible_count_delta($boardId, $topicId, $posterId, -1, $topicDelta);
    db_commit();

    corebb_mod_settle_visible_counts($boardId, $topicId, [$posterId]);
    corebb_notifications_notify_moderated_post($post, 'deleted', $deletedBy, corebb_mod_current_username(), $reason);
    corebb_mod_log("Moved post {$postId} from topic {$topicId} to deleted-posts bin");

    if ($visiblePostsBefore <= 1) {
        return ['ok' => true, 'message' => 'The only visible post in the topic was moved to the deleted-posts bin, so the topic is hidden until restored.', 'redirect' => corebb_mod_board_redirect($boardId)];
    }
    return ['ok' => true, 'message' => 'Post moved to the deleted-posts bin.', 'redirect' => corebb_mod_thread_redirect($topicId)];
}

/**
 * Move an entire topic and its visible posts to the deleted-posts bin.
 *
 * Usage: public moderator/API/admin action for soft-deleting a topic, including
 * escalation from deleting the first visible post.
 * Referenced by: post removal, admin mod requests, and moderator/API workflows.
 *
 * @param int $topicId Topic id to soft-delete.
 * @param string $reason Moderator-provided reason.
 * @return array{ok: bool, message: string, redirect: string} Operation result.
 */
function corebb_mod_remove_topic(int $topicId, string $reason = ''): array
{
    corebb_mod_ensure_schema();
    $topic = corebb_mod_get_topic($topicId, true);
    if (!$topic) {
        return ['ok' => false, 'message' => 'Unknown topic ID.', 'redirect' => 'index.php'];
    }

    $boardId = (int)($topic['boardid'] ?? 0);
    if (!corebb_secure_archive_user_can_write_board_id($boardId, corebb_mod_actor_level())) {
        return ['ok' => false, 'message' => corebb_secure_archive_denied_message(), 'redirect' => corebb_mod_thread_redirect($topicId)];
    }
    $rankError = corebb_mod_topic_rank_error($topicId);
    if ($rankError !== '') {
        return ['ok' => false, 'message' => $rankError, 'redirect' => corebb_mod_thread_redirect($topicId)];
    }
    $visiblePostsBefore = corebb_mod_visible_post_count_for_topic($topicId);
    $topicWasVisible = ((int)($topic['is_deleted'] ?? 0) === 0) && $visiblePostsBefore > 0;
    $affectedUsers = array_filter(array_map('intval', db_column('SELECT DISTINCT posterid FROM posts WHERE threadid = ? AND is_deleted = 0', [$topicId])));
    $firstPost = db_one('SELECT id, posterid, author, title, body, threadid, boardid, ptd, posttime, posttimeraw, postip, is_deleted FROM posts WHERE threadid = ? AND is_deleted = 0 ORDER BY id ASC LIMIT 1', [$topicId]);
    $deletedAt = corebb_mod_now();
    $deletedBy = corebb_mod_current_user_id();
    $reason = corebb_mod_limit_reason($reason);

    if (!db_begin()) {
        return ['ok' => false, 'message' => 'Could not start topic delete transaction: ' . db_error(), 'redirect' => corebb_mod_thread_redirect($topicId)];
    }
    $postsOk = db_run(
        'UPDATE posts SET is_deleted = 1, deleted_at = ?, deleted_by = ?, delete_reason = ? WHERE threadid = ? AND is_deleted = 0',
        [$deletedAt, $deletedBy, $reason, $topicId]
    );
    $topicOk = db_run('UPDATE topics SET is_deleted = 1, deleted_at = ?, deleted_by = ?, delete_reason = ?, postcount = 0, replycount = 0 WHERE id = ?', [$deletedAt, $deletedBy, $reason, $topicId]);
    if (!$postsOk || !$topicOk) {
        db_rollback();
        return ['ok' => false, 'message' => 'Error moving topic to deleted-posts bin: ' . db_error(), 'redirect' => corebb_mod_thread_redirect($topicId)];
    }
    corebb_mod_apply_visible_count_delta($boardId, $topicId, 0, -$visiblePostsBefore, $topicWasVisible ? -1 : 0);
    db_commit();

    corebb_mod_settle_visible_counts($boardId, 0, $affectedUsers);
    if ($firstPost) {
        corebb_notifications_notify_moderated_post($firstPost, 'deleted', $deletedBy, corebb_mod_current_username(), $reason);
    }
    corebb_mod_log("Moved topic {$topicId} to deleted-posts bin");
    return ['ok' => true, 'message' => 'Topic moved to the deleted-posts bin.', 'redirect' => corebb_mod_board_redirect($boardId)];
}

/**
 * Restore a deleted-bin post, or the whole topic when restoring its first post.
 *
 * Usage: admin/API restore path for bringing hidden content back into normal
 * board visibility.
 * Referenced by: API moderation endpoint and admin deleted-posts tools.
 *
 * @param int $postId Deleted-bin post id to restore.
 * @return array{ok: bool, message: string, redirect: string} Operation result.
 */
function corebb_mod_restore_post(int $postId): array
{
    corebb_mod_ensure_schema();
    $post = corebb_mod_get_post($postId, true);
    if (!$post) {
        return ['ok' => false, 'message' => 'Unknown deleted post ID.', 'redirect' => '/admin/?act=deleted_posts'];
    }
    if ((int)($post['is_deleted'] ?? 0) !== 1) {
        return ['ok' => true, 'message' => 'That post is already visible.', 'redirect' => corebb_mod_thread_redirect((int)($post['threadid'] ?? 0))];
    }
    if (!corebb_secure_archive_user_can_modify_post_id($postId, corebb_mod_actor_level())) {
        return ['ok' => false, 'message' => corebb_secure_archive_denied_message(), 'redirect' => '/admin/?act=deleted_posts'];
    }

    $topicId = (int)($post['threadid'] ?? 0);
    $boardId = (int)($post['boardid'] ?? 0);
    if ($boardId <= 0 && $topicId > 0) {
        $boardId = (int)db_value('SELECT boardid FROM topics WHERE id = ? LIMIT 1', [$topicId], 0);
    }
    $posterId = (int)($post['posterid'] ?? 0);
    $rankError = corebb_mod_author_rank_error($posterId);
    if ($rankError !== '') {
        return ['ok' => false, 'message' => $rankError, 'redirect' => '/admin/?act=deleted_posts'];
    }
    $topic = corebb_mod_get_topic($topicId, true);
    if (!$topic) {
        return ['ok' => false, 'message' => 'The parent topic no longer exists, so this post cannot be restored safely.', 'redirect' => '/admin/?act=deleted_posts'];
    }
    $firstTopicPostId = (int)db_value(
        'SELECT id FROM posts WHERE threadid = ? ORDER BY id ASC LIMIT 1',
        [$topicId],
        0
    );
    if ($firstTopicPostId === $postId && (int)($topic['is_deleted'] ?? 0) === 1) {
        $affectedUsers = array_filter(array_map('intval', db_column('SELECT DISTINCT posterid FROM posts WHERE threadid = ?', [$topicId])));
        if (!db_begin()) {
            return ['ok' => false, 'message' => 'Could not start topic restore transaction: ' . db_error(), 'redirect' => '/admin/?act=deleted_posts'];
        }
        // Restoring the original post restores the topic as a unit. That mirrors
        // the delete path and prevents a thread from coming back with replies
        // still stranded in the bin.
        $postsOk = db_run("UPDATE posts SET is_deleted = 0, deleted_at = '', deleted_by = 0, delete_reason = NULL WHERE threadid = ? AND is_deleted = 1", [$topicId]);
        $topicOk = db_run("UPDATE topics SET is_deleted = 0, deleted_at = '', deleted_by = 0, delete_reason = NULL WHERE id = ?", [$topicId]);
        if (!$postsOk || !$topicOk) {
            db_rollback();
            return ['ok' => false, 'message' => 'Error restoring topic: ' . db_error(), 'redirect' => '/admin/?act=deleted_posts'];
        }
        db_commit();

        corebb_mod_settle_visible_counts($boardId, $topicId, $affectedUsers);
        corebb_mod_log("Restored deleted topic {$topicId} from original post {$postId}");
        return ['ok' => true, 'message' => 'Topic restored with all deleted posts.', 'redirect' => corebb_mod_thread_redirect($topicId, $postId)];
    }

    $visiblePostsBefore = corebb_mod_visible_post_count_for_topic($topicId);
    $topicWasVisible = ((int)($topic['is_deleted'] ?? 0) === 0) && $visiblePostsBefore > 0;
    $topicDelta = $topicWasVisible ? 0 : 1;

    if (!db_begin()) {
        return ['ok' => false, 'message' => 'Could not start restore transaction: ' . db_error(), 'redirect' => '/admin/?act=deleted_posts'];
    }
    $ok = db_run("UPDATE posts SET is_deleted = 0, deleted_at = '', deleted_by = 0, delete_reason = NULL WHERE id = ?", [$postId]);
    $topicOk = db_run("UPDATE topics SET is_deleted = 0, deleted_at = '', deleted_by = 0, delete_reason = NULL WHERE id = ?", [$topicId]);
    if (!$ok || !$topicOk) {
        db_rollback();
        return ['ok' => false, 'message' => 'Error restoring post: ' . db_error(), 'redirect' => '/admin/?act=deleted_posts'];
    }
    corebb_mod_apply_visible_count_delta($boardId, $topicId, $posterId, 1, $topicDelta);
    db_commit();

    corebb_mod_settle_visible_counts($boardId, $topicId, [$posterId]);
    corebb_mod_log("Restored deleted post {$postId} to topic {$topicId}");
    return ['ok' => true, 'message' => 'Post restored.', 'redirect' => corebb_mod_thread_redirect($topicId, $postId)];
}

/**
 * Permanently delete one already-hidden post from the database.
 *
 * Usage: admin deleted-posts cleanup action after soft deletion.
 * Referenced by: admin_deleted_posts_view_model.php.
 *
 * @param int $postId Deleted-bin post id to purge.
 * @return array{ok: bool, message: string, redirect: string} Operation result.
 */
function corebb_mod_purge_post(int $postId): array
{
    corebb_mod_ensure_schema();
    $post = corebb_mod_get_post($postId, true);
    if (!$post) {
        return ['ok' => false, 'message' => 'Unknown deleted post ID.', 'redirect' => '/admin/?act=deleted_posts'];
    }
    if ((int)($post['is_deleted'] ?? 0) !== 1) {
        return ['ok' => false, 'message' => 'Only deleted-bin posts can be permanently purged.', 'redirect' => '/admin/?act=deleted_posts'];
    }
    if (!corebb_secure_archive_user_can_modify_post_id($postId, corebb_mod_actor_level())) {
        return ['ok' => false, 'message' => corebb_secure_archive_denied_message(), 'redirect' => '/admin/?act=deleted_posts'];
    }

    $topicId = (int)($post['threadid'] ?? 0);
    $boardId = (int)($post['boardid'] ?? 0);
    $posterId = (int)($post['posterid'] ?? 0);
    $rankError = corebb_mod_author_rank_error($posterId);
    if ($rankError !== '') {
        return ['ok' => false, 'message' => $rankError, 'redirect' => '/admin/?act=deleted_posts'];
    }

    if (!db_begin()) {
        return ['ok' => false, 'message' => 'Could not start purge transaction: ' . db_error(), 'redirect' => '/admin/?act=deleted_posts'];
    }
    if (!db_run('DELETE FROM posts WHERE id = ? AND is_deleted = 1', [$postId])) {
        db_rollback();
        return ['ok' => false, 'message' => 'Error purging post: ' . db_error(), 'redirect' => '/admin/?act=deleted_posts'];
    }
    $remainingTotal = corebb_mod_total_post_count_for_topic($topicId);
    if ($remainingTotal <= 0) {
        db_run('DELETE FROM topics WHERE id = ?', [$topicId]);
    }
    db_commit();

    if ($remainingTotal > 0) {
        corebb_mod_settle_visible_counts($boardId, $topicId);
    } else {
        corebb_mod_settle_visible_counts($boardId);
    }
    corebb_mod_log("Permanently purged deleted post {$postId} from topic {$topicId}");
    return ['ok' => true, 'message' => 'Deleted post permanently purged.', 'redirect' => '/admin/?act=deleted_posts'];
}

/**
 * Lock or unlock a topic.
 *
 * Usage: moderator/API action for changing reply availability on a topic.
 * Referenced by: moderator view model and API moderation endpoint.
 *
 * @param int $topicId Topic id to update.
 * @param int $locked Truthy value locks the topic; falsey value unlocks it.
 * @return array{ok: bool, message: string, redirect: string} Operation result.
 */
function corebb_mod_set_topic_locked(int $topicId, int $locked): array
{
    corebb_mod_ensure_schema();
    $topic = corebb_mod_get_topic($topicId, true);
    if (!$topic) {
        return ['ok' => false, 'message' => 'Unknown topic ID.', 'redirect' => 'index.php'];
    }
    if (!corebb_secure_archive_user_can_write_board_id((int)($topic['boardid'] ?? 0), corebb_mod_actor_level())) {
        return ['ok' => false, 'message' => corebb_secure_archive_denied_message(), 'redirect' => corebb_mod_thread_redirect($topicId)];
    }
    db_run('UPDATE topics SET locked = ? WHERE id = ?', [$locked ? 1 : 0, $topicId]);
    corebb_mod_log(($locked ? 'Locked' : 'Unlocked') . " topic {$topicId}");
    return ['ok' => true, 'message' => $locked ? 'Topic locked.' : 'Topic unlocked.', 'redirect' => corebb_mod_thread_redirect($topicId)];
}

/**
 * Mark a topic sticky or remove its sticky flag.
 *
 * Usage: moderator edit action attached to the post edit workflow.
 * Referenced by: post_view_model.php.
 *
 * @param int $topicId Topic id to update.
 * @param int $sticky Truthy value makes the topic sticky; falsey clears it.
 * @return array{ok: bool, message: string, redirect: string} Operation result.
 */
function corebb_mod_set_topic_sticky(int $topicId, int $sticky): array
{
    corebb_mod_ensure_schema();
    $topic = corebb_mod_get_topic($topicId, true);
    if (!$topic) {
        return ['ok' => false, 'message' => 'Unknown topic ID.', 'redirect' => 'index.php'];
    }
    if (!corebb_secure_archive_user_can_write_board_id((int)($topic['boardid'] ?? 0), corebb_mod_actor_level())) {
        return ['ok' => false, 'message' => corebb_secure_archive_denied_message(), 'redirect' => corebb_mod_thread_redirect($topicId)];
    }

    $rankError = corebb_mod_topic_rank_error($topicId);
    if ($rankError !== '') {
        return ['ok' => false, 'message' => $rankError, 'redirect' => corebb_mod_thread_redirect($topicId)];
    }

    $newState = $sticky ? 1 : 0;
    $currentState = (int)($topic['sticky'] ?? 0) === 1 ? 1 : 0;
    if ($newState === $currentState) {
        return ['ok' => true, 'message' => $newState ? 'Topic already sticky.' : 'Topic already unsticky.', 'redirect' => corebb_mod_thread_redirect($topicId)];
    }

    if (!db_run('UPDATE topics SET sticky = ? WHERE id = ?', [$newState, $topicId])) {
        return ['ok' => false, 'message' => 'Error updating sticky state: ' . db_error(), 'redirect' => corebb_mod_thread_redirect($topicId)];
    }

    corebb_mod_log(($newState ? 'Stickied' : 'Unstickied') . " topic {$topicId}");
    return ['ok' => true, 'message' => $newState ? 'Topic stickied.' : 'Topic unstickied.', 'redirect' => corebb_mod_thread_redirect($topicId)];
}

/**
 * Ban a lower-ranked user and demote the account to normal user access.
 *
 * Usage: moderator/API action from profiles or post moderation.
 * Referenced by: moderator view model and API moderation endpoint.
 *
 * @param int $userId User id to ban.
 * @param string $reason Moderator-provided reason.
 * @return array{ok: bool, message: string, redirect: string} Operation result.
 */
function corebb_mod_ban_user(int $userId, string $reason = ''): array
{
    global $userlogindata_a;
    $target = corebb_mod_get_user($userId);
    if (!$target) {
        return ['ok' => false, 'message' => 'Unknown user ID.', 'redirect' => 'index.php'];
    }
    $currentId = (int)($userlogindata_a['id'] ?? 0);
    $currentLevel = (int)($userlogindata_a['accesslevel'] ?? 0);
    $targetLevel = (int)($target['accesslevel'] ?? 0);
    if ($userId === $currentId) {
        return ['ok' => false, 'message' => 'You cannot ban yourself.', 'redirect' => "content.php?action=profile&id={$userId}"];
    }
    if ($targetLevel >= $currentLevel) {
        return ['ok' => false, 'message' => 'You cannot ban a user with equal or higher rights.', 'redirect' => "content.php?action=profile&id={$userId}"];
    }
    if ((string)($target['status'] ?? '') === '2') {
        return ['ok' => true, 'message' => 'That user is already banned.', 'redirect' => "content.php?action=profile&id={$userId}"];
    }

    $sets = ['status = 2', 'accesslevel = 1'];
    $params = [];
    $reason = corebb_mod_limit_reason($reason);
    if (corebb_mod_column_exists('users', 'ban_reason')) {
        $sets[] = 'ban_reason = ?';
        $params[] = $reason;
    }
    if (corebb_mod_column_exists('users', 'banned_at')) {
        $sets[] = 'banned_at = ?';
        $params[] = corebb_mod_now();
    }
    if (corebb_mod_column_exists('users', 'banned_by')) {
        $sets[] = 'banned_by = ?';
        $params[] = $currentId;
    }
    if (corebb_mod_column_exists('users', 'unbanned_at')) {
        $sets[] = "unbanned_at = ''";
    }
    if (corebb_mod_column_exists('users', 'unbanned_by')) {
        $sets[] = 'unbanned_by = 0';
    }
    $params[] = $userId;

    if (!db_run('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params)) {
        return ['ok' => false, 'message' => 'Error banning user: ' . db_error(), 'redirect' => "content.php?action=profile&id={$userId}"];
    }

    $log = 'Banned user ' . (string)($target['username'] ?? $userId) . " ({$userId}) and demoted to User";
    if ($reason !== '') {
        $log .= ': ' . $reason;
    }
    corebb_mod_log($log);
    return ['ok' => true, 'message' => 'User banned and demoted to User.', 'redirect' => "content.php?action=profile&id={$userId}"];
}

/**
 * Remove the banned status from a lower-ranked user.
 *
 * Usage: moderator/API action for restoring account access after a ban.
 * Referenced by: API moderation endpoint.
 *
 * @param int $userId User id to unban.
 * @param string $note Optional moderator note.
 * @return array{ok: bool, message: string, redirect: string} Operation result.
 */
function corebb_mod_unban_user(int $userId, string $note = ''): array
{
    global $userlogindata_a;
    $target = corebb_mod_get_user($userId);
    if (!$target) {
        return ['ok' => false, 'message' => 'Unknown user ID.', 'redirect' => 'index.php'];
    }

    $currentLevel = (int)($userlogindata_a['accesslevel'] ?? 0);
    $targetLevel = (int)($target['accesslevel'] ?? 0);
    if ($targetLevel >= $currentLevel) {
        return ['ok' => false, 'message' => 'You cannot moderate a user with equal or higher rights.', 'redirect' => "content.php?action=profile&id={$userId}"];
    }
    if ((string)($target['status'] ?? '') !== '2') {
        return ['ok' => true, 'message' => 'That user is not currently banned.', 'redirect' => "content.php?action=profile&id={$userId}"];
    }

    $sets = ['status = 0'];
    $params = [];
    if (corebb_mod_column_exists('users', 'unbanned_at')) {
        $sets[] = 'unbanned_at = ?';
        $params[] = corebb_mod_now();
    }
    if (corebb_mod_column_exists('users', 'unbanned_by')) {
        $sets[] = 'unbanned_by = ?';
        $params[] = (int)($userlogindata_a['id'] ?? 0);
    }
    $params[] = $userId;

    if (!db_run('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params)) {
        return ['ok' => false, 'message' => 'Error unbanning user: ' . db_error(), 'redirect' => "content.php?action=profile&id={$userId}"];
    }

    $note = corebb_mod_limit_reason($note);
    $log = 'Unbanned user ' . (string)($target['username'] ?? $userId) . " ({$userId})";
    if ($note !== '') {
        $log .= ': ' . $note;
    }
    corebb_mod_log($log);
    return ['ok' => true, 'message' => 'User unbanned.', 'redirect' => "content.php?action=profile&id={$userId}"];
}
?>
