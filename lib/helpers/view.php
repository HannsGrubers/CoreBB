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
 |  view.php  - Twig view layer for CoreBB.              |
 +-------------------------------------------------------+*/

if (!defined('COREBB_VIEW_LOADED')) {
    define('COREBB_VIEW_LOADED', true);
}

require_once __DIR__ . '/user_display_helpers.php';
require_once __DIR__ . '/content_format_helpers.php';
require_once __DIR__ . '/corebb_url_helpers.php';
require_once __DIR__ . '/security.php';

/**
 * Usage: Normalize a requested view name to the matching Twig filename.
 * Referenced by: corebb_twig_view_path() and corebb_render().
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
 * Referenced by: corebb_render().
 */
function corebb_twig_view_path(string $view): string
{
    return __DIR__ . '/../../views/' . corebb_twig_view_name($view);
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

    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Twig templates are present, but vendor/autoload.php was not found. Run Composer install.');
    }
    require_once $autoload;

    $cacheDir = __DIR__ . '/../../cache/twig';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0775, true);
    }

    $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../../views');
    $twig = new \Twig\Environment($loader, [
        'autoescape' => 'html',
        'cache' => is_dir($cacheDir) && is_writable($cacheDir) ? $cacheDir : false,
        'strict_variables' => false,
        'auto_reload' => true,
    ]);

    $twig->addFunction(new \Twig\TwigFunction('asset', static function (string $path): string {
        return corebb_public_join_base_path($path);
    }));
    $twig->addFunction(new \Twig\TwigFunction('url', static function (string $path): string {
        return corebb_public_join_base_path($path);
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
    $twig->addFunction(new \Twig\TwigFunction('csrf_token', 'corebb_security_csrf_token'));
    return $twig;
}

/**
 * Usage: Render a Twig view.
 * Referenced by: public controllers and corebb_capture().
 */
function corebb_render(string $view, array $vars = []): void
{
    if (!is_file(corebb_twig_view_path($view))) {
        throw new RuntimeException('Twig view not found: ' . corebb_twig_view_name($view));
    }

    echo corebb_twig()->render(corebb_twig_view_name($view), $vars);
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
 * Usage: Render a public Twig page inside the shared public layout.
 * Referenced by: migrated public route controllers such as controllers/forum.php, controllers/post.php, controllers/blogs.php.
 */
function corebb_render_public(string $view, array $vars = [], array $layoutContext = []): void
{
    require_once __DIR__ . '/../models/layout_view_model.php';

    corebb_security_set_explicit_csrf_fields();

    $content = corebb_capture($view, $vars);
    $layout = corebb_public_layout_model($vars, $layoutContext);
    echo corebb_twig()->render('layouts/public.twig', [
        'layout' => $layout,
        'content' => $content,
    ]);
}
