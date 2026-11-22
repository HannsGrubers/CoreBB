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
 |  admin_host_lookup_view_model.php  - Admin Host       |
 |  Address Lookup.                                      |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/admin_user_tools_view_model.php';
require_once __DIR__ . '/admin_user_ip_check_view_model.php';

function corebb_admin_host_lookup_model(array $viewer, array $request, array $post): array
{
    $access = corebb_address_lookup_access($viewer);
    $model = ['access'=>$access, 'query'=>'', 'lookups'=>[], 'rows'=>[], 'errors'=>[],
        'limit'=>COREBB_ADMIN_USER_IP_CHECK_LIMIT, 'searched_ips'=>false, 'limited'=>false];
    if (corebb_admin_viewer_id($viewer) <= 0 || !$access['host_lookup']) {
        $model['errors'][] = 'You do not have access to Host Address Lookup.';
        return $model;
    }
    $input = $request + $post;
    $ip = corebb_address_lookup_input($input, ['ip_address','ip','ipaddress']);
    $host = corebb_address_lookup_input($input, ['host_address','host','hostname']);
    $query = corebb_address_lookup_input($input, ['q']);
    if ($query !== '') {
        $normalized = corebb_admin_display_ip($query);
        if (filter_var($normalized, FILTER_VALIDATE_IP) || str_contains($query, ':') || preg_match('/^[0-9.]+$/D', $query)) {
            $ip = $normalized;
        } else {
            $host = $query;
        }
    }
    $model['query'] = $query !== '' ? $query : ($ip !== '' ? $ip : $host);
    $ips = [];
    $audited = [];
    if ($ip !== '') {
        $ip = corebb_admin_display_ip($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $audited[] = 'IP ' . $ip;
            $answer = @gethostbyaddr($ip);
            $model['lookups'][] = ['source'=>'Reverse lookup', 'host'=>($answer && $answer !== $ip) ? $answer : '', 'ip'=>$ip];
            $ips[$ip] = $ip;
        } else {
            $model['errors'][] = 'Enter a valid IPv4 or IPv6 address.';
        }
    }
    if ($host !== '') {
        $literal = filter_var($host, FILTER_VALIDATE_IP);
        if (!$literal && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $model['errors'][] = 'Enter a hostname without a protocol, port, or path.';
        } else {
            $audited[] = 'host ' . $host;
            $answers = $literal ? [$host] : (@gethostbynamel($host) ?: []);
            foreach (array_unique($answers) as $answer) {
                $model['lookups'][] = ['source'=>'Forward lookup','host'=>$host,'ip'=>$answer];
                $ips[$answer] = $answer;
            }
            if (!$answers) { $model['lookups'][] = ['source'=>'Forward lookup','host'=>$host,'ip'=>'']; }
        }
    }
    $model['searched_ips'] = (bool)$ips;
    foreach ($ips as $answer) {
        $matches = corebb_user_ip_check_fetch_users($answer, $viewer, $access);
        if (db_error() !== '') {
            $model['errors'][] = 'The matching accounts could not be loaded.';
            break;
        }
        if (count($matches) >= $model['limit']) { $model['limited'] = true; }
        array_push($model['rows'], ...$matches);
    }
    if ($audited) {
        corebb_adminlog_viewer($viewer, 'Host Address Lookup: ' . implode(', ', $audited), 'host_lookup');
    }
    return $model;
}
