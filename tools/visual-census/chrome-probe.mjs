// Targeted chrome probes that the generic extractor cannot classify reliably:
// header search affordance, CTA clipping, logo box, mobile menu trigger, breadcrumb band,
// floating CTA, quote card, footer. Real Chrome, same protocol as the census.
// Usage: node tools/visual-census/chrome-probe.mjs   (writes $VISUAL_CENSUS_OUT/chrome-probe.json)
import { createRequire } from 'module';
import { writeFileSync } from 'fs';
import { fileURLToPath } from 'url';
const require = createRequire(process.env.PLAYWRIGHT_HOME ? process.env.PLAYWRIGHT_HOME + '/' : fileURLToPath(new URL('../pixel-baseline/', import.meta.url)));
let chromium;
try { ({ chromium } = require('playwright')); } catch { ({ chromium } = createRequire('/tmp/md-chrome/')('playwright')); }
const OUT = process.env.VISUAL_CENSUS_OUT || '/tmp/visual-census';

const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const PAGES = [
  { key: 'home', old: 'https://metalpartition.com/', new: 'https://metal-divider.com/' },
  { key: 'shop', old: 'https://metalpartition.com/collections/all', new: 'https://metal-divider.com/shop/' },
  { key: 'archive', old: 'https://metalpartition.com/collections/pattern-abstract', new: 'https://metal-divider.com/collections/pattern-abstract/' },
  { key: 'family', old: 'https://metalpartition.com/pages/laser-cut-geometric-metal-screens', new: 'https://metal-divider.com/laser-cut-geometric-metal-screens/' },
  { key: 'pdp', old: 'https://metalpartition.com/products/apiary-architectural-metal-screen-pattern', new: 'https://metal-divider.com/pattern/apiary-architectural-metal-screen-pattern/' },
  { key: 'faqs', old: 'https://metalpartition.com/pages/faqs', new: 'https://metal-divider.com/faqs/' },
  { key: 'inquiry', old: 'https://metalpartition.com/pages/contact', new: 'https://metal-divider.com/inquiry/' },
  { key: 'privacy', old: 'https://metalpartition.com/policies/privacy-policy', new: 'https://metal-divider.com/privacy/' },
  { key: 'blog', old: 'https://metalpartition.com/blogs/knowledge', new: 'https://metal-divider.com/blog/' },
];
const VPS = [{ key: '1440x900', w: 1440, h: 900 }, { key: '390x844', w: 390, h: 844 }];

const PROBE = () => {
  const vis = el => { const r = el.getBoundingClientRect(); const c = getComputedStyle(el); return r.width > 0 && r.height > 0 && c.display !== 'none' && c.visibility !== 'hidden'; };
  const box = el => { const r = el.getBoundingClientRect(); return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height), right: Math.round(r.right), bottom: Math.round(r.bottom) }; };
  const txt = el => (el.innerText || el.getAttribute('aria-label') || el.getAttribute('title') || '').replace(/\s+/g, ' ').trim().slice(0, 40);
  const header = document.querySelector('header, [role=banner]');
  const out = { vw: document.documentElement.clientWidth, header: null, search: [], logo: null, menu: null, ctas: [], quoteCard: null, floats: [], breadcrumb: null, footer: null, firstSectionAfterHeader: null };
  if (header && vis(header)) out.header = { box: box(header), bg: getComputedStyle(header).backgroundColor, pos: getComputedStyle(header).position, html: header.outerHTML.length };
  // search affordances inside the header
  const scopes = header ? [header] : [];
  for (const root of scopes) {
    for (const el of root.querySelectorAll('a,button,input,form,[role=search],svg,[class*=search]')) {
      if (!vis(el)) continue;
      const sig = ((el.getAttribute('class') || '') + ' ' + (el.getAttribute('aria-label') || '') + ' ' + (el.getAttribute('href') || '') + ' ' + (el.getAttribute('type') || '') + ' ' + (el.getAttribute('name') || '') + ' ' + (el.querySelector('title')?.textContent || '')).toLowerCase();
      if (!/search|magnif/.test(sig)) continue;
      out.search.push({ tag: el.tagName, sig: sig.slice(0, 60), box: box(el) });
    }
  }
  const logo = header && header.querySelector('img, svg, [class*=logo]');
  if (logo) out.logo = { tag: logo.tagName, src: (logo.currentSrc || logo.src || '').split('/').pop(), box: box(logo), natural: logo.naturalWidth ? logo.naturalWidth + 'x' + logo.naturalHeight : null, cs: (() => { const c = getComputedStyle(logo); return { display: c.display, maxW: c.maxWidth, maxH: c.maxHeight, objectFit: c.objectFit, overflow: c.overflow }; })() };
  for (const el of (header || document).querySelectorAll('button,[role=button],[class*=hamburger],[class*=menu-toggle],[class*=burger]')) {
    if (!vis(el)) continue;
    const sig = ((el.getAttribute('class') || '') + (el.getAttribute('aria-label') || '')).toLowerCase();
    if (!/hamburger|burger|menu-toggle|menu|nav/.test(sig)) continue;
    out.menu = { sig: sig.slice(0, 60), box: box(el) };
    break;
  }
  for (const el of (header || document).querySelectorAll('a,button')) {
    if (!vis(el)) continue;
    const t = txt(el);
    if (!/quote/i.test(t)) continue;
    const c = getComputedStyle(el);
    out.ctas.push({ t, box: box(el), bg: c.backgroundColor, col: c.color, fs: c.fontSize, ff: c.fontFamily.split(',')[0], clippedRight: Math.round(el.getBoundingClientRect().right) > document.documentElement.clientWidth });
  }
  // quote card (hero-embedded conversion card)
  for (const el of document.querySelectorAll('div,section,aside,form')) {
    if (!vis(el)) continue;
    const t = el.innerText || '';
    if (!/custom quote in 24h/i.test(t)) continue;
    const r = el.getBoundingClientRect();
    if (r.height > 600) continue;
    out.quoteCard = { box: box(el), bg: getComputedStyle(el).backgroundColor, border: getComputedStyle(el).borderTopColor, text: t.replace(/\s+/g, ' ').slice(0, 120) };
    break;
  }
  for (const el of document.querySelectorAll('body *')) {
    if (!vis(el)) continue;
    if (getComputedStyle(el).position !== 'fixed') continue;
    const r = el.getBoundingClientRect();
    if (r.width < 30 || r.height < 20) continue;
    out.floats.push({ tag: el.tagName, cls: (el.getAttribute('class') || '').slice(0, 50), text: txt(el), box: box(el), href: el.tagName === 'A' ? el.getAttribute('href') : null });
  }
  const bc = document.querySelector('[aria-label*=readcrumb i], [class*=breadcrumb], .breadcrumbs, nav[aria-label*=readcrumb i]');
  if (bc && vis(bc)) {
    const c = getComputedStyle(bc);
    const section = bc.closest('section,div[class*=breadcrumb],nav');
    out.breadcrumb = { box: box(bc), bg: c.backgroundColor, col: c.color, fs: c.fontSize, ff: c.fontFamily.split(',')[0], text: bc.innerText.replace(/\s+/g, ' ').trim(), links: [...bc.querySelectorAll('a')].map(a => ({ t: a.innerText.trim(), col: getComputedStyle(a).color, td: getComputedStyle(a).textDecorationLine, fs: getComputedStyle(a).fontSize })), bandBg: section ? getComputedStyle(section).backgroundColor : null, bandBox: section ? box(section) : null };
  }
  const f = document.querySelector('footer, [role=contentinfo]');
  if (f && vis(f)) out.footer = { box: box(f), bg: getComputedStyle(f).backgroundColor, links: f.querySelectorAll('a').length, headings: [...f.querySelectorAll('h1,h2,h3,h4,h5')].map(h => h.innerText.trim()).filter(Boolean).slice(0, 12), text: f.innerText.replace(/\s+/g, ' ').slice(0, 300) };
  const main = document.querySelector('main, [role=main]') || document.body;
  const first = [...main.children].find(el => vis(el));
  if (first) out.firstSectionAfterHeader = { tag: first.tagName, cls: (first.getAttribute('class') || '').slice(0, 50), box: box(first), bg: getComputedStyle(first).backgroundColor };
  return out;
};

const b = await chromium.launch({ channel: 'chrome', headless: true });
const results = [];
const jobs = [];
for (const vp of VPS) for (const p of PAGES) for (const side of ['old', 'new']) jobs.push({ vp, p, side });
async function worker(list) {
  for (const { vp, p, side } of list) {
    const ctx = await b.newContext({ viewport: { width: vp.w, height: vp.h }, deviceScaleFactor: 1, locale: 'en-US', timezoneId: 'UTC', userAgent: UA, reducedMotion: 'reduce' });
    const page = await ctx.newPage();
    await page.route(/google-analytics|googletagmanager|hotjar|clarity|facebook|doubleclick|consentmo|cookieyes|gdpr-backpack|consent-tracking/i, r => r.abort());
    try {
      await page.goto(p[side], { waitUntil: 'domcontentloaded', timeout: 45000 });
      await page.waitForLoadState('networkidle', { timeout: 4000 }).catch(() => {});
      await page.evaluate(() => document.fonts.ready).catch(() => {});
      await page.waitForTimeout(1200);
      const data = await page.evaluate(PROBE);
      results.push({ page: p.key, vp: vp.key, side, url: p[side], ...data });
      console.log('ok', p.key, vp.key, side);
    } catch (e) {
      console.log('ERR', p.key, vp.key, side, String(e).slice(0, 120));
      results.push({ page: p.key, vp: vp.key, side, url: p[side], error: String(e).slice(0, 200) });
    }
    await ctx.close();
  }
}
const slices = Array.from({ length: 4 }, () => []);
jobs.forEach((j, i) => slices[i % 4].push(j));
await Promise.all(slices.map(s => worker(s)));
await b.close();
writeFileSync(OUT + '/chrome-probe.json', JSON.stringify(results, null, 1));
console.log('DONE', results.length);
