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
 |  blog_helpers.php  - Blog helpers for the PHP 8       |
 |  migration.                                           |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/corebb_url_helpers.php';

/**
 * Usage: Escape blog text in legacy PHP contexts.
 * Referenced by: older blog/admin snippets that do not render through Twig.
 */
function corebb_blog_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!defined('COREBB_BLOG_HELPERS_LOADED')) {
    define('COREBB_BLOG_HELPERS_LOADED', true);
}

/**
 * Usage: Quote trusted schema identifiers used by blog migration helpers.
 * Referenced by: corebb_blog_add_column().
 */
function corebb_blog_identifier(string $identifier): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Usage: Trim blog input to the byte limits expected by the old database schema.
 * Referenced by: blog insert/update helpers.
 */
function corebb_blog_limit_text(string $value, int $maxBytes): string {
    if ($maxBytes <= 0) {
        return '';
    }
    if (function_exists('mb_strcut')) {
        return mb_strcut($value, 0, $maxBytes, 'UTF-8');
    }
    return substr($value, 0, $maxBytes);
}

/**
 * Usage: Check whether a blog-related column exists before migration writes.
 * Referenced by: corebb_blog_add_column().
 */
function corebb_blog_column_exists(string $table, string $column): bool {
    $db = corebb_db_connection_name();
    if ($db === '') {
        return false;
    }
    return db_exists(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [$db, $table, $column]
    );
}

/**
 * Usage: Add one missing blog compatibility column in an idempotent way.
 * Referenced by: corebb_blog_ensure_schema().
 */
function corebb_blog_add_column(string $table, string $column, string $definition): void {
    if (!corebb_blog_column_exists($table, $column)) {
        $tableSafe = corebb_blog_identifier($table);
        $columnSafe = corebb_blog_identifier($column);
        db_run("ALTER TABLE {$tableSafe} ADD COLUMN {$columnSafe} {$definition}");
    }
}

/**
 * Usage: Ensure the migrated blog feature has the columns used by current code.
 * Referenced by: blog routes, post blog flow, and blog view models.
 */
function corebb_blog_ensure_schema(): void {
    corebb_blog_add_column('blogs_posts', 'posterid', 'INT NOT NULL DEFAULT 0');
    corebb_blog_add_column('blogs_posts', 'title', "VARCHAR(255) NOT NULL DEFAULT ''");
    corebb_blog_add_column('blogs_posts', 'subject', "VARCHAR(255) NOT NULL DEFAULT ''");
    corebb_blog_add_column('blogs_posts', 'body', 'TEXT NULL');
    corebb_blog_add_column('blogs_posts', 'posttime', "VARCHAR(64) NOT NULL DEFAULT ''");
    corebb_blog_add_column('blogs_posts', 'posttimeraw', 'INT NOT NULL DEFAULT 0');
    corebb_blog_add_column('blogs_posts', 'ptd', "VARCHAR(64) NOT NULL DEFAULT ''");
    corebb_blog_add_column('blogs_posts', 'approved', 'TINYINT(1) NOT NULL DEFAULT 1');
    corebb_blog_add_column('users', 'LockedBlog', 'TINYINT(1) NOT NULL DEFAULT 0');
    corebb_blog_add_column('users', 'blog_posts', 'INT NOT NULL DEFAULT 0');
}

/**
 * Usage: Accept either a numeric user id or username from older blog URLs.
 * Referenced by: corebb_blog_viewblog_model().
 */
function corebb_blog_user_id_from_request($value): int {
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    if (ctype_digit($value)) {
        return (int)$value;
    }
    return (int)db_value('SELECT id FROM users WHERE username = ? LIMIT 1', [$value], 0);
}

/**
 * Usage: Load the small user row needed for blog rendering and permissions.
 * Referenced by: blog view models and lock-state checks.
 */
function corebb_blog_get_user(int $userId) {
    if ($userId <= 0) {
        return false;
    }
    return db_one('SELECT id, username, accesslevel, iconid, regdate, title, blog_posts, LockedBlog FROM users WHERE id = ? LIMIT 1', [$userId]);
}

/**
 * Usage: Fetch one blog entry row by id.
 * Referenced by: blog view/edit/delete models.
 */
function corebb_blog_get_entry(int $entryId) {
    if ($entryId <= 0) {
        return false;
    }
    return db_one('SELECT id, posterid, title, subject, body, posttime, posttimeraw, ptd, approved FROM blogs_posts WHERE id = ? LIMIT 1', [$entryId]);
}

/**
 * Usage: Resolve the display title for a blog entry across old/new columns.
 * Referenced by: blog row, entry, edit, and delete models.
 */
function corebb_blog_title(array $entry): string {
    $title = (string)($entry['title'] ?? '');
    if ($title === '') {
        $title = (string)($entry['subject'] ?? '');
    }
    return $title !== '' ? $title : 'Untitled Blog Entry';
}

/**
 * Usage: Decide whether the current viewer may edit/delete a blog entry.
 * Referenced by: blog row controls, edit model, and delete model.
 */
function corebb_blog_can_modify(array $entry): bool {
    global $MyData, $userlogindata_a;
    if (!corebb_load_logged_in_user()) {
        return false;
    }
    $userId = (int)($MyData['id'] ?? ($userlogindata_a['id'] ?? 0));
    $access = (int)($MyData['accesslevel'] ?? ($userlogindata_a['accesslevel'] ?? 0));
    return $userId > 0 && ((int)($entry['posterid'] ?? 0) === $userId || $access >= 3);
}

/**
 * Usage: Check whether a user's blog is closed to new entries.
 * Referenced by: blog posting and user-info view models.
 */
function corebb_blog_is_locked(int $userId): bool {
    $user = corebb_blog_get_user($userId);
    if (!$user) {
        return true;
    }
    $locked = $user['LockedBlog'] ?? 0;
    return $locked === 'true' || $locked === true || (int)$locked === 1;
}

/**
 * Usage: Store a new blog entry and keep the owner's post count in sync.
 * Referenced by: corebb_post_process_blog().
 */
function corebb_blog_insert_entry(int $posterId, string $title, string $body) {
    corebb_blog_ensure_schema();
    $title = corebb_blog_limit_text(trim($title) !== '' ? trim($title) : 'Untitled Blog Entry', 255);
    $body = corebb_blog_limit_text(corebb_prepare_post_data($body), 65535);
    if (trim($body) === '') {
        $body = '(no message)';
    }
    $now = convert_to_timestamp_raw(time());
    $raw = time();
    $ptd = date('m/d/y');

    $ok = db_run(
        'INSERT INTO blogs_posts (posterid, title, subject, body, posttime, posttimeraw, ptd, approved) VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
        [$posterId, $title, $title, $body, $now, $raw, $ptd]
    );
    if (!$ok) {
        return false;
    }

    /*
     * Capture the new blog id immediately. Some PDO/MySQL combinations report
     * 0 from lastInsertId() after compatibility-layer follow-up queries, even
     * though the INSERT succeeded. That made the post composer show "Error posting blog
     * entry" while the row was actually present.
     */
    $entryId = (int)db_insert_id();
    if ($entryId <= 0) {
        $entryId = (int)db_value(
            'SELECT id FROM blogs_posts WHERE posterid = ? AND posttimeraw = ? AND title = ? ORDER BY id DESC LIMIT 1',
            [$posterId, $raw, $title],
            0
        );
    }

    db_run('UPDATE users SET blog_posts = COALESCE(blog_posts, 0) + 1 WHERE id = ?', [$posterId]);
    return $entryId > 0 ? $entryId : false;
}

/**
 * Usage: Save edited blog title/body text after permission checks.
 * Referenced by: corebb_blog_edit_model().
 */
function corebb_blog_update_entry(int $entryId, string $title, string $body): bool {
    corebb_blog_ensure_schema();
    $title = corebb_blog_limit_text(trim($title) !== '' ? trim($title) : 'Untitled Blog Entry', 255);
    $body = corebb_blog_limit_text(corebb_prepare_post_data($body), 65535);
    if (trim($body) === '') {
        $body = '(no message)';
    }
    $ok = db_run('UPDATE blogs_posts SET title = ?, subject = ?, body = ? WHERE id = ?', [$title, $title, $body, $entryId]);
    return $ok !== false;
}

/**
 * Usage: Delete one blog entry and decrement the owner's displayed count.
 * Referenced by: corebb_blog_delete_model().
 */
function corebb_blog_delete_entry(int $entryId): bool {
    corebb_blog_ensure_schema();
    $entry = corebb_blog_get_entry($entryId);
    if (!$entry) {
        return false;
    }
    $ok = db_run('DELETE FROM blogs_posts WHERE id = ?', [$entryId]);
    if ($ok !== false) {
        db_run('UPDATE users SET blog_posts = GREATEST(COALESCE(blog_posts, 0) - 1, 0) WHERE id = ?', [(int)$entry['posterid']]);
        return true;
    }
    return false;
}

/**
 * Usage: Build the blog sidebar controls consumed by the Twig partial.
 * Referenced by: corebb_blog_shell_model() and views/partials/blog_sidebar.twig.
 */
function corebb_blog_sidebar_links(): array {
    global $MyData;
    if (corebb_load_logged_in_user()) {
        $userId = (int)($MyData['id'] ?? 0);
        return [
            ['label' => 'View My Blog', 'url' => corebb_public_join_base_path('/blogs/my/')],
            ['label' => 'Post Blog Message', 'url' => corebb_public_join_base_path('/blogs/new/')],
            ['label' => 'Edit/Delete Blog Messages', 'url' => corebb_public_join_base_path('/blogs/user/' . $userId . '/')],
            ['label' => 'Close my Blog', 'url' => corebb_public_join_base_path('/blogs/modify/'), 'post' => true, 'method' => 'Lock'],
            ['label' => 'Open my Blog', 'url' => corebb_public_join_base_path('/blogs/modify/'), 'post' => true, 'method' => 'Open'],
        ];
    }
    $registerUrl = corebb_public_join_base_path('/register/');
    $loginUrl = corebb_public_join_base_path('/login/');
    return [
        ['label' => 'Register', 'url' => $registerUrl],
        ['label' => 'Login', 'url' => $loginUrl, 'suffix' => 'to create your own blog!'],
    ];
}

?>
