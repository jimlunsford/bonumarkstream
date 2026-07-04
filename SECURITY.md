# Security Policy

Bonumark Stream is a shared-hosting-friendly PHP/MySQL microblog CMS built on the v0.4.0+ clean-break foundation.

## Supported versions

| Version | Supported |
|---|---|
| 0.5.x | Yes |
| 0.4.x | Upgrade source only |
| Earlier development builds | No |

## Reporting a vulnerability

Do not open a public issue for a suspected security vulnerability. Use GitHub private vulnerability reporting for the Bonumark Stream repository. Repository maintainers should enable that GitHub setting before publishing the release. Include a clear reproduction path, affected version, impact, and any safe mitigation you identified.

## Security model

- Admin routes require login and capability checks.
- Mutating admin actions use CSRF protection.
- Public comments use CSRF protection.
- Public likes are unauthenticated but rate-limited.
- Registration is disabled by default unless enabled in settings.
- SVG uploads are blocked.
- Theme packages are code-free. PHP, JavaScript, HTML files, server configuration files, symlinks, and executable code are rejected during theme ZIP installation.
- `_bonumark_stream/` and `scripts/` are protected by `.htaccess` on Apache and LiteSpeed.
- Remote Posting API tokens are scoped and stored as hashes.

Nginx and other non-Apache servers must add equivalent deny rules for private folders, `scripts/`, and config files. Shipped test scripts are CLI-only and must not be exposed through the web server.

## Account model

Bonumark Stream uses two account types: Admin, the sole publisher and site manager, and Commenter, for comment participation and profile/account features. Commenters cannot publish posts, upload media, or access the admin publishing system.

## Remembered devices

The Remember this device option uses persistent device tokens instead of extending the normal PHP session. Token validators are stored hashed in the database, cookies are HttpOnly and SameSite=Lax, Secure is used on HTTPS, tokens rotate when reused, and remembered devices are revoked on logout and password changes.
