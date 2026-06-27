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
 |  admin_api_explorer_view_model.php  - Admin API      |
 |  Explorer model.                                      |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_user_tools_view_model.php';
require_once __DIR__ . '/corebb_url_helpers.php';

/**
 * Usage: Build and process the api explorer admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_api_explorer_model(array $viewer, array $request): array
{
    $model = corebb_admin_require_model_base($viewer, 'API Explorer', $request);
    $model['api_base_path'] = rtrim(corebb_public_join_base_path('/api/v1'), '/');
    $model['default_endpoint'] = corebb_public_join_base_path('/api/v1/health');
    $model['endpoints'] = [
        ['label' => 'Health', 'path' => corebb_public_join_base_path('/api/v1/health')],
        ['label' => 'Auth CSRF', 'path' => corebb_public_join_base_path('/api/v1/auth/csrf')],
        ['label' => 'Current Viewer', 'path' => corebb_public_join_base_path('/api/v1/me')],
        ['label' => 'Board Index', 'path' => corebb_public_join_base_path('/api/v1/index')],
        ['label' => 'Board Topics', 'path' => corebb_public_join_base_path('/api/v1/boards/1')],
        ['label' => 'Thread Posts', 'path' => corebb_public_join_base_path('/api/v1/threads/1')],
        ['label' => 'Profile', 'path' => corebb_public_join_base_path('/api/v1/profiles/1')],
    ];
    return $model;
}
