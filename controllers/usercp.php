<?php
/*-------------------------------------------------------
 | usercp.php - User control panel controller.
 |
 | Handles the signed-in user's account center, settings,
 | avatar, username appearance, and notification pages.
 +-------------------------------------------------------*/

$root = dirname(__DIR__);

include $root . '/CookieEngine.php';
include_once $root . '/functions.php';
require_once $root . '/lib/view.php';
require_once $root . '/lib/layout_view_model.php';
require_once $root . '/lib/usercp_view_model.php';
require_once $root . '/lib/usercp_settings_view_model.php';
require_once $root . '/lib/avatar_view_model.php';
require_once $root . '/lib/user_appearance_view_model.php';
require_once $root . '/lib/notifications_view_model.php';

/**
 * Usage: Redirect a User CP action to its next public URL.
 * Referenced by: POST branches in this controller.
 *
 * @param string $url Public path for the Location header.
 * @return void
 */
function corebb_usercp_controller_redirect(string $url): void
{
    if (function_exists('corebb_public_url')) {
        $url = corebb_public_url($url);
    }
    header('Location: ' . $url);
    exit;
}

if (!loggedin()) {
    corebb_usercp_controller_redirect('/login/?msg=' . urlencode('You must be logged in to access the User CP.'));
}

$uid = (int)($userlogindata_a['id'] ?? 0);
if ($uid <= 0) {
    corebb_usercp_controller_redirect('/login/?msg=' . urlencode('You must be logged in to access the User CP.'));
}

$action = strtolower(trim((string)($_GET['action'] ?? 'index')));
$allowedActions = ['index', 'profile', 'avatar', 'signature', 'options', 'appearance', 'notifications'];
if (!in_array($action, $allowedActions, true)) {
    $action = 'index';
}

switch ($action) {
    case 'profile':
        if (($_GET['act'] ?? '') === 'submit' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (corebb_usercp_save_profile($uid)) {
                corebb_usercp_controller_redirect('/user-cp/profile/?msg=Profile+Successfully+Updated!');
            }
            corebb_usercp_controller_redirect('/user-cp/profile/?msg=' . urlencode('Error Updating Profile: ' . db_error()));
        }

        $GLOBALS['corebb_layout_script'] = 'usercp:profile';
        $model = corebb_usercp_base_model($uid);
        $model['profile'] = corebb_usercp_load_profile($uid);
        $model['fields'] = corebb_profile_fields();
        $model['boardName'] = $BoardName ?? 'Board';
        corebb_render_public('pages/edit_profile.twig', ['model' => $model]);
        break;

    case 'avatar':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $result = corebb_avatar_handle_submit($uid);
            corebb_usercp_controller_redirect('/user-cp/avatar/?msg=' . urlencode($result['message']));
        }

        $GLOBALS['corebb_layout_script'] = 'usercp:avatar';
        $model = corebb_usercp_base_model($uid);
        $model += corebb_avatar_model($uid);
        $model['boardName'] = $BoardName ?? 'Board';
        corebb_render_public('pages/edit_avatar.twig', ['model' => $model]);
        break;

    case 'signature':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['post'])) {
            if (corebb_usercp_save_signature($uid)) {
                corebb_usercp_controller_redirect('/user-cp/signature/?msg=Signature+Successfully+Updated!');
            }
            corebb_usercp_controller_redirect('/user-cp/signature/?msg=' . urlencode('Error Updating Signature: ' . db_error()));
        }

        $GLOBALS['corebb_layout_script'] = 'usercp:signature';
        $model = corebb_usercp_base_model($uid);
        $model['signatureRow'] = corebb_usercp_load_signature($uid);
        $model['signatureParts'] = corebb_signature_parts($model['signatureRow']);
        corebb_render_public('pages/edit_signature.twig', ['model' => $model]);
        break;

    case 'options':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (isset($_POST['ThreadPages']) || isset($_POST['BoardPages']))) {
            if (corebb_usercp_save_options($uid)) {
                $userlogindata_a['ThreadPages'] = (int)($_POST['ThreadPages'] ?? 25);
                $userlogindata_a['BoardPages'] = (int)($_POST['BoardPages'] ?? 25);
                corebb_usercp_controller_redirect('/user-cp/options/?msg=Successfully+updated+settings.');
            }
            corebb_usercp_controller_redirect('/user-cp/options/?msg=' . urlencode('Error updating settings.'));
        }

        $GLOBALS['corebb_layout_script'] = 'usercp:options';
        $model = corebb_usercp_base_model($uid);
        $model['options'] = corebb_usercp_load_options($uid);
        corebb_render_public('pages/edit_options.twig', ['model' => $model]);
        break;

    case 'appearance':
        $viewer = corebb_user_appearance_load_user($uid);
        if (!$viewer || !corebb_vip_style_user_can_self_manage($uid, $viewer)) {
            corebb_usercp_controller_redirect('/denied/');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $result = corebb_user_appearance_save_self($uid, $_POST);
            corebb_usercp_controller_redirect('/user-cp/appearance/?msg=' . urlencode((string)($result['message'] ?? '')) . '&err=' . (!empty($result['ok']) ? '0' : '1'));
        }

        $GLOBALS['corebb_layout_script'] = 'usercp:appearance';
        $model = corebb_user_appearance_self_model($uid, $_GET);
        corebb_render_public('pages/edit_appearance.twig', ['model' => $model]);
        break;

    case 'notifications':
        $GLOBALS['corebb_layout_script'] = 'usercp:notifications';
        $model = corebb_notifications_model($userlogindata_a, $_POST);
        corebb_render_public('pages/notifications.twig', ['model' => $model]);
        break;

    case 'index':
    default:
        $GLOBALS['corebb_layout_script'] = 'usercp:index';
        $model = corebb_fetch_usercp_model($uid);
        corebb_render_public('pages/usercp.twig', ['model' => $model]);
        break;
}
