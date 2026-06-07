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
 |  guardrails.php  - JSON API limits and boundaries.    |
 +-------------------------------------------------------+*/

require_once dirname(__DIR__) . '/rate_limit_helpers.php';
require_once dirname(__DIR__) . '/corebb_url_helpers.php';

const COREBB_API_GUEST_PAGE_MAX = 100;
const COREBB_API_AUTH_PAGE_MAX = 500;

/**
 * Build a public API URL under the active forum base path.
 *
 * Usage: expose API metadata that works for root and subdirectory installs.
 * Referenced by: corebb_api_boundary() and API error metadata.
 *
 * @param string $path API path relative to the forum root.
 * @return string Root-relative API URL.
 */
function corebb_api_public_url(string $path): string
{
    return corebb_public_join_base_path('/' . ltrim($path, '/'));
}

/**
 * Build a method-qualified public API URL.
 *
 * Usage: document POST endpoints without losing the active forum base path.
 * Referenced by: API error metadata.
 *
 * @param string $method HTTP method label.
 * @param string $path API path relative to the forum root.
 * @return string Method-prefixed API URL.
 */
function corebb_api_public_method_url(string $method, string $path): string
{
    return strtoupper(trim($method)) . ' ' . corebb_api_public_url($path);
}

/**
 * Resolve the client IP used for guest API rate limiting.
 *
 * Usage: identify unauthenticated request budgets.
 * Referenced by: corebb_api_rate_limit_identity().
 *
 * @return string Client IP string capped by the shared security helper.
 */
function corebb_api_client_ip(): string
{
    return corebb_security_client_ip();
}

/**
 * Build the current API rate-limit identity.
 *
 * Usage: rate authenticated users by account and guests by IP.
 * Referenced by: corebb_api_rate_limit_rules().
 *
 * @return string Rate-limit identity key.
 */
function corebb_api_rate_limit_identity(): string
{
    $viewer = corebb_api_viewer();
    $userId = (int)($viewer['id'] ?? 0);
    if ($userId > 0) {
        return 'api:user:' . $userId;
    }
    return 'api:ip:' . corebb_api_client_ip();
}

/**
 * Build rate-limit rules for one API resource group.
 *
 * Usage: apply cheaper health-check limits and normal read limits based on
 * authentication state.
 * Referenced by: corebb_api_apply_rate_limit().
 *
 * @param string $resource Resource name from the API route.
 * @return array<int, array<string, mixed>> Rate limit rule set.
 */
function corebb_api_rate_limit_rules(string $resource): array
{
    $loggedIn = loggedin();
    $identity = corebb_api_rate_limit_identity();
    $prefix = $loggedIn ? 'api_auth' : 'api_guest';

    // Health checks are expected to be noisy but cheap, so they get a separate
    // budget from content reads.
    if ($resource === 'health') {
        return [[
            'action' => $prefix . ':health',
            'identity' => $identity,
            'max' => $loggedIn ? 240 : 120,
            'window' => 60,
        ]];
    }

    return [
        [
            'action' => $prefix . ':read_minute',
            'identity' => $identity,
            'max' => $loggedIn ? 120 : 30,
            'window' => 60,
        ],
        [
            'action' => $prefix . ':read_hour',
            'identity' => $identity,
            'max' => $loggedIn ? 2000 : 300,
            'window' => 3600,
        ],
    ];
}

/**
 * Apply API rate limiting and send a 429 response when exceeded.
 *
 * Usage: call once near the front-controller entry before routing work begins.
 * Referenced by: API v1 front controller.
 *
 * @param string $resource Resource name from the API route.
 * @return void
 */
function corebb_api_apply_rate_limit(string $resource): void
{
    $result = corebb_rate_limit_check_rules(corebb_api_rate_limit_rules($resource));
    corebb_api_rate_limit_headers($result);
    if (empty($result['allowed'])) {
        corebb_api_error(
            'rate_limited',
            'Too many API requests. Please wait before trying again.',
            429,
            [
                'retryAfter' => (int)($result['retry_after'] ?? 60),
                'limit' => (int)($result['limit'] ?? 0),
                'window' => (int)($result['window'] ?? 0),
            ]
        );
    }
}

/**
 * Return the maximum page number allowed for the current viewer.
 *
 * Usage: keep guest list endpoints smaller than authenticated requests.
 * Referenced by: page limiting and boundary payload helpers.
 *
 * @return int Current page ceiling.
 */
function corebb_api_max_page(): int
{
    return loggedin() ? COREBB_API_AUTH_PAGE_MAX : COREBB_API_GUEST_PAGE_MAX;
}

/**
 * Read and clamp a page parameter to the current viewer's limit.
 *
 * Usage: bound list endpoints before calling shared view models.
 * Referenced by: API v1 front controller.
 *
 * @param array<string, mixed> $source Request parameter source.
 * @return int One-based page number.
 */
function corebb_api_limited_page(array $source): int
{
    return min(corebb_api_page_param($source), corebb_api_max_page());
}

/**
 * Describe API write and pagination boundaries.
 *
 * Usage: expose client-discoverable limits in CSRF/health-style responses.
 * Referenced by: API v1 auth/csrf endpoint.
 *
 * @return array<string, mixed> Boundary metadata.
 */
function corebb_api_boundary(): array
{
    $loggedIn = loggedin();
    return [
        'authenticated' => $loggedIn,
        'guestPageMax' => COREBB_API_GUEST_PAGE_MAX,
        'authPageMax' => COREBB_API_AUTH_PAGE_MAX,
        'currentPageMax' => corebb_api_max_page(),
        // Let clients discover the enabled write endpoints.
        'writeEnabled' => true,
        'writeEndpoints' => [
            'reply' => corebb_api_public_url('/api/v1/post/reply'),
            'newTopic' => corebb_api_public_url('/api/v1/post/new'),
            'editPost' => corebb_api_public_url('/api/v1/post/edit'),
            'sendPrivateMessage' => corebb_api_public_url('/api/v1/pm/send'),
            'markPrivateMessageRead' => corebb_api_public_url('/api/v1/pm/messages/{id}/read'),
            'lockTopic' => corebb_api_public_url('/api/v1/mod/topics/{id}/lock'),
            'unlockTopic' => corebb_api_public_url('/api/v1/mod/topics/{id}/unlock'),
            'removePost' => corebb_api_public_url('/api/v1/mod/posts/{id}/remove'),
            'restorePost' => corebb_api_public_url('/api/v1/mod/posts/{id}/restore'),
            'banUser' => corebb_api_public_url('/api/v1/mod/users/{id}/ban'),
            'unbanUser' => corebb_api_public_url('/api/v1/mod/users/{id}/unban'),
        ],
    ];
}

/**
 * Decode the JSON request body.
 *
 * Usage: support JSON clients on POST/PATCH-style API endpoints.
 * Referenced by: corebb_api_request_data().
 *
 * @return array<string, mixed> Decoded JSON object or empty array.
 */
function corebb_api_request_json(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Return request data from JSON or form POST.
 *
 * Usage: let API write endpoints accept either JSON clients or classic form
 * payloads.
 * Referenced by: API v1 front controller.
 *
 * @return array<string, mixed> Request data.
 */
function corebb_api_request_data(): array
{
    $json = corebb_api_request_json();
    if ($json) {
        return $json;
    }
    return $_POST;
}

/**
 * Read an API CSRF token from supported request headers.
 *
 * Usage: prefer header-based CSRF for JSON/mobile clients while preserving form
 * compatibility elsewhere.
 * Referenced by: corebb_api_csrf_valid().
 *
 * @return string Submitted CSRF token or empty string.
 */
function corebb_api_csrf_header_token(): string
{
    return (string)(
        $_SERVER['HTTP_X_COREBB_CSRF']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_SERVER['HTTP_X_CSRFTOKEN']
        ?? ''
    );
}

/**
 * Validate the CSRF token for an API write request.
 *
 * Usage: accept the API header token or legacy form-field token names.
 * Referenced by: corebb_api_require_csrf().
 *
 * @param array<string, mixed> $data Request data fallback for form tokens.
 * @return bool True when the token matches the current session token.
 */
function corebb_api_csrf_valid(array $data = []): bool
{
    $token = corebb_api_csrf_header_token();
    if ($token === '') {
        $token = (string)($data['corebb_csrf_token'] ?? $data['csrf_token'] ?? $data['wb_csrf_token'] ?? '');
    }
    return $token !== '' && hash_equals(corebb_security_csrf_token(), $token);
}

/**
 * Require a valid CSRF token or send an API error response.
 *
 * Usage: guard write endpoints before mutating forum state.
 * Referenced by: API v1 front controller write routes.
 *
 * @param array<string, mixed> $data Request data fallback for form tokens.
 * @return void
 */
function corebb_api_require_csrf(array $data = []): void
{
    if (!corebb_api_csrf_valid($data)) {
        corebb_api_error('csrf_failed', 'Missing or invalid CSRF token.', 400);
    }
}
