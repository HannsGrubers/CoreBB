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
require_once __DIR__ . '/corebb_url_helpers.php';
require_once __DIR__ . '/corebb_image_helpers.php';
require_once __DIR__ . '/db.php';

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
    return corebb_public_join_base_path('/profile/' . $userId . '/');
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
    if ($userId > 0) {
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
    if ($postCount <= 0 && $userId > 0) {
        $row = db_one('SELECT posts FROM users WHERE id = ? LIMIT 1', [$userId]);
        $postCount = (int)($row['posts'] ?? 0);
    }

    $level = corebb_star_level_for_posts($postCount);
    $filename = corebb_star_filename_for_level($level);
    if ($level <= 0 || $filename === '') {
        return ['visible' => false];
    }

    $label = $level === 1 ? '1 star' : $level . ' stars';
    $src = corebb_public_join_base_path('images/stars/' . $filename);
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
    if ($userId <= 0) {
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
            'src' => corebb_public_join_base_path('images/noiconblog.gif'),
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
                'prompt_url' => corebb_public_join_base_path('/user-cp/avatar/'),
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

    $path = corebb_safe_local_image_asset((string)($icon['filepath'] ?? ''));
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

/* PHP 8 migration helpers for user profile/forum option columns. */
/**
 * Usage: Check whether an optional user-profile column already exists.
 * Referenced by: corebb_user_add_column() and profile migration guards.
 *
 * @param string $column Column name to check in the users table.
 * @return bool True when the column is present.
 */
function corebb_user_column_exists(string $column): bool {
    return db_exists("SHOW COLUMNS FROM `users` LIKE ?", [$column]);
}

/**
 * Usage: Add a missing optional users column during lightweight migrations.
 * Referenced by: corebb_user_ensure_profile_columns().
 *
 * @param string $column Safe users-table column name.
 * @param string $definition SQL column definition to append to ALTER TABLE.
 * @return void
 */
function corebb_user_add_column(string $column, string $definition): void {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return;
    }
    if (!corebb_user_column_exists($column)) {
        db_run("ALTER TABLE `users` ADD COLUMN `" . str_replace('`', '``', $column) . "` " . $definition);
    }
}

/**
 * Usage: Ensure profile paging and signature columns exist before reads/writes.
 * Referenced by: signature helpers and profile/user-control workflows.
 *
 * @return void
 */
function corebb_user_ensure_profile_columns(): void {
    corebb_user_add_column('ThreadPages', 'INT NOT NULL DEFAULT 25');
    corebb_user_add_column('BoardPages', 'INT NOT NULL DEFAULT 25');
    corebb_user_add_column('signature', 'TEXT NULL');
    for ($i = 1; $i <= 5; $i++) {
        corebb_user_add_column('sig' . $i, "VARCHAR(255) NOT NULL DEFAULT ''");
    }
}

/**
 * Usage: Build the display signature from either legacy sig1-sig5 fields or signature,
 * which is just the new line separated full signature text.
 * Referenced by: corebb_user_signature_text() and profile view models.
 *
 * @param array<string, mixed> $row User row containing legacy or current signature fields.
 * @return string Plain signature text ready for the markup pipeline.
 */
function corebb_user_signature_from_row(array $row): string {
    $lines = [];
    for ($i = 1; $i <= 5; $i++) {
        $value = trim((string)($row['sig' . $i] ?? ''));
        if ($value !== '' && $value !== '$') {
            $lines[] = $value;
        }
    }
    if ($lines) {
        return implode("\n", $lines);
    }

    $signature = trim((string)($row['signature'] ?? ''));
    return $signature;
}

/**
 * Usage: Convert a post count into the CoreBB star rank number.
 * Referenced by: corebb_render_user_star_from_post_count().
 *
 * @param int $postcount Total posts credited to the user.
 * @return int Star level from 0 through 10.
 */
function corebb_star_level_for_posts(int $postcount): int{
    $postcount = max(0, $postcount);
    if ($postcount >= 50000) { return 10; }
    if ($postcount >= 40000) { return 9; }
    if ($postcount >= 30000) { return 8; }
    if ($postcount >= 20000) { return 7; }
    if ($postcount >= 10000) { return 6; }
    if ($postcount >= 5000) { return 5; }
    if ($postcount >= 1000) { return 4; }
    if ($postcount >= 500) { return 3; }
    if ($postcount >= 250) { return 2; }
    if ($postcount >= 50) { return 1; }
    return 0;
}

/**
 * Usage: Resolve a star level to its image filename.
 * Referenced by: corebb_render_user_star_from_post_count().
 *
 * @param int $level Star level from corebb_star_level_for_posts().
 * @return string Star image filename, or an empty string for no star.
 */
function corebb_star_filename_for_level(int $level): string{
    return match ($level) {
        1 => 'star.gif',
        2 => 'star2.gif',
        3 => 'star3.gif',
        4 => 'star4.gif',
        5 => 'star5.gif',
        6 => 'star6.gif',
        7 => 'star7.gif',
        8 => 'star8.gif',
        9 => 'star9.gif',
        10 => 'star10.gif',
        default => '',
    };
}

/**
 * Usage: Render the star image fragment for a known post count.
 * Referenced by: corebb_render_user_star() and profile/topic helpers.
 *
 * @param mixed $postcount Numeric post count from a user row or aggregate query.
 * @return string HTML image fragment, or an empty string when no star is earned.
 */
function corebb_render_user_star_from_post_count($postcount): string{
    $level = corebb_star_level_for_posts((int)$postcount);
    if ($level <= 0) {
        return '';
    }

    $filename = corebb_star_filename_for_level($level);
    if ($filename === '') {
        return '';
    }

    $src = corebb_public_join_base_path('images/stars/' . $filename);
    $label = $level === 1 ? '1 star' : $level . ' stars';
    return "&nbsp;<img src='" . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . "' style='vertical-align:middle;' alt='" . $label . "' title='" . $label . "'>";
}

/**
 * Usage: Render the star image fragment for a user id or username.
 * Referenced by: profile pages, postbit helpers, and templates.
 *
 * @param mixed $userid User id by default, or username when non-numeric/forced.
 * @param bool $force_str Treat $userid as a username even when it is numeric text.
 * @return string HTML image fragment, or an empty string when the user is missing.
 */
function corebb_render_user_star($userid, $force_str = false) {
    if (!is_numeric($userid) || $force_str) {
        $user = (string)$userid;
        $row = db_one("SELECT id, posts FROM `users` WHERE `username` = ? LIMIT 1", [$user]);
    } else {
        $user = (int)$userid;
        $row = db_one("SELECT id, posts FROM `users` WHERE `id` = ? LIMIT 1", [$user]);
    }

    if (!$row) {
        return '';
    }

    return corebb_render_user_star_from_post_count((int)($row['posts'] ?? 0));
}

/**
 * Usage: Format a username with optional VIP styling and profile link.
 * Referenced by: board rows, profile pages, blogs, PMs, and admin log viewers.
 *
 * @param mixed $userid User id by default, or username when non-numeric/forced.
 * @param bool $style When false, return the raw username without a link.
 * @param bool $force_str Treat $userid as a username even when it is numeric text.
 * @return string Username text or linked/styled HTML fragment.
 */
function corebb_render_username($userid, $style = true, $force_str = false) {
    if (!is_numeric($userid) || $force_str) {
        $user = (string)$userid;
        $isnumeric = false;
    } else {
        $user = (int)$userid;
        $isnumeric = true;
    }

    if (!$isnumeric) {
        $userdata = db_one("SELECT * FROM `users` WHERE `username` = ? LIMIT 1", [$user]);
    } else {
        $userdata = db_one("SELECT * FROM `users` WHERE `id` = ? LIMIT 1", [$user]);
    }

    if (!$userdata) {
        return '';
    }

    $username = htmlspecialchars((string)$userdata['username'], ENT_QUOTES, 'UTF-8');
    $profileId = (int)$userdata['id'];

    if (!$style) {
        return (string)$userdata['username'];
    }

    $styleAttr = corebb_vip_style_attr_for_user($userdata);
    $profileUrl = htmlspecialchars(corebb_public_join_base_path('/profile/' . $profileId . '/'), ENT_QUOTES, 'UTF-8');

    if ($styleAttr !== '') {
        return "<a class='AuthorLink' $styleAttr href='$profileUrl'>$username</a>";
    }

    return "<a class='AuthorLink' href='$profileUrl'>$username</a>";
}

/**
 * Usage: Render a user's approved avatar/icon fragment.
 * Referenced by: profile pages, blog listings, and postbit helpers.
 *
 * @param mixed $userid User id to load.
 * @param bool $blog Use the compact blog icon dimensions and fallback.
 * @return string HTML icon wrapper, or an empty string when no icon should show.
 */
function corebb_render_user_icon($userid, $blog = false) {
    $userid = (int)$userid;
    if ($userid <= 0) {
        return '';
    }

    $userdata = db_one('SELECT id, iconid FROM `users` WHERE `id` = ? LIMIT 1', [$userid]);
    if (!$userdata) {
        return '';
    }

    $user_icon = (int)($userdata['iconid'] ?? 0);
    $img = '';
    if ($blog) {
        if (!$user_icon) {
            $img = "<img src='/images/noiconblog.gif' height='40' width='40' alt='No icon'>";
        } else {
            $icon = db_one('SELECT * FROM `icons` WHERE `id` = ? LIMIT 1', [$user_icon]);
            $approved = !$icon || !array_key_exists('approved', $icon) ? 1 : (int)$icon['approved'];
            $path = ($icon && $approved) ? htmlspecialchars(corebb_safe_local_image_asset((string)($icon['filepath'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
            $alt = $icon ? htmlspecialchars((string)($icon['filename'] ?? 'User icon'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'User icon';
            if ($path !== '') {
                $img = "<img src='$path' height='40' width='40' alt='$alt'>";
            }
        }
    } else {
        if (!$user_icon) {
            $sessionUserId = (int)($_SESSION['userid'] ?? 0);
            if ($userid === $sessionUserId) {
                $img = "<br/>&nbsp;&nbsp;&nbsp;[<a href=\"" . corebb_public_join_base_path('/user-cp/avatar/') . "\">your icon here</a>]";
            }
        } else {
            $icon = db_one('SELECT * FROM `icons` WHERE `id` = ? LIMIT 1', [$user_icon]);
            $approved = !$icon || !array_key_exists('approved', $icon) ? 1 : (int)$icon['approved'];
            $path = ($icon && $approved) ? htmlspecialchars(corebb_safe_local_image_asset((string)($icon['filepath'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
            $alt = $icon ? htmlspecialchars((string)($icon['filename'] ?? 'User icon'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'User icon';
            if ($path !== '') {
                $img = "<img src='$path' alt='$alt'>";
            }
        }
    }

    if ($img === '') {
        return '';
    }
    return "<span class='wb-user-icon-frame'>" . $img . "</span>\n";
}

/**
 * Usage: Format a user's stored or recalculated post count.
 * Referenced by: profile, member, and postbit display helpers.
 *
 * @param mixed $userid User id to count posts for.
 * @param bool $real When true, count rows in posts instead of reading users.posts.
 * @return string Number-formatted post count.
 */
function corebb_user_post_count_display($userid, $real = false) {
    $userid = (int)$userid;

    if ($real) {
        $query_num = (int)db_value("SELECT COUNT(*) FROM `posts` WHERE `posterid` = ?", [$userid], 0);
        return number_format($query_num);
    }

    $query_num = (int)db_value("SELECT posts FROM `users` WHERE `id` = ? LIMIT 1", [$userid], 0);
    return number_format($query_num);
}

/**
 * Usage: Load a user's stored signature text.
 * Referenced by: profile pages, postbit helpers, and signature previews.
 *
 * @param mixed $userid User id by default, or username when non-numeric.
 * @return string Plain signature text.
 */
function corebb_user_signature_text($userid){
    corebb_user_ensure_profile_columns();
	$sigoutput = "";
	$usrsig = is_numeric($userid) ? db_one("SELECT * FROM `users` WHERE `id` = ? LIMIT 1", [(int)$userid]) : db_one("SELECT * FROM `users` WHERE `username` = ? LIMIT 1", [(string)$userid]);
	if($usrsig){
		$sigoutput = corebb_user_signature_from_row($usrsig);
	}
	return stripslashes($sigoutput);
}

/**
 * Usage: Convert a numeric access level into a profile/admin display label.
 * Referenced by: profile display and admin user views.
 *
 * @param mixed $level Numeric access level.
 * @return string Human-readable level label.
 */
function corebb_user_level_label($level){
	if($level == 5){
		return "Administrator";
	}
	else if($level == 4){
		return "Manager";
	}
	else if($level == 3){
		return "Moderator";
	}
	else if($level == 2){
		return "VIP";
	}
	else if($level == 1){
		return "User";
	}
	else{
		return "Unknown";
	}
}
