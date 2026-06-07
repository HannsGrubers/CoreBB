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
    return dirname(__DIR__) . '/VERSION_HISTORY.md';
}

/**
 * Usage: Create an empty version-history entry.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $version Version heading text.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_version_history_empty_entry(string $version): array
{
    return [
        'version' => $version,
        'date' => '',
        'type' => '',
        'summary' => '',
        'added' => [],
        'changes' => [],
        'removed' => [],
        'security' => [],
        'notes' => [],
        'verified' => [],
    ];
}

/**
 * Usage: Normalize a version-history section heading.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $heading Markdown section heading.
 * @return string Normalized or display-ready string.
 */
function corebb_admin_version_history_section_key(string $heading): string
{
    $heading = strtolower(trim($heading));
    $map = [
        'added' => 'added',
        'additions' => 'added',
        'changed' => 'changes',
        'changes' => 'changes',
        'removed' => 'removed',
        'security' => 'security',
        'notes' => 'notes',
        'verified' => 'verified',
        'verification' => 'verified',
    ];
    return $map[$heading] ?? '';
}

/**
 * Usage: Parse version-history markdown into sections.
 * Referenced by: admin route handlers and helper chains in this file.
 *
 * @param string $markdown Version history markdown.
 * @return array Data prepared for the admin template or caller.
 */
function corebb_admin_version_history_parse(string $markdown): array
{
    $entries = [];
    $entry = null;
    $section = '';

    foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '# CoreBB Version History')) {
            continue;
        }

        if (preg_match('/^##\s+(.+)$/', $trimmed, $m) === 1) {
            if ($entry !== null) {
                $entries[] = $entry;
            }
            $entry = corebb_admin_version_history_empty_entry(trim($m[1]));
            $section = '';
            continue;
        }

        if ($entry === null) {
            continue;
        }

        if (preg_match('/^###\s+(.+)$/', $trimmed, $m) === 1) {
            $section = corebb_admin_version_history_section_key($m[1]);
            continue;
        }

        if (preg_match('/^Date:\s*(.+)$/i', $trimmed, $m) === 1) {
            $entry['date'] = trim($m[1]);
            continue;
        }

        if (preg_match('/^Type:\s*(.+)$/i', $trimmed, $m) === 1) {
            $entry['type'] = trim($m[1]);
            continue;
        }

        if ($section !== '' && preg_match('/^-\s+(.+)$/', $trimmed, $m) === 1) {
            $entry[$section][] = trim($m[1]);
            continue;
        }

        if ($section === '') {
            $entry['summary'] = trim($entry['summary'] . ' ' . $trimmed);
        }
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
