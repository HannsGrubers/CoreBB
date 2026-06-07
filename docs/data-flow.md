# CoreBB Data Flow Guide

This guide explains how a browser request moves through CoreBB after the public
templating and controller cleanup. It is meant for future maintainers who need
to know where to add code without reintroducing root-file sprawl or mixing data
processing with display markup.

## Request Boundaries

CoreBB is organized around a small set of boundaries:

- `.htaccess` owns public URL mapping. Friendly routes such as `/login/`,
  `/post/new/b12/`, and `/private-messages/` rewrite to internal controllers.
- `CookieEngine.php` is the shared browser bootstrap. It starts the security
  output filter, loads configuration and database access, starts the session,
  enforces normal form CSRF checks, and loads the signed-in user globals.
- `controllers/` contains public workflow entry points. Controllers should read
  request intent, check coarse access, call the right model or processor, then
  render or redirect.
- `lib/` contains the data and workflow layer. View models prepare template
  arrays. Processors validate writes, enforce detailed permissions, call the
  database helpers, and trigger side effects such as notifications.
- `views/` contains Twig layouts, pages, and partials. Twig owns display HTML.
  PHP should pass data, flags, URLs, and formatted-content models rather than
  assembling page markup.
- `api/v1/` is the JSON front controller. API writes reuse the same `lib/`
  processors as browser forms so permissions and database behavior stay in one
  place.
- `admin.php` remains the admin front controller. Admin view models live under
  `lib/admin_*_view_model.php`, and admin templates live under
  `views/pages/admin_*.twig`.

Direct browser requests to `controllers/` and `views/` are blocked in
`.htaccess`. Those folders are server-side source, not public assets.

## Normal Public Page Flow

Most public pages follow this shape:

```text
Browser URL
  -> .htaccess rewrite
  -> controllers/*.php or index.php
  -> CookieEngine.php bootstrap
  -> lib/*_view_model.php builds a model
  -> corebb_render_public()
  -> views/pages/*.twig
  -> views/layouts/public.twig
  -> HTML response
```

`corebb_render_public()` in `lib/view.php` captures the requested page template,
builds shared chrome through `corebb_public_layout_model()`, and renders
`views/layouts/public.twig`. Twig auto-escapes normal variables by default.
Stored forum content that intentionally supports BBCode-style formatting should
go through the content formatting boundary documented in
`docs/content-formatting-boundary.md`.

## Normal Write Flow

Browser writes should be POST requests:

```text
POST form
  -> .htaccess rewrite
  -> controller includes CookieEngine.php
  -> CookieEngine.php validates CSRF for normal forms
  -> controller checks login/method/action
  -> lib processor validates input and permissions
  -> lib/db.php prepared helpers read/write rows
  -> processor returns a result model or redirect target
  -> controller renders result page or redirects to the next public URL
```

Use the database helpers in `lib/db.php` for new code:

- `db_one()` and `db_all()` for row reads.
- `db_value()` and `db_exists()` for scalar/existence checks.
- `db_run()` for writes.
- `db_begin()`, `db_commit()`, and `db_rollback()` for multi-table writes.
- `db_insert_id()` after inserts that need the generated id.

When a write updates more than one table, prefer a transaction. The post
workflow is the best current example.

## Example: Forum Index, Board, And Topic Views

The forum landing page still starts at the root front door:

```text
/
  -> index.php
  -> CookieEngine.php
  -> lib/index_view_model.php: corebb_fetch_index_model()
  -> views/pages/index.twig
  -> views/partials/index_category_*.twig and index_forum_row.twig
  -> views/layouts/public.twig
```

A board page follows the forum controller:

```text
/some-board-slug/b12/p2/
  -> .htaccess: controllers/forum.php?action=board&id=12&p=2
  -> controllers/forum.php
  -> lib/board_view_model.php: corebb_board_fetch_model()
  -> views/pages/board.twig
  -> views/partials/board_topic_row.twig
```

A topic page uses the same controller with a different action:

```text
/some-board-slug/b12/345/p1/
  -> .htaccess: controllers/forum.php?action=thread&id=345&brd=12&p=1
  -> controllers/forum.php
  -> lib/thread_view_model.php: corebb_thread_fetch_model()
  -> views/pages/thread.twig
  -> views/partials/thread_post.twig
  -> views/partials/formatted_content.twig for post body output
```

Board and topic models are responsible for permission-aware data selection.
Private-board and archive rules should be enforced before rows become visible
to Twig.

## Example: User Creates A New Topic

The compose screen is a GET:

```text
/post/new/b12/
  -> .htaccess: controllers/post.php?boardid=12&act=new
  -> controllers/post.php
  -> login check
  -> lib/post_view_model.php: corebb_post_form_model()
  -> views/pages/post_form.twig
```

The submit is a POST:

```text
/post/submit/
  -> .htaccess: controllers/post.php
  -> CookieEngine.php CSRF enforcement
  -> controllers/post.php
  -> lib/post_view_model.php: corebb_post_process()
  -> corebb_post_process_new_topic()
  -> db_begin()
  -> INSERT topics
  -> INSERT posts
  -> optional poll creation
  -> UPDATE forums/topics/users counters and activity
  -> db_commit()
  -> mention notifications
  -> views/pages/post_result.twig
```

Replies and edits use the same controller and dispatcher:

- `corebb_post_process_reply()` inserts a post, updates topic/board/user
  activity, and sends reply/mention notifications.
- `corebb_post_process_edit()` validates ownership or moderator access, enforces
  edit timers for normal users, updates the post, and optionally updates sticky
  state for moderator edits.
- `corebb_post_process_blog()` handles blog-entry submissions that enter through
  the same composer.

The API routes under `/api/v1/post/...` also call `corebb_post_process()`.
That keeps browser and API posting behavior aligned.

## Example: Private Messages

PM folders are read-only page models:

```text
/private-messages/
  -> .htaccess: controllers/messages.php?action=folder&folder=unread
  -> controllers/messages.php
  -> login check
  -> lib/pm_view_model.php: corebb_pm_folder_model()
  -> lib/pm_helpers.php: corebb_pm_folder_result(), corebb_pm_counts()
  -> views/pages/pm_folder.twig
  -> views/partials/pm_message_row.twig
```

The compose page prepares display data only:

```text
/private-messages/send/15/
  -> controllers/messages.php?action=send&usr=15
  -> lib/pm_send_view_model.php: corebb_pm_send_model()
  -> views/pages/pm_send.twig
```

Sending a PM is a write:

```text
POST /private-messages/send/
  -> CookieEngine.php CSRF enforcement
  -> controllers/messages.php
  -> lib/pm_helpers.php: corebb_pm_send_from_post()
  -> normalize recipients by username or user id
  -> enforce recipient limits and rate limits
  -> INSERT one privatemessages row per valid recipient
  -> redirect to /private-messages/ with a status message
```

Viewing a PM is permission-scoped by sender/recipient:

```text
/private-messages/message/99/unread/
  -> controllers/messages.php?action=view&pm=99&method=unread
  -> lib/pm_view_model.php: corebb_pm_view_model()
  -> lib/pm_helpers.php: corebb_pm_get_for_view()
  -> mark unread received message as read
  -> views/pages/pm_view.twig
  -> views/partials/formatted_content.twig for PM body output
```

PM reports are submitted from the PM view and flow through
`corebb_pm_report_private_message()`, which validates that the reporting user can
see the message before creating a `pm_reports` row.

## Example: Login, Registration, And Recovery

Authentication routes are consolidated under `controllers/auth.php`:

```text
/login/
  -> controllers/auth.php?action=login
  -> lib/auth_view_model.php: corebb_login_model()
  -> views/pages/login.twig
```

Login submission is intentionally redirect-only:

```text
POST /login/submit/
  -> CookieEngine.php CSRF enforcement
  -> controllers/auth.php?action=login_submit
  -> lib/auth_flow_helpers.php: corebb_auth_login_submit_redirect()
  -> signed persistent-login cookie is issued by auth helpers
  -> redirect to the next public URL
```

Registration, resend verification, password reset, and email verification use
the same controller with different actions. Their validation and email/token
logic belongs in the auth helper/view-model layer, not in Twig.

## Example: User Control Panel

User CP is also action-based:

```text
/user-cp/profile/
  -> controllers/usercp.php?action=profile
  -> login check
  -> lib/usercp_settings_view_model.php
  -> views/pages/edit_profile.twig
```

Each User CP POST is handled near the matching action in
`controllers/usercp.php`, but the data work lives in helpers such as:

- `corebb_usercp_save_profile()`
- `corebb_avatar_handle_submit()`
- `corebb_usercp_save_signature()`
- `corebb_usercp_save_options()`
- `corebb_user_appearance_save_self()`

The controller should redirect after a successful settings write. That prevents
browser refreshes from resubmitting the form.

## Example: Blogs

Blog routes are grouped under `controllers/blogs.php`:

```text
/blogs/
  -> controllers/blogs.php?action=home
  -> lib/blog_view_model.php: corebb_blog_home_model()
  -> views/pages/blogs.twig
```

Entry and owner pages follow the same pattern:

```text
/blogs/user/15/
  -> corebb_blog_viewblog_model()
  -> views/pages/blog_viewblog.twig

/blogs/entry/22/
  -> corebb_blog_viewentry_model()
  -> views/pages/blog_viewentry.twig
```

New blog entries use the post composer at `/blogs/new/`, which rewrites to
`controllers/post.php?act=blog`. Blog edit/delete pages are still routed through
`controllers/blogs.php` and handled by `lib/blog_view_model.php`.

## Example: Support And Moderation

Small public support pages are grouped in `controllers/support.php`:

```text
/denied/              -> system message model
/banned/              -> unban request model
/board-rules-faq/     -> board rules and FAQ model
/contact-mods/        -> contact moderators model
/report-message/123/  -> post report model
```

Moderator actions are grouped in `controllers/moderation.php`. Keep moderator
permission checks inside the moderation helper/model layer as close as possible
to the action being performed. A menu link being visible is not authorization.

## Example: JSON API

The API front controller is `api/v1/index.php`:

```text
/api/v1/post/reply/345
  -> api/v1/index.php?path=post/reply/345
  -> lib/api/bootstrap.php and guardrails
  -> API rate limit and CSRF checks
  -> corebb_api_viewer()
  -> build a browser-form-compatible payload
  -> lib/post_view_model.php: corebb_post_process()
  -> JSON response
```

The API should reuse browser processors wherever possible. Do not duplicate
posting, PM, moderation, or auth write logic in API-only code unless the behavior
is truly API-specific.

## Example: Admin Pages

Admin requests intentionally remain under `admin.php`:

```text
/admin/?act=manage_boards
  -> admin.php
  -> CookieEngine.php
  -> admin authentication and tool-access checks
  -> lib/admin_boards_view_model.php
  -> views/pages/admin_manage_boards.twig
  -> views/layouts/admin.twig
```

The admin panel has a separate layout and permission model. Special tool access
lets a non-admin user enter specific admin tools, but each action still needs
its own authorization check before it mutates data.

## Where New Code Should Go

Use this checklist for new features:

- Add or update the public URL in `.htaccess`.
- Add a controller action under `controllers/` when the feature is public.
- Put data loading and write processing in `lib/`.
- Put HTML in `views/pages/*.twig` and reusable fragments in
  `views/partials/*.twig`.
- Render public pages with `corebb_render_public()`.
- Redirect after successful POST writes.
- Use `corebb_public_url()` or the Twig `url()` function for generated links.
- Use `db_*()` helpers with bound parameters for database access.
- Keep permission checks in the processor/model layer, not only in templates.
- Send stored user content through the formatting boundary instead of handing
  raw HTML around inside models.

If a future change needs a new kind of route, copy the closest current flow
first. The existing post, PM, and User CP paths are the best examples for
write-heavy public features.
