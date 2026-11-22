<?php
require_once __DIR__ . '/../helpers/admin_log_helpers.php';
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

include_once __DIR__ . '/../helpers/admin_helpers.php';

/**
 * Usage: Build and process the settings admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_settings_model(array $viewer, array $request, array $post, bool $termsOnly = false): array
{
    $sections = corebb_admin_settings_sections();
    if ($termsOnly) {
        $sections = ['terms' => $sections['terms']];
        $request['section'] = 'terms';
    }
    $requested = $request['section'] ?? 'appearance';
    $validSection = is_string($requested) && isset($sections[$requested]);
    $section = $validSection ? $requested : 'appearance';
    $fields = $sections[$section]['fields'];
    $messages = [];
    $errors = [];

    if (($request['method'] ?? '') === 'post' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        // The previous all-settings form did not send a section.
        $allowed = array_key_exists('section', $request) ? $fields : corebb_admin_editable_settings();
        if (is_array($post['settings'] ?? null)) {
            // A settings page opened before the update can still submit these retired controls.
            unset($post['settings']['theme_vn_eol'], $post['settings']['encaseboards'], $post['settings']['quickreply'], $post['settings']['markupcode']);
        }
        $count = 0;
        $errors = $validSection ? corebb_admin_save_system_settings($post, $allowed, $count) : ['Unknown settings section.'];
        if (!$errors) {
            $messages[] = $count ? 'Settings saved.' : 'No settings changed.';
            if ($count) {
                corebb_adminlog_viewer($viewer, 'Modified system settings', 'edit_system_settings',
                    array_key_exists('section', $request) ? $sections[$section]['title'] : 'System settings');
            }
        }
    }

    $settings = array_intersect_key(corebb_admin_system_settings(), $fields);
    if ($errors && is_array($post['settings'] ?? null)) {
        foreach (array_intersect_key($post['settings'], $settings) as $name => $value) {
            if (!is_string($value)) { continue; }
            $type = $fields[$name][2] ?? '';
            if (($type === 'boolean' || in_array($name, corebb_admin_rate_limit_boolean_settings(), true)) && !in_array($value, ['0', '1'], true)) { continue; }
            if ($type === 'style' && !isset(corebb_public_style_builtin_options()[$value])) { continue; }
            $settings[$name]['value'] = $value;
        }
    }
    return [
        'sections' => array_map(static fn(array $group): string => $group['title'], $sections),
        'section' => $section,
        'fields' => $fields,
        'settings' => $settings,
        'messages' => $messages,
        'errors' => $errors,
        'message' => is_string($request['msg'] ?? null) ? $request['msg'] : '',
        'rate_groups' => $section === 'limits' ? corebb_admin_rate_limit_groups() : [],
        'upgrade_maintenance' => $section === 'maintenance' && corebb_admin_setting_get('upgrade_maintenance_active', '0') === '1',
        'style_options' => corebb_public_style_builtin_options(),
    ];
}
