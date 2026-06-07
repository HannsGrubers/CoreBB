# Content Formatting Boundary

CoreBB public Twig views escape normal data by default. Any preformatted HTML
that remains necessary on the public forum must pass through one narrow output
pipe:

```twig
{% include 'partials/formatted_content.twig' with {content: contentModel} only %}
```

That partial is the public display boundary for late-rendered content HTML.

## Rulebook

1. Public controllers and view models pass structured data, not HTML strings.
2. Public Twig templates render normal text with regular Twig variables.
3. Public Twig templates render formatted/user-authored/stored content only via
   `partials/formatted_content.twig`.
4. New public view-model fields should not be named `*_html` or `html` unless
   they are inside API payloads or admin-only models.
5. Do not use `|raw` in public Twig templates except for the approved boundary
   cases listed below.
6. If a dependency produces HTML, redesign the data flow so that HTML is created
   as late as possible and consumed by the formatted-content partial.
7. Admin pages, maintenance tools, and API serializers may have separate legacy
   HTML needs, but those exceptions do not apply to public Twig templates.

## Approved Public Raw Outputs

- `views/layouts/public.twig`: uses `content|raw` to place an already-rendered
  public page template into the public layout.
- `views/partials/public_head.twig`: uses `json_encode|raw` for JavaScript data,
  not HTML.
- `views/partials/formatted_content.twig`: renders the output of
  `corebb_formatted_content_html()`.

No other public Twig template should use `|raw`.

## Content Models

Use `corebb_content_model($type, $body, $options)` or one of its wrappers:

- `corebb_post_body_model()` for posts, replies, blog entries, and signatures.
- `corebb_pm_body_model()` for private-message bodies.
- `corebb_profile_bio_model()` for profile bios.
- `corebb_profile_field_model()` for profile fields that may need email/link
  formatting.
- `corebb_stored_page_body_model()` for trusted database-backed content such as
  the Terms of Service setting.
- `corebb_search_highlight_model()` for search result highlights.
- `corebb_user_title_model()` for custom user titles.

The model tells the formatter what kind of content is being displayed. Twig only
decides where it appears.

## Stored HTML

`stored_html` is allowed only for trusted stored content, such as the Terms of
Service body maintained by staff/admin tools. Do not use `stored_html` for
user-authored posts, private messages, signatures, profile fields, comments, or
search input.

## Adding A New Formatted Surface

When adding a public surface that needs markup:

1. Add or reuse a content model helper in `lib/content_format_helpers.php`.
2. Keep the controller/view model field structured, for example:
   `['content' => corebb_post_body_model($body)]`.
3. Render the field with `partials/formatted_content.twig`.
4. Avoid putting rendered HTML in the model.
5. Run the public raw-output scan:

```powershell
rg -n --glob '!vendor/**' --glob '!cache/**' --glob '!views/pages/admin_*' "\|raw|_html\b|formatted_content_html" views lib functions.php
```

The expected public Twig hits are `public.twig`, `public_head.twig`, and
`formatted_content.twig`.

## Why This Exists

This keeps the Twig migration honest: backend code prepares content data and
security decisions, while Twig owns the display. The one intentional exception
is late-rendered formatted content, because BBCode/stored-content conversion
must produce HTML at the final display step.
