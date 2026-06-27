<?php
require_once __DIR__ . '/admin_log_helpers.php';
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
 |  admin_content_view_model.php  - Admin content        |
 |  editors: System TOS and System Style.                |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/tos_helpers.php';
require_once __DIR__ . '/public_style_helpers.php';

/**
 * Usage: Write an audit entry for this admin workflow.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param string $action Human-readable action message.
 * @return void No return value.
 */
function corebb_admin_content_add_log(array $viewer, string $action): void
{
    {
        corebb_adminlog_entry((string)($viewer['username'] ?? 'Unknown'), (int)($viewer['accesslevel'] ?? 0), $action);
    }
}

/**
 * Usage: Build and process the Terms of Service admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_tos_model(array $viewer, array $request, array $post): array
{
    $messages = [];
    $errors = [];
    $method = (string)($request['method'] ?? '');

    if($method === 'post' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
        $newTos = (string)($post['newtos'] ?? '');
        if(corebb_tos_save_text($newTos)){
            corebb_admin_content_add_log($viewer, 'Modified the System TOS');
            $messages[] = 'Successfully modified system TOS.';
        }
        else{
            corebb_admin_content_add_log($viewer, 'Tried and failed to edit the System TOS');
            $errors[] = 'Error changing system TOS setting: ' . (function_exists('db_error') ? db_error() : 'unknown database error');
        }
    }

    if(isset($request['msg']) && $request['msg'] !== ''){
        $messages[] = (string)$request['msg'];
    }

    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'tos' => corebb_tos_load_text(),
        'messages' => $messages,
        'errors' => $errors,
    ];
}

/**
 * Usage: Load the stylesheet setting row used by the style editor.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_style_setting_row(): array
{
    $row = db_one('SELECT * FROM systemsettings WHERE id = ? LIMIT 1', [1]);
    return is_array($row) ? $row : [];
}

/**
 * Usage: Check whether a resolved path stays inside the forum root.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $path Filesystem path.
 * @param string $root Allowed root path.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_admin_path_inside_root(string $path, string $root): bool
{
    $realRoot = realpath($root);
    $realPath = realpath($path);
    if($realRoot === false || $realPath === false){
        return false;
    }
    $realRoot = rtrim($realRoot, DIRECTORY_SEPARATOR);
    return $realPath === $realRoot || strncmp($realPath, $realRoot . DIRECTORY_SEPARATOR, strlen($realRoot) + 1) === 0;
}

/**
 * Usage: Resolve the editable stylesheet path from the configured setting.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_style_file_from_setting(): array
{
    $row = corebb_admin_style_setting_row();
    $setting = trim((string)($row['setting'] ?? 'style_vn_eol.css'));
    $setting = str_replace('\\', '/', $setting);
    $setting = preg_replace('/[\x00-\x1F\x7F]+/', '', $setting) ?? '';
    if($setting === ''){
        $setting = 'style_vn_eol.css';
    }
    $setting = corebb_public_style_normalize_file($setting);

    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $isAbsolute = (bool)preg_match('~^(?:[A-Za-z]:)?[\/]~', $setting);
    $hasProtocol = (bool)preg_match('/^[a-z][a-z0-9+.-]*:/i', $setting);
    $hasTraversal = str_contains('/' . $setting . '/', '/../');
    $isCss = (bool)preg_match('/\.css$/i', $setting);
    $pathAllowed = !$isAbsolute && !$hasProtocol && !$hasTraversal && $isCss && (bool)preg_match('~^[A-Za-z0-9._/-]+$~', $setting);

    $candidate = $pathAllowed ? $root . DIRECTORY_SEPARATOR . ltrim($setting, '/') : '';
    $realFile = $candidate !== '' ? realpath($candidate) : false;
    $insideRoot = $candidate !== '' && corebb_admin_path_inside_root($candidate, $root);

    return [
        'setting_row' => $row,
        'setting' => $setting,
        'candidate' => $candidate,
        'real_file' => $realFile ?: '',
        'inside_root' => $insideRoot,
        'css_file' => $isCss,
        'path_allowed' => (bool)$pathAllowed,
        'exists' => $candidate !== '' && is_file($candidate),
        'readable' => $candidate !== '' && is_readable($candidate),
        'writable' => $candidate !== '' && is_writable($candidate),
    ];
}

/**
 * Usage: Read the editable stylesheet contents.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $file Resolved editable file metadata.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_style_load_file(array $file): string
{
    if(!($file['exists'] ?? false) || !($file['readable'] ?? false) || !($file['inside_root'] ?? false) || !($file['path_allowed'] ?? false)){
        return '';
    }
    $contents = file_get_contents((string)$file['candidate']);
    return $contents === false ? '' : (string)$contents;
}

/**
 * Usage: Apply the requested admin change and return its result state.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $file Resolved editable file metadata.
 * @param string $contents File contents to write.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_admin_style_save_file(array $file, string $contents): bool
{
    if(!($file['inside_root'] ?? false) || !($file['path_allowed'] ?? false)){
        return false;
    }
    if(!($file['exists'] ?? false)){
        return false;
    }
    if(!($file['writable'] ?? false)){
        return false;
    }
    return file_put_contents((string)$file['candidate'], $contents, LOCK_EX) !== false;
}

/**
 * Usage: Build and process the style admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_style_model(array $viewer, array $request, array $post): array
{
    $messages = [];
    $errors = [];
    $method = (string)($request['method'] ?? '');
    $file = corebb_admin_style_file_from_setting();

    if($method === 'post' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
        $newStyle = (string)($post['newstyle'] ?? '');
        if($newStyle === ''){
            corebb_admin_content_add_log($viewer, 'Attempted to empty current style file');
            $errors[] = 'You cannot save an empty system style sheet.';
        }
        elseif(!($file['path_allowed'] ?? false) || !($file['inside_root'] ?? false)){
            corebb_admin_content_add_log($viewer, 'Attempted to edit a disallowed system style path');
            $errors[] = 'Refusing to edit a style file outside the forum directory or a non-CSS file.';
        }
        elseif(!($file['exists'] ?? false)){
            $errors[] = 'The configured style file does not exist: ' . (string)($file['setting'] ?? '');
        }
        elseif(!($file['writable'] ?? false)){
            $errors[] = 'The configured style file is not writable by PHP: ' . (string)($file['setting'] ?? '');
        }
        elseif(corebb_admin_style_save_file($file, $newStyle)){
            corebb_admin_content_add_log($viewer, 'Modified the System Style');
            $messages[] = 'Successfully edited the system style.';
            $file = corebb_admin_style_file_from_setting();
        }
        else{
            corebb_admin_content_add_log($viewer, 'Tried and failed to modify the System Style');
            $errors[] = 'Error writing system style file.';
        }
    }

    if(isset($request['msg']) && $request['msg'] !== ''){
        $messages[] = (string)$request['msg'];
    }

    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'style_file' => $file,
        'style_contents' => corebb_admin_style_load_file($file),
        'messages' => $messages,
        'errors' => $errors,
    ];
}
