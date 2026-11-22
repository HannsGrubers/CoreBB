# CoreBB Version History

## CoreBB 1.1.12-rc.13

Date: 2026-11-21
Type: Release candidate
Update package base: 1.1.12-rc.12

This release updates all seven Moderation tools in the admin panel. It uses the same database schema as rc.12.

- Private Message History lists message subjects, participants, and mailbox status. Staff with history access can open an individual message and return to their search. A removed-only filter helps administrators find messages to restore.

- Private Message Reports separates open reports from handled decisions. Staff can read the complaint and the reported message together, then close the report or remove the message from both mailboxes. Removal also closes other open reports for that message.

- Contact Mods Inbox opens each member message in its own review. Staff can reply by private message, close with a staff-only note, or reopen a handled request. Sending a reply and closing the request are saved together.

- Mod Post Requests groups open and handled reports by severity. Staff can review the report alongside the message, close or reopen it, or confirm removal of a reply or an entire topic. Returning to the queue retains the filters and page.

- Unban Requests separates pending appeals from past decisions. Staff can review the appeal and account details together, then confirm approval or denial with an optional member-visible response. Approval records the decision and lifts the ban in one transaction.

- Unban User lists and filters banned accounts with paginated results. The review shows the original ban and the role the member will have after the ban is lifted. An optional staff note goes in the action log.

- Ban User separates member lookup from confirmation. The review identifies the account, explains any role demotion, and tells staff where their reason appears. Saving rechecks staff permissions and the member’s current role.

## CoreBB 1.1.12-rc.12

Date: 2026-11-21
Type: Release candidate
Update package base: 1.1.12-rc.11

This release updates all seven Content tools in the admin panel. It uses the database schema installed by rc.11.

- Admin Action Log shows the action, staff member, and timestamp in compact rows. Expand an entry for its recorded details, or narrow the log by staff member, IP address, or action type while keeping other filters.

- Spam Ratings shows posting totals and the period leaderboard before staff copy the full BBCode report. The board picker and generated reports respect private-board access.

- Deleted Posts lists the subject, author, and deletion date in compact rows. Expand a row to review its message, deletion reason, and restore options. Permanent deletion has a separate confirmation, and actions retain the current board filter and page.

- View a Message keeps the ID lookup above the post and shows the board, topic, author and latest edit together. Staff can expand the editor or confirm a move to Deleted Posts from the message.

- Manage Icons centers each avatar above a filename caption, with approval and usage visible at a glance. Staff can open filtered views from the library counts, approve icons from the gallery, and return to the same filtered page after a change.

- Forum Statistics distinguishes activity during the selected dates from current topic and post counts. Board drill-downs retain the date range, and the daily report follows calendar dates across daylight-saving changes.

- Manage Boards displays the full forum order with move arrows next to each category and board. After a move, the editor returns to the moved entry. Reordering respects secure archive boundaries and checks whether the neighboring entry has changed.

- After a Development Git deployment, staging footers show the installed release and identify the development preview.

## CoreBB 1.1.12-rc.11

Date: 2026-11-21
Type: Release candidate
Update package base: 1.1.12-rc.10

This release updates the People tools and adds staff-managed username appearance presets. The database migration creates the preset catalog and adds an indexed, optional preset assignment to member accounts. Existing members keep their current styling until staff assign a preset.

- Staff can name username appearance presets, assign them to members, and update every linked member’s colors and formatting together. Linked members can see their assigned preset in User CP. Unlinking keeps their current appearance and restores their usual editing permissions.

- Edit User Appearance previews username colors, formatting, and borders on light and dark backgrounds as staff edit. Clearing a custom style requires confirmation, and an outdated form prompts staff to review the latest settings.

- User IP Check and Host Address Lookup share the People layout. Staff can see whether an address matched an account, a post, or both, including older hyphenated IPv4 records. Results link to the member tools each staff account can access.

- User’s Admin Notes uses a single member lookup and a paginated staff-only history. Staff can read older notes, add a categorized note, and refresh after saving without posting it twice.

- Assign Title uses the People member lookup and can preview board-code formatting before saving. Clearing a staff title preserves the member’s personal title. An outdated form prompts staff to review the current title.

- Edit User’s Profile groups personal details, work and contact, and picture and bio into independently saved sections. The editor preserves omitted fields and flags edits made since the form was opened.

- Updates uses section cards for Releases, Plan upgrade, and Package review. The catalog highlights the installed release and aligns versions, channels, dates, and available actions.

- Change User’s Password uses the member lookup and confirmation layout from the People tools. A password change and revocation of that member’s remembered sign-ins commit together. Refreshing the confirmation does not repeat the change.

- Add User shares the role cards from Edit User Rights and opens a creation receipt with links to the new member. Refreshing the receipt does not resubmit the form.

- Latest Users has status filters with account counts across all pages. User Pages groups role and direct grants, account standing, and activity in the overview and adds a section menu for each member.

- Edit User Rights displays side-by-side role choices and a searchable permission table with a dedicated direct-grants view. The admin stylesheet URL changes when the file changes so browsers can load the matching layout.

- Edit User Rights previews role changes and lists the individual tool grants being added or removed. Saving applies the reviewed changes together and checks that the account’s rights are still current.

- Latest Users shows newest accounts in expandable rows, with compact pagination and permission-aware access to IP addresses, notes, and moderation tools.

- User Pages has one username or ID lookup and separate views for account details, profile content, recent posts, and private staff notes. Staff links and sensitive details follow the viewer’s tool permissions.
- Recent posts on User Pages now show compact, expandable rows with the subject, board, and date. Open a row to read the formatted post and reach its forum or admin links.

## CoreBB 1.1.12-rc.10

Date: 2026-11-21
Type: Release candidate
Update package base: 1.1.12-rc.9

- Forum Sim-Test separates simulation setup from forum-wide cleanup, previews the workload as settings change, and checks the simulator account as well as the thread title before removing content. Refreshing a result page does not repeat a run or cleanup.
- Generated posts now start with an explicit edit flag so simulated moderator edits work on strict-mode databases.

- API Explorer now groups presets and custom GET requests in one request bar. Responses show the requested endpoint, HTTP status and elapsed time, with raw JSON and copy controls.

- Updates → Plan upgrade finds a compatible route to a selected stable release or candidate from the signed catalog. After backup confirmation, CoreBB validates and applies one ZIP per request, verifies the installed version and resumes from its saved route. Administrators can pause between packages.
- Release Manager includes each new package’s starting-version and PHP requirements in the signed feed. New incremental releases must build on the newest published version so installations can keep following the chain.

- Updates separates the release catalog from package review. Administrators can see the recommended next package, inspect file changes and preflight checks, and recover an interrupted upgrade. Refreshing after a successful check, upload, or sideload shows the result without repeating the action.

- Schema Deploy is now an administrator-only tab in Database Tools. Preview schema additions from dumps or database connections, then confirm an apply against the destination database. The previous Schema Deploy address still works.

- Database Tools groups count and search maintenance on one tab and private SQL backups on another. Maintenance results include expandable details, failed count rebuilds no longer record a successful run, and refreshing a result page does not repeat the operation.

- Admin now has one Global Messages page to publish, edit, and remove forum-wide announcements. Existing staff grants still apply to each action.
- Terms of Service and the default theme are under System Settings. The standalone terms page and raw stylesheet editor have been retired.

- Mail Services separates delivery settings from test messages and groups SMTP and diagnostic options. Test delivery shows the sender, redirect, and blind copy, and reports when outgoing mail is disabled.
- CoreBB’s SMTP transport now keeps the diagnostic blind-copy address out of the delivered message headers.

- Authentication Settings has an independently saved form for each sign-in provider, with credential sources, setup addresses, and troubleshooting together. Blank secret fields keep the stored secret.

- Guest browsing now controls anonymous access to forum content in the browser, mobile client, and API. Sign-in and registration remain available on members-only forums.
- Custom member titles use the plain-text title in User CP → Edit Profile. Disabling the option hides personal titles and prevents member edits while preserving stored titles. Staff-assigned post titles retain priority.
- Maintenance mode serves the configured notice with HTTP 503 and keeps staff sign-in, Admin, and recovery accessible. Upgrades use a separate maintenance flag so they preserve the owner's notice and manual maintenance state.
- System Settings retires the old VN EOL, wrapped layout, quick reply, and post markup switches. The maintenance notice and terms editors fill the settings area.

- System Settings groups appearance, forum options, rate limits, maintenance, and terms into separately saved sections. Rate-limit rules pair their maximum with the window in seconds. Invalid submissions show an error before changes are written.

- A green check marks a verified, up-to-date dashboard. Version History shows compact expandable release rows, package prerequisites, and formatted notes.

- Recent activity uses compact Action, Staff, and When columns on wide dashboards and wraps each event into a short summary on narrow screens.

- Admin has a searchable, permission-aware tool list, a compact forum snapshot, and one role-filtered activity feed. The dashboard distinguishes the latest stable and security releases and calls out failed checks and known security updates. Account-menu logout uses the existing CSRF-protected POST flow.

## CoreBB 1.1.12-rc.9

Update package base: 1.1.12-rc.8

- Signal’s masthead pairs the forum name with today’s post count, community totals, and forum search. The activity figures follow the forum’s existing basic-statistics setting.

- Signal gives forum replies and Knowledge Base discussions a roomier reading layout. Reply and Quote are directly accessible on writable posts. Signatures and author details expand on demand, and replies with their own subjects keep their headings.

- Signal uses dedicated wide and compact vector tapestries. The strands retain their proportions on high-density displays and carry light from the masthead into the board directory.

- Signal now has a category sidebar, a filterable board directory, account navigation, and a conversation reader with inline author details and post actions.

- Public themes can supply individual Twig pages, layouts, and partials with shared templates as the fallback. Signal's board index branches from the tapestry into categories and boards, including favorites, unread activity, and Secure Archives.

- Signal now carries the homepage tapestry into the forum. Light travels from the masthead into the navigation, connected markers distinguish boards with new posts, and thread replies and Knowledge Base tabs use the same visual cues. The motion control remembers the reader’s choice across pages and follows their reduced-motion preference.

## CoreBB 1.1.12-rc.8

Date: 2026-11-20
Update package base: 1.1.12-rc.7

- Added CoreBB Signal, a charcoal-and-copper public forum theme with an animated host header, a motion control, and support for reduced motion. Select it in Edit Forum Options or set it as the forum default.
- VN EOL uses compact board-style rows on login, registration, account recovery, verification resend, and search pages. Search filters sit together above the results, with native radio choices and controls sized to their contents.
- The admin updater opens the completion page in a fresh request after a successful upgrade. This keeps the previous release's PHP renderer from loading the new release's templates and preserves the upgrade counts on the confirmation page.

## CoreBB 1.1.12-rc.7

- Browser logout requires a confirmed POST. API and browser logout both invalidate the session and clear pending account actions.
- Reset and verification mail use a configured forum address. If the installation has no trusted address, the member can enter the emailed code on the forum instead. Recovery diagnostics are restricted to authorized administrators outside production.
- New installations generate a private cookie-signing key. Legacy installations with an existing database-derived key retain it.

- Hosted forum entry points refuse cleartext sessions. The desktop, mobile, installer, and API responses set browser security headers. HTTPS responses advertise a short initial HSTS lifetime.
- CoreBB uses a per-response script nonce and external event handlers for its forum and admin templates. The mobile view preference now uses the same secure cookie flags as the session on HTTPS.
- The mobile return link accepts local paths. Draft text used by the legacy composer helpers stays in the browser tab. The Apache rules add header coverage for static files and redirects and restrict access to internal directories.
- Email verification accepts only the issued token and rechecks its hash and expiry when confirming an account. Invalid links no longer reveal whether other accounts await verification.
- The legacy tooltip page uses local JavaScript and renders labels as text. It no longer contacts an external image server.
- Existing installations preserve their local Apache configuration during updates. Merge the header and access rules from the release into the forum’s existing `.htaccess` and retain the host’s HTTPS redirect.

## CoreBB 1.1.12-rc.6 (in development)

- Manage Boards can designate a board as a Knowledge Base. Staff publish and maintain articles using the forum composer. Members can reply on a separate Discussion tab.
- Article search stays within the selected board and searches titles, summaries and opening posts. Articles support a category, applicable versions and a manually maintained verification date.
- The article index, reader, and discussion use the active forum theme’s full-width board rows, menus, and post styling.
- The article header groups the category, title, and summary together. Author, applicable versions, and last verification date appear in labeled rows beside the summary, or below it on small screens.
- The update migration adds article metadata and defaults existing boards to Discussion. Changing the board type keeps its topics and replies.

## CoreBB 1.1.12-rc.5

Date: 2026-11-19
Type: Security release candidate
Update package base: 1.1.12-rc.4

- Microsoft sign-in requires confirmation with the member's CoreBB password before linking a local account. Returning members sign in with their linked provider identity.
- Edit System Settings exposes only supported forum preferences. Authentication credentials and internal updater records are handled separately.
- The updater verifies the signed catalog before trusting a package checksum, including when using a cached catalog during a network outage. A signature failure stops package validation.
- Update ZIPs are matched to the catalog before extraction. Extraction limits the entry count and expanded size, cleans up rejected packages, and protects files inside site-owned directories. Validated package details are saved in private storage.
- Bans now apply before protected API reads and writes, including private messages and moderation actions. Members can still check their account status and log out.
- Recovery email changes require the current password and a confirmation code sent to the new mailbox. The previous address receives a notice and outstanding password resets are invalidated.
- Password registrations save the account and pending verification together. A verification storage failure blocks sign-in, and failed mail delivery leaves the account pending.
- The classic VN header now uses the CoreBB charcoal-and-orange artwork, with separate left, center, and right images over the horizontally repeating background.

### Upgrade notes

- After installation, use **Check Now** to refresh the signed catalog. Upload or sideload a package again if it was staged by an older updater.
- Registration requires transactional storage for the `users` and `email_verifications` tables. New installs already use InnoDB.

## CoreBB 1.1.12-rc.4

Date: 2026-11-19
Type: Board topic ordering

### Changed

- Board pages sort pinned and ordinary topics by their last activity before pagination. Older dates with single-digit months or days now sort chronologically alongside zero-padded dates.

## Unreleased

Date: 2026-11-18
Type: Registration abuse prevention

### Changed

- Browser and API password signups now share the same per-IP registration limit. Each submission that reaches the registration handler counts once. Both routes return HTTP 429 and a Retry-After header when the limit is reached.

### Added

- The corebb_registration_events table records each handled signup submission with its UTC timestamp, route, and outcome. When a submission creates an account, the event includes the new user ID.

### Verified

- Staging deployment 483 verified the shared limit for commit e2f01f9. After one empty browser submission and one empty API submission, the next browser submission returned HTTP 429 with a 600-second retry interval.

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
