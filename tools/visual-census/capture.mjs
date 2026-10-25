// Visual census harness: old (metalpartition.com, frozen Shopify) vs new (metal-divider.com, WordPress)
// Real Chrome only. Same machine, same viewport, clean non-authenticated contexts.
// Output: $VISUAL_CENSUS_OUT (default /tmp/visual-census)/facts.json + shots/ + manifest.json.
//
// Usage (from the repo root):
//   VISUAL_CENSUS_OUT=/tmp/visual-census node tools/visual-census/capture.mjs [pageKeyFilter] [viewportFilter]
// Playwright is resolved from tools/pixel-baseline/node_modules (the repo's pixel-gate install);
// set PLAYWRIGHT_HOME=<dir containing node_modules> to use another install.
// The run is resumable: finished jobs are appended to records.jsonl and skipped on the next start.
import { createRequire } from 'module';
import { mkdirSync, writeFileSync, readFileSync, existsSync, appendFileSync } from 'fs';
import { createHash } from 'crypto';
import { fileURLToPath } from 'url';
const require = createRequire(process.env.PLAYWRIGHT_HOME ? process.env.PLAYWRIGHT_HOME + '/' : fileURLToPath(new URL('../pixel-baseline/', import.meta.url)));
let chromium;
try { ({ chromium } = require('playwright')); } catch { ({ chromium } = createRequire('/tmp/md-chrome/')('playwright')); }

const OUT = process.env.VISUAL_CENSUS_OUT || '/tmp/visual-census';
const SHOTS = OUT + '/shots';
mkdirSync(SHOTS, { recursive: true });

const OLD = 'https://metalpartition.com';
const NEW = 'https://metal-divider.com';

// Protocol aligned verbatim with tools/pixel-baseline/lib.mjs (the repo's pixel gate),
// so old-side captures here are directly comparable with the frozen baseline of #21.
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const KILL_CSS = `*, *::before, *::after {
  animation-play-state: paused !important;
  transition: none !important;
  scroll-behavior: auto !important;
}`;
const COOKIE_ACCEPT_SELECTORS = ['#cookie_action_close_header', '.cky-btn-accept', '#cky-btn-accept', 'button.cky-banner-element.cky-btn-accept', '.cookieyes-btn-accept', '#onetrust-accept-btn-handler', '#cookiescript_accept'];
const BANNER_DETECTORS = ['#cky-consent', '.cky-consent', '.cky-consent-bar', '#cookie-law-info-bar', '.cookieyes-consent-container', '#cookiescript_injected', '#onetrust-consent-sdk'];

// One row per page-type; old/new paths differ.
const PAGES = [
  { type: 'home',        key: 'home',        old: '/',                                            new: '/' },
  { type: 'shop',        key: 'shop',        old: '/collections/all',                             new: '/shop/' },
  { type: 'hub',         key: 'hub',         old: '/collections/',                                new: '/collections/' },
  { type: 'archive',     key: 'arc-abstract',     old: '/collections/pattern-abstract',          new: '/collections/pattern-abstract/' },
  { type: 'archive',     key: 'arc-asian',        old: '/collections/pattern-asian',             new: '/collections/pattern-asian/' },
  { type: 'archive',     key: 'arc-geometric',    old: '/collections/pattern-geometric',         new: '/collections/pattern-geometric/' },
  { type: 'archive',     key: 'arc-gradient',     old: '/collections/pattern-gradient',          new: '/collections/pattern-gradient/' },
  { type: 'archive',     key: 'arc-landscape',    old: '/collections/pattern-landscape',         new: '/collections/pattern-landscape/' },
  { type: 'archive',     key: 'arc-moroccan',     old: '/collections/pattern-moroccan-arabic',   new: '/collections/pattern-moroccan-arabic/' },
  { type: 'archive',     key: 'arc-organic',      old: '/collections/pattern-organic',           new: '/collections/pattern-organic/' },
  { type: 'archive',     key: 'arc-perforated',   old: '/collections/pattern-perforated',        new: '/collections/pattern-perforated/' },
  { type: 'archive',     key: 'arc-vintage',      old: '/collections/pattern-vintage-retro',     new: '/collections/pattern-vintage-retro/' },
  { type: 'family',      key: 'fam-laser-geo',    old: '/pages/laser-cut-geometric-metal-screens', new: '/laser-cut-geometric-metal-screens/' },
  { type: 'family',      key: 'fam-glass-hybrid', old: '/pages/glass-hybrid-screens',            new: '/glass-hybrid-screens/' },
  { type: 'family',      key: 'fam-outdoor',      old: '/pages/outdoor-privacy-landscape-screens', new: '/outdoor-privacy-landscape-screens/' },
  { type: 'family',      key: 'fam-chinese',      old: '/pages/chinese-style-artistic-screens',  new: '/chinese-style-artistic-screens/' },
  { type: 'family',      key: 'fam-stainless',    old: '/pages/stainless-steel-screens',         new: '/stainless-steel-screens/' },
  { type: 'family',      key: 'fam-custom',       old: '/pages/custom-metal-divider-screens',    new: '/custom-metal-divider-screens/' },
  { type: 'family',      key: 'fam-laser-panels', old: '/pages/laser-cut-panels',                new: '/laser-cut-panels/' },
  { type: 'family',      key: 'fam-perf-panels',  old: '/pages/perforated-panels',               new: '/perforated-panels/' },
  { type: 'family',      key: 'fam-expanded',     old: '/pages/expanded-metal-panels',           new: '/expanded-metal-panels/' },
  { type: 'pdp',         key: 'pdp-apiary',       old: '/products/apiary-architectural-metal-screen-pattern', new: '/pattern/apiary-architectural-metal-screen-pattern/' },
  { type: 'pdp',         key: 'pdp-zelda',        old: '/products/zelda-architectural-metal-screen-pattern',  new: '/pattern/zelda-architectural-metal-screen-pattern/' },
  { type: 'pdp',         key: 'pdp-tafoni',       old: '/products/tafoni-architectural-metal-screen-pattern', new: '/pattern/tafoni-architectural-metal-screen-pattern/' },
  { type: 'about',       key: 'about',       old: '/pages/about-us',                              new: '/about-us/' },
  { type: 'faqs',        key: 'faqs',        old: '/pages/faqs',                                  new: '/faqs/' },
  { type: 'inquiry',     key: 'inquiry',     old: '/pages/contact',                               new: '/inquiry/' },
  { type: 'thankyou',    key: 'thankyou',    old: '/pages/custom-metal-divider-screens-thank-you', new: '/inquiry/thank-you/' },
  { type: 'gallery',     key: 'gallery',     old: '/pages/project-gallery',                       new: '/project-gallery/' },
  { type: 'manufacturing', key: 'mfg',       old: '/pages/manufacturing-process',                 new: '/manufacturing-process/' },
  { type: 'privacy',     key: 'privacy',     old: '/policies/privacy-policy',                     new: '/privacy/' },
  { type: 'blog-index',  key: 'blog-index',  old: '/blogs/knowledge',                             new: '/blog/' },
  { type: 'blog-single', key: 'blog-single', old: '/blogs/knowledge/test-blog-1',                 new: '/blog/test-blog-1/' },
  { type: 'blog-cat',    key: 'blog-cat',    old: '/blogs/knowledge/tagged/news',                 new: '/category/design-guide/' },
  { type: 'search',      key: 'search',      old: '/search?q=divider',                            new: '/?s=divider' },
];

const VPS = [
  { key: '1440x900', w: 1440, h: 900, mobile: false },
  { key: '390x844', w: 390, h: 844, mobile: true },
];

const EXTRACT = () => {
  const clip = (s, n = 100) => (s == null ? '' : String(s)).replace(/\s+/g, ' ').trim().slice(0, n);
  const cls = el => (typeof el.className === 'string' ? el.className : (el.getAttribute && el.getAttribute('class')) || '');
  const st = el => {
    const c = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    return {
      fs: Math.round(parseFloat(c.fontSize) * 10) / 10,
      ff: (c.fontFamily || '').split(',')[0].replace(/["']/g, ''),
      fw: c.fontWeight,
      lh: c.lineHeight === 'normal' ? 'normal' : Math.round(parseFloat(c.lineHeight) * 10) / 10,
      ls: c.letterSpacing,
      col: c.color,
      bg: c.backgroundColor,
      mt: Math.round(parseFloat(c.marginTop) * 10) / 10,
      mb: Math.round(parseFloat(c.marginBottom) * 10) / 10,
      pt: Math.round(parseFloat(c.paddingTop) * 10) / 10,
      pb: Math.round(parseFloat(c.paddingBottom) * 10) / 10,
      w: Math.round(r.width), h: Math.round(r.height),
      x: Math.round(r.x), y: Math.round(r.y + window.scrollY),
      td: c.textDecorationLine, tt: c.textTransform, pos: c.position, ws: c.whiteSpace,
      disp: c.display, vis: c.visibility, op: c.opacity,
    };
  };
  const vis = el => {
    if (!el) return false;
    const r = el.getBoundingClientRect();
    const c = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && c.display !== 'none' && c.visibility !== 'hidden' && parseFloat(c.opacity) > 0.05;
  };
  const out = { url: location.href, title: document.title, lang: document.documentElement.lang, vw: document.documentElement.clientWidth, vh: window.innerHeight, docH: document.documentElement.scrollHeight, scrollW: document.scrollingElement.scrollWidth, hOverflow: document.scrollingElement.scrollWidth - document.documentElement.clientWidth, metaViewport: document.querySelector('meta[name=viewport]')?.content || null, canonical: document.querySelector('link[rel=canonical]')?.href || null, bodyText: clip(document.body.innerText, 60000), wordCount: (document.body.innerText || '').trim().split(/\s+/).filter(Boolean).length };

  out.body = st(document.body);
  out.bodyFont = out.body.ff + ' / ' + out.body.fs + 'px';
  // html overflow offenders
  out.overflowEls = [];
  for (const el of document.querySelectorAll('body *')) {
    if (!vis(el)) continue;
    const r = el.getBoundingClientRect();
    if (r.right > out.vw + 1 && out.overflowEls.length < 15) out.overflowEls.push({ tag: el.tagName, cls: clip(cls(el), 60), left: Math.round(r.left), right: Math.round(r.right), w: Math.round(r.width), text: clip(el.innerText, 40) });
  }
  // header / nav
  const header = document.querySelector('header, .header, #shopify-section-header, [role=banner]');
  out.header = header && vis(header) ? st(header) : null;
  out.navs = [];
  for (const nav of document.querySelectorAll('header nav, nav, [class*=nav-menu], [class*=menu-wrapper]')) {
    if (!vis(nav) || out.navs.length >= 4) continue;
    const links = [...nav.querySelectorAll('a')].filter(vis).slice(0, 60).map(a => {
      const s = st(a);
      return { t: clip(a.innerText || a.getAttribute('aria-label'), 40), href: a.getAttribute('href'), fs: s.fs, ff: s.ff, fw: s.fw, col: s.col, bg: s.bg, x: s.x, y: s.y, w: s.w, h: s.h, ws: s.ws, td: s.td };
    });
    const rows = [...new Set(links.map(l => l.y))].length;
    out.navs.push({ cls: clip(cls(nav), 60), w: st(nav).w, h: st(nav).h, scrollW: nav.scrollWidth, clientW: nav.clientWidth, overflowPx: nav.scrollWidth - nav.clientWidth, rows, links });
  }
  // header CTA candidates
  out.headerCtas = [...document.querySelectorAll('header a, header button, [role=banner] a, [role=banner] button')].filter(vis)
    .filter(el => { const c = getComputedStyle(el); return c.backgroundColor !== 'rgba(0, 0, 0, 0)' || /btn|cta|button/i.test(cls(el)); })
    .slice(0, 8).map(el => { const s = st(el); return { t: clip(el.innerText || el.getAttribute('aria-label'), 40), ...s }; });
  // headings in DOM order
  out.headings = [...document.querySelectorAll('h1,h2,h3,h4')].filter(vis).slice(0, 80).map(h => ({ tag: h.tagName, t: clip(h.innerText, 90), ...st(h) }));
  out.h1 = out.headings.filter(h => h.tag === 'H1');
  // breadcrumb
  out.breadcrumbs = [];
  for (const el of document.querySelectorAll('[aria-label*=readcrumb i], [class*=breadcrumb], .breadcrumbs, nav[aria-label*=readcrumb i]')) {
    if (!vis(el) || out.breadcrumbs.length >= 3) continue;
    const s = st(el);
    const links = [...el.querySelectorAll('a')].filter(vis).map(a => ({ t: clip(a.innerText, 30), col: getComputedStyle(a).color, td: getComputedStyle(a).textDecorationLine }));
    out.breadcrumbs.push({ cls: clip(cls(el), 60), text: clip(el.innerText, 160), links, ...s });
  }
  // section headings in DOM order (main-level blocks)
  out.sections = [];
  const main = document.querySelector('main, #MainContent, .main-content, [role=main]') || document.body;
  for (const sec of [...main.querySelectorAll(':scope > *, :scope > * > section, :scope > section')].slice(0, 80)) {
    if (!vis(sec)) continue;
    const h = sec.querySelector('h1,h2,h3');
    out.sections.push({ tag: sec.tagName, cls: clip(cls(sec), 50), id: sec.id || '', heading: h ? h.tagName + ': ' + clip(h.innerText, 70) : null, h: st(sec).h, y: st(sec).y, imgs: sec.querySelectorAll('img').length });
  }
  // cards (product / collection / feature cards) — same class families both stacks share
  out.cards = [];
  for (const el of document.querySelectorAll('[class*=product-card], [class*=collection-card], [class*=feature-card], [class*=product-item], [class*=grid-item]')) {
    if (!vis(el) || out.cards.length >= 14) continue;
    const s = st(el);
    const h = el.querySelector('h2,h3,h4,[class*=title],[class*=name]');
    const img = el.querySelector('img');
    out.cards.push({ cls: clip(cls(el), 50), w: s.w, h: s.h, x: s.x, y: s.y, title: h ? clip(h.innerText, 50) : null, titleFs: h ? st(h).fs : null, titleFf: h ? st(h).ff : null, img: img ? (img.currentSrc || img.src).split('/').pop().slice(0, 60) : null, imgW: img ? Math.round(img.getBoundingClientRect().width) : null, imgH: img ? Math.round(img.getBoundingClientRect().height) : null });
  }
  // first large media band (hero) under the header
  out.hero = null;
  const hdr = document.querySelector('header, [role=banner]');
  for (const el of document.querySelectorAll('section, div, main > *')) {
    if (!vis(el)) continue;
    const r = el.getBoundingClientRect();
    if (r.height < 320 || r.width < window.innerWidth * 0.6) continue;
    if (hdr && (el.contains(hdr) || hdr.contains(el))) continue;
    const img = el.querySelector('img');
    const bg = getComputedStyle(el).backgroundImage;
    if (!img && !(bg && /url\(/.test(bg))) continue;
    out.hero = { cls: clip(cls(el), 60), w: Math.round(r.width), h: Math.round(r.height), y: Math.round(r.y + window.scrollY), img: img ? (img.currentSrc || img.src) : null, imgAlt: img ? clip(img.alt, 60) : null, imgNatural: img ? img.naturalWidth + 'x' + img.naturalHeight : null, imgRendered: img ? Math.round(img.getBoundingClientRect().width) + 'x' + Math.round(img.getBoundingClientRect().height) : null, bgimg: bg && bg !== 'none' ? bg.slice(0, 200) : null };
    break;
  }
  // price text presence (catalogue is $0 display-only)
  const priceMatch = (document.body.innerText.match(/\$\s?[\d,]+(\.\d+)?/g) || []).slice(0, 6);
  out.prices = priceMatch;
  // images
  out.images = [...document.querySelectorAll('img')].filter(vis).slice(0, 40).map(i => {
    const r = i.getBoundingClientRect();
    return { src: (i.currentSrc || i.src || '').split('/').slice(-2).join('/').slice(0, 100), alt: clip(i.alt, 60), nw: i.naturalWidth, nh: i.naturalHeight, w: Math.round(r.width), h: Math.round(r.height), y: Math.round(r.y + window.scrollY), srcset: !!i.srcset, loading: i.loading };
  });
  // background-image hero-ish elements
  out.bgImages = [];
  for (const el of document.querySelectorAll('section,div,header,[class*=hero]')) {
    if (!vis(el) || out.bgImages.length >= 8) continue;
    const bi = getComputedStyle(el).backgroundImage;
    if (bi && bi !== 'none' && /url\(/.test(bi)) {
      const s = st(el);
      out.bgImages.push({ cls: clip(cls(el), 50), bgimg: bi.slice(0, 160), w: s.w, h: s.h, y: s.y });
    }
  }
  // chrome features
  const linkHrefs = [...document.querySelectorAll('a[href]')].map(a => a.getAttribute('href') || '');
  out.chrome = {
    searchInput: !!document.querySelector('input[type=search], form[role=search], [role=search]'),
    searchLinks: linkHrefs.filter(h => /search/i.test(h)).slice(0, 5),
    whatsapp: linkHrefs.filter(h => /wa\.me|whatsapp/i.test(h)).slice(0, 5),
    cart: linkHrefs.filter(h => /cart/i.test(h)).slice(0, 5),
    account: linkHrefs.filter(h => /account|login/i.test(h)).slice(0, 5),
    currency: !!document.querySelector('[class*=currency], [data-currency], select[name=currency]'),
    quoteText: /request a quote|get a quote|request quote|quote/i.test(document.body.innerText),
    quoteEls: [...document.querySelectorAll('a,button')].filter(vis).filter(e => /quote/i.test(e.innerText || '')).slice(0, 6).map(e => ({ tag: e.tagName, t: clip(e.innerText, 40), href: e.getAttribute('href'), bg: getComputedStyle(e).backgroundColor, col: getComputedStyle(e).color, ...st(e) })),
    turnstile: !!document.querySelector('[class*=turnstile], iframe[src*="challenges.cloudflare"]'),
    cookieBanner: !!(document.querySelector('[class*=cookie]') && vis(document.querySelector('[class*=cookie]'))),
    fixed: [...document.querySelectorAll('body *')].filter(vis).filter(e => getComputedStyle(e).position === 'fixed').slice(0, 10).map(e => ({ tag: e.tagName, cls: clip(cls(e), 40), t: clip(e.innerText, 30), w: Math.round(e.getBoundingClientRect().width), h: Math.round(e.getBoundingClientRect().height), x: Math.round(e.getBoundingClientRect().x), y: Math.round(e.getBoundingClientRect().y) })),
  };
  out.forms = [...document.querySelectorAll('form')].filter(vis).map(f => ({ id: f.id, action: (f.getAttribute('action') || '').slice(0, 80), fields: f.querySelectorAll('input:not([type=hidden]),textarea,select').length, labels: [...f.querySelectorAll('label')].slice(0, 20).map(l => clip(l.innerText, 40)), submit: [...f.querySelectorAll('button,[type=submit]')].filter(vis).map(b => clip(b.innerText || b.value, 40)).slice(0, 4) }));
  out.buttons = [...document.querySelectorAll('a,button')].filter(vis).filter(e => /btn|button|cta/i.test(cls(e))).slice(0, 15).map(e => { const s = st(e); return { t: clip(e.innerText, 40), ...s }; });
  out.touchTargets = { total: 0, small: 0 };
  for (const el of document.querySelectorAll('a,button,input,select,textarea,[role=button]')) {
    if (!vis(el)) continue;
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) continue;
    out.touchTargets.total++;
    if (r.height < 44 || r.width < 44) out.touchTargets.small++;
  }
  // footer
  const footer = document.querySelector('footer, [role=contentinfo]');
  out.footer = footer && vis(footer) ? { ...st(footer), links: footer.querySelectorAll('a').length, headings: [...footer.querySelectorAll('h1,h2,h3,h4,h5')].map(h => clip(h.innerText, 40)).slice(0, 12) } : null;
  return out;
};

async function loadPage(page, url) {
  const rec = { url };
  try {
    const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
    rec.status = resp ? resp.status() : null;
    rec.finalUrl = page.url();
    rec.redirected = page.url().replace(/\/$/, '') !== url.replace(/\/$/, '');
    await page.waitForLoadState('networkidle', { timeout: 4000 }).catch(() => {});
    await page.evaluate(() => (document.fonts ? document.fonts.ready : null)).catch(() => {});
    // disable animation/transition for stable capture
    await page.addStyleTag({ content: KILL_CSS }).catch(() => {});
    // cookie banner: same accept list as the pixel gate, so old/new see identical chrome
    for (const sel of COOKIE_ACCEPT_SELECTORS) {
      try {
        const el = page.locator(sel).first();
        if ((await el.count()) > 0 && (await el.isVisible({ timeout: 800 }))) {
          await el.click({ timeout: 2500, force: true }).catch(() => null);
          rec.bannerClicked = sel;
          await page.waitForTimeout(400);
          break;
        }
      } catch {}
    }
    // generic fallback: bottom-fixed consent bar with an Accept button (the old site's engine
    // does not use the gate's class list); clicked through the DOM so both sides show identical chrome
    if (!rec.bannerClicked) {
      rec.bannerClicked = await page.evaluate(() => {
        for (const b of document.querySelectorAll('button, a, [role=button]')) {
          const t = (b.innerText || b.value || '').trim();
          const r = b.getBoundingClientRect();
          if (!/^(accept|accept all|i agree|got it|allow all)$/i.test(t)) continue;
          if (r.width === 0 || r.height === 0 || r.top < 200) continue;
          b.click();
          return 'generic:' + t;
        }
        return null;
      }).catch(() => null);
      await page.waitForTimeout(500);
    }
    // lazy-load sweep
    await page.evaluate(async () => {
      const sleep = ms => new Promise(r => setTimeout(r, ms));
      const step = Math.max(300, window.innerHeight * 0.85);
      const h = () => document.documentElement.scrollHeight;
      for (let y = 0; y < h() && y < 20000; y += step) { window.scrollTo(0, y); await sleep(80); }
      window.scrollTo(0, h());
      await sleep(500);
      await Promise.race([
        Promise.all([...document.images].filter(i => !i.complete).map(i => new Promise(res => { i.onload = i.onerror = res; setTimeout(res, 3000); }))),
        sleep(3000),
      ]);
      window.scrollTo(0, 0);
      await sleep(400);
    }).catch(() => {});
    // stability: height unchanged for 3 polls
    let last = -1, stable = 0;
    for (let i = 0; i < 12 && stable < 3; i++) {
      const hh = await page.evaluate(() => document.documentElement.scrollHeight).catch(() => 0);
      if (hh === last) stable++; else { stable = 0; last = hh; }
      await page.waitForTimeout(200);
    }
    await page.waitForTimeout(300);
    rec.facts = await page.evaluate(EXTRACT);
    rec.residualBanner = await page.evaluate(sels => sels.some(s => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(e).visibility !== 'hidden'; }), BANNER_DETECTORS).catch(() => null);
  } catch (e) {
    rec.error = String(e).slice(0, 300);
  }
  return rec;
}

async function shot(page, file, vp) {
  try {
    await page.screenshot({ path: file, fullPage: true, animations: 'disabled', timeout: 60000 });
    await page.screenshot({ path: file.replace('-full.png', '-fold.png'), clip: { x: 0, y: 0, width: vp.w, height: vp.h }, animations: 'disabled', timeout: 30000 });
    return true;
  } catch (e) { return false; }
}

const only = process.argv[2] || null; // filter substring on key or side:url
const vpFilter = process.argv[3] || null;

const jobs = [];
for (const p of PAGES) for (const vp of VPS) {
  if (vpFilter && vp.key !== vpFilter) continue;
  if (only && !(p.key.includes(only) || p.type.includes(only))) continue;
  jobs.push({ p, vp, side: 'old', url: OLD + p.old, key: p.key + '-old' });
  jobs.push({ p, vp, side: 'new', url: NEW + p.new, key: p.key + '-new' });
}

const browser = await chromium.launch({ channel: 'chrome', headless: true, args: ['--disable-lcd-text'] });

// resume: records are appended to JSONL as each job finishes
const JSONL = OUT + '/records.jsonl';
const done = new Map();
if (existsSync(JSONL)) {
  for (const line of readFileSync(JSONL, 'utf8').split('\n')) {
    if (!line.trim()) continue;
    try { const r = JSON.parse(line); done.set(r.page + '|' + r.viewport + '|' + r.side, r); } catch {}
  }
}
const results = [...done.values()];
const todo = jobs.filter(j => {
  const k = j.p.key + '|' + j.vp.key + '|' + j.side;
  const prev = done.get(k);
  if (!prev) return true;
  if (!prev.status || !prev.facts) return true;
  if (prev.error) return true;
  if (prev.status && prev.status < 400 && !prev.shotOk) return true;
  return false;
});
console.log(`RESUME ${results.length} done, ${todo.length} to go of ${jobs.length}`);

process.on('unhandledRejection', e => console.error('UNHANDLED', String(e).slice(0, 400)));
process.on('uncaughtException', e => console.error('UNCAUGHT', String(e).slice(0, 400)));

async function worker(slots) {
  for (const job of slots) {
    let ctx = null;
    try {
      ctx = await browser.newContext({
        viewport: { width: job.vp.w, height: job.vp.h },
        deviceScaleFactor: 1, isMobile: false, hasTouch: false,
        locale: 'en-US', timezoneId: 'UTC',
        userAgent: UA,
        reducedMotion: 'reduce',
      });
      const page = await ctx.newPage();
      // block heavy third-party trackers that do not affect layout
      await page.route(/google-analytics|googletagmanager|hotjar|clarity\.ms|facebook\.net|doubleclick|consentmo|cookieyes|gdpr-backpack|consent-tracking/i, r => r.abort());
      const t0 = Date.now();
      const rec = await loadPage(page, job.url);
      const base = `${SHOTS}/${job.key}-${job.vp.key}`;
      rec.page = job.p.key; rec.type = job.p.type; rec.viewport = job.vp.key; rec.side = job.side; rec.shot = base;
      rec.shotOk = rec.status && rec.status < 400 ? await shot(page, base + '-full.png', job.vp) : false;
      rec.ms = Date.now() - t0;
      results.push(rec);
      appendFileSync(JSONL, JSON.stringify(rec) + '\n');
      console.log(`${rec.status ?? 'ERR'} ${job.key} ${job.vp.key} ${rec.facts ? rec.facts.headings.length + 'h/' + rec.facts.images.length + 'img' : 'nofacts'} ${rec.ms}ms ${rec.shotOk ? 'shot' : 'NO-SHOT'}${rec.error ? ' ERR:' + rec.error.slice(0, 80) : ''}`);
    } catch (e) {
      console.error('JOB FAIL', job.key, job.vp.key, String(e).slice(0, 200));
      appendFileSync(JSONL, JSON.stringify({ page: job.p.key, type: job.p.type, viewport: job.vp.key, side: job.side, url: job.url, error: String(e).slice(0, 300), ms: 0 }) + '\n');
    } finally {
      try { await ctx?.close(); } catch {}
    }
  }
}

const N = Math.max(1, Math.min(4, todo.length));
function pending() {
  const ok = new Map();
  for (const r of results) if (!r.error && r.status && r.facts && r.shotOk) ok.set(r.page + '|' + r.viewport + '|' + r.side, 1);
  return jobs.filter(j => !ok.has(j.p.key + '|' + j.vp.key + '|' + j.side));
}
for (let pass = 0; pass < 3; pass++) {
  const rem = pass === 0 ? todo : pending();
  if (!rem.length) break;
  const slices = Array.from({ length: Math.min(N, rem.length) }, () => []);
  rem.forEach((j, i) => slices[i % slices.length].push(j));
  if (pass > 0) console.log(`RETRY PASS ${pass}: ${rem.length} jobs`);
  await Promise.all(slices.map(s => worker(s)));
}
await browser.close();

// screenshot manifest with sha256
const dedup = new Map();
for (const r of results) {
  const k = r.page + '|' + r.viewport + '|' + r.side;
  const prev = dedup.get(k);
  if (!prev) { dedup.set(k, r); continue; }
  const score = x => (x.facts ? 4 : 0) + (x.status && x.status < 400 ? 2 : 0) + (x.shotOk ? 1 : 0) - (x.error ? 10 : 0);
  if (score(r) >= score(prev)) dedup.set(k, r);
}
const finalResults = [...dedup.values()].sort((a, b) => (a.page + a.viewport + a.side).localeCompare(b.page + b.viewport + b.side));
const manifest = [];
for (const r of finalResults) {
  for (const suffix of ['-full.png', '-fold.png']) {
    const f = r.shot + suffix;
    if (existsSync(f)) manifest.push({ file: f.replace(OUT + '/', ''), page: r.page, viewport: r.viewport, side: r.side, sha256: createHash('sha256').update(readFileSync(f)).digest('hex'), bytes: readFileSync(f).length });
  }
}
writeFileSync(OUT + '/facts.json', JSON.stringify({ generatedAt: new Date().toISOString(), protocol: { browser: 'Google Chrome (channel: chrome, headless) via Playwright', userAgent: UA, locale: 'en-US', timezone: 'UTC', viewports: VPS, reducedMotion: 'reduce', killCss: KILL_CSS, cookieAcceptSelectors: COOKIE_ACCEPT_SELECTORS, trackedRequestsAborted: ['google-analytics', 'googletagmanager', 'hotjar', 'clarity.ms', 'facebook.net', 'doubleclick', 'consentmo', 'cookieyes', 'gdpr-backpack', 'consent-tracking'] }, pages: PAGES, viewports: VPS, results: finalResults }, null, 1));
writeFileSync(OUT + '/manifest.json', JSON.stringify(manifest, null, 1));
console.log(`\nDONE ${finalResults.length} records, ${manifest.length} screenshots -> ${OUT}`);
