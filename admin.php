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

require_once __DIR__ . '/lib/bootstrap.php';
include_once 'lib/view.php';
include_once 'lib/admin_routes.php';

$requestedAct = (string)($_GET['act'] ?? '');
$route = corebb_admin_resolve_route($requestedAct);
$act = (string)($route['act'] ?? '');
$unknownAct = (string)($route['unknown_act'] ?? '');

if (!corebb_load_logged_in_user()) {
    header('Location: ' . corebb_public_join_base_path('/'));
    exit;
}

$viewer = is_array($GLOBALS['userlogindata_a'] ?? null) ? $GLOBALS['userlogindata_a'] : [];
$toolKey = corebb_admin_tool_key_for_request($act, $_GET);

if (!corebb_admin_can_access_admin($viewer)) {
    header('Location: ' . corebb_public_join_base_path('/denied/'));
    exit;
}

if (!corebb_admin_can_access_tool($viewer, $toolKey)) {
    header('Location: ' . corebb_public_join_base_path('/denied/'));
    exit;
}

$model = corebb_admin_route_model($route, $viewer);

if ($unknownAct !== '') {
    $model['message'] = 'Unknown or retired admin action: ' . $unknownAct;
} elseif (isset($_GET['msg']) && (string)$_GET['msg'] !== '' && empty($model['message'])) {
    $model['message'] = (string)$_GET['msg'];
}

if (corebb_admin_tool_is_special_access($viewer, $toolKey)) {
    $toolMap = corebb_admin_tool_map();
    $toolLabel = (string)($toolMap[$toolKey]['label'] ?? $toolKey);
    $model['special_access_notice'] = 'Special Access: ' . $toolLabel;
}

$content = corebb_capture((string)$route['view'], ['model' => $model]);
$layout = corebb_admin_layout_model($viewer, $model, [
    'act' => $act,
    'tool_key' => $toolKey,
    'route_label' => (string)($route['label'] ?? 'Dashboard'),
    'view' => (string)($route['view'] ?? ''),
]);

echo corebb_twig()->render('layouts/admin.twig', [
    'layout' => $layout,
    'content' => $content,
]);
