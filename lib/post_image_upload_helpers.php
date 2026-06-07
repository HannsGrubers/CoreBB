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
 |  post_image_upload_helpers.php  - Post image upload   |
 |  helpers.                                             |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/../functions.php';

const COREBB_POST_IMAGE_UPLOAD_DIR = 'images/post_uploads';
const COREBB_POST_IMAGE_MAX_BYTES = 6291456; // 6 MB before resize
const COREBB_POST_IMAGE_MAX_SOURCE_WIDTH = 4096;
const COREBB_POST_IMAGE_MAX_SOURCE_HEIGHT = 4096;
const COREBB_POST_IMAGE_MAX_RENDER_WIDTH = 900;
const COREBB_POST_IMAGE_MAX_RENDER_HEIGHT = 900;

/**
 * Check whether a user may upload privileged post images.
 *
 * Usage: gate the admin-only [a_image] upload workflow.
 * Referenced by: post_view_model.php and upload handlers in this file.
 *
 * @param array<string, mixed> $user Current user row.
 * @return bool True when the user has upload rights.
 */
function corebb_post_image_can_upload(array $user): bool
{
    return (int)($user['accesslevel'] ?? 0) >= 5;
}

/**
 * Return the absolute forum root directory.
 *
 * Usage: anchor post-image uploads inside the forum tree.
 * Referenced by: post-image upload path helpers.
 *
 * @return string Absolute forum root path.
 */
function corebb_post_image_forum_root_abs(): string
{
    return dirname(__DIR__);
}

/**
 * Return the absolute post-image upload directory.
 *
 * Usage: create, verify, save, and delete post image upload files.
 * Referenced by: upload directory and path resolution helpers.
 *
 * @return string Absolute post-image upload directory.
 */
function corebb_post_image_upload_dir_abs(): string
{
    return corebb_post_image_forum_root_abs() . '/' . COREBB_POST_IMAGE_UPLOAD_DIR;
}

/**
 * Return the .htaccess hardening file for post-image uploads.
 *
 * Usage: prevent uploaded images from executing as scripts if a bad file ever
 * reaches the upload folder.
 * Referenced by: corebb_post_image_ensure_upload_dir().
 *
 * @return string .htaccess contents.
 */
function corebb_post_image_upload_htaccess_contents(): string
{
    return <<<'HTACCESS'
Options -Indexes

# Defense-in-depth for post image uploads. PHP should never execute here even if
# a bad file or misnamed polyglot image is somehow written into this folder.
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
 * Create and verify the post-image upload directory.
 *
 * Usage: prepare a writable, hardened destination before saving an uploaded
 * post image.
 * Referenced by: corebb_post_image_handle_upload().
 *
 * @return array{ok: bool, message?: string, dir?: string} Directory check result.
 */
function corebb_post_image_ensure_upload_dir(): array
{
    $root = corebb_post_image_forum_root_abs();
    $dir = corebb_post_image_upload_dir_abs();

    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'message' => 'Post image upload folder could not be created.'];
    }

    $realRoot = realpath($root);
    $realDir = realpath($dir);
    if ($realRoot === false || $realDir === false) {
        return ['ok' => false, 'message' => 'Post image upload folder could not be verified.'];
    }

    $realRoot = rtrim($realRoot, DIRECTORY_SEPARATOR);
    if ($realDir !== $realRoot && strncmp($realDir, $realRoot . DIRECTORY_SEPARATOR, strlen($realRoot) + 1) !== 0) {
        return ['ok' => false, 'message' => 'Post image upload folder resolves outside the forum directory.'];
    }

    if (!is_writable($realDir)) {
        return ['ok' => false, 'message' => 'Post image upload folder is not writable.'];
    }

    $htaccess = $realDir . DIRECTORY_SEPARATOR . '.htaccess';
    $contents = corebb_post_image_upload_htaccess_contents() . "\n";
    if (!is_file($htaccess) || strpos((string)@file_get_contents($htaccess), 'Defense-in-depth for post image uploads') === false) {
        if (@file_put_contents($htaccess, $contents, LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'Post image upload folder hardening file could not be written.'];
        }
    }

    return ['ok' => true, 'dir' => $realDir];
}

/**
 * Check whether the current request includes a post image upload.
 *
 * Usage: avoid invoking upload validation when the post form has no file.
 * Referenced by: post_view_model.php and optional upload helpers.
 *
 * @return bool True when a file was submitted for post_image_upload.
 */
function corebb_post_image_upload_present(): bool
{
    $file = $_FILES['post_image_upload'] ?? null;
    return is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

/**
 * Validate a submitted post image file.
 *
 * Usage: enforce upload size, image type, MIME consistency, and source
 * dimensions before saving or resizing.
 * Referenced by: corebb_post_image_handle_upload().
 *
 * @param array<string, mixed> $file One $_FILES entry.
 * @return array<string, mixed> Validation result with image metadata on success.
 */
function corebb_post_image_validate_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'No post image was selected.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Image upload failed with error code ' . (int)$file['error'] . '.'];
    }
    if ((int)($file['size'] ?? 0) <= 0) {
        return ['ok' => false, 'message' => 'The uploaded image was empty.'];
    }
    if ((int)$file['size'] > COREBB_POST_IMAGE_MAX_BYTES) {
        return ['ok' => false, 'message' => 'Post image is too large. Maximum upload size is 6 MB.'];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Image upload could not be verified by PHP.'];
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
        return ['ok' => false, 'message' => 'Only PNG, GIF, and JPG post images are allowed.'];
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
        return ['ok' => false, 'message' => 'Image dimensions could not be read.'];
    }
    if ($width > COREBB_POST_IMAGE_MAX_SOURCE_WIDTH || $height > COREBB_POST_IMAGE_MAX_SOURCE_HEIGHT) {
        return ['ok' => false, 'message' => 'Post image dimensions are too large. Maximum source dimensions are ' . COREBB_POST_IMAGE_MAX_SOURCE_WIDTH . 'x' . COREBB_POST_IMAGE_MAX_SOURCE_HEIGHT . ' pixels.'];
    }

    return [
        'ok' => true,
        'ext' => $allowed[$type]['ext'],
        'mime' => $allowed[$type]['mime'],
        'type' => $type,
        'width' => $width,
        'height' => $height,
    ];
}

/**
 * Calculate render dimensions that fit within post-image display limits.
 *
 * Usage: resize large JPEG/PNG uploads without upscaling smaller images.
 * Referenced by: corebb_post_image_resize_to_fit().
 *
 * @param int $width Source width.
 * @param int $height Source height.
 * @return array{0: int, 1: int} New width and height.
 */
function corebb_post_image_scaled_dimensions(int $width, int $height): array
{
    if ($width <= 0 || $height <= 0) {
        return [0, 0];
    }

    $scale = min(COREBB_POST_IMAGE_MAX_RENDER_WIDTH / $width, COREBB_POST_IMAGE_MAX_RENDER_HEIGHT / $height, 1);
    $newWidth = max(1, (int)floor($width * $scale));
    $newHeight = max(1, (int)floor($height * $scale));
    return [$newWidth, $newHeight];
}

/**
 * Save a post image, resizing JPEG/PNG files when needed.
 *
 * Usage: preserve acceptable GIFs, resize oversized JPEG/PNG files with GD, and
 * write the final image to the upload directory.
 * Referenced by: corebb_post_image_handle_upload().
 *
 * @param string $src Uploaded temporary file path.
 * @param string $dest Destination file path.
 * @param array<string, mixed> $validation Metadata from validation.
 * @return array<string, mixed> Save result with resize metadata on success.
 */
function corebb_post_image_resize_to_fit(string $src, string $dest, array $validation): array
{
    $type = (int)($validation['type'] ?? 0);
    $width = (int)($validation['width'] ?? 0);
    $height = (int)($validation['height'] ?? 0);
    [$newWidth, $newHeight] = corebb_post_image_scaled_dimensions($width, $height);
    $needsResize = ($newWidth > 0 && $newHeight > 0 && ($newWidth !== $width || $newHeight !== $height));

    // GIF resizing with GD flattens animated GIFs. Preserve GIFs as-is when they
    // already fit; ask admins to pre-scale large GIFs instead of silently ruining
    // animation.
    if ($type === IMAGETYPE_GIF) {
        if ($needsResize) {
            return ['ok' => false, 'message' => 'GIF post images must already fit within ' . COREBB_POST_IMAGE_MAX_RENDER_WIDTH . 'x' . COREBB_POST_IMAGE_MAX_RENDER_HEIGHT . ' pixels.'];
        }
        return @move_uploaded_file($src, $dest)
            ? ['ok' => true, 'resized' => false]
            : ['ok' => false, 'message' => 'Post image could not be saved.'];
    }

    if (!$needsResize) {
        return @move_uploaded_file($src, $dest)
            ? ['ok' => true, 'resized' => false]
            : ['ok' => false, 'message' => 'Post image could not be saved.'];
    }

    if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) {
        return ['ok' => false, 'message' => 'This image needs resizing, but the PHP GD image library is not available.'];
    }

    if ($type === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg') && function_exists('imagejpeg')) {
        $source = @imagecreatefromjpeg($src);
    } elseif ($type === IMAGETYPE_PNG && function_exists('imagecreatefrompng') && function_exists('imagepng')) {
        $source = @imagecreatefrompng($src);
    } else {
        $source = false;
    }

    if (!$source) {
        return ['ok' => false, 'message' => 'Post image could not be opened for resizing.'];
    }

    $canvas = imagecreatetruecolor($newWidth, $newHeight);
    if (!$canvas) {
        imagedestroy($source);
        return ['ok' => false, 'message' => 'Post image resize canvas could not be created.'];
    }

    if ($type === IMAGETYPE_PNG) {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $transparent);
        }
    }

    $copied = imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    if (!$copied) {
        imagedestroy($source);
        imagedestroy($canvas);
        return ['ok' => false, 'message' => 'Post image could not be resized.'];
    }

    if ($type === IMAGETYPE_JPEG) {
        $saved = imagejpeg($canvas, $dest, 85);
    } else {
        $saved = imagepng($canvas, $dest, 6);
    }

    imagedestroy($source);
    imagedestroy($canvas);

    if (!$saved) {
        @unlink($dest);
        return ['ok' => false, 'message' => 'Resized post image could not be saved.'];
    }

    return ['ok' => true, 'resized' => true, 'width' => $newWidth, 'height' => $newHeight];
}

/**
 * Build a public path for a generated post-image filename.
 *
 * Usage: return the path stored in generated [a_image] BBCode.
 * Referenced by: corebb_post_image_handle_upload().
 *
 * @param string $filename Generated upload filename.
 * @return string Public path beginning with /, or empty string when invalid.
 */
function corebb_post_image_public_path(string $filename): string
{
    $base = basename(str_replace('\\', '/', $filename));
    if (!preg_match('/^[A-Za-z0-9._-]+\.(?:gif|jpe?g|png)$/i', $base)) {
        return '';
    }
    return '/' . COREBB_POST_IMAGE_UPLOAD_DIR . '/' . $base;
}

/**
 * Convert a public post-image path back to an absolute file path.
 *
 * Usage: locate uploads for cleanup after failed post transactions or edits.
 * Referenced by: corebb_post_image_delete_public_path().
 *
 * @param string $publicPath Public path emitted by this helper.
 * @return string Absolute file path or empty string when invalid.
 */
function corebb_post_image_abs_from_public_path(string $publicPath): string
{
    $publicPath = trim(str_replace('\\', '/', $publicPath));
    $publicPath = ltrim($publicPath, '/');
    $prefix = trim(COREBB_POST_IMAGE_UPLOAD_DIR, '/') . '/';
    if (!str_starts_with($publicPath, $prefix)) {
        return '';
    }
    $name = substr($publicPath, strlen($prefix));
    if ($name === '' || str_contains($name, '/') || str_contains($name, '..')) {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9._-]+\.(?:gif|jpe?g|png)$/i', $name)) {
        return '';
    }
    return corebb_post_image_upload_dir_abs() . '/' . $name;
}

/**
 * Delete a post-image upload by public path.
 *
 * Usage: clean up files when a post write fails after upload succeeds.
 * Referenced by: post_view_model.php.
 *
 * @param string $publicPath Public image path to delete.
 * @return void
 */
function corebb_post_image_delete_public_path(string $publicPath): void
{
    $path = corebb_post_image_abs_from_public_path($publicPath);
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}

/**
 * Save the submitted post image and return BBCode for insertion.
 *
 * Usage: process the admin-only post image upload endpoint or post form upload
 * fallback.
 * Referenced by: controllers/post.php and post_view_model.php.
 *
 * @param array<string, mixed> $user Current user row.
 * @return array<string, mixed> Upload result with public_path and bbcode on success.
 */
function corebb_post_image_handle_upload(array $user): array
{
    if (!corebb_post_image_can_upload($user)) {
        return ['ok' => false, 'message' => 'Only admins can upload post images.'];
    }

    $file = $_FILES['post_image_upload'] ?? null;
    if (!is_array($file)) {
        return ['ok' => false, 'message' => 'No post image was selected.'];
    }

    $validation = corebb_post_image_validate_upload($file);
    if (empty($validation['ok'])) {
        return $validation;
    }

    $dirResult = corebb_post_image_ensure_upload_dir();
    if (empty($dirResult['ok'])) {
        return ['ok' => false, 'message' => (string)($dirResult['message'] ?? 'Post image upload folder could not be verified.')];
    }

    $random = bin2hex(random_bytes(8));
    $ext = (string)$validation['ext'];
    $userId = max(0, (int)($user['id'] ?? 0));
    $filename = 'post_' . $userId . '_' . time() . '_' . $random . '.' . $ext;
    $dest = (string)$dirResult['dir'] . '/' . $filename;

    $save = corebb_post_image_resize_to_fit((string)$file['tmp_name'], $dest, $validation);
    if (empty($save['ok'])) {
        return $save;
    }
    @chmod($dest, 0644);

    $publicPath = corebb_post_image_public_path($filename);
    if ($publicPath === '') {
        @unlink($dest);
        return ['ok' => false, 'message' => 'Post image upload path could not be verified.'];
    }

    return [
        'ok' => true,
        'public_path' => $publicPath,
        'bbcode' => '[a_image=' . $publicPath . ']',
        'resized' => !empty($save['resized']),
    ];
}

/**
 * Append post-image BBCode to a post body.
 *
 * Usage: insert uploaded [a_image] markup while preserving existing body text.
 * Referenced by: corebb_post_image_apply_optional_upload().
 *
 * @param string $body Existing post body.
 * @param string $bbcode Image BBCode to append.
 * @return string Combined body text.
 */
function corebb_post_image_append_to_body(string $body, string $bbcode): string
{
    $bbcode = trim($bbcode);
    if ($bbcode === '') {
        return $body;
    }
    $body = rtrim($body);
    return $body === '' ? $bbcode : $body . "\n\n" . $bbcode;
}

/**
 * Apply an optional upload to a post body when a file was submitted.
 *
 * Usage: compose body text plus uploaded-image markup in one helper when a form
 * supports post image uploads.
 * Referenced by: post-image-capable post workflows.
 *
 * @param string $body Existing post body.
 * @param array<string, mixed> $user Current user row.
 * @return array<string, mixed> Result containing updated body and uploaded path.
 */
function corebb_post_image_apply_optional_upload(string $body, array $user): array
{
    if (!corebb_post_image_upload_present()) {
        return ['ok' => true, 'body' => $body, 'uploaded_path' => ''];
    }

    $upload = corebb_post_image_handle_upload($user);
    if (empty($upload['ok'])) {
        return ['ok' => false, 'message' => (string)($upload['message'] ?? 'Post image upload failed.')];
    }

    return [
        'ok' => true,
        'body' => corebb_post_image_append_to_body($body, (string)$upload['bbcode']),
        'uploaded_path' => (string)$upload['public_path'],
    ];
}
