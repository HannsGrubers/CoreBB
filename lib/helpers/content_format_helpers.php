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
 |  content_format_helpers.php - Late content rendering  |
 |  models for Twig views.                              |
 +-------------------------------------------------------+*/

require_once __DIR__ . '/corebb_url_helpers.php';
require_once __DIR__ . '/corebb_markup_helpers.php';
require_once __DIR__ . '/pm_helpers.php';
require_once __DIR__ . '/user_display_helpers.php';

/**
 * Usage: Create a typed content payload for Twig's final formatting pipe.
 * Referenced by: the specific content model helpers in this file.
 *
 * @param string $type Formatting pipeline type.
 * @param string $body Raw stored text/body value.
 * @param array<string, mixed> $options Formatting options for the selected type.
 * @return array{type: string, body: string, options: array<string, mixed>} Content payload for formatted_content_html().
 */
function corebb_content_model(string $type, string $body, array $options = []): array
{
    return [
        'type' => $type,
        'body' => $body,
        'options' => $options,
    ];
}

/**
 * Usage: Wrap a forum post body for late BBCode/markup rendering.
 * Referenced by: Twig through post_body_model().
 *
 * @param string $body Stored post body.
 * @param int $authorAccessLevel Author access level used by markup permissions.
 * @return array<string, mixed> Content payload for formatted_content_html().
 */
function corebb_post_body_model(string $body, int $authorAccessLevel = 0): array
{
    return corebb_content_model('post_body', $body, ['author_access_level' => $authorAccessLevel]);
}

/**
 * Usage: Wrap a private-message body for late PM markup rendering.
 * Referenced by: Twig and API serializers through pm_body_model().
 *
 * @param string $body Stored private-message body.
 * @return array<string, mixed> Content payload for formatted_content_html().
 */
function corebb_pm_body_model(string $body): array
{
    return corebb_content_model('pm_body', $body);
}

/**
 * Usage: Wrap admin note/user-audit text for late BBCode rendering without image tags.
 * Referenced by: admin user notes and user portal Twig templates.
 *
 * @param string $body Stored note, signature, or audit message body.
 * @return array<string, mixed> Content payload for formatted_content_html().
 */
function corebb_admin_note_body_model(string $body): array
{
    return corebb_content_model('admin_note_body', $body);
}

/**
 * Usage: Wrap a profile bio for late profile-bio markup rendering.
 * Referenced by: Twig and API serializers through profile_bio_model().
 *
 * @param string $bio Stored profile bio.
 * @return array<string, mixed> Content payload for formatted_content_html().
 */
function corebb_profile_bio_model(string $bio): array
{
    return corebb_content_model('profile_bio', $bio);
}

/**
 * Usage: Wrap stored static/TOS page bodies with their configured format.
 * Referenced by: Twig through stored_page_body_model().
 *
 * @param array<string, mixed> $model Page model containing body and body_format.
 * @return array<string, mixed> Content payload for formatted_content_html().
 */
function corebb_stored_page_body_model(array $model): array
{
    return corebb_content_model('stored_page', (string)($model['body'] ?? ''), [
        'format' => (string)($model['body_format'] ?? 'plain'),
    ]);
}

/**
 * Usage: Wrap a public profile field with its display format.
 * Referenced by: Twig through profile_field_model().
 *
 * @param array<string, mixed> $field Profile field row with value and format.
 * @return array<string, mixed> Content payload for formatted_content_html().
 */
function corebb_profile_field_model(array $field): array
{
    return corebb_content_model('profile_field', (string)($field['value'] ?? ''), [
        'format' => (string)($field['format'] ?? 'plain'),
    ]);
}

/**
 * Usage: Wrap search result text with a highlight needle.
 * Referenced by: Twig through search_highlight_model().
 *
 * @param string $text Search result text.
 * @param string $needle Search term to highlight.
 * @return array<string, mixed> Content payload for formatted_content_html().
 */
function corebb_search_highlight_model(string $text, string $needle): array
{
    return corebb_content_model('search_highlight', $text, ['needle' => $needle]);
}

/**
 * Usage: Highlight the search term inside already-selected result text.
 * Referenced by: formatted content rendering and search result view models.
 *
 * @param string $text Result text to display.
 * @param string $needle Search term to highlight.
 * @return string Escaped HTML with highlight spans around matches.
 */
function corebb_search_highlight_html(string $text, string $needle): string
{
    if ($text === '') {
        return '';
    }
    $needle = trim($needle);
    if ($needle === '') {
        return corebb_h($text);
    }

    $pattern = '/(' . preg_quote($needle, '/') . ')/iu';
    $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts) || count($parts) <= 1) {
        return corebb_h($text);
    }

    $html = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        if (preg_match($pattern, $part) === 1) {
            $html .= '<span style="background:#ffff66; color:#000000; font-weight:bold;">' . corebb_h($part) . '</span>';
        } else {
            $html .= corebb_h($part);
        }
    }
    return $html;
}

/**
 * Usage: Provide the post-editor face palette in display rows.
 * Referenced by: Twig through post_faces_model().
 *
 * @return array{rows: array<int, array<int, array{name: string, file: string}>>} Face palette grouped for templates.
 */
function corebb_post_faces_model(): array
{
    $faces = [
        ['name' => 'thinking', 'file' => '33.gif'],
        ['name' => 'monkey', 'file' => '38.gif'],
        ['name' => 'tired', 'file' => '31.gif'],
        ['name' => 'confused', 'file' => '6.gif'],
        ['name' => 'hugs', 'file' => '60.gif'],
        ['name' => 'not_talking', 'file' => '28.gif'],
        ['name' => 'alien_1', 'file' => '47.gif'],
        ['name' => 'nerd', 'file' => '22.gif'],
        ['name' => 'alien_2', 'file' => '48.gif'],
        ['name' => 'money_eyes', 'file' => '53.gif'],
        ['name' => 'beatup', 'file' => '56.gif'],
        ['name' => 'shock', 'file' => '11.gif'],
        ['name' => 'cry', 'file' => '17.gif'],
        ['name' => 'dancing', 'file' => '59.gif'],
        ['name' => 'plain', 'file' => '19.gif'],
        ['name' => 'kiss', 'file' => '10.gif'],
        ['name' => 'blush', 'file' => '8.gif'],
        ['name' => 'idea', 'file' => '45.gif'],
        ['name' => 'angel', 'file' => '21.gif'],
        ['name' => 'praying', 'file' => '51.gif'],
        ['name' => 'angry', 'file' => '12.gif'],
        ['name' => 'liarliar', 'file' => '55.gif'],
        ['name' => 'worried', 'file' => '15.gif'],
        ['name' => 'rolling_eyes', 'file' => '25.gif'],
        ['name' => 'skull', 'file' => '46.gif'],
        ['name' => 'frustrated', 'file' => '49.gif'],
        ['name' => 'raised_brow', 'file' => '20.gif'],
        ['name' => 'love', 'file' => '7.gif'],
        ['name' => 'shame_on_you', 'file' => '58.gif'],
        ['name' => 'coffee', 'file' => '44.gif'],
        ['name' => 'sick', 'file' => '26.gif'],
        ['name' => 'silly', 'file' => '30.gif'],
        ['name' => 'talk_hand', 'file' => '23.gif'],
        ['name' => 'drooling', 'file' => '32.gif'],
        ['name' => 'rose', 'file' => '40.gif'],
        ['name' => 'tongue', 'file' => '9.gif'],
        ['name' => 'grin', 'file' => '4.gif'],
        ['name' => 'sad', 'file' => '2.gif'],
        ['name' => 'doh!', 'file' => '34.gif'],
        ['name' => 'wink', 'file' => '3.gif'],
        ['name' => 'mischief', 'file' => '13.gif'],
        ['name' => 'chicken', 'file' => '39.gif'],
        ['name' => 'applause', 'file' => '35.gif'],
        ['name' => 'clown', 'file' => '29.gif'],
        ['name' => 'batting', 'file' => '5.gif'],
        ['name' => 'laugh', 'file' => '18.gif'],
        ['name' => 'devil', 'file' => '16.gif'],
        ['name' => 'cowboy', 'file' => '50.gif'],
        ['name' => 'pig', 'file' => '36.gif'],
        ['name' => 'whistling', 'file' => '54.gif'],
        ['name' => 'pumpkin', 'file' => '43.gif'],
        ['name' => 'flag', 'file' => '42.gif'],
        ['name' => 'cool', 'file' => '14.gif'],
        ['name' => 'cow', 'file' => '37.gif'],
        ['name' => 'hypnotized', 'file' => '52.gif'],
        ['name' => 'happy', 'file' => '1.gif'],
        ['name' => 'shhh', 'file' => '27.gif'],
        ['name' => 'peace', 'file' => '57.gif'],
        ['name' => 'good_luck', 'file' => '41.gif'],
        ['name' => 'sleep', 'file' => '24.gif'],
    ];

    return ['rows' => array_chunk($faces, 7)];
}

/**
 * Usage: Render a typed content payload at the final Twig HTML boundary.
 * Referenced by: Twig formatted_content_html() and API serializers that expose legacy HTML.
 *
 * @param array<string, mixed> $content Content payload created by the model helpers in this file.
 * @return string Final trusted HTML for the requested content type.
 */
function corebb_formatted_content_html(array $content): string
{
    $type = (string)($content['type'] ?? 'plain');
    $body = (string)($content['body'] ?? '');
    $options = is_array($content['options'] ?? null) ? $content['options'] : [];

    if ($type === 'post_body') {
        return corebb_markup_post($body, (int)($options['author_access_level'] ?? 0));
    }

    if ($type === 'pm_body') {
        return corebb_pm_markup($body);
    }

    if ($type === 'admin_note_body') {
        $permissions = 'B-I-Q-U-O-BQ-S-HR-UL-OL-LI-ST-SP-BL-CT-F-LL-FC-FG-FH-FB-FD-FL-FT-FBB-FS-CB';
        return nl2br(corebb_render_markup($body, $permissions));
    }

    if ($type === 'profile_bio') {
        $body = trim($body);
        if ($body === '') {
            return '&nbsp;';
        }
        $permissions = 'B-I-Q-U-O-BQ-S-HR-UL-OL-LI-ST-SP-BL-CT-F-LL-FC-FG-FH-FB-FD-FL-FT-FBB-FS-CB-MD-IMG';
        return nl2br(corebb_render_markup($body, $permissions));
    }

    if ($type === 'stored_page') {
        if ($body === '') {
            return '&nbsp;';
        }
        return (string)($options['format'] ?? 'plain') === 'stored_html' ? $body : nl2br(corebb_h($body), false);
    }

    if ($type === 'profile_field') {
        $value = trim($body);
        if ($value === '') {
            return '&nbsp;';
        }
        $format = (string)($options['format'] ?? 'plain');
        if ($format === 'website') {
            return corebb_render_markup($value, 'LL');
        }
        if ($format === 'email') {
            $safeEmail = corebb_h($value);
            $atAsset = corebb_public_join_base_path('images/at_small.gif');
            return str_replace('@', "<img class='wb-img-middle' src='" . corebb_h($atAsset) . "' alt='At'>", $safeEmail);
        }
        return corebb_h($value);
    }

    if ($type === 'search_highlight') {
        return corebb_search_highlight_html($body, (string)($options['needle'] ?? ''));
    }

    if ($type === 'user_title') {
        return corebb_markup_user_title($body);
    }

    return corebb_h($body);
}
