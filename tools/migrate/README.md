# md-migrate — the Shopify → WordPress migration

Seven stages, plan → apply, through WP-CLI `eval-file` (spec:
`docs/specs/18-migrator.md`, branch research/spec-migrator; mapping table:
`docs/specs/06-product-data-model.md` §9, branch research/spec-data-model).
`18-migrate.py` is the driver, `18-wordpress.php` the WordPress side, the
redirect map is vendored under `data/` (the closed ticket #4 authority,
public URL data only).

```sh
just migrate plan                              # five plans + the preflight (read-only)
just migrate apply --stage terms               # dry run of one stage
just migrate apply --stage terms --write       # the write
just migrate apply --stage products --write    # …then media, pages, redirects
just migrate reconcile                         # the 13-item audit, FAIL = exit 1
```

Stages: `terms` (9 series, gradient restored with its 3 patterns — owner
ruling, issue #25), `products` (146 patterns, SKU = pattern_code, empty
price, ACF groups A/B/C, SEOPress, Woo settings: product base `/pattern/`,
category base `collections`, no checkout surface), `media` (the 1063 finals
of `data/media-final/`: the top tier is the attachment, the others are
sibling files, `_md_*` postmeta, featured = old media[0], gallery in order),
`pages` (the 13 content pages under `/pages/` with their media; the other 11
published pages retire to redirects, the 7 drafts are dropped), `redirects`
(27 mapped + the `/products/*` → `/pattern/$1` regex rule into SEOPress
PRO), then `reconcile`.

Idempotency is natural keys + diff updates (`_sku`, `_md_source_url`, slug,
post_name, source path): a second `apply --write` of the same plan must
write nothing, and reconcile re-proves it as check 13. Plans, ledgers and
reports live under `~/metal-divider-local/migrate/`, outside this public
repository; the corpus (`data/shopify-admin-export`, `data/media-final`, …)
lives on this Mac and is never in the repository.

Rulings this driver carries that postdate the specs' text: the gradient
series exists again with la-pente/nara/skyfall (issue #25), materials are
Stainless Steel 304/316 only — the old five-material lists are dropped and
never written (revision 6) — and the PDP base is `/pattern/` (revision 5).

## Remote mode (`--wp`)

`--wp "<command>"` runs against any WP-CLI — production over SSH, for
instance — and that target cannot see this Mac's `media-final` paths, so
the media plan binds differently there: every entry carries `attach_uploads`,
the uploads-relative `_wp_attached_file` of the dev attachment with the same
`_md_source_url` (the vendored `data/dev-attachment-map.json`, regenerated
with `just attachment-map` after any dev media write). The WordPress side
then registers the file the deployer already mirrored into the target's
uploads — no copy — and checks every variant sibling by name, failing with
the missing name. An entry the map does not resolve (nor the sha256 reuse
path, for page media dev imported as a reuse) fails the plan build by name,
never the target write. The PHPUnit suite (`tests/php/Unit`, `just test`)
covers the branch against doubles; the local `attach` branch keeps its copy
behaviour for dev.

