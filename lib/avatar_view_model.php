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
 |  avatar_view_model.php  - Avatar/icon helpers.        |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/corebb_browser_helpers.php';

const COREBB_AVATAR_MAX_WIDTH = 120;
const COREBB_AVATAR_MAX_HEIGHT = 120;
const COREBB_AVATAR_MAX_BYTES = 524288; // 512 KB
const COREBB_AVATAR_UPLOAD_DIR = 'images/user_avatars';

/**
 * Return the active database name for avatar schema checks.
 *
 * Usage: inspect INFORMATION_SCHEMA before adding avatar/icon columns.
 * Referenced by: avatar table and column existence helpers.
 *
 * @return string Current database/schema name.
 */
function corebb_avatar_db_name(): string
{
    return corebb_db_connection_name();
}

/**
 * Validate a table or column identifier used in avatar schema SQL.
 *
 * Usage: reject unsafe dynamic identifiers before composing ALTER/lookup SQL.
 * Referenced by: avatar table, column, and add-column helpers.
 *
 * @param string $identifier Identifier candidate.
 * @return bool True when the identifier is alphanumeric/underscore only.
 */
function corebb_avatar_valid_identifier(string $identifier): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_]+$/', $identifier);
}

/**
 * Quote a validated avatar schema identifier.
 *
 * Usage: build ALTER TABLE statements for known-safe table and column names.
 * Referenced by: corebb_avatar_add_column().
 *
 * @param string $identifier Validated database identifier.
 * @return string Backtick-quoted identifier.
 */
function corebb_avatar_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Check whether an avatar-related table exists.
 *
 * Usage: create the icons table only when older installs do not have it yet.
 * Referenced by: corebb_avatar_ensure_schema() and model metadata.
 *
 * @param string $table Table name to inspect.
 * @return bool True when the table exists.
 */
function corebb_avatar_table_exists(string $table): bool
{
    $db = corebb_avatar_db_name();
    if ($db === '' || !corebb_avatar_valid_identifier($table)) {
        return false;
    }
    return (int)db_value(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        [$db, $table],
        0
    ) > 0;
}

/**
 * Check whether an avatar-related column exists.
 *
 * Usage: keep avatar schema upgrades idempotent across partially upgraded
 * installs.
 * Referenced by: corebb_avatar_add_column() and corebb_avatar_ensure_schema().
 *
 * @param string $table Table name to inspect.
 * @param string $column Column name to inspect.
 * @return bool True when the column exists.
 */
function corebb_avatar_column_exists(string $table, string $column): bool
{
    $db = corebb_avatar_db_name();
    if ($db === '' || !corebb_avatar_valid_identifier($table) || !corebb_avatar_valid_identifier($column)) {
        return false;
    }
    return (int)db_value(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$db, $table, $column],
        0
    ) > 0;
}

/**
 * Add one avatar/icon column when it is missing.
 *
 * Usage: evolve the icons table without requiring a manual migration step.
 * Referenced by: avatar schema setup and admin icon maintenance.
 *
 * @param string $table Table to alter.
 * @param string $column Column to add.
 * @param string $definition SQL column definition.
 * @return void
 */
function corebb_avatar_add_column(string $table, string $column, string $definition): void
{
    if (!corebb_avatar_valid_identifier($table) || !corebb_avatar_valid_identifier($column)) {
        return;
    }
    if (!corebb_avatar_column_exists($table, $column)) {
        db_run('ALTER TABLE ' . corebb_avatar_quote_identifier($table) . ' ADD COLUMN ' . corebb_avatar_quote_identifier($column) . ' ' . $definition);
    }
}

/**
 * Ensure avatar/icon tables and columns exist.
 *
 * Usage: call before reading, listing, selecting, uploading, or administering
 * avatars.
 * Referenced by: controllers/usercp.php action=avatar, admin icon tools, and avatar helpers.
 *
 * @return void
 */
function corebb_avatar_ensure_schema(): void
{
    if (!corebb_avatar_table_exists('icons')) {
        db_run(
            "CREATE TABLE IF NOT EXISTS `icons` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `filepath` VARCHAR(255) NOT NULL DEFAULT '',
                `filename` VARCHAR(255) NOT NULL DEFAULT '',
                `mime` VARCHAR(255) NOT NULL DEFAULT '',
                `userid` INT NOT NULL DEFAULT 0,
                `uploaded` TINYINT(1) NOT NULL DEFAULT 0,
                `uploaded_at` INT NOT NULL DEFAULT 0,
                `approved` TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                KEY `idx_icons_userid` (`userid`),
                KEY `idx_icons_uploaded` (`uploaded`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    corebb_avatar_add_column('icons', 'filepath', "VARCHAR(255) NOT NULL DEFAULT ''");
    corebb_avatar_add_column('icons', 'filename', "VARCHAR(255) NOT NULL DEFAULT ''");
    corebb_avatar_add_column('icons', 'mime', "VARCHAR(255) NOT NULL DEFAULT ''");
    corebb_avatar_add_column('icons', 'userid', 'INT NOT NULL DEFAULT 0');
    corebb_avatar_add_column('icons', 'uploaded', 'TINYINT(1) NOT NULL DEFAULT 0');
    corebb_avatar_add_column('icons', 'uploaded_at', 'INT NOT NULL DEFAULT 0');
    corebb_avatar_add_column('icons', 'approved', 'TINYINT(1) NOT NULL DEFAULT 1');

    if (!corebb_avatar_column_exists('users', 'iconid')) {
        db_run('ALTER TABLE `users` ADD COLUMN `iconid` INT NOT NULL DEFAULT 0');
    }
}

/**
 * Fetch the current avatar user row.
 *
 * Usage: build the avatar selection model for the logged-in user.
 * Referenced by: corebb_avatar_model().
 *
 * @param int $uid User id to fetch.
 * @return array<string, mixed> User row or empty array.
 */
function corebb_avatar_current_user(int $uid): array
{
    if ($uid <= 0) {
        return [];
    }
    corebb_avatar_ensure_schema();
    $row = db_one('SELECT id, username, iconid FROM users WHERE id = ?', [$uid]);
    return is_array($row) ? $row : [];
}

/**
 * Count avatar icons available to a user.
 *
 * Usage: size pagination for built-in icons plus the user's own uploads.
 * Referenced by: corebb_avatar_model().
 *
 * @param int $uid User id whose available icons are counted.
 * @return int Number of selectable icons.
 */
function corebb_avatar_count(int $uid): int
{
    if ($uid <= 0) {
        return 0;
    }
    corebb_avatar_ensure_schema();
    return (int)db_value('SELECT COUNT(*) FROM icons WHERE approved = 1 AND (uploaded = 0 OR userid = ?)', [$uid], 0);
}

/**
 * List avatar icons available to a user for one page.
 *
 * Usage: feed the user control panel avatar picker.
 * Referenced by: corebb_avatar_model().
 *
 * @param int $uid User id whose icons are listed.
 * @param int $page One-based page number.
 * @param int $perPage Icons per page, capped to 100.
 * @return array<int, array<string, mixed>> Avatar icon rows.
 */
function corebb_avatar_list(int $uid, int $page, int $perPage): array
{
    if ($uid <= 0) {
        return [];
    }
    corebb_avatar_ensure_schema();
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset = ($page - 1) * $perPage;
    $sql = 'SELECT id, filepath, filename, mime, userid, uploaded, uploaded_at, approved FROM icons WHERE approved = 1 AND (uploaded = 0 OR userid = ?) ORDER BY uploaded ASC, id ASC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
    return db_all($sql, [$uid]);
}

/**
 * Check whether an icon id is selectable by a user.
 *
 * Usage: validate avatar selection submissions before updating users.iconid.
 * Referenced by: corebb_avatar_handle_select().
 *
 * @param int $uid User id attempting the selection.
 * @param int $iconId Icon id, or 0 to clear the avatar.
 * @return bool True when the icon is public or belongs to the user.
 */
function corebb_avatar_icon_exists_for_user(int $uid, int $iconId): bool
{
    if ($uid <= 0) {
        return false;
    }
    if ($iconId <= 0) {
        return true;
    }
    corebb_avatar_ensure_schema();
    return db_exists('SELECT 1 FROM icons WHERE id = ? AND approved = 1 AND (uploaded = 0 OR userid = ?) LIMIT 1', [$iconId, $uid]);
}

/**
 * Return the absolute forum root directory.
 *
 * Usage: anchor avatar upload paths inside the forum tree.
 * Referenced by: avatar upload path helpers.
 *
 * @return string Absolute forum root path.
 */
function corebb_avatar_forum_root_abs(): string
{
    return dirname(__DIR__);
}

/**
 * Return the absolute avatar upload directory.
 *
 * Usage: create, verify, and clean uploaded avatar files.
 * Referenced by: upload directory, admin icon, and path validation helpers.
 *
 * @return string Absolute avatar upload directory.
 */
function corebb_avatar_upload_dir_abs(): string
{
    return corebb_avatar_forum_root_abs() . '/' . COREBB_AVATAR_UPLOAD_DIR;
}

/**
 * Verify that a resolved path is inside a resolved directory.
 *
 * Usage: prevent avatar upload/delete operations from escaping the intended
 * upload folder.
 * Referenced by: user avatar upload and admin icon maintenance.
 *
 * @param string $path Candidate file path.
 * @param string $dir Required containing directory.
 * @return bool True when both paths resolve and the file is inside the dir.
 */
function corebb_avatar_path_inside_dir(string $path, string $dir): bool
{
    $realDir = realpath($dir);
    $realPath = realpath($path);
    if ($realDir === false || $realPath === false) {
        return false;
    }

    $realDir = rtrim($realDir, DIRECTORY_SEPARATOR);
    return $realPath === $realDir || strncmp($realPath, $realDir . DIRECTORY_SEPARATOR, strlen($realDir) + 1) === 0;
}

/**
 * Return the .htaccess hardening file for avatar uploads.
 *
 * Usage: write defense-in-depth rules so uploaded avatars cannot execute as
 * server-side scripts.
 * Referenced by: corebb_avatar_ensure_upload_dir().
 *
 * @return string .htaccess contents.
 */
function corebb_avatar_upload_htaccess_contents(): string
{
    return <<<'HTACCESS'
Options -Indexes

# Defense-in-depth for avatar uploads. PHP should never execute here even if a
# bad file or misnamed polyglot image is somehow written into this folder.
<IfModule mod_php.c>
    php_flag engine off
</IfModule>

RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .phtml .phar .cgi .pl .asp .aspx .jsp
RemoveType .php .php3 .php4 .php5 .php7 .php8 .phtml .phar .cgi .pl .asp .aspx .jsp

<FilesMatch "\.(?:php|php[0-9]?|phtml|phar|cgi|pl|asp|aspx|jsp)$">
    Require all denied
</FilesMatch>

<FilesMatch "^\.">
    Require all denied
</FilesMatch>

<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
</IfModule>
HTACCESS;
}

/**
 * Create and verify the avatar upload directory.
 *
 * Usage: prepare the upload target and write the hardening .htaccess before
 * moving an uploaded avatar.
 * Referenced by: user avatar upload and admin icon upload.
 *
 * @return array{ok: bool, message?: string, dir?: string} Directory check result.
 */
function corebb_avatar_ensure_upload_dir(): array
{
    $root = corebb_avatar_forum_root_abs();
    $dir = corebb_avatar_upload_dir_abs();

    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'message' => 'Avatar upload folder could not be created.'];
    }

    $realRoot = realpath($root);
    $realDir = realpath($dir);
    if ($realRoot === false || $realDir === false) {
        return ['ok' => false, 'message' => 'Avatar upload folder could not be verified.'];
    }

    $realRoot = rtrim($realRoot, DIRECTORY_SEPARATOR);
    if ($realDir !== $realRoot && strncmp($realDir, $realRoot . DIRECTORY_SEPARATOR, strlen($realRoot) + 1) !== 0) {
        return ['ok' => false, 'message' => 'Avatar upload folder resolves outside the forum directory.'];
    }

    if (!is_writable($realDir)) {
        return ['ok' => false, 'message' => 'Avatar upload folder is not writable.'];
    }

    $htaccess = $realDir . DIRECTORY_SEPARATOR . '.htaccess';
    $contents = corebb_avatar_upload_htaccess_contents() . "\n";
    if (!is_file($htaccess) || strpos((string)@file_get_contents($htaccess), 'Defense-in-depth for avatar uploads') === false) {
        if (@file_put_contents($htaccess, $contents, LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'Avatar upload folder hardening file could not be written.'];
        }
    }

    return ['ok' => true, 'dir' => $realDir];
}

/**
 * Build a safe public avatar path from a generated filename.
 *
 * Usage: store upload paths in the icons table after the file is saved.
 * Referenced by: user avatar upload and admin icon upload.
 *
 * @param string $filename Generated upload filename.
 * @return string Public relative path or empty string when invalid.
 */
function corebb_avatar_public_path(string $filename): string
{
    $base = basename(str_replace('\\', '/', $filename));
    if (!preg_match('/^[A-Za-z0-9._-]+\.(?:gif|jpe?g|png)$/i', $base)) {
        return '';
    }
    return COREBB_AVATAR_UPLOAD_DIR . '/' . $base;
}

/**
 * Sanitize an icon path before rendering it as an image src.
 *
 * Usage: block unsafe local image paths in avatar templates.
 * Referenced by: avatar model, admin icon templates, and user display helpers.
 *
 * @param string $path Stored icon path.
 * @return string Safe local image path or empty string.
 */
function corebb_avatar_safe_icon_src(string $path): string
{
    return corebb_safe_local_image_asset($path, ['images']);
}

/**
 * Normalize a stored uploaded-avatar path.
 *
 * Usage: verify admin delete/edit targets refer to uploaded avatar files only.
 * Referenced by: admin icon maintenance.
 *
 * @param string $path Stored or submitted public path.
 * @return string Safe uploaded-avatar public path or empty string.
 */
function corebb_avatar_safe_uploaded_public_path(string $path): string
{
    $path = trim(str_replace('\\', '/', html_entity_decode($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
    $path = preg_replace('/[\x00-\x1F\x7F]+/', '', $path) ?? '';
    $path = ltrim($path, '/');
    $uploadDir = trim(COREBB_AVATAR_UPLOAD_DIR, '/');

    if ($path === '' || !str_starts_with($path, $uploadDir . '/')) {
        return '';
    }

    $name = substr($path, strlen($uploadDir) + 1);
    if ($name === '' || str_contains($name, '/') || str_contains($name, '..')) {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9._-]+\.(?:gif|jpe?g|png)$/i', $name)) {
        return '';
    }

    return $uploadDir . '/' . $name;
}

/**
 * Sanitize an uploaded avatar's original filename for display/storage.
 *
 * Usage: keep friendly filenames while removing path components and control
 * characters.
 * Referenced by: user avatar upload and admin icon upload.
 *
 * @param string $name Original client filename.
 * @param string $fallback Safe fallback filename.
 * @return string Clean display filename.
 */
function corebb_avatar_sanitize_original_name(string $name, string $fallback): string
{
    $name = html_entity_decode($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?? '';
    $name = preg_replace('/[^A-Za-z0-9._() -]+/', '_', $name) ?? '';
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

    if ($name === '' || $name === '.' || $name === '..') {
        $name = $fallback;
    }
    if (function_exists('mb_strcut')) {
        $name = mb_strcut($name, 0, 255, 'UTF-8');
    } else {
        $name = substr($name, 0, 255);
    }
    return $name !== '' ? $name : $fallback;
}

/**
 * Validate an uploaded avatar file.
 *
 * Usage: enforce size, MIME, image type, and dimensions before saving an avatar.
 * Referenced by: user avatar upload and admin icon upload.
 *
 * @param array<string, mixed> $file One $_FILES entry.
 * @return array<string, mixed> Validation result with image metadata on success.
 */
function corebb_avatar_validate_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'Choose a PNG, GIF, or JPG avatar to upload.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Upload failed with error code ' . (int)$file['error'] . '.'];
    }
    if ((int)($file['size'] ?? 0) <= 0) {
        return ['ok' => false, 'message' => 'The uploaded file was empty.'];
    }
    if ((int)$file['size'] > COREBB_AVATAR_MAX_BYTES) {
        return ['ok' => false, 'message' => 'Avatar is too large. Maximum size is 512 KB.'];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Upload could not be verified by PHP.'];
    }

    $info = @getimagesize($tmp);
    if (!$info || empty($info[2])) {
        return ['ok' => false, 'message' => 'That file is not a valid image.'];
    }

    $type = (int)$info[2];
    $allowed = [
        IMAGETYPE_GIF => ['ext' => 'gif', 'mime' => 'image/gif'],
        IMAGETYPE_JPEG => ['ext' => 'jpg', 'mime' => 'image/jpeg'],
        IMAGETYPE_PNG => ['ext' => 'png', 'mime' => 'image/png'],
    ];
    if (!isset($allowed[$type])) {
        return ['ok' => false, 'message' => 'Only PNG, GIF, and JPG avatars are allowed.'];
    }

    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detectedMime = (string)@finfo_file($finfo, $tmp);
            @finfo_close($finfo);
            $acceptedMimes = $type === IMAGETYPE_JPEG ? ['image/jpeg', 'image/pjpeg'] : [$allowed[$type]['mime']];
            if ($detectedMime !== '' && !in_array($detectedMime, $acceptedMimes, true)) {
                return ['ok' => false, 'message' => 'Uploaded image MIME type did not match its image data.'];
            }
        }
    }

    $width = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    if ($width < 1 || $height < 1) {
        return ['ok' => false, 'message' => 'Avatar dimensions could not be read.'];
    }
    if ($width > COREBB_AVATAR_MAX_WIDTH || $height > COREBB_AVATAR_MAX_HEIGHT) {
        return ['ok' => false, 'message' => 'Avatar is too large. Maximum dimensions are ' . COREBB_AVATAR_MAX_WIDTH . 'x' . COREBB_AVATAR_MAX_HEIGHT . ' pixels.'];
    }

    return ['ok' => true, 'ext' => $allowed[$type]['ext'], 'mime' => $allowed[$type]['mime'], 'width' => $width, 'height' => $height];
}

/**
 * Save a submitted avatar upload and select it for the user.
 *
 * Usage: process the upload action from the user control panel avatar page.
 * Referenced by: corebb_avatar_handle_submit().
 *
 * @param int $uid Current user id.
 * @return array{ok: bool, message: string} Upload result.
 */
function corebb_avatar_handle_upload(int $uid): array
{
    if ($uid <= 0) {
        return ['ok' => false, 'message' => 'You must be logged in to upload an avatar.'];
    }
    corebb_avatar_ensure_schema();
    $file = $_FILES['avatar_file'] ?? null;
    if (!is_array($file)) {
        return ['ok' => false, 'message' => 'Choose a PNG, GIF, or JPG avatar to upload.'];
    }

    $validation = corebb_avatar_validate_upload($file);
    if (empty($validation['ok'])) {
        return $validation;
    }

    $dirResult = corebb_avatar_ensure_upload_dir();
    if (empty($dirResult['ok'])) {
        return ['ok' => false, 'message' => (string)($dirResult['message'] ?? 'Avatar upload folder could not be verified.')];
    }
    $dir = (string)$dirResult['dir'];

    $random = bin2hex(random_bytes(8));
    $ext = (string)$validation['ext'];
    $filename = 'user_' . $uid . '_' . time() . '_' . $random . '.' . $ext;
    $dest = $dir . '/' . $filename;

    if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
        return ['ok' => false, 'message' => 'Avatar could not be saved.'];
    }
    @chmod($dest, 0644);

    if (!corebb_avatar_path_inside_dir($dest, $dir)) {
        @unlink($dest);
        return ['ok' => false, 'message' => 'Avatar upload destination could not be verified.'];
    }

    $publicPath = corebb_avatar_public_path($filename);
    if ($publicPath === '') {
        @unlink($dest);
        return ['ok' => false, 'message' => 'Avatar upload path could not be verified.'];
    }
    $originalName = corebb_avatar_sanitize_original_name((string)($file['name'] ?? ''), $filename);

    $ok = db_run(
        'INSERT INTO icons (filepath, filename, mime, userid, uploaded, uploaded_at, approved) VALUES (?, ?, ?, ?, 1, ?, 1)',
        [$publicPath, $originalName, (string)($validation['mime'] ?? ''), $uid, time()]
    );
    if (!$ok) {
        @unlink($dest);
        return ['ok' => false, 'message' => 'Avatar database record could not be created: ' . db_error()];
    }

    $iconId = (int)db_insert_id();
    if ($iconId <= 0) {
        $iconId = (int)db_value('SELECT id FROM icons WHERE filepath = ? AND userid = ? ORDER BY id DESC LIMIT 1', [$publicPath, $uid], 0);
    }
    if ($iconId <= 0) {
        @unlink($dest);
        return ['ok' => false, 'message' => 'Avatar uploaded, but the new icon ID could not be found.'];
    }

    db_run('UPDATE users SET iconid = ? WHERE id = ?', [$iconId, $uid]);
    return ['ok' => true, 'message' => 'Avatar uploaded and selected.'];
}

/**
 * Select or clear an avatar for the user.
 *
 * Usage: process the icon selection action from the user control panel.
 * Referenced by: corebb_avatar_handle_submit().
 *
 * @param int $uid Current user id.
 * @return array{ok: bool, message: string} Selection result.
 */
function corebb_avatar_handle_select(int $uid): array
{
    if ($uid <= 0) {
        return ['ok' => false, 'message' => 'You must be logged in to update your avatar.'];
    }
    corebb_avatar_ensure_schema();
    $iconId = (int)($_POST['iconid'] ?? 0);
    if ($iconId < 0) {
        $iconId = 0;
    }
    if (!corebb_avatar_icon_exists_for_user($uid, $iconId)) {
        return ['ok' => false, 'message' => 'That icon no longer exists or is not available to your account.'];
    }
    $ok = (bool)db_run('UPDATE users SET iconid = ? WHERE id = ?', [$iconId, $uid]);
    if (!$ok) {
        return ['ok' => false, 'message' => 'Error updating avatar: ' . db_error()];
    }
    if ($iconId === 0) {
        return ['ok' => true, 'message' => 'Avatar removed.'];
    }
    return ['ok' => true, 'message' => 'Avatar updated.'];
}

/**
 * Dispatch the submitted avatar form action.
 *
 * Usage: route avatar POST submissions to upload or select handlers.
 * Referenced by: controllers/usercp.php action=avatar.
 *
 * @param int $uid Current user id.
 * @return array{ok: bool, message: string} Submission result.
 */
function corebb_avatar_handle_submit(int $uid): array
{
    $action = (string)($_POST['avatar_action'] ?? 'select');
    if ($action === 'upload') {
        return corebb_avatar_handle_upload($uid);
    }
    return corebb_avatar_handle_select($uid);
}

/**
 * Build the user control panel avatar view model.
 *
 * Usage: render avatar choices, limits, and pagination for the avatar editor.
 * Referenced by: controllers/usercp.php action=avatar.
 *
 * @param int $uid Current user id.
 * @return array<string, mixed> Avatar page view model.
 */
function corebb_avatar_model(int $uid): array
{
    require_once __DIR__ . '/pagination_helpers.php';

    if ($uid <= 0) {
        return [
            'avatarUser' => [],
            'currentIconId' => 0,
            'icons' => [],
            'iconsTotal' => 0,
            'page' => 1,
            'perPage' => 48,
            'totalPages' => 1,
            'hasIconsTable' => false,
            'avatarMaxWidth' => COREBB_AVATAR_MAX_WIDTH,
            'avatarMaxHeight' => COREBB_AVATAR_MAX_HEIGHT,
            'avatarMaxBytes' => COREBB_AVATAR_MAX_BYTES,
            'pagination' => corebb_pagination_model('/user-cp/avatar/?p={page}', 1, 1, 'MultiPages'),
        ];
    }
    corebb_avatar_ensure_schema();
    $page = max(1, (int)($_GET['p'] ?? 1));
    $perPage = 48;
    $total = corebb_avatar_count($uid);
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $user = corebb_avatar_current_user($uid);
    $icons = corebb_avatar_list($uid, $page, $perPage);
    foreach ($icons as &$icon) {
        $icon['src'] = corebb_avatar_safe_icon_src((string)($icon['filepath'] ?? ''));
        $icon['isUploaded'] = (int)($icon['uploaded'] ?? 0) === 1;
    }
    unset($icon);

    return [
        'avatarUser' => $user,
        'currentIconId' => (int)($user['iconid'] ?? 0),
        'icons' => $icons,
        'iconsTotal' => $total,
        'page' => $page,
        'perPage' => $perPage,
        'totalPages' => $totalPages,
        'pagination' => corebb_pagination_model('/user-cp/avatar/?p={page}', $page, $totalPages, 'MultiPages'),
        'hasIconsTable' => corebb_avatar_table_exists('icons'),
        'avatarMaxWidth' => COREBB_AVATAR_MAX_WIDTH,
        'avatarMaxHeight' => COREBB_AVATAR_MAX_HEIGHT,
        'avatarMaxBytes' => COREBB_AVATAR_MAX_BYTES,
    ];
}
