# Media reference check

Source review cannot tell whether `/wp-content/uploads/2026/10/Aztec_Wall-480w.webp`
exists. Two naming conventions live on the site at once — the pipeline writes
`aztec-wall-480w.webp`, the converted templates address the old shop's
`Aztec_Wall-480w.webp`, and the importer put both in `uploads` — so a path can
look right and still 404.

The authority is the site, not any manifest in the repository. `data/media-final/
site-manifest.json` does not list the vendor-named files at all, even though the
server serves them; a test built on that manifest reported 15 photographs as
missing that all return 200.

Run this against the site the templates will be deployed to:

```sh
python3 tools/audit/media_refs.py --base https://metal-divider.com
```

Exit code 1 when any referenced file is not served, with the template that
mentions it.

## What it has caught

- **2026-10-28** — 23 `-768w` candidates on the home page. The `-480w` sibling of
  every one of them existed; only the 768 tier was never produced. Because the
  cards declare `sizes="(min-width: 640px) 33vw, 100vw"`, a desktop at 2x picks
  the 768 candidate over the 480, gets a 404, and renders an empty tile:
  `complete: true`, `naturalWidth: 0`, `currentSrc` pointing at the 404,
  `decode()` throwing `EncodingError`. The fix removed those candidates so the
  browser falls back to the tier that exists; verified on the dev instance
  (`naturalWidth: 403`, `decode(): ok`).
- **2026-10-28** — `Long_Island_Fence-480w.webp`, one spelling drift between the
  pipeline and the template, which left a whole pattern card with one dead image.

## Rules it learned the hard way

- **A `<picture>` writes its `<source>` elements back to back inside one
  attribute, separated by `|`.** A regex that does not stop at the pipe glues
  three real URLs into one string and reports a 404 that does not exist. The
  first run of this checker reported 49 failures; 25 of them were that.
- **`loading="lazy"` and "broken" are different states.** An image the browser
  has not fetched is not broken. An image reporting `complete: true` with
  `naturalWidth: 0` is broken, lazy or not.
- **A zero-size image box is unrendered, not broken** — that is what a hidden
  slide looks like.

## What it does not cover

Only paths written literally into the templates. A URL assembled at runtime by
PHP (the pattern card partials, the PDP gallery) never appears here, so the
per-page sweep in `tools/audit/sweep.mjs` remains the check for those.
