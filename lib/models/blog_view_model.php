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
 |  blog_view_model.php  - Blog view-model helpers.      |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../helpers/blog_helpers.php';

/**
 * Usage: Return the logged-in user id from either modern or legacy globals.
 * Referenced by: controllers/blogs.php redirects and blog sidebar routing.
 */
function corebb_blog_current_user_id(): int
{
    global $MyData, $userlogindata_a;
    return (int)($MyData['id'] ?? ($userlogindata_a['id'] ?? 0));
}

/**
 * Usage: Expose the legacy short PHP suffix for templates that still need it.
 * Referenced by: corebb_blog_shell_model().
 */
function corebb_blog_short_php(): string
{
    global $ShortPHP;
    return (string)($ShortPHP ?? '.php');
}

/**
 * Usage: Provide common blog page chrome data shared by blog templates.
 * Referenced by: all blog page view-model builders.
 */
function corebb_blog_shell_model(string $sidebarTitle = 'My Controls'): array
{
    $short = corebb_blog_short_php();
    return [
        'shortPHP' => $short,
        'sidebarTitle' => $sidebarTitle,
        'controls' => corebb_blog_sidebar_links(),
    ];
}

/**
 * Usage: Resolve a user id to a display username for blog rows.
 * Referenced by: blog entry row and entry view models.
 */
function corebb_blog_username(int $userId): string
{
    if ($userId <= 0) {
        return 'Unknown';
    }
    return (string)db_value('SELECT username FROM users WHERE id = ? LIMIT 1', [$userId], 'Unknown');
}

/**
 * Usage: Load and cache a user's custom title for blog display.
 * Referenced by: corebb_blog_entry_row_model().
 */
function corebb_blog_user_title(int $userId): string
{
    static $cache = [];
    if ($userId <= 0) {
        return '';
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    $cache[$userId] = (string)db_value('SELECT title FROM users WHERE id = ? LIMIT 1', [$userId], '');
    return $cache[$userId];
}

/**
 * Usage: Convert a blogs_posts row into the compact list-row model Twig expects.
 * Referenced by: blog home and user blog pages.
 */
function corebb_blog_entry_row_model(array $entry, bool $showControls = false): array
{
    $posterId = (int)($entry['posterid'] ?? 0);
    return [
        'id' => (int)($entry['id'] ?? 0),
        'posterId' => $posterId,
        'authorName' => corebb_blog_username($posterId),
        'authorTitle' => corebb_blog_user_title($posterId),
        'title' => corebb_blog_title($entry),
        'bodyPreview' => corebb_trim_words((string)($entry['body'] ?? ''), 45),
        'posttime' => (string)($entry['posttime'] ?? ''),
        'showControls' => $showControls && corebb_blog_can_modify($entry),
    ];
}

/**
 * Usage: Convert a user row into the blog sidebar user-info model.
 * Referenced by: user blog and entry pages.
 */
function corebb_blog_user_info_model(array $user): array
{
    $userId = (int)($user['id'] ?? 0);
    return [
        'id' => $userId,
        'username' => (string)($user['username'] ?? corebb_blog_username($userId)),
        'userTitle' => (string)($user['title'] ?? corebb_blog_user_title($userId)),
        'blogPosts' => number_format((int)($user['blog_posts'] ?? 0)),
        'regdate' => (string)($user['regdate'] ?? ''),
        'locked' => corebb_blog_is_locked($userId),
    ];
}

/**
 * Usage: Build the public blog landing page model.
 * Referenced by: controllers/blogs.php home route.
 */
function corebb_blog_home_model(): array
{
    corebb_blog_ensure_schema();
    $model = corebb_blog_shell_model('My Controls');
    $model['page'] = 'home';
    $model['latestEntries'] = [];
    $model['leaders'] = [];

    foreach (db_all('SELECT * FROM blogs_posts ORDER BY id DESC LIMIT 10') as $row) {
        $model['latestEntries'][] = corebb_blog_entry_row_model($row, false);
    }

    foreach (db_all('SELECT * FROM users WHERE COALESCE(blog_posts, 0) > 0 ORDER BY blog_posts DESC, username ASC LIMIT 25') as $row) {
        $userId = (int)($row['id'] ?? 0);
        $model['leaders'][] = [
            'id' => $userId,
            'username' => (string)($row['username'] ?? corebb_blog_username($userId)),
            'blogPosts' => number_format((int)($row['blog_posts'] ?? 0)),
            'regdate' => (string)($row['regdate'] ?? ''),
            'boardPosts' => number_format((int)($row['posts'] ?? 0)),
        ];
    }

    return $model;
}

/**
 * Usage: Build the model for one user's blog page.
 * Referenced by: controllers/blogs.php viewblog route.
 */
function corebb_blog_viewblog_model($requestedId): array
{
    corebb_blog_ensure_schema();
    $userId = corebb_blog_user_id_from_request($requestedId);
    $user = corebb_blog_get_user($userId);
    if (!$user) {
        return [
            'error' => 'Sorry, unknown user!',
            'returnUrl' => '/blogs/',
            'returnText' => 'Return to Blogs',
        ];
    }

    $model = corebb_blog_shell_model('My Controls');
    $model['page'] = 'viewblog';
    $model['user'] = corebb_blog_user_info_model($user);
    $model['entries'] = [];

    if (!$model['user']['locked']) {
        foreach (db_all('SELECT * FROM blogs_posts WHERE posterid = ? ORDER BY id DESC', [(int)$user['id']]) as $row) {
            $model['entries'][] = corebb_blog_entry_row_model($row, true);
        }
    }

    return $model;
}

/**
 * Usage: Build the model for a single blog entry page.
 * Referenced by: controllers/blogs.php viewentry route.
 */
function corebb_blog_viewentry_model(int $entryId): array
{
    corebb_blog_ensure_schema();
    $entry = corebb_blog_get_entry($entryId);
    if (!$entry) {
        return [
            'error' => 'Error! Requested message does not exist!',
            'returnUrl' => '/blogs/',
            'returnText' => 'Return to Blogs',
        ];
    }
    $user = corebb_blog_get_user((int)$entry['posterid']);
    if (!$user) {
        return [
            'error' => 'Error! Requested blog owner does not exist!',
            'returnUrl' => '/blogs/',
            'returnText' => 'Return to Blogs',
        ];
    }

    $model = corebb_blog_shell_model('My Controls');
    $model['page'] = 'viewentry';
    $model['user'] = corebb_blog_user_info_model($user);
    $model['entry'] = $entry;
    $model['entryModel'] = [
        'id' => (int)$entry['id'],
        'posterId' => (int)$entry['posterid'],
        'title' => corebb_blog_title($entry),
        'body' => (string)($entry['body'] ?? ''),
        'posttime' => (string)($entry['posttime'] ?? ''),
        'authorName' => corebb_blog_username((int)$entry['posterid']),
        'canModify' => corebb_blog_can_modify($entry),
    ];
    return $model;
}

/**
 * Usage: Build or process the blog-entry edit form.
 * Referenced by: controllers/blogs.php edit route.
 */
function corebb_blog_edit_model(array $request, array $post, string $method): array
{
    corebb_blog_ensure_schema();
    if (!corebb_load_logged_in_user()) {
        return [
            'error' => 'Sorry, you must be logged in to edit blog entries!',
            'returnUrl' => '/blogs/',
            'returnText' => 'Return to Blogs',
        ];
    }

    $id = (int)($request['id'] ?? $post['id'] ?? 0);
    $entry = corebb_blog_get_entry($id);
    if (!$entry || !corebb_blog_can_modify($entry)) {
        return [
            'error' => 'Sorry, unknown blog entry or insufficient permissions.',
            'returnUrl' => '/blogs/',
            'returnText' => 'Return to Blogs',
        ];
    }

    if ($method === 'POST') {
        $title = (string)($post['message_subject'] ?? '');
        $body = (string)($post['message_body'] ?? '');
        if (corebb_blog_update_entry($id, $title, $body)) {
            return [
                'result' => 'Your blog entry has been updated.',
                'returnUrl' => '/blogs/entry/' . $id . '/',
                'returnText' => 'View it here',
            ];
        }
        return [
            'error' => 'Error updating blog entry: ' . db_error(),
            'returnUrl' => '/blogs/entry/' . $id . '/edit/',
            'returnText' => 'Try again',
        ];
    }

    return [
        'page' => 'edit',
        'id' => $id,
        'subject' => corebb_blog_title($entry),
        'body' => (string)($entry['body'] ?? ''),
    ];
}

/**
 * Usage: Build or process the blog-entry delete confirmation form.
 * Referenced by: controllers/blogs.php delete route.
 */
function corebb_blog_delete_model(array $request, array $post, string $method): array
{
    corebb_blog_ensure_schema();
    if (!corebb_load_logged_in_user()) {
        return [
            'error' => 'Sorry, you must be logged in to delete blog entries!',
            'returnUrl' => '/blogs/',
            'returnText' => 'Return to Blogs',
        ];
    }

    $id = (int)($request['id'] ?? $post['id'] ?? 0);
    $entry = corebb_blog_get_entry($id);
    if (!$entry || !corebb_blog_can_modify($entry)) {
        return [
            'error' => 'Sorry, unknown blog entry or insufficient permissions.',
            'returnUrl' => '/blogs/',
            'returnText' => 'Return to Blogs',
        ];
    }

    if ($method === 'POST' && (string)($post['confirm'] ?? '') === '1') {
        if (corebb_blog_delete_entry($id)) {
            return [
                'result' => 'Blog entry deleted.',
                'returnUrl' => '/blogs/',
                'returnText' => 'Return to Blogs',
            ];
        }
        return [
            'error' => 'Error deleting blog entry: ' . db_error(),
            'returnUrl' => '/blogs/entry/' . $id . '/',
            'returnText' => 'Cancel',
        ];
    }

    return [
        'page' => 'delete',
        'id' => $id,
        'title' => corebb_blog_title($entry),
    ];
}
