# CoreBB Version History

## CoreBB Initial Release v1.0.0

Date: 2026-06-07
Type: Initial public source release

Initial public release of CoreBB as a clean source repository. This release
packages the Twig-backed public forum, modern dark-first admin panel, fresh
installer, API/mobile shell, mail setup tools, database backup tool, and
release documentation into a shippable source tree.

### Added

- Added the fresh-install web installer with bundled `lib/helpers/install_schema.sql`, first-admin creation, default forum seeding, and starter setup notes.
- Added a public README, release-oriented `.gitignore`, and Git attributes for the clean source repository.
- Added GNU GPL version 2 only project licensing to prevent closed-source redistribution of CoreBB derivatives.
- Added documented developer guidance for installation, data flow, API contracts, content formatting boundaries, schema deployment, and open-source readability.

### Changed

- Updated public and admin footer labels to `CoreBB Initial Release v1.0.0`.
- Reset release-facing config and outbound mail fallbacks to generic CoreBB/installed-board branding.
- Replaced source-specific header/footer branding with generic CoreBB release assets and removed the public legal/disclaimer footer block.
- Preserved the older pre-release history below this entry as development background for maintainers.

### Security

- Kept private/local configuration, generated logs, database backups, and user uploads out of the public release commit.
- Kept upload-folder `.htaccess` protections in the release package while excluding live uploaded avatar content.
- Preserved installer config fallback behavior for hosts that cannot write outside the web root while blocking direct config-file access through web requests.

### Verified

- PHP syntax checks passed for the release-polished config and mail helper changes.
- The public release scan found no local config files, runtime logs, tool scripts, or uploaded user avatars.
- The required installer schema is tracked despite SQL dump ignore rules.

## CoreBB Stable Pre-Release v1.4.0

Date: 2026
Type: Public templating, admin modernization, and release cleanup pre-release

Pre-release milestone for the full CoreBB public presentation refactor and the
new admin control center. This release moves the public forum into Twig-backed
templates, removes large amounts of retired legacy surface area, consolidates
workflow sprawl, hardens admin permissions, and gives the admin panel a modern
dark-first interface without adding an unbounded extension framework.

### Added

- Added Twig-backed layouts, pages, and partials for the public forum presentation layer.
- Added a modern Twig-backed admin panel with compact navigation, dark-mode integration, dashboard counters, action summaries, and cleaner database/content/user/moderation tools.
- Added a private database backup action under Database Tools, storing SQL dumps outside the public web tree and listing recent backup metadata without exposing download links.
- Added dedicated admin tool permission grants so selected non-admin users can access specific tools without inheriting full administrator rights.
- Added admin user appearance, title assignment, profile editing, private board access management, icon management, unban request, PM moderation, Contact Mods, and board management view models.
- Added release-oriented documentation comments across admin functions, parameters, and return values.

### Changed

- Updated the site footer version label to `CoreBB Stable Pre-Release v1.4.0`.
- Migrated public layout, forum, thread, post workflow, profile, search, blog, private-message, notification, and board-rules/FAQ output toward Twig templates so PHP prepares data and Twig owns display markup.
- Consolidated blog and private-message actions into controller-backed routes.
- Modernized Manage Boards and private board access workflows with inline actions, clearer status badges, cleaner spacing, and safer Secure Archive handling.
- Reworked admin navigation and page structure around focused tools instead of legacy `admin.php` sprawl.
- Made admin dark mode the default while keeping public forum theme preference separate.
- Moved Terms of Service content into `systemsettings` instead of keeping a dedicated table.
- Moved legacy toolbar scripts into the scripts folder and normalized admin-only helper naming.

### Removed

- Removed deprecated public features and dead paths including Active Topics, Top Posters, obsolete TOS routing, static message lookup storage, privateboards remnants, and unused table dependencies.
- Removed public access to Twig template source files through web requests.
- Removed most public raw-HTML handoff patterns by pushing formatting into template models and late-rendered formatted-content partials.
- Removed broad legacy compatibility redirects that would keep retired routes alive without a release reason.

### Security

- Hardened admin special-access permissions so tool access does not automatically authorize unrelated page actions.
- Added and reviewed CSRF coverage across migrated public and admin forms.
- Kept database backups outside public routes and avoided admin download links for SQL dumps.
- Preserved manual restore as an infrastructure task rather than adding a risky one-click database restore action.

### Verified

- Public forum browsing, posting, post editing, search, profiles, blogs, private messages, API login, and moderation flows were exercised during migration.
- Admin pages were migrated and smoke-tested through the new admin layout, with VIP colors reserved for a later targeted admin discussion.
- PHP syntax checks and Twig render checks passed for the migrated admin and public view-model/template changes.
- `git diff --check` passed throughout the release cleanup and admin modernization work.

## CoreBB Stable Pre-Release v1.3.0

Date: 2026
Type: HTML5, theme, and mobile stabilization pre-release

Pre-release milestone for standards-compliant public markup and the first real
theme layer for the VNBoards end-of-life presentation. This release moves legacy
table presentation behavior into CSS, introduces selectable light/dark styling,
keeps the admin panel on its classic control-panel look, and hardens mobile
routing for modern Android Chrome and other mobile browsers.

### Added

- Added a shared theme stylesheet for HTML modernization utilities, public forum color tokens, dark-mode tokens, and subtle visual polish.
- Added selectable light and dark forum modes through the footer theme dropdown.
- Added public-only dark-mode palette support for VN-style forum pages, including softer row, menu, link, username, category, quote, code, and footer colors.
- Added dedicated category title and category expand/collapse styling so board category accents can be tuned independently from ordinary column headers.
- Added ASCII category expand/collapse controls in place of the legacy plus/minus GIFs.
- Added mobile redirect support for browser Client Hints and common device proxy headers.
- Added a public-page mobile fallback redirect for mobile browsers that present desktop-like user-agent strings.

### Changed

- Updated the site footer version label to `CoreBB Stable Pre-Release v1.3.0`.
- Migrated public forum output to `<!doctype html>` with HTML5 validation as the target baseline.
- Replaced obsolete table presentation attributes with valid CSS classes and inline CSS equivalents while preserving legacy spacing, padding, alignment, borders, and dimensions.
- Scoped public forum visual theming to the VN end-of-life public theme so admin pages keep the classic admin appearance while still sharing modernization utility classes.
- Softened the dark-mode public forum palette to reduce contrast while preserving the legacy VN layout rhythm.
- Tuned public category labels, default username colors, custom username rendering, breadcrumb/menu link brightness, and subtle rounded corners.
- Restored category collapse behavior for opened categories, including default-open categories.
- Improved admin reset styling so admin form labels, table headers, action logs, and nested admin tables retain classic readable contrast.
- Improved mobile routing so stale `corebb_view_mode=desktop` cookies no longer permanently suppress mobile detection on Android Chrome; explicit `view=desktop` still works.

### Removed

- Removed legacy HTML 4.01 Transitional document identification from public forum output.
- Removed public category plus/minus image dependencies for category open/closed controls.

### Notes

- The legacy VN public theme remains the active public presentation. Light and dark mode now apply over that theme instead of requiring separate hardcoded table designs.
- Admin pages intentionally remain classic and square-edged; public theme changes are scoped away from the admin shell.
- Mobile remains web-based and continues to use the API-backed mobile shell introduced in v1.2.0.

### Verified

- Public forum pages were checked against the Nu HTML validator during the HTML5 migration.
- Live staging validation reached a clean no-errors/no-warnings result after the HTML5 and table-attribute cleanup.
- PHP syntax checks passed for the edited PHP files during the theme, admin, and mobile routing work.
- `git diff --check` passed during the release-candidate cleanup.
- Android Chrome mobile routing was verified with server-side simulation for normal mobile requests, stale desktop-cookie requests, and explicit desktop override requests.

## CoreBB Stable Pre-Release v1.2.0

Date: 2026
Type: Mobile/API pre-release and forum workflow expansion

Pre-release milestone for the first API-backed CoreBB mobile experience. This release
introduces a dedicated mobile client, expands the JSON API into real end-user forum
workflows, and keeps the classic desktop forum behavior intact.

### Added

- Added the CoreBB JSON API v1 platform with health, session, forum index, board, thread, profile, posting, private-message, poll, and moderation endpoints.
- Added API guardrails with guest/authenticated rate limits, page caps, JSON error responses, and API boundary metadata.
- Added CSRF-protected API authentication for login, logout, registration, and current-session checks.
- Added API-backed posting workflows for new topics, replies, quoted replies, and post edits through the existing CoreBB post-processing path.
- Added API-backed private-message workflows for folders, message detail, sending, and marking messages read.
- Added API-backed moderator workflows for topic lock/unlock, post remove/restore, and user ban/unban.
- Added API-backed poll voting through the existing CoreBB poll helper path.
- Added an admin-only API Explorer for inspecting and testing API responses.
- Added a dedicated mobile shell with mobile browser routing and explicit `view=mobile` / `view=desktop` overrides.
- Added mobile board index, board topic list, thread view, profile view, authentication, registration, posting, PM, poll, and basic moderation screens.
- Added mobile session display showing the current logged-in user or guest browsing state.
- Added mobile thread pagination, page status, latest-page navigation, post anchors, quoted-reply flow, and thumb-oriented bottom controls.
- Added mobile post action rails using a right-aligned options control for quote/edit/remove actions.

### Changed

- Updated the site footer version label to `CoreBB Stable Pre-Release v1.2.0`.
- Kept mobile forum actions routed through existing CoreBB helper/view-model paths instead of duplicating forum control logic.
- Improved mobile thread controls by moving board-return, reply, latest, poll, and lock controls above the topic title.
- Improved mobile board controls by placing pagination and new-topic actions under the topic list.
- Improved mobile post confirmation screens with bottom-right follow-up actions.
- Improved mobile post form ergonomics by right-aligning submit actions.
- Improved mobile poll display and voting controls for finger-friendly use.
- Improved mobile topbar branding by using the configured board name with safe truncation for long names.
- Improved mobile config loading by treating the mobile shell as a valid CoreBB entry point before loading guarded configuration.

### Security

- Preserved the guarded `config.php` direct-access behavior; the mobile shell now identifies itself as a valid CoreBB entry point before loading configuration.
- Added CSRF validation to mobile/API write flows.
- Kept mobile API writes behind existing login, permission, private-board, secure-archive, moderation, and banned-user checks.
- Kept API page limits and rate limits in place to reduce open-ended scraping/data-mining exposure.

### Notes

- Mobile is intentionally web-based and does not require users to install a separate app.
- Administration tasks remain desktop/admin-panel focused; mobile scope is end-user forum activity and basic moderation.
- PM delete remains unavailable to normal users, matching the desktop forum's indelible PM behavior.
- Advanced PM moderation remains outside the mobile MVP scope.

### Verified

- API authentication, registration, post/reply/edit, PM read/send, poll voting, and basic moderation workflows were smoke-tested.
- Desktop forum workflows remained functional during mobile/API rollout.
- PHP syntax checks and mobile JavaScript syntax checks passed during implementation.

## CoreBB Stable Release v1.1.0

Date: 2026
Type: Feature and modernization release

Feature release following the initial modernization baseline, focused on admin tooling,
moderation support, simulation/testing tools, and continued CoreBB naming cleanup.

### Added

- Added an admin Version History page backed by this markdown changelog.
- Added granular special access permissions for individual admin tools.
- Added bin counters for deleted posts, PM reports, moderator post requests, and the contact mods inbox.
- Added the Forum Sim Test admin tool for generating isolated simulated users, threads, replies, polls, votes, and moderation activity.
- Added board-targeted simulation runs, separate registration/post activity spans, randomized BBCode/quote/poll behavior, and a wipe-sim cleanup action.
- Added the moderator Spam Ratings tool with postable BBCode output, username formatting, award highlighting, and poll result bars.
- Added YouTube auto-embed support to the BBCode parser.

### Changed

- Completed project-wide helper prefix normalization from legacy helper paths to CoreBB naming.
- Removed transitional compatibility alias files after local function renaming.
- Removed the legacy User.class.php bootstrap include from CookieEngine.php after confirming the current auth/user-state path no longer depends on it.
- Updated admin board-management movement icons.
- Improved profile table layout by tightening the Info column width.
- Improved profile metadata handling for profile update timestamps and admin-created registration dates.
- Improved generated Spam Ratings poll output to match the original ratings-style presentation.

### Notes

- Archive/import tooling remains preserved as legacy one-use migration support.
- Simulated forum data is isolated from archive users, forums, threads, and posts.

### Verified

- Live PHP syntax checks passed during the feature and cleanup passes.
- Forum/admin smoke checks continued to run normally after each release-candidate change.

## CoreBB Stable Release v1.0.0

Date: 2026
Type: Initial modernization release

Initial stable modernization release derived from the original unreleased 2012 development effort.

### Changed

- Established the modernized CoreBB release baseline.
- Carried forward the original board identity while updating the runtime path for current hosting.

### Notes

- This is the baseline release before the v1.0.1 internal cleanup work.
