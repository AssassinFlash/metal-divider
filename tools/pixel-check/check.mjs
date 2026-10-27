#!/usr/bin/env node
/**
 * Pixel-gate checker for the metal-divider rebuild — ticket #30.
 *
 * Captures a local instance with the EXACT protocol of the baseline
 * (tools/pixel-baseline/lib.mjs: same Chromium, user agent, viewports
 * 1440x900 / 390x844, animations killed, fonts + images settled, cookie
 * banner dismissed) and diffs every active page type against the
 * metalpartition.com baseline of ticket #21.
 *
 * Per URL the report shows: total diff pixels, out-of-mask diff pixels and
 * the images (baseline / current / diff). Masked regions come from
 * masks.json — JSON-declared M1 dynamic zones, M2 content-repair zones and
 * M3 upgrade zones, each of which must cite its ledger row (spec 08 §5.2:
 * "没有台账行就没有掩膜"). M2 masks can carry geometry assertions (spec 08
 * §3.2 track (a): bounding box position/width, font, line-height, block
 * order) checked against the local DOM.
 *
 * Exit code: 0 = gate passed (out-of-mask diff = 0 everywhere, geometry
 * holds, no unresolved masks, no capture failures); 1 = gate failed;
 * 2 = configuration error (bad masks, missing/tampered baseline, bad args).
 *
 * Usage:
 *   node check.mjs --base http://127.0.0.1:9890 \
 *                  [--baseline <dir>/data/pixel-baseline] \
 *                  [--filter <regex on type/slug>] [--viewports 1440,390] \
 *                  [--out <report dir>] [--masks <file>] [--map <file>] \
 *                  [--workers 2] [--gap-ms 1500]
 *   just pixel-check dev            # from the repo root
 *
 * Setup (once per machine):
 *   npm install                                       # in tools/pixel-check
 *
 * Captures are taken by capture_opencli.py through opencli against the
 * owner's own Chrome. No Playwright (AGENTS §7): the driver this file used to
 * import is gone, and there is no fallback path.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';
import { ALL_VIEWPORTS, sha256 } from '../pixel-baseline/lib.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');
const MASK_CLASSES = new Set(['M1', 'M2', 'M3']);
const PER_CAPTURE_BUDGET_MS = 120_000;
const MAX_CONSECUTIVE_FAILURES = 5;

function parseArgs(argv) {
  const a = {
    baseline: path.join(REPO, 'data', 'pixel-baseline'),
    map: path.join(HERE, 'url-map.json'),
    masks: path.join(HERE, 'masks.json'),
    out: null,
    filter: null,
    viewports: ALL_VIEWPORTS.map((v) => v.name),
    workers: 2,
    gapMs: 1500,
  };
  for (let i = 2; i < argv.length; i++) {
    const k = argv[i];
    const v = argv[i + 1];
    if (k === '--base') (a.base = v), i++;
    else if (k === '--baseline') (a.baseline = v), i++;
    else if (k === '--map') (a.map = v), i++;
    else if (k === '--masks') (a.masks = v), i++;
    else if (k === '--out') (a.out = v), i++;
    else if (k === '--filter') (a.filter = v), i++;
    else if (k === '--viewports') (a.viewports = v.split(',')), i++;
    else if (k === '--workers') (a.workers = Number(v)), i++;
    else if (k === '--gap-ms') (a.gapMs = Number(v)), i++;
    else {
      console.error(`unknown arg: ${k}`);
      process.exit(64);
    }
  }
  if (!a.base) {
    console.error('--base is required (e.g. --base http://127.0.0.1:9890)');
    process.exit(64);
  }
  a.base = a.base.replace(/\/+$/, '');
  a.baseline = path.resolve(a.baseline);
  a.viewportList = ALL_VIEWPORTS.filter((v) => a.viewports.includes(v.name));
  if (a.viewportList.length === 0) {
    console.error(`no known viewports in: ${a.viewports.join(',')}`);
    process.exit(64);
  }
  if (!Number.isFinite(a.workers) || a.workers < 1) {
    console.error('--workers must be a positive number');
    process.exit(64);
  }
  if (!Number.isFinite(a.gapMs) || a.gapMs < 0) {
    console.error('--gap-ms must be a non-negative number');
    process.exit(64);
  }
  return a;
}

function die(msg) {
  console.error(`pixel-check: ${msg}`);
  process.exit(2);
}

function globToRe(glob) {
  const escaped = glob.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '[^/]*');
  return new RegExp(`^${escaped}$`);
}

/* ---------- masks ---------- */

function validateMaskFile(masksFile) {
  if (!fs.existsSync(masksFile)) die(`masks file not found: ${masksFile}`);
  let doc;
  try {
    doc = JSON.parse(fs.readFileSync(masksFile, 'utf8'));
  } catch (e) {
    die(`masks file is not valid JSON: ${e.message}`);
  }
  const masks = doc.masks ?? [];
  const errors = [];
  const ids = new Set();
  for (const m of masks) {
    const at = m.id ?? JSON.stringify(m).slice(0, 60);
    if (!m.id || ids.has(m.id)) errors.push(`mask ${at}: id missing or not unique`);
    if (m.id) ids.add(m.id);
    if (!MASK_CLASSES.has(m.class)) errors.push(`mask ${at}: class must be one of M1/M2/M3`);
    if (!Array.isArray(m.pages) || m.pages.length === 0) errors.push(`mask ${at}: pages must be a non-empty array`);
    if (!m.reason || typeof m.reason !== 'string') errors.push(`mask ${at}: reason is required (spec 08 §5.2: no ledger line, no mask)`);
    if (!m.ledger || typeof m.ledger !== 'string') errors.push(`mask ${at}: ledger is required (spec 08 §5.2: no ledger line, no mask)`);
    const hasSelector = typeof m.selector === 'string' && m.selector.length > 0;
    const hasRect = Array.isArray(m.rect) && m.rect.length === 4 && m.rect.every((n) => Number.isFinite(n));
    if (hasSelector === hasRect) errors.push(`mask ${at}: exactly one of selector | rect [x,y,w,h] is required`);
    if (hasRect && !Array.isArray(m.viewports)) errors.push(`mask ${at}: rect masks must declare their viewports (document coords are viewport-specific)`);
    if (m.class !== 'M2' && m.geometry) errors.push(`mask ${at}: geometry assertions are for M2 masks only`);
    if (m.geometry) {
      if (!Array.isArray(m.geometry) || m.geometry.length === 0) errors.push(`mask ${at}: geometry must be a non-empty array`);
      else
        for (const g of m.geometry) {
          if (!g.selector) errors.push(`mask ${at}: geometry entry without selector`);
        }
    }
  }
  if (errors.length > 0) die(`masks file rejected (${errors.length} errors):\n  ${errors.join('\n  ')}`);
  return masks;
}

function masksFor(masks, key, vpName) {
  return masks.filter(
    (m) =>
      m.pages.some((g) => globToRe(g).test(key)) &&
      (m.viewports ? m.viewports.includes(vpName) : true)
  );
}

/* ---------- geometry assertions (spec 08 §3.2 track (a)) ---------- */

function checkGeometryEntry(expect, actual) {
  const issues = [];
  for (const key of ['x', 'y', 'width', 'height']) {
    if (expect[key]) {
      const [min, max] = expect[key];
      if (!(actual[key] >= min && actual[key] <= max)) issues.push(`${key} ${actual[key]} outside [${min}, ${max}]`);
    }
  }
  for (const key of ['fontSize', 'lineHeight']) {
    if (expect[key]) {
      const v = parseFloat(actual[key]);
      const [min, max] = expect[key];
      if (!Number.isFinite(v)) issues.push(`${key} "${actual[key]}" is not a px value`);
      else if (!(v >= min && v <= max)) issues.push(`${key} ${actual[key]} outside [${min}px, ${max}px]`);
    }
  }
  if (expect.fontFamily && !actual.fontFamily.toLowerCase().includes(String(expect.fontFamily).toLowerCase())) {
    issues.push(`fontFamily "${actual.fontFamily}" does not contain "${expect.fontFamily}"`);
  }
  return issues;
}

function evalGeometry(mask, metricsBySelector) {
  // Array order is the expected block order (spec 08 §3.2: 区块次序).
  const results = [];
  let prevY = null;
  for (const g of mask.geometry) {
    const actual = metricsBySelector.get(g.selector);
    if (!actual) {
      results.push({ selector: g.selector, ok: false, issues: ['selector not found in local DOM'], actual: null });
      prevY = null;
      continue;
    }
    const issues = checkGeometryEntry(g, actual);
    if (prevY !== null && actual.y <= prevY) issues.push(`block order: y=${actual.y} not below previous block (y=${prevY})`);
    results.push({ selector: g.selector, ok: issues.length === 0, issues, actual });
    prevY = actual.y;
  }
  return results;
}

/* ---------- pixel diff ---------- */

function padTo(img, w, h) {
  if (img.width === w && img.height === h) return img;
  const out = new PNG({ width: w, height: h });
  // White opaque canvas: the shorter page's missing area counts as diff
  // against the longer page's content, and as equal where both are empty.
  out.data.fill(255);
  PNG.bitblt(img, out, 0, 0, img.width, img.height, 0, 0);
  return out;
}

function buildMaskBitmap(w, h, boxes) {
  const mask = new Uint8Array(w * h);
  for (const box of boxes) {
    const x0 = Math.max(0, Math.floor(box.x));
    const y0 = Math.max(0, Math.floor(box.y));
    const x1 = Math.min(w, Math.ceil(box.x + box.width));
    const y1 = Math.min(h, Math.ceil(box.y + box.height));
    for (let y = y0; y < y1; y++) for (let x = x0; x < x1; x++) mask[y * w + x] = 1;
  }
  return mask;
}

function drawMaskBox(img, box) {
  const x0 = Math.max(0, Math.floor(box.x));
  const y0 = Math.max(0, Math.floor(box.y));
  const x1 = Math.min(img.width, Math.ceil(box.x + box.width));
  const y1 = Math.min(img.height, Math.ceil(box.y + box.height));
  const set = (x, y, r, g, b, a) => {
    if (x < 0 || y < 0 || x >= img.width || y >= img.height) return;
    const p = (y * img.width + x) * 4;
    img.data[p] = r;
    img.data[p + 1] = g;
    img.data[p + 2] = b;
    img.data[p + 3] = a;
  };
  // Fill: translucent blue. Border: solid blue, 2 px.
  for (let y = y0; y < y1; y++)
    for (let x = x0; x < x1; x++) {
      const border = x < x0 + 2 || y < y0 + 2 || x >= x1 - 2 || y >= y1 - 2;
      if (border) set(x, y, 13, 71, 255, 255);
      else set(x, y, 13, 71, 255, 36);
    }
}

function compare(baselinePng, currentPng, boxes) {
  const w = Math.max(baselinePng.width, currentPng.width);
  const h = Math.max(baselinePng.height, currentPng.height);
  const a = padTo(baselinePng, w, h);
  const b = padTo(currentPng, w, h);
  const diffImg = new PNG({ width: w, height: h });
  // Pass 1: unmasked totals.
  const totalDiff = pixelmatch(a.data, b.data, diffImg.data, w, h, { threshold: 0.1 });
  // Pass 2: equalize masked pixels in a copy of the baseline, then count —
  // what remains is exactly the out-of-mask diff.
  const mask = buildMaskBitmap(w, h, boxes);
  let maskedPx = 0;
  const a2 = new PNG({ width: w, height: h });
  a2.data.set(a.data);
  for (let i = 0; i < mask.length; i++) {
    if (!mask[i]) continue;
    maskedPx += 1;
    const p = i * 4;
    a2.data[p] = b.data[p];
    a2.data[p + 1] = b.data[p + 1];
    a2.data[p + 2] = b.data[p + 2];
    a2.data[p + 3] = b.data[p + 3];
  }
  const outOfMaskDiff = pixelmatch(a2.data, b.data, diffImg.data, w, h, { threshold: 0.1 });
  for (const box of boxes) drawMaskBox(diffImg, box);
  return { width: w, height: h, totalDiff, outOfMaskDiff, maskedPx, diffImg };
}

/* ---------- report ---------- */

function renderIndex(report) {
  const fmt = (n) => n.toLocaleString('en-US');
  const rows = [];
  for (const [type, pages] of Object.entries(report.pages)) {
    const subPass = pages.filter((p) => p.verdict === 'pass').length;
    rows.push(`<h2>${type} <small>${subPass}/${pages.length} pass</small></h2>`);
    rows.push('<table><tr><th>page</th><th>vp</th><th>http</th><th>diff px</th><th>out-of-mask px</th><th>masks</th><th>geometry</th></tr>');
    for (const p of pages) {
      for (const [vp, r] of Object.entries(p.viewports)) {
        const masksCell = r.masks.map((m) => `<span class="cls cls-${m.class}" title="${esc(m.reason)}">${m.id}</span>`).join(' ') || '-';
        const geo = r.geometry && r.geometry.length ? (r.geometry.every((g) => g.ok) ? 'ok' : 'FAIL') : '-';
        rows.push(
          `<tr class="${r.status}"><td>${type}/${esc(p.slug)}<br><small>${esc(p.path)}</small></td>` +
            `<td>${vp}</td><td>${r.httpStatus ?? '?'}</td>` +
            `<td class="num">${r.compared ? fmt(r.totalDiffPx) : '-'}</td>` +
            `<td class="num">${r.compared ? fmt(r.outOfMaskDiffPx) : '-'}</td>` +
            `<td>${masksCell}</td><td>${geo}</td></tr>`
        );
        if (r.compared) {
          rows.push(
            `<tr class="imgs"><td colspan="7">` +
              `<figure><figcaption>baseline</figcaption><img src="${r.images.baseline}" loading="lazy"></figure>` +
              `<figure><figcaption>current</figcaption><img src="${r.images.current}" loading="lazy"></figure>` +
              `<figure><figcaption>diff (red = out-of-mask, blue = masked)</figcaption><img src="${r.images.diff}" loading="lazy"></figure>` +
              `</td></tr>`
          );
        }
      }
    }
    rows.push('</table>');
  }
  const s = report.summary;
  return `<!doctype html><html><head><meta charset="utf-8"><title>pixel-check ${esc(report.base)}</title><style>
    body{font:13px/1.45 -apple-system,system-ui,sans-serif;margin:24px;color:#1a1a1a}
    h1{font-size:18px}h2{font-size:15px;margin:28px 0 6px}small{color:#777;font-weight:400}
    table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:4px 8px;text-align:left;vertical-align:top}
    th{background:#f5f5f5}.num{text-align:right;font-variant-numeric:tabular-nums}
    tr.fail td{background:#fde8e8}tr.pass td{background:#e8f5e9}tr.error td{background:#fff3cd}
    tr.imgs td{background:#fafafa}figure{display:inline-block;margin:4px 8px 4px 0;max-width:340px;vertical-align:top}
    figure img{width:100%;border:1px solid #ccc;display:block}figcaption{font-size:11px;color:#666;margin-bottom:2px}
    .cls{display:inline-block;padding:0 5px;border-radius:3px;font-size:11px;background:#eef;border:1px solid #ccd}
    .cls-M2{background:#efe;border:1px solid #cdc}.cls-M3{background:#fee;border:1px solid #dcc}
    .summary{background:#f5f5f5;padding:10px 14px;border-radius:6px;margin:12px 0}
  </style></head><body>
  <h1>pixel-check — ${esc(report.base)}</h1>
  <p class="summary"><b>${s.pass}/${s.comparisons} comparisons pass</b> (out-of-mask diff = 0).
     Out-of-mask diff pixels total: <b>${fmt(s.outOfMaskDiffPx)}</b>; in-mask: ${fmt(s.totalDiffPx - s.outOfMaskDiffPx)}.
     Masks applied: ${s.masksApplied}, unresolved: ${s.masksUnresolved}. Baseline: <code>${esc(report.baseline_manifest_sha256.slice(0, 16))}…</code></p>
  ${rows.join('\n')}
  </body></html>`;
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/* ---------- main ---------- */

const args = parseArgs(process.argv);

const mapDoc = JSON.parse(fs.readFileSync(args.map, 'utf8'));
const baselineManifestPath = path.join(args.baseline, 'manifest.json');
if (!fs.existsSync(baselineManifestPath)) {
  die(`baseline manifest not found: ${baselineManifestPath}\n  pass --baseline <dir containing manifest.json>, the ticket #21 capture`);
}
const baselineManifestSha = sha256(baselineManifestPath);
if (baselineManifestSha !== mapDoc.sources.baseline_manifest_sha256) {
  die(
    `baseline manifest sha256 does not match the url-map's recorded capture\n` +
      `  url-map:   ${mapDoc.sources.baseline_manifest_sha256}\n` +
      `  baseline: ${baselineManifestSha}\n` +
      `  re-run gen-url-map.mjs against the manifest this baseline was captured with`
  );
}
const baselineManifest = JSON.parse(fs.readFileSync(baselineManifestPath, 'utf8'));
const masks = validateMaskFile(args.masks);

// Join: url-map rows x baseline manifest files.
const byKey = new Map(baselineManifest.targets.map((t) => [`${t.type}/${t.slug}`, t]));
let rows = mapDoc.entries.filter((e) => e.status === 'active');
if (args.filter) {
  const re = new RegExp(args.filter);
  rows = rows.filter((e) => re.test(`${e.type}/${e.slug}`));
}
for (const e of rows) {
  const t = byKey.get(`${e.type}/${e.slug}`);
  if (!t || t.status !== 'ok') die(`baseline entry missing or failed for ${e.type}/${e.slug}`);
  for (const vp of args.viewportList) {
    const f = t.files[vp.name];
    if (!f || f.status !== 'ok') die(`baseline file missing for ${e.type}/${e.slug}@${vp.name}`);
    const file = path.join(args.baseline, f.file);
    if (!fs.existsSync(file)) die(`baseline file not on disk: ${file}`);
    if (sha256(file) !== f.sha256) die(`baseline file sha256 mismatch (tampered?): ${file}`);
  }
}

const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const outDir = path.resolve(args.out ?? path.join(REPO, 'data', 'pixel-reports', stamp));
const imgDir = path.join(outDir, 'images');
fs.mkdirSync(imgDir, { recursive: true });

console.log(
  `targets: ${rows.length} active (of ${mapDoc.entries.length} mapped), ` +
    `viewports: ${args.viewportList.map((v) => v.name).join(',')}, masks: ${masks.length}, base: ${args.base}`
);

// The capture driver: opencli, via the Python library beside this file.
const CAPTURE_PY = path.join(HERE, 'capture_opencli.py');
const PX_SESSION = process.env.MD_PIXEL_SESSION || 'mdpx';

/**
 * One capture, through opencli. Returns the record capture_opencli.py built:
 * file, bytes, sha256, capturedWidth/Height, banner state, style bytes.
 */
function captureViaOpencli(t, vp, width, out, opts = {}) {
  const payload = JSON.stringify({
    url: t.url,
    session: PX_SESSION,
    width,
    height: vp.height,
    out,
    maskSelectors: opts.maskSelectors,
    geometrySelectors: opts.geometrySelectors,
  });
  const out_json = execFileSync(
    'python3',
    [CAPTURE_PY, '--json', payload],
    { encoding: 'utf8', timeout: PER_CAPTURE_BUDGET_MS, env: process.env }
  );
  return JSON.parse(out_json.trim().split('\n').pop());
}
const report = {
  ticket: 'AssassinFlash/metal-divider#30',
  generated_at: new Date().toISOString(),
  base: args.base,
  baseline_dir: args.baseline,
  baseline_manifest_sha256: baselineManifestSha,
  masks_file: path.resolve(args.masks),
  config: {
    viewports: args.viewportList.map((v) => v.name),
    protocol: 'tools/pixel-baseline/lib.mjs (shared with the #21 baseline capture)',
    driver: 'opencli', 
    killCss: KILL_CSS,
    pixelmatchThreshold: 0.1,
    workers: args.workers,
    gapMs: args.gapMs,
  },
  pages: {},
  summary: null,
};
let comparisons = 0;
let pass = 0;
let fail = 0;
let masksApplied = 0;
let masksUnresolved = 0;
let totalDiffPx = 0;
let outOfMaskDiffPx = 0;
let consecutiveFails = 0;
let done = 0;
const total = rows.length * args.viewportList.length;

async function processOne(context, entry, vp) {
  const key = `${entry.type}/${entry.slug}`;
  const url = args.base + entry.path;
  const applicable = masksFor(masks, key, vp.name);
  const maskSelectors = [...new Set(applicable.filter((m) => m.selector).map((m) => m.selector))];
  const geometrySelectors = [
    ...new Set(applicable.filter((m) => m.geometry).flatMap((m) => m.geometry.map((g) => g.selector))),
  ];
  const target = { type: entry.type, slug: entry.slug, url };

  try {
    let res;
    try {
      res = captureViaOpencli(
        target,
        vp,
        vp.width,
        path.join(outDir, 'current', `${target.type}-${target.slug}-${vp.name}.png`),
        {
          maskSelectors: maskSelectors.length ? maskSelectors : undefined,
          geometrySelectors: geometrySelectors.length ? geometrySelectors : undefined,
        }
      );
    } catch (e) {
      return { status: 'error', error: e.message };
    }

  const view = {
    status: null,
    compared: false,
    httpStatus: res.httpStatus,
    finalUrl: res.finalUrl,
    masks: [],
    geometry: null,
  };
  const problems = [];

  if (res.httpStatus >= 400) {
    problems.push(`http ${res.httpStatus}`);
  } else {
    // Resolve masks: selector boxes from the capture, rects as declared.
    const boxesBySelector = new Map();
    if (res.maskBoxes) for (const b of res.maskBoxes) if (b) boxesBySelector.set(b.selector, b);
    const metricsBySelector = new Map();
    if (res.geometry) for (const g of res.geometry) metricsBySelector.set(g.selector, g);
    const boxes = [];
    for (const m of applicable) {
      if (m.selector) {
        const b = boxesBySelector.get(m.selector);
        if (!b) {
          masksUnresolved += 1;
          problems.push(`mask ${m.id}: selector ${m.selector} not found in local DOM`);
          view.masks.push({ id: m.id, class: m.class, resolved: false, reason: m.reason, ledger: m.ledger });
          continue;
        }
        masksApplied += 1;
        boxes.push(b);
        view.masks.push({ id: m.id, class: m.class, resolved: true, box: b, reason: m.reason, ledger: m.ledger });
      } else {
        const [x, y, width, height] = m.rect;
        const b = { selector: null, x, y, width, height };
        masksApplied += 1;
        boxes.push(b);
        view.masks.push({ id: m.id, class: m.class, resolved: true, box: b, reason: m.reason, ledger: m.ledger });
      }
      if (m.geometry) {
        const geoResults = evalGeometry(m, metricsBySelector);
        view.geometry = [...(view.geometry ?? []), ...geoResults];
        for (const g of geoResults) if (!g.ok) problems.push(`geometry ${m.id}/${g.selector}: ${g.issues.join('; ')}`);
      }
    }

    const bEntry = byKey.get(key);
    const baselineFile = path.join(args.baseline, bEntry.files[vp.name].file);
    const baselinePng = PNG.sync.read(fs.readFileSync(baselineFile));
    const currentFile = path.join(outDir, 'current', res.file);
    const currentPng = PNG.sync.read(fs.readFileSync(currentFile));
    const cmp = compare(baselinePng, currentPng, boxes);

    const imageBase = path.join('images', entry.type, `${entry.slug}-${vp.name}`);
    const imageDirFull = path.join(imgDir, entry.type);
    fs.mkdirSync(imageDirFull, { recursive: true });
    fs.copyFileSync(baselineFile, path.join(outDir, `${imageBase}-baseline.png`));
    fs.copyFileSync(currentFile, path.join(outDir, `${imageBase}-current.png`));
    fs.writeFileSync(path.join(outDir, `${imageBase}-diff.png`), PNG.sync.write(cmp.diffImg));

    view.compared = true;
    view.baseline = { file: bEntry.files[vp.name].file, width: baselinePng.width, height: baselinePng.height };
    view.current = { file: path.posix.join('current', res.file), width: currentPng.width, height: currentPng.height };
    view.union = { width: cmp.width, height: cmp.height };
    view.totalDiffPx = cmp.totalDiff;
    view.outOfMaskDiffPx = cmp.outOfMaskDiff;
    view.maskedPx = cmp.maskedPx;
    view.images = {
      baseline: `${imageBase}-baseline.png`,
      current: `${imageBase}-current.png`,
      diff: `${imageBase}-diff.png`,
    };
    totalDiffPx += cmp.totalDiff;
    outOfMaskDiffPx += cmp.outOfMaskDiff;
    if (cmp.outOfMaskDiff > 0) problems.push(`${cmp.outOfMaskDiff} out-of-mask diff pixels`);
  }

    view.status = problems.length === 0 ? 'pass' : 'fail';
    if (problems.length > 0) view.problems = problems;
    return view;
  } catch (e) {
    return { status: 'error', error: e.message };
  }
}

async function worker(queue, viewport) {
  // One warmup visit per viewport, so consent state is stored before the
  // first saved capture (the banner never appears inside a screenshot).
  captureViaOpencli(
    { url: `${args.base}/`, type: 'warmup', slug: 'home' },
    viewport,
    viewport.width,
    path.join(outDir, '_warmup', `warmup-${viewport.name}.png`)
  );
  while (queue.length > 0 && consecutiveFails < MAX_CONSECUTIVE_FAILURES) {
    const entry = queue.shift();
    if (!entry) break;
    const key = `${entry.type}/${entry.slug}`;
    // One report row per page; each viewport lands inside it.
    let pageRow = report.pages[entry.type]?.find((p) => p.slug === entry.slug);
    if (!pageRow) {
      pageRow = {
        slug: entry.slug,
        path: entry.path,
        baseline_url: entry.baseline_url,
        ledger: entry.ledger,
        viewports: {},
      };
      (report.pages[entry.type] ??= []).push(pageRow);
    }
    for (let attempt = 1; attempt <= 2; attempt++) {
      const view = await processOne(context, entry, viewport);
      pageRow.viewports[viewport.name] = view;
      if (view.status !== 'error') break;
      if (attempt === 1) await new Promise((r) => setTimeout(r, 2000));
    }
    const view = pageRow.viewports[viewport.name];
    comparisons += 1;
    done += 1;
    if (view.status === 'pass') {
      consecutiveFails = 0;
      pass += 1;
    } else {
      consecutiveFails += 1;
      fail += 1;
      console.error(`FAIL ${key}@${viewport.name}: ${(view.problems ?? [view.error]).join('; ')}`);
      if (consecutiveFails >= MAX_CONSECUTIVE_FAILURES) {
        console.error(`aborting: ${MAX_CONSECUTIVE_FAILURES} consecutive failures`);
        break;
      }
    }
    const nums = view.compared ? ` total=${view.totalDiffPx} unmasked=${view.outOfMaskDiffPx}` : '';
    console.log(
      `[${done}/${total}] ${key}@${viewport.name} ${view.status.toUpperCase()}${nums} masks=${view.masks.length} http=${view.httpStatus ?? '?'}`
    );
    await new Promise((r) => setTimeout(r, args.gapMs));
  }
  await context.close().catch(() => null);
}

for (const viewport of args.viewportList) {
  if (consecutiveFails >= MAX_CONSECUTIVE_FAILURES) break;
  const queue = [...rows];
  const shards = Array.from({ length: Math.max(1, args.workers) }, () => []);
  queue.forEach((t, i) => shards[i % shards.length].push(t));
  await Promise.all(shards.map((shard) => worker(shard, viewport)));
}

await browser.close();

report.summary = {
  comparisons,
  pass,
  fail,
  totalDiffPx,
  outOfMaskDiffPx,
  masksApplied,
  masksUnresolved,
  aborted: consecutiveFails >= MAX_CONSECUTIVE_FAILURES,
  gate: 'out-of-mask diff = 0 on every active page (AGENTS.md §5)',
};
// Per-type verdicts for the report page rows.
for (const pages of Object.values(report.pages)) {
  for (const p of pages) p.verdict = Object.values(p.viewports).every((v) => v.status === 'pass') ? 'pass' : 'fail';
}
fs.writeFileSync(path.join(outDir, 'report.json'), JSON.stringify(report, null, 2) + '\n');
fs.writeFileSync(path.join(outDir, 'index.html'), renderIndex(report));

console.log(
  `pixel-check: ${comparisons} comparisons, ${pass} pass, ${fail} fail; ` +
    `out-of-mask diff pixels total ${outOfMaskDiffPx.toLocaleString('en-US')} (of ${totalDiffPx.toLocaleString('en-US')} total)`
);
console.log(`report: ${path.join(outDir, 'index.html')}`);
process.exit(fail > 0 || consecutiveFails >= MAX_CONSECUTIVE_FAILURES ? 1 : 0);
