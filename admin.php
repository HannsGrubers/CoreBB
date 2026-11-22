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
 |  admin.php  - Admin control center.                   |
 +-------------------------------------------------------+*/

define('IN_ADMIN', true);

require_once __DIR__ . '/lib/helpers/bootstrap.php';
include_once 'lib/helpers/view.php';
include_once 'lib/helpers/admin_routes.php';

$requestedAct = (string)($_GET['act'] ?? '');
if (!corebb_load_logged_in_user()) {
    header('Location: ' . corebb_public_join_base_path('/'));
    exit;
}

$viewer = is_array($GLOBALS['userlogindata_a'] ?? null) ? $GLOBALS['userlogindata_a'] : [];

if (!corebb_admin_can_access_admin($viewer)) {
    header('Location: ' . corebb_public_join_base_path('/denied/'));
    exit;
}

// Old bookmarks lead to the corresponding settings tab. An open TOS form
// can still save its original field through the section-scoped handler.
if ($requestedAct === 'edit_tos' || $requestedAct === 'edit_style') {
    $section = $requestedAct === 'edit_tos' ? 'terms' : 'appearance';
    if ($requestedAct === 'edit_tos' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && ($_GET['method'] ?? '') === 'post' && is_string($_POST['newtos'] ?? null)) {
        $requestedAct = 'edit_settings';
        $_GET = ['act' => $requestedAct, 'section' => 'terms', 'method' => 'post'];
        $_POST['settings'] = ['terms_of_service' => $_POST['newtos']];
    } else {
        header('Location: ' . corebb_public_join_base_path('/admin/?act=edit_settings&section=' . $section), true, 303);
        exit;
    }
}
if ($requestedAct === 'db_schema_deploy') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        header('Location: ' . corebb_public_join_base_path('/admin/?act=database_tools&section=schema'), true, 303);
        exit;
    }
    // Preserve the files and both CSRF tokens on an already-open legacy form.
    $requestedAct = 'database_tools';
    $_GET = ['act' => $requestedAct, 'section' => 'schema'];
    if (($_POST['action'] ?? '') === 'apply') {
        $_POST['production_source'] = 'database'; // The old form always used credentials for apply.
    }
}
$route = corebb_admin_resolve_route($requestedAct);
$act = $route['act'];
$unknownAct = $route['unknown_act'];
$toolKey = corebb_admin_tool_key_for_request($act, $_GET, $viewer);

if (!corebb_admin_can_access_tool($viewer, $toolKey)) {
    header('Location: ' . corebb_public_join_base_path('/denied/'));
    exit;
}

if ($act === 'forum_sim') {
    $simKey = 'corebb_sim_result:' . corebb_admin_viewer_id($viewer);
    $simSection = ($_GET['section'] ?? '') === 'cleanup' ? 'cleanup' : 'run';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[$simKey][$simSection])) {
        $simReceipt = $_SESSION[$simKey][$simSection];
        unset($_SESSION[$simKey][$simSection]);
        $_GET += $simReceipt['settings'];
    }
}
$model = corebb_admin_route_model($route, $viewer);
if (in_array($act, ['mod_requests', 'moderate_message', 'contact_mods', 'pm_reports', 'pm_history'], true)) {
    $queue = $act === 'moderate_message' ? 'mod_requests' : $act;
    $key = 'corebb_queue_result:' . $queue . ':' . corebb_admin_viewer_id($viewer);
    if (!empty($model['saved'])) {
        $_SESSION[$key]=$model['messages'];
        $returnUrl = $model['return_url'] ?? $model['queue_url'] ?? $model['action_url'];
        header('Location: ' . corebb_public_join_base_path($returnUrl), true, 303);
        exit;
    }
    if ($act !== 'moderate_message' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[$key])) {
        $model['messages']=$_SESSION[$key];
        unset($_SESSION[$key]);
    }
}
if ($act === 'moderation' && $model['mode'] === 'requests') {
    $key = 'corebb_appeal_decision:' . corebb_admin_viewer_id($viewer);
    if (!empty($model['decision_saved'])) {
        $_SESSION[$key]=$model['messages'];
        header('Location: ' . corebb_public_join_base_path($model['queue_url']), true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[$key])) {
        $model['messages']=$_SESSION[$key];
        unset($_SESSION[$key]);
    }
}
if ($act === 'moderation' && $model['mode'] === 'unban') {
    $key = 'corebb_unban_saved:' . corebb_admin_viewer_id($viewer);
    if (!empty($model['unban_saved'])) {
        $_SESSION[$key] = $model['messages'];
        header('Location: ' . corebb_public_join_base_path($model['back_url']), true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[$key])) {
        $model['messages'] = $_SESSION[$key];
        unset($_SESSION[$key]);
    }
}
if ($act === 'moderation' && $model['mode'] === 'ban') {
    $key = 'corebb_ban_saved:' . corebb_admin_viewer_id($viewer);
    if (!empty($model['ban_saved'])) {
        $_SESSION[$key] = $model['messages'];
        header('Location: ' . corebb_public_join_base_path('/admin/?act=moderation&mode=ban'), true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[$key])) {
        $model['messages'] = $_SESSION[$key];
        unset($_SESSION[$key]);
    }
}
if ($act === 'deleted_posts') {
    $key = 'corebb_deleted_post_result:' . corebb_admin_viewer_id($viewer);
    if (isset($model['notice'])) {
        $_SESSION[$key] = $model['notice'];
        header('Location: ' . corebb_public_join_base_path($model['return_url']), true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[$key])) {
        $model['messages'][] = $_SESSION[$key];
        unset($_SESSION[$key]);
    }
}
if ($act === 'view_message' && !empty($model['post'])) {
    $id = (int)$model['post']['id'];
    $key = 'corebb_message_result:' . corebb_admin_viewer_id($viewer) . ':' . $id;
    if (!empty($model['saved'])) {
        $_SESSION[$key] = [$model['messages'], $model['continue_url'] ?? ''];
        header('Location: ' . corebb_public_join_base_path('/admin/?act=view_message&method=view&messageid=' . $id), true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[$key])) {
        [$model['messages'], $model['continue_url']] = $_SESSION[$key];
        unset($_SESSION[$key]);
    }
}
if ($act === 'manage_icons') {
    $key = 'corebb_icon_result:' . corebb_admin_viewer_id($viewer);
    if (isset($model['notice'])) {
        $_SESSION[$key] = $model['notice'];
        $suffix = $model['queryString'] !== '' ? '&' . $model['queryString'] : '';
        header('Location: ' . corebb_public_join_base_path('/admin/?act=manage_icons' . $suffix), true, 303);
        exit;
    }
    if (isset($_SESSION[$key])) {
        [$kind,$message,$model['upload_error']] = $_SESSION[$key];
        $model[$kind][] = $message;
        unset($_SESSION[$key]);
    }
}
if (isset($model['move_notice'])) {
    $_SESSION['corebb_board_move:' . corebb_admin_viewer_id($viewer)] = $model['move_notice'];
    header('Location: ' . corebb_public_join_base_path('/admin/?act=manageboards') . '#' . $model['move_anchor'], true, 303);
    exit;
}
if ($act === 'manageboards' && isset($_SESSION['corebb_board_move:' . corebb_admin_viewer_id($viewer)])) {
    $key = 'corebb_board_move:' . corebb_admin_viewer_id($viewer);
    [$kind,$notice] = $_SESSION[$key];
    $model[$kind][] = $notice;
    unset($_SESSION[$key]);
}
if ($act === 'user_appearance' && !empty($model['library'])) {
    $noticeKey = 'corebb_preset_saved:' . corebb_admin_viewer_id($viewer);
    if (!empty($model['library_saved'])) {
        $_SESSION[$noticeKey] = $model['messages'];
        header('Location: ' . corebb_public_join_base_path('/admin/?act=user_appearance&section=presets&preset=' . $model['return_id']), true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[$noticeKey])) {
        $model['messages'] = $_SESSION[$noticeKey];
        unset($_SESSION[$noticeKey]);
    }
}
if (in_array($act, ['assign_title', 'admin_notes', 'user_appearance'], true) && $model['selected_user']) {
    $memberId = $model['selected_user']['id'];
    $noticePrefix = match ($act) {
        'assign_title' => 'corebb_title_saved:',
        'admin_notes' => 'corebb_note_added:',
        'user_appearance' => 'corebb_appearance_saved:',
    };
    $noticeKey = $noticePrefix . corebb_admin_viewer_id($viewer) . ':' . $memberId;
    if ($model['saved']) {
        $_SESSION[$noticeKey] = $model['messages'];
        header('Location: ' . corebb_public_join_base_path('/admin/?act=' . $act . '&userid=' . $memberId), true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[$noticeKey])) {
        $model['messages'] = $_SESSION[$noticeKey];
        unset($_SESSION[$noticeKey]);
    }
}
if ($act === 'edit_profile' && !empty($model['user']) && isset($model['section'])) {
    $memberId = $model['user']['id'];
    $section = $model['section'];
    $noticeKey = 'corebb_profile_saved:' . corebb_admin_viewer_id($viewer) . ':' . $memberId . ':' . $section;
    if ($model['saved']) {
        $_SESSION[$noticeKey] = $model['messages'];
        header('Location: ' . corebb_public_join_base_path('/admin/?act=edit_profile&userid=' . $memberId . '&section=' . $section), true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[$noticeKey])) {
        $model['messages'] = $_SESSION[$noticeKey];
        unset($_SESSION[$noticeKey]);
    }
}
if ($act === 'change_user_password') {
    $noticeKey = 'corebb_password_changed:' . corebb_admin_viewer_id($viewer);
    if ($model['saved']) {
        $_SESSION[$noticeKey] = array_intersect_key($model['user'], array_flip(['id', 'username', 'level_name', 'profile_url']));
        header('Location: ' . corebb_public_join_base_path('/admin/?act=change_user_password'), true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[$noticeKey])) {
        if (!$model['user'] && !$model['errors']) { $model['changed_user'] = $_SESSION[$noticeKey]; }
        unset($_SESSION[$noticeKey]);
    }
}
if ($act === 'add_user') {
    $noticeKey = 'corebb_account_created:' . corebb_admin_viewer_id($viewer);
    if ($model['created_user'] !== null) {
        $_SESSION[$noticeKey] = $model['created_user'];
        header('Location: ' . corebb_public_join_base_path('/admin/?act=add_user'), true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[$noticeKey])) {
        $model['created_user'] = $_SESSION[$noticeKey];
        unset($_SESSION[$noticeKey]);
    }
}
if ($act === 'edit_rights' && !empty($model['user'])) {
    $memberId = $model['user']['id'];
    $noticeKey = 'corebb_rights_saved:' . corebb_admin_viewer_id($viewer) . ':' . $memberId;
    if (!empty($model['saved'])) {
        $_SESSION[$noticeKey] = $model['messages'];
        header('Location: ' . corebb_public_join_base_path('/admin/?act=edit_rights&user=' . $memberId), true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[$noticeKey])) {
        $model['messages'] = $_SESSION[$noticeKey];
        unset($_SESSION[$noticeKey]);
    }
}
if ($act === 'user_pages' && $model['selected_user']) {
    $memberId = $model['selected_user']['id'];
    $noticeKey = 'corebb_user_page_result:' . corebb_admin_viewer_id($viewer) . ':' . $memberId;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $model['messages'] && !$model['errors']) {
        $_SESSION[$noticeKey] = $model['messages'];
        header('Location: ' . corebb_public_join_base_path('/admin/?act=user_pages&userid=' . $memberId . '&section=profile'), true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[$noticeKey])) {
        $model['messages'] = $_SESSION[$noticeKey];
        unset($_SESSION[$noticeKey]);
    }
}
if ($act === 'forum_sim' && $model['attempted']) {
    $section = $model['section'];
    $_SESSION[$simKey][$section] = array_intersect_key($model, array_flip(['messages', 'errors', 'settings']));
    header('Location: ' . corebb_public_join_base_path('/admin/?act=forum_sim&section=' . $section), true, 303);
    exit;
}
if (isset($simReceipt)) {
    $model['messages'] = $simReceipt['messages'];
    $model['errors'] = array_merge($simReceipt['errors'], $model['errors']);
}
if ($toolKey === 'updates') {
    $feedbackKey = 'corebb_update_feedback:' . corebb_update_app_root() . ':' . (int)($viewer['id'] ?? 0);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $model['can_manage'] && !$model['errors']
        && in_array($_POST['action'] ?? '', ['check_updates', 'upload_package', 'sideload_package', 'clear_update_lock'], true)) {
        $_SESSION[$feedbackKey] = $model['messages'];
        header('Location: ' . corebb_public_join_base_path('/admin/?act=updates&section=' . $model['section']), true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION[$feedbackKey])) {
        $model['messages'] = array_merge($model['messages'], $_SESSION[$feedbackKey]);
        unset($_SESSION[$feedbackKey]);
    }
}
if (in_array($toolKey, ['database_tools', 'db_schema_deploy'], true)) {
    $section = $model['section'];
    if ($model['result'] !== null) {
        $_SESSION['corebb_database_tool_results'][$section] = $model['result'];
        header('Location: ' . corebb_public_join_base_path('/admin/?act=database_tools&section=' . $section), true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $model['result'] = $_SESSION['corebb_database_tool_results'][$section] ?? null;
        unset($_SESSION['corebb_database_tool_results'][$section]);
    }
}
if ($act === 'global_messages') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $model['messages']) {
        $_SESSION['corebb_global_message_feedback'] = $model['messages'];
        header('Location: ' . corebb_public_join_base_path('/admin/?act=global_messages'), true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION['corebb_global_message_feedback'])) {
        $model['messages'] = $_SESSION['corebb_global_message_feedback'];
        unset($_SESSION['corebb_global_message_feedback']);
    }
}

if ($unknownAct !== '') {
    $model['message'] = 'Unknown or retired admin action: ' . $unknownAct;
} elseif (isset($_GET['msg']) && (string)$_GET['msg'] !== '' && empty($model['message'])) {
    $model['message'] = (string)$_GET['msg'];
}

if (corebb_admin_tool_is_special_access($viewer, $toolKey)) {
    $toolMap = corebb_admin_tool_map();
    $toolLabel = $act === 'global_messages' ? $route['label'] : (string)($toolMap[$toolKey]['label'] ?? $toolKey);
    $model['special_access_notice'] = 'Special Access: ' . $toolLabel;
}

$content = corebb_capture((string)$route['view'], ['model' => $model]);
$layout = corebb_admin_layout_model($viewer, $model, [
    'act' => $act,
    'tool_key' => $toolKey === 'db_schema_deploy' ? 'database_tools' : $toolKey,
    'route_label' => (string)($route['label'] ?? 'Dashboard'),
    'view' => (string)($route['view'] ?? ''),
]);

echo corebb_twig()->render('layouts/admin.twig', [
    'layout' => $layout,
    'content' => $content,
]);
