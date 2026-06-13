# Security Policy

Bonumark Stream v0.5.0 is a shared-hosting friendly PHP/MySQL microblog CMS built on the v0.4.0+ clean-break foundation.

## Supported versions

| Version | Supported |
|---|---|
| 0.5.x | Yes |
| 0.4.x | Upgrade source only |
| Earlier development builds | No |

## Security model

- Admin routes require login and capability checks.
- Mutating admin actions use CSRF protection.
- Public comments use CSRF protection.
- Public likes are unauthenticated but rate-limited.
- Registration is disabled by default unless enabled in settings.
- SVG uploads are blocked.
- Theme packages are code-free. PHP, JavaScript, HTML files, server configuration files, symlinks, and executable code are rejected during theme ZIP installation.
- `_bonumark_stream/` is protected by `.htaccess` on Apache and LiteSpeed.
- Remote Posting API tokens are scoped and stored as hashes.

Nginx users must add equivalent deny rules for private folders and config files.

Report security problems privately before public disclosure.

## Account model

Bonumark Stream uses two account types: Admin, the sole publisher and site manager, and Commenter, for comment participation and profile/account features. Commenters cannot publish posts, upload media, or access the admin publishing system.
