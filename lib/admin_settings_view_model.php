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
 |  admin_settings_view_model.php  - System settings     |
 |  view model.                                          |
 +-------------------------------------------------------+*/

include_once __DIR__ . '/admin_helpers.php';

/**
 * Usage: Build and process the settings admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_settings_model(array $viewer, array $request, array $post): array
{
    $messages = [];
    $saved = false;

    $method = (string)($request['method'] ?? '');
    if($method === 'post' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
        $messages = corebb_admin_save_system_settings($post);
        $saved = true;
        if(function_exists('addlogentry')){
            addlogentry((string)($viewer['username'] ?? 'Unknown'), (int)($viewer['accesslevel'] ?? 0), 'Modified system settings');
        }
    }

    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'settings' => corebb_admin_system_settings(),
        'messages' => $messages,
        'saved' => $saved,
        'message' => (string)($request['msg'] ?? ''),
        'bool_settings' => array_merge(['theme_vn_eol','encaseboards','showbasicstats','allowguests','customtitles','quickreply','markupcode','maintenancemode','auth_google_enabled','auth_google_allow_auto_create'], corebb_admin_rate_limit_boolean_settings()),
        'number_settings' => corebb_admin_rate_limit_numeric_settings(),
        'style_options' => corebb_admin_public_style_options(),
    ];
}
