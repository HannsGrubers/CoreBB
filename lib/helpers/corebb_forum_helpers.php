<?php
/*-------------------------------------------------------
 | corebb_forum_helpers.php - Small forum-wide helpers.
 +-------------------------------------------------------*/

require_once __DIR__ . '/auth_password_helpers.php';
require_once __DIR__ . '/security.php';

/**
 * Usage: Check whether a short text value contains only the allowed board-name set.
 * Referenced by: admin board/category forms.
 *
 * @param mixed $text Text to validate.
 * @return bool True when the text matches the character whitelist.
 */
function corebb_text_is_simple_label($text): bool
{
    return !preg_match("/[^a-zA-Z0-9\s.,!'_]/i", (string)$text);
}

/**
 * Usage: Set a long-lived forum cookie through the shared security helper.
 * Referenced by: board read-state tracking.
 *
 * @param string $name Cookie name.
 * @param mixed $data Cookie value.
 * @return bool True when PHP accepts the cookie header.
 */
function corebb_set_forum_cookie(string $name, $data): bool
{
    $expires = time() + 1029600;
    return corebb_security_set_cookie($name, (string)$data, $expires, '/', $GLOBALS['CookieDomain'] ?? '');
}

/**
 * Usage: Read a boolean board/system setting from the database.
 * Referenced by: layout and admin stats toggles.
 *
 * @param mixed $setting Setting name in systemsettings.name.
 * @return bool True for enabled truthy values; false for missing/disabled values.
 */
function corebb_board_setting_enabled($setting): bool
{
    $value = db_value("SELECT setting FROM `systemsettings` WHERE `name` = ? LIMIT 1", [(string)$setting], null);
    if ($value === null) {
        return false;
    }
    return ($value === true || $value === 1 || $value === '1' || strtolower((string)$value) === 'true' || strtolower((string)$value) === 'yes' || strtolower((string)$value) === 'on');
}

/**
 * Usage: Create a forum user from registration/admin-supplied basics.
 * Referenced by: registration and Google account completion.
 *
 * @param string $name Requested username.
 * @param string $email Private/account email address.
 * @param string $password Raw password to hash.
 * @return array{ok: bool, id: int, error: string}
 */
function corebb_create_user(string $name, string $email, string $password): array
{
    $name = trim($name);
    $email = trim($email);

    if ($name === '' || $email === '' || $password === '') {
        return ['ok' => false, 'id' => 0, 'error' => 'One or more required fields were left empty.'];
    }
    if (!preg_match('/^[A-Za-z0-9_\- ]{3,20}$/', $name)) {
        return ['ok' => false, 'id' => 0, 'error' => 'Invalid username.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'id' => 0, 'error' => 'Invalid email address.'];
    }

    corebb_auth_ensure_schema();
    $passwordHash = corebb_auth_password_hash($password);
    $currentDate = date('M y');

    if (db_exists("SELECT id FROM users WHERE username = ? LIMIT 1", [$name])) {
        return ['ok' => false, 'id' => 0, 'error' => 'Username already exists!'];
    }

    if (db_exists("SELECT id FROM users WHERE privemail = ? LIMIT 1", [$email])) {
        return ['ok' => false, 'id' => 0, 'error' => 'User with that email address already exists!'];
    }

    $sql = "INSERT INTO users (username, password, regdate, accesslevel, privemail, ThreadPages, BoardPages, posts) VALUES (?, ?, ?, 1, ?, 25, 25, 0)";
    if (db_run($sql, [$name, $passwordHash, $currentDate, $email])) {
        return ['ok' => true, 'id' => (int)db_insert_id(), 'error' => ''];
    }

    return ['ok' => false, 'id' => 0, 'error' => 'Error running query: ' . db_error()];
}

/**
 * Usage: Return the first N whitespace-separated words plus the board preview ellipsis.
 * Referenced by: blog entry previews.
 *
 * @param string $text Text to shorten.
 * @param int $limit Maximum number of words to keep.
 * @return string Shortened text with "....." appended.
 */
function corebb_trim_words(string $text, int $limit): string
{
    $words = explode(' ', $text);
    $out = [];
    for ($i = 0; $i <= $limit - 1 && isset($words[$i]); $i++) {
        $out[] = $words[$i];
    }
    return trim(implode(' ', $out)) . '.....';
}
