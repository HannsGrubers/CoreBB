# CoreBB

CoreBB is a self-hosted forum built with PHP and MySQL. If you remember the old Vault Network boards, you’ll recognize some of their DNA here. Pick a board, start a topic, and the conversation is still there for the next person who comes looking for it.

[Visit the live forum](https://corebb.net/forum/) · [Explore the themes](https://corebb.net/#interface)

![The Workshop Forums board directory using CoreBB’s Signal theme](https://corebb.net/corebb-assets/themes/signal-cover.jpg)

## Around the boards

Classic VN EOL keeps the compact rows and familiar layout of the old boards. Signal, pictured above, gives the same boards a different layout. You can switch themes and keep every topic, reply, and member account in place.

Most boards are built around conversations. A Knowledge Base board works a little differently. You can write a guide, give it a category, and let readers ask questions in its discussion tab. When a reply turns up a better answer, you can edit the guide so the next reader finds it first.

Moderators can review reported posts, answer members privately, and handle ban appeals. If a board has run its course, you can make it a read-only archive and keep years of discussions available.

## Run your own forum

You’ll need PHP 8.1 or newer with PDO MySQL, an empty MySQL or MariaDB database, and a web server configured for CoreBB’s routes. On Apache, enable `mod_rewrite` and allow the included `.htaccess` file. The PHP dependencies are included in `vendor/`.

1. Download or clone this repository and copy its contents to the directory where you want the forum to live. Include the hidden `.htaccess` file.
2. Open `/install/` in your browser. If you put CoreBB under `/forum/`, open `/forum/install/` instead.
3. Enter your database details and create the first administrator account. The installer creates a General Discussion board and a starter topic. Follow its instructions for saving the private configuration outside the web root.
4. Sign in and open **Admin → Mail Services** to set up email verification and password recovery.

Already running CoreBB? Open **Admin → Updates** to check for releases and plan an upgrade from your installed version. [Version history](VERSION_HISTORY.md).

## Help and feedback

Questions and bug reports are welcome on the [CoreBB forum](https://corebb.net/forum/). If you’ve found a security issue, please use one of the private reporting channels in the [security policy](SECURITY.md).

CoreBB is released under the [GNU General Public License, version 2](LICENSE).
