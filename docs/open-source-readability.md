# Open-Source Readability Notes

CoreBB still carries a mix of old forum-era PHP, newer view models, and Twig
templates. Keep changes easy to review by making the request flow explicit and
by documenting why a helper exists, not just what each line does.

For the current request/data path through the project, start with
`docs/data-flow.md`.

## Function Comments

Every new or materially edited named function should have a short block comment
immediately above it:

```php
/**
 * Usage: Build the public page model for a board topic list.
 * Referenced by: controllers/forum.php?action=board and views/pages/board.twig.
 */
function corebb_board_fetch_model(...) { ... }
```

Use `Usage:` for the function's contract. Use `Referenced by:` when a maintainer
would otherwise have to search to understand the call path. It does not need to
list every indirect caller; name the primary route, model, template, or helper.

Avoid comments that restate the code. A useful comment explains a boundary,
permission rule, compatibility reason, or side effect.

## Current Public Flow

- Browser routes start through `CookieEngine.php`, which applies security
  headers, session state, global CSRF enforcement, and the signed-login cookie.
- Public route files should gather request data, call a view model or processor,
  and render through `corebb_render_public()`.
- Twig carries display HTML for public pages. PHP view models should hand Twig
  arrays, strings, URLs, flags, and preformatted content models.
- User-provided post/PM/profile/page content should move through
  `content_format_helpers.php` and `views/partials/formatted_content.twig`.
- Normal public writes should be POST with `corebb_csrf_token`; API writes use
  the API CSRF header/body token checked in `lib/api/guardrails.php`.

## First-Pass Coverage

This pass documented every named function in the main public request boundary:

- `CookieEngine.php`
- `controllers/forum.php`
- `controllers/blogs.php`
- `lib/security.php`
- `lib/view.php`
- `lib/blog_helpers.php`
- `lib/blog_view_model.php`
- `lib/post_view_model.php`

The older admin, archive-import, and bulk-maintenance helpers still have mixed
comment styles. When touching those files, apply the same function-comment rule
as part of the change rather than doing a noisy mechanical sweep.
