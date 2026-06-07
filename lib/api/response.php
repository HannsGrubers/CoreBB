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
 |  response.php  - JSON API response helpers.           |
 +-------------------------------------------------------+*/

if (!defined('COREBB_API_RESPONSE_LOADED')) {
    define('COREBB_API_RESPONSE_LOADED', true);
}

/**
 * Send a JSON API response and end the request.
 *
 * Usage: final output boundary for every API success/error response.
 * Referenced by: corebb_api_ok() and corebb_api_error().
 *
 * @param array<string, mixed> $payload Response payload.
 * @param int $status HTTP status code.
 * @return never
 */
function corebb_api_send(array $payload, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send rate limit headers for the active API budget.
 *
 * Usage: expose limit/remaining/retry metadata for clients.
 * Referenced by: API guardrails and auth login rate limiting.
 *
 * @param array<string, mixed> $limit Rate limit result.
 * @return void
 */
function corebb_api_rate_limit_headers(array $limit): void
{
    if (isset($limit['limit'])) {
        header('X-RateLimit-Limit: ' . (int)$limit['limit']);
    }
    if (array_key_exists('remaining', $limit) && $limit['remaining'] !== null) {
        header('X-RateLimit-Remaining: ' . max(0, (int)$limit['remaining']));
    }
    if (!empty($limit['retry_after'])) {
        header('Retry-After: ' . max(1, (int)$limit['retry_after']));
    }
}

/**
 * Send a successful JSON API response and end the request.
 *
 * Usage: return endpoint data with the standard ok/data envelope.
 * Referenced by: API front controller.
 *
 * @param array<string, mixed> $data Response data.
 * @param int $status HTTP status code.
 * @return never
 */
function corebb_api_ok(array $data = [], int $status = 200): never
{
    corebb_api_send(['ok' => true, 'data' => $data], $status);
}

/**
 * Send an error JSON API response and end the request.
 *
 * Usage: return endpoint failures with a stable code/message/meta envelope.
 * Referenced by: API bootstrap, guardrails, auth helpers, and front controller.
 *
 * @param string $code Machine-readable error code.
 * @param string $message User/client-facing error message.
 * @param int $status HTTP status code.
 * @param array<string, mixed> $meta Optional structured error metadata.
 * @return never
 */
function corebb_api_error(string $code, string $message, int $status = 400, array $meta = []): never
{
    $payload = [
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ];
    if ($meta) {
        $payload['error']['meta'] = $meta;
    }
    corebb_api_send($payload, $status);
}

/**
 * Read and clamp an integer request parameter.
 *
 * Usage: parse route/query/post ids and pagination numbers safely.
 * Referenced by: page helper and API front controller routes.
 *
 * @param array<string, mixed> $source Parameter source.
 * @param string $key Parameter name.
 * @param int $default Value used when the key is absent.
 * @param int $min Minimum allowed value.
 * @param int $max Maximum allowed value.
 * @return int Clamped integer value.
 */
function corebb_api_int_param(array $source, string $key, int $default = 0, int $min = 0, int $max = PHP_INT_MAX): int
{
    $value = isset($source[$key]) ? (int)$source[$key] : $default;
    return min($max, max($min, $value));
}

/**
 * Read the API page parameter.
 *
 * Usage: accept either page or p while enforcing the generic page ceiling.
 * Referenced by: API guardrails and front controller list endpoints.
 *
 * @param array<string, mixed> $source Parameter source.
 * @return int One-based page number capped at 500.
 */
function corebb_api_page_param(array $source): int
{
    return corebb_api_int_param($source, 'page', corebb_api_int_param($source, 'p', 1, 1), 1, 500);
}
