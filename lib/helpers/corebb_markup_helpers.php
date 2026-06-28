<?php
/*-------------------------------------------------------
 | corebb_markup_helpers.php - BBCode/content renderer.
 |
 | Owns CoreBB's trusted BBCode, quote, code block,
 | Markdown, lazy-link, image, and post-body normalizers.
 +-------------------------------------------------------*/

require_once __DIR__ . '/corebb_image_helpers.php';

/**
 * Usage: Escape scalar output before composing trusted HTML fragments.
 * Referenced by: content formatting, admin helper, and search helpers.
 *
 * @param mixed $value Raw value.
 * @return string HTML-safe text.
 */
function corebb_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Usage: Escape user text before the BBCode renderer adds approved HTML.
 * Referenced by: corebb_render_markup() and the smaller BBCode render helpers.
 *
 * @param mixed $value Raw value to escape.
 * @return string HTML-escaped text using UTF-8 substitution.
 */
function corebb_markup_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
}

/**
 * Usage: Validate a BBCode color token before placing it in inline CSS.
 * Referenced by: corebb_render_markup() color, glow, highlight, and border handlers.
 *
 * @param mixed $value Raw color value from BBCode.
 * @return string Safe named or hex color, or an empty string when rejected.
 */
function corebb_markup_safe_color($value): string
{
    $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $value = trim($value);
    if (preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $value)) {
        return $value;
    }
    if (preg_match('/^[a-z]{3,20}$/i', $value)) {
        return strtolower($value);
    }
    return '';
}

/**
 * Usage: Normalize legacy BBCode font-size tokens.
 * Referenced by: corebb_markup_font_size_style().
 *
 * @param mixed $value Raw size token from [size=...].
 * @return string Legacy size key, or an empty string when rejected.
 */
function corebb_markup_safe_size($value): string
{
    $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $value = strtolower(trim($value));
    $value = preg_replace('/\s+/', '', $value) ?? '';

    // HTML font sizes intentionally match the old VNBoards-era behavior:
    // absolute 1-7 and relative +/-1 through +/-6 are allowed, nothing else.
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
 * Usage: Convert a validated BBCode size token to a CSS font-size value.
 * Referenced by: corebb_render_markup() when rendering [size=...].
 *
 * @param mixed $value Raw or normalized size token.
 * @return string CSS size value, or an empty string when rejected.
 */
function corebb_markup_font_size_style($value): string
{
    $size = corebb_markup_safe_size($value);
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
 * Usage: Validate a remote URL before rendering it as a post/profile link.
 * Referenced by: corebb_render_markup(), image rendering, YouTube detection, and import links.
 *
 * @param mixed $value Raw URL from content or imported markup.
 * @param array<int, string> $allowedSchemes Allowed URL schemes.
 * @return string Valid URL, or an empty string when rejected.
 */
function corebb_markup_safe_url($value, array $allowedSchemes = ['http', 'https']): string
{
    $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $value = trim($value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/', '', $value);
    if ($value === '' || strlen($value) > 2048) {
        return '';
    }

    $parts = parse_url($value);
    if (!is_array($parts) || empty($parts['scheme'])) {
        return '';
    }

    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, $allowedSchemes, true)) {
        return '';
    }

    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return '';
    }

    return $value;
}

/**
 * Usage: Validate smilie or face asset URLs before rendering image tags.
 * Referenced by: corebb_render_markup() emoticon parsing.
 *
 * @param mixed $value Stored asset path or remote URL.
 * @return string Safe local/remote asset URL, or an empty string when rejected.
 */
function corebb_markup_safe_asset_url($value): string
{
    $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $value = trim($value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/', '', $value);
    if ($value === '' || strlen($value) > 1024) {
        return '';
    }

    $remote = corebb_markup_safe_url($value, ['http', 'https']);
    if ($remote !== '') {
        return $remote;
    }

    // Local/site-relative smilie paths only. Do not allow protocol-relative,
    // backslash, data:, javascript:, or quote/event-handler style payloads.
    if (str_starts_with($value, '//') || str_contains($value, '\\') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) {
        return '';
    }
    if (preg_match('~^/?[A-Za-z0-9._/%-]+(?:\?[A-Za-z0-9._\~!$&()*+,;=:@/%-]*)?$~', $value)) {
        return $value;
    }

    return '';
}

/**
 * Usage: Extract a YouTube video id from a safe YouTube URL.
 * Referenced by: corebb_render_markup() lazy-link rendering.
 *
 * @param mixed $value Raw URL candidate from a post body.
 * @return string Eleven-character YouTube id, or an empty string.
 */
function corebb_youtube_video_id_from_url($value): string
{
    $url = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $url = trim($url);
    $url = rtrim($url, ".,!?;:");
    $url = corebb_markup_safe_url($url, ['http', 'https']);
    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }

    $host = strtolower((string)$parts['host']);
    $host = preg_replace('/^www\./', '', $host) ?? $host;
    $path = trim((string)($parts['path'] ?? ''), '/');
    $videoId = '';

    if ($host === 'youtu.be') {
        $segments = explode('/', $path);
        $videoId = (string)($segments[0] ?? '');
    } elseif ($host === 'youtube.com' || $host === 'm.youtube.com' || $host === 'music.youtube.com') {
        if ($path === 'watch') {
            parse_str((string)($parts['query'] ?? ''), $query);
            $videoId = (string)($query['v'] ?? '');
        } elseif (preg_match('~^(?:embed|shorts|live)/([^/?#]+)~i', $path, $m)) {
            $videoId = (string)$m[1];
        }
    }

    return preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) ? $videoId : '';
}

/**
 * Usage: Render the approved iframe wrapper for a YouTube video id.
 * Referenced by: corebb_render_markup() when a lazy link points to YouTube.
 *
 * @param string $videoId Eleven-character YouTube video id.
 * @return string HTML embed fragment, or an empty string for invalid ids.
 */
function corebb_render_youtube_embed(string $videoId): string
{
    if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
        return '';
    }

    $safeId = corebb_markup_escape($videoId);
    $src = 'https://www.youtube-nocookie.com/embed/' . $safeId;
    return "<div class='bbcode-youtube-embed' style='max-width:560px; margin:6px 0;'>"
        . "<iframe width='560' height='315' src='" . $src . "' title='YouTube video player' loading='lazy' referrerpolicy='strict-origin-when-cross-origin' allow='accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share' allowfullscreen style='max-width:100%; border:0;'></iframe>"
        . '</div>';
}

/**
 * Usage: Sanitize quote attribution text before it becomes a quote label.
 * Referenced by: quote extraction and quote block rendering helpers.
 *
 * @param mixed $attribution Raw username/label from quote markup.
 * @return string Cleaned attribution, capped at 100 characters.
 */
function corebb_clean_quote_attribution($attribution): string
{
    $attribution = html_entity_decode((string)$attribution, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $attribution = preg_replace('~\[/?(?:b|i|u|strong|em)\]~i', '', $attribution) ?? $attribution;
    $attribution = preg_replace('~\[/?color(?:=[^\]]*)?\]~i', '', $attribution) ?? $attribution;
    $attribution = strip_tags($attribution);
    $attribution = preg_replace('/[\x00-\x1F\x7F\[\]]+/', ' ', $attribution) ?? $attribution;
    $attribution = preg_replace('/\s+/', ' ', $attribution) ?? $attribution;
    $attribution = trim($attribution);
    if ($attribution === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($attribution, 0, 100);
    }
    return substr($attribution, 0, 100);
}

/**
 * Usage: Pull a VN archive "User posted:" prefix into quote attribution.
 * Referenced by: corebb_render_quote_markup().
 *
 * VN's archived quote HTML often imported as:
 * [quote][b]User[/b] posted:quoted text[/quote]
 * Promote that first-line prefix into proper quote attribution so the old
 * quote body starts with the quoted text instead of a fake header line.
 *
 * @param string $body Escaped quote body being rendered.
 * @return array{attribution: string, body: string}|null Extracted quote data or null.
 */
function corebb_extract_legacy_quote_prefix(string $body): ?array
{
    $patterns = [
        '~^\s*\[b\]\s*([^\[\]\r\n]{1,100})\s*\[/b\]\s*posted:\s*~i',
        '~^\s*([^\[\]\r\n]{1,100})\s+posted:\s*~i',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match($pattern, $body, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        $author = corebb_clean_quote_attribution((string)$m[1][0]);
        if ($author === '') {
            continue;
        }
        $prefixLen = strlen((string)$m[0][0]);
        return [
            'attribution' => $author,
            'body' => ltrim(substr($body, $prefixLen)),
        ];
    }

    return null;
}


/**
 * Usage: Normalize obvious imported quote headers before balanced parsing.
 * Referenced by: corebb_render_quote_markup().
 *
 * Renderer-only cleanup for old VN quote markup.  The archive contains many
 * nested quote chains that start as [quote][b]User[/b] posted:.  Normalize
 * the safe, obvious opening-attribution form before the balanced quote
 * parser runs.  This intentionally does not write anything back to the DB.
 *
 * @param string $text Escaped markup text about to enter quote parsing.
 * @return string Text with supported legacy quote headers normalized.
 */
function corebb_normalize_legacy_quote_markup_for_render(string $text): string
{
    if ($text === '' || stripos($text, '[quote]') === false || stripos($text, 'posted:') === false) {
        return $text;
    }

    return preg_replace_callback(
        '~\[quote\]\s*\[b\]\s*([^\[\]\r\n]{1,100})\s*\[/b\]\s*posted:\s*~i',
        static function (array $m): string {
            $author = corebb_clean_quote_attribution((string)$m[1]);
            return $author !== '' ? '[quote=' . $author . ']' : (string)$m[0];
        },
        $text
    ) ?? $text;
}

/**
 * Usage: Render VN-style quote blocks, including nested attributed quotes.
 * Referenced by: corebb_render_markup().
 *
 * The input is already HTML-escaped by corebb_render_markup(), so quote bodies may contain
 * allowed BBCode but never raw HTML from user text.
 *
 * @param mixed $text Escaped text that may contain [quote] markup.
 * @param int $depth Internal recursion depth guard.
 * @return string Text with balanced quote blocks rendered as HTML.
 */
function corebb_render_quote_markup($text, $depth = 0) {
    $text = (string)$text;
    if ($depth === 0) {
        $text = corebb_normalize_legacy_quote_markup_for_render($text);
    }
    if ($text === '' || $depth > 25 || stripos($text, '[quote') === false) {
        return $text;
    }

    $output = '';
    $offset = 0;
    $openPattern = '~\[quote(?:=([^\]\r\n]{0,100}))?\]~i';
    $tokenPattern = '~\[quote(?:=[^\]\r\n]{0,100})?\]|\[/quote\]~i';

    while (preg_match($openPattern, $text, $open, PREG_OFFSET_CAPTURE, $offset)) {
        $openText = $open[0][0];
        $openPos = $open[0][1];
        $attribution = isset($open[1][0]) ? (string)$open[1][0] : '';
        $innerStart = $openPos + strlen($openText);

        $output .= substr($text, $offset, $openPos - $offset);

        $searchPos = $innerStart;
        $quoteDepth = 1;
        $closePos = null;
        $closeLen = 0;

        while (preg_match($tokenPattern, $text, $token, PREG_OFFSET_CAPTURE, $searchPos)) {
            $tokenText = $token[0][0];
            $tokenPos = $token[0][1];
            $searchPos = $tokenPos + strlen($tokenText);

            if (stripos($tokenText, '[/quote]') === 0) {
                $quoteDepth--;
                if ($quoteDepth === 0) {
                    $closePos = $tokenPos;
                    $closeLen = strlen($tokenText);
                    break;
                }
            }
            else {
                $quoteDepth++;
            }
        }

        // Preserve malformed/unclosed quote markup rather than swallowing text.
        if ($closePos === null) {
            $output .= substr($text, $openPos);
            return $output;
        }

        $inner = substr($text, $innerStart, $closePos - $innerStart);
        if (trim($attribution) === '') {
            $legacy = corebb_extract_legacy_quote_prefix($inner);
            if (is_array($legacy)) {
                $attribution = $legacy['attribution'];
                $inner = $legacy['body'];
            }
        }

        $output .= corebb_render_quote_block($attribution, corebb_render_quote_markup($inner, $depth + 1));
        $offset = $closePos + $closeLen;
    }

    $output .= substr($text, $offset);
    return $output;
}

/**
 * Usage: Render the opening HTML for a quote block.
 * Referenced by: corebb_render_quote_block() and malformed-quote fallback code.
 *
 * @param mixed $attribution Optional quote author/label.
 * @return string Opening quote HTML fragment.
 */
function corebb_render_quote_open_block($attribution) {
    $attribution = corebb_clean_quote_attribution($attribution);
    if ($attribution !== '') {
        $label = corebb_markup_escape($attribution) . ' posted:';
    }
    else {
        $label = 'Quote:';
    }

    return "<div class='QuotedText'><strong>" . $label . "</strong><br><hr class='bbcode-rule'>";
}

/**
 * Usage: Render the closing HTML for a quote block.
 * Referenced by: corebb_render_quote_block() and legacy quote fallback code.
 *
 * @return string Closing quote HTML fragment.
 */
function corebb_render_quote_close_block() {
    return "<hr class='bbcode-rule'></div>";
}

/**
 * Usage: Wrap rendered quote body text in the standard quote container.
 * Referenced by: corebb_render_quote_markup().
 *
 * @param mixed $attribution Optional quote author/label.
 * @param mixed $body Already-rendered quote body.
 * @return string Complete quote HTML fragment.
 */
function corebb_render_quote_block($attribution, $body) {
    return corebb_render_quote_open_block($attribution) . $body . corebb_render_quote_close_block();
}



/**
 * Usage: Normalize a [code=language] token for display and CSS class names.
 * Referenced by: corebb_render_code_block().
 *
 * @param mixed $value Raw language token.
 * @return array{label: string, class: string} Display label and CSS class suffix.
 */
function corebb_code_block_language($value): array
{
    $raw = trim((string)$value);
    $raw = html_entity_decode($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $raw = preg_replace('/[\x00-\x1F\x7F]+/', '', $raw) ?? '';
    $raw = trim($raw);

    if ($raw === '') {
        return ['label' => 'Code', 'class' => ''];
    }

    if (function_exists('mb_substr')) {
        $raw = mb_substr($raw, 0, 32);
    } else {
        $raw = substr($raw, 0, 32);
    }

    if (!preg_match('/^[A-Za-z0-9#+._-]{1,32}$/', $raw)) {
        return ['label' => 'Code', 'class' => ''];
    }

    $key = strtolower($raw);
    $labels = [
        'html' => 'HTML', 'htm' => 'HTML',
        'php' => 'PHP',
        'css' => 'CSS',
        'js' => 'JavaScript', 'javascript' => 'JavaScript',
        'sql' => 'SQL',
        'xml' => 'XML',
        'json' => 'JSON',
        'bash' => 'Bash', 'sh' => 'Shell', 'shell' => 'Shell',
        'txt' => 'Text', 'text' => 'Text',
        'py' => 'Python', 'python' => 'Python',
        'c' => 'C', 'cpp' => 'C++', 'c++' => 'C++',
        'cs' => 'C#', 'c#' => 'C#', 'csharp' => 'C#',
        'java' => 'Java',
        'ini' => 'INI', 'diff' => 'Diff',
    ];
    $classes = [
        'htm' => 'html',
        'js' => 'javascript',
        'sh' => 'bash', 'shell' => 'bash',
        'txt' => 'text',
        'py' => 'python',
        'c++' => 'cpp',
        'cs' => 'csharp', 'c#' => 'csharp',
    ];

    $label = $labels[$key] ?? strtoupper($raw);
    $class = $classes[$key] ?? preg_replace('/[^a-z0-9_-]+/', '-', $key);
    $class = trim((string)$class, '-_');

    return [
        'label' => $label !== '' ? $label : 'Code',
        'class' => $class !== '' ? $class : '',
    ];
}

/**
 * Usage: Render a BBCode code block without letting user text become HTML.
 * Referenced by: corebb_render_markup() before normal BBCode parsing begins.
 *
 * @param mixed $code Raw code body.
 * @param mixed $language Optional language token from [code=...].
 * @return string HTML code-block fragment.
 */
function corebb_render_code_block($code, $language = ''): string
{
    $code = (string)$code;
    $code = str_replace(["\r\n", "\r"], "\n", $code);
    // Most users put the opening/closing BBCode tags on their own lines.
    // Trim one wrapper newline on each side without stripping intentional
    // indentation or multiple blank lines inside the code body.
    if (str_starts_with($code, "\n")) {
        $code = substr($code, 1);
    }
    if (str_ends_with($code, "\n")) {
        $code = substr($code, 0, -1);
    }

    $lang = corebb_code_block_language($language);
    $label = corebb_markup_escape($lang['label']);
    $class = $lang['class'] !== '' ? ' language-' . corebb_markup_escape($lang['class']) : '';
    $display = corebb_markup_escape($code);
    $display = str_replace("\t", "    ", $display);
    // corebb_render_markup() output is commonly wrapped in nl2br() by callers. Do not
    // leave literal newlines in the block HTML or code will double-space.
    $display = str_replace("\n", '<br>', $display);
    if ($display === '') {
        $display = '&nbsp;';
    }

    return "<div class='QuotedText bbcode-code-block'><strong>" . $label . ":</strong><br><hr class='bbcode-rule'>"
        . "<pre class='bbcode-code-pre'><code class='bbcode-code-content" . $class . "'>" . $display . "</code></pre>"
        . "<hr class='bbcode-rule'></div>";
}

/**
 * Usage: Load the shared Parsedown instance only when Markdown BBCode is used.
 * Referenced by: corebb_render_markdown_block().
 *
 * @return object|null Parsedown parser in safe mode, or null when unavailable.
 */
function corebb_markdown_parser(): ?object
{
    static $parser = null;
    static $loaded = false;

    if ($loaded) {
        return $parser;
    }

    $loaded = true;
    if (!class_exists('Parsedown')) {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    if (!class_exists('Parsedown')) {
        return null;
    }

    $parser = new Parsedown();
    if (method_exists($parser, 'setSafeMode')) {
        $parser->setSafeMode(true);
    }

    return $parser;
}

/**
 * Usage: Normalize anchors emitted by Parsedown to the forum's safe link policy.
 * Referenced by: corebb_render_markdown_block().
 *
 * @param string $html Parsedown-generated HTML.
 * @return string HTML with unsafe links degraded to plain label text.
 */
function corebb_sanitize_markdown_links(string $html): string
{
    return preg_replace_callback('~<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>(.*?)</a>~is', static function ($m): string {
        $rawUrl = html_entity_decode((string)($m[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = corebb_markup_safe_url($rawUrl, ['http', 'https']);
        if ($url === '') {
            return (string)($m[3] ?? '');
        }

        return "<a class='AuthorLink' href='" . corebb_markup_escape($url)
            . "' target='_blank' rel='noopener noreferrer'>" . (string)($m[3] ?? '') . '</a>';
    }, $html) ?? $html;
}

/**
 * Usage: Render [md]...[/md] content through Parsedown without accepting raw HTML.
 * Referenced by: corebb_render_markup() before regular BBCode parsing begins.
 *
 * @param mixed $markdown Raw Markdown body between [md] tags.
 * @return string Sanitized HTML fragment.
 */
function corebb_render_markdown_block($markdown): string
{
    $markdown = (string)$markdown;
    $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
    if (str_starts_with($markdown, "\n")) {
        $markdown = substr($markdown, 1);
    }
    if (str_ends_with($markdown, "\n")) {
        $markdown = substr($markdown, 0, -1);
    }

    $parser = corebb_markdown_parser();
    if ($parser === null || !method_exists($parser, 'text')) {
        $fallback = corebb_markup_escape($markdown);
        $fallback = str_replace("\n", '<br>', $fallback);
        return "<div class='bbcode-markdown-block'>" . ($fallback !== '' ? $fallback : '&nbsp;') . '</div>';
    }

    $html = (string)$parser->text($markdown);
    $html = corebb_sanitize_markdown_links($html);
    // Keep image rendering inside the existing [image] and [a_image] policies.
    $html = preg_replace('~<img\b[^>]*>~i', '', $html) ?? $html;
    $html = preg_replace_callback('~<pre><code([^>]*)>(.*?)</code></pre>~is', static function ($m): string {
        $code = str_replace(["\r\n", "\r", "\n"], '<br>', (string)($m[2] ?? ''));
        return '<pre><code' . (string)($m[1] ?? '') . '>' . $code . '</code></pre>';
    }, $html) ?? $html;
    // corebb_render_markup() output is usually passed through nl2br(); avoid double breaks.
    $html = str_replace(["\r\n", "\r", "\n"], '', $html);

    return "<div class='bbcode-markdown-block'>" . ($html !== '' ? $html : '&nbsp;') . '</div>';
}

/**
 * Usage: Convert trusted BBCode tags in forum text into sanitized display HTML.
 * Referenced by: posts, PMs, profiles, signatures, blogs, imports, and previews.
 *
 * @param mixed $text Raw user/content text to render.
 * @param mixed $permissions Dash-delimited BBCode permission list.
 * @return string Sanitized HTML fragment for display through Twig/formatted content.
 */
function corebb_render_markup($text, $permissions){
    $PermissionsArr = array_map('strtoupper', explode('-', (string)$permissions));
    $hasPermission = static function (string $perm) use ($PermissionsArr): bool {
        return in_array($perm, $PermissionsArr, true);
    };

    // corebb_render_markup() is the public renderer for posts, PMs, signatures, bios, notes,
    // and import previews. Treat all input as untrusted raw text.
    $text = (string)$text;

    $formatBlocks = [];
    if (($hasPermission('CB') || $hasPermission('MD')) && preg_match('~\[(?:bbcode|code|md)(?:=[^\]]{0,32})?\]~i', $text)) {
        $text = preg_replace_callback('~\[(bbcode|code|md)(?:=([^\]]{0,32}))?\](.*?)\[/\1\]~is', static function ($m) use (&$formatBlocks, $hasPermission): string {
            $tag = strtolower((string)($m[1] ?? 'code'));
            if ($tag === 'md') {
                if (!$hasPermission('MD')) {
                    return (string)($m[0] ?? '');
                }

                $value = (string)($m[3] ?? '');
                $key = '%%COREBB_FORMAT_BLOCK_' . count($formatBlocks) . '_' . md5($value . count($formatBlocks)) . '%%';
                $formatBlocks[$key] = corebb_render_markdown_block($value);
                return $key;
            }

            if (!$hasPermission('CB')) {
                return (string)($m[0] ?? '');
            }

            $language = $tag === 'code' ? (string)($m[2] ?? '') : '';
            $value = (string)($m[3] ?? '');
            $key = '%%COREBB_FORMAT_BLOCK_' . count($formatBlocks) . '_' . md5($value . count($formatBlocks)) . '%%';
            $formatBlocks[$key] = corebb_render_code_block($value, $language);
            return $key;
        }, $text) ?? $text;
    }

    // BBCode tags outside extracted code blocks are parsed after escaping so
    // user-supplied HTML/event handlers stay text.
    $text = corebb_markup_escape($text);

    if($hasPermission('Q')){
        $text = corebb_render_quote_markup($text);
        // Fallback for malformed/unbalanced attributed archive quotes that the
        // balanced parser deliberately preserved.  Plain [quote] fallbacks are
        // handled by the legacy replacement table below.
        if (stripos($text, '[quote=') !== false) {
            $text = preg_replace_callback('~\[quote=([^\]\r\n]{0,100})\]~i', static function ($m): string {
                return corebb_render_quote_open_block((string)($m[1] ?? ''));
            }, $text) ?? $text;
        }
    }

    $replace = [];
    // [BR] is used by the VN archive importer, especially for multi-line
    // legacy user titles. It is safe to support globally because corebb_render_markup()
    // has already escaped user-supplied HTML at this point.
    $replace[] = ['[br]', '<br>'];
    if($hasPermission('B')){
        $replace[] = ['[b]', '<strong>'];
        $replace[] = ['[/b]', '</strong>'];
    }
    if($hasPermission('I')){
        $replace[] = ['[i]', '<em>'];
        $replace[] = ['[/i]', '</em>'];
    }
    if($hasPermission('Q')){
        // Fallback for malformed legacy quote tags that were not consumed by the
        // balanced parser above.
        $replace[] = ['[quote]', corebb_render_quote_open_block('')];
        $replace[] = ['[/quote]', corebb_render_quote_close_block()];
    }
    if($hasPermission('U')){
        $replace[] = ['[u]', '<u>'];
        $replace[] = ['[/u]', '</u>'];
    }
    if($hasPermission('O')){
        $replace[] = ['[o]', "<span style='text-decoration:overline;'>"];
        $replace[] = ['[/o]', '</span>'];
    }
    if($hasPermission('BQ')){
        $replace[] = ['[blockquote]', '<blockquote>'];
        $replace[] = ['[/blockquote]', '</blockquote>'];
        $replace[] = ['[bq]', '<blockquote>'];
        $replace[] = ['[/bq]', '</blockquote>'];
    }
    if($hasPermission('S')){
        $replace[] = ['[spaces]', "<span style='white-space: pre'>"];
        $replace[] = ['[/spaces]', '</span>'];
    }
    if($hasPermission('HR')){
        $replace[] = ['[hr]', "<hr class='bbcode-rule'>"];
    }
    if($hasPermission('UL')){
        // Common forum shorthand for an unordered list. This keeps public
        // release posts compatible with [list][*]Item[/list] style BBCode.
        $replace[] = ['[list]', '<ul>'];
        $replace[] = ['[/list]', '</ul>'];
        $replace[] = ['[ul]', '<ul>'];
        $replace[] = ['[/ul]', '</ul>'];
    }
    if($hasPermission('OL')){
        $replace[] = ['[ol]', '<ol>'];
        $replace[] = ['[/ol]', '</ol>'];
    }
    if($hasPermission('LI')){
        $replace[] = ['[li]', '<li>'];
        $replace[] = ['[/li]', '</li>'];
        // Legacy VN toolbar emits [bullet]...[/bullet] instead of [li]...[/li].
        // Treat it as a list item so old/new posts and the toolbar button render.
        $replace[] = ['[bullet]', '<li>'];
        $replace[] = ['[/bullet]', '</li>'];
        // Common shorthand used by some imported/forum BBCode variants.
        $replace[] = ['[*]', '<li>'];
    }
    if($hasPermission('ST')){
        $replace[] = ['[strike]', "<span style='text-decoration: line-through'>"];
        $replace[] = ['[/strike]', '</span>'];
    }
    if($hasPermission('SP')){
        $replace[] = ['[spoiler]', "<span style='color: black; background-color: black; border-right: 1px dashed blue; border-left: 1px dashed blue; border-bottom: 1px dashed blue; border-top: 1px dashed blue;'>"];
        $replace[] = ['[/spoiler]', '</span>'];
    }
    if($hasPermission('BL')){
        $replace[] = ['[blink]', "<span class='bbcode-blink'>"];
        $replace[] = ['[/blink]', '</span>'];
    }
    if($hasPermission('CT')){
        $replace[] = ['[center]', "<span class='bbcode-center'>"];
        $replace[] = ['[/center]', '</span>'];
    }

    foreach ($replace as $rule) {
        $text = str_ireplace($rule[0], $rule[1], $text);
    }

    if($hasPermission('F')){
        // Now for the face symbol things.
        $faces = [
            "*-:)" => '45.gif', ":-?" => '33.gif', "/:)" => '20.gif', ":@:)" => '2.gif', "[:D]" => '36.gif',
            ":^O" => '60.gif', "=P~" => '32.gif', "]):)" => '50.gif', "O:)" => '21.gif', "I-)" => '24.gif',
            ":-L" => '49.gif', ":-s" => '15.gif', ":)" => '1.gif', "[-X" => '58.gif', "]-}" => '48.gif',
            "=}=" => '57.gif', ":O" => '11.gif', "=:}" => '47.gif', "8-}" => '30.gif', ";;)" => '5.gif',
            ":^o" => '55.gif', "**==" => '42.gif', "8-|" => '25.gif', ":-oo" => '54.gif', "]:)" => '16.gif',
            ":-8" => '26.gif', "[-(" => '28.gif', ":*" => '10.gif', ":_|" => '17.gif', ":o)" => '29.gif',
            "[-o|" => '51.gif', ";Y" => '13.gif', ":8}" => '8.gif', "$-)" => '53.gif', "X-(" => '12.gif',
            "(~~)" => '43.gif', "(:|" => '31.gif', ":|" => '19.gif', "B-)" => '14.gif', "=;" => '23.gif',
            ":x" => '7.gif', ":D" => '4.gif', "@};-" => '40.gif', ":-B" => '22.gif', "\:D/" => '59.gif',
            "=D=" => '35.gif', "@-)" => '52.gif', ":p" => '9.gif', ":P" => '9.gif', "=p" => '9.gif',
            "3:-O" => '37.gif', "%%-" => '41.gif', ":-$" => '27.gif', ":{|}" => '38.gif', "~:-" => '39.gif',
            "~o)" => '44.gif', "b-(" => '56.gif', "#-o" => '34.gif', ":-/" => '6.gif', ";)" => '3.gif',
        ];
        $faceNames = corebb_face_name_file_map();
        $text = preg_replace_callback('~\[face_([a-z0-9_!\-]{1,32})\]~i', static function ($m) use ($faceNames): string {
            $name = strtolower((string)$m[1]);
            if (!isset($faceNames[$name])) {
                return $m[0];
            }
            return corebb_render_face_image_by_name($name);
        }, $text);

        foreach ($faces as $token => $file) {
            $text = str_replace($token, "<img src='/images/faces/" . $file . "' style='vertical-align:top;' alt=''>", $text);
        }
    }

    if($hasPermission('FC') && stripos($text, '[color') !== false){
        $text = preg_replace_callback('~\[color=([^\]]{1,32})\](.*?)\[/(?:color|hl)\]~is', static function ($m): string {
            $color = corebb_markup_safe_color($m[1]);
            return $color === '' ? $m[2] : "<SPAN STYLE='color: " . corebb_markup_escape($color) . ";'>" . $m[2] . '</SPAN>';
        }, $text);
    }
    if($hasPermission('FG') && stripos($text, '[glow') !== false){
        $text = preg_replace_callback('~\[glow=([^\]]{1,32})\](.*?)\[/glow\]~is', static function ($m): string {
            $color = corebb_markup_safe_color($m[1]);
            return $color === '' ? $m[2] : "<SPAN STYLE='height:2;filter: glow(color=" . corebb_markup_escape($color) . ", strength=2);'>" . $m[2] . '</SPAN>';
        }, $text);
    }
    if($hasPermission('FH') && stripos($text, '[hl') !== false){
        $text = preg_replace_callback('~\[hl=([^\]]{1,32})\](.*?)\[/hl\]~is', static function ($m): string {
            $color = corebb_markup_safe_color($m[1]);
            return $color === '' ? $m[2] : "<SPAN STYLE='background-color: " . corebb_markup_escape($color) . ";'>" . $m[2] . '</SPAN>';
        }, $text);
    }

    if($hasPermission('FS') && stripos($text, '[size') !== false){
        $text = preg_replace_callback('~\[size=([^\]]{1,32})\](.*?)\[/size\]~is', static function ($m): string {
            $sizeStyle = corebb_markup_font_size_style($m[1]);
            return $sizeStyle === '' ? $m[2] : "<span style='font-size:" . corebb_markup_escape($sizeStyle) . ";'>" . $m[2] . '</span>';
        }, $text);
    }

    $borderMap = [
        'border' => 'border-right: 1px solid %s; border-left: 1px solid %s; border-bottom: 1px solid %s; border-top: 1px solid %s;',
        'dashedborder' => 'border-right: 1px dashed %s; border-left: 1px dashed %s; border-bottom: 1px dashed %s; border-top: 1px dashed %s;',
        'right-border' => 'border-right: 1px solid %s;',
        'left-border' => 'border-left: 1px solid %s;',
        'top-border' => 'border-top: 1px solid %s;',
        'bottom-border' => 'border-bottom: 1px solid %s;',
    ];
    foreach ($borderMap as $tag => $stylePattern) {
        $perm = match ($tag) {
            'border' => 'FB',
            'dashedborder' => 'FD',
            'right-border' => 'FR',
            'left-border' => 'FL',
            'top-border' => 'FT',
            'bottom-border' => 'FBB',
            default => '',
        };
        if (!$hasPermission($perm) || stripos($text, '[' . $tag) === false) {
            continue;
        }
        $text = preg_replace_callback('~\[' . preg_quote($tag, '~') . '=([^\]]{1,32})\](.*?)\[/' . preg_quote($tag, '~') . '\]~is', static function ($m) use ($stylePattern): string {
            $color = corebb_markup_safe_color($m[1]);
            if ($color === '') {
                return $m[2];
            }
            $safeColor = corebb_markup_escape($color);
            $count = substr_count($stylePattern, '%s');
            return "<SPAN STYLE='" . vsprintf($stylePattern, array_fill(0, $count, $safeColor)) . "'>" . $m[2] . '</SPAN>';
        }, $text);
    }

    if($hasPermission('IMG') && (stripos($text, '[image') !== false || stripos($text, '[a_image') !== false || stripos($text, '[img]') !== false)){
        $renderImage = static function ($rawValue, bool $fullSize = false): string {
            $rawUrl = html_entity_decode((string)$rawValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $faceName = corebb_legacy_boardface_url_to_name($rawUrl);
            if ($faceName !== '') {
                return corebb_render_face_image_by_name($faceName);
            }

            $url = corebb_markup_safe_url($rawUrl, ['http', 'https']);
            if ($url === '') {
                $url = corebb_safe_local_image_asset($rawUrl, ['images']);
            }
            if ($url === '') {
                return '';
            }

            $safeUrl = corebb_markup_escape($url);
            if ($fullSize) {
                return "<a class='BoardRowBLink' target='_blank' rel='noopener noreferrer' href='" . $safeUrl . "'><img class='bbcode-post-image-admin' src='" . $safeUrl . "' alt='' style='border:1px solid currentColor; margin:5px; max-width:100%; height:auto; vertical-align:top;'></a>";
            }

            return "<a class='BoardRowBLink' target='_blank' rel='noopener noreferrer' href='" . $safeUrl . "'><img class='bbcode-post-image' src='" . $safeUrl . "' alt='' height='120' width='160' style='border:1px solid currentColor; margin:5px;'></a>";
        };

        $text = preg_replace_callback('~\[a_image\s*=\s*([^\]\s]{1,2048})\]~i', static function ($m) use ($renderImage, $hasPermission): string {
            return $renderImage($m[1] ?? '', $hasPermission('AIMG'));
        }, $text);
        $text = preg_replace_callback('~\[image\s*=\s*([^\]\s]{1,2048})\]~i', static function ($m) use ($renderImage): string {
            return $renderImage($m[1] ?? '', false);
        }, $text);
        $text = preg_replace_callback('~\[img\](.*?)\[/img\]~is', static function ($m) use ($renderImage): string {
            return $renderImage(trim((string)($m[1] ?? '')), false);
        }, $text);
    }

    if($hasPermission('LL') && stripos($text, '[link=') !== false){
        // The VN archive importer converts safe HTML anchors to [link=url]text[/link].
        // Render them here instead of leaving imported signatures/posts as raw BBCode.
        $text = preg_replace_callback('~\[link\s*=\s*([^\]]{1,2048})\](.*?)\[/link\]~is', static function ($m): string {
            $url = corebb_markup_safe_url(html_entity_decode((string)$m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ['http', 'https']);
            if ($url === '') {
                return $m[2];
            }
            $safeUrl = corebb_markup_escape($url);
            $label = trim((string)$m[2]) !== '' ? (string)$m[2] : $safeUrl;
            return "<A class='AuthorLink' HREF='" . $safeUrl . "' TARGET='_blank' rel='noopener noreferrer'>" . $label . '</A>';
        }, $text);
    }

    if($hasPermission('LL')){
        $text = preg_replace_callback('~(^|[\s(])((?:https?)://[^\s<>{}\[\]"\']{3,2048})~i', static function ($m): string {
            $rawUrl = (string)$m[2];
            $trimmedUrl = rtrim($rawUrl, ".,!?;:");
            $trailing = substr($rawUrl, strlen($trimmedUrl));
            $url = corebb_markup_safe_url($trimmedUrl, ['http', 'https']);
            if ($url === '') {
                return $m[0];
            }
            $videoId = corebb_youtube_video_id_from_url($url);
            if ($videoId !== '') {
                return $m[1] . corebb_render_youtube_embed($videoId) . $trailing;
            }
            $safeUrl = corebb_markup_escape($url);
            return $m[1] . "<A class='AuthorLink' HREF='" . $safeUrl . "' TARGET='_blank' rel='noopener noreferrer'>" . $safeUrl . '</A>' . $trailing;
        }, $text);
    }

    if (stripos($text, '<li>') !== false && stripos($text, '<ul>') === false && stripos($text, '<ol>') === false) {
        $text = str_ireplace('<li>', '<span class="bbcode-list-item">', $text);
        $text = str_ireplace('</li>', '</span>', $text);
    }

    if (!empty($formatBlocks)) {
        $text = strtr($text, $formatBlocks);
    }

    return $text;
}

/**
 * Usage: Convert stored post-style markup into display HTML.
 * Referenced by: content_format_helpers.php and formatted_content.twig.
 *
 * @param string $text Stored post text.
 * @param int $authorAccessLevel Post author's access level.
 * @return string Trusted HTML for display.
 */
function corebb_markup_post(string $text, int $authorAccessLevel = 0): string
{
    $permissions = 'B-I-Q-U-O-BQ-S-HR-UL-OL-LI-ST-SP-BL-CT-F-LL-FC-FG-FH-FB-FD-FL-FT-FBB-FS-CB-MD-IMG';
    if ($authorAccessLevel >= 5) {
        $permissions .= '-AIMG';
    }
    return nl2br(corebb_render_markup((string)$text, $permissions));
}

/**
 * Usage: Validate title-only color values before placing them in inline styles.
 * Referenced by: corebb_markup_user_title().
 *
 * @param string $value Raw color token.
 * @return string Safe CSS color token, or empty when rejected.
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
 * Usage: Normalize title-only text size values to the small allow-list.
 * Referenced by: corebb_title_font_size_style().
 *
 * @param string $value Raw size token.
 * @return string Normalized size token, or empty when rejected.
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
 *
 * @param string $value Raw or normalized size token.
 * @return string CSS font-size value, or empty when rejected.
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
 *
 * @param string $text Stored user-title text.
 * @return string Trusted HTML for display.
 */
function corebb_markup_user_title(string $text): string
{
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }

    $text = str_ireplace(['<br />', '<br/>', '<br>', '[br]'], "\n", $text);
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
 * Usage: Normalize post text before storage and balance code tags.
 * Referenced by: post, blog, and signature save workflows.
 *
 * @param mixed $data Raw textarea/body content.
 * @return string Normalized text ready for database writes.
 */
function corebb_prepare_post_data($data){
    $data = (string)$data;

    // Normalize browser/OS line endings before storing. This keeps textarea
    // edits from turning into visible literal \r\n text after prepared writes.
    $data = str_replace(["\r\n", "\r"], "\n", $data);

    /* Fix Code tags, this includes finding open tags and closing them (that's really it =P ) */
    foreach (['bbcode', 'code', 'md'] as $codeTag) {
        $TagsOpen = preg_match_all('~(\[' . $codeTag . '(?:=[^\]]+)?\])~is', $data, $dummy);
        $TagsClosed = preg_match_all('~(\[/' . $codeTag . '\])~is', $data, $dummy);

        /* Perform the fixes */
        if ($TagsOpen > $TagsClosed){
            $data .= str_repeat('[/' . $codeTag . ']', $TagsOpen - $TagsClosed);
        }
        elseif ($TagsClosed > $TagsOpen){
            $data = str_repeat('[' . $codeTag . ']', $TagsClosed - $TagsOpen) . $data;
        }
    }

    return $data;
}

/**
 * Usage: Render text with the standard broad BBCode permission set.
 * Referenced by: display paths that use the standard permission list.
 *
 * @param mixed $text Raw content to render.
 * @return string Sanitized HTML fragment from corebb_render_markup().
 */
function corebb_render_default_markup($text){
    return corebb_render_markup($text, "B-I-Q-U-O-BQ-S-HR-UL-OL-LI-ST-SP-BL-CT-F-LL-FC-FG-FH-FB-FD-FL-FT-FBB-FS-CB-MD-IMG");
}
