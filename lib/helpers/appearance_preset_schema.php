<?php
require_once __DIR__ . '/db.php';

/** Provision the catalog on staged Git installs as well as through the release migration. */
function corebb_appearance_presets_prepare_schema(): bool
{
    static $ready = false;
    if ($ready) { return true; }
    if (!db_run('SELECT appearance_preset_id FROM users LIMIT 0')
        && !db_run('ALTER TABLE users ADD COLUMN appearance_preset_id INT NULL, ADD INDEX idx_appearance_preset (appearance_preset_id)')
        && !db_run('SELECT appearance_preset_id FROM users LIMIT 0')) { return false; }
    $ready = db_run('SELECT id, name, settings, revision FROM appearance_presets LIMIT 0')
        || db_run("CREATE TABLE IF NOT EXISTS appearance_presets (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE,
            settings TEXT NOT NULL, revision INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return $ready;
}
