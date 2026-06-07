# CoreBB

A message board without the bloat.

Current release: v1.0.0.

CoreBB is a PHP/MySQL bulletin board with Twig-backed public and admin templates, a compact modern admin panel, BBCode plus Markdown-in-BBCode support, private messages, blogs, moderation tools, email verification, password recovery, and a fresh-install web installer.

## Requirements

- PHP 8.1 or newer.
- PDO MySQL extension.
- MySQL or MariaDB with InnoDB and utf8mb4 support.
- Apache-compatible URL rewriting through `.htaccess`.

## Fresh Install

Upload the project files to the directory where the forum should live, then open:

```text
/install/
```

The installer creates the database tables, writes a private/local config file, creates the first administrator, and seeds the first discussion board with setup notes.

After installation, log in to the admin panel and configure mail services before relying on registration verification, password recovery, or notification mail.

More detail lives in [docs/installation.md](docs/installation.md).

## Project Layout

- `controllers/` holds public route controllers.
- `lib/` holds helpers, data access, view models, security, and formatting logic.
- `views/` holds Twig templates.
- `api/v1/` holds the JSON API front controller and API routes.
- `admin.php` is the admin front controller.
- `docs/` contains maintainer notes, API contracts, data flow, installation, and formatting boundaries.

## Development Notes

Composer dependencies are included for shared-host friendly installs. If rebuilding dependencies locally, use:

```text
composer install
```

Keep secrets, local configs, database dumps, backups, generated logs, and user uploads out of public commits. The included `.gitignore` covers the common runtime paths.

## License

CoreBB is released under the GNU General Public License version 2 only (`GPL-2.0-only`). See [LICENSE](LICENSE).

Bundled third-party dependencies remain under their own licenses in `vendor/` and `scripts/vendor/`.
