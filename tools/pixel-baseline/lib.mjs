/**
 * Shared protocol of the pixel gate (tickets #21, #30).
 *
 * Everything that must be IDENTICAL between the baseline capture
 * (tools/pixel-baseline/capture.mjs) and the local-instance check
 * (tools/pixel-check/check.mjs) lives here: the user agent, the viewports, the
 * animation kill switch, the banner selectors and the hash function. AGENTS.md
 * §5: "全页截图由同一浏览器同机同视口拍摄(禁动画、等字体图片加载)" — one shared
 * module is what makes "same protocol" checkable instead of hoped for.
 *
 * The capture itself is no longer here. Both callers drive opencli against the
 * owner's own Chrome through tools/pixel-check/capture_opencli.py (AGENTS §7:
 * opencli only, never Playwright), and that file carries the settle gates the
 * Playwright routine used to hold.
 */
import fs from 'node:fs';
import crypto from 'node:crypto';

export const UA =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

export const ALL_VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '390', width: 390, height: 844 },
];

/**
 * Paused animations and dead transitions.
 *
 * The gate compares screenshots byte for byte, so a hover state that lands on
 * a different frame each run is noise. capture_opencli.py injects this before
 * it measures anything; it is exported here because the check reports which
 * protocol it ran under.
 */
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
  const hash = crypto.createHash('sha256');
  hash.update(fs.readFileSync(file));
  return hash.digest('hex');
}
