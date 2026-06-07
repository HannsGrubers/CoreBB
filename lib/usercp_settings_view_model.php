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
 |  usercp_settings_view_model.php  - User CP edit-page  |
 |  helpers.                                             |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/pm_helpers.php';
require_once __DIR__ . '/notification_helpers.php';
require_once __DIR__ . '/vip_style_helpers.php';

/**
 * Usage: Count unread private messages for User CP chrome and sidebars.
 * Referenced by: corebb_usercp_base_model().
 *
 * @param int $uid Current logged-in user id.
 * @return int Unread private message count.
 */
function corebb_usercp_unread_count(int $uid): int
{
    return corebb_pm_count($uid, 'unread');
}

/**
 * Usage: Build the common User CP model shared by edit-profile/settings pages.
 * Referenced by: controllers/usercp.php settings actions and admin edit-profile tools.
 *
 * @param int $uid Current logged-in user id.
 * @return array<string, mixed> Shared counts and status message for User CP templates.
 */
function corebb_usercp_base_model(int $uid): array
{
    return [
        'uid' => $uid,
        'unreadPmCount' => corebb_usercp_unread_count($uid),
        'notificationCount' => $uid > 0 ? corebb_notifications_uncleared_count($uid, false) : 0,
        'message' => trim((string)($_GET['msg'] ?? '')),
        'canEditAppearance' => corebb_vip_style_user_can_self_manage($uid),
    ];
}


/**
 * Usage: Remove abandoned messenger columns before profile-field lists are built.
 * Referenced by: corebb_profile_fields().
 *
 * @return void
 */
function corebb_usercp_drop_legacy_messenger_columns(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    foreach (['icqid', 'aolid', 'msnid'] as $column) {
        if (db_exists('SHOW COLUMNS FROM `users` LIKE ?', [$column])) {
            db_run('ALTER TABLE `users` DROP COLUMN `' . $column . '`');
        }
    }
}

/**
 * Usage: Describe editable profile fields for user and admin profile forms.
 * Referenced by: controllers/usercp.php action=profile and lib/admin_edit_profile_view_model.php.
 *
 * @return array<int, array<string, mixed>> Field definitions for profile-edit templates.
 */
function corebb_profile_fields(): array
{
    corebb_usercp_drop_legacy_messenger_columns();

    return [
        ['key' => 'country', 'label' => 'Country:', 'type' => 'text', 'maxlength' => 255],
        ['key' => 'company', 'label' => 'Company', 'type' => 'text', 'maxlength' => 255],
        ['key' => 'jobtitle', 'label' => 'Job Title:', 'type' => 'text', 'maxlength' => 255],
        ['key' => 'websiteurl', 'label' => 'Website URL:', 'type' => 'text', 'maxlength' => 255],
        ['key' => 'pubemail', 'label' => 'Public Email:', 'type' => 'text', 'maxlength' => 255],
        ['key' => 'privemail', 'label' => 'Private Boards Email Address:', 'type' => 'text', 'maxlength' => 255],
        ['key' => 'profiletitle', 'label' => 'Title of your profile page:', 'type' => 'text', 'maxlength' => 255],
        ['key' => 'gender', 'label' => 'Gender:', 'type' => 'text', 'maxlength' => 255],
        ['key' => 'birthday', 'label' => 'Birthday (This will be public):', 'type' => 'text', 'maxlength' => 255],
    ];
}

/**
 * Usage: Trim a string to a database-safe byte length.
 * Referenced by: profile and signature save helpers.
 *
 * @param string $value Value to shorten.
 * @param int $maxLen Maximum byte length to keep.
 * @return string Original or truncated value.
 */
function corebb_usercp_truncate_bytes(string $value, int $maxLen): string
{
    $maxLen = max(0, $maxLen);
    if ($maxLen > 0 && strlen($value) > $maxLen) {
        return substr($value, 0, $maxLen);
    }
    return $value;
}

/**
 * Usage: Read, strip, trim, and length-limit a profile value from an input array.
 * Referenced by: corebb_usercp_save_profile_from_array().
 *
 * @param array<string, mixed> $source Submitted profile values.
 * @param string $key Source key to clean.
 * @param int $maxLen Maximum byte length to keep.
 * @return string Cleaned profile value.
 */
function corebb_clean_profile_value_from(array $source, string $key, int $maxLen = 255): string
{
    $value = trim(strip_tags((string)($source[$key] ?? '')));
    return corebb_usercp_truncate_bytes($value, $maxLen);
}

/**
 * Usage: Build the escaped SELECT column list for loading editable profile data.
 * Referenced by: corebb_usercp_load_profile().
 *
 * @return string Comma-separated, backtick-escaped user column list.
 */
function corebb_usercp_profile_columns_sql(): string
{
    $columns = ['id', 'username'];
    foreach (corebb_profile_fields() as $field) {
        $columns[] = (string)$field['key'];
    }
    $columns[] = 'profpic';
    $columns[] = 'bio';

    return implode(', ', array_map(static function (string $column): string {
        return '`' . str_replace('`', '``', $column) . '`';
    }, array_values(array_unique($columns))));
}

/**
 * Usage: Load editable profile data for the current user or an admin target.
 * Referenced by: controllers/usercp.php action=profile and lib/admin_edit_profile_view_model.php.
 *
 * @param int $uid User id to load.
 * @return array<string, mixed> User profile row, or an empty array for invalid/missing users.
 */
function corebb_usercp_load_profile(int $uid): array
{
    if ($uid <= 0) {
        return [];
    }
    $row = db_one('SELECT ' . corebb_usercp_profile_columns_sql() . ' FROM users WHERE id = ? LIMIT 1', [$uid]);
    return is_array($row) ? $row : [];
}

/**
 * Usage: Save profile fields from a supplied array instead of directly from globals.
 * Referenced by: corebb_usercp_save_profile() and admin edit-profile saves.
 *
 * @param int $uid User id to update.
 * @param array<string, mixed> $source Submitted profile fields.
 * @return bool True when the profile update succeeds.
 */
function corebb_usercp_save_profile_from_array(int $uid, array $source): bool
{
    if ($uid <= 0) {
        return false;
    }

    $fields = [];
    foreach (corebb_profile_fields() as $field) {
        $fields[$field['key']] = corebb_clean_profile_value_from($source, $field['key'], (int)($field['maxlength'] ?? 255));
    }
    $fields['profpic'] = corebb_clean_profile_value_from($source, 'profpic', 255);
    // Bio may contain board markup, so strip HTML but preserve BBCode-ish text.
    $fields['bio'] = corebb_usercp_truncate_bytes(trim(strip_tags((string)($source['bio'] ?? ''))), 65535);
    if (function_exists('corebb_user_column_exists') && corebb_user_column_exists('profupdated')) {
        $fields['profupdated'] = date('M y');
    }

    $columns = array_keys($fields);
    $setSql = implode(', ', array_map(static function ($column) {
        return '`' . str_replace('`', '``', $column) . '` = ?';
    }, $columns));
    $params = array_values($fields);
    $params[] = $uid;

    return db_run('UPDATE users SET ' . $setSql . ' WHERE id = ?', $params);
}

/**
 * Usage: Save the current user's posted profile fields.
 * Referenced by: controllers/usercp.php action=profile.
 *
 * @param int $uid Current logged-in user id.
 * @return bool True when the profile update succeeds.
 */
function corebb_usercp_save_profile(int $uid): bool
{
    return corebb_usercp_save_profile_from_array($uid, $_POST);
}

/**
 * Usage: Read one signature line, falling back to the legacy combined signature.
 * Referenced by: corebb_signature_parts().
 *
 * @param array<string, mixed> $row User signature row.
 * @param int $index Signature slot number, 1 through 5.
 * @return string Signature text for the requested slot.
 */
function corebb_signature_part(array $row, int $index): string
{
    $value = trim((string)($row['sig' . $index] ?? ''));
    if ($value !== '' && $value !== '$') {
        return $value;
    }
    $signature = (string)($row['signature'] ?? '');
    if ($signature !== '') {
        $lines = preg_split('/\R/', $signature);
        return (string)($lines[$index - 1] ?? '');
    }
    return '';
}

/**
 * Usage: Split a signature row into the five editable signature form fields.
 * Referenced by: controllers/usercp.php action=signature.
 *
 * @param array<string, mixed> $row User signature row.
 * @return array<int, string> Five signature parts in form order.
 */
function corebb_signature_parts(array $row): array
{
    $parts = [];
    for ($i = 1; $i <= 5; $i++) {
        $parts[] = corebb_signature_part($row, $i);
    }
    return $parts;
}

/**
 * Usage: Load signature fields for the User CP signature editor.
 * Referenced by: controllers/usercp.php action=signature.
 *
 * @param int $uid Current logged-in user id.
 * @return array<string, mixed> Signature row, or an empty array for invalid/missing users.
 */
function corebb_usercp_load_signature(int $uid): array
{
    if ($uid <= 0) {
        return [];
    }
    corebb_user_ensure_profile_columns();
    $row = db_one('SELECT id, username, sig1, sig2, sig3, sig4, sig5, signature FROM users WHERE id = ? LIMIT 1', [$uid]);
    return is_array($row) ? $row : [];
}

/**
 * Usage: Save the current user's five signature lines and legacy combined signature.
 * Referenced by: controllers/usercp.php action=signature.
 *
 * @param int $uid Current logged-in user id.
 * @return bool True when the signature update succeeds.
 */
function corebb_usercp_save_signature(int $uid): bool
{
    if ($uid <= 0) {
        return false;
    }
    corebb_user_ensure_profile_columns();
    $parts = [];
    for ($i = 1; $i <= 5; $i++) {
        $value = trim(strip_tags((string)($_POST['sig' . $i] ?? '')));
        $value = corebb_usercp_truncate_bytes($value, 250);
        $parts[$i] = $value;
    }
    $signature = implode("\n", array_values(array_filter($parts, static function ($line) {
        return $line !== '';
    })));

    return db_run(
        'UPDATE users SET sig1 = ?, sig2 = ?, sig3 = ?, sig4 = ?, sig5 = ?, signature = ? WHERE id = ?',
        [
            $parts[1] !== '' ? $parts[1] : '$',
            $parts[2] !== '' ? $parts[2] : '$',
            $parts[3] !== '' ? $parts[3] : '$',
            $parts[4] !== '' ? $parts[4] : '$',
            $parts[5] !== '' ? $parts[5] : '$',
            $signature,
            $uid,
        ]
    );
}

/**
 * Usage: Define the page-size choices allowed in User CP options.
 * Referenced by: option load and save helpers.
 *
 * @return array<int, int> Allowed topic/thread and board page sizes.
 */
function corebb_options_allowed_page_sizes(): array
{
    return [5, 10, 15, 20, 25, 30, 35, 40, 45, 50];
}

/**
 * Usage: Load current paging options for the User CP options form.
 * Referenced by: controllers/usercp.php action=options.
 *
 * @param int $uid Current logged-in user id.
 * @return array<string, mixed> Current page-size options and allowed values.
 */
function corebb_usercp_load_options(int $uid): array
{
    if ($uid <= 0) {
        return [
            'ThreadPages' => 25,
            'BoardPages' => 25,
            'allowedPageSizes' => corebb_options_allowed_page_sizes(),
        ];
    }
    corebb_user_ensure_profile_columns();
    $row = db_one('SELECT ThreadPages, BoardPages FROM users WHERE id = ? LIMIT 1', [$uid]);
    $row = is_array($row) ? $row : [];
    $allowed = corebb_options_allowed_page_sizes();
    $threadPages = (int)($row['ThreadPages'] ?? 25);
    $boardPages = (int)($row['BoardPages'] ?? 25);
    if (!in_array($threadPages, $allowed, true)) {
        $threadPages = 25;
    }
    if (!in_array($boardPages, $allowed, true)) {
        $boardPages = 25;
    }
    return [
        'ThreadPages' => $threadPages,
        'BoardPages' => $boardPages,
        'allowedPageSizes' => $allowed,
    ];
}

/**
 * Usage: Save posted User CP paging options after validating allowed sizes.
 * Referenced by: controllers/usercp.php action=options.
 *
 * @param int $uid Current logged-in user id.
 * @return bool True when the options update succeeds.
 */
function corebb_usercp_save_options(int $uid): bool
{
    if ($uid <= 0) {
        return false;
    }
    corebb_user_ensure_profile_columns();
    $allowed = corebb_options_allowed_page_sizes();
    $threadPages = (int)($_POST['ThreadPages'] ?? 25);
    $boardPages = (int)($_POST['BoardPages'] ?? 25);
    if (!in_array($threadPages, $allowed, true) || !in_array($boardPages, $allowed, true)) {
        return false;
    }
    return db_run('UPDATE users SET ThreadPages = ?, BoardPages = ? WHERE id = ?', [$threadPages, $boardPages, $uid]);
}
