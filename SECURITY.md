# Security Policy

Bonumark Stream is a self-hosted PHP/MySQL microblog CMS designed to run across conventional shared hosting and server-managed PHP environments.

## Supported versions

| Version | Supported |
|---|---|
| 0.7.x | Yes |
| 0.6.x | Upgrade source only |
| 0.5.x | Upgrade source only |
| 0.4.x | Upgrade source only |
| Earlier development builds | No |

## Reporting a vulnerability

Do not open a public issue for a suspected security vulnerability. Use GitHub private vulnerability reporting for the Bonumark Stream repository. Repository maintainers should enable that GitHub setting before publishing the release. Include a clear reproduction path, affected version, impact, and any safe mitigation you identified.

## Security model

- Admin routes require login and capability checks.
- Mutating admin actions use CSRF protection.
- Public comments use CSRF protection and are rate limited per account and per network. The owner can hold a new commenter's first comment for review.
- Public likes are unauthenticated but rate-limited.
- Sign-in attempts are throttled per username and network, and per network across usernames, with a delay that doubles after each further failure. A username is never locked by itself, so failures from one network do not block the account elsewhere. Remembered devices are not throttled.
- Each signed-in session is bound to the account's credentials. Changing or resetting a password signs out every other session of that account, and Account offers Sign out all other devices. The session that makes the change stays signed in.
- Each email address belongs to one account. Changing your own address needs your current password, and a new address takes effect only after its confirmation link is opened.
- Verification links work only for accounts that are still waiting for email verification.
- Registration is disabled by default unless enabled in settings.
- SVG uploads are blocked.
- Theme packages are code-free. PHP, JavaScript, HTML files, server configuration files, symlinks, and executable code are rejected during theme ZIP installation.
- `_bonumark_stream/` and `scripts/` are protected by `.htaccess` on Apache and LiteSpeed; the package also ships a maintained Nginx configuration example.
- Remote Posting API tokens are scoped and stored as hashes.
- The SMTP password is stored encrypted with a key derived from the installation security salt in `config.php`, and no export includes it.
- Public sign-in shows the same message for an unknown, pending, or inactive account and a wrong password, and every attempt counts toward the throttle. Resetting your own password in Admin needs your current password.
- An upgrade interrupted after its database migrations begin resumes only with the exact release ZIP that started it, unless the owner explicitly accepts a different package.

Nginx deployments should start with `docs/server/NGINX.md` and the shipped configuration example. Other web servers must provide equivalent deny rules for private folders, `scripts/`, and config files. Shipped test scripts are CLI-only and must not be exposed through the web server.

## Account model

Bonumark Stream uses two account types: Admin, the sole publisher and site manager, and Commenter, for comment participation and profile/account features. Commenters cannot publish posts, upload media, or access the admin publishing system.

## Remembered devices

The Remember this device option uses persistent device tokens instead of extending the normal PHP session. Token validators are stored hashed in the database, cookies are HttpOnly and SameSite=Lax, Secure is used on HTTPS, tokens rotate when reused, and remembered devices are revoked on logout, password changes and resets, and Sign out all other devices.

## Locked-down deployment boundary

Bonumark does not require the web/PHP process to own or replace application code. When System Check reports that web-based software upgrades are unavailable, keep the application tree locked and use `php scripts/deploy-update.php /path/to/release.zip` as the application owner when shell access is available. The helper uses Bonumark's normal package validation, backup, rollback, migration-recovery, and verification code but never invokes `sudo`, installs a privileged daemon, uses setuid behavior, or elevates the web runtime. Hosts without shell access can use the application-owner/hosting-layer fallback workflows under `docs/server/` rather than broadening PHP write access.

Manual deployments that introduce database migrations must use the owner-run migration workflow after a verified external database backup. Because MySQL/MariaDB DDL can auto-commit, do not roll application code backward after a migration phase may have begun; resume the same target release instead.
