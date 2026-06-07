# CoreBB API Contracts

The CoreBB API is a transport layer over the existing forum system, not a
second implementation of forum behavior.

API endpoints should prefer the same helpers and view-models used by desktop
routes. This keeps permissions, validation, SQL writes, notifications, logging,
rate limits, and counters aligned across classic desktop pages and API clients.

## Shared Helper Contract

These shared helpers now affect both desktop and API behavior:

- Auth and session helpers in `lib/api/auth.php`, `lib/security.php`, and
  `lib/auth_password_helpers.php`
- Registration helpers in `lib/auth_view_model.php`,
  `lib/email_verification_helpers.php`, and `CreateUser()`
- Board, thread, profile, and post view-models
- Posting helpers, especially `corebb_post_process()`
- Private-message helpers, especially `corebb_pm_send_from_post()`
- Moderation helpers in `lib/moderation_helpers.php`
- Rate-limit helpers in `lib/rate_limit_helpers.php`

When changing any shared helper, test both the desktop route and the matching
API endpoint.

## API Rules

- API writes require an authenticated session unless the endpoint is public by
  design, such as registration.
- API writes require CSRF validation with `X-CoreBB-CSRF` or an accepted body
  token field.
- API registration does not auto-login. Users must verify email before login.
- API endpoints should not duplicate permission checks or SQL behavior when a
  shared CoreBB helper already exists.
- If desktop behavior is embedded as a page side effect, prefer extracting a
  shared helper before adding API support.

## Mobile Scope

The first mobile API scope includes:

- Auth: CSRF, register, login, logout, and current viewer
- Read-side forum data: index, board, thread, profile
- Posting: preflight, reply, new topic, edit
- Private messages: folders, message detail, send, mark read
- Basic moderation: lock/unlock topic, remove/restore post, ban/unban user

The first mobile API scope intentionally excludes:

- User private-message delete, because desktop PMs are indelible to users
- PM moderation/removal, because it is advanced moderation
- Admin tools and site-management actions
- Archive import tooling
- Maintenance/database/schema actions
- Spam ratings generation

## Compatibility Notes

The classic desktop UI remains the primary legacy-compatible surface. API
clients should treat API responses as versioned contracts, while desktop helpers
remain shared system contracts.

When a behavior change is intentional, update this document and test the
affected desktop and API paths together.
