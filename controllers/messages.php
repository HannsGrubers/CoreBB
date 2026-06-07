<?php
/*-------------------------------------------------------
 | messages.php - Private-message controller.
 |
 | Handles folder views, message views, and send actions
 | behind the rewritten /private-messages routes.
 +-------------------------------------------------------*/

$root = dirname(__DIR__);

include $root . '/CookieEngine.php';
include_once $root . '/functions.php';
include_once $root . '/lib/pm_helpers.php';
include_once $root . '/lib/pm_view_model.php';
include_once $root . '/lib/pm_send_view_model.php';
include_once $root . '/lib/view.php';
require_once $root . '/lib/layout_view_model.php';
include_once $root . '/lib/mobile_helpers.php';

/**
 * Usage: Redirect a private-message action to its next public URL.
 * Referenced by: login checks, send processing, and missing-message handling.
 *
 * @param string $url Public path for the Location header.
 * @return never
 */
function corebb_messages_redirect(string $url): void
{
    if (function_exists('corebb_public_url')) {
        $url = corebb_public_url($url);
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Usage: Read the private-message action from the rewritten route.
 * Referenced by: controller dispatch below.
 *
 * @return string Normalized action name.
 */
function corebb_messages_action(): string
{
    $action = strtolower(trim((string)($_GET['action'] ?? 'folder')));
    return in_array($action, ['folder', 'send', 'view'], true) ? $action : 'folder';
}

/**
 * Usage: Resolve the requested mailbox folder.
 * Referenced by: folder display and mobile fallback dispatch.
 *
 * @return string One of unread, read, or sent.
 */
function corebb_messages_requested_folder(): string
{
    $folder = strtolower(trim((string)($_GET['folder'] ?? 'unread')));
    return in_array($folder, ['unread', 'read', 'sent'], true) ? $folder : 'unread';
}

$action = corebb_messages_action();
$folder = corebb_messages_requested_folder();
$pmid = isset($_GET['pm']) ? (int)$_GET['pm'] : 0;
$method = strtolower(trim((string)($_GET['method'] ?? '')));

if ($action === 'send') {
    if (!isset($_POST['from_send'])) {
        corebb_mobile_redirect('pm-send', ['to' => (string)($_GET['usr'] ?? '')]);
    }
} elseif ($action === 'view') {
    corebb_mobile_redirect('pm-message', ['id' => $pmid, 'folder' => $method]);
} else {
    corebb_mobile_redirect('pm', ['folder' => $folder === 'unread' ? 'inbox' : $folder]);
}

if (!loggedin()) {
    if ($action === 'send') {
        corebb_messages_redirect('/login/?msg=' . urlencode('You must be logged in to send private messages!'));
    }
    corebb_messages_redirect('/');
}

if ($action === 'send' && isset($_POST['from_send'])) {
    $pmResult = corebb_pm_send_from_post($userlogindata_a, $_POST);
    if (!$pmResult['ok']) {
        corebb_messages_redirect('/private-messages/send/?msg=' . urlencode($pmResult['message']));
    }

    corebb_messages_redirect('/private-messages/?msg=' . urlencode($pmResult['message']));
}

if ($action === 'view') {
    $model = corebb_pm_view_model($userlogindata_a, $pmid, $method);
    if (!empty($model['missing'])) {
        corebb_messages_redirect('/private-messages/?msg=' . urlencode((string)($model['message'] ?? 'Sorry, unknown private message ID.')));
    }

    $GLOBALS['corebb_layout_script'] = 'messages:view';
    $model['page'] = 'view';
    corebb_render_public('pages/pm_view.twig', ['model' => $model]);
    exit;
}

if ($action === 'send') {
    $GLOBALS['corebb_layout_script'] = 'messages:send';
    $model = corebb_pm_send_model($userlogindata_a, $_GET);
    $model['page'] = 'send';
    corebb_render_public('pages/pm_send.twig', ['model' => $model]);
    exit;
}

$GLOBALS['corebb_layout_script'] = 'messages:folder';
$model = corebb_pm_folder_model($userlogindata_a, $folder);
$model['page'] = 'folder';
corebb_render_public('pages/pm_folder.twig', ['model' => $model]);
