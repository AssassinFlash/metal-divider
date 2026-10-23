#!/usr/bin/env node
/**
 * Pixel baseline capture for metalpartition.com (EN face) — wayfinder ticket #21.
 *
 * Full-page screenshots of every replica page type, two viewports (1440x900,
 * 390x844), same Playwright Chromium, animations disabled, fonts + images
 * settled (networkidle + document.fonts.ready + lazy-load scroll pass +
 * 1.5s backstop). Cookie-consent banner is dismissed by click; if a banner
 * remains visible it is recorded as a known mask in the manifest.
 *
 * Usage:
 *   node capture.mjs --out /path/to/data/pixel-baseline \
 *                    --data /path/to/data/shopify-public
 *   node capture.mjs --out DIR --from-manifest DIR/manifest.json   # re-run a manifest
 *   optional: --filter <regex on type/slug> --viewports 1440,390
 *
 * Politeness: 2 workers, 1.5s gap between loads per worker, 1 retry per
 * capture, abort after 5 consecutively failed targets. Screenshots land in
 * <out>/<page-type>/<slug>-<1440|390>.png; manifest.json lands in <out>.
 *
 * Setup (once per machine):
 *   npm install && npx playwright install chromium
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const BASE = 'https://metalpartition.com';
const UA =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const ALL_VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '390', width: 390, height: 844 },
];
const WORKERS = 2;
const GAP_MS = 1500;
const MAX_CONSECUTIVE_FAILURES = 5;
const PER_CAPTURE_BUDGET_MS = 120_000;

// Freeze motion: honored reduced-motion + paused animations + dead transitions.
// Entrance reveals are triggered by the lazy-load scroll pass instead.
const KILL_CSS = `*, *::before, *::after {
  animation-play-state: paused !important;
  transition: none !important;
  scroll-behavior: auto !important;
}`;

const COOKIE_ACCEPT_SELECTORS = [
  '#cookie_action_close_header',
  '.cky-btn-accept',
  '#cky-btn-accept',
  'button.cky-banner-element.cky-btn-accept',
  '.cookieyes-btn-accept',
  '#onetrust-accept-btn-handler',
  '#cookiescript_accept',
];
const BANNER_DETECTORS = [
  '#cky-consent',
  '.cky-consent',
  '.cky-consent-bar',
  '#cookie-law-info-bar',
  '.cookieyes-consent-container',
  '#cookiescript_injected',
  '#onetrust-consent-sdk',
];

function parseArgs(argv) {
  const a = { viewports: ALL_VIEWPORTS.map((v) => v.name) };
  for (let i = 2; i < argv.length; i++) {
    const k = argv[i];
    const v = argv[i + 1];
    if (k === '--out') (a.out = v), i++;
    else if (k === '--data') (a.data = v), i++;
    else if (k === '--from-manifest') (a.fromManifest = v), i++;
    else if (k === '--filter') (a.filter = v), i++;
    else if (k === '--viewports') (a.viewports = v.split(',')), i++;
    else {
      console.error(`unknown arg: ${k}`);
      process.exit(64);
    }
  }
  if (!a.out) {
    console.error('--out is required');
    process.exit(64);
  }
  a.viewportList = ALL_VIEWPORTS.filter((v) => a.viewports.includes(v.name));
  return a;
}

function buildTargets(dataDir) {
  const read = (f) => JSON.parse(fs.readFileSync(path.join(dataDir, f), 'utf8'));
  const targets = [{ type: 'home', slug: 'home', url: `${BASE}/` }];
  for (const p of read('pages.json').pages) {
    if (p.handle === '/') continue;
    targets.push({ type: 'pages', slug: p.handle, url: `${BASE}/pages/${p.handle}` });
  }
  for (const p of read('products.json').products)
    targets.push({ type: 'products', slug: p.handle, url: `${BASE}/products/${p.handle}` });
  for (const c of read('collections.json').collections)
    targets.push({ type: 'collections', slug: c.handle, url: `${BASE}/collections/${c.handle}` });
  targets.push({ type: 'collections-index', slug: 'index', url: `${BASE}/collections/` });
  targets.push({ type: 'collections-index', slug: 'all', url: `${BASE}/collections/all` });
  return targets;
}

function sha256(file) {
  const h = crypto.createHash('sha256');
  h.update(fs.readFileSync(file));
  return h.digest('hex');
}

async function dismissCookieBanner(page) {
  for (const sel of COOKIE_ACCEPT_SELECTORS) {
    try {
      const el = page.locator(sel).first();
      if ((await el.count()) > 0 && (await el.isVisible({ timeout: 1000 }))) {
        await el.click({ timeout: 3000, force: true }).catch(() => null);
        await page.waitForTimeout(500);
        return sel;
      }
    } catch {
      /* selector not present */
    }
  }
  return null;
}

async function detectResidualBanner(page) {
  for (const sel of BANNER_DETECTORS) {
    try {
      const el = page.locator(sel).first();
      if ((await el.count()) > 0 && (await el.isVisible({ timeout: 500 }))) return sel;
    } catch {
      /* not present */
    }
  }
  return null;
}

async function lazyLoadPass(page) {
  await page.evaluate(async () => {
    await new Promise((resolve) => {
      let y = 0;
      let steps = 0;
      const step = Math.max(400, Math.round(window.innerHeight * 0.9));
      const timer = setInterval(() => {
        y += step;
        steps += 1;
        window.scrollTo(0, y);
        if (y >= document.body.scrollHeight || steps > 80) {
          clearInterval(timer);
          window.scrollTo(0, 0);
          resolve();
        }
      }, 120);
    });
  }).catch(() => null);
  await page.waitForTimeout(800);
}

async function captureOne(context, t, vp, outDir) {
  const page = await context.newPage();
  const started = Date.now();
  try {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const resp = await page.goto(t.url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    await page.addStyleTag({ content: KILL_CSS }).catch(() => null);
    await page.evaluate(() => document.fonts.ready).catch(() => null);
    const bannerClicked = await dismissCookieBanner(page);
    await lazyLoadPass(page);
    // Deterministic settle gate: every <img> finished loading (or errored).
    // networkidle is NOT used: this site's analytics keep sockets open forever.
    await page
      .waitForFunction(() => Array.from(document.images).every((i) => i.complete), { timeout: 15_000 })
      .catch(() => null);
    await page.waitForTimeout(1500);
    const residualBanner = await detectResidualBanner(page);
    const file = path.join(outDir, t.type, `${t.slug}-${vp.name}.png`);
    fs.mkdirSync(path.dirname(file), { recursive: true });
    await page.screenshot({ path: file, fullPage: true, timeout: 60_000 });
    return {
      ok: true,
      file: path.relative(outDir, file),
      bytes: fs.statSync(file).size,
      sha256: sha256(file),
      httpStatus: resp ? resp.status() : null,
      finalUrl: page.url(),
      bannerClicked,
      residualBanner,
      ms: Date.now() - started,
    };
  } finally {
    await page.close().catch(() => null);
  }
}

async function warmup(context) {
  // Visit home once per context so cookie consent is stored before any saved
  // capture — the banner never appears inside a baseline screenshot.
  const page = await context.newPage();
  try {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    await page.evaluate(() => document.fonts.ready).catch(() => null);
    await dismissCookieBanner(page);
    await page.waitForTimeout(800);
  } catch (e) {
    console.warn(`[warmup] ${e.message}`);
  } finally {
    await page.close().catch(() => null);
  }
}

async function main() {
  const args = parseArgs(process.argv);
  const outDir = path.resolve(args.out);
  fs.mkdirSync(outDir, { recursive: true });

  let targets;
  if (args.fromManifest) {
    const m = JSON.parse(fs.readFileSync(args.fromManifest, 'utf8'));
    targets = m.targets.map(({ type, slug, url }) => ({ type, slug, url }));
  } else {
    if (!args.data) {
      console.error('--data (shopify-public dir) or --from-manifest is required');
      process.exit(64);
    }
    targets = buildTargets(path.resolve(args.data));
  }
  if (args.filter) {
    const re = new RegExp(args.filter);
    targets = targets.filter((t) => re.test(`${t.type}/${t.slug}`));
  }
  console.log(`targets: ${targets.length}, viewports: ${args.viewportList.map((v) => v.name).join(',')}`);

  let pwVersion = 'unknown';
  try {
    pwVersion = JSON.parse(fs.readFileSync(new URL('./package.json', import.meta.url), 'utf8')).dependencies.playwright;
  } catch {}

  const browser = await chromium.launch({ headless: true });
  const results = new Map(); // target index -> per-viewport result
  targets.forEach((t) => results.set(t, {}));
  const masks = new Map(); // selector -> [pages]
  let consecutiveFails = 0;
  let done = 0;
  const total = targets.length * args.viewportList.length;

  async function worker(queue, viewport) {
    const context = await browser.newContext({
      viewport: { width: viewport.width, height: viewport.height },
      deviceScaleFactor: 1,
      locale: 'en-US',
      timezoneId: 'UTC',
      userAgent: UA,
    });
    await warmup(context);
    while (queue.length > 0 && consecutiveFails < MAX_CONSECUTIVE_FAILURES) {
      const t = queue.shift();
      if (!t) break;
      let res = null;
      let lastErr = null;
      for (let attempt = 1; attempt <= 2; attempt++) {
        try {
          const capped = Promise.race([
            captureOne(context, t, viewport, outDir),
            new Promise((_, rej) => setTimeout(() => rej(new Error('capture budget exceeded')), PER_CAPTURE_BUDGET_MS)),
          ]);
          res = await capped;
          break;
        } catch (e) {
          lastErr = e;
          if (attempt === 1) await new Promise((r) => setTimeout(r, 2000));
        }
      }
      if (res && res.ok && (!res.httpStatus || res.httpStatus < 400)) {
        consecutiveFails = 0;
        results.get(t)[viewport.name] = { status: 'ok', ...res };
        if (res.residualBanner)
          masks.set(res.residualBanner, [...(masks.get(res.residualBanner) || []), `${t.type}/${t.slug}@${viewport.name}`]);
      } else {
        consecutiveFails += 1;
        results.get(t)[viewport.name] = {
          status: 'fail',
          error: lastErr ? lastErr.message : `http ${res ? res.httpStatus : '?'}`,
        };
        console.error(`FAIL ${t.type}/${t.slug}@${viewport.name}: ${results.get(t)[viewport.name].error}`);
        if (consecutiveFails >= MAX_CONSECUTIVE_FAILURES) {
          console.error(`aborting: ${MAX_CONSECUTIVE_FAILURES} consecutive failures`);
          break;
        }
      }
      done += 1;
      console.log(
        `[${done}/${total}] ${t.type}/${t.slug}@${viewport.name} ${results.get(t)[viewport.name].status}` +
          (res && res.ok ? ` ${res.ms}ms ${res.finalUrl}` : '')
      );
      if (done % 20 === 0) writeManifest();
      await new Promise((r) => setTimeout(r, GAP_MS));
    }
    await context.close().catch(() => null);
  }

  function writeManifest(final = false) {
    const list = targets.map((t) => {
      const files = results.get(t) || {};
      const statuses = Object.values(files).map((f) => f.status);
      const status = statuses.length === 0 ? 'pending' : statuses.every((s) => s === 'ok') ? 'ok' : statuses.some((s) => s === 'ok') ? 'partial' : 'fail';
      return { type: t.type, slug: t.slug, url: t.url, status, files };
    });
    const manifest = {
      ticket: 'AssassinFlash/metal-divider#21',
      generated_at: new Date().toISOString(),
      base: BASE,
      final: final,
      config: {
        browser: 'playwright chromium (headless)',
        playwright: pwVersion,
        userAgent: UA,
        locale: 'en-US',
        timezone: 'UTC',
        viewports: args.viewportList,
        fullPage: true,
        reducedMotion: 'reduce',
        killCss: KILL_CSS,
        workers: WORKERS,
        gapMs: GAP_MS,
        retriesPerCapture: 1,
      },
      summary: {
        targets: targets.length,
        ok: list.filter((t) => t.status === 'ok').length,
        partial: list.filter((t) => t.status === 'partial').length,
        fail: list.filter((t) => t.status === 'fail').length,
        screenshots: list.reduce((n, t) => n + Object.values(t.files).filter((f) => f.status === 'ok').length, 0),
      },
      known_masks: [...masks.entries()].map(([selector, pages]) => ({ selector, seen_on: pages })),
      targets: list,
    };
    fs.writeFileSync(path.join(outDir, 'manifest.json'), JSON.stringify(manifest, null, 2));
  }

  for (const viewport of args.viewportList) {
    if (consecutiveFails >= MAX_CONSECUTIVE_FAILURES) break;
    const queue = [...targets];
    const shards = Array.from({ length: WORKERS }, () => []);
    queue.forEach((t, i) => shards[i % WORKERS].push(t));
    await Promise.all(shards.map((shard) => worker(shard, viewport)));
  }

  await browser.close();
  writeManifest(true);
  const s = JSON.parse(fs.readFileSync(path.join(outDir, 'manifest.json'), 'utf8')).summary;
  console.log(`done: ${s.screenshots} screenshots, ${s.ok} ok, ${s.partial} partial, ${s.fail} fail`);
  process.exit(s.fail > 0 || consecutiveFails >= MAX_CONSECUTIVE_FAILURES ? 2 : 0);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
