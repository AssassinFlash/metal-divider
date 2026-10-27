#!/usr/bin/env node
/**
 * Measure one section at several viewports in real Chrome.
 *
 * The desktop browser session (opencli) renders at the window's size, so a
 * 390px or 320px measurement has to come from somewhere that can set the
 * viewport. This drives Playwright's Chrome channel against the local instance
 * and reports, per width, whether the section overflows, hugs the edge, or
 * leaves a dead gap.
 *
 * Usage:
 *   NODE_PATH=tools/pixel-check/node_modules node tools/audit/viewport.mjs <url> <selector> [widths]
 */
import { createRequire } from 'node:module';

const require = createRequire('/Users/chesterjohn/Documents/metal-divider/tools/pixel-check/package.json');
const { chromium } = require('playwright');

const url = process.argv[2];
const selector = process.argv[3];
const widths = (process.argv[4] || '1440,768,390,320').split(',').map(Number);

// A profile of its own: the hpanel profile is held open by the interactive
// browser session, and two Chrome processes cannot share one profile directory.
const PROFILE = process.env.MD_VIEWPORT_PROFILE
  || `${process.env.HOME}/metal-divider-local/audit-profile`;

// A plain launch of the installed Chrome, not a persistent profile: the
// interactive session already holds the site's profile, and a second process
// cannot share it. `channel: 'chrome'` is the requirement (AGENTS.md §7: real
// Chrome only, never Playwright's bundled Chromium).
const browser = await chromium.launch({
  channel: 'chrome',
  headless: false,
  args: ['--no-first-run', '--no-default-browser-check']
});
const context = await browser.newContext({ viewport: { width: widths[0], height: 900 } });

const results = [];
try {
  for (const width of widths) {
    const page = await context.newPage();
    await page.setViewportSize({ width, height: 900 });
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForSelector(selector, { timeout: 30000 });
    // Let lazy images resolve by walking the page, then settle back at the top.
    await page.evaluate(async () => {
      const step = 700;
      for (let y = 0; y < document.documentElement.scrollHeight; y += step) {
        window.scrollTo(0, y);
        await new Promise((r) => setTimeout(r, 60));
      }
      window.scrollTo(0, 0);
    });
    const measured = await page.evaluate((sel) => {
      const el = document.querySelector(sel);
      if (!el) return { error: 'selector not found' };
      const vp = { w: innerWidth, h: innerHeight };
      const doc = { w: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight };
      const rect = el.getBoundingClientRect();

      const wide = [];
      const hug = [];
      for (const node of el.querySelectorAll('*')) {
        const r = node.getBoundingClientRect();
        if (r.width > vp.w + 2) {
          wide.push(node.tagName.toLowerCase() + (node.className && typeof node.className === 'string' ? '.' + node.className.trim().split(/\s+/)[0] : '') + ' w=' + Math.round(r.width));
        }
        if (r.width > 24 && (r.left < 5 || r.right > vp.w - 5)) {
          const tag = node.tagName.toLowerCase();
          if (['h2', 'h3', 'p', 'dd', 'dt', 'span', 'a'].includes(tag)) {
            hug.push(tag + ':' + (node.textContent || '').trim().slice(0, 28));
          }
        }
      }

      // Cards: are they all the same height and inside the viewport?
      const cards = [...el.querySelectorAll('article')].map((c) => {
        const r = c.getBoundingClientRect();
        return { x: Math.round(r.x), w: Math.round(r.width), h: Math.round(r.height), right: Math.round(r.right) };
      });

      // A heading or body line taller than the viewport is a layout failure.
      const tall = [...el.querySelectorAll('h2,h3,p')].filter((n) => n.getBoundingClientRect().height > vp.h).length;

      return {
        viewport: vp,
        doc,
        section: { h: Math.round(rect.height), w: Math.round(rect.width) },
        hScroll: doc.w > vp.w + 1,
        widerThanViewport: wide.slice(0, 8),
        edgeHug: [...new Set(hug)].slice(0, 8),
        cards,
        tallBlocks: tall
      };
    }, selector);
    results.push({ width, ...measured });
    await page.close();
  }
} finally {
  await context.close();
  await browser.close();
}

console.log(JSON.stringify(results, null, 1));
