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
 |  admin_routes.php  - Declarative admin route map.     |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_layout_view_model.php';
require_once __DIR__ . '/admin_maintenance_view_model.php';
require_once __DIR__ . '/admin_forum_sim_view_model.php';
require_once __DIR__ . '/admin_content_view_model.php';
require_once __DIR__ . '/admin_style_helpers.php';
require_once __DIR__ . '/admin_dashboard_view_model.php';
require_once __DIR__ . '/admin_version_history_view_model.php';
require_once __DIR__ . '/admin_api_explorer_view_model.php';
require_once __DIR__ . '/admin_settings_view_model.php';
require_once __DIR__ . '/admin_mail_view_model.php';
require_once __DIR__ . '/admin_auth_settings_view_model.php';
require_once __DIR__ . '/admin_db_schema_deploy_view_model.php';
require_once __DIR__ . '/admin_updates_view_model.php';
require_once __DIR__ . '/admin_user_tools_view_model.php';
require_once __DIR__ . '/admin_global_messages_view_model.php';
require_once __DIR__ . '/admin_boards_view_model.php';
require_once __DIR__ . '/admin_icons_view_model.php';
require_once __DIR__ . '/admin_moderation_view_model.php';
require_once __DIR__ . '/admin_user_notes_view_model.php';
require_once __DIR__ . '/admin_action_log_view_model.php';
require_once __DIR__ . '/admin_user_pages_view_model.php';
require_once __DIR__ . '/admin_user_ip_check_view_model.php';
require_once __DIR__ . '/admin_host_lookup_view_model.php';
require_once __DIR__ . '/admin_mod_requests_view_model.php';
require_once __DIR__ . '/admin_deleted_posts_view_model.php';
require_once __DIR__ . '/admin_spam_ratings_view_model.php';
require_once __DIR__ . '/admin_pm_moderation_view_model.php';
require_once __DIR__ . '/admin_assign_title_view_model.php';
require_once __DIR__ . '/admin_edit_profile_view_model.php';
require_once __DIR__ . '/admin_contact_mods_view_model.php';
require_once __DIR__ . '/admin_latest_users_view_model.php';
require_once __DIR__ . '/user_appearance_view_model.php';

/**
 * Usage: Return every admin action that may be dispatched from admin.php.
 * Referenced by: admin.php permission checks and route resolution.
 *
 * @return array<string, array<string, mixed>> Admin route definitions keyed by act.
 */
function corebb_admin_route_definitions(): array
{
    return [
        '' => [
            'label' => 'Dashboard',
            'view' => 'pages/admin_dashboard.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_dashboard_model($viewer),
        ],
        'version_history' => [
            'label' => 'Version History',
            'view' => 'pages/admin_version_history.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_version_history_model($viewer),
        ],
        'edit_settings' => [
            'label' => 'System Settings',
            'view' => 'pages/admin_settings.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_settings_model($viewer, $_GET, $_POST),
        ],
        'mail_services' => [
            'label' => 'Mail Services',
            'view' => 'pages/admin_mail_services.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_mail_model($viewer, $_GET, $_POST),
        ],
        'auth_settings' => [
            'label' => 'Authentication Settings',
            'view' => 'pages/admin_auth_settings.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_auth_settings_model($viewer, $_GET, $_POST),
        ],
        'administrator_tools' => [
            'label' => 'Database Tools',
            'view' => 'pages/admin_maintenance.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_maintenance_model($viewer, $_GET, $_POST),
        ],
        'database_tools' => [
            'label' => 'Database Tools',
            'view' => 'pages/admin_maintenance.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_maintenance_model($viewer, $_GET, $_POST),
        ],
        'db_schema_deploy' => [
            'label' => 'DB Schema Deploy',
            'view' => 'pages/admin_db_schema_deploy.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_db_schema_deploy_model($viewer, $_GET, $_POST, $_FILES),
        ],
        'updates' => [
            'label' => 'Updates',
            'view' => 'pages/admin_updates.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_updates_model($viewer, $_GET, $_POST),
        ],
        'api_explorer' => [
            'label' => 'API Explorer',
            'view' => 'pages/admin_api_explorer.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_api_explorer_model($viewer, $_GET),
        ],
        'forum_sim' => [
            'label' => 'Forum Sim-Test',
            'view' => 'pages/admin_forum_sim.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_forum_sim_model($viewer, $_GET, $_POST),
        ],
        'edit_tos' => [
            'label' => 'Terms of Service',
            'view' => 'pages/admin_tos.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_tos_model($viewer, $_GET, $_POST),
        ],
        'edit_style' => [
            'label' => 'System Style',
            'view' => 'pages/admin_style.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_style_model($viewer, $_GET, $_POST),
        ],
        'edit_rights' => [
            'label' => 'User Rights',
            'view' => 'pages/admin_edit_rights.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_edit_rights_model($viewer, $_GET, $_POST),
        ],
        'change_user_password' => [
            'label' => 'Change Password',
            'view' => 'pages/admin_change_password.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_change_password_model($viewer, $_GET, $_POST),
        ],
        'add_user' => [
            'label' => 'Add User',
            'view' => 'pages/admin_add_user.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_add_user_model($viewer, $_GET, $_POST),
        ],
        'view_message' => [
            'label' => 'View Message',
            'view' => 'pages/admin_view_message.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_view_message_model($viewer, $_GET, $_POST),
        ],
        'global_message' => [
            'label' => 'Add Global Message',
            'view' => 'pages/admin_global_message.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_global_message_add_model($viewer, $_GET, $_POST),
        ],
        'edit_global_message' => [
            'label' => 'Edit Global Message',
            'view' => 'pages/admin_edit_global_message.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_global_message_edit_model($viewer, $_GET, $_POST),
        ],
        'remove_global_message' => [
            'label' => 'Remove Global Message',
            'view' => 'pages/admin_remove_global_message.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_global_message_remove_model($viewer, $_GET, $_POST),
        ],
        'manage_icons' => [
            'label' => 'Manage Icons',
            'view' => 'pages/admin_manage_icons.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_icons_model($viewer, $_GET, $_POST, $_FILES),
        ],
        'moderation' => [
            'label' => 'Moderation',
            'view' => 'pages/admin_moderation.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_moderation_model($viewer, $_GET, $_POST),
        ],
        'admin_notes' => [
            'label' => 'User Notes',
            'view' => 'pages/admin_user_notes.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_user_notes_model($viewer, $_GET, $_POST),
        ],
        'action_log' => [
            'label' => 'Action Log',
            'view' => 'pages/admin_action_log.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_action_log_model($viewer, $_GET, $_POST),
        ],
        'user_pages' => [
            'label' => 'User Pages',
            'view' => 'pages/admin_user_pages.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_user_pages_model($viewer, $_GET, $_POST),
        ],
        'user_ip_check' => [
            'label' => 'User IP Check',
            'view' => 'pages/admin_user_ip_check.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_user_ip_check_model($viewer, $_GET, $_POST),
        ],
        'host_lookup' => [
            'label' => 'Host Lookup',
            'view' => 'pages/admin_host_lookup.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_host_lookup_model($viewer, $_GET, $_POST),
        ],
        'mod_requests' => [
            'label' => 'Mod Post Requests',
            'view' => 'pages/admin_mod_requests.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_mod_requests_model($viewer, $_GET, $_POST),
        ],
        'moderate_message' => [
            'label' => 'Moderate Message',
            'view' => 'pages/admin_moderate_message.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_moderate_message_model($viewer, $_GET, $_POST),
        ],
        'deleted_posts' => [
            'label' => 'Deleted Posts',
            'view' => 'pages/admin_deleted_posts.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_deleted_posts_model($viewer, $_GET, $_POST),
        ],
        'spam_ratings' => [
            'label' => 'Spam Ratings',
            'view' => 'pages/admin_spam_ratings.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_spam_ratings_model($viewer, $_GET, $_POST),
        ],
        'pm_reports' => [
            'label' => 'Private Message Reports',
            'view' => 'pages/admin_pm_reports.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_pm_reports_model($viewer, $_GET, $_POST),
        ],
        'pm_history' => [
            'label' => 'Private Message History',
            'view' => 'pages/admin_pm_history.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_pm_history_model($viewer, $_GET, $_POST),
        ],
        'contact_mods' => [
            'label' => 'Contact Mods Inbox',
            'view' => 'pages/admin_contact_mods.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_contact_mods_model($viewer, $_GET, $_POST),
        ],
        'latest_users' => [
            'label' => 'Latest Users',
            'view' => 'pages/admin_latest_users.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_latest_users_model($viewer, $_GET),
        ],
        'assign_title' => [
            'label' => 'Assign Title',
            'view' => 'pages/admin_assign_title.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_assign_title_model($viewer, $_GET, $_POST),
        ],
        'edit_profile' => [
            'label' => 'Edit Profile',
            'view' => 'pages/admin_edit_profile.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_edit_profile_model($viewer, $_GET, $_POST),
        ],
        'user_appearance' => [
            'label' => 'User Appearance',
            'view' => 'pages/admin_user_appearance.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_user_appearance_model($viewer, $_GET, $_POST),
        ],
        'manageboards' => [
            'label' => 'Manage Boards',
            'view' => 'pages/admin_manage_boards.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_manage_boards_model($viewer, $_GET, $_POST),
        ],
        'movebrd' => [
            'label' => 'Move Board',
            'view' => 'pages/admin_manage_boards.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_manage_boards_model($viewer, $_GET, $_POST),
        ],
        'movecat' => [
            'label' => 'Move Category',
            'view' => 'pages/admin_manage_boards.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_manage_boards_model($viewer, $_GET, $_POST),
        ],
        'add_category' => [
            'label' => 'Add Category',
            'view' => 'pages/admin_add_category.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_add_category_model($viewer, $_GET, $_POST),
        ],
        'delete_category' => [
            'label' => 'Delete Category',
            'view' => 'pages/admin_delete_category.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_delete_category_model($viewer, $_GET, $_POST),
        ],
        'manageboards_cat' => [
            'label' => 'Modify Category',
            'view' => 'pages/admin_modify_category.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_modify_category_model($viewer, $_GET, $_POST),
        ],
        'addboard' => [
            'label' => 'Add Board',
            'view' => 'pages/admin_add_board.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_add_board_model($viewer, $_GET, $_POST),
        ],
        'modifyboard' => [
            'label' => 'Modify Board',
            'view' => 'pages/admin_modify_board.twig',
            'handler' => static fn(array $viewer): array => corebb_admin_modify_board_model($viewer, $_GET, $_POST),
        ],
    ];
}

/**
 * Usage: Resolve an incoming act into a known admin route or dashboard fallback.
 * Referenced by: admin.php before permission checks.
 *
 * @param string $act Requested admin action.
 * @return array<string, mixed> Route plus normalized act and unknown-act metadata.
 */
function corebb_admin_resolve_route(string $act): array
{
    $routes = corebb_admin_route_definitions();
    if (isset($routes[$act])) {
        $route = $routes[$act];
        $route['act'] = $act;
        $route['unknown_act'] = '';
        return $route;
    }

    $route = $routes[''];
    $route['act'] = '';
    $route['unknown_act'] = $act;
    return $route;
}

/**
 * Usage: Run the model builder attached to a resolved admin route.
 * Referenced by: admin.php once login and tool permissions have passed.
 *
 * @param array<string, mixed> $route Resolved route.
 * @param array<string, mixed> $viewer Current admin user row.
 * @return array<string, mixed> Page view model.
 */
function corebb_admin_route_model(array $route, array $viewer): array
{
    $handler = $route['handler'] ?? null;
    if (!is_callable($handler)) {
        return corebb_admin_dashboard_model($viewer);
    }

    $model = $handler($viewer);
    return is_array($model) ? $model : [];
}
