# Bonumark Stream Importers

Importers bring outside content into Bonumark Stream without writing anything until the administrator confirms the preview.

## Import flow

1. Upload an import file.
2. The importer parses and normalizes records into prepared import items.
3. Bonumark stores the prepared set privately.
4. The admin screen shows a smaller preview sample.
5. Confirm Import writes the prepared content.
6. Duplicate handling, status handling, date preservation, and media behavior are applied during confirmation.

## Supported import types

- Markdown files
- Generic JSON
- WordPress WXR/XML exports
- Twitter/X archive ZIP files
- Bluesky/AT Protocol `.car` repository archives
- Bonumark Stream export ZIP files

## Large imports

Large supported imports should not require users to manually split normal archive files. Bonumark stages the full prepared set privately while keeping the preview screen manageable.

A shared safety ceiling may still exist to protect low-resource hosting from extreme imports. That ceiling is not intended to replace proper archive handling for normal exports.

## Media behavior

Media behavior depends on the source format.

- WordPress remote images can be imported, left remote, or removed during confirmation.
- Twitter/X local archive images can be staged and copied into Bonumark Media.
- Bluesky CAR exports currently import text, timestamps, hashtags, and links only. Media import is not available from Bluesky CAR exports at this time.
- Generic JSON imports preserve content fields but do not guess private media locations.

## Importer expectations

Importers should report skipped content clearly. Silent failure is worse than an honest limitation. If a source format does not provide usable media files or reliable media URLs, the importer should say so.
