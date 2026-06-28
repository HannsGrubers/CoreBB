<?php
/*-------------------------------------------------------
 | corebb_image_helpers.php - Safe image and face helpers.
 |
 | Centralizes local image URL validation and the classic
 | CoreBB face/boardface image mappings used by markup.
 +-------------------------------------------------------*/

require_once __DIR__ . '/corebb_url_helpers.php';

/**
 * Usage: Return a root-relative URL for local image assets only.
 * Referenced by: user icon rendering and BBCode image fallbacks.
 *
 * Used for DB-backed avatar/icon paths so an old or poisoned icons.filepath
 * value cannot become javascript:, data:, protocol-relative, traversal, or a
 * non-image file reference in an <img> tag.
 *
 * @param string $path Stored image path to validate.
 * @param array<int, string> $allowedTopDirs Allowed first path segments.
 * @return string Root-relative image URL, or an empty string when rejected.
 */
function corebb_safe_local_image_asset(string $path, array $allowedTopDirs = ['images']): string {
    $path = html_entity_decode($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $path = trim(str_replace('\\', '/', $path));
    $path = preg_replace('/[\x00-\x1F\x7F]+/', '', $path) ?? '';
    if ($path === '' || strlen($path) > 512) {
        return '';
    }
    if (str_starts_with($path, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path)) {
        return '';
    }
    if (str_contains($path, '?') || str_contains($path, '#')) {
        return '';
    }

    $local = ltrim(preg_replace('~^\./+~', '', $path) ?? $path, '/');
    if ($local === '' || str_contains($local, '..')) {
        return '';
    }
    if (!preg_match('~^[A-Za-z0-9._/-]+$~', $local)) {
        return '';
    }

    $top = explode('/', $local, 2)[0] ?? '';
    $allowed = array_map(static fn($dir) => trim((string)$dir, '/'), $allowedTopDirs);
    if (!in_array($top, $allowed, true)) {
        return '';
    }

    $ext = strtolower((string)pathinfo($local, PATHINFO_EXTENSION));
    if (!in_array($ext, ['gif', 'jpg', 'jpeg', 'png', 'webp'], true)) {
        return '';
    }

    return corebb_public_join_base_path($local);
}

/**
 * Usage: Return the supported [face_name] to image-file lookup table.
 * Referenced by: face rendering and legacy boardface conversion helpers.
 *
 * @return array<string, string> Map of normalized face names to gif filenames.
 */
function corebb_face_name_file_map(): array
{
    return [
        'thinking' => '33.gif',
        'monkey' => '38.gif',
        'tired' => '31.gif',
        'confused' => '6.gif',
        'hugs' => '60.gif',
        'not_talking' => '28.gif',
        'alien_1' => '47.gif',
        'nerd' => '22.gif',
        'alien_2' => '48.gif',
        'money_eyes' => '53.gif',
        'beatup' => '56.gif',
        'shock' => '11.gif',
        'cry' => '17.gif',
        'dancing' => '59.gif',
        'plain' => '19.gif',
        'kiss' => '10.gif',
        'blush' => '8.gif',
        'idea' => '45.gif',
        'angel' => '21.gif',
        'praying' => '51.gif',
        'angry' => '12.gif',
        'liarliar' => '55.gif',
        'worried' => '15.gif',
        'rolling_eyes' => '25.gif',
        'skull' => '46.gif',
        'frustrated' => '49.gif',
        'raised_brow' => '20.gif',
        'love' => '7.gif',
        'shame_on_you' => '58.gif',
        'coffee' => '44.gif',
        'sick' => '26.gif',
        'silly' => '30.gif',
        'talk_hand' => '23.gif',
        'drooling' => '32.gif',
        'rose' => '40.gif',
        'tongue' => '9.gif',
        'grin' => '4.gif',
        'sad' => '2.gif',
        'doh!' => '34.gif',
        'wink' => '3.gif',
        'mischief' => '13.gif',
        'chicken' => '39.gif',
        'applause' => '35.gif',
        'clown' => '29.gif',
        'batting' => '5.gif',
        'laugh' => '18.gif',
        'devil' => '16.gif',
        'cowboy' => '50.gif',
        'pig' => '36.gif',
        'whistling' => '54.gif',
        'pumpkin' => '43.gif',
        'flag' => '42.gif',
        'cool' => '14.gif',
        'cow' => '37.gif',
        'hypnotized' => '52.gif',
        'happy' => '1.gif',
        'shhh' => '27.gif',
        'peace' => '57.gif',
        'good_luck' => '41.gif',
        'sleep' => '24.gif',
    ];
}

/**
 * Usage: Build the reverse image-file to face-name lookup table.
 * Referenced by: corebb_legacy_boardface_url_to_name().
 *
 * @return array<string, string> Map of gif filenames to normalized face names.
 */
function corebb_face_file_name_map(): array
{
    $byFile = [];
    foreach (corebb_face_name_file_map() as $name => $file) {
        $byFile[strtolower($file)] = $name;
    }
    return $byFile;
}

/**
 * Usage: Convert old boardfaces image URLs into CoreBB face names.
 * Referenced by: corebb_render_markup() image rendering for imported archive content.
 *
 * @param string $url Stored or imported boardface image URL.
 * @return string Face name, or an empty string when the URL is not recognized.
 */
function corebb_legacy_boardface_url_to_name(string $url): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url) ?? '';
    if ($url === '') {
        return '';
    }

    $file = '';
    if (preg_match('~^(?:https?:)?//[^\s/]+/boardfaces/([1-9]|[1-5][0-9]|60)\.gif(?:[?#].*)?$~i', $url, $m)) {
        $file = $m[1] . '.gif';
    } elseif (preg_match('~^/images/faces/([1-9]|[1-5][0-9]|60)\.gif(?:[?#].*)?$~i', $url, $m)) {
        $file = $m[1] . '.gif';
    }

    if ($file === '') {
        return '';
    }

    $byFile = corebb_face_file_name_map();
    return $byFile[strtolower($file)] ?? '';
}

/**
 * Usage: Render a known board face by normalized name.
 * Referenced by: corebb_render_markup() face tags and legacy boardface image conversion.
 *
 * @param string $name Normalized face name.
 * @return string HTML image fragment, or an empty string when unknown.
 */
function corebb_render_face_image_by_name(string $name): string
{
    $name = strtolower(trim($name));
    $faceNames = corebb_face_name_file_map();
    if (!isset($faceNames[$name])) {
        return '';
    }
    return "<img src='/images/faces/" . $faceNames[$name] . "' style='vertical-align:top;' alt=''>";
}
