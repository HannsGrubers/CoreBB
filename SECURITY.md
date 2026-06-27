# CoreBB Security Policy

## Vulnerability Reporting

Please do not report suspected security vulnerabilities in public GitHub issues.

Email security reports to security@corebb.net or use GitHub private
vulnerability reporting:

<https://github.com/HannsGrubers/CoreBB/security/advisories/new>

Please include:

- Affected CoreBB version or commit.
- Steps to reproduce.
- Proof-of-concept details, if available.
- Impact.
- Whether the issue appears to be actively exploited.
- Your preferred credit name, if you want credit.

CoreBB does not currently operate a paid bug bounty program. Responsible
vulnerability reports are appreciated, and reporter credit may be included in
release notes when requested.

## Scope

Examples of security issues:

- Authentication bypass.
- Privilege escalation.
- Private board access bypass.
- Stored or reflected XSS.
- CSRF that changes account, admin, or forum state.
- SQL injection.
- Arbitrary file upload.
- Password reset or token flaws.
- Sensitive data exposure.
- Moderator or administrator permission bugs.
- Unsafe archive or private content exposure.

Usually not in scope:

- Spam or abuse that requires normal user permissions.
- Social engineering.
- Self-XSS.
- Outdated browser behavior.
- Vulnerabilities caused by modified third-party installs.
- Issues caused by deliberately weakened server configuration.

## Built-in Protections

CoreBB includes CSRF protection on state-changing form requests unless a route
has an explicit, reviewed exemption. Restricted boards, Secure Archive areas,
moderator tools, and administrator tools use server-side permission checks.

Actively maintained database access paths are expected to use shared database
helpers with prepared statements and bound parameters. Legacy paths should be
reviewed during modernization work.

Passwords use PHP's password APIs, persistent login tokens store hashed verifier
tokens, and session cookies are configured with hardened attributes where
supported by the hosting environment.

## Supply Chain and Releases

Release packages should be built from reviewed source and should not include
development-only files, test artifacts, private configuration, or local
environment secrets.

## Supported Versions

Security fixes are provided for the latest stable release. Older releases may not
receive backported fixes unless the vulnerability is severe.

## Disclosure Policy

We aim to acknowledge valid security reports, investigate them privately, prepare
a fix, and publish release notes once a patched version is available.
