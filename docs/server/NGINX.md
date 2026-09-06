# Nginx deployment

Bonumark Stream supports Nginx with PHP-FPM when the server provides the same two guarantees supplied by the bundled Apache/LiteSpeed `.htaccess` files:

1. Bonumark clean routes are mapped to `index.php` with the correct `__bonumark_route` values.
2. Private application storage and shipped CLI tools are not reachable over HTTP.

The package includes [`bonumark-stream-nginx.conf`](bonumark-stream-nginx.conf) as a maintained starting point.

## What to change

Do not copy the example unchanged. Set the values that belong to the host:

- `server_name`
- `root`
- `fastcgi_pass`
- TLS/certificate directives
- `client_max_body_size` when the intended upload policy differs from the example
- log paths or other host policy, when needed

Nginx can reject a request before PHP sees it. Keep `client_max_body_size` above the intended Bonumark media file limit and large enough for multipart request overhead. **Admin → System Check → Media upload ceiling** reports the PHP/Bonumark limit, but PHP cannot detect a lower reverse-proxy or web-server request ceiling.

The example assumes Bonumark is installed at the document root of its own virtual host. For a subdirectory install, prefix the route and deny locations with that subdirectory or use a dedicated virtual host.

## Required protections

At minimum, Nginx must reject direct HTTP access to:

- `/_bonumark_stream/`
- `/scripts/`
- hidden files such as `.gitignore` and `.htaccess`

The example also refuses PHP execution from `/media/` as defense in depth.

After configuration, these checks should not return HTTP 200:

```sh
curl -sS -o /dev/null -w "%{http_code}\n" https://example.com/_bonumark_stream/VERSION
curl -sS -o /dev/null -w "%{http_code}\n" https://example.com/scripts/smoke-test.php
```

HTTP `403` or a deliberately protected `404` is acceptable.

## Required routing checks

At minimum, verify:

```sh
curl -sS -o /dev/null -w "%{http_code}\n" https://example.com/
curl -sS -o /dev/null -w "%{http_code}\n" https://example.com/stream
curl -sS -o /dev/null -w "%{http_code}\n" https://example.com/feed.xml
```

The home page and `/stream` should return HTTP 200 on a working site. Feed behavior depends on site state but must route through Bonumark rather than an Nginx filesystem 404.

## Authentication and federation headers

The Remote Posting API uses bearer authentication. The example explicitly forwards the incoming `Authorization` header to PHP-FPM:

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

Keep this directive if Remote Posting is used.

ActivityPub inbox verification also depends on `Signature` or `Signature-Input`, plus `Digest` or `Content-Digest`. The example explicitly forwards those headers to PHP-FPM:

```nginx
fastcgi_param HTTP_SIGNATURE $http_signature;
fastcgi_param HTTP_SIGNATURE_INPUT $http_signature_input;
fastcgi_param HTTP_DIGEST $http_digest;
fastcgi_param HTTP_CONTENT_DIGEST $http_content_digest;
```

Keep the original `Host`, `Content-Type`, and `Content-Length` request values as well. Nginx normally passes them through the standard FastCGI parameters, but a reverse proxy or customized include must not remove or rewrite them. Bonumark rejects an inbox request when the signed host, body length, or digest no longer matches what PHP receives.

## File permissions

Nginx does not require the PHP-FPM user to own Bonumark application code. A locked-down deployment can keep package-managed software read-only to PHP while granting PHP write access only to the runtime paths reported by **Admin → System Check**.

In that model:

- normal publishing remains supported;
- web-based software upgrades report that the owner-run CLI workflow is available;
- Admin theme ZIP installation may also require manual deployment if the theme directories are read-only.

Do not make the whole application tree writable merely to silence those capability warnings. For software updates with shell access, run `php scripts/deploy-update.php /path/to/release.zip` as the application owner. Use the [manual software deployment](MANUAL-DEPLOYMENT.md) fallback when shell access is unavailable, and use the [manual theme deployment](MANUAL-THEME-DEPLOYMENT.md) workflow when theme installation is intentionally locked.

## Validate from Bonumark

After installing or changing the Nginx configuration, open **Admin → System Check**. Confirm that:

- **Web server** identifies Nginx;
- **Private folder exposure** passes;
- **Public URL mode** passes;
- all required runtime directories pass.

Capability warnings for web-based upgrades or theme ZIP installation can be intentional on a locked-down deployment; the owner-run CLI software upgrade remains supported.
