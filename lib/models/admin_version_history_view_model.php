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
 |  admin_version_history_view_model.php  - Admin        |
 |  version history data.                                |
 +-------------------------------------------------------+*/

/**
 * Usage: Resolve the bundled version-history markdown file.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return string Normalized or display-ready string.
 */
function corebb_admin_version_history_markdown_path(): string
{
    return dirname(__DIR__, 2) . '/VERSION_HISTORY.md';
}

/** Split releases from the bundled file, leaving note formatting to the safe Markdown renderer. */
function corebb_admin_version_history_parse(string $markdown): array
{
    $entries = [];
    $entry = null;
    $inMetadata = true;
    $fence = '';
    $metadata = ['date' => 'date', 'type' => 'type', 'update package base' => 'base'];

    foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
        if ($fence === '' && preg_match('/^##[ \t]+(.+)$/', $line, $match)) {
            if ($entry !== null) {
                $entries[] = $entry;
            }
            $entry = ['version' => trim($match[1]), 'date' => '', 'type' => '', 'base' => '', 'markdown' => ''];
            $inMetadata = true;
            continue;
        }
        if ($entry === null) {
            continue;
        }
        if ($inMetadata && preg_match('/^(Date|Type|Update package base):\s*(.+)$/i', trim($line), $match)) {
            $entry[$metadata[strtolower($match[1])]] = trim($match[2]);
            continue;
        }
        if (trim($line) !== '') {
            $inMetadata = false;
        }
        if (preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $match)) {
            if ($fence === '') {
                $fence = $match[1];
            } elseif (str_starts_with($match[1], $fence) && trim($match[2]) === '') {
                $fence = '';
            }
        }
        $entry['markdown'] .= $line . "\n";
    }
    if ($entry !== null) {
        $entries[] = $entry;
    }
    return $entries;
}

/**
 * Usage: Load parsed version-history entries for display.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_version_history_entries(): array
{
    $path = corebb_admin_version_history_markdown_path();
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $markdown = file_get_contents($path);
    if ($markdown === false) {
        return [];
    }

    return corebb_admin_version_history_parse($markdown);
}

/**
 * Usage: Build and process the version history admin page model.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param array $viewer Current admin user row.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_version_history_model(array $viewer): array
{
    return [
        'viewer' => $viewer,
        'viewer_accesslevel' => (int)($viewer['accesslevel'] ?? 0),
        'title' => 'Version History',
        'entries' => corebb_admin_version_history_entries(),
        'source_path' => corebb_admin_version_history_markdown_path(),
    ];
}
