# Manual theme deployment

Use this workflow when **Admin → System Check → Theme ZIP installation** reports that PHP cannot write the theme directories.

A locked-down installation can run and activate themes normally while keeping theme installation outside the PHP-FPM/web process.

## Theme storage boundary

A deployed theme uses two locations:

- Private theme package: `_bonumark_stream/themes/<slug>/`
- Public declared assets: `assets/themes/<slug>/`

The private package contains `theme.json`, optional documentation, and any declared private Layout Schema JSON. The public directory contains only the assets declared by the manifest, such as CSS, images, fonts, and the screenshot.

Do not make the full Bonumark application tree writable just to use the Admin ZIP uploader.

## Preferred distributable package layout

For themes intended to support both Admin ZIP installation and manual deployment, use the canonical Bonumark layout:

```text
_bonumark_stream/
  themes/
    example-theme/
      theme.json
      README.md
      layouts/
        home.json
        profile.json
        site-header.json
        stream-card.json
assets/
  themes/
    example-theme/
      assets/
        css/
          theme.css
        images/
          screenshot.svg
```

Only files actually declared by `theme.json` should be copied to the public asset directory. Private layout JSON should remain private.

## Install or update through SSH/SFTP/control panel

1. Back up the existing theme directories when updating an installed theme.
2. Read `theme.json` and confirm the intended theme slug.
3. Copy the private theme package to `_bonumark_stream/themes/<slug>/`.
4. Copy the declared public assets to `assets/themes/<slug>/`.
5. Preserve the site's existing ownership model. PHP only needs read access to an externally deployed theme.
6. Open **Admin → Appearance**.
7. Run **Theme Health** for the theme.
8. Activate the theme only after Theme Health passes.

When updating a theme, replace that theme's two directories deliberately. Do not delete unrelated themes.

## Simple single-folder theme ZIPs

The Admin uploader can accept a simple single-folder theme ZIP and stage only its manifest, declared layouts, documentation, and declared assets into the two runtime locations. A manual deployment cannot safely infer that split by blindly unzipping the package into the live site.

For locked-down hosts, theme authors should prefer the canonical two-root distributable layout above, or the administrator should manually place only the files declared by `theme.json` into the correct private/public locations.
