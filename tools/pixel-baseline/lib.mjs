/**
 * Shared capture protocol of the pixel gate (tickets #21, #30).
 *
 * Everything that must be IDENTICAL between the baseline capture
 * (tools/pixel-baseline/capture.mjs) and the local-instance check
 * (tools/pixel-check/check.mjs) lives here: browser, user agent, viewports,
 * animation kill switch, cookie-banner handling, lazy-load pass and settle
 * gates. AGENTS.md §5: "全页截图由同一浏览器同机同视口拍摄(禁动画、等字体
 * 图片加载)" — one shared module is what makes "same protocol" checkable
 * instead of hoped for.
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

export const UA =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

export const ALL_VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '390', width: 390, height: 844 },
];

// Freeze motion: honored reduced-motion + paused animations + dead transitions.
// Entrance reveals are triggered by the lazy-load scroll pass instead.
export const KILL_CSS = `*, *::before, *::after {
  animation-play-state: paused !important;
  transition: none !important;
  scroll-behavior: auto !important;
}`;

export const COOKIE_ACCEPT_SELECTORS = [
  '#cookie_action_close_header',
  '.cky-btn-accept',
  '#cky-btn-accept',
  'button.cky-banner-element.cky-btn-accept',
  '.cookieyes-btn-accept',
  '#onetrust-accept-btn-handler',
  '#cookiescript_accept',
];

export const BANNER_DETECTORS = [
  '#cky-consent',
  '.cky-consent',
  '.cky-consent-bar',
  '#cookie-law-info-bar',
  '.cookieyes-consent-container',
  '#cookiescript_injected',
  '#onetrust-consent-sdk',
];

export function sha256(file) {
  const h = crypto.createHash('sha256');
  h.update(fs.readFileSync(file));
  return h.digest('hex');
}

/**
 * Browser-context settings of the pixel gate: same viewport size, device
 * scale, locale, timezone and user agent for baseline and check alike.
 */
export function contextOptions(viewport) {
  return {
    viewport: { width: viewport.width, height: viewport.height },
    deviceScaleFactor: 1,
    locale: 'en-US',
    timezoneId: 'UTC',
    userAgent: UA,
  };
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

/**
 * Measure CSS selectors into full-page document coordinates, for masking.
 * Runs with the page scrolled back to the top, so rect + scroll offset is
 * the position in the fullPage screenshot. Returns one entry per selector:
 * null when the selector matches nothing (the caller treats that as an
 * unresolved mask — loud, never silently dropped).
 */
async function measureSelectors(page, selectors) {
  return page.evaluate((sels) => {
    return sels.map((sel) => {
      const el = document.querySelector(sel);
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return {
        selector: sel,
        x: r.left + window.scrollX,
        y: r.top + window.scrollY,
        width: r.width,
        height: r.height,
      };
    });
  }, selectors);
}

/**
 * Computed-style metrics of one selector: the numbers an M2 geometry
 * assertion checks (spec 08 §3.2 track (a): 包围盒位置/宽度、字体、行高).
 */
async function measureGeometry(page, selector) {
  return page.evaluate((sel) => {
    const el = document.querySelector(sel);
    if (!el) return null;
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    return {
      selector: sel,
      x: Math.round((r.left + window.scrollX) * 10) / 10,
      y: Math.round((r.top + window.scrollY) * 10) / 10,
      width: Math.round(r.width * 10) / 10,
      height: Math.round(r.height * 10) / 10,
      fontFamily: cs.fontFamily,
      fontSize: cs.fontSize,
      lineHeight: cs.lineHeight,
    };
  }, selector);
}

/**
 * Capture one target with the pixel-gate protocol. `t` = {type, slug, url}.
 * `maskSelectors` (optional): CSS selectors to measure while the page is
 * settled; their document-coordinate boxes come back as `maskBoxes`.
 * `geometrySelectors` (optional): selectors whose computed geometry comes
 * back as `geometry` (M2 double-track assertions).
 */
export async function captureOne(context, t, vp, outDir, opts = {}) {
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
    // WindPress Tailwind v4 JIT compiles client-side after DOM load; without
    // this wait the screenshot captures an unstyled page (styles still in the
    // VFS blob, not yet injected into <style> tags). On sites without WindPress
    // this passes immediately (style bytes are already stable).
    await page
      .waitForFunction(
        () => {
          let total = 0;
          document.querySelectorAll('style').forEach((s) => { total += s.textContent.length; });
          return total > 50000;
        },
        { timeout: 30_000, polling: 500 }
      )
      .catch(() => null); // no WindPress JIT → pass through
    await page.waitForTimeout(1500);
    const residualBanner = await detectResidualBanner(page);
    const extras = {};
    if (opts.maskSelectors) {
      extras.maskBoxes = await measureSelectors(page, opts.maskSelectors).catch(() => null);
    }
    if (opts.geometrySelectors) {
      extras.geometry = (
        await Promise.all(
          opts.geometrySelectors.map((sel) => measureGeometry(page, sel).catch(() => null))
        )
      ).filter((g) => g !== null);
    }
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
      ...extras,
      ms: Date.now() - started,
    };
  } finally {
    await page.close().catch(() => null);
  }
}

/**
 * Visit the home page once per context so cookie consent is stored before
 * any saved capture — the banner never appears inside a screenshot.
 */
export async function warmup(context, homeUrl) {
  const page = await context.newPage();
  try {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto(homeUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    await page.evaluate(() => document.fonts.ready).catch(() => null);
    await dismissCookieBanner(page);
    await page.waitForTimeout(800);
  } catch (e) {
    console.warn(`[warmup] ${e.message}`);
  } finally {
    await page.close().catch(() => null);
  }
}
