<?php
/** Each member keeps the resolved colors for existing forum, mobile and API renderers. */
function corebb_appearance_preset_list(): array
{
    $presets = [];
    foreach (db_all('SELECT p.*, (SELECT COUNT(*) FROM users u WHERE u.appearance_preset_id=p.id) AS members
        FROM appearance_presets p ORDER BY p.name, p.id') as $row) {
        $row['values'] = corebb_vip_style_values_from_user(json_decode($row['settings'], true) ?: []);
        $row['css'] = corebb_vip_style_css_from_values($row['values']);
        $presets[(int)$row['id']] = $row;
    }
    return $presets;
}

function corebb_admin_preset_library(array $model, array $viewer, array $request, array $post): array
{
    $model['library'] = true;
    $id = filter_var($request['preset'] ?? 0, FILTER_VALIDATE_INT);
    $item = $model['presets'][$id] ?? null;
    if ($id === false || ($id && !$item)) {
        $model['errors'][] = 'That preset could not be found.';
        return $model;
    }
    $model['item'] = $item;
    $model['preset_name'] = $item['name'] ?? '';
    $model['preset_values'] = $item['values'] ?? corebb_vip_style_values_from_user([]);
    $model['token'] = corebb_user_appearance_token();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return $model; }
    $action = $post['action'] ?? '';
    if (!corebb_security_csrf_valid($post) || !corebb_user_appearance_token_ok($post)
        || !in_array($action, ['save_preset','delete_preset'], true)
        || (string)$id !== ($post['preset_id'] ?? null) || (!$id && $action === 'delete_preset')) {
        $model['errors'][] = 'Reopen the preset before saving changes.';
        return $model;
    }
    if ($action === 'save_preset') {
        $name = trim(is_string($post['preset_name'] ?? null) ? $post['preset_name'] : '');
        $model['preset_name'] = $name;
        if (!preg_match('/\A[^\p{Cc}]{1,80}\z/u', $name)) {
            $model['errors'][] = 'Enter a preset name of up to 80 characters.';
        }
        foreach (['vip_text_color','vip_bg_color','vip_border_color'] as $key) {
            $color = $post[$key] ?? null;
            if (!is_string($color) || (trim($color) !== '' && corebb_vip_style_clean_hex($color) === '')) {
                $model['errors'][] = 'Enter a three- or six-digit hex color, or leave the color blank.';
                return $model;
            }
        }
        $values = $model['preset_values'] = corebb_vip_style_values_from_post($post);
        if ($model['errors']) { return $model; }
    }
    corebb_vip_style_ensure_schema();
    corebb_admin_tool_permissions_ensure_schema();
    if (!db_begin()) { $model['errors'][] = 'Could not start the preset change.'; return $model; }
    try {
        $lock = corebb_db_connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $current = $id ? db_one('SELECT * FROM appearance_presets WHERE id=?' . $lock, [$id]) : null;
        $actorId = corebb_admin_viewer_id($viewer);
        $actor = db_one('SELECT id,username,accesslevel FROM users WHERE id=?' . $lock, [$actorId]);
        corebb_admin_user_granted_tool_keys($actorId,true);
        if (!$actor || !corebb_admin_can_access_tool($actor,'user_appearance_admin')) {
            $model['errors'][] = 'You no longer have access to appearance presets.';
            return $model;
        }
        if ($id && (!$current || (string)$current['revision'] !== ($post['preset_revision'] ?? null))) {
            $model['errors'][] = 'This preset changed. Reopen it before saving.';
            return $model;
        }
        if ($id) {
            // Assignments lock the preset first, so the set of linked accounts cannot grow during this check.
            $members = db_prepare_statement('SELECT id,accesslevel FROM users WHERE appearance_preset_id=? ORDER BY id' . $lock, [$id]);
            if (!$members) {
                $model['errors'][] = 'Could not check the assigned members.';
                return $model;
            }
            while ($member = $members->fetch(PDO::FETCH_ASSOC)) {
                if (corebb_admin_target_content_error($actor,$member) !== '') {
                    $members->closeCursor();
                    $model['errors'][] = 'This preset includes a member you cannot manage.';
                    return $model;
                }
            }
            $members->closeCursor();
        }
        if ($action === 'delete_preset') {
            $ok = db_run('DELETE FROM appearance_presets WHERE id=? AND NOT EXISTS (SELECT 1 FROM users WHERE appearance_preset_id=?)', [$id,$id])
                && corebb_db_last_affected_rows() === 1;
            if (!$ok) { $model['errors'][] = 'Unlink all members before deleting this preset.'; return $model; }
        } else {
            $settings = json_encode($values, JSON_THROW_ON_ERROR);
            if ($id) {
                $ok = db_run('UPDATE appearance_presets SET name=?,settings=?,revision=revision+1 WHERE id=?', [$name,$settings,$id]);
                if ($ok) { $ok = corebb_appearance_write_values($values, $id, true); }
            } else {
                $ok = db_run('INSERT INTO appearance_presets(name,settings) VALUES(?,?)', [$name,$settings]);
                if ($ok) { $id = db_insert_id(); }
            }
            if (!$ok) {
                $model['errors'][] = db_exists('SELECT 1 FROM appearance_presets WHERE name=? AND id<>?', [$name,$id])
                    ? 'A preset already has that name.' : 'The preset could not be saved.';
                return $model;
            }
        }
        if (!db_commit()) { $model['errors'][] = 'The preset could not be saved.'; return $model; }
    } finally {
        if (corebb_db_connection()->inTransaction()) { db_rollback(); }
    }
    corebb_adminlog_viewer($actor, ($action === 'delete_preset' ? 'Deleted' : 'Saved') . ' appearance preset #' . $id, 'user_appearance');
    $model['library_saved'] = true;
    $model['return_id'] = $action === 'delete_preset' ? 0 : $id;
    $model['messages'][] = $action === 'delete_preset' ? 'Appearance preset deleted.' : 'Appearance preset saved.';
    return $model;
}
