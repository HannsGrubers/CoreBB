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
 |  vip_style_helpers.php  - VIP username styling        |
 |  helpers.                                             |
 +-------------------------------------------------------+*/

if (!defined('COREBB_VIP_STYLE_HELPERS_LOADED')) {
    define('COREBB_VIP_STYLE_HELPERS_LOADED', true);
}

const COREBB_VIP_APPEARANCE_SELF_TOOL = 'vip_appearance_self';

/**
 * Escape a value for use inside a VIP style attribute.
 *
 * Usage: protect generated style attributes before rendering.
 * Referenced by: corebb_vip_style_attr_from_values().
 *
 * @param mixed $value Value to escape.
 * @return string HTML-safe value.
 */
function corebb_vip_style_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Check whether a VIP style column exists on users.
 *
 * Usage: keep VIP style schema upgrades idempotent.
 * Referenced by: corebb_vip_style_ensure_schema().
 *
 * @param string $column Users-table column name.
 * @return bool True when the column exists.
 */
function corebb_vip_style_column_exists(string $column): bool
{
    return (int)db_value(
        "SELECT COUNT(*)
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'users'
            AND COLUMN_NAME = ?",
        [$column],
        0
    ) > 0;
}

/**
 * Ensure user columns required for VIP username styling exist.
 *
 * Usage: call before saving style choices or running admin/user style tools.
 * Referenced by: corebb_vip_style_save_user().
 *
 * @return void
 */
function corebb_vip_style_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $columns = [
        'style' => "TEXT NULL",
        'vip_bg_color' => "VARCHAR(7) NOT NULL DEFAULT ''",
        'vip_text_color' => "VARCHAR(7) NOT NULL DEFAULT ''",
        'vip_strike' => "TINYINT(1) NOT NULL DEFAULT 0",
        'vip_bold' => "TINYINT(1) NOT NULL DEFAULT 0",
        'vip_italic' => "TINYINT(1) NOT NULL DEFAULT 0",
        'vip_border' => "TINYINT(1) NOT NULL DEFAULT 0",
        'vip_border_color' => "VARCHAR(7) NOT NULL DEFAULT ''",
    ];

    foreach ($columns as $name => $definition) {
        if (!corebb_vip_style_column_exists($name)) {
            db_run("ALTER TABLE `users` ADD COLUMN `$name` $definition");
        }
    }
}

/**
 * Normalize a user-submitted CSS hex color.
 *
 * Usage: accept blank, #rgb, rgb, #rrggbb, or rrggbb values and reject anything
 * else.
 * Referenced by: style value builders.
 *
 * @param mixed $value Submitted color value.
 * @return string Lowercase #rgb/#rrggbb color or an empty string.
 */
function corebb_vip_style_clean_hex($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if ($value[0] !== '#') {
        $value = '#' . $value;
    }
    if (preg_match('/^#[0-9a-fA-F]{3}$/', $value) || preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        return strtolower($value);
    }
    return '';
}

/**
 * Normalize a checkbox-style value to 0 or 1.
 *
 * Usage: store VIP style toggles consistently.
 * Referenced by: corebb_vip_style_values_from_post().
 *
 * @param mixed $value Submitted checkbox value.
 * @return int 1 when truthy, otherwise 0.
 */
function corebb_vip_style_bool($value): int
{
    return !empty($value) ? 1 : 0;
}

/**
 * Usage: Check whether the user has the explicit self-service appearance grant.
 * Referenced by: self-service appearance checks and legacy-style fallback rules.
 *
 * @param int $userId User id to check.
 * @return bool True when the user has explicit self-service appearance access.
 */
function corebb_vip_style_user_has_self_access(int $userId): bool
{
    static $cache = [];
    if ($userId <= 0) {
        return false;
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    if (!function_exists('db_exists') || !db_exists("SHOW TABLES LIKE 'admin_tool_permissions'")) {
        $cache[$userId] = false;
        return false;
    }
    $cache[$userId] = db_exists(
        'SELECT 1 FROM admin_tool_permissions WHERE userid = ? AND tool_key = ? LIMIT 1',
        [$userId, COREBB_VIP_APPEARANCE_SELF_TOOL]
    );
    return $cache[$userId];
}

/**
 * Usage: Decide whether a user may edit their own appearance settings.
 * Referenced by: User CP appearance controller/model and save validation.
 *
 * @param int $userId User id to check.
 * @param array<string, mixed>|null $user Optional already-loaded user row.
 * @return bool True when the user is VIP+ or has explicit self-service access.
 */
function corebb_vip_style_user_can_self_manage(int $userId, ?array $user = null): bool
{
    if ($userId <= 0) {
        return false;
    }
    if ($user === null && function_exists('db_one')) {
        $row = db_one('SELECT id, accesslevel FROM users WHERE id = ? LIMIT 1', [$userId]);
        $user = is_array($row) ? $row : [];
    }
    if ((int)($user['accesslevel'] ?? 0) >= 2) {
        return true;
    }
    return corebb_vip_style_user_has_self_access($userId);
}

/**
 * Build normalized VIP style values from a submitted form.
 *
 * Usage: sanitize edit-profile/user-control-panel style inputs before saving.
 * Referenced by: corebb_vip_style_save_user().
 *
 * @param array<string, mixed> $post Submitted POST data.
 * @return array<string, int|string> Normalized VIP style values.
 */
function corebb_vip_style_values_from_post(array $post): array
{
    return [
        'vip_bg_color' => corebb_vip_style_clean_hex($post['vip_bg_color'] ?? ''),
        'vip_text_color' => corebb_vip_style_clean_hex($post['vip_text_color'] ?? ''),
        'vip_strike' => corebb_vip_style_bool($post['vip_strike'] ?? 0),
        'vip_bold' => corebb_vip_style_bool($post['vip_bold'] ?? 0),
        'vip_italic' => corebb_vip_style_bool($post['vip_italic'] ?? 0),
        'vip_border' => corebb_vip_style_bool($post['vip_border'] ?? 0),
        'vip_border_color' => corebb_vip_style_clean_hex($post['vip_border_color'] ?? ''),
    ];
}

/**
 * Build normalized VIP style values from a user row.
 *
 * Usage: render saved username styles in layout/user display helpers.
 * Referenced by: layout, user display, and attr helpers.
 *
 * @param array<string, mixed> $row User row.
 * @return array<string, int|string> Normalized VIP style values.
 */
function corebb_vip_style_values_from_user(array $row): array
{
    return [
        'vip_bg_color' => corebb_vip_style_clean_hex($row['vip_bg_color'] ?? ''),
        'vip_text_color' => corebb_vip_style_clean_hex($row['vip_text_color'] ?? ''),
        'vip_strike' => (int)($row['vip_strike'] ?? 0) ? 1 : 0,
        'vip_bold' => (int)($row['vip_bold'] ?? 0) ? 1 : 0,
        'vip_italic' => (int)($row['vip_italic'] ?? 0) ? 1 : 0,
        'vip_border' => (int)($row['vip_border'] ?? 0) ? 1 : 0,
        'vip_border_color' => corebb_vip_style_clean_hex($row['vip_border_color'] ?? ''),
    ];
}

/**
 * Convert normalized VIP style values into inline CSS declarations.
 *
 * Usage: produce the final visual style used by layout and username renderers.
 * Referenced by: layout viewer and style attribute helpers.
 *
 * @param array<string, mixed> $values Normalized VIP style values.
 * @return string Inline CSS declaration list without a trailing semicolon.
 */
function corebb_vip_style_css_from_values(array $values): string
{
    $css = [];

    if (!empty($values['vip_text_color'])) {
        $css[] = 'color: ' . $values['vip_text_color'];
    }
    if (!empty($values['vip_bg_color'])) {
        $css[] = 'background-color: ' . $values['vip_bg_color'];
    }
    if (!empty($values['vip_bold'])) {
        $css[] = 'font-weight: bold';
    }
    if (!empty($values['vip_italic'])) {
        $css[] = 'font-style: italic';
    }
    if (!empty($values['vip_strike'])) {
        $css[] = 'text-decoration: line-through';
    }
    if (!empty($values['vip_border'])) {
        $borderColor = !empty($values['vip_border_color']) ? $values['vip_border_color'] : 'currentColor';
        $css[] = 'border: 1px solid ' . $borderColor;
        $css[] = 'padding: 0 2px';
    }

    return implode('; ', $css);
}

/**
 * Convert normalized VIP style values into a full style attribute.
 *
 * Usage: maintain compatibility with legacy rows that store users.style.
 * Referenced by: user display helpers and save helper.
 *
 * @param array<string, mixed> $values Normalized VIP style values.
 * @return string style="..." attribute or an empty string.
 */
function corebb_vip_style_attr_from_values(array $values): string
{
    $css = corebb_vip_style_css_from_values($values);
    if ($css === '') {
        return '';
    }
    return 'style="' . corebb_vip_style_h($css) . '"';
}

/**
 * Build the VIP style attribute for one user row.
 *
 * Usage: render saved username appearance values and preserve legacy users.style
 * fallback values only for users who may manage their own appearance.
 * Referenced by: user display helpers.
 *
 * @param array<string, mixed> $row User row.
 * @return string style attribute or an empty string.
 */
function corebb_vip_style_attr_for_user(array $row): string
{
    $values = corebb_vip_style_values_from_user($row);
    $attr = corebb_vip_style_attr_from_values($values);
    if ($attr !== '') {
        return $attr;
    }

    // Database compatibility for rows that only have users.style.
    $legacyStyle = trim((string)($row['style'] ?? ''));
    if ($legacyStyle !== '' && stripos($legacyStyle, 'style=') !== false && corebb_vip_style_user_can_self_manage((int)($row['id'] ?? 0), $row)) {
        return $legacyStyle;
    }

    return '';
}

/**
 * Save VIP username style choices for one user.
 *
 * Usage: persist user control panel or profile-edit style settings.
 * Referenced by: profile/user control panel style forms.
 *
 * @param int $userId User id to update.
 * @param array<string, mixed> $post Submitted style form values.
 * @param bool $requireSelfEligibility Whether VIP+/special self-service access is required.
 * @return array{ok: bool, message: string} Save result.
 */
function corebb_vip_style_save_user(int $userId, array $post, bool $requireSelfEligibility = true): array
{
    corebb_vip_style_ensure_schema();

    $user = db_one("SELECT id, username, accesslevel FROM users WHERE id = ?", [$userId]);
    if (!$user) {
        return ['ok' => false, 'message' => 'Unknown user.'];
    }
    if ($requireSelfEligibility && !corebb_vip_style_user_can_self_manage($userId, $user)) {
        return ['ok' => false, 'message' => 'You do not have access to username appearance settings.'];
    }

    $values = corebb_vip_style_values_from_post($post);
    if (!empty($post['clear_vip_style'])) {
        $values = [
            'vip_bg_color' => '',
            'vip_text_color' => '',
            'vip_strike' => 0,
            'vip_bold' => 0,
            'vip_italic' => 0,
            'vip_border' => 0,
            'vip_border_color' => '',
        ];
    }

    $styleAttr = corebb_vip_style_attr_from_values($values);

    $ok = db_run(
        "UPDATE users
            SET vip_bg_color = ?,
                vip_text_color = ?,
                vip_strike = ?,
                vip_bold = ?,
                vip_italic = ?,
                vip_border = ?,
                vip_border_color = ?,
                style = ?
          WHERE id = ?",
        [
            $values['vip_bg_color'],
            $values['vip_text_color'],
            $values['vip_strike'],
            $values['vip_bold'],
            $values['vip_italic'],
            $values['vip_border'],
            $values['vip_border_color'],
            $styleAttr,
            $userId,
        ]
    );

    if (!$ok) {
        return ['ok' => false, 'message' => 'Database error saving VIP style: ' . db_error()];
    }

    return ['ok' => true, 'message' => !empty($post['clear_vip_style']) ? 'Username appearance cleared.' : 'Username appearance saved.'];
}
