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
 |  user_display_helpers.php - Public user display       |
 |  view models.                                        |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/vip_style_helpers.php';

/**
 * Usage: Build a public profile URL for a user display model.
 * Referenced by: corebb_user_name_model().
 *
 * @param int $userId User id to link to.
 * @return string Profile URL, or an empty string for invalid users.
 */
function corebb_user_display_profile_url(int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    return function_exists('corebb_public_url') ? corebb_public_url('content.php?action=profile&id=' . $userId) : '/profile/' . $userId . '/';
}

/**
 * Usage: Resolve VIP/custom username CSS from structured style columns or legacy style text.
 * Referenced by: corebb_user_name_model().
 *
 * @param array<string, mixed> $user User row.
 * @return string Inline CSS style value, or an empty string when none applies.
 */
function corebb_user_display_style_css(array $user): string
{
    $css = corebb_vip_style_css_from_values(corebb_vip_style_values_from_user($user));
    if ($css !== '') {
        return $css;
    }

    $legacy = trim((string)($user['style'] ?? ''));
    if ($legacy !== ''
        && corebb_vip_style_user_can_self_manage((int)($user['id'] ?? 0), $user)
        && preg_match('/style=(["\'])(.*?)\1/i', $legacy, $match) === 1) {
        return trim(html_entity_decode((string)$match[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    return '';
}

/**
 * Usage: Build a Twig-ready username display model.
 * Referenced by: Twig user_name_model() and API serializers.
 *
 * @param int $userId User id to resolve.
 * @param string $fallback Name to use if the user row is missing.
 * @param bool $style Whether profile links and VIP styling should be included.
 * @return array<string, mixed> Username display state for partials/user_name.twig.
 */
function corebb_user_name_model(int $userId, string $fallback = 'Unknown', bool $style = true): array
{
    $user = null;
    if ($userId > 0 && function_exists('db_one')) {
        $row = db_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]);
        $user = is_array($row) ? $row : null;
    }

    $resolvedId = (int)($user['id'] ?? $userId);
    $username = trim((string)($user['username'] ?? $fallback));

    return [
        'id' => $resolvedId,
        'username' => $username,
        'profile_url' => corebb_user_display_profile_url($resolvedId),
        'style_css' => $style && $user ? corebb_user_display_style_css($user) : '',
        'linked' => $style && $user && $resolvedId > 0,
        'blank' => $username === '',
    ];
}

/**
 * Usage: Build a post-count star badge model for user display.
 * Referenced by: Twig user_star_model().
 *
 * @param int $postCount Known post count, or 0 to load it by user id.
 * @param int $userId User id used when the post count must be loaded.
 * @return array<string, mixed> Star badge display state for partials/user_star.twig.
 */
function corebb_user_star_model(int $postCount, int $userId = 0): array
{
    if ($postCount <= 0 && $userId > 0 && function_exists('db_one')) {
        $row = db_one('SELECT posts FROM users WHERE id = ? LIMIT 1', [$userId]);
        $postCount = (int)($row['posts'] ?? 0);
    }

    $level = function_exists('corebb_star_level_for_posts') ? corebb_star_level_for_posts($postCount) : 0;
    $filename = function_exists('corebb_star_filename_for_level') ? corebb_star_filename_for_level($level) : '';
    if ($level <= 0 || $filename === '') {
        return ['visible' => false];
    }

    $label = $level === 1 ? '1 star' : $level . ' stars';
    $src = function_exists('corebb_public_asset') ? corebb_public_asset('images/stars/' . $filename) : '/images/stars/' . $filename;
    return [
        'visible' => true,
        'src' => $src,
        'label' => $label,
    ];
}

/**
 * Usage: Build a user icon/avatar model for profile, post, and blog partials.
 * Referenced by: Twig user_icon_model().
 *
 * @param int $userId User id whose icon should be loaded.
 * @param bool $blog Whether to use blog-sized icon defaults.
 * @return array<string, mixed> Icon display state for partials/user_icon.twig.
 */
function corebb_user_icon_model(int $userId, bool $blog = false): array
{
    if ($userId <= 0 || !function_exists('db_one')) {
        return ['visible' => false];
    }

    $user = db_one('SELECT id, iconid FROM users WHERE id = ? LIMIT 1', [$userId]);
    if (!$user) {
        return ['visible' => false];
    }

    $iconId = (int)($user['iconid'] ?? 0);
    if ($blog && $iconId <= 0) {
        return [
            'visible' => true,
            'src' => function_exists('corebb_public_asset') ? corebb_public_asset('images/noiconblog.gif') : '/images/noiconblog.gif',
            'alt' => 'No icon',
            'height' => 40,
            'width' => 40,
            'prompt_url' => '',
            'prompt_label' => '',
        ];
    }

    if ($iconId <= 0) {
        $sessionUserId = (int)($_SESSION['userid'] ?? 0);
        if ($userId === $sessionUserId) {
            return [
                'visible' => true,
                'src' => '',
                'alt' => '',
                'height' => 0,
                'width' => 0,
                'prompt_url' => function_exists('corebb_public_url') ? corebb_public_url('usercp.php?action=avatar') : '/user-cp/avatar/',
                'prompt_label' => 'your icon here',
            ];
        }
        return ['visible' => false];
    }

    $icon = db_one('SELECT * FROM icons WHERE id = ? LIMIT 1', [$iconId]);
    $approved = !$icon || !array_key_exists('approved', $icon) ? 1 : (int)$icon['approved'];
    if (!$icon || !$approved) {
        return ['visible' => false];
    }

    $path = function_exists('corebb_safe_local_image_asset') ? corebb_safe_local_image_asset((string)($icon['filepath'] ?? '')) : (string)($icon['filepath'] ?? '');
    if (trim($path) === '') {
        return ['visible' => false];
    }

    return [
        'visible' => true,
        'src' => $path,
        'alt' => (string)($icon['filename'] ?? 'User icon'),
        'height' => $blog ? 40 : 0,
        'width' => $blog ? 40 : 0,
        'prompt_url' => '',
        'prompt_label' => '',
    ];
}

/**
 * Usage: Wrap a user title for late title markup rendering.
 * Referenced by: Twig user_title_model().
 *
 * @param string $title Stored profile/user title.
 * @return array<string, mixed> Title display state for partials/user_title.twig.
 */
function corebb_user_title_model(string $title): array
{
    $title = trim($title);
    return [
        'visible' => $title !== '',
        'content' => [
            'type' => 'user_title',
            'body' => $title,
            'options' => [],
        ],
    ];
}
