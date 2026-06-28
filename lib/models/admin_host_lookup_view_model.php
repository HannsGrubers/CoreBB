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

const COREBB_ADMIN_HOST_LOOKUP_USER_LIMIT = 250;

/**
 * Usage: Normalize host/IP lookup input.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $value Raw value to normalize.
 * @return string Normalized or display-ready string.
 */
function corebb_host_lookup_clean(string $value): string
{
    $value = trim($value);
    $value = str_replace(["\r", "\n", "\t"], '', $value);
    return substr($value, 0, 255);
}

/**
 * Usage: Validate a hostname before forward lookup.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $host Host name.
 * @return bool True when the check passes or mutation succeeds.
 */
function corebb_host_lookup_valid_host(string $host): bool
{
    if ($host === '' || strlen($host) > 253) {
        return false;
    }
    return (bool)preg_match('/^[A-Za-z0-9._:-]+$/', $host);
}

/**
 * Usage: Resolve an IP address to a host name.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $ip IP address.
 * @return string Normalized or display-ready string.
 */
function corebb_host_lookup_reverse(string $ip): string
{
    $ip = corebb_host_lookup_clean($ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return '';
    }

    $host = @gethostbyaddr($ip);
    $host = is_string($host) ? trim($host) : '';
    if ($host === '' || $host === $ip) {
        return 'Unknown';
    }
    return $host;
}

/**
 * Usage: Resolve a host name to IP addresses.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $host Host name.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_host_lookup_forward(string $host): array
{
    $host = corebb_host_lookup_clean($host);
    if ($host === '' || !corebb_host_lookup_valid_host($host)) {
        return [];
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return [$host];
    }

    $ips = @gethostbynamel($host);
    if (!is_array($ips)) {
        return [];
    }

    $out = [];
    foreach ($ips as $ip) {
        $ip = trim((string)$ip);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $out[$ip] = $ip;
        }
    }
    return array_values($out);
}

/**
 * Usage: Find users associated with lookup IP addresses.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $ips IP address list.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_host_lookup_users_for_ips(array $ips): array
{
    $out = [];
    foreach ($ips as $ip) {
        $ip = trim((string)$ip);
        if ($ip === '') {
            continue;
        }
        foreach (corebb_user_ip_check_fetch_users($ip) as $row) {
            $key = (int)($row['id'] ?? 0) . '|' . $ip;
            $row['lookup_ip'] = $ip;
            $out[$key] = $row;
        }
    }
    return array_values($out);
}

/**
 * Usage: Build and process the host lookup admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @param array $request Query/request values from admin.php.
 * @param array $post Posted form data from admin.php.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_host_lookup_model(array $viewer, array $request, array $post): array
{
    $model = corebb_admin_require_model_base($viewer, 'Host Address Lookup', $request);
    $ip = corebb_host_lookup_clean((string)(
        $request['ip_address']
        ?? $request['ip']
        ?? $request['ipaddress']
        ?? $post['ip_address']
        ?? $post['ip']
        ?? ''
    ));
    $host = corebb_host_lookup_clean((string)(
        $request['host_address']
        ?? $request['host']
        ?? $request['hostname']
        ?? $post['host_address']
        ?? $post['host']
        ?? ''
    ));

    $model['search'] = ['ip_address' => $ip, 'host_address' => $host];
    $model['lookups'] = [];
    $model['rows'] = [];
    $model['result_limit'] = COREBB_ADMIN_HOST_LOOKUP_USER_LIMIT;

    $ips = [];

    if ($ip !== '') {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $model['errors'][] = 'The IP address is not valid.';
        } else {
            $reverse = corebb_host_lookup_reverse($ip);
            $model['lookups'][] = [
                'ip_address' => $ip,
                'host_address' => $reverse,
                'source' => 'reverse',
            ];
            $ips[$ip] = $ip;
        }
    }

    if ($host !== '') {
        if (!corebb_host_lookup_valid_host($host)) {
            $model['errors'][] = 'The host address contains invalid characters.';
        } else {
            $forwardIps = corebb_host_lookup_forward($host);
            if (!$forwardIps) {
                $model['lookups'][] = [
                    'ip_address' => 'Unknown',
                    'host_address' => $host,
                    'source' => 'forward',
                ];
            } else {
                foreach ($forwardIps as $forwardIp) {
                    $ips[$forwardIp] = $forwardIp;
                    $model['lookups'][] = [
                        'ip_address' => $forwardIp,
                        'host_address' => $host,
                        'source' => 'forward',
                    ];
                }
            }
        }
    }

    if ($ips) {
        $model['rows'] = corebb_host_lookup_users_for_ips(array_values($ips));
    }

    if (($ip !== '' || $host !== '') && empty($model['errors']) && (int)($viewer['accesslevel'] ?? 0) >= 3 ) {
        $parts = [];
        if ($ip !== '') { $parts[] = 'IP ' . $ip; }
        if ($host !== '') { $parts[] = 'host ' . $host; }
        corebb_adminlog_viewer($viewer, 'Host Address Lookup: ' . implode(', ', $parts), 'host_lookup');
    }

    return $model;
}
