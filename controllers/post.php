<?php
/*-------------------------------------------------------
 | post.php - Public post composer controller.
 |
 | Handles new topics, replies, edits, blog-entry posting,
 | and post-image uploads behind rewritten /post routes.
 +-------------------------------------------------------*/

$root = dirname(__DIR__);

include $root . '/CookieEngine.php';
include_once $root . '/functions.php';
require_once $root . '/lib/view.php';
require_once $root . '/lib/layout_view_model.php';
require_once $root . '/lib/post_view_model.php';
require_once $root . '/lib/moderation_helpers.php';
require_once $root . '/lib/mobile_helpers.php';

$isPostImageUploadEndpoint = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
    && (string)($_GET['act'] ?? '') === 'image_upload';

$isLoggedIn = loggedin();

if ($isPostImageUploadEndpoint) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }

    if (!$isLoggedIn) {
        if (!headers_sent()) {
            http_response_code(401);
        }
        echo json_encode(['ok' => false, 'message' => 'You must be logged in to upload post images.']);
        exit;
    }

    $upload = corebb_post_image_handle_upload($userlogindata_a);
    if (empty($upload['ok'])) {
        if (!headers_sent()) {
            http_response_code(400);
        }
        echo json_encode([
            'ok' => false,
            'message' => (string)($upload['message'] ?? 'Post image upload failed.'),
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => !empty($upload['resized']) ? 'Image uploaded and scaled.' : 'Image uploaded.',
        'bbcode' => (string)($upload['bbcode'] ?? ''),
        'public_path' => (string)($upload['public_path'] ?? ''),
        'resized' => !empty($upload['resized']),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $act = (string)($_GET['act'] ?? '');
    $screen = match ($act) {
        'new' => 'post-new',
        'reply' => 'post-reply',
        default => isset($_GET['edit']) ? 'post-edit' : 'post',
    };
    if ($screen !== 'post') {
        corebb_mobile_redirect($screen, [
            'id' => (int)($_GET['id'] ?? $_GET['edit'] ?? $_GET['boardid'] ?? 0),
            'boardId' => (int)($_GET['boardid'] ?? $_GET['brd'] ?? 0),
            'quoteId' => (int)($_GET['quote'] ?? 0),
        ]);
    }
}

if (!$isLoggedIn) {
    header('Location: ' . (function_exists('corebb_public_url') ? corebb_public_url('/?msg=You+must+be+logged+in+to+post') : '/?msg=You+must+be+logged+in+to+post'));
    exit;
}

corebb_mod_ensure_schema();
$GLOBALS['corebb_layout_script'] = 'post:main';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $model = corebb_post_process($_POST, $userlogindata_a);
    corebb_render_public('pages/post_result.twig', ['model' => $model]);
    exit;
}

$model = corebb_post_form_model($_GET, $userlogindata_a);
corebb_render_public('pages/post_form.twig', ['model' => $model]);
