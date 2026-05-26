# Security Policy

Bonumark Stream is pre-1.0 software. Security reports are taken seriously, but this project is not yet offered as a production-supported public platform.

## License and supported use

Bonumark Stream is licensed under `AGPL-3.0-or-later`. Security fixes should remain compatible with that license. Commercial installation, support, training, and custom development services may be offered separately, but those services do not change the software license.

## Supported versions

Only the latest development release is reviewed for security fixes.

| Version | Status |
| --- | --- |
| 0.3.x latest | Reviewed |
| older 0.3.x releases | Not maintained |
| 0.2.x and older | Not maintained |

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability.

Send a private report to the project maintainer with:

- affected version
- affected file or feature
- reproduction steps
- expected impact
- relevant logs or screenshots
- whether the issue requires authentication

Do not include live credentials, private user data, database dumps, or full private content exports unless specifically requested through a secure channel. Database and full export ZIPs are private backups that may contain password hashes, account records, security logs, invites, reset tokens, and email addresses.

## Security-sensitive areas

Please give special attention to:

- authentication and sessions
- CSRF protection
- password reset tokens
- public registration and invites
- media uploads
- importers
- theme ZIP installation
- admin ZIP upgrades
- path traversal and archive extraction
- database migrations
- static site export output

Public likes are anonymous public interactions. They do not require CSRF tokens because they are intentionally available to public visitors, but they are rate-limited and should not expose internal exception messages.

## Disclosure

The maintainer will review valid reports, prioritize fixes, and include a security note in the changelog when appropriate.


## Server configuration assumptions

The release package includes Apache `.htaccess` files to deny direct browser access to `_bonumark_stream/`, but security still depends on the web server honoring those rules. Hosts using Nginx, IIS, reverse proxies, or custom control-panel routing must apply equivalent deny rules. Treat a publicly browseable `_bonumark_stream/config.php`, backup, export, or temporary file as a critical misconfiguration.

## Trusted code boundaries

Admin ZIP upgrades and theme ZIP installation are trusted-code operations. Only administrators should use them, and only packages from controlled sources should be installed. Theme templates are PHP code and are not sandboxed.

## Remote fetch boundaries

Link previews and remote media import reject private/reserved hosts and require cURL for pinned remote fetches with connected-IP validation. Report any bypass that causes the server to fetch private network, loopback, metadata-service, local file, or non-HTTP resources.
