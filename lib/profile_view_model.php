<?php
if (!defined('COREBB_VIEW_LOADED')) {
    require_once __DIR__ . '/view.php';
}

/**
 * Usage: Cache the live users-table column list for schema-tolerant profile queries.
 * Referenced by: corebb_profile_user_columns_sql().
 *
 * @return array<string, bool> Map of available users-table columns.
 */
function corebb_profile_available_user_columns(): array
{
    static $columns = null;
    if (is_array($columns)) {
        return $columns;
    }

    $columns = [];
    foreach (db_all('SHOW COLUMNS FROM `users`') as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }

    return $columns;
}

/**
 * Usage: Build a safe SELECT list containing only columns present on this install.
 * Referenced by: corebb_profile_row().
 *
 * @param array<int, string> $wanted Preferred users-table columns for the profile page.
 * @return string Comma-separated, backtick-escaped column list.
 */
function corebb_profile_user_columns_sql(array $wanted): string
{
    $available = corebb_profile_available_user_columns();
    $columns = [];
    foreach ($wanted as $column) {
        $column = (string)$column;
        if ($column !== '' && isset($available[$column])) {
            $columns[] = $column;
        }
    }

    // The profile page cannot function without these two, but keep a fallback
    // anyway so a failed SHOW COLUMNS cannot produce invalid SQL.
    if (!$columns) {
        $columns = ['id', 'username'];
    }

    return implode(', ', array_map(static function (string $column): string {
        return '`' . str_replace('`', '``', $column) . '`';
    }, array_values(array_unique($columns))));
}

/**
 * Usage: Load the public profile row while tolerating older/newer user schemas.
 * Referenced by: corebb_profile_model().
 *
 * @param int $userId Profile user id.
 * @return array<string, mixed>|null User row, or null when the user cannot be found.
 */
function corebb_profile_row(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $columns = [
        'id', 'username', 'accesslevel', 'posts', 'profiletitle',
        'status',
        'country', 'company', 'jobtitle',
        'gender', 'birthday',
        'websiteurl', 'pubemail', 'bio', 'profpic',
        // Some installs only have regdate; some newer/dev copies may also have
        // profadded/profupdated.  Filter these against the live schema before
        // building the SELECT so profile links do not fail as Unknown User.
        'profadded', 'regdate', 'profupdated', 'lastlogindate', 'lastpstdate',
    ];

    $row = db_one('SELECT ' . corebb_profile_user_columns_sql($columns) . ' FROM users WHERE id = ? LIMIT 1', [$userId]);
    return is_array($row) ? $row : null;
}

/**
 * Usage: Format a stored profile date for public display.
 * Referenced by: corebb_profile_model().
 *
 * @param mixed $value Stored date value from the user row.
 * @return string Display date, or "Never" for empty/zero values.
 */
function corebb_profile_date($value): string
{
    $value = (string)($value ?? '');
    if ($value === '' || $value === '0') {
        return 'Never';
    }
    if (function_exists('convert_to_vndate')) {
        return convert_to_vndate($value);
    }
    return str_replace('-', '/', $value);
}

/**
 * Usage: Pick the first populated date from a list of possible schema columns.
 * Referenced by: corebb_profile_model().
 *
 * @param array<string, mixed> $row User row.
 * @param array<int, string> $columns Date columns to check in order.
 * @return string First usable date string, or an empty string.
 */
function corebb_profile_first_date(array $row, array $columns): string
{
    foreach ($columns as $column) {
        $value = trim((string)($row[$column] ?? ''));
        if ($value !== '' && $value !== '0') {
            return str_replace('-', '/', $value);
        }
    }
    return '';
}

/**
 * Usage: Convert stored profile fields into label/value rows for Twig.
 * Referenced by: corebb_profile_model().
 *
 * @param array<string, mixed> $row User row.
 * @return array<int, array<string, string>> Public profile field rows.
 */
function corebb_profile_public_fields(array $row): array
{
    $fields = [];
    $map = [
        'profiletitle' => 'Boards Title',
        'country' => 'Country',
        'company' => 'Company',
        'jobtitle' => 'Job Title',
        'gender' => 'Gender',
        'birthday' => 'Birthday',
    ];

    foreach ($map as $key => $label) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') {
            $fields[] = ['label' => $label, 'value' => $value, 'format' => 'plain'];
        }
    }

    $website = trim((string)($row['websiteurl'] ?? ''));
    if ($website !== '') {
        $fields[] = ['label' => 'Website', 'value' => $website, 'format' => 'website'];
    }

    $email = trim((string)($row['pubemail'] ?? ''));
    if ($email !== '') {
        $fields[] = ['label' => 'Email', 'value' => $email, 'format' => 'email'];
    }

    return $fields;
}

/**
 * Usage: Build the public profile view model for page and API consumers.
 * Referenced by: controllers/content.php?action=profile and api/v1/index.php.
 *
 * @param int $userId Profile user id.
 * @return array<string, mixed> Profile display state, or a not-found message.
 */
function corebb_profile_model(int $userId): array
{
    $row = corebb_profile_row($userId);
    if (!$row) {
        return [
            'found' => false,
            'message' => 'Unknown User.',
        ];
    }

    $usernamePlain = (string)($row['username'] ?? 'Unknown');
    $level = function_exists('LoadUserLevel') ? LoadUserLevel((int)($row['accesslevel'] ?? 0)) : (string)($row['accesslevel'] ?? 0);
    $postCount = function_exists('CreateUserPostcount') ? CreateUserPostcount((int)$row['id']) : number_format((int)($row['posts'] ?? 0));

    $displayTitle = trim((string)($row['profiletitle'] ?? ''));

    return [
        'found' => true,
        'user' => $row,
        'username_plain' => $usernamePlain,
        'profile_title' => $displayTitle,
        'level' => $level,
        'post_count' => $postCount,
        'post_count_raw' => (int)($row['posts'] ?? 0),
        'fields' => corebb_profile_public_fields($row),
        'reg_date' => corebb_profile_first_date($row, ['profadded', 'regdate']),
        'last_update' => corebb_profile_first_date($row, ['profupdated', 'profadded', 'regdate']),
        'last_login' => corebb_profile_date($row['lastlogindate'] ?? ''),
        'last_post' => corebb_profile_date($row['lastpstdate'] ?? ''),
        'bio' => trim((string)($row['bio'] ?? '')),
        'profile_picture' => trim((string)($row['profpic'] ?? '')),
        'can_edit_self' => function_exists('loggedin') && loggedin() && (int)(($GLOBALS['userlogindata_a']['id'] ?? 0)) === (int)$row['id'],
        'can_view_content_links' => function_exists('loggedin') && loggedin(),
        'all_topics_url' => '/profile/' . (int)$row['id'] . '/topics/',
        'all_posts_url' => '/profile/' . (int)$row['id'] . '/posts/',
    ];
}
