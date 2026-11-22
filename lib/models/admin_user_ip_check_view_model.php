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
 |  admin_user_ip_check_view_model.php  - Admin User IP  |
 |  Check.                                               |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_user_tools_view_model.php';

const COREBB_ADMIN_USER_IP_CHECK_LIMIT = 250;

require_once __DIR__ . '/admin_moderation_view_model.php';

function corebb_user_ip_check_format_date(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '0000-00-00 00:00:00' || $value === '1/1/0001 12:00:00 AM') {
        return '';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }
    return date('n/j/Y g:i:s A', $ts);
}

function corebb_address_lookup_input(array $input, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($input[$key]) && is_scalar($input[$key])) {
            $value = trim((string)$input[$key]);
            if ($value !== '') { return $value; }
        }
    }
    return '';
}

function corebb_address_lookup_access(array $viewer): array
{
    $access = [];
    foreach (['user_ip_check', 'host_lookup', 'user_pages', 'admin_notes', 'moderation_ban', 'moderation_unban'] as $key) {
        $access[$key] = corebb_admin_can_access_tool($viewer, $key);
    }
    return $access;
}

/** Keep separate evidence for account and post matches, including archived hyphenated IPv4. */
function corebb_user_ip_check_fetch_users(string $ip, array $viewer, array $access): array
{
    $ip = corebb_admin_display_ip($ip);
    $variants = [$ip];
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $variants[] = str_replace('.', '-', $ip);
    } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $variants[] = inet_ntop(inet_pton($ip));
        $variants[] = implode(':', str_split(bin2hex(inet_pton($ip)), 4));
        $variants = array_values(array_unique($variants));
    } else {
        return [];
    }
    $slots = implode(',', array_fill(0, count($variants), '?'));
    $queries = [];
    $params = [];
    if (isset(corebb_admin_table_columns('users')['lastip'])) {
        $queries[] = "SELECT id, 1 AS account_match, 0 AS post_match FROM users WHERE lastip IN ($slots)";
        $params = $variants;
    }
    $posts = corebb_admin_table_columns('posts');
    if (isset($posts['posterid'], $posts['postip'])) {
        $queries[] = "SELECT DISTINCT posterid AS id, 0 AS account_match, 1 AS post_match FROM posts WHERE postip IN ($slots) AND posterid > 0";
        array_push($params, ...$variants);
    }
    if (!$queries) { return []; }

    $fields = corebb_admin_select_columns('users', ['id','username','accesslevel','status','approved','lastip','lastlogindate','lastpstdate','lastpost','regdate'], 'u');
    $matches = implode(' UNION ALL ', $queries);
    $rows = db_all("SELECT $fields, m.account_match, m.post_match FROM users u JOIN
        (SELECT id, MAX(account_match) AS account_match, MAX(post_match) AS post_match FROM ($matches) hits GROUP BY id) m
        ON m.id = u.id ORDER BY u.username, u.id LIMIT " . COREBB_ADMIN_USER_IP_CHECK_LIMIT, $params);
    $results = [];
    foreach ($rows as $row) {
        $banned = (string)($row['status'] ?? '') === '2';
        $verb = $banned ? 'unban' : 'ban';
        $result = [
            'id'=>(int)$row['id'], 'username'=>$row['username'], 'matched_ip'=>$ip,
            'match'=>$row['account_match'] ? ($row['post_match'] ? 'Account and posts' : 'Account') : 'Posts',
            'status'=>$banned ? 'Banned' : (in_array((string)($row['approved'] ?? ''), ['', '1'], true) ? 'Active' : 'Pending'),
            'last_ip'=>corebb_admin_display_ip((string)($row['lastip'] ?? '')),
            'last_login'=>corebb_user_ip_check_format_date((string)($row['lastlogindate'] ?? '')),
            'last_post'=>corebb_user_ip_check_format_date((string)($row['lastpstdate'] ?? $row['lastpost'] ?? '')),
            'registered'=>corebb_user_ip_check_format_date((string)($row['regdate'] ?? '')),
        ];
        if ($access['moderation_' . $verb] && corebb_admin_mod_can_touch_user($viewer, $row, $verb)[0]) {
            $result['moderation_url'] = '/admin/?act=moderation&mode=' . $verb . '&' . ($banned ? 'username' : 'q') . '='
                . rawurlencode($row['username']) . '&ip=' . rawurlencode($ip);
        }
        $results[] = $result;
    }
    return $results;
}

function corebb_admin_user_ip_check_model(array $viewer, array $request, array $post): array
{
    $access = corebb_address_lookup_access($viewer);
    $model = ['access'=>$access, 'errors'=>[], 'query'=>'', 'ip'=>'', 'rows'=>[], 'selected_user'=>null, 'limit'=>COREBB_ADMIN_USER_IP_CHECK_LIMIT];
    if (corebb_admin_viewer_id($viewer) <= 0 || !$access['user_ip_check']) {
        $model['errors'][] = 'You do not have access to User IP Check.';
        return $model;
    }
    $input = $request + $post;
    $query = corebb_address_lookup_input($input, ['q']);
    $ip = corebb_address_lookup_input($input, ['ip','ip_address','ipaddress']);
    $id = corebb_address_lookup_input($input, ['userid','uid','usrid']);
    $name = corebb_address_lookup_input($input, ['username','usr','name']);
    if ($query !== '') {
        if (filter_var(corebb_admin_display_ip($query), FILTER_VALIDATE_IP)) { $ip = $query; }
        else { $name = $query; }
    }
    $model['query'] = $query !== '' ? $query : ($name !== '' ? $name : ($id !== '' ? $id : $ip));
    if ($name !== '' || $id !== '') {
        $member = ctype_digit($id) ? corebb_admin_find_user($id) : null;
        if (!$member && $name !== '') { $member = corebb_admin_find_user($name); }
        if (!$member) {
            $model['errors'][] = 'No member found for that username or ID.';
            if ($ip === '') { return $model; }
        } else {
            $model['selected_user'] = corebb_admin_user_summary($member);
            if ($ip === '') { $ip = trim((string)($member['lastip'] ?? '')); }
            if ($ip === '') {
                $model['errors'][] = 'This member has no recorded account IP.';
                return $model;
            }
        }
    }
    if ($ip === '') { return $model; }
    $ip = corebb_admin_display_ip($ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $model['errors'][] = 'Enter a valid IPv4 or IPv6 address.';
        return $model;
    }
    $model['ip'] = $ip;
    $model['rows'] = corebb_user_ip_check_fetch_users($ip, $viewer, $access);
    if (db_error() !== '') { $model['errors'][] = 'The matching accounts could not be loaded.'; }
    $target = $model['selected_user'] ? ' for user #' . $model['selected_user']['id'] : '';
    corebb_adminlog_viewer($viewer, 'User IP Check: ' . $ip . $target, 'user_ip_check');
    return $model;
}
