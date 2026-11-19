<?php
/*-------------------------------------------------------
 | oauth_provider_helpers.php - External login providers.
 |
 | CoreBB acts as an OAuth2/OpenID Connect client here. It
 | trusts external providers for identity, then still issues
 | CoreBB's normal signed browser login cookie.
 +-------------------------------------------------------*/

require_once __DIR__ . '/auth_password_helpers.php';
require_once __DIR__ . '/email_verification_helpers.php';
require_once __DIR__ . '/tos_helpers.php';
require_once __DIR__ . '/corebb_url_helpers.php';
require_once __DIR__ . '/corebb_forum_helpers.php';

/**
 * Usage: Read one authentication setting from systemsettings.
 * Referenced by: OAuth provider configuration loaders.
 */
function corebb_oauth_setting_get(string $name, string $default = ''): string
{
    return (string)db_value('SELECT setting FROM systemsettings WHERE name = ? ORDER BY id ASC LIMIT 1', [$name], $default);
}

/**
 * Usage: Read a yes/no authentication setting from systemsettings.
 * Referenced by: OAuth provider configuration loaders.
 */
function corebb_oauth_setting_enabled(string $name, bool $default = false): bool
{
    $value = strtolower(trim(corebb_oauth_setting_get($name, $default ? '1' : '0')));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Usage: Return the built-in provider templates that admins can enable.
 * Referenced by: provider loaders, admin settings, and callback processing.
 */
function corebb_oauth_provider_catalog(): array
{
    return [
        'google' => [
            'label' => 'Google',
            'type' => 'oidc',
            'uses_redirect_flow' => false,
            'requires_client_secret' => false,
            'issuer' => ['https://accounts.google.com', 'accounts.google.com'],
            'authorization_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scope' => 'openid email profile',
            'supports_hosted_domain' => true,
            'callback_route' => 'service',
            'setup_url' => 'https://console.cloud.google.com/auth/clients',
            'docs_url' => 'https://developers.google.com/identity/gsi/web/guides/get-google-api-clientid',
            'setup_steps' => [
                'Open Google Cloud Auth Platform clients.',
                'Create an OAuth client for a Web application.',
                'Add the forum origin below as an Authorized JavaScript origin.',
                'Copy the client ID into CoreBB. A client secret is not used for Google button sign-in.',
            ],
            'troubleshooting' => [
                'Google sign-in now uses Google Identity Services and posts an ID token to CoreBB; it does not use a redirect callback URL.',
                'If Google shows redirect_uri_mismatch, the page is still using an older deployed CoreBB build or a cached OAuth provider link.',
                'The Authorized JavaScript origin must match the forum scheme, hostname, and port.',
            ],
        ],
        'microsoft' => [
            'label' => 'Microsoft',
            'type' => 'oidc',
            'issuer' => [],
            'authorization_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'userinfo_url' => 'https://graph.microsoft.com/oidc/userinfo',
            'scope' => 'openid email profile',
            'supports_hosted_domain' => false,
            'callback_route' => 'service',
            'setup_url' => 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
            'docs_url' => 'https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app',
            'setup_steps' => [
                'Open Microsoft Entra App registrations.',
                'Create a new app registration.',
                'Add a Web platform redirect URI using this provider callback URL.',
                'Create a client secret under Certificates & secrets, then copy its value into CoreBB.',
            ],
            'troubleshooting' => [
                'Use the Web platform redirect URI type, not mobile, desktop, or single-page application.',
                'The scheme, hostname, path, and trailing slash must match CoreBB\'s callback URL exactly.',
            ],
        ],
        'github' => [
            'label' => 'GitHub',
            'type' => 'github',
            'icon_path' => 'images/auth/github.svg',
            'authorization_url' => 'https://github.com/login/oauth/authorize',
            'token_url' => 'https://github.com/login/oauth/access_token',
            'userinfo_url' => 'https://api.github.com/user',
            'emails_url' => 'https://api.github.com/user/emails',
            'scope' => 'read:user user:email',
            'supports_hosted_domain' => false,
            'callback_route' => 'service',
            'setup_url' => 'https://github.com/settings/applications/new',
            'docs_url' => 'https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/creating-an-oauth-app',
            'setup_steps' => [
                'Open GitHub OAuth App registration.',
                'Use your forum URL as the homepage URL.',
                'Paste this provider callback URL into Authorization callback URL.',
                'Copy the client ID and generate a client secret, then paste both into CoreBB.',
            ],
            'troubleshooting' => [
                'The Authorization callback URL must exactly match CoreBB\'s callback URL.',
                'If you changed from older CoreBB callback routes, update the GitHub OAuth app callback URL to the primary callback shown here.',
            ],
        ],
        'discord' => [
            'label' => 'Discord',
            'type' => 'discord',
            'icon_path' => 'images/auth/discord.svg',
            'authorization_url' => 'https://discord.com/oauth2/authorize',
            'token_url' => 'https://discord.com/api/oauth2/token',
            'userinfo_url' => 'https://discord.com/api/users/@me',
            'scope' => 'identify email',
            'supports_hosted_domain' => false,
            'callback_route' => 'service',
            'setup_url' => 'https://discord.com/developers/applications',
            'docs_url' => 'https://docs.discord.com/developers/topics/oauth2',
            'setup_steps' => [
                'Open the Discord Developer Portal and create an application.',
                'Open the OAuth2 settings for the application.',
                'Add this provider callback URL as a redirect.',
                'Copy the client ID and client secret into CoreBB.',
            ],
            'troubleshooting' => [
                'Add CoreBB\'s callback URL under OAuth2 redirects for the Discord application.',
                'If you changed from older CoreBB callback routes, update the Discord redirect URL to the primary callback shown here.',
            ],
        ],
    ];
}

/**
 * Usage: Read config/env fallback values without requiring systemsettings.
 * Referenced by: provider configuration resolution.
 */
function corebb_oauth_config_value(string $provider, string $field): string
{
    $provider = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $provider) ?? $provider);
    $field = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $field) ?? $field);
    $constant = 'COREBB_OAUTH_' . $provider . '_' . $field;
    if (defined($constant)) {
        return trim((string)constant($constant));
    }
    $env = trim((string)(getenv($constant) ?: ''));
    if ($env !== '') {
        return $env;
    }

    if ($provider === 'GOOGLE' && $field === 'CLIENT_ID') {
        if (defined('COREBB_GOOGLE_CLIENT_ID')) {
            return trim((string)COREBB_GOOGLE_CLIENT_ID);
        }
        $configured = trim((string)($GLOBALS['GoogleClientID'] ?? ''));
        return $configured !== '' ? $configured : trim((string)(getenv('COREBB_GOOGLE_CLIENT_ID') ?: ''));
    }
    if ($provider === 'GOOGLE' && $field === 'CLIENT_SECRET') {
        if (defined('COREBB_GOOGLE_CLIENT_SECRET')) {
            return trim((string)COREBB_GOOGLE_CLIENT_SECRET);
        }
        return trim((string)(getenv('COREBB_GOOGLE_CLIENT_SECRET') ?: ''));
    }

    return '';
}

/**
 * Usage: Decode provider settings stored as one JSON systemsetting.
 * Referenced by: provider configuration resolution.
 */
function corebb_oauth_stored_provider_settings(): array
{
    $raw = trim(corebb_oauth_setting_get('auth_oauth_providers', ''));
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Usage: Normalize one provider key for routing and storage.
 * Referenced by: provider lookup, route handlers, and admin saves.
 */
function corebb_oauth_provider_key(string $provider): string
{
    return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($provider)) ?? '');
}

/**
 * Usage: Merge built-in provider templates with stored/admin configuration.
 * Referenced by: public UI, admin UI, and OAuth callbacks.
 */
function corebb_oauth_providers(bool $includeDisabled = true): array
{
    $catalog = corebb_oauth_provider_catalog();
    $stored = corebb_oauth_stored_provider_settings();
    $providers = [];

    foreach ($catalog as $key => $template) {
        $saved = is_array($stored[$key] ?? null) ? $stored[$key] : [];
        $legacyGoogleEnabled = $key === 'google' && corebb_oauth_setting_enabled('auth_google_enabled', corebb_oauth_config_value('google', 'client_id') !== '');
        $clientId = trim((string)($saved['client_id'] ?? ''));
        $clientSecret = trim((string)($saved['client_secret'] ?? ''));

        if ($clientId === '') {
            if ($key === 'google') {
                $clientId = trim(corebb_oauth_setting_get('auth_google_client_id', ''));
            }
            if ($clientId === '') {
                $clientId = corebb_oauth_config_value($key, 'client_id');
            }
        }
        if ($clientSecret === '') {
            $clientSecret = corebb_oauth_config_value($key, 'client_secret');
        }

        $provider = $template + [
            'key' => $key,
            'enabled' => false,
            'client_id' => '',
            'client_secret' => '',
            'hosted_domain' => '',
            'allow_auto_create' => true,
            'uses_redirect_flow' => true,
            'requires_client_secret' => true,
        ];
        $provider['key'] = $key;
        $provider['enabled'] = array_key_exists('enabled', $saved) ? !empty($saved['enabled']) : $legacyGoogleEnabled;
        $provider['client_id'] = $clientId;
        $provider['client_secret'] = $clientSecret;
        $provider['hosted_domain'] = strtolower(trim((string)($saved['hosted_domain'] ?? corebb_oauth_setting_get('auth_google_hosted_domain', ''))));
        $provider['allow_auto_create'] = array_key_exists('allow_auto_create', $saved)
            ? !empty($saved['allow_auto_create'])
            : ($key === 'google' ? corebb_oauth_setting_enabled('auth_google_allow_auto_create', true) : true);
        $provider['configured'] = $provider['client_id'] !== '' && (empty($provider['requires_client_secret']) || $provider['client_secret'] !== '');
        $provider['redirect_url'] = !empty($provider['uses_redirect_flow']) ? corebb_oauth_callback_url($key) : '';
        $provider['alternate_redirect_url'] = !empty($provider['uses_redirect_flow']) ? corebb_oauth_alternate_callback_url($key) : '';
        $provider['origin_url'] = corebb_oauth_origin_url();
        $provider['start_url'] = corebb_public_join_base_path('/login/service/' . $key . '/go/');
        $provider['debug_url'] = corebb_public_join_base_path('/login/service/' . $key . '/look/');

        if ($includeDisabled || (!empty($provider['enabled']) && !empty($provider['configured']))) {
            $providers[$key] = $provider;
        }
    }

    return $providers;
}

/**
 * Usage: Return one provider or null when it is unavailable.
 * Referenced by: OAuth start and callback handlers.
 */
function corebb_oauth_provider(string $provider): ?array
{
    $key = corebb_oauth_provider_key($provider);
    $providers = corebb_oauth_providers(true);
    return is_array($providers[$key] ?? null) ? $providers[$key] : null;
}

/**
 * Usage: Build the provider buttons shown on login/register pages.
 * Referenced by: controllers/auth.php and public templates.
 */
function corebb_oauth_button_model(): array
{
    $providers = array_filter(corebb_oauth_providers(false), static function (array $provider): bool {
        return !empty($provider['uses_redirect_flow']);
    });

    return array_values(array_map(static fn(array $provider): array => [
        'key' => (string)$provider['key'],
        'label' => (string)$provider['label'],
        'icon_path' => (string)($provider['icon_path'] ?? ''),
        'start_url' => (string)$provider['start_url'],
    ], $providers));
}

/**
 * Usage: Build an absolute callback URL suitable for provider consoles.
 * Referenced by: admin settings and token exchanges.
 */
function corebb_oauth_callback_url(string $provider): string
{
    $key = corebb_oauth_provider_key($provider);
    $catalog = corebb_oauth_provider_catalog();
    $route = (string)($catalog[$key]['callback_route'] ?? 'provider');
    if ($route === 'service') {
        return corebb_oauth_absolute_url('/login/service/' . $key . '/back/');
    }
    return corebb_oauth_absolute_url('/login/' . $route . '/' . $key . '/callback/');
}

/**
 * Usage: Show admins the compatibility callback URL for provider consoles.
 * Referenced by: authentication settings diagnostics.
 */
function corebb_oauth_alternate_callback_url(string $provider): string
{
    $key = corebb_oauth_provider_key($provider);
    $primary = corebb_oauth_callback_url($key);
    if (str_contains($primary, '/login/service/')) {
        return corebb_oauth_absolute_url('/login/provider/' . $key . '/callback/');
    }
    $alternateRoute = str_contains($primary, '/login/oauth/') ? 'provider' : 'oauth';
    return corebb_oauth_absolute_url('/login/' . $alternateRoute . '/' . $key . '/callback/');
}

/**
 * Usage: Convert a local public path into an absolute URL.
 * Referenced by: redirect URI generation.
 */
function corebb_oauth_absolute_url(string $path): string
{
    if (defined('COREBB_PUBLIC_BASE_URL') && trim((string)COREBB_PUBLIC_BASE_URL) !== '') {
        return rtrim((string)COREBB_PUBLIC_BASE_URL, '/') . '/' . ltrim($path, '/');
    }
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?: 'localhost';
    return $scheme . '://' . $host . corebb_public_join_base_path($path);
}

/**
 * Usage: Build the scheme/host origin shown for browser-based provider setup.
 * Referenced by: admin authentication settings.
 */
function corebb_oauth_origin_url(): string
{
    $absolute = corebb_oauth_absolute_url('/');
    $parts = parse_url($absolute);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return rtrim($absolute, '/');
    }

    $origin = strtolower((string)$parts['scheme']) . '://' . strtolower((string)$parts['host']);
    if (!empty($parts['port'])) {
        $origin .= ':' . (int)$parts['port'];
    }
    return $origin;
}

/**
 * Usage: Create or reuse the external-identity table.
 * Referenced by: account lookup and linking helpers.
 */
function corebb_oauth_ensure_schema(): bool
{
    return db_run("CREATE TABLE IF NOT EXISTS `user_external_identities` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `provider` VARCHAR(32) NOT NULL,
        `provider_subject` VARCHAR(255) NOT NULL,
        `email` VARCHAR(255) NOT NULL DEFAULT '',
        `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
        `hosted_domain` VARCHAR(255) NOT NULL DEFAULT '',
        `display_name` VARCHAR(255) NOT NULL DEFAULT '',
        `picture_url` VARCHAR(1024) NOT NULL DEFAULT '',
        `created_at` INT UNSIGNED NOT NULL,
        `last_login_at` INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_external_identity` (`provider`, `provider_subject`),
        KEY `idx_external_identity_user` (`user_id`),
        KEY `idx_external_identity_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Usage: Encode bytes for OAuth state and PKCE values.
 * Referenced by: OAuth start URL generation.
 */
function corebb_oauth_base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * Usage: Store an OAuth state token until the provider callback returns.
 * Referenced by: corebb_oauth_start_redirect().
 */
function corebb_oauth_store_state(string $provider, string $state, string $verifier): void
{
    $_SESSION['corebb_oauth_states'][$state] = [
        'provider' => $provider,
        'code_verifier' => $verifier,
        'created_at' => time(),
    ];
}

/**
 * Usage: Consume and validate one OAuth state token.
 * Referenced by: corebb_oauth_callback_redirect().
 */
function corebb_oauth_consume_state(string $provider, string $state): ?array
{
    $states = is_array($_SESSION['corebb_oauth_states'] ?? null) ? $_SESSION['corebb_oauth_states'] : [];
    $entry = is_array($states[$state] ?? null) ? $states[$state] : null;
    unset($_SESSION['corebb_oauth_states'][$state]);
    if (!$entry || (string)($entry['provider'] ?? '') !== $provider) {
        return null;
    }
    if ((int)($entry['created_at'] ?? 0) < time() - 600) {
        return null;
    }
    return $entry;
}

/**
 * Usage: Start an external-provider login by redirecting to its auth page.
 * Referenced by: controllers/auth.php action=oauth_start.
 */
function corebb_oauth_start_redirect(string $providerKey): string
{
    $provider = corebb_oauth_provider($providerKey);
    if (!$provider || empty($provider['enabled']) || empty($provider['configured'])) {
        return '/login/?msg=' . urlencode('That sign-in provider is not configured.');
    }
    if (empty($provider['uses_redirect_flow'])) {
        return '/login/?msg=' . urlencode($provider['label'] . ' uses the Google sign-in button on this page.');
    }

    $state = corebb_oauth_base64url(random_bytes(24));
    $verifier = corebb_oauth_base64url(random_bytes(48));
    corebb_oauth_store_state((string)$provider['key'], $state, $verifier);
    $challenge = corebb_oauth_base64url(hash('sha256', $verifier, true));

    $params = [
        'client_id' => (string)$provider['client_id'],
        'redirect_uri' => corebb_oauth_callback_url((string)$provider['key']),
        'response_type' => 'code',
        'scope' => (string)$provider['scope'],
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ];
    if ((string)($provider['key'] ?? '') === 'google' && trim((string)($provider['hosted_domain'] ?? '')) !== '') {
        $params['hd'] = trim((string)$provider['hosted_domain']);
    }

    return (string)$provider['authorization_url'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Usage: Return admin-safe OAuth diagnostics without exposing client secrets.
 * Referenced by: controllers/auth.php when an admin tests provider setup.
 */
function corebb_oauth_debug_payload(string $providerKey): array
{
    $provider = corebb_oauth_provider($providerKey);
    if (!$provider) {
        return ['ok' => false, 'error' => 'Unknown provider.'];
    }

    $state = 'debug-state';
    $challenge = corebb_oauth_base64url(hash('sha256', 'debug-verifier', true));
    $params = [
        'client_id' => (string)$provider['client_id'],
        'redirect_uri' => corebb_oauth_callback_url((string)$provider['key']),
        'response_type' => 'code',
        'scope' => (string)$provider['scope'],
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ];
    if ((string)($provider['key'] ?? '') === 'google' && trim((string)($provider['hosted_domain'] ?? '')) !== '') {
        $params['hd'] = trim((string)$provider['hosted_domain']);
    }

    return [
        'ok' => true,
        'provider' => (string)$provider['label'],
        'configured' => !empty($provider['configured']),
        'client_id' => (string)$provider['client_id'],
        'redirect_uri' => (string)$params['redirect_uri'],
        'authorization_url' => (string)$provider['authorization_url'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986),
        'note' => 'For Google, redirect_uri must exactly match an Authorized redirect URI on this OAuth client.',
    ];
}

/**
 * Usage: Print a small admin-only text diagnostic for provider setup.
 * Referenced by: controllers/auth.php action=oauth_debug.
 */
function corebb_oauth_debug_response(string $providerKey): never
{
    $viewer = is_array($GLOBALS['userlogindata_a'] ?? null) ? $GLOBALS['userlogindata_a'] : [];
    if ((int)($viewer['accesslevel'] ?? 0) < 5) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Admin access required.\n";
        exit;
    }

    $payload = corebb_oauth_debug_payload($providerKey);
    header('Content-Type: text/plain; charset=UTF-8');
    foreach ($payload as $key => $value) {
        echo $key . ': ' . (is_bool($value) ? ($value ? 'yes' : 'no') : (string)$value) . "\n";
    }
    exit;
}

/**
 * Usage: Fetch a JSON response from an OAuth provider.
 * Referenced by: token, userinfo, and provider-specific profile requests.
 */
function corebb_oauth_http_json(string $method, string $url, array $headers = [], array $data = []): array
{
    $headers[] = 'Accept: application/json';
    $headers[] = 'User-Agent: CoreBB OAuth Client';
    $body = null;
    if ($data) {
        $body = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $httpOptions = [
        'method' => $method,
        'timeout' => 8,
        'ignore_errors' => true,
        'header' => implode("\r\n", $headers) . "\r\n",
    ];
    if ($body !== null) {
        $httpOptions['content'] = $body;
    }
    $context = stream_context_create(['http' => $httpOptions]);
    $raw = @file_get_contents($url, false, $context);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Provider did not return a readable JSON response.'];
    }
    if (!empty($decoded['error'])) {
        $message = (string)($decoded['error_description'] ?? $decoded['message'] ?? $decoded['error']);
        return ['ok' => false, 'error' => $message, 'body' => $decoded];
    }
    return ['ok' => true, 'body' => $decoded];
}

/**
 * Usage: Exchange an authorization code for provider tokens.
 * Referenced by: OAuth callback processing.
 */
function corebb_oauth_exchange_code(array $provider, string $code, string $verifier): array
{
    return corebb_oauth_http_json('POST', (string)$provider['token_url'], [], [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => corebb_oauth_callback_url((string)$provider['key']),
        'client_id' => (string)$provider['client_id'],
        'client_secret' => (string)$provider['client_secret'],
        'code_verifier' => $verifier,
    ]);
}

/**
 * Usage: Decode a JWT payload without using it as standalone proof.
 * Referenced by: OIDC claim validation and hosted-domain extraction.
 */
function corebb_oauth_decode_jwt_payload(string $jwt): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    $payload = strtr($parts[1], '-_', '+/');
    $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
    $json = base64_decode($payload, true);
    $claims = is_string($json) ? json_decode($json, true) : null;
    return is_array($claims) ? $claims : null;
}

/**
 * Usage: Check OIDC ID-token claims returned by the token endpoint.
 * Referenced by: OIDC callback processing.
 */
function corebb_oauth_validate_id_token(array $provider, string $idToken): array
{
    $claims = corebb_oauth_decode_jwt_payload($idToken);
    if (!$claims) {
        return ['ok' => false, 'error' => 'Provider returned an unreadable ID token.'];
    }
    $audience = $claims['aud'] ?? '';
    $audiences = is_array($audience) ? array_map('strval', $audience) : [(string)$audience];
    if (!in_array((string)$provider['client_id'], $audiences, true)) {
        return ['ok' => false, 'error' => 'Provider ID token was not issued for this forum.'];
    }
    if ((int)($claims['exp'] ?? 0) <= time()) {
        return ['ok' => false, 'error' => 'Provider ID token has expired.'];
    }
    $issuers = is_array($provider['issuer'] ?? null) ? $provider['issuer'] : [];
    if ($issuers && !in_array((string)($claims['iss'] ?? ''), $issuers, true)) {
        return ['ok' => false, 'error' => 'Provider ID token issuer is invalid.'];
    }
    return ['ok' => true, 'claims' => $claims];
}

/**
 * Usage: Load normalized identity claims from a provider access token.
 * Referenced by: OAuth callback processing.
 */
function corebb_oauth_fetch_identity(array $provider, array $tokens): array
{
    $accessToken = (string)($tokens['access_token'] ?? '');
    if ($accessToken === '') {
        return ['ok' => false, 'error' => 'Provider did not return an access token.'];
    }

    $type = (string)($provider['type'] ?? 'oidc');
    if ($type === 'github') {
        return corebb_oauth_fetch_github_identity($provider, $accessToken);
    }
    if ($type === 'discord') {
        return corebb_oauth_fetch_discord_identity($provider, $accessToken);
    }

    $idClaims = [];
    if (!empty($tokens['id_token'])) {
        $validated = corebb_oauth_validate_id_token($provider, (string)$tokens['id_token']);
        if (empty($validated['ok'])) {
            return $validated;
        }
        $idClaims = (array)$validated['claims'];
    }

    $userinfo = corebb_oauth_http_json('GET', (string)$provider['userinfo_url'], ['Authorization: Bearer ' . $accessToken]);
    if (empty($userinfo['ok'])) {
        return $userinfo;
    }
    $claims = array_merge($idClaims, (array)$userinfo['body']);
    return corebb_oauth_identity_from_claims($provider, $claims);
}

/**
 * Usage: Load GitHub profile and primary verified email.
 * Referenced by: corebb_oauth_fetch_identity().
 */
function corebb_oauth_fetch_github_identity(array $provider, string $accessToken): array
{
    $profile = corebb_oauth_http_json('GET', (string)$provider['userinfo_url'], ['Authorization: Bearer ' . $accessToken]);
    if (empty($profile['ok'])) {
        return $profile;
    }
    $emails = corebb_oauth_http_json('GET', (string)$provider['emails_url'], ['Authorization: Bearer ' . $accessToken]);
    if (empty($emails['ok'])) {
        return $emails;
    }
    $email = '';
    $verified = false;
    foreach ((array)$emails['body'] as $row) {
        if (!is_array($row) || empty($row['verified'])) {
            continue;
        }
        if (!empty($row['primary']) || $email === '') {
            $email = (string)($row['email'] ?? '');
            $verified = true;
            if (!empty($row['primary'])) {
                break;
            }
        }
    }
    $body = (array)$profile['body'];
    return corebb_oauth_identity_from_claims($provider, [
        'sub' => (string)($body['id'] ?? ''),
        'email' => $email,
        'email_verified' => $verified,
        'name' => trim((string)($body['name'] ?? '')) !== '' ? (string)$body['name'] : (string)($body['login'] ?? ''),
        'picture' => (string)($body['avatar_url'] ?? ''),
    ]);
}

/**
 * Usage: Load Discord profile and verified email.
 * Referenced by: corebb_oauth_fetch_identity().
 */
function corebb_oauth_fetch_discord_identity(array $provider, string $accessToken): array
{
    $profile = corebb_oauth_http_json('GET', (string)$provider['userinfo_url'], ['Authorization: Bearer ' . $accessToken]);
    if (empty($profile['ok'])) {
        return $profile;
    }
    $body = (array)$profile['body'];
    $display = trim((string)($body['global_name'] ?? '')) !== '' ? (string)$body['global_name'] : (string)($body['username'] ?? '');
    $avatar = '';
    if (!empty($body['id']) && !empty($body['avatar'])) {
        $avatar = 'https://cdn.discordapp.com/avatars/' . rawurlencode((string)$body['id']) . '/' . rawurlencode((string)$body['avatar']) . '.png';
    }
    return corebb_oauth_identity_from_claims($provider, [
        'sub' => (string)($body['id'] ?? ''),
        'email' => (string)($body['email'] ?? ''),
        'email_verified' => !empty($body['verified']),
        'name' => $display,
        'picture' => $avatar,
    ]);
}

/**
 * Usage: Normalize provider claims into the subset CoreBB persists.
 * Referenced by: OIDC and provider-specific profile readers.
 */
function corebb_oauth_identity_from_claims(array $provider, array $claims): array
{
    $subject = trim((string)($claims['sub'] ?? $claims['id'] ?? ''));
    $email = strtolower(trim((string)($claims['email'] ?? $claims['preferred_username'] ?? '')));
    $verified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL);
    // Microsoft commonly omits email_verified from the OIDC userinfo response.
    // The token exchange still happened against this app's client credentials,
    // so a syntactically valid returned email is treated as provider-verified.
    if (!$verified && (string)($provider['key'] ?? '') === 'microsoft' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $verified = true;
    }
    if (!$verified && in_array((string)$provider['type'], ['github', 'discord'], true)) {
        $verified = !empty($claims['email_verified']);
    }
    $identity = [
        'provider' => (string)$provider['key'],
        'provider_label' => (string)$provider['label'],
        'subject' => $subject,
        'email' => $email,
        'email_verified' => $verified ? 1 : 0,
        'hosted_domain' => strtolower(trim((string)($claims['hd'] ?? ''))),
        'display_name' => trim((string)($claims['name'] ?? $claims['login'] ?? '')),
        'picture_url' => trim((string)($claims['picture'] ?? $claims['avatar_url'] ?? '')),
    ];
    if ($identity['subject'] === '' || $identity['email'] === '' || !$identity['email_verified'] || !filter_var($identity['email'], FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => (string)$provider['label'] . ' did not provide a verified email identity.'];
    }
    if ((string)$provider['key'] === 'google' && trim((string)($provider['hosted_domain'] ?? '')) !== '') {
        if (!hash_equals(trim((string)$provider['hosted_domain']), (string)$identity['hosted_domain'])) {
            return ['ok' => false, 'error' => 'This Google account is not in the allowed hosted domain.'];
        }
    }
    return ['ok' => true, 'identity' => $identity];
}

/**
 * Usage: Find a local user linked to a provider subject.
 * Referenced by: OAuth callback processing.
 */
function corebb_oauth_user_by_subject(string $provider, string $subject): ?array
{
    if ($provider === '' || $subject === '' || !corebb_oauth_ensure_schema()) {
        return null;
    }
    $row = db_one(
        "SELECT u.*, ei.id AS external_identity_id
         FROM user_external_identities ei
         INNER JOIN users u ON u.id = ei.user_id
         WHERE ei.provider = ? AND ei.provider_subject = ?
         LIMIT 1",
        [$provider, $subject]
    );
    return is_array($row) ? $row : null;
}

/**
 * Usage: Decide whether an external verified email may auto-link an account.
 * Referenced by: safe email-based account linking.
 */
function corebb_oauth_email_is_linkable(array $identity): bool
{
    return !empty($identity['email_verified'])
        && filter_var((string)($identity['email'] ?? ''), FILTER_VALIDATE_EMAIL);
}

/**
 * Usage: Find an existing CoreBB user that may be safely linked by email.
 * Referenced by: OAuth callback processing.
 */
function corebb_oauth_user_by_safe_email(array $identity): ?array
{
    $email = (string)($identity['email'] ?? '');
    if ($email === '' || !corebb_oauth_email_is_linkable($identity)) {
        return null;
    }
    $row = db_one('SELECT * FROM users WHERE LOWER(privemail) = LOWER(?) ORDER BY id ASC LIMIT 1', [$email]);
    if (!is_array($row) || (int)($row['is_archive_user'] ?? 0) === 1 || corebb_email_verification_is_pending((int)($row['id'] ?? 0))) {
        return null;
    }
    return $row;
}

/**
 * Usage: Link one external identity to a local CoreBB user.
 * Referenced by: OAuth callback and account creation flows.
 */
function corebb_oauth_link_identity(int $userId, array $identity): bool
{
    if ($userId <= 0 || !corebb_oauth_ensure_schema()) {
        return false;
    }
    return db_run(
        "INSERT INTO user_external_identities
            (user_id, provider, provider_subject, email, email_verified, hosted_domain, display_name, picture_url, created_at, last_login_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            email = VALUES(email), email_verified = VALUES(email_verified), hosted_domain = VALUES(hosted_domain),
            display_name = VALUES(display_name), picture_url = VALUES(picture_url), last_login_at = VALUES(last_login_at)",
        [
            $userId,
            (string)$identity['provider'],
            (string)$identity['subject'],
            (string)$identity['email'],
            (int)$identity['email_verified'],
            (string)$identity['hosted_domain'],
            (string)$identity['display_name'],
            (string)$identity['picture_url'],
            time(),
            time(),
        ]
    );
}

/**
 * Usage: Mark a linked identity as recently used.
 * Referenced by: OAuth callback after a successful local login.
 */
function corebb_oauth_touch_identity(array $identity): void
{
    if (empty($identity['provider']) || empty($identity['subject']) || !corebb_oauth_ensure_schema()) {
        return;
    }
    db_run(
        "UPDATE user_external_identities
         SET email = ?, email_verified = ?, hosted_domain = ?, display_name = ?, picture_url = ?, last_login_at = ?
         WHERE provider = ? AND provider_subject = ?",
        [
            (string)$identity['email'],
            (int)$identity['email_verified'],
            (string)$identity['hosted_domain'],
            (string)$identity['display_name'],
            (string)$identity['picture_url'],
            time(),
            (string)$identity['provider'],
            (string)$identity['subject'],
        ]
    );
}

/**
 * Usage: Issue CoreBB's normal login cookie for an external-provider user.
 * Referenced by: OAuth callback and account-completion flows.
 */
function corebb_oauth_login_user(array $user): string
{
    global $CookieDomain;

    $userId = (int)($user['id'] ?? 0);
    $username = (string)($user['username'] ?? '');
    if ($userId <= 0 || $username === '') {
        return '/login/?msg=' . urlencode('Unable to load the linked CoreBB account.');
    }
    if ((int)($user['is_archive_user'] ?? 0) === 1) {
        return '/login/?msg=' . urlencode('Archived user identities cannot log in.');
    }

    $expiresAt = time() + 2592000;
    $loginToken = corebb_auth_create_login_token($userId, $expiresAt);
    $cookie = corebb_security_sign_login_cookie([
        'userid' => $userId,
        'username' => $username,
        'selector' => $loginToken['selector'],
        'token' => $loginToken['token'],
        'expiretime' => $expiresAt,
    ]);
    if (!corebb_security_set_cookie('BoardCookieV3', serialize($cookie), $expiresAt, '/', $CookieDomain ?: '')) {
        return '/login/?msg=' . urlencode('Unable to set login cookie. Please check your browser cookie settings.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['userid'] = $userId;
    $ip = str_replace('.', '-', corebb_security_client_ip());
    db_run('UPDATE users SET lastlogindate = ?, lastip = ? WHERE id = ?', [convert_to_timestamp_raw(time()), $ip, $userId]);
    return '/';
}

/**
 * Usage: Store a verified external identity until local registration completes.
 * Referenced by: OAuth callback and completion flows.
 */
function corebb_oauth_store_pending_identity(array $identity): void
{
    $_SESSION['corebb_oauth_pending'] = ['identity' => $identity, 'created_at' => time()];
}

/**
 * Usage: Read the not-yet-completed external identity from session.
 * Referenced by: account-completion model.
 */
function corebb_oauth_pending_identity(): ?array
{
    $pending = $_SESSION['corebb_oauth_pending'] ?? null;
    if (!is_array($pending) || !is_array($pending['identity'] ?? null)) {
        return null;
    }
    if ((int)($pending['created_at'] ?? 0) < time() - 900) {
        unset($_SESSION['corebb_oauth_pending']);
        return null;
    }
    return $pending['identity'];
}

/**
 * Usage: Build a username candidate from provider profile data.
 * Referenced by: account-completion model.
 */
function corebb_oauth_suggest_username(array $identity): string
{
    $seed = trim((string)($identity['display_name'] ?? ''));
    if ($seed === '') {
        $seed = preg_replace('/@.*$/', '', (string)($identity['email'] ?? '')) ?? '';
    }
    $seed = preg_replace('/[^A-Za-z0-9_\- ]+/', '', $seed) ?? '';
    $seed = preg_replace('/\s+/', ' ', trim($seed)) ?? '';
    if (strlen($seed) < 3) {
        $seed = (string)($identity['provider_label'] ?? 'Forum') . ' User';
    }
    $seed = substr($seed, 0, 20);
    $candidate = $seed;
    for ($i = 2; $i <= 99 && db_exists('SELECT id FROM users WHERE username = ? LIMIT 1', [$candidate]); $i++) {
        $suffix = (string)$i;
        $candidate = substr($seed, 0, 20 - strlen($suffix)) . $suffix;
    }
    return $candidate;
}

/**
 * Usage: Create a local CoreBB user for a verified external identity.
 * Referenced by: account-completion POST handling.
 */
function corebb_oauth_create_user(string $username, array $identity): array
{
    $email = (string)($identity['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'The provider did not provide a usable email address.'];
    }
    if (db_exists('SELECT id FROM users WHERE LOWER(privemail) = LOWER(?) LIMIT 1', [$email])) {
        return ['ok' => false, 'error' => 'A CoreBB account already uses this email address. Log in with your password first, then use this provider after the account is linked.'];
    }
    $create = corebb_create_user($username, $email, corebb_auth_random_token(32));
    if (!$create['ok']) {
        return ['ok' => false, 'error' => $create['error'] !== '' ? $create['error'] : 'Unable to create account.'];
    }
    $userId = (int)$create['id'];
    $user = $userId > 0 ? db_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) : false;
    if (!$user) {
        return ['ok' => false, 'error' => 'Account was created, but CoreBB could not load it.'];
    }
    if (!corebb_oauth_link_identity($userId, $identity)) {
        return ['ok' => false, 'error' => 'Account was created, but the external sign-in could not be linked.'];
    }
    unset($_SESSION['corebb_oauth_pending']);
    return ['ok' => true, 'user' => $user];
}

/**
 * Usage: Finish an OAuth callback once a provider identity is known.
 * Referenced by: corebb_oauth_callback_redirect().
 */
function corebb_oauth_handle_identity(array $provider, array $identity): string
{
    $user = corebb_oauth_user_by_subject((string)$identity['provider'], (string)$identity['subject']);
    if (!$user) {
        $user = corebb_oauth_user_by_safe_email($identity);
        if ($user) {
            corebb_oauth_link_identity((int)$user['id'], $identity);
        }
    }
    if ($user) {
        corebb_oauth_touch_identity($identity);
        return corebb_oauth_login_user($user);
    }
    if (empty($provider['allow_auto_create'])) {
        return '/login/?msg=' . urlencode('External sign-in is valid, but new account creation is disabled.');
    }
    corebb_oauth_store_pending_identity($identity);
    return '/login/service/done/';
}

/**
 * Usage: Process a provider callback and return the next CoreBB URL.
 * Referenced by: controllers/auth.php action=oauth_callback.
 */
function corebb_oauth_callback_redirect(string $providerKey, array $query): string
{
    $provider = corebb_oauth_provider($providerKey);
    if (!$provider || empty($provider['enabled']) || empty($provider['configured'])) {
        return '/login/?msg=' . urlencode('That sign-in provider is not configured.');
    }
    if (empty($provider['uses_redirect_flow'])) {
        return '/login/?msg=' . urlencode($provider['label'] . ' does not use this callback route.');
    }
    if (!empty($query['error'])) {
        return '/login/?msg=' . urlencode('External sign-in was cancelled or rejected.');
    }
    $code = trim((string)($query['code'] ?? ''));
    $state = trim((string)($query['state'] ?? ''));
    $stateEntry = $state !== '' ? corebb_oauth_consume_state((string)$provider['key'], $state) : null;
    if ($code === '' || !$stateEntry) {
        return '/login/?msg=' . urlencode('External sign-in security check failed. Please try again.');
    }

    $token = corebb_oauth_exchange_code($provider, $code, (string)$stateEntry['code_verifier']);
    if (empty($token['ok'])) {
        return '/login/?msg=' . urlencode('External sign-in token exchange failed: ' . (string)($token['error'] ?? 'unknown error'));
    }
    $identity = corebb_oauth_fetch_identity($provider, (array)$token['body']);
    if (empty($identity['ok'])) {
        return '/login/?msg=' . urlencode((string)($identity['error'] ?? 'External sign-in failed.'));
    }
    return corebb_oauth_handle_identity($provider, (array)$identity['identity']);
}

/**
 * Usage: Build and process the screen-name completion page for new provider users.
 * Referenced by: controllers/auth.php action=oauth_complete.
 */
function corebb_oauth_complete_model(array $post = [], string $method = 'GET'): array
{
    $identity = corebb_oauth_pending_identity();
    $tos = corebb_tos_load_text();
    $model = [
        'available' => $identity !== null,
        'errors' => [],
        'identity' => $identity ?: [],
        'provider_label' => (string)($identity['provider_label'] ?? 'External'),
        'old' => ['username' => $identity ? corebb_oauth_suggest_username($identity) : ''],
        'tos' => ['body' => $tos !== '' ? $tos : 'By registering, you agree to follow the board rules.', 'body_format' => $tos !== '' ? 'stored_html' : 'plain'],
    ];
    if (!$identity || strtoupper($method) !== 'POST') {
        return $model;
    }

    $username = trim((string)($post['username'] ?? ''));
    $model['old']['username'] = $username;
    if (trim((string)($post['website'] ?? '')) !== '') {
        $model['errors'][] = 'Registration rejected.';
    }
    if (!preg_match('/^[A-Za-z0-9_\- ]{3,20}$/', $username)) {
        $model['errors'][] = 'Screen name must be 3-20 characters and use only letters, numbers, spaces, underscores, or dashes.';
    }
    if (empty($post['agree_tos'])) {
        $model['errors'][] = 'You must agree to the Terms of Service.';
    }
    if (empty($post['confirm_age_13'])) {
        $model['errors'][] = 'You must confirm that you are at least 13 years old.';
    }
    if (!$model['errors']) {
        $created = corebb_oauth_create_user($username, $identity);
        if (!empty($created['ok']) && is_array($created['user'] ?? null)) {
            $model['redirect'] = corebb_oauth_login_user($created['user']);
            return $model;
        }
        $model['errors'][] = (string)($created['error'] ?? 'Unable to create account.');
    }
    return $model;
}
