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
 |  view.php  - Minimal native-PHP view layer for        |
 |  CoreBB.                                              |
 +-------------------------------------------------------+*/

if (!defined('COREBB_VIEW_LOADED')) {
    define('COREBB_VIEW_LOADED', true);
}

require_once __DIR__ . '/user_display_helpers.php';
require_once __DIR__ . '/content_format_helpers.php';

/**
 * Usage: Resolve a legacy PHP view path and fail loudly if it is missing.
 * Referenced by: corebb_render() when no Twig template is available.
 */
function corebb_view_path(string $view): string
{
    $view = ltrim($view, '/');
    $path = __DIR__ . '/../views/' . $view;
    if (!is_file($path)) {
        throw new RuntimeException('View not found: ' . $view);
    }
    return $path;
}

/**
 * Usage: Escape scalar output in legacy PHP views.
 * Referenced by: admin PHP views and fallback view rendering.
 */
function corebb_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Usage: Normalize a requested view name to the matching Twig filename.
 * Referenced by: corebb_twig_view_path(), corebb_twig_available(), and corebb_render().
 */
function corebb_twig_view_name(string $view): string
{
    $view = ltrim($view, '/');
    if (str_ends_with($view, '.php')) {
        return substr($view, 0, -4) . '.twig';
    }
    if (!str_ends_with($view, '.twig')) {
        return $view . '.twig';
    }
    return $view;
}

/**
 * Usage: Build the absolute path for a normalized Twig template.
 * Referenced by: corebb_twig_available().
 */
function corebb_twig_view_path(string $view): string
{
    return __DIR__ . '/../views/' . corebb_twig_view_name($view);
}

/**
 * Usage: Check whether a view has been migrated to Twig.
 * Referenced by: corebb_render() to choose Twig before legacy PHP.
 */
function corebb_twig_available(string $view): bool
{
    return is_file(corebb_twig_view_path($view));
}

/**
 * Usage: Create and cache the Twig environment plus CoreBB template functions.
 * Referenced by: corebb_render() and corebb_render_public().
 */
function corebb_twig(): \Twig\Environment
{
    static $twig = null;

    if ($twig instanceof \Twig\Environment) {
        return $twig;
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Twig templates are present, but vendor/autoload.php was not found. Run Composer install.');
    }
    require_once $autoload;

    $cacheDir = __DIR__ . '/../cache/twig';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0775, true);
    }

    $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../views');
    $twig = new \Twig\Environment($loader, [
        'autoescape' => 'html',
        'cache' => is_dir($cacheDir) && is_writable($cacheDir) ? $cacheDir : false,
        'strict_variables' => false,
        'auto_reload' => !defined('COREBB_ENV') || (string)COREBB_ENV !== 'production',
    ]);

    $twig->addFunction(new \Twig\TwigFunction('asset', static function (string $path): string {
        return function_exists('corebb_public_asset') ? corebb_public_asset($path) : '/' . ltrim($path, '/');
    }));
    $twig->addFunction(new \Twig\TwigFunction('url', static function (string $path): string {
        return function_exists('corebb_public_url') ? corebb_public_url($path) : '/' . ltrim($path, '/');
    }));
    $twig->addFunction(new \Twig\TwigFunction('formatted_content_html', 'corebb_formatted_content_html', ['is_safe' => ['html']]));
    $twig->addFunction(new \Twig\TwigFunction('post_body_model', 'corebb_post_body_model'));
    $twig->addFunction(new \Twig\TwigFunction('pm_body_model', 'corebb_pm_body_model'));
    $twig->addFunction(new \Twig\TwigFunction('admin_note_body_model', 'corebb_admin_note_body_model'));
    $twig->addFunction(new \Twig\TwigFunction('profile_bio_model', 'corebb_profile_bio_model'));
    $twig->addFunction(new \Twig\TwigFunction('stored_page_body_model', 'corebb_stored_page_body_model'));
    $twig->addFunction(new \Twig\TwigFunction('profile_field_model', 'corebb_profile_field_model'));
    $twig->addFunction(new \Twig\TwigFunction('search_highlight_model', 'corebb_search_highlight_model'));
    $twig->addFunction(new \Twig\TwigFunction('post_faces_model', 'corebb_post_faces_model'));
    $twig->addFunction(new \Twig\TwigFunction('user_name_model', 'corebb_user_name_model'));
    $twig->addFunction(new \Twig\TwigFunction('user_star_model', 'corebb_user_star_model'));
    $twig->addFunction(new \Twig\TwigFunction('user_icon_model', 'corebb_user_icon_model'));
    $twig->addFunction(new \Twig\TwigFunction('user_title_model', 'corebb_user_title_model'));
    $twig->addFunction(new \Twig\TwigFunction('csrf_token', static function (): string {
        return function_exists('corebb_security_csrf_token') ? corebb_security_csrf_token() : '';
    }));
    return $twig;
}

/**
 * Usage: Render either a Twig view or a legacy PHP view with the same call shape.
 * Referenced by: public controllers and corebb_partial().
 */
function corebb_render(string $view, array $vars = []): void
{
    if (corebb_twig_available($view)) {
        echo corebb_twig()->render(corebb_twig_view_name($view), $vars);
        return;
    }

    extract($vars, EXTR_SKIP);
    include corebb_view_path($view);
}

/**
 * Usage: Render a view into a string so the public layout can wrap it.
 * Referenced by: corebb_render_public().
 */
function corebb_capture(string $view, array $vars = []): string
{
    ob_start();
    corebb_render($view, $vars);
    return (string)ob_get_clean();
}

/**
 * Usage: Compatibility alias for old partial rendering calls.
 * Referenced by: older PHP view code that still speaks in partials.
 */
function corebb_partial(string $view, array $vars = []): void
{
    corebb_render($view, $vars);
}

/**
 * Usage: Render a public Twig/PHP page inside the shared public layout.
 * Referenced by: migrated public route controllers such as controllers/forum.php, controllers/post.php, controllers/blogs.php.
 */
function corebb_render_public(string $view, array $vars = [], array $layoutContext = []): void
{
    require_once __DIR__ . '/layout_view_model.php';

    if (function_exists('corebb_security_set_explicit_csrf_fields')) {
        corebb_security_set_explicit_csrf_fields();
    }

    $content = corebb_capture($view, $vars);
    $layout = corebb_public_layout_model($vars, $layoutContext);
    echo corebb_twig()->render('layouts/public.twig', [
        'layout' => $layout,
        'content' => $content,
    ]);
}

/**
 * Usage: Convert stored post-style markup into display HTML.
 * Referenced by: content_format_helpers.php and formatted_content.twig.
 */
function corebb_markup_post(string $text, int $authorAccessLevel = 0): string
{
    $permissions = 'B-I-Q-U-O-BQ-S-HR-UL-OL-LI-ST-SP-BL-CT-F-LL-FC-FG-FH-FB-FD-FL-FT-FBB-FS-CB-MD-IMG';
    if ($authorAccessLevel >= 5) {
        $permissions .= '-AIMG';
    }
    return nl2br(MarkUp((string)$text, $permissions));
}


/**
 * Usage: Validate title-only color values before placing them in inline styles.
 * Referenced by: corebb_markup_user_title().
 */
function corebb_title_safe_color(string $value): string
{
    $value = trim($value);
    if (preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $value)) {
        return $value;
    }
    if (preg_match('/^[a-z]{3,20}$/i', $value)) {
        return $value;
    }
    return '';
}

/**
 * Usage: Normalize title-only text size values to the small legacy allow-list.
 * Referenced by: corebb_title_font_size_style().
 */
function corebb_title_safe_size(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/\s+/', '', $value) ?? '';

    if (preg_match('/^[1-7]$/', $value)) {
        return $value;
    }
    if (preg_match('/^[+-][1-6]$/', $value)) {
        return $value;
    }

    $namedSizes = [
        'tiny' => '1',
        'small' => '2',
        'normal' => '3',
        'medium' => '3',
        'large' => '4',
        'x-large' => '+2',
        'xlarge' => '+2',
        'xx-large' => '+3',
        'xxlarge' => '+3',
        'huge' => '+3',
    ];

    return $namedSizes[$value] ?? '';
}

/**
 * Usage: Convert a safe title size token into a CSS font-size value.
 * Referenced by: corebb_markup_user_title().
 */
function corebb_title_font_size_style(string $value): string
{
    $size = corebb_title_safe_size($value);
    $map = [
        '1' => '0.63em',
        '2' => '0.82em',
        '3' => '1em',
        '4' => '1.13em',
        '5' => '1.5em',
        '6' => '2em',
        '7' => '3em',
        '+1' => '1.13em',
        '+2' => '1.5em',
        '+3' => '2em',
        '+4' => '2.5em',
        '+5' => '3em',
        '+6' => '3.5em',
        '-1' => '0.82em',
        '-2' => '0.7em',
        '-3' => '0.63em',
        '-4' => '0.55em',
        '-5' => '0.5em',
        '-6' => '0.45em',
    ];

    return $map[$size] ?? '';
}

/**
 * Usage: Render the limited markup allowed in user titles.
 * Referenced by: user_display_helpers.php, profile/blog/thread templates.
 */
function corebb_markup_user_title(string $text): string
{
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }

    /*
     * Titles need their own small, safe BB-code renderer.
     *
     * The legacy MarkUp() helper only applies basic replacements while the
     * "F"/faces permission is enabled, which made titles without smilies show
     * raw [b]...[/b] tags. This renderer keeps titles compact and prevents
     * image/HTML injection while still supporting the old VN-style title markup.
     */
    $text = str_ireplace(['<br />', '<br/>', '<br>', '[br]'], "
", $text);
    $text = str_ireplace(
        ['<strong>', '</strong>', '<b>', '</b>', '<em>', '</em>', '<i>', '</i>', '<u>', '</u>'],
        ['[b]', '[/b]', '[b]', '[/b]', '[i]', '[/i]', '[i]', '[/i]', '[u]', '[/u]'],
        $text
    );
    $text = strip_tags($text);
    $text = mb_substr($text, 0, 500);

    $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $simplePairs = [
        '~\[b\](.*?)\[/b\]~is' => '<strong>$1</strong>',
        '~\[i\](.*?)\[/i\]~is' => '<em>$1</em>',
        '~\[u\](.*?)\[/u\]~is' => '<u>$1</u>',
        '~\[o\](.*?)\[/o\]~is' => '<span style="text-decoration:overline;">$1</span>',
        '~\[strike\](.*?)\[/strike\]~is' => '<span style="text-decoration:line-through;">$1</span>',
        '~\[s\](.*?)\[/s\]~is' => '<span style="text-decoration:line-through;">$1</span>',
        '~\[spoiler\](.*?)\[/spoiler\]~is' => '<span style="color:black;background-color:black;border:1px dashed blue;">$1</span>',
        '~\[center\](.*?)\[/center\]~is' => '<span style="display:block;text-align:center;">$1</span>',
        '~\[blink\](.*?)\[/blink\]~is' => '<span class="bbcode-blink">$1</span>',
    ];

    for ($i = 0; $i < 4; $i++) {
        $before = $html;
        foreach ($simplePairs as $pattern => $replacement) {
            $html = preg_replace($pattern, $replacement, $html);
        }
        $html = preg_replace_callback('~\[color=([^\]]{1,32})\](.*?)\[/(?:color|hl)\]~is', static function ($m): string {
            $color = corebb_title_safe_color(html_entity_decode((string)$m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            return $color === '' ? $m[2] : '<span style="color:' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . ';">' . $m[2] . '</span>';
        }, $html);
        $html = preg_replace_callback('~\[hl=([^\]]{1,32})\](.*?)\[/hl\]~is', static function ($m): string {
            $color = corebb_title_safe_color(html_entity_decode((string)$m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            return $color === '' ? $m[2] : '<span style="background-color:' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . ';">' . $m[2] . '</span>';
        }, $html);
        $html = preg_replace_callback('~\[size=([^\]]{1,32})\](.*?)\[/size\]~is', static function ($m): string {
            $sizeStyle = corebb_title_font_size_style(html_entity_decode((string)$m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            return $sizeStyle === '' ? $m[2] : '<span style="font-size:' . htmlspecialchars($sizeStyle, ENT_QUOTES, 'UTF-8') . ';">' . $m[2] . '</span>';
        }, $html);
        $html = preg_replace_callback('~\[(border|dashedborder|left-border|right-border|top-border|bottom-border)=([^\]]{1,32})\](.*?)\[/\1\]~is', static function ($m): string {
            $color = corebb_title_safe_color(html_entity_decode((string)$m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            if ($color === '') {
                return $m[3];
            }
            $kind = strtolower((string)$m[1]);
            $styleColor = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
            $styles = [
                'border' => 'border:1px solid ' . $styleColor . ';',
                'dashedborder' => 'border:1px dashed ' . $styleColor . ';',
                'left-border' => 'border-left:1px solid ' . $styleColor . ';',
                'right-border' => 'border-right:1px solid ' . $styleColor . ';',
                'top-border' => 'border-top:1px solid ' . $styleColor . ';',
                'bottom-border' => 'border-bottom:1px solid ' . $styleColor . ';',
            ];
            return '<span style="' . $styles[$kind] . '">' . $m[3] . '</span>';
        }, $html);
        if ($html === $before) {
            break;
        }
    }

    return nl2br($html, false);
}

/**
 * Usage: Render compact page links for migrated templates and legacy fallbacks.
 * Referenced by: pagination helpers and older view models.
 *
 *
 *DEPRECATED, removal pending 6/7/26
 *
function corebb_page_links(string $urlPattern, int $currentPage, int $totalPages, string $class = 'MainMenuFont'): string
{
    if (function_exists('corebb_compact_pagination_html')) {
        return corebb_compact_pagination_html($urlPattern, $currentPage, $totalPages, $class, 'Pages:', ' | ', 2);
    }

    if ($totalPages <= 1) {
        return '';
    }

    $links = [];
    for ($i = 1; $i <= $totalPages; $i++) {
        if ($i === $currentPage) {
            $links[] = "<a class='" . corebb_h($class) . "'><b>{$i}</b></a>";
        } else {
            $links[] = "<a class='" . corebb_h($class) . "' href='" . corebb_h(str_replace('{page}', (string)$i, $urlPattern)) . "'>{$i}</a>";
        }
    }
    return 'Pages: ' . implode(' | ', $links);
}
*/
