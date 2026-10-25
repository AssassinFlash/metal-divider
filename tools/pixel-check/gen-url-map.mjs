#!/usr/bin/env node
/**
 * Generate the page-type -> local-URL map (ticket #30).
 *
 * Input: the baseline capture manifest (tools/pixel-baseline/manifest.json,
 * ticket #21's canonical 182-target list) cross-checked against the
 * shopify-public export (pages.json / collections.json / products.json).
 * Output: url-map.json next to this script — one row per baseline target
 * with the local instance path it must be compared on, or the ruling that
 * retires it.
 *
 * The mapping rules are the ledger rulings of spec 08 (branch
 * research/spec-content-boundary), not data-derived:
 *   - products/<handle>  -> /pattern/<handle>      (L2; URL base /pattern/, R2)
 *   - collections/<h>    -> /collections/<h>       (L3; R3)
 *   - collections/index  -> /collections/          (L4 hub)
 *   - collections/all    -> /shop/                 (L18; R4)
 *   - pages/<h>          -> /pages/<h>             (L5-L17; R5)
 *   - retired: contact (X1), custom-metal-divider-screens-thank-you (X2),
 *     metal-divider old landing (X3), the 8 Consentmo compliance pages (X4,
 *     D7), the empty pattern-gradient collection (D8).
 *
 * Expected result: 182 rows, 170 active (1 home + 13 pages + 146 PDP +
 * 8 collections + 2 index) and 12 retired — spec 08 §5.1's pixel-gate set.
 *
 * Usage:
 *   node gen-url-map.mjs --data /path/to/data/shopify-public \
 *                       [--manifest ../pixel-baseline/manifest.json] \
 *                       [--out url-map.json]
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const HERE = path.dirname(new URL(import.meta.url).pathname);

// Rulings, each citing its ledger row (spec 08 §2.2 / decision table).
const RETIRED_PAGES = {
  contact: { ledger: 'X1', reason: '301 -> /inquiry/ (spec 08 F6)' },
  'custom-metal-divider-screens-thank-you': { ledger: 'X2', reason: '301 -> /inquiry/thank-you/ (spec 08 F6)' },
  'metal-divider': { ledger: 'X3', reason: 'old landing, 301 -> / (spec 08 F5 R6)' },
  gdpr: { ledger: 'X4/D7', reason: 'Consentmo compliance page, 301 -> /privacy/ (spec 08 D7)' },
  lgpd: { ledger: 'X4/D7', reason: 'Consentmo compliance page, 301 -> /privacy/ (spec 08 D7)' },
  popia: { ledger: 'X4/D7', reason: 'Consentmo compliance page, 301 -> /privacy/ (spec 08 D7)' },
  pdpa: { ledger: 'X4/D7', reason: 'Consentmo compliance page, 301 -> /privacy/ (spec 08 D7)' },
  appi: { ledger: 'X4/D7', reason: 'Consentmo compliance page, 301 -> /privacy/ (spec 08 D7)' },
  'apa-nzpa': { ledger: 'X4/D7', reason: 'Consentmo compliance page, 301 -> /privacy/ (spec 08 D7)' },
  'canadian-laws-compliance': { ledger: 'X4/D7', reason: 'Consentmo compliance page, 301 -> /privacy/ (spec 08 D7)' },
  'us-laws-compliance': { ledger: 'X4/D7', reason: 'Consentmo compliance page, 301 -> /privacy/ (spec 08 D7)' },
};
const RETIRED_COLLECTIONS = {
  'pattern-gradient': { ledger: 'D8', reason: 'empty collection, 301 -> /shop/ (spec 08 D8)' },
};

function parseArgs(argv) {
  const a = { manifest: path.join(HERE, '..', 'pixel-baseline', 'manifest.json'), out: path.join(HERE, 'url-map.json') };
  for (let i = 2; i < argv.length; i++) {
    const k = argv[i];
    const v = argv[i + 1];
    if (k === '--data') (a.data = v), i++;
    else if (k === '--manifest') (a.manifest = v), i++;
    else if (k === '--out') (a.out = v), i++;
    else {
      console.error(`unknown arg: ${k}`);
      process.exit(64);
    }
  }
  if (!a.data) {
    console.error('--data (shopify-public dir) is required');
    process.exit(64);
  }
  return a;
}

function sha256(file) {
  const h = crypto.createHash('sha256');
  h.update(fs.readFileSync(file));
  return h.digest('hex');
}

function fail(msg) {
  console.error(`gen-url-map: ${msg}`);
  process.exit(2);
}

const args = parseArgs(process.argv);
const manifest = JSON.parse(fs.readFileSync(path.resolve(args.manifest), 'utf8'));
const dataDir = path.resolve(args.data);
const read = (f) => JSON.parse(fs.readFileSync(path.join(dataDir, f), 'utf8'));
const pages = new Set(read('pages.json').pages.map((p) => p.handle).filter((h) => h !== '/'));
const collections = new Set(read('collections.json').collections.map((c) => c.handle));
const products = new Set(read('products.json').products.map((p) => p.handle));

const entries = [];
for (const t of manifest.targets) {
  const key = `${t.type}/${t.slug}`;
  let row;
  if (t.type === 'home') {
    row = { type: t.type, slug: t.slug, status: 'active', path: '/', ledger: 'L1' };
  } else if (t.type === 'products') {
    if (!products.has(t.slug)) fail(`${key}: not in shopify-public products.json`);
    row = { type: t.type, slug: t.slug, status: 'active', path: `/pattern/${t.slug}`, ledger: 'L2' };
  } else if (t.type === 'collections') {
    if (!collections.has(t.slug)) fail(`${key}: not in shopify-public collections.json`);
    const retired = RETIRED_COLLECTIONS[t.slug];
    row = retired
      ? { type: t.type, slug: t.slug, status: 'retired', ledger: retired.ledger, reason: retired.reason }
      : { type: t.type, slug: t.slug, status: 'active', path: `/collections/${t.slug}`, ledger: 'L3' };
  } else if (t.type === 'collections-index') {
    row =
      t.slug === 'index'
        ? { type: t.type, slug: t.slug, status: 'active', path: '/collections/', ledger: 'L4' }
        : { type: t.type, slug: t.slug, status: 'active', path: '/shop/', ledger: 'L18 (R4)' };
  } else if (t.type === 'pages') {
    if (!pages.has(t.slug)) fail(`${key}: not in shopify-public pages.json`);
    const retired = RETIRED_PAGES[t.slug];
    row = retired
      ? { type: t.type, slug: t.slug, status: 'retired', ledger: retired.ledger, reason: retired.reason }
      : { type: t.type, slug: t.slug, status: 'active', path: `/${t.slug}`, ledger: 'L5-L17' };
  } else {
    fail(`${key}: unknown target type in baseline manifest`);
  }
  row.baseline_url = t.url;
  entries.push(row);
}

// Bidirectional completeness against the export data.
const mappedProducts = new Set(entries.filter((e) => e.type === 'products').map((e) => e.slug));
for (const h of products) if (!mappedProducts.has(h)) fail(`products.json handle ${h} missing from baseline manifest`);

const active = entries.filter((e) => e.status === 'active');
const retired = entries.filter((e) => e.status === 'retired');
const count = (fn) => active.filter(fn).length;
const tally = {
  total: entries.length,
  active: active.length,
  retired: retired.length,
  activeByType: {
    home: count((e) => e.type === 'home'),
    pages: count((e) => e.type === 'pages'),
    products: count((e) => e.type === 'products'),
    collections: count((e) => e.type === 'collections'),
    'collections-index': count((e) => e.type === 'collections-index'),
  },
};
// Spec 08 §5.1: 1 + 13 + 146 + 8 + 2 = 170 active of 182 captured.
const expected = { total: 182, active: 170, retired: 12 };
for (const [k, v] of Object.entries(expected)) {
  if (tally[k] !== v) fail(`expected ${k}=${v}, got ${tally[k]} — a ruling changed, update this generator`);
}
if (tally.activeByType.collections !== 8 || tally.activeByType.products !== 146 || tally.activeByType.pages !== 13) {
  fail(`unexpected per-type split: ${JSON.stringify(tally.activeByType)}`);
}

const out = {
  ticket: 'AssassinFlash/metal-divider#30',
  generated_at: new Date().toISOString(),
  sources: {
    baseline_manifest: path.relative(path.dirname(path.resolve(args.out)), path.resolve(args.manifest)),
    baseline_manifest_sha256: sha256(path.resolve(args.manifest)),
    shopify_public_sha256: {
      pages: sha256(path.join(dataDir, 'pages.json')),
      collections: sha256(path.join(dataDir, 'collections.json')),
      products: sha256(path.join(dataDir, 'products.json')),
    },
  },
  rules: 'spec 08 (branch research/spec-content-boundary): /pattern/ base for PDPs (R2/L2), /collections/<h> kept (R3/L3), /collections/all -> /shop/ (R4/L18), /pages/<h> kept (R5), retirements X1-X4/D7/D8',
  summary: tally,
  entries,
};
fs.writeFileSync(path.resolve(args.out), JSON.stringify(out, null, 2) + '\n');
console.log(`url-map: ${tally.total} rows, ${tally.active} active, ${tally.retired} retired -> ${path.resolve(args.out)}`);
