<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/corebb_url_helpers.php';

/** Missing switches use installer defaults. A failed read must not expose a private forum. */
function corebb_forum_access_settings(): array
{
    $stmt = db_prepare_statement("SELECT name, setting FROM systemsettings WHERE name IN
        ('allowguests','customtitles','maintenancemode','upgrade_maintenance_active','maintenancesubject','maintenancemessage') ORDER BY id");
    if (!$stmt) {
        return ['allow_guests'=>false, 'member_titles'=>false, 'maintenance'=>true,
            'subject'=>'Forum unavailable', 'message'=>'Please try again later.'];
    }
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stmt->closeCursor();
    $enabled = static fn($value): bool => in_array(strtolower(trim((string)$value)), ['1','true','yes','on'], true);
    $upgrade = $enabled($settings['upgrade_maintenance_active'] ?? '0');
    return [
        'allow_guests' => $enabled($settings['allowguests'] ?? '1'),
        'member_titles' => $enabled($settings['customtitles'] ?? '1'),
        'maintenance' => $upgrade || $enabled($settings['maintenancemode'] ?? '0'),
        'subject' => $upgrade ? 'CoreBB upgrade in progress' : (trim((string)($settings['maintenancesubject'] ?? '')) ?: 'Boards offline'),
        'message' => $upgrade ? 'The forum is temporarily unavailable during a software update.'
            : (trim((string)($settings['maintenancemessage'] ?? '')) ?: 'The boards are temporarily unavailable.'),
    ];
}

function corebb_forum_maintenance_page(array $settings): never
{
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $title = $escape($settings['subject']);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>',
        $title, '</title><link rel="stylesheet" href="', $escape(corebb_public_join_base_path('/style_theme.css')),
        '"></head><body><main class="corebb-maintenance-notice"><h1>', $title, '</h1><p>', nl2br($escape($settings['message'])), '</p><p><a href="',
        $escape(corebb_public_join_base_path('/login/?view=desktop')), '">Staff sign-in</a> · <a href="',
        $escape(corebb_public_join_base_path('/admin/')), '">Admin</a></p></main></body></html>';
    exit;
}

function corebb_forum_enforce_browser_access(bool $signedIn, ?string $authAction): void
{
    $settings = corebb_forum_access_settings();
    if ($settings['maintenance']) {
        if (!in_array($authAction, ['login','login_submit','logout','google','oauth_start','oauth_callback','sso_start','sso_callback'], true)) {
            corebb_forum_maintenance_page($settings);
        }
        define('COREBB_MAINTENANCE_AUTH', true);
    } elseif (!$signedIn && !$settings['allow_guests'] && $authAction === null) {
        header('Cache-Control: no-store');
        header('Location: ' . corebb_public_join_base_path('/login/'), true, 303);
        exit;
    }
}
