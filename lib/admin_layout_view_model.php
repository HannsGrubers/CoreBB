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
 |  admin_layout_view_model.php  - Admin chrome model.   |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/corebb_browser_helpers.php';
require_once __DIR__ . '/admin_user_tools_view_model.php';
require_once __DIR__ . '/admin_mod_requests_view_model.php';
require_once __DIR__ . '/admin_pm_moderation_view_model.php';
require_once __DIR__ . '/contact_mods_helpers.php';
require_once __DIR__ . '/notification_helpers.php';
require_once __DIR__ . '/performance_helpers.php';
require_once __DIR__ . '/pm_helpers.php';
require_once __DIR__ . '/theme_helpers.php';
require_once __DIR__ . '/vn_eol_chrome_helpers.php';

/**
 * Usage: Return the display model for the active admin user.
 * Referenced by: corebb_admin_layout_model().
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @return array<string, mixed> Viewer display state.
 */
function corebb_admin_layout_viewer(array $viewer): array
{
    $userId = (int)($viewer['id'] ?? 0);
    $level = (int)($viewer['accesslevel'] ?? 0);
    $username = (string)($viewer['username'] ?? 'User');

    return [
        'id' => $userId,
        'username' => $username,
        'access_level' => $level,
        'level_label' => corebb_user_level_label($level),
        'profile_url' => $userId > 0 ? corebb_public_join_base_path('/profile/' . $userId . '/') : '',
    ];
}

/**
 * Usage: Map permission-tool keys to their primary admin URLs.
 * Referenced by: corebb_admin_nav_groups().
 *
 * @param string $toolKey Permission-tool key.
 * @return string Admin URL for that tool.
 */
function corebb_admin_tool_url(string $toolKey): string
{
    $urls = [
        'admin_home' => '/admin/',
        'version_history' => '/admin/?act=version_history',
        'edit_settings' => '/admin/?act=edit_settings',
        'mail_services' => '/admin/?act=mail_services',
        'auth_settings' => '/admin/?act=auth_settings',
        'database_tools' => '/admin/?act=database_tools',
        'db_schema_deploy' => '/admin/?act=db_schema_deploy',
        'updates' => '/admin/?act=updates',
        'api_explorer' => '/admin/?act=api_explorer',
        'forum_sim' => '/admin/?act=forum_sim',
        'edit_tos' => '/admin/?act=edit_tos',
        'edit_style' => '/admin/?act=edit_style',
        'edit_rights' => '/admin/?act=edit_rights',
        'change_user_password' => '/admin/?act=change_user_password',
        'add_user' => '/admin/?act=add_user',
        'global_message' => '/admin/?act=global_message',
        'edit_global_message' => '/admin/?act=edit_global_message',
        'remove_global_message' => '/admin/?act=remove_global_message',
        'pm_history' => '/admin/?act=pm_history',
        'manage_boards' => '/admin/?act=manageboards',
        'manage_icons' => '/admin/?act=manage_icons',
        'assign_title' => '/admin/?act=assign_title',
        'edit_profile' => '/admin/?act=edit_profile',
        'user_appearance_admin' => '/admin/?act=user_appearance',
        'moderation_ban' => '/admin/?act=moderation&mode=ban',
        'moderation_unban' => '/admin/?act=moderation&mode=unban',
        'moderation_requests' => '/admin/?act=moderation&mode=requests',
        'mod_requests' => '/admin/?act=mod_requests',
        'contact_mods' => '/admin/?act=contact_mods',
        'pm_reports' => '/admin/?act=pm_reports',
        'latest_users' => '/admin/?act=latest_users',
        'view_message' => '/admin/?act=view_message',
        'deleted_posts' => '/admin/?act=deleted_posts',
        'spam_ratings' => '/admin/?act=spam_ratings',
        'user_pages' => '/admin/?act=user_pages',
        'admin_notes' => '/admin/?act=admin_notes',
        'user_ip_check' => '/admin/?act=user_ip_check',
        'host_lookup' => '/admin/?act=host_lookup',
        'action_log' => '/admin/?act=action_log',
        'vip_appearance_self' => '/user-cp/appearance/',
    ];

    return corebb_public_join_base_path($urls[$toolKey] ?? '/admin/');
}

/**
 * Usage: Return live count badges for admin queue-style tools.
 * Referenced by: corebb_admin_nav_groups().
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @return array<string, int> Badge counts keyed by permission-tool key.
 */
function corebb_admin_nav_badges(array $viewer): array
{
    return [
        'mod_requests' => corebb_mod_requests_new_count($viewer),
        'contact_mods' => corebb_contact_mods_new_count(false),
        'pm_reports' => corebb_pm_reports_new_count(),
    ];
}

/**
 * Usage: Define the compact admin navigation groups.
 * Referenced by: corebb_admin_nav_groups().
 *
 * @return array<string, array<int, string>> Group labels mapped to tool keys.
 */
function corebb_admin_nav_group_definitions(): array
{
    return [
        'Overview' => ['admin_home', 'version_history'],
        'System' => [
            'edit_settings', 'auth_settings', 'mail_services', 'edit_tos', 'edit_style', 'global_message',
            'edit_global_message', 'remove_global_message', 'database_tools',
            'db_schema_deploy', 'updates', 'api_explorer', 'forum_sim',
        ],
        'People' => [
            'user_pages', 'latest_users', 'edit_rights', 'add_user',
            'change_user_password', 'edit_profile', 'assign_title',
            'admin_notes', 'user_ip_check', 'host_lookup', 'user_appearance_admin',
        ],
        'Content' => ['manage_boards', 'manage_icons', 'view_message', 'deleted_posts', 'spam_ratings', 'action_log'],
        'Moderation' => [
            'moderation_ban', 'moderation_unban', 'moderation_requests',
            'mod_requests', 'contact_mods', 'pm_reports', 'pm_history',
        ],
    ];
}

/**
 * Usage: Build visible sidebar groups for the current admin user.
 * Referenced by: corebb_admin_layout_model().
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param string $activeToolKey Permission-tool key for the current page.
 * @return array<int, array<string, mixed>> Twig-ready navigation groups.
 */
function corebb_admin_nav_groups(array $viewer, string $activeToolKey): array
{
    $toolMap = corebb_admin_tool_map();
    $badges = corebb_admin_nav_badges($viewer);
    $groups = [];

    foreach (corebb_admin_nav_group_definitions() as $groupLabel => $toolKeys) {
        $items = [];
        foreach ($toolKeys as $toolKey) {
            if (!isset($toolMap[$toolKey]) || !corebb_admin_can_access_tool($viewer, $toolKey)) {
                continue;
            }
            $items[] = [
                'key' => $toolKey,
                'label' => (string)$toolMap[$toolKey]['label'],
                'url' => corebb_admin_tool_url($toolKey),
                'active' => $toolKey === $activeToolKey,
                'special' => corebb_admin_tool_is_special_access($viewer, $toolKey),
                'badge' => max(0, (int)($badges[$toolKey] ?? 0)),
            ];
        }
        if ($items) {
            $groups[] = ['label' => $groupLabel, 'items' => $items];
        }
    }

    return $groups;
}

/**
 * Usage: Build top-of-page notices for the admin layout.
 * Referenced by: corebb_admin_layout_model().
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @return array<int, array<string, string>> Alert rows.
 */
function corebb_admin_layout_alerts(array $viewer): array
{
    $alerts = [];
    $msg = trim(str_replace('+', ' ', strip_tags((string)($_GET['msg'] ?? ''))));
    if ($msg !== '') {
        $alerts[] = ['label' => 'System Message', 'message' => $msg, 'type' => 'info'];
    }

    $flash = corebb_contact_mods_flash_pull();
    foreach (($flash['messages'] ?? []) as $message) {
        $alerts[] = ['label' => 'Contact Mods Result', 'message' => (string)$message, 'type' => 'success'];
    }
    foreach (($flash['errors'] ?? []) as $message) {
        $alerts[] = ['label' => 'Contact Mods Error', 'message' => (string)$message, 'type' => 'error'];
    }

    foreach (db_all('SELECT id, message, poster FROM globalmessages ORDER BY id ASC') as $globalMessage) {
        $message = trim((string)($globalMessage['message'] ?? ''));
        if ($message !== '') {
            $poster = trim((string)($globalMessage['poster'] ?? ''));
            $alerts[] = [
                'label' => 'Global Message',
                'message' => $poster !== '' ? ($message . ' - ' . $poster) : $message,
                'type' => 'global',
            ];
        }
    }

    return $alerts;
}

/**
 * Usage: Build the small forum-stat footer text for admin pages.
 * Referenced by: corebb_admin_layout_model().
 *
 * @return string Footer statistic text.
 */
function corebb_admin_footer_stats(): string
{
    if (!corebb_board_setting_enabled('showbasicstats')) {
        return '';
    }

    $today = date('m/d/y');
    return number_format((int)corebb_perf_total_forums()) . ' Boards | '
        . number_format((int)corebb_perf_total_posts()) . ' Messages ('
        . number_format((int)corebb_perf_today_posts($today)) . ' Today)';
}

/**
 * Usage: Assemble the full admin layout payload for Twig.
 * Referenced by: admin.php after page content has been captured.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $pageModel Admin page model.
 * @param array<string, mixed> $context Route/render context.
 * @return array<string, mixed> Admin layout model.
 */
function corebb_admin_layout_model(array $viewer, array $pageModel = [], array $context = []): array
{
    global $SiteURL, $SiteName, $BoardURL, $BoardName;

    $chrome = corebb_vn_eol_chrome_links((string)($SiteURL ?? ''), (string)($SiteName ?? ''), (string)($BoardURL ?? ''), (string)($BoardName ?? ''));
    $SiteURL = $chrome['site_url'];
    $SiteName = $chrome['site_name'];
    $BoardURL = $chrome['board_url'];
    $BoardName = $chrome['board_name'];

    $activeToolKey = (string)($context['tool_key'] ?? 'admin_home');
    $routeLabel = (string)($context['route_label'] ?? 'Dashboard');
    $viewerModel = corebb_admin_layout_viewer($viewer);
    $notificationCount = corebb_notifications_uncleared_count((int)$viewerModel['id'], false);
    $pmUnread = corebb_pm_count((int)$viewerModel['id'], 'unread');

    return [
        'head' => [
            'title' => 'CoreBB Admin - ' . $routeLabel,
            'body_class' => 'wb-admin-body',
            'staging' => defined('COREBB_ENV') && (string)COREBB_ENV === 'staging',
            'stylesheets' => [corebb_theme_url('style_vn_eol.css'), corebb_theme_url('style_theme.css')],
            'scripts' => [
                corebb_theme_url('scripts/minmax.js'),
                corebb_theme_url('scripts/repopulate2.js'),
                corebb_theme_url('scripts/vendor/prism/prism-corebb.min.js'),
                corebb_theme_url('scripts/corebb_prism_codeblocks.js'),
            ],
        ],
        'chrome' => $chrome,
        'viewer' => $viewerModel,
        'route' => [
            'act' => (string)($context['act'] ?? ''),
            'tool_key' => $activeToolKey,
            'label' => $routeLabel,
            'view' => (string)($context['view'] ?? ''),
        ],
        'nav' => [
            'groups' => corebb_admin_nav_groups($viewer, $activeToolKey),
        ],
        'menu' => [
            'board_url' => corebb_public_join_base_path('/'),
            'logout_url' => corebb_public_join_base_path('/logoff/'),
            'pm_url' => corebb_public_join_base_path('/private-messages/'),
            'notifications_url' => corebb_public_join_base_path('/notifications/'),
            'pm_unread' => $pmUnread,
            'notification_count' => $notificationCount,
        ],
        'alerts' => corebb_admin_layout_alerts($viewer),
        'special_access_notice' => (string)($pageModel['special_access_notice'] ?? ''),
        'footer' => [
            'stats' => corebb_admin_footer_stats(),
            'version_label' => corebb_version_label(),
            'copyright_start' => 2005,
            'copyright_year' => date('Y'),
            'copyright_name' => 'CoreBB',
            'debug_allowed' => (int)($viewer['accesslevel'] ?? 0) >= 5 && (string)($_GET['debug'] ?? '') === 'yes',
        ],
    ];
}
