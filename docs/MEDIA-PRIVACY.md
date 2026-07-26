# Privacy-Safe Media Uploads

Bonumark Stream randomizes public filenames for newly uploaded media and attempts to remove metadata from supported image uploads. This is designed for normal shared hosting and does not require shell tools, SSH, Composer, npm, background workers, or server packages.

## What happens on upload

- The original filename is not used as the public media filename.
- The public file is saved under a randomized name in `/media/YYYY/MM/`.
- JPG, PNG, and WebP images are re-encoded when the server supports the needed PHP image functions. Re-encoding saves new pixel data and normally removes embedded metadata such as camera, device, date, editing, and location metadata.
- GIFs and other unsupported image formats are still saved with randomized public filenames in best-effort mode, but Bonumark Stream warns when metadata removal cannot be confirmed.

## Best-effort mode

Best-effort mode is the default. It keeps Bonumark Stream usable on common shared hosting. If metadata removal succeeds, the media item is marked as cleaned. If metadata removal cannot be confirmed, the upload is still saved with a randomized public filename and the admin media library shows a warning.

## Strict privacy mode

Strict privacy mode is optional and can be enabled from **Admin → Settings → Writing**. In strict mode, image uploads are rejected when Bonumark Stream cannot confirm metadata removal on the current server.

## What this does not do

- It does not require or call command-line tools such as `exiftool`, `jpegoptim`, or ImageMagick CLI.
- It does not rewrite existing media files during upgrade.
- It does not remove metadata from already uploaded legacy files.
- It does not hide admin-only original filename records from administrators.

Existing media remains preserved. Newly uploaded media receives a privacy status in the media library.
