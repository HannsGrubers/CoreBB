<?php
require_once __DIR__ . '/../helpers/admin_log_helpers.php';
/*-------------------------------------------------------
 | admin_auth_settings_view_model.php - Auth settings.
 +-------------------------------------------------------*/

require_once __DIR__ . '/../helpers/admin_helpers.php';
require_once __DIR__ . '/../helpers/oauth_provider_helpers.php';
require_once __DIR__ . '/../helpers/google_auth_helpers.php';

/**
 * Usage: Validate normalized authentication settings.
 * Referenced by: corebb_admin_auth_settings_save().
 *
 * @param array<string, array<string, mixed>> $values Normalized provider values.
 * @return array<int, string> User-facing validation errors.
 */
function corebb_admin_auth_settings_validate(array $values): array
{
    $errors = [];
    $catalog = corebb_oauth_provider_catalog();
    foreach ($values as $key => $provider) {
        $label = $catalog[$key]['label'];
        if ($provider['hosted_domain'] !== '' && (!filter_var($provider['hosted_domain'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || !str_contains($provider['hosted_domain'], '.') || str_ends_with($provider['hosted_domain'], '.'))) {
            $errors[] = $label . ' hosted domain must be a valid domain name, such as example.com.';
        }
        if (!$provider['enabled']) { continue; }
        $clientId = trim((string)($provider['client_id'] ?? ''));
        $clientSecret = trim((string)($provider['client_secret'] ?? ''));
        if ($clientId === '') {
            $clientId = $key === 'google' ? corebb_google_config_client_id() : corebb_oauth_config_value($key, 'client_id');
        }
        if ($clientSecret === '') {
            $clientSecret = corebb_oauth_config_value($key, 'client_secret');
        }
        $requiresSecret = !array_key_exists('requires_client_secret', $catalog[$key] ?? [])
            || !empty($catalog[$key]['requires_client_secret']);
        if ($clientId === '') {
            $errors[] = 'Enter a client ID before enabling ' . $label . '.';
        }
        if ($requiresSecret && $clientSecret === '') {
            $errors[] = 'Enter a client secret before enabling ' . $label . '.';
        }
    }
    return $errors;
}

/**
 * Usage: Save authentication settings from the admin panel.
 * Referenced by: corebb_admin_auth_settings_model().
 *
 * @param array<string, mixed> $post Raw POST payload.
 * @return array{messages: array<int, string>, errors: array<int, string>}
 */
function corebb_admin_auth_settings_save(array $post, ?string $selected = null): array
{
    $rows = $post['authp'] ?? $post['providers'] ?? null;
    $catalog = corebb_oauth_provider_catalog();
    $expected = $selected === null ? $catalog : array_intersect_key($catalog, [$selected => true]);
    if (!is_array($rows) || !$expected || array_diff_key($rows, $expected) || array_diff_key($expected, $rows)) {
        return ['messages'=>[], 'errors'=>['The submitted providers do not match this form. Reload and try again.']];
    }
    foreach ($rows as $key => $row) {
        if (!is_array($row) || !is_string($row['cid'] ?? $row['client_id'] ?? null)
            || count(array_filter($row, 'is_scalar')) !== count($row)
            || (!empty($catalog[$key]['supports_hosted_domain']) && !is_string($row['hd'] ?? $row['hosted_domain'] ?? null))) {
            return ['messages'=>[], 'errors'=>['Incomplete provider settings. Reload and try again.']];
        }
    }

    // Providers share a JSON row. Serialize its read/merge/write, including first-time setup
    // on older installations, using the existing reserved default-style row as an anchor.
    $failure = ['messages'=>[], 'errors'=>['Could not save authentication settings. Reload the page and try again.']];
    if (!db_begin()) { return $failure; }
    try {
        $lock = corebb_db_connection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = db_prepare_statement("SELECT id,name,setting FROM systemsettings WHERE id = 1 OR name = ? ORDER BY id" . $lock, ['auth_oauth_providers']);
        if (!$stmt) { return $failure; }
        $stored = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $setting) {
            if ($setting['name'] === 'auth_oauth_providers') {
                $raw = trim((string)$setting['setting']);
                $stored = $raw === '' ? [] : json_decode($raw, true);
                if (!is_array($stored)) { return $failure; }
                break;
            }
        }
        $changed = [];
        foreach ($rows as $key => $row) {
            $existing = $stored[$key] ?? [];
            if (!is_array($existing)) { return $failure; }
            $secret = trim((string)($row['ckey'] ?? $row['client_secret'] ?? ''));
            $changed[$key] = array_replace($existing, [
                'enabled' => !empty($row['en']) || !empty($row['enabled']),
                'client_id' => trim((string)($row['cid'] ?? $row['client_id'])),
                'client_secret' => $secret !== '' && ($catalog[$key]['requires_client_secret'] ?? true)
                    ? $secret : ($existing['client_secret'] ?? ''),
                'hosted_domain' => !empty($catalog[$key]['supports_hosted_domain'])
                    ? strtolower(trim((string)($row['hd'] ?? $row['hosted_domain']))) : '',
                'allow_auto_create' => $key === 'microsoft' ? false : (!empty($row['ac']) || !empty($row['allow_auto_create'])),
            ]);
        }
        $errors = corebb_admin_auth_settings_validate($changed);
        if ($errors) { return ['messages'=>[], 'errors'=>$errors]; }
        $json = json_encode(array_replace($stored, $changed), JSON_UNESCAPED_SLASHES);
        if ($json === false) { return $failure; }
        $writes = ['auth_oauth_providers' => $json];
        if (isset($changed['google'])) {
            $google = $changed['google'];
            $writes += [
                'auth_google_enabled' => $google['enabled'] ? '1' : '0',
                'auth_google_client_id' => $google['client_id'],
                'auth_google_hosted_domain' => $google['hosted_domain'],
                'auth_google_allow_auto_create' => $google['allow_auto_create'] ? '1' : '0',
            ];
        }
        foreach ($writes as $name => $value) {
            if (!corebb_admin_setting_upsert($name, $value) || corebb_oauth_setting_get($name, "\0") !== $value) {
                return $failure;
            }
        }
        if (!db_commit()) { return $failure; }
    } finally {
        if (corebb_db_connection()->inTransaction()) { db_rollback(); }
    }
    $label = $selected === null ? 'Authentication' : $catalog[$selected]['label'];
    return ['messages'=>[$label . ' settings saved.'], 'errors'=>[]];
}

/**
 * Usage: Build the admin authentication settings form model.
 * Referenced by: admin route act=auth_settings.
 *
 * @param array<string, mixed> $viewer Current admin user row.
 * @param array<string, mixed> $request Query/request values.
 * @param array<string, mixed> $post Posted form data.
 * @return array<string, mixed> Template model.
 */
function corebb_admin_auth_settings_model(array $viewer, array $request, array $post): array
{
    $messages = [];
    $errors = [];

    $catalog = corebb_oauth_provider_catalog();
    $requested = $request['provider'] ?? 'google';
    $valid = is_string($requested) && isset($catalog[$requested]);
    $selected = $valid ? $requested : 'google';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($post['action'] ?? '') === 'save_auth') {
        $result = $valid ? corebb_admin_auth_settings_save($post, array_key_exists('provider', $request) ? $selected : null)
            : ['messages'=>[], 'errors'=>['Unknown sign-in provider.']];
        $messages = $result['messages'];
        $errors = $result['errors'];
        if (!$errors) {
            corebb_adminlog_viewer($viewer, 'Modified authentication settings', 'edit_auth_settings', array_key_exists('provider', $request) ? $catalog[$selected]['label'] : 'All providers');
        }
    }

    $providers = corebb_oauth_providers(true);
    $stored = corebb_oauth_stored_provider_settings();
    $enabledCount = 0;
    $adminProviders = [];
    foreach ($providers as $provider) {
        $key = (string)$provider['key'];
        $saved = is_array($stored[$key] ?? null) ? $stored[$key] : [];
        $savedClientId = trim((string)($saved['client_id'] ?? ''));
        $configClientId = corebb_oauth_config_value($key, 'client_id');
        if ($key === 'google') {
            // The Google button and token handler use the Identity Services settings.
            $savedClientId = corebb_google_system_client_id();
            $configClientId = corebb_google_config_client_id();
            $provider['configured'] = $savedClientId !== '' || $configClientId !== '';
            $provider['enabled'] = corebb_auth_setting_enabled('auth_google_enabled', $provider['configured']);
            $provider['hosted_domain'] = corebb_google_hosted_domain();
            $provider['allow_auto_create'] = corebb_google_allow_auto_create();
        }
        if ($provider['enabled'] && $provider['configured']) { $enabledCount++; }
        unset($provider['client_secret'], $provider['client_id']);
        $adminProviders[$key] = $provider + [
            'saved_client_id' => $savedClientId,
            'client_id_source' => $savedClientId !== '' ? 'saved on this forum' : ($configClientId !== '' ? 'private configuration or environment' : 'not configured'),
            'secret_source' => trim((string)($saved['client_secret'] ?? '')) !== '' ? 'saved on this forum' : (corebb_oauth_config_value($key, 'client_secret') !== '' ? 'private configuration or environment' : 'not configured'),
        ];
    }

    $provider = $adminProviders[$selected];
    $draft = [
        'enabled' => $provider['enabled'], 'allow_auto_create' => $provider['allow_auto_create'],
        'client_id' => $provider['saved_client_id'], 'hosted_domain' => $provider['hosted_domain'],
    ];
    $submitted = $post['authp'][$selected] ?? $post['providers'][$selected] ?? null;
    if ($errors && $valid && is_array($submitted)) {
        $draft['enabled'] = !empty($submitted['en']) || !empty($submitted['enabled']);
        $draft['allow_auto_create'] = !empty($submitted['ac']) || !empty($submitted['allow_auto_create']);
        foreach (['cid'=>'client_id', 'hd'=>'hosted_domain'] as $short => $field) {
            $value = $submitted[$short] ?? $submitted[$field] ?? null;
            if (is_string($value)) { $draft[$field] = $value; }
        }
        if (!empty($submitted['ckey']) || !empty($submitted['client_secret'])) {
            $errors[] = 'Re-enter the new client secret before saving again.';
        }
    }
    return [
        'selected' => $selected,
        'draft' => $draft,
        'can_debug' => (int)($viewer['accesslevel'] ?? 0) >= 5,
        'messages' => $messages,
        'errors' => $errors,
        'providers' => $adminProviders,
        'effective' => [
            'enabled_count' => $enabledCount,
            'provider_count' => count($providers),
        ],
    ];
}
