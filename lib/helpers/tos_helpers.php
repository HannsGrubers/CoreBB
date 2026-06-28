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
 |  tos_helpers.php  - Terms of Service setting helpers. |
 +-------------------------------------------------------+*/

/**
 * Usage: Return the systemsettings key that owns the Terms of Service body.
 * Referenced by: corebb_tos_load_text() and corebb_tos_save_text().
 *
 * @return string systemsettings.name value for the editable TOS body.
 */
function corebb_tos_setting_name(): string
{
    return 'terms_of_service';
}

/**
 * Usage: Load the current Terms of Service text from systemsettings.
 * Referenced by: auth, board-rules/FAQ, and admin content view models.
 *
 * @return string Stored TOS HTML/text, or an empty string if unavailable.
 */
function corebb_tos_load_text(): string
{
    return (string)db_value(
        'SELECT setting FROM systemsettings WHERE name = ? ORDER BY id ASC LIMIT 1',
        [corebb_tos_setting_name()],
        ''
    );
}

/**
 * Usage: Persist the single active Terms of Service setting.
 * Referenced by: admin content view-model save flow.
 *
 * @param string $tos Terms of Service content to store in systemsettings.
 * @return bool True when the insert or update succeeds.
 */
function corebb_tos_save_text(string $tos): bool
{
    $name = corebb_tos_setting_name();
    $row = db_one('SELECT id FROM systemsettings WHERE name = ? ORDER BY id ASC LIMIT 1', [$name]);
    if ($row) {
        return db_run('UPDATE systemsettings SET setting = ? WHERE id = ?', [$tos, (int)$row['id']]);
    }

    return db_run('INSERT INTO systemsettings (name, setting) VALUES (?, ?)', [$name, $tos]);
}
