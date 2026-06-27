<?php
/*-------------------------------------------------------
 | public_style_helpers.php - Public theme selection.
 |
 | Keeps stylesheet names constrained to local CSS files
 | and shares the approved option list across admin,
 | layout, and User CP forms.
 +-------------------------------------------------------*/

require_once __DIR__ . '/../functions.php';

/**
 * Usage: Check whether a stylesheet path is a local CSS file CoreBB may load.
 * Referenced by: public style option, normalization, and user preference helpers.
 *
 * @param string $style Submitted or configured stylesheet path.
 * @return bool True when the path is a safe relative CSS file.
 */
function corebb_public_style_file_is_safe(string $style): bool
{
    $style = trim(str_replace('\\', '/', $style));
    $style = preg_replace('/[\x00-\x1F\x7F]+/', '', $style) ?? '';
    if ($style === '' || $style[0] === '/' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $style)) {
        return false;
    }
    if (str_contains('/' . $style . '/', '/../')) {
        return false;
    }
    return (bool)preg_match('~^[A-Za-z0-9._/-]+\.css$~', $style);
}

/**
 * Usage: Normalize a stylesheet path, falling back to the VN EOL theme if unsafe.
 * Referenced by: layout style loading and User CP preference handling.
 *
 * @param string $style Submitted or configured stylesheet path.
 * @return string Safe local stylesheet path.
 */
function corebb_public_style_normalize_file(string $style): string
{
    $style = trim(str_replace('\\', '/', $style));
    $style = preg_replace('/[\x00-\x1F\x7F]+/', '', $style) ?? '';
    return corebb_public_style_file_is_safe($style) ? corebb_public_style_canonical_file($style) : 'style_vn_eol.css';
}

/**
 * Usage: Map retired public theme filenames to the current release-facing set.
 * Referenced by: file normalization and saved user preference handling.
 *
 * @param string $style Safe local CSS filename.
 * @return string Current public theme filename.
 */
function corebb_public_style_canonical_file(string $style): string
{
    $legacy = [
        'style_modern_3.css' => 'style_modern_2.css',
        'style_modern_4.css' => 'style_modern_2.css',
        'style.css' => 'style_vn_eol.css',
        'style_ign.css' => 'style_vn_eol.css',
    ];

    return $legacy[$style] ?? $style;
}

/**
 * Usage: Return CoreBB's built-in public stylesheet choices.
 * Referenced by: corebb_public_style_options().
 *
 * @return array<string, string> Style filename to display label.
 */
function corebb_public_style_builtin_options(): array
{
    return [
        'style_vn_eol.css' => 'CoreBB VN EOL',
        'style_modern.css' => 'CoreBB Modern 1',
        'style_modern_2.css' => 'CoreBB Modern 2',
        'style_emberline.css' => 'CoreBB Emberline',
    ];
}

/**
 * Usage: Build the curated public stylesheet list exposed to admins and users.
 * Referenced by: admin default-style settings and User CP theme selection.
 *
 * @return array<string, string> Style filename to display label.
 */
function corebb_public_style_options(): array
{
    return corebb_public_style_builtin_options();
}

/**
 * Usage: Resolve a users.userstyle preference into a safe stylesheet file.
 * Referenced by: layout rendering and User CP option loading.
 *
 * @param string $value Stored userstyle value, either a CSS filename or legacy style id.
 * @return string Safe stylesheet file, or empty string when the user follows forum default.
 */
function corebb_public_style_resolve_user_value(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '0') {
        return '';
    }

    if (ctype_digit($value) && function_exists('db_one')) {
        $row = db_one('SELECT file FROM systemstyles WHERE id = ? LIMIT 1', [(int)$value]) ?: [];
        $file = (string)($row['file'] ?? '');
        if ($file !== '' && corebb_public_style_file_is_safe($file)) {
            $file = corebb_public_style_normalize_file($file);
            return isset(corebb_public_style_options()[$file]) ? $file : '';
        }
    }

    if (!corebb_public_style_file_is_safe($value)) {
        return '';
    }
    $file = corebb_public_style_normalize_file($value);
    return isset(corebb_public_style_options()[$file]) ? $file : '';
}

/**
 * Usage: Normalize a posted User CP stylesheet choice.
 * Referenced by: corebb_usercp_save_options().
 *
 * @param string $value Posted theme value.
 * @return string CSS filename to store in users.userstyle, or empty for forum default.
 */
function corebb_public_style_normalize_user_choice(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '0') {
        return '';
    }

    if (!corebb_public_style_file_is_safe($value)) {
        return '';
    }
    $file = corebb_public_style_normalize_file($value);
    return isset(corebb_public_style_options()[$file]) ? $file : '';
}
