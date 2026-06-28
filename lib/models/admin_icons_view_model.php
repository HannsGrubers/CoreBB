<?php
require_once __DIR__ . '/../helpers/admin_log_helpers.php';
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
 |  admin_icons_view_model.php  - Admin avatar/icon      |
 |  manager.                                             |
 +-------------------------------------------------------+*/

if (!defined('COREBB_ADMIN_ICONS_LOADED')) {
    define('COREBB_ADMIN_ICONS_LOADED', true);
}

require_once __DIR__ . '/avatar_view_model.php';
require_once __DIR__ . '/../helpers/admin_helpers.php';
require_once __DIR__ . '/admin_user_tools_view_model.php';
require_once __DIR__ . '/../helpers/pagination_helpers.php';

const COREBB_ADMIN_ICONS_PER_PAGE = 24;

/**
 * Usage: Ensure required database structures exist before this admin page runs.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return void No return value.
 */
function corebb_admin_icons_ensure_schema(): void
{
    corebb_avatar_ensure_schema();
    corebb_avatar_add_column('icons', 'mime', "VARCHAR(255) NOT NULL DEFAULT ''");
    corebb_avatar_add_column('icons', 'approved', 'TINYINT(1) NOT NULL DEFAULT 1');
}

/**
 * Usage: Return the form token for this admin page.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_admin_icons_token(): string
{
    return corebb_security_named_token('admin_icons_token');
}

/**
 * Usage: Validate the form token before accepting an admin mutation.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $post Posted form data from admin.php.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_admin_icons_token_ok(array $post): bool
{
    return corebb_security_named_token_valid('admin_icons_token', $post, 'admin_icons_token');
}

/**
 * Usage: Normalize input before it is displayed or saved.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @param array $allowed Allowed values for this filter.
 * @param string $default Fallback value.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_icons_clean_filter(string $value, array $allowed, string $default): string
{
    return in_array($value, $allowed, true) ? $value : $default;
}

/**
 * Usage: Normalize input before it is displayed or saved.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @param int $maxBytes Maximum bytes to keep.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_icons_limit_text(string $value, int $maxBytes): string
{
    if ($maxBytes <= 0) {
        return '';
    }
    if (function_exists('mb_strcut')) {
        return mb_strcut($value, 0, $maxBytes, 'UTF-8');
    }
    return substr($value, 0, $maxBytes);
}

/**
 * Usage: Normalize icon-manager list filters.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $get Query parameters from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_icons_filters(array $get): array
{
    return [
        'q' => corebb_admin_icons_limit_text(trim((string)($get['q'] ?? '')), 255),
        'type' => corebb_admin_icons_clean_filter((string)($get['type'] ?? 'all'), ['all', 'uploaded', 'shared'], 'all'),
        'status' => corebb_admin_icons_clean_filter((string)($get['status'] ?? 'all'), ['all', 'approved', 'unapproved'], 'all'),
        'usage' => corebb_admin_icons_clean_filter((string)($get['usage'] ?? 'all'), ['all', 'used', 'unused'], 'all'),
    ];
}

/**
 * Usage: Build an admin URL or query string.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $filters List filter values.
 * @param ?int $page Current page number.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_icons_filter_query(array $filters, ?int $page = null): string
{
    $params = [];
    foreach (['q', 'type', 'status', 'usage'] as $key) {
        $value = (string)($filters[$key] ?? '');
        if ($key === 'q') {
            if ($value !== '') { $params[$key] = $value; }
        } elseif ($value !== '' && $value !== 'all') {
            $params[$key] = $value;
        }
    }
    if ($page !== null && $page > 1) {
        $params['p'] = $page;
    }
    return http_build_query($params);
}

/**
 * Usage: Build the SQL fragment for an admin query.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $filters List filter values.
 * @param array $params SQL parameter list, appended by reference when needed.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_icons_where(array $filters, array &$params): string
{
    $where = [];

    if (($filters['type'] ?? 'all') === 'uploaded') {
        $where[] = 'i.uploaded = 1';
    } elseif (($filters['type'] ?? 'all') === 'shared') {
        $where[] = 'i.uploaded = 0';
    }

    if (($filters['status'] ?? 'all') === 'approved') {
        $where[] = 'i.approved = 1';
    } elseif (($filters['status'] ?? 'all') === 'unapproved') {
        $where[] = 'i.approved = 0';
    }

    if (($filters['usage'] ?? 'all') === 'used') {
        $where[] = 'EXISTS (SELECT 1 FROM users used_filter WHERE used_filter.iconid = i.id)';
    } elseif (($filters['usage'] ?? 'all') === 'unused') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM users used_filter WHERE used_filter.iconid = i.id)';
    }

    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        if (ctype_digit($q)) {
            $where[] = '(i.id = ? OR i.userid = ? OR i.filename LIKE ? OR i.filepath LIKE ? OR owner.username LIKE ?)';
            $params[] = (int)$q;
            $params[] = (int)$q;
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        } else {
            $where[] = '(i.filename LIKE ? OR i.filepath LIKE ? OR owner.username LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
    }

    if (!$where) {
        return '';
    }
    return ' WHERE ' . implode(' AND ', $where);
}

/**
 * Usage: Count records for the admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $filters List filter values.
 * @return int Numeric result for the caller.
 */
function corebb_admin_icons_count(array $filters): int
{
    corebb_admin_icons_ensure_schema();
    $params = [];
    $where = corebb_admin_icons_where($filters, $params);
    return (int)db_value('SELECT COUNT(*) FROM icons i LEFT JOIN users owner ON owner.id = i.userid' . $where, $params, 0);
}

/**
 * Usage: Fetch records for the admin display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $filters List filter values.
 * @param int $page Current page number.
 * @param int $perPage Rows per page.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_icons_list(array $filters, int $page, int $perPage): array
{
    corebb_admin_icons_ensure_schema();
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset = ($page - 1) * $perPage;

    $params = [];
    $where = corebb_admin_icons_where($filters, $params);
    $sql = "
        SELECT
            i.id,
            i.filepath,
            i.filename,
            i.mime,
            i.userid,
            i.uploaded,
            i.uploaded_at,
            i.approved,
            owner.username AS owner_username,
            COUNT(used_users.id) AS usage_count,
            GROUP_CONCAT(used_users.username ORDER BY used_users.username SEPARATOR ', ') AS used_by
        FROM icons i
        LEFT JOIN users owner ON owner.id = i.userid
        LEFT JOIN users used_users ON used_users.iconid = i.id
        {$where}
        GROUP BY i.id, i.filepath, i.filename, i.mime, i.userid, i.uploaded, i.uploaded_at, i.approved, owner.username
        ORDER BY i.uploaded DESC, i.id DESC
        LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

    $icons = [];
    foreach (db_all($sql, $params) as $row) {
        $iconId = (int)($row['id'] ?? 0);
        $filename = (string)($row['filename'] ?? '');
        $ownerId = (int)($row['userid'] ?? 0);
        $ownerName = trim((string)($row['owner_username'] ?? ''));
        $uploadedAt = (int)($row['uploaded_at'] ?? 0);
        $isUploaded = (int)($row['uploaded'] ?? 0) === 1;
        $isApproved = (int)($row['approved'] ?? 1) === 1;

        $icons[] = [
            'id' => $iconId,
            'filepath' => (string)($row['filepath'] ?? ''),
            'src' => corebb_avatar_safe_icon_src((string)($row['filepath'] ?? '')),
            'filename' => (string)($row['filename'] ?? ''),
            'alt' => $filename !== '' ? $filename : 'Icon ' . $iconId,
            'mime' => (string)($row['mime'] ?? ''),
            'userid' => $ownerId,
            'uploaded' => $isUploaded ? 1 : 0,
            'uploaded_at' => $uploadedAt,
            'uploaded_at_label' => $uploadedAt > 0 ? date('Y-m-d H:i', $uploadedAt) : '',
            'approved' => $isApproved ? 1 : 0,
            'is_uploaded' => $isUploaded,
            'is_approved' => $isApproved,
            'type_label' => $isUploaded ? 'upload' : 'shared',
            'visibility_label' => $isUploaded ? 'User-uploaded/private' : 'Shared/default',
            'status_label' => $isApproved ? 'Approved' : 'Unapproved',
            'owner_username' => $ownerName,
            'owner' => [
                'id' => $ownerId,
                'username' => $ownerName !== '' ? $ownerName : ($ownerId > 0 ? 'User #' . $ownerId : 'System/default'),
                'profile_url' => $ownerId > 0 ? corebb_public_join_base_path('/profile/' . $ownerId . '/') : '',
                'style_css' => '',
                'linked' => $ownerId > 0,
                'blank' => false,
            ],
            'usage_count' => (int)($row['usage_count'] ?? 0),
            'used_by' => (string)($row['used_by'] ?? ''),
        ];
    }
    return $icons;
}

/**
 * Usage: Count icon-manager review buckets.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_icons_stats(): array
{
    corebb_admin_icons_ensure_schema();
    $stats = [
        'total' => 0,
        'uploaded' => 0,
        'shared' => 0,
        'approved' => 0,
        'unapproved' => 0,
        'unused_uploaded' => 0,
    ];

    $row = db_one("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN uploaded = 1 THEN 1 ELSE 0 END) AS uploaded,
        SUM(CASE WHEN uploaded = 0 THEN 1 ELSE 0 END) AS shared,
        SUM(CASE WHEN approved = 1 THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN approved = 0 THEN 1 ELSE 0 END) AS unapproved
        FROM icons");
    if ($row) {
        foreach (['total', 'uploaded', 'shared', 'approved', 'unapproved'] as $key) {
            $stats[$key] = (int)($row[$key] ?? 0);
        }
    }

    $stats['unused_uploaded'] = (int)db_value(
        'SELECT COUNT(*) FROM icons i WHERE i.uploaded = 1 AND NOT EXISTS (SELECT 1 FROM users u WHERE u.iconid = i.id)',
        [],
        0
    );

    return $stats;
}

/**
 * Usage: Fetch one managed icon row.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $iconId Icon id.
 * @return ?array Data row when found, otherwise null.
 */
function corebb_admin_icons_icon(int $iconId): ?array
{
    corebb_admin_icons_ensure_schema();
    if ($iconId <= 0) {
        return null;
    }
    $row = db_one('SELECT id, filepath, filename, mime, userid, uploaded, uploaded_at, approved FROM icons WHERE id = ? LIMIT 1', [$iconId]);
    return is_array($row) ? $row : null;
}

/**
 * Usage: Write an audit entry for this admin workflow.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param string $message User-facing or log message.
 * @return void No return value.
 */
function corebb_admin_icons_log(array $viewer, string $message): void
{
    {
        corebb_adminlog_entry((string)($viewer['username'] ?? ''), (int)($viewer['accesslevel'] ?? 0), $message);
    }
}

/**
 * Usage: Resolve an icon file path while staying inside allowed image folders.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $publicPath Public asset path.
 * @return ?string Function result.
 */
function corebb_admin_icons_safe_file_path(string $publicPath): ?string
{
    $safePublicPath = corebb_avatar_safe_uploaded_public_path($publicPath);
    if ($safePublicPath === '') {
        return null;
    }

    $base = corebb_avatar_upload_dir_abs();
    $candidate = dirname(__DIR__, 2) . '/' . $safePublicPath;
    if (!is_file($candidate) || !corebb_avatar_path_inside_dir($candidate, $base)) {
        return null;
    }

    return realpath($candidate) ?: null;
}

/**
 * Usage: Apply the requested admin change and return its result state.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $iconId Icon id.
 * @param array $viewer Current admin user row.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_icons_delete_icon(int $iconId, array $viewer): array
{
    $icon = corebb_admin_icons_icon($iconId);
    if (!$icon) {
        return [false, 'Unknown icon.'];
    }

    db_run('UPDATE users SET iconid = 0 WHERE iconid = ?', [$iconId]);
    $ok = db_run('DELETE FROM icons WHERE id = ?', [$iconId]);
    if (!$ok) {
        return [false, 'Error deleting icon #' . $iconId . ': ' . db_error()];
    }

    $filePath = corebb_admin_icons_safe_file_path((string)($icon['filepath'] ?? ''));
    if ($filePath !== null) {
        @unlink($filePath);
    }

    corebb_admin_icons_log($viewer, 'Deleted avatar/icon #' . $iconId);
    return [true, 'Icon #' . $iconId . ' was deleted. Any users using it were reset to no icon.'];
}

/**
 * Usage: Apply the requested admin change and return its result state.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_icons_bulk_delete_unused(array $viewer): array
{
    corebb_admin_icons_ensure_schema();
    $deleted = 0;
    $failed = 0;
    foreach (db_all('SELECT i.id, i.filepath FROM icons i WHERE i.uploaded = 1 AND NOT EXISTS (SELECT 1 FROM users u WHERE u.iconid = i.id)') as $row) {
        [$ok] = corebb_admin_icons_delete_icon((int)($row['id'] ?? 0), $viewer);
        if ($ok) { $deleted++; } else { $failed++; }
    }

    if ($deleted > 0) {
        corebb_admin_icons_log($viewer, 'Deleted ' . $deleted . ' unused uploaded avatars');
    }

    if ($deleted === 0 && $failed === 0) {
        return [true, 'No unused uploaded avatars were found.'];
    }
    if ($failed > 0) {
        return [false, 'Deleted ' . $deleted . ' unused uploaded avatar(s), but ' . $failed . ' failed.'];
    }
    return [true, 'Deleted ' . $deleted . ' unused uploaded avatar(s).'];
}

/**
 * Usage: Apply the requested admin change and return its result state.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $iconId Icon id.
 * @param bool $approved Approved state.
 * @param array $viewer Current admin user row.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_icons_set_approved(int $iconId, bool $approved, array $viewer): array
{
    if (!corebb_admin_icons_icon($iconId)) {
        return [false, 'Unknown icon.'];
    }

    $ok = db_run('UPDATE icons SET approved = ? WHERE id = ?', [$approved ? 1 : 0, $iconId]);
    if (!$ok) {
        return [false, 'Error updating icon #' . $iconId . ': ' . db_error()];
    }

    if (!$approved) {
        db_run('UPDATE users SET iconid = 0 WHERE iconid = ?', [$iconId]);
    }

    corebb_admin_icons_log($viewer, ($approved ? 'Approved' : 'Unapproved') . ' avatar/icon #' . $iconId);
    return [true, 'Icon #' . $iconId . ' was ' . ($approved ? 'approved.' : 'unapproved and removed from any profiles using it.')];
}

/**
 * Usage: Apply the requested admin change and return its result state.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param int $iconId Icon id.
 * @param bool $shared Shared state.
 * @param array $viewer Current admin user row.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_icons_set_shared(int $iconId, bool $shared, array $viewer): array
{
    if (!corebb_admin_icons_icon($iconId)) {
        return [false, 'Unknown icon.'];
    }

    $ok = db_run('UPDATE icons SET uploaded = ? WHERE id = ?', [$shared ? 0 : 1, $iconId]);
    if (!$ok) {
        return [false, 'Error updating icon #' . $iconId . ': ' . db_error()];
    }

    corebb_admin_icons_log($viewer, ($shared ? 'Made shared' : 'Made user-owned') . ' avatar/icon #' . $iconId);
    return [true, 'Icon #' . $iconId . ' is now ' . ($shared ? 'a shared/default icon.' : 'a user-uploaded icon.')];
}

/**
 * Usage: Apply the requested admin change and return its result state.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $files Uploaded files array from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_icons_upload_shared(array $viewer, array $files, array $post): array
{
    corebb_admin_icons_ensure_schema();
    $file = $files['shared_icon_file'] ?? null;
    if (!is_array($file)) {
        return [false, 'Choose a PNG, GIF, or JPG icon to upload.'];
    }

    $validation = corebb_avatar_validate_upload($file);
    if (empty($validation['ok'])) {
        return [false, (string)($validation['message'] ?? 'Upload failed.')];
    }

    $dirResult = corebb_avatar_ensure_upload_dir();
    if (empty($dirResult['ok'])) {
        return [false, (string)($dirResult['message'] ?? 'Icon upload folder could not be verified.')];
    }
    $dir = (string)$dirResult['dir'];

    $viewerId = (int)($viewer['id'] ?? 0);
    if ($viewerId <= 0) {
        return [false, 'Unknown admin user.'];
    }
    $random = bin2hex(random_bytes(8));
    $ext = (string)($validation['ext'] ?? 'img');
    $filename = 'shared_' . $viewerId . '_' . time() . '_' . $random . '.' . $ext;
    $dest = $dir . '/' . $filename;

    if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
        return [false, 'Icon could not be saved.'];
    }
    @chmod($dest, 0644);

    if (!corebb_avatar_path_inside_dir($dest, $dir)) {
        @unlink($dest);
        return [false, 'Icon upload destination could not be verified.'];
    }

    $publicPath = corebb_avatar_public_path($filename);
    if ($publicPath === '') {
        @unlink($dest);
        return [false, 'Icon upload path could not be verified.'];
    }
    $displayName = trim((string)($post['shared_icon_name'] ?? ''));
    $displayName = $displayName !== '' ? $displayName : (string)($file['name'] ?? '');
    $displayName = corebb_avatar_sanitize_original_name(strip_tags($displayName), $filename);
    $mime = corebb_admin_icons_limit_text((string)($validation['mime'] ?? ''), 255);

    $ok = db_run(
        'INSERT INTO icons (filepath, filename, mime, userid, uploaded, uploaded_at, approved) VALUES (?, ?, ?, ?, 0, ?, 1)',
        [$publicPath, $displayName, $mime, $viewerId, time()]
    );
    if (!$ok) {
        @unlink($dest);
        return [false, 'Icon database record could not be created: ' . db_error()];
    }

    $iconId = (int)db_insert_id();
    corebb_admin_icons_log($viewer, 'Added shared/default avatar icon #' . $iconId);
    return [true, 'Shared/default icon uploaded' . ($iconId > 0 ? ' as #' . $iconId : '') . '.'];
}

/**
 * Usage: Apply the requested admin change and return its result state.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $post Posted form data from admin.php.
 * @param array $files Uploaded files array from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_icons_handle_post(array $viewer, array $post, array $files): array
{
    if (!corebb_admin_icons_token_ok($post)) {
        return [false, 'Your admin icon manager session token was missing or expired. Reload the page and try again.'];
    }

    $action = (string)($post['icon_action'] ?? '');
    $iconId = (int)($post['icon_id'] ?? 0);

    switch ($action) {
        case 'approve':
            return corebb_admin_icons_set_approved($iconId, true, $viewer);
        case 'unapprove':
            return corebb_admin_icons_set_approved($iconId, false, $viewer);
        case 'make_shared':
            return corebb_admin_icons_set_shared($iconId, true, $viewer);
        case 'make_uploaded':
            return corebb_admin_icons_set_shared($iconId, false, $viewer);
        case 'delete':
            return corebb_admin_icons_delete_icon($iconId, $viewer);
        case 'delete_unused_uploaded':
            return corebb_admin_icons_bulk_delete_unused($viewer);
        case 'upload_shared':
            return corebb_admin_icons_upload_shared($viewer, $files, $post);
    }

    return [false, 'Unknown icon manager action.'];
}

/**
 * Usage: Build and process the icons admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $get Query parameters from admin.php.
 * @param array $post Posted form data from admin.php.
 * @param array $files Uploaded files array from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_icons_model(array $viewer, array $get, array $post, array $files): array
{
    corebb_admin_icons_ensure_schema();
    $filters = corebb_admin_icons_filters($get);
    $messages = [];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        [$ok, $message] = corebb_admin_icons_handle_post($viewer, $post, $files);
        $messages[] = $message;
    }

    $page = max(1, (int)($get['p'] ?? 1));
    $perPage = COREBB_ADMIN_ICONS_PER_PAGE;
    $total = corebb_admin_icons_count($filters);
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $filterQuery = corebb_admin_icons_filter_query($filters, null);
    $pageUrlPattern = '/admin/?act=manage_icons&' . ($filterQuery !== '' ? $filterQuery . '&' : '') . 'p={page}';

    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'messages' => $messages,
        'filters' => $filters,
        'icons' => corebb_admin_icons_list($filters, $page, $perPage),
        'stats' => corebb_admin_icons_stats(),
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'totalPages' => $totalPages,
        'token' => corebb_admin_icons_token(),
        'queryString' => $filterQuery,
        'pagination' => corebb_pagination_model(
            $totalPages > 1 ? $pageUrlPattern : '',
            $page,
            $totalPages,
            'MultiPages'
        ),
        'maxWidth' => COREBB_AVATAR_MAX_WIDTH,
        'maxHeight' => COREBB_AVATAR_MAX_HEIGHT,
        'maxBytes' => COREBB_AVATAR_MAX_BYTES,
    ];
}
