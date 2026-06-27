<?php
require_once __DIR__ . '/corebb_date_helpers.php';
require_once __DIR__ . '/admin_log_helpers.php';
require_once __DIR__ . '/moderation_helpers.php';
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
 |  contact_mods_helpers.php  - Contact Mods request     |
 |  helpers.                                             |
 +-------------------------------------------------------+*/

/**
 * Usage: Validate and quote a database identifier used by Contact Mods helpers.
 * Referenced by: table and column existence checks.
 *
 * @param string $identifier Raw table or column identifier.
 * @return string Backtick-quoted identifier.
 * @throws InvalidArgumentException When the identifier contains unsafe characters.
 */
function corebb_contact_mods_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Usage: Check whether a Contact Mods table exists before optional reads.
 * Referenced by: corebb_contact_mods_new_count().
 *
 * @param string $table Table name to inspect.
 * @return bool True when the table exists in the active database.
 */
function corebb_contact_mods_table_exists(string $table): bool
{
    corebb_contact_mods_identifier($table);

    // Prepared SHOW TABLES queries can behave differently across MySQL/PDO
    // versions. information_schema is reliable here and keeps the nav count
    // from silently returning 0 when the inbox table already exists.
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
 * Usage: Check whether a compatibility column exists before attempting ALTER TABLE.
 * Referenced by: corebb_contact_mods_ensure_schema().
 *
 * @param string $table Table name to inspect.
 * @param string $column Column name to inspect.
 * @return bool True when the column exists.
 */
function corebb_contact_mods_column_exists(string $table, string $column): bool
{
    $tableSafe = corebb_contact_mods_identifier($table);
    return db_exists("SHOW COLUMNS FROM {$tableSafe} LIKE ?", [$column]);
}

/**
 * Usage: Ensure the Contact Mods inbox table and compatibility columns exist.
 * Referenced by: public submission, admin inbox, and request mutation helpers.
 *
 * @return void
 */
function corebb_contact_mods_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db_run("CREATE TABLE IF NOT EXISTS `contact_mod_requests` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `userid` INT(11) NOT NULL DEFAULT 0,
        `subject` VARCHAR(160) NOT NULL DEFAULT '',
        `message` TEXT NULL,
        `status` VARCHAR(32) NOT NULL DEFAULT 'new',
        `created_at` VARCHAR(64) NOT NULL DEFAULT '',
        `created_ip` VARCHAR(64) NOT NULL DEFAULT '',
        `handled_by` INT(11) NOT NULL DEFAULT 0,
        `handled_at` VARCHAR(64) NOT NULL DEFAULT '',
        `handler_note` TEXT NULL,
        `response_pm_id` INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_contact_mod_status` (`status`),
        KEY `idx_contact_mod_userid` (`userid`),
        KEY `idx_contact_mod_created` (`created_at`),
        KEY `idx_contact_mod_handled` (`handled_by`, `handled_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [
        'userid' => "ALTER TABLE `contact_mod_requests` ADD `userid` INT(11) NOT NULL DEFAULT 0",
        'subject' => "ALTER TABLE `contact_mod_requests` ADD `subject` VARCHAR(160) NOT NULL DEFAULT ''",
        'message' => "ALTER TABLE `contact_mod_requests` ADD `message` TEXT NULL",
        'status' => "ALTER TABLE `contact_mod_requests` ADD `status` VARCHAR(32) NOT NULL DEFAULT 'new'",
        'created_at' => "ALTER TABLE `contact_mod_requests` ADD `created_at` VARCHAR(64) NOT NULL DEFAULT ''",
        'created_ip' => "ALTER TABLE `contact_mod_requests` ADD `created_ip` VARCHAR(64) NOT NULL DEFAULT ''",
        'handled_by' => "ALTER TABLE `contact_mod_requests` ADD `handled_by` INT(11) NOT NULL DEFAULT 0",
        'handled_at' => "ALTER TABLE `contact_mod_requests` ADD `handled_at` VARCHAR(64) NOT NULL DEFAULT ''",
        'handler_note' => "ALTER TABLE `contact_mod_requests` ADD `handler_note` TEXT NULL",
        'response_pm_id' => "ALTER TABLE `contact_mod_requests` ADD `response_pm_id` INT(11) NOT NULL DEFAULT 0",
    ];

    foreach ($columns as $column => $sql) {
        if (!corebb_contact_mods_column_exists('contact_mod_requests', $column)) {
            db_run($sql);
        }
    }

    $done = true;
}



/**
 * Usage: Normalize a local return URL for Contact Mods redirects.
 * Referenced by: return URL and contact-link builders.
 *
 * @param string $returnUrl Submitted or current return URL.
 * @return string Local return URL, or "/" when the value is unsafe.
 */
function corebb_contact_mods_normalize_return_url(string $returnUrl): string
{
    $returnUrl = trim(html_entity_decode($returnUrl, ENT_QUOTES, 'UTF-8'));
    if ($returnUrl === '' || preg_match('/[\r\n]/', $returnUrl)) {
        return '/';
    }
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $returnUrl) || str_starts_with($returnUrl, '//')) {
        return '/';
    }
    if ($returnUrl[0] !== '/') {
        return '/';
    }

    $path = (string)(parse_url($returnUrl, PHP_URL_PATH) ?: '/');
    if (preg_match('#^/(contact-mods|contact_mods\.php)(/|$)#i', $path)) {
        return '/';
    }

    return $returnUrl;
}

/**
 * Usage: Normalize the current request URI for a Contact Mods return link.
 * Referenced by: admin layout and lib/layout_view_model.php.
 *
 * @return string Safe local return URL for the current request.
 */
function corebb_contact_mods_current_return_url(): string
{
    return corebb_contact_mods_normalize_return_url((string)($_SERVER['REQUEST_URI'] ?? '/'));
}

/**
 * Usage: Resolve the return URL from Contact Mods GET/POST input.
 * Referenced by: corebb_contact_mods_public_model().
 *
 * @param array<string, mixed> $get Query parameters.
 * @param array<string, mixed> $post Submitted form values.
 * @return string Safe local return URL.
 */
function corebb_contact_mods_request_return_url(array $get, array $post = []): string
{
    $posted = (string)($post['return_url'] ?? '');
    if ($posted !== '') {
        return corebb_contact_mods_normalize_return_url($posted);
    }
    return corebb_contact_mods_normalize_return_url((string)($get['return'] ?? '/'));
}

/**
 * Usage: Add a safe return URL parameter to a Contact Mods link.
 * Referenced by: admin layout, layout view model, and public Contact Mods model.
 *
 * @param string $contactUrl Base Contact Mods URL.
 * @param string $returnUrl Return URL to encode.
 * @return string Contact Mods URL with a return query parameter.
 */
function corebb_contact_mods_url_with_return(string $contactUrl, string $returnUrl): string
{
    $returnUrl = corebb_contact_mods_normalize_return_url($returnUrl);
    $separator = str_contains($contactUrl, '?') ? '&' : '?';
    return $contactUrl . $separator . 'return=' . rawurlencode($returnUrl);
}

/**
 * Usage: Store Contact Mods flash messages for the redirect-after-submit flow.
 * Referenced by: corebb_contact_mods_public_model().
 *
 * @param array<int, mixed> $messages Success/info messages to flash.
 * @param array<int, mixed> $errors Error messages to flash.
 * @return void
 */
function corebb_contact_mods_flash_set(array $messages = [], array $errors = []): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION['corebb_contact_mods_flash'] = [
        'messages' => array_values(array_filter(array_map('strval', $messages), static fn($v) => $v !== '')),
        'errors' => array_values(array_filter(array_map('strval', $errors), static fn($v) => $v !== '')),
    ];
}

/**
 * Usage: Pull and clear Contact Mods flash messages from the session.
 * Referenced by: admin layout and lib/layout_view_model.php.
 *
 * @return array{messages: array<int, string>, errors: array<int, string>} Flash payload.
 */
function corebb_contact_mods_flash_pull(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $flash = $_SESSION['corebb_contact_mods_flash'] ?? $_SESSION['wb_contact_mods_flash'] ?? ['messages' => [], 'errors' => []];
    unset($_SESSION['corebb_contact_mods_flash'], $_SESSION['wb_contact_mods_flash']);
    return is_array($flash) ? $flash : ['messages' => [], 'errors' => []];
}

/**
 * Usage: Trim text to a maximum byte length while preserving valid UTF-8 where possible.
 * Referenced by: Contact Mods cleaning, close, reply, and admin preview flows.
 *
 * @param string $value Text to trim.
 * @param int $maxBytes Maximum byte length.
 * @return string Trimmed text.
 */
function corebb_contact_mods_limit_text(string $value, int $maxBytes): string
{
    $value = trim($value);
    if ($maxBytes > 0 && strlen($value) > $maxBytes) {
        return function_exists('mb_strcut') ? mb_strcut($value, 0, $maxBytes, 'UTF-8') : substr($value, 0, $maxBytes);
    }
    return $value;
}

/**
 * Usage: Strip HTML/control characters from submitted Contact Mods text.
 * Referenced by: public submission and admin reply/close flows.
 *
 * @param string $value Submitted text.
 * @param int $maxBytes Maximum byte length after cleaning.
 * @return string Clean text safe for storage or PM composition.
 */
function corebb_contact_mods_clean_text(string $value, int $maxBytes): string
{
    $value = str_ireplace(['<br />', '<br/>', '<br>'], "\n", $value);
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $value) ?? '';
    return corebb_contact_mods_limit_text($value, $maxBytes);
}

/**
 * Usage: Return the session CSRF token used by Contact Mods forms.
 * Referenced by: public and admin Contact Mods view models.
 *
 * @return string Session token for Contact Mods forms.
 * @throws Random\RandomException When secure token bytes cannot be generated.
 */
function corebb_contact_mods_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['corebb_contact_mods_token'])) {
        $_SESSION['corebb_contact_mods_token'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['corebb_contact_mods_token'];
}

/**
 * Usage: Validate a submitted Contact Mods CSRF token.
 * Referenced by: public and admin Contact Mods POST handlers.
 *
 * @param array<string, mixed> $post Submitted form values.
 * @return bool True when the submitted token matches the session token.
 */
function corebb_contact_mods_check_token(array $post): bool
{
    $token = (string)($post['contact_mods_token'] ?? '');
    return $token !== '' && hash_equals(corebb_contact_mods_token(), $token);
}

/**
 * Usage: Count new Contact Mods requests for navigation badges and admin pages.
 * Referenced by: admin header/sidebar, layout view model, and admin Contact Mods model.
 *
 * @param bool $ensureSchema Whether to create/repair the inbox table before counting.
 * @return int Number of new Contact Mods requests.
 */
function corebb_contact_mods_new_count(bool $ensureSchema = false): int
{
    if ($ensureSchema) {
        corebb_contact_mods_ensure_schema();
    } elseif (!corebb_contact_mods_table_exists('contact_mod_requests')) {
        return 0;
    }
    return (int)db_value("SELECT COUNT(*) FROM contact_mod_requests WHERE status = 'new'", [], 0);
}

/**
 * Usage: Convert stored Contact Mods statuses into display labels.
 * Referenced by: corebb_admin_contact_mods_enrich_row().
 *
 * @param string $status Stored request status.
 * @return string Human-readable status label.
 */
function corebb_contact_mods_status_label(string $status): string
{
    return match ($status) {
        'responded' => 'Responded',
        'ignored' => 'Closed',
        default => 'New',
    };
}

/**
 * Usage: Write Contact Mods staff actions into the admin/moderator log.
 * Referenced by: corebb_admin_contact_mods_model().
 *
 * @param array<string, mixed> $viewer Staff viewer performing the action.
 * @param string $action Log action text.
 * @param string $type Log category/type.
 * @param string $description Optional longer log description.
 * @return void
 */
function corebb_contact_mods_log(array $viewer, string $action, string $type = 'contact_mods', string $description = ''): void
{
    {
        corebb_adminlog_entry((string)($viewer['username'] ?? $viewer['id'] ?? 'Unknown'), (int)($viewer['accesslevel'] ?? 0), $action, $type, $description !== '' ? $description : $action);
    }
}

/**
 * Usage: Fetch a single Contact Mods request with requester/handler display data.
 * Referenced by: admin Contact Mods detail and POST handlers.
 *
 * @param int $requestId contact_mod_requests.id value.
 * @return array<string, mixed>|false Joined request row, or false when unknown.
 */
function corebb_contact_mods_fetch_request(int $requestId): array|false
{
    corebb_contact_mods_ensure_schema();
    if ($requestId <= 0) {
        return false;
    }
    return db_one(
        'SELECT cmr.id, cmr.userid, cmr.subject, cmr.message, cmr.status, cmr.created_at, cmr.created_ip, cmr.handled_by, cmr.handled_at, cmr.handler_note, cmr.response_pm_id,
                requester.username AS requester_username, requester.accesslevel AS requester_accesslevel, requester.lastip AS requester_lastip,
                handler.username AS handler_username
           FROM contact_mod_requests cmr
           LEFT JOIN users requester ON requester.id = cmr.userid
           LEFT JOIN users handler ON handler.id = cmr.handled_by
          WHERE cmr.id = ? LIMIT 1',
        [$requestId]
    );
}

/**
 * Usage: Mark a Contact Mods request as handled.
 * Referenced by: corebb_admin_contact_mods_model().
 *
 * @param int $requestId Request id to update.
 * @param int $viewerId Staff user id handling the request.
 * @param string $status New request status.
 * @param string $note Handler note or response text.
 * @param int $pmId Private message id created for a staff response.
 * @return void
 */
function corebb_contact_mods_close(int $requestId, int $viewerId, string $status, string $note = '', int $pmId = 0): void
{
    corebb_contact_mods_ensure_schema();
    if ($requestId <= 0 || $viewerId <= 0) {
        return;
    }
    $allowed = ['new', 'responded', 'ignored'];
    if (!in_array($status, $allowed, true)) {
        $status = 'ignored';
    }
    db_run(
        'UPDATE contact_mod_requests SET status = ?, handled_by = ?, handled_at = ?, handler_note = ?, response_pm_id = ? WHERE id = ?',
        [$status, $viewerId, date('Y-m-d H:i:s'), corebb_contact_mods_limit_text($note, 65535), $pmId, $requestId]
    );
}

/**
 * Usage: Reopen a closed Contact Mods request for staff follow-up.
 * Referenced by: corebb_admin_contact_mods_model().
 *
 * @param int $requestId Request id to reopen.
 * @return void
 */
function corebb_contact_mods_reopen(int $requestId): void
{
    corebb_contact_mods_ensure_schema();
    if ($requestId <= 0) {
        return;
    }
    db_run(
        "UPDATE contact_mod_requests SET status = 'new', handled_by = 0, handled_at = '', handler_note = '', response_pm_id = 0 WHERE id = ?",
        [$requestId]
    );
}

/**
 * Usage: Send a staff response to the requester as a private message.
 * Referenced by: corebb_admin_contact_mods_model().
 *
 * @param array<string, mixed> $viewer Staff user sending the response.
 * @param array<string, mixed> $request Contact Mods request row.
 * @param string $responseBody Moderator/admin response text.
 * @return array<string, mixed> Result with ok/message and pm_id on success.
 */
function corebb_contact_mods_send_reply_pm(array $viewer, array $request, string $responseBody): array
{
    $senderId = (int)($viewer['id'] ?? 0);
    $recipientId = (int)($request['userid'] ?? 0);
    if ($senderId <= 0) {
        return ['ok' => false, 'message' => 'Unknown staff user.'];
    }
    if ($recipientId <= 0) {
        return ['ok' => false, 'message' => 'Unknown request user.'];
    }

    $recipient = db_one('SELECT id, username FROM users WHERE id = ? LIMIT 1', [$recipientId]);
    if (!$recipient) {
        return ['ok' => false, 'message' => 'The user who submitted this request no longer exists.'];
    }

    $subject = corebb_contact_mods_limit_text((string)($request['subject'] ?? ''), 120);
    $title = corebb_contact_mods_limit_text('Re: Contact Mods' . ($subject !== '' ? ': ' . $subject : ''), 100);
    $reply = corebb_contact_mods_clean_text($responseBody, 65535);
    if ($reply === '') {
        return ['ok' => false, 'message' => 'Please enter a response before sending.'];
    }

    $original = corebb_contact_mods_clean_text((string)($request['message'] ?? ''), 4000);
    $body = "A moderator responded to your Contact Mods request.\n\n";
    $body .= $reply . "\n\n";
    $body .= "----- Original Contact Mods Request -----\n";
    $body .= "Subject: " . ($subject !== '' ? $subject : '(no subject)') . "\n";
    $body .= "Submitted: " . (string)($request['created_at'] ?? '') . "\n\n";
    $body .= $original;
    $body = corebb_contact_mods_limit_text($body, 65535);

    $now = convert_to_timestamp_raw(time());

    $ok = db_run(
        'INSERT INTO privatemessages (senderid, recieveid, title, message, markread, datesent) VALUES (?, ?, ?, ?, ?, ?)',
        [$senderId, $recipientId, $title, $body, 0, $now]
    );
    if (!$ok) {
        return ['ok' => false, 'message' => 'Could not send private message: ' . db_error()];
    }

    return ['ok' => true, 'message' => 'Response sent to ' . (string)($recipient['username'] ?? ('#' . $recipientId)) . ' by private message.', 'pm_id' => db_insert_id()];
}
?>
