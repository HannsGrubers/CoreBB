<?php
require_once __DIR__ . '/corebb_date_helpers.php';
require_once __DIR__ . '/rate_limit_helpers.php';
/**
 * Private-message helpers for the PHP 8 migration.
 *
 * The original PM code interpolated raw POST values into SQL. These helpers keep
 * the old one-row-per-recipient message model while using prepared statements
 * for the code paths we have touched.
 */



/**
 * Usage: Quote a trusted table/alias/column identifier for PM SQL snippets.
 * Referenced by: PM schema, visibility, and optional-column helpers.
 *
 * @param string $identifier Database identifier containing only letters, numbers, or underscores.
 * @return string Backtick-quoted identifier.
 * @throws InvalidArgumentException When the identifier contains unsafe characters.
 */
function corebb_pm_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Usage: Check whether a PM-related table exists before migration/query work.
 * Referenced by: PM schema and moderation capability checks.
 *
 * @param string $table Table name to check.
 * @return bool True when the table exists in the current database.
 */
function corebb_pm_table_exists(string $table): bool
{
    corebb_pm_identifier($table);
    return db_exists(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
        [$table]
    );
}

/**
 * Usage: Check whether a PM-related table has an optional column.
 * Referenced by: soft-delete, moderation, and optional SELECT helpers.
 *
 * @param string $table Table name to inspect.
 * @param string $column Column name to inspect.
 * @return bool True when the column exists.
 */
function corebb_pm_column_exists(string $table, string $column): bool
{
    corebb_pm_identifier($table);
    if ($column === '' || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }
    return db_exists(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [$table, $column]
    );
}

/**
 * Usage: Check whether a PM-related index already exists.
 * Referenced by: corebb_pm_try_index().
 *
 * @param string $table Table name to inspect.
 * @param string $index Index name to inspect.
 * @return bool True when the index exists.
 */
function corebb_pm_index_exists(string $table, string $index): bool
{
    corebb_pm_identifier($table);
    if ($index === '' || !preg_match('/^[A-Za-z0-9_]+$/', $index)) {
        return false;
    }
    return db_exists(
        'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
        [$table, $index]
    );
}

/**
 * Usage: Create an index only when it is missing.
 * Referenced by: corebb_pm_ensure_moderation_schema().
 *
 * @param string $table Table name being indexed.
 * @param string $index Expected index name.
 * @param string $sql ALTER TABLE statement that creates the index.
 * @return void
 */
function corebb_pm_try_index(string $table, string $index, string $sql): void
{
    if (!corebb_pm_index_exists($table, $index)) {
        db_run($sql);
    }
}


/**
 * Usage: Detect whether the privatemessages table supports soft deletion.
 * Referenced by: PM list, count, view, and moderation queries.
 *
 * @return bool True when the soft-delete column is available.
 */
function corebb_pm_soft_delete_supported(): bool
{
    return corebb_pm_table_exists('privatemessages')
        && corebb_pm_column_exists('privatemessages', 'is_deleted');
}

/**
 * Usage: Build the SQL visibility condition for non-deleted PM rows.
 * Referenced by: PM list, count, view, and report queries.
 *
 * @param string $alias Optional table alias to prefix.
 * @return string SQL condition without a leading WHERE/AND.
 */
function corebb_pm_visible_condition(string $alias = ''): string
{
    if (!corebb_pm_soft_delete_supported()) {
        return '1 = 1';
    }
    $prefix = $alias !== '' ? corebb_pm_identifier($alias) . '.' : '';
    return "COALESCE({$prefix}`is_deleted`, 0) <> 1";
}

/**
 * Usage: Detect whether PM rows can record the admin/user who deleted them.
 * Referenced by: moderation and delete metadata display helpers.
 *
 * @return bool True when deleted_by exists.
 */
function corebb_pm_deleted_by_supported(): bool
{
    return corebb_pm_table_exists('privatemessages')
        && corebb_pm_column_exists('privatemessages', 'deleted_by');
}

/**
 * Usage: Build a SELECT fragment for optional PM delete metadata columns.
 * Referenced by: PM view and report lookup queries.
 *
 * @param string $alias Optional table alias to prefix.
 * @return string SQL SELECT fragment with stable aliases.
 */
function corebb_pm_deleted_select_columns(string $alias = ''): string
{
    $prefix = $alias !== '' ? corebb_pm_identifier($alias) . '.' : '';
    $isDeleted = corebb_pm_column_exists('privatemessages', 'is_deleted')
        ? "COALESCE({$prefix}`is_deleted`, 0) AS is_deleted"
        : '0 AS is_deleted';
    $deletedAt = corebb_pm_column_exists('privatemessages', 'deleted_at')
        ? "COALESCE({$prefix}`deleted_at`, '') AS deleted_at"
        : "'' AS deleted_at";
    $deletedBy = corebb_pm_column_exists('privatemessages', 'deleted_by')
        ? "COALESCE({$prefix}`deleted_by`, 0) AS deleted_by"
        : '0 AS deleted_by';
    $deleteReason = corebb_pm_column_exists('privatemessages', 'delete_reason')
        ? "COALESCE({$prefix}`delete_reason`, '') AS delete_reason"
        : "'' AS delete_reason";
    return $isDeleted . ', ' . $deletedAt . ', ' . $deletedBy . ', ' . $deleteReason;
}

/**
 * Usage: Create/upgrade PM moderation support tables and columns.
 * Referenced by: report, count, folder, mark-read, and view helpers.
 *
 * @return void
 */
function corebb_pm_ensure_moderation_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!corebb_pm_table_exists('privatemessages')) {
        return;
    }

    $pmColumns = [
        'is_deleted' => "ALTER TABLE `privatemessages` ADD `is_deleted` TINYINT(1) NOT NULL DEFAULT 0",
        'deleted_at' => "ALTER TABLE `privatemessages` ADD `deleted_at` VARCHAR(64) NOT NULL DEFAULT ''",
        'deleted_by' => "ALTER TABLE `privatemessages` ADD `deleted_by` INT(11) NOT NULL DEFAULT 0",
        'delete_reason' => "ALTER TABLE `privatemessages` ADD `delete_reason` TEXT NULL",
    ];
    foreach ($pmColumns as $column => $sql) {
        if (!corebb_pm_column_exists('privatemessages', $column)) {
            db_run($sql);
        }
    }

    if (corebb_pm_soft_delete_supported()) {
        corebb_pm_try_index('privatemessages', 'idx_pm_deleted_receiver_read', 'ALTER TABLE `privatemessages` ADD KEY `idx_pm_deleted_receiver_read` (`recieveid`, `is_deleted`, `markread`, `id`)');
        corebb_pm_try_index('privatemessages', 'idx_pm_deleted_sender', 'ALTER TABLE `privatemessages` ADD KEY `idx_pm_deleted_sender` (`senderid`, `is_deleted`, `id`)');
    }

    db_run("CREATE TABLE IF NOT EXISTS `pm_reports` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `pmid` INT(11) NOT NULL DEFAULT 0,
        `reporterid` INT(11) NOT NULL DEFAULT 0,
        `reported_userid` INT(11) NOT NULL DEFAULT 0,
        `senderid` INT(11) NOT NULL DEFAULT 0,
        `recieveid` INT(11) NOT NULL DEFAULT 0,
        `reason_type` VARCHAR(64) NOT NULL DEFAULT '',
        `comments` TEXT NULL,
        `severity` TINYINT(3) NOT NULL DEFAULT 1,
        `status` VARCHAR(32) NOT NULL DEFAULT 'new',
        `created_at` VARCHAR(64) NOT NULL DEFAULT '',
        `created_ip` VARCHAR(64) NOT NULL DEFAULT '',
        `handled_by` INT(11) NOT NULL DEFAULT 0,
        `handled_at` VARCHAR(64) NOT NULL DEFAULT '',
        `handler_note` TEXT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_pm_reports_status` (`status`),
        KEY `idx_pm_reports_pmid` (`pmid`),
        KEY `idx_pm_reports_reporter` (`reporterid`),
        KEY `idx_pm_reports_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $reportColumns = [
        'pmid' => "ALTER TABLE `pm_reports` ADD `pmid` INT(11) NOT NULL DEFAULT 0",
        'reporterid' => "ALTER TABLE `pm_reports` ADD `reporterid` INT(11) NOT NULL DEFAULT 0",
        'reported_userid' => "ALTER TABLE `pm_reports` ADD `reported_userid` INT(11) NOT NULL DEFAULT 0",
        'senderid' => "ALTER TABLE `pm_reports` ADD `senderid` INT(11) NOT NULL DEFAULT 0",
        'recieveid' => "ALTER TABLE `pm_reports` ADD `recieveid` INT(11) NOT NULL DEFAULT 0",
        'reason_type' => "ALTER TABLE `pm_reports` ADD `reason_type` VARCHAR(64) NOT NULL DEFAULT ''",
        'comments' => "ALTER TABLE `pm_reports` ADD `comments` TEXT NULL",
        'severity' => "ALTER TABLE `pm_reports` ADD `severity` TINYINT(3) NOT NULL DEFAULT 1",
        'status' => "ALTER TABLE `pm_reports` ADD `status` VARCHAR(32) NOT NULL DEFAULT 'new'",
        'created_at' => "ALTER TABLE `pm_reports` ADD `created_at` VARCHAR(64) NOT NULL DEFAULT ''",
        'created_ip' => "ALTER TABLE `pm_reports` ADD `created_ip` VARCHAR(64) NOT NULL DEFAULT ''",
        'handled_by' => "ALTER TABLE `pm_reports` ADD `handled_by` INT(11) NOT NULL DEFAULT 0",
        'handled_at' => "ALTER TABLE `pm_reports` ADD `handled_at` VARCHAR(64) NOT NULL DEFAULT ''",
        'handler_note' => "ALTER TABLE `pm_reports` ADD `handler_note` TEXT NULL",
    ];
    foreach ($reportColumns as $column => $sql) {
        if (!corebb_pm_column_exists('pm_reports', $column)) {
            db_run($sql);
        }
    }
}

/**
 * Usage: Read the current request IP for PM report metadata.
 * Referenced by: corebb_pm_report_private_message().
 *
 * @return string Remote IP capped for storage.
 */
function corebb_pm_current_ip(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? substr($ip, 0, 64) : 'Unknown';
}

/**
 * Usage: Submit a report for a private message visible to the current viewer.
 * Referenced by: corebb_pm_view_model().
 *
 * @param array<string, mixed> $viewer Current logged-in user row.
 * @param int $pmId Private message id to report.
 * @param string $reason Report reason code from the form.
 * @param int $severity Severity from 1 through 5.
 * @param string $comments User-entered report details.
 * @return array{ok: bool, message: string} Result message for the PM view.
 */
function corebb_pm_report_private_message(array $viewer, int $pmId, string $reason, int $severity, string $comments): array
{
    corebb_pm_ensure_moderation_schema();
    $viewerId = corebb_pm_user_id($viewer);
    if ($viewerId <= 0) {
        return ['ok' => false, 'message' => 'You must be logged in to report a private message.'];
    }
    $pm = db_one(
        'SELECT id, senderid, recieveid, title, message, ' . corebb_pm_deleted_select_columns() . ' FROM privatemessages WHERE id = ? AND (senderid = ? OR recieveid = ?) LIMIT 1',
        [$pmId, $viewerId, $viewerId]
    );
    if (!$pm || (int)($pm['is_deleted'] ?? 0) === 1) {
        return ['ok' => false, 'message' => 'Unknown private message ID.'];
    }
    $reason = trim($reason);
    $allowed = ['TOS_Violation', 'Language', 'Spamming', 'Harassment', 'Personal_Attack', 'Threats', 'Other'];
    if (!in_array($reason, $allowed, true)) {
        $reason = 'Other';
    }
    $severity = max(1, min(5, $severity));
    $comments = corebb_pm_limit_text($comments, 65535);
    if ($comments === '') {
        return ['ok' => false, 'message' => 'Please enter a brief reason for the report.'];
    }
    $dupe = db_one("SELECT id FROM pm_reports WHERE pmid = ? AND reporterid = ? AND status = 'new' LIMIT 1", [$pmId, $viewerId]);
    if ($dupe) {
        return ['ok' => true, 'message' => 'You have already reported this private message.'];
    }
    $rate = corebb_rate_limit_report_submit($viewer);
    if (empty($rate['allowed'])) {
        return ['ok' => false, 'message' => corebb_rate_limit_message($rate, 'private-message reports')];
    }
    $senderId = (int)($pm['senderid'] ?? 0);
    $receiverId = (int)($pm['recieveid'] ?? 0);
    $reportedUserId = $senderId === $viewerId ? $receiverId : $senderId;
    $ok = db_run(
        'INSERT INTO pm_reports (pmid, reporterid, reported_userid, senderid, recieveid, reason_type, comments, severity, status, created_at, created_ip)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$pmId, $viewerId, $reportedUserId, $senderId, $receiverId, $reason, $comments, $severity, 'new', date('Y-m-d H:i:s'), corebb_pm_current_ip()]
    );
    if (!$ok) {
        return ['ok' => false, 'message' => 'Error submitting PM report: ' . db_error()];
    }
    return ['ok' => true, 'message' => 'Your private-message report has been submitted.'];
}

/**
 * Usage: Split a comma-separated recipient field into unique names/ids.
 * Referenced by: corebb_pm_send_from_post().
 *
 * @param string $input Raw recipient list from the compose form.
 * @return array<int, string> Unique recipient tokens in input order.
 */
function corebb_pm_recipient_list(string $input): array
{
    $parts = explode(',', $input);
    $names = [];
    foreach ($parts as $part) {
        $name = trim($part);
        if ($name === '') {
            continue;
        }
        $names[strtolower($name)] = $name;
    }
    return array_values($names);
}

/**
 * Usage: Trim and byte-limit PM text before validation or storage.
 * Referenced by: PM subject/body/report cleaning helpers.
 *
 * @param string $value Raw text value.
 * @param int $maxBytes Maximum byte length; zero disables truncation.
 * @return string Trimmed and optionally truncated text.
 */
function corebb_pm_limit_text(string $value, int $maxBytes): string
{
    $value = trim($value);
    if ($maxBytes > 0 && strlen($value) > $maxBytes) {
        $value = substr($value, 0, $maxBytes);
    }
    return $value;
}

/**
 * Usage: Strip HTML and optionally limit PM text.
 * Referenced by: PM subject/body cleaning helpers.
 *
 * @param string $value Raw text value.
 * @param int $maxBytes Maximum byte length; zero disables truncation.
 * @return string Plain trimmed text.
 */
function corebb_pm_clean_text(string $value, int $maxBytes = 0): string
{
    return corebb_pm_limit_text(strip_tags($value), $maxBytes);
}

/**
 * Usage: Normalize a PM subject to the legacy 100-byte limit.
 * Referenced by: PM compose, reply-title, and send helpers.
 *
 * @param string $value Raw subject value.
 * @return string Clean subject text.
 */
function corebb_pm_clean_subject(string $value): string
{
    // Match the old form's maxlength and keep the DB write path bounded.
    return corebb_pm_clean_text($value, 100);
}

/**
 * Usage: Normalize a PM body to the database-safe byte limit.
 * Referenced by: corebb_pm_send_from_post().
 *
 * @param string $value Raw body value.
 * @return string Clean body text.
 */
function corebb_pm_clean_body(string $value): string
{
    // PM bodies can be long, but should still be bounded before insert.
    return corebb_pm_clean_text($value, 65535);
}

/**
 * Usage: Extract the numeric id from a logged-in user row.
 * Referenced by: PM send, folder, view, mark-read, and report helpers.
 *
 * @param array<string, mixed> $user Current user row.
 * @return int User id, or zero when unavailable.
 */
function corebb_pm_user_id(array $user): int
{
    return (int)($user['id'] ?? 0);
}

/**
 * Usage: Validate and send a compose-form private message.
 * Referenced by: controllers/messages.php send action.
 *
 * @param array<string, mixed> $sender Current logged-in sender row.
 * @param array<string, mixed> $post PM compose form data.
 * @return array{ok: bool, message: string, sent_to?: array<int, string>, missing?: array<int, string>} Send result.
 */
function corebb_pm_send_from_post(array $sender, array $post): array
{
    $senderId = corebb_pm_user_id($sender);
    $senderAccess = (int)($sender['accesslevel'] ?? 0);

    if ($senderId <= 0) {
        return ['ok' => false, 'message' => 'You must be logged in to send private messages.'];
    }

    $rawRecipients = trim((string)($post['user_name'] ?? ''));
    if ($rawRecipients === '') {
        return ['ok' => false, 'message' => 'Please enter a user name to send to.'];
    }

    $title = corebb_pm_clean_subject((string)($post['message_subject'] ?? ''));
    if ($title === '') {
        return ['ok' => false, 'message' => 'Please enter a message title.'];
    }

    $body = corebb_pm_clean_body((string)($post['message_body'] ?? ''));
    $body = $body === '' ? '(no message)' : $body;

    $recipients = corebb_pm_recipient_list($rawRecipients);
    if (!$recipients) {
        return ['ok' => false, 'message' => 'Please enter a user name to send to.'];
    }

    if ($senderAccess < 2 && count($recipients) > 5) {
        return ['ok' => false, 'message' => 'You can only send a private message to five users at once.'];
    }

    $rate = corebb_rate_limit_pm_send($sender);
    if (empty($rate['allowed'])) {
        return ['ok' => false, 'message' => corebb_rate_limit_message($rate, 'private messages')];
    }

    $now = convert_to_timestamp_raw(time());

    $sentTo = [];
    $missing = [];

    foreach ($recipients as $name) {
        // The old UI says username or user ID. Support both.
        if (ctype_digit($name)) {
            $recipient = db_one('SELECT id, username FROM users WHERE id = ? LIMIT 1', [(int)$name]);
        } else {
            $recipient = db_one('SELECT id, username FROM users WHERE username = ? LIMIT 1', [$name]);
        }

        if (!$recipient) {
            $missing[] = $name;
            continue;
        }

        $recipientId = (int)$recipient['id'];
        $insert = db_run(
            'INSERT INTO privatemessages (senderid, recieveid, title, message, markread, datesent) VALUES (?, ?, ?, ?, ?, ?)',
            [(int)$senderId, (int)$recipientId, $title, $body, 0, $now]
        );

        if (!$insert) {
            return ['ok' => false, 'message' => 'Error sending message: ' . db_error()];
        }

        $sentTo[] = (string)$recipient['username'];
    }

    if (!$sentTo) {
        $msg = 'No matching recipients found.';
        if ($missing) {
            $msg .= ' Missing: ' . implode(', ', $missing);
        }
        return ['ok' => false, 'message' => $msg];
    }

    $msg = 'Successfully sent message to: ' . implode(', ', $sentTo);
    if ($missing) {
        $msg .= '. Not found: ' . implode(', ', $missing);
    }

    return ['ok' => true, 'message' => $msg, 'sent_to' => $sentTo, 'missing' => $missing];
}

/**
 * Usage: Count messages in one PM folder for the current user.
 * Referenced by: folder/view/send view models.
 *
 * @param int $userId Current user id.
 * @param string $folder One of unread, read, or sent.
 * @return int Folder item count.
 */
function corebb_pm_count(int $userId, string $folder): int
{
    corebb_pm_ensure_moderation_schema();
    if ($userId <= 0) {
        return 0;
    }

    switch ($folder) {
        case 'unread':
            $visible = corebb_pm_visible_condition();
            return (int)db_value("SELECT COUNT(*) FROM privatemessages WHERE recieveid = ? AND markread = 0 AND {$visible}", [(int)$userId], 0);
        case 'read':
            $visible = corebb_pm_visible_condition();
            return (int)db_value("SELECT COUNT(*) FROM privatemessages WHERE recieveid = ? AND markread = 1 AND {$visible}", [(int)$userId], 0);
        case 'sent':
            $visible = corebb_pm_visible_condition();
            return (int)db_value("SELECT COUNT(*) FROM privatemessages WHERE senderid = ? AND {$visible}", [(int)$userId], 0);
        default:
            return 0;
    }

}

/**
 * Usage: Fetch message rows for one PM folder.
 * Referenced by: corebb_pm_folder_model().
 *
 * @param int $userId Current user id.
 * @param string $folder One of unread, read, or sent.
 * @return array<int, array<string, mixed>> Folder rows ordered newest first.
 */
function corebb_pm_folder_result(int $userId, string $folder): array
{
    corebb_pm_ensure_moderation_schema();
    if ($userId <= 0) {
        return [];
    }

    switch ($folder) {
        case 'unread':
            $visible = corebb_pm_visible_condition();
            return db_all("SELECT id, senderid, recieveid, title, markread, datesent FROM privatemessages WHERE recieveid = ? AND markread = 0 AND {$visible} ORDER BY id DESC", [(int)$userId]);
        case 'read':
            $visible = corebb_pm_visible_condition();
            return db_all("SELECT id, senderid, recieveid, title, markread, datesent FROM privatemessages WHERE recieveid = ? AND markread = 1 AND {$visible} ORDER BY id DESC", [(int)$userId]);
        case 'sent':
            $visible = corebb_pm_visible_condition();
            return db_all("SELECT id, senderid, recieveid, title, markread, datesent FROM privatemessages WHERE senderid = ? AND {$visible} ORDER BY id DESC", [(int)$userId]);
        default:
            return [];
    }
}

/**
 * Usage: Convert a PM folder key into its display title.
 * Referenced by: corebb_pm_folder_model().
 *
 * @param string $folder Folder key from routing/model state.
 * @return string Human-readable folder title.
 */
function corebb_pm_folder_title(string $folder): string
{
    return match ($folder) {
        'unread' => 'Inbox',
        'read' => 'Read Items',
        'sent' => 'Sent Items',
        default => 'Private Messages',
    };
}

/**
 * Usage: Mark a received private message as read.
 * Referenced by: PM workflow actions.
 *
 * @param array<string, mixed> $viewer Current logged-in user row.
 * @param int $pmId Private message id to mark.
 * @return array{ok: bool, message: string} Result message for callers.
 */
function corebb_pm_mark_read(array $viewer, int $pmId): array
{
    corebb_pm_ensure_moderation_schema();
    $userId = corebb_pm_user_id($viewer);
    if ($userId <= 0 || $pmId <= 0) {
        return ['ok' => false, 'message' => 'Unknown private message ID.'];
    }

    $visible = corebb_pm_visible_condition();
    $pm = db_one(
        "SELECT id, markread FROM privatemessages WHERE id = ? AND recieveid = ? AND {$visible} LIMIT 1",
        [(int)$pmId, (int)$userId]
    );
    if (!$pm) {
        return ['ok' => false, 'message' => 'Unknown private message ID.'];
    }

    if ((int)($pm['markread'] ?? 0) === 1) {
        return ['ok' => true, 'message' => 'Private message is already marked read.'];
    }

    $ok = db_run('UPDATE privatemessages SET markread = 1 WHERE id = ? AND recieveid = ?', [(int)$pmId, (int)$userId]);
    if (!$ok) {
        return ['ok' => false, 'message' => 'Unable to mark private message read: ' . db_error()];
    }

    return ['ok' => true, 'message' => 'Private message marked read.'];
}

/**
 * Usage: Fetch one visible PM row for the read/sent/unread view.
 * Referenced by: corebb_pm_view_model().
 *
 * @param int $userId Current user id.
 * @param int $pmId Private message id.
 * @param string $method Folder/view method: unread, read, or sent.
 * @return array<string, mixed>|null PM row, or null when unavailable.
 */
function corebb_pm_get_for_view(int $userId, int $pmId, string $method): ?array
{
    corebb_pm_ensure_moderation_schema();
    if ($userId <= 0 || $pmId <= 0) {
        return null;
    }

    $deleteColumns = corebb_pm_deleted_select_columns();
    $visible = corebb_pm_visible_condition();
    if ($method === 'sent') {
        $row = db_one("SELECT id, senderid, recieveid, title, message, markread, datesent, {$deleteColumns} FROM privatemessages WHERE senderid = ? AND id = ? AND {$visible} LIMIT 1", [(int)$userId, (int)$pmId]);
    } elseif ($method === 'read' || $method === 'unread') {
        $row = db_one("SELECT id, senderid, recieveid, title, message, markread, datesent, {$deleteColumns} FROM privatemessages WHERE recieveid = ? AND id = ? AND {$visible} LIMIT 1", [(int)$userId, (int)$pmId]);
    } else {
        return null;
    }

    if (!$row) {
        return null;
    }

    if ($method === 'unread') {
        db_run('UPDATE privatemessages SET markread = 1 WHERE id = ? AND recieveid = ?', [(int)$pmId, (int)$userId]);
        $row['markread'] = 1;
    }

    return $row;
}
?>
