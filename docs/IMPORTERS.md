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

## Bonumark export and import are content-only

**Admin → System → Export** and **Import** move content between Bonumark Stream sites. They are not a backup. For full recovery, keep server backups of the database and the site files, including `_bonumark_stream/config.php` and `media/`, and restore those.

A **Full** export, imported into another site with **Use detected status** and **Preserve imported dates when available**, restores:

- Stream Posts and Pages, published, draft, and scheduled, with their slugs, titles, descriptions, tags, and bodies.
- Dates and publication times. Each published post keeps the instant it shows publicly, including a post published from a schedule.
- The SEO title and robots setting, link previews, Local Places locations, photo galleries, and pin state with its pin time.
- Every media library file in the export, including files no post uses and trashed items, at its original path with its original file name, description, caption, and responsive image sizes.

A **Markdown** export carries the same posts and Pages and the media library details, but not the media files. Use a Full export to move media.

Import does not restore comments, commenter accounts, settings, analytics, Profiles, the Local Places directory, or ActivityPub state. Database and Full exports include a private SQL copy of database records for reference; Import never runs it, and it never includes the SMTP password.

Details that matter when restoring:

- **Scheduled posts.** A scheduled post whose time is still ahead stays scheduled. One whose time is missing or has already passed imports as a draft, and the import result names it.
- **Publication times.** Sites upgraded from before v0.5.23 read older stored times as site-local clock values, and a fresh install records its install time as the start of UTC storage. Import moves that start back to the earliest imported time when no stored time would change meaning, so every imported time reads correctly. When it cannot, it stores the local clock value that reads back as the same instant, and names any time it could not store exactly.
- **Media.** A file whose original path holds a different file on this site gets a new name, and the posts that use it follow. An image the export does not mark as privacy-cleaned is cleaned again under this site's media privacy mode. Profile and account media is not restored. Files larger than this site's upload limit are skipped with a warning.
- **Local Places.** Posts keep their location text and place reference; the place directory itself, with its private coordinates, is not exported. Bonumark keeps a new place from ever taking an imported place's id.
- **Size.** The import file must fit the import upload limit (128 MB, and the host's PHP upload limits). A Full export also contains a static copy of the media, so a site with more media than that should move its media with a server backup.
- **Repeating an import** with **Skip duplicate slugs** adds no duplicate posts or media.
- **Older exports.** An export made before this change has no publication times, pin state, or media library details. Its published posts take their time from the saved creation time, and its media files are restored under their file names.

