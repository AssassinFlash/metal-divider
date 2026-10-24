# md-media — the final media pipeline

Every byte uploaded to the server is a final byte produced here
(AGENTS.md §2: zero runtime transformation). Spec: `docs/specs/15-media-pipeline.md`,
branch `research/spec-media-pipeline`. The corpus (585 MB of masters in
`data/media-raw/`, the 1420-entry `data/shopify-public/media-manifest.json`,
the export in `data/shopify-admin-export/`) lives on this Mac, never in the
repository — so `media-build` and `media-check` are local-only gates, not CI.

```sh
just media-build          # full corpus -> data/media-final/ (minutes, CPU pool)
just media-check          # §6.4 assertions + lossless pixel proof; FAIL = exit 1
just media-one <file>     # single-file final for an owner upload (§8.3)
```

## What the build produces

```text
data/media-final/
  products/{handle}/          finals + manifest.json (the product sidecar)
  site/{page|home|collection|blog|article}/…   site image finals
  site/_videos/               the 6 mp4s passthrough + their poster finals
  site-manifest.json          the site sidecar
  _state.json                 content-addressed checkpoint (§6.3)
```

Widths `[480, 768, 1024, 1536, 2048]` plus the original width as top tier when
below 2048; never upscaled; 2048 cap. WebP only: PNG sources pick the smaller
of lossless/q85 per tier (cut sheets are forced lossless), JPEG sources are
q85; lossless encodes with `exact=True` so a lossless tier decodes back
pixel-identical. Files are named `{handle}--{seq:02d}--{w}w.webp` (products)
or `{stem}--{w}w.webp` (site), ASCII, ≤ 96 chars.

## Size reality vs the spec's projection

The spec projected ~322 MB (cap 350 MB) by scaling its native-size KB/MP
experiment by per-tier pixel counts. The full corpus measures ~823 MB at the
same ruled parameters: downscaled tiers carry more entropy per pixel than
the native-size experiment (a real scene photo is 161 KB/MP at q85, not 38),
and the product renders are alpha cutouts displayed on the old site's dark
`#1a1a1a` PDP panels — the alpha plane cannot be flattened away. The
encoder is verified byte-identical to `cwebp -q 85 -m 6`. The budget
assertion was therefore revised to 1 GiB (see `params.py`); every quality
decision of the spec stands. Per-page serving is what matters and is
unchanged: srcset picks one tier per image.

## Sidecar contract for the migrator (#18), `md-media/sidecar@1`

The full field list is spec §5. Three conventions the spec schema leaves
implicit, fixed here:

- **Tier `file` paths.** A bare file name (no `/`) means a sibling of the
  manifest that cites it — the normal case inside `products/{handle}/`.
  A path containing `/` is relative to the `data/media-final/` root: this is
  how a byte-identical source shared across units is cited exactly once
  (spec §3.2: one set of products per sha256, the product side owns it; the
  site manifest always uses root-relative paths).
- **Per-tier `mode`.** Every tier carries its own `mode` (the per-tier
  smaller-encoding winner may differ across tiers); the media-level `mode` is
  the top tier's — the attachment file #18 uploads.
- **Site sources.** Site manifest entries carry `source.cdn_path` (their URL
  identity — their `master_src` is null in the manifest) and decoded
  dimensions. `alt`/`role` are the owning product's when the source is shared
  with a gallery, otherwise `null`/`unknown`. Videos are `passthrough: true`
  entries listing their posters' top-tier finals.

Skips (a missing source, an undecodable file, an unjoined product media URL)
are recorded with a reason in `_state.json` and in the sidecars' `skipped`
arrays and fail both `media-build` and `media-check`.

## Resume

`_state.json` fingerprints the parameters, the tool code and the Pillow
version. A rerun skips a source whose outputs still pass the size+mtime first
check; any change to parameters or code wipes the trees and rebuilds
everything, so old- and new-parameter products never mix. Sidecars and state
are rewritten only when their content changes, so a no-op rerun leaves every
mtime untouched.
