#!/usr/bin/env node
/**
 * Sweep the whole site for visual defects at every required viewport.
 *
 * GOAL.md's coverage matrix asks for every page type at 1440, 390 and 320, and
 * the owner's standing request is a complete per-page checklist rather than a
 * sample. This drives real Chrome (AGENTS.md §7) and runs tools/audit/defects.js
 * on each page × width pair, then writes one checklist.
 *
 * Usage:
 *   NODE_PATH=tools/pixel-check/node_modules node tools/audit/sweep.mjs [--base URL] [--out FILE]
 */
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync } from 'node:fs';

const require = createRequire('/Users/chesterjohn/Documents/metal-divider/tools/pixel-check/package.json');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const base = (args.includes('--base') ? args[args.indexOf('--base') + 1] : 'https://metal-divider.com').replace(/\/$/, '');
const out = args.includes('--out') ? args[args.indexOf('--out') + 1] : '/tmp/md-sweep/report.json';
const cacheBust = base.includes('metal-divider.com') ? '?mdsweep=1' : '';

const PAGES = [
  ['home', '/'],
  ['inquiry', '/inquiry/'],
  ['faqs', '/faqs/'],
  ['custom-screens', '/custom-metal-divider-screens/'],
  ['laser-cut-geometric', '/laser-cut-geometric-metal-screens/'],
  ['glass-hybrid', '/glass-hybrid-screens/'],
  ['outdoor-privacy', '/outdoor-privacy-landscape-screens/'],
  ['chinese-style', '/chinese-style-artistic-screens/'],
  ['stainless-steel', '/stainless-steel-screens/'],
  ['manufacturing', '/manufacturing-process/'],
  ['laser-cut-panels', '/laser-cut-panels/'],
  ['perforated-panels', '/perforated-panels/'],
  ['expanded-metal', '/expanded-metal-panels/'],
  ['project-gallery', '/project-gallery/'],
  ['about-us', '/about-us/'],
  ['privacy', '/privacy/'],
  ['hub', '/collections/'],
  ['shop', '/shop/'],
  ['blog', '/blog/'],
  ['thank-you', '/inquiry/thank-you/'],
  ['col-geometric', '/collections/pattern-geometric/'],
  ['col-abstract', '/collections/pattern-abstract/'],
  ['col-perforated', '/collections/pattern-perforated/'],
  ['col-gradient', '/collections/pattern-gradient/'],
  ['pdp-apiary', '/pattern/apiary-architectural-metal-screen-pattern/'],
  ['pdp-aztec', '/pattern/aztec-architectural-metal-screen-pattern/'],
  ['pdp-herringbone', '/pattern/herringbone-architectural-metal-screen-pattern/'],
  ['pdp-orchid', '/pattern/orchid-lattice-architectural-metal-screen-pattern/'],
  ['pdp-square-rage', '/pattern/square-rage-architectural-metal-screen-pattern/'],
  ['pdp-vlahos', '/pattern/the-vlahos-architectural-metal-screen-pattern/']
];

const WIDTHS = [1440, 390, 320];

const probe = readFileSync('/Users/chesterjohn/Documents/metal-divider/tools/audit/defects.js', 'utf8');

const browser = await chromium.launch({
  channel: 'chrome',
  headless: false,
  args: ['--no-first-run', '--no-default-browser-check']
});
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });

const report = [];

for (const [slug, path] of PAGES) {
  for (const width of WIDTHS) {
    const page = await context.newPage();
    const row = { slug, path, width, url: base + path + cacheBust };
    try {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(base + path + cacheBust, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForSelector('main', { timeout: 30000 });
      await page.evaluate(async () => {
        const step = 700;
        for (let y = 0; y < document.documentElement.scrollHeight; y += step) {
          window.scrollTo(0, y);
          await new Promise((r) => setTimeout(r, 55));
        }
        window.scrollTo(0, 0);
        await new Promise((r) => setTimeout(r, 250));
      });
      const raw = await page.evaluate(probe);
      const parsed = JSON.parse(raw);
      row.http = await page.evaluate(() => document.title);
      row.doc = parsed.doc;
      row.viewport = parsed.viewport;
      row.summary = parsed.summary;
      row.items = parsed.items;
    } catch (error) {
      row.error = String(error).slice(0, 200);
    }
    await page.close();
    report.push(row);
    const s = row.summary ? JSON.stringify(row.summary) : 'ERROR';
    console.log(`${slug}@${width}  ${s}`);
  }
}

await context.close();
await browser.close();

writeFileSync(out, JSON.stringify(report, null, 1));
console.log(`\nwrote ${out}  (${report.length} page×viewport pairs)`);
