# Importers

Bonumark Stream includes importer tools for bringing owned content into the database-first publishing system.

Supported importer families:

- Bonumark export import
- Markdown import
- JSON import
- Bluesky archive import
- Twitter/X archive import
- WordPress WXR import

Importers create database-first content records. Markdown import remains an ownership and portability path, not runtime fallback storage. Historical importer development notes belong in `docs/HISTORY.md`.

## Photo gallery preservation

Markdown, JSON, and Bonumark export imports preserve an ordered `media_gallery` list of up to four images. The first gallery item is also restored as `featured_media`. Media import policy is applied to each gallery item, and existing single-featured-image imports remain unchanged.
