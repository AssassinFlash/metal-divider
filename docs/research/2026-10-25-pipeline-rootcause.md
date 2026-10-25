# Conversion pipeline visual-baseline root cause

**Ticket:** #62 — Research: 转换管线视觉基座根因审计
**Date:** 2026-10-25
**Status:** research only. No production code, template or volume changed.
**Recommendation, not decision:** the repair choice belongs to the strategy ticket.

- Repo audited: `main` @ `8859209` (this branch's base).
- Production audited: `metal-divider.com`, running the `imp/58-arbitrary-values` line (local tip `569ca0a`; the live cache contains the rules added by `5b80a70`, the out-of-band "Production fix round").
- Old site: `metalpartition.com`, live; read-only theme at `~/Documents/shopify-builder/theme`; Tailwind config at `~/Documents/shopify-builder/scripts/tailwind/tailwind.config.js`.
- New compiled CSS: `https://metal-divider.com/wp-content/uploads/windpress/cache/tailwind.css?ver=1792939056` (464,835 bytes, generated 2026-10-25 22:37 CST).
- Old compiled CSS: `https://metalpartition.com/cdn/shop/t/7/assets/tailwind-production.css?v=50266062223174944081783409175` (118,440 bytes).

---

## 0. TL;DR

The converter did not "change values". It carried markup and declarations almost verbatim, then **selected the wrong subset of the old cascade and dropped the context that gave those values their meaning**. Five selection/context losses account for the symptoms:

1. **The class tokenizer is quote-blind.** `collect_classes()` only reads `class="…"` (double quotes); the old theme renders large parts of its markup with `class='…'` (single quotes). 54 of the 90 `main.css` class tokens used by the templates have no matching rule in any of the 36 volumes (selector-presence cross-check). The header loses 62 of its 109 classes; the breadcrumb family loses 9 of 9. (`tools/template-convert/src/md_template_convert/csslib.py:56-61`)
2. **Rules without a class are discarded.** `Stylesheet` puts class-less rules in `_bare` and `distribute()` never reads `_bare`. The old global baseline lives exactly there: `html { font-size: 62.5% }`, `body { font-family: var(--font-body-family) }`, the `h1…h6` reset, `a`, `img { max-width:100% }`, `xo-container`, `#xo-root`. 140 class-less rules from `main.css` were dropped. (`csslib.py:167-174`, `convert.py:223-256`)
3. **The old design tokens are outside the converter's input model.** The 174 custom properties in the rendered `settings-adapter` `:root` block — font families, `--font-heading-scale: 1.4`, `--color-background`, `--color-foreground`, the 56/42/32/24/20 heading scale — are printed into `<head>`, not into a Shopify section. The converter only reads section wrappers (`sections.py:19-65`), so the block never entered any volume. `tokens.css` carries 12 `--md-*` tokens and no body/heading/scale/color-scheme rules. 42 variables are used but never defined in the exported volumes (excluding Tailwind's runtime `--tw-*`, the design-critical ones are `--color-foreground`, `--color-background`, `--color-accent`, `--font-body-scale`, `--font-heading-scale`, `--spacing-sections-*`).
4. **The old rem scale and the old Tailwind scale were not carried.**
   - Old root: `html { font-size: 62.5% }` → **1rem = 10px** (measured: a 1rem probe computes 10px). New root: **1rem = 16px**. Every `rem` value in the carried volumes and every rem-based Tailwind utility renders **1.6×** larger. Example: `.xo-container--wide { padding: 14rem }` (old `main.css:8811-8813`, new `chrome.css:90-94`) renders 140px old, 224px new.
   - Old Tailwind `fontSize` was a custom **px** scale (`5xl = 42px`, `7xl = 56px`; `scripts/tailwind/tailwind.config.js:21-36`); WindPress compiles Tailwind v4 defaults (`--text-5xl: 3rem = 48px`, `--text-7xl: 4.5rem = 72px`). The markup still says `md:text-5xl` / `lg:text-7xl`, so the classes are unchanged and the rendered sizes grow.
5. **The runtime and layout-level assets are outside the section model.** T3 strips every `<script>` (`transform.py:57-61`); the old runtime was `xo-webcomponents` + Alpine + app scripts. The quote modal is rendered by `layout/theme.liquid:275`, the support desk by `layout/theme.layout.liquid:322`, and the WhatsApp float is injected by the Moosedesk app — none is inside a Shopify section, so none was captured. The `#open-quote` CTAs survive but their target does not.

Two secondary mechanisms make individual symptoms worse:

6. **Inline declarations became class rules.** Where inline styles were folded, an old inline declaration (specificity ∞) became a class rule (0,1,0/0,1,1). The deployed overlay's ID rule now outranks it. The nav label is the proof: old inline `font-size: 13px` (`snippets/menu-horizontal-v1.liquid:127-133`), new `h3.xo-menu-horizontal-v1__label { font-size: 13px }` (`chrome.css:14`) loses to `#HeaderV9-… .xo-menu-horizontal-v1__label { font-size: 1.4rem }` (`imp/58 chrome.css:308-315`, commit `5b80a70`) → 22.4px. The same fold produced three invalid duplicate `class` attributes in the header (`header.html:104`), where the browser ignores the second attribute and its minted style.
7. **The deployed overlay is not on `main`.** The 22.4px nav and the white header background come from the overlay in `5b80a70`; the invisible search icon and the missing gutter are the overlay's values colliding with the dropped baseline (white icon on the overlay's white header; no container rule to give the grid a gutter). A naive converter re-run from `main` would overwrite the overlay and change the nav to 13px Times (still wrong), so any repair must reconcile the two first.

The three listed type-size symptoms are the visible tip. The measured 1.6× rem inflation touches 856 `rem` occurrences in the 36 volumes and every rem-based Tailwind utility; it is the largest unlisted divergence.

---

## 1. Method and evidence

### 1.1 Static audit

- Read all of `tools/template-convert/src/md_template_convert/` (10 modules, 2,392 lines with tests), the 36 volumes (`plugins/md-site/content/windpress/*.css`, 10,821 lines), `tokens.css`, the 25 LiveCanvas page templates and 5 partials (`plugins/md-site/content/livecanvas/`, 32,217 lines), and the presentation plugin's volume installer.
- Read the old theme: `assets/main.css` (25,226 lines), `snippets/settings-adapter.liquid`, `snippets/menu-horizontal-v1.liquid`, `snippets/quote-modal.liquid`, `snippets/landing-support-desk.liquid`, `sections/header-v1.liquid`, `sections/home-hero-ioh.liquid`, `config/settings_data.json`, and the Tailwind config.
- Reproduced the converter's selection behaviour on the real files with tinycss2 (`uv run --with tinycss2`), not by reading code alone.

### 1.2 Live verification (real Chrome, owner's rule)

- Local real Chrome (`channel: 'chrome'`) with the persistent profile `~/metal-divider-local/hpanel-profile`, 1440×900, locale en-US.
- Pages measured: old/new home, old/new `/pages/laser-cut-geometric-metal-screens`, old/new PDP (`jaipur-architectural-metal-screen-pattern`).
- Methods: `getComputedStyle`, a `1rem` probe appended to the live DOM, CDP `CSS.getMatchedStylesForNode` for authoritative matched rules, and header/breadcrumb screenshots.
- No production write. Screenshots and probe scripts live in `.scratch/62/` (gitignored).

### 1.3 What the converter actually transforms

T1 strips Shopify scaffolding, T3 strips scripts, T4 maps asset URLs, T7 makes URLs root-relative. **None of them rewrites a CSS value or text case.** T2 (styles into volumes) is the only style path, and its failure mode is *selection*: which rules are indexed, which volumes they land in, and which context (root scale, variable definitions, utility scale) they are rendered against. The upgrade pass only replaces inquiry forms. This matters for the repair options: there is no wrong arithmetic to undo, only missing rules and missing context.

---

## 2. Root-cause detail

### C1. The class tokenizer only reads double-quoted attributes

`csslib.py:56-61`:

```python
def collect_classes(html: str) -> set[str]:
    tokens: set[str] = set()
    for value in re.findall(r'class="([^"]*)"', html):
        tokens.update(value.split())
    return tokens
```

The old theme emits many attributes with single quotes (`class='…'` in Liquid snippets), and the raw captures preserved them. The converter's pass one (`convert.py:176-183`) uses this token set to decide which classes exist; `Stylesheet.rules_for_classes()` then returns nothing for a class it never saw.

Measured on the shipped templates:

| Markup | Classes actually present | Classes `collect_classes` finds | Missing |
|---|---:|---:|---:|
| `partials/header.html` | 109 | 47 | **62** (the whole `xo-menu-horizontal-v1` / `xo-menu-hamburger-style1` family) |
| `family-stainless-steel.html` | 547 | 538 | **9** (the whole `breadcrumb` family) |

Cross-check against the old stylesheet: of the 90 `main.css` class tokens used by the templates, **36 have a matching rule in a volume and 54 have none** (selector-presence cross-check). The largest absent groups are:

- `.xo-section-bg-image`, `.xo-section-bg-image__item` — used by 16 templates; rules in `main.css:8743-8762`; zero occurrences in all 36 volumes. This is why the breadcrumb section's background wrapper sits in flow (new section height 126px vs old 60px).
- `.breadcrumb__link`, `.breadcrumb__separator`, `.breadcrumb--current`, `.breadcrumb--normal` — used by 15 templates; rules in `main.css:2163-2219`; zero occurrences in the volumes.
- Header/footer tokens: `xo-menu-horizontal-v1__label`, `__link`, `__sub-menu`, `__item`, `xo-menu-hamburger-style1__*`, `footer-v21__*`, `xo-image__loading`, etc.

The same blind spot appears in the inline-style regex: `INLINE_STYLE = re.compile(r'\sstyle="([^"]*)"')` (`convert.py:46`, `csslib.py:28`) and the merge regexes in `_fold_inline` (`convert.py:322-340`) only understand double-quoted attributes. When an element has `class='x' style="…"`, the minted class is appended as a **second** `class` attribute (`header.html:104`: `<ul class='xo-menu-horizontal-v1__sub-menu list-unstyled' class="md-t14a7f425">`). HTML ignores the second class attribute, so the minted rule never applies. Three such elements exist in the header partial.

A second tokenizer defect compounds C5: `_selector_classes()` uses `re.findall(r"[.][A-Za-z0-9_-]+", arm)` (`csslib.py:260-266`), so an escaped responsive utility is indexed under its prefix, not its full class:

```
.md\:text-5xl      -> {'md'}
.lg\:text-7xl      -> {'lg'}
.lg\:text-\[56px\] -> {'lg'}
```

The old Tailwind definitions for the responsive utilities are therefore never carried, even where the markup class was seen. WindPress recompiles utilities from its own default theme (C5), so the old custom scale cannot win by this route.

**Affected pages:** every page type; the header and breadcrumb fragments are on all standard pages.

### C2. Class-less rules and head-level tokens are discarded

`Stylesheet.__init__` indexes a qualified rule by the class tokens in its selector; a selector with no class token is appended to `_bare` (`csslib.py:167-174`, `204-236`). `distribute()` iterates only `_class_volumes` and calls `rules_for_classes()` (`convert.py:239-256`); `_bare` is never read. `extract_rules()` has the same class-only contract.

Reproduction on the real `main.css`: **140 class-less top-level rules** were dropped (the file carries a duplicated normalize block, so some repeat). The ones that define the visual baseline:

| Old rule | Location | What it carried |
|---|---|---|
| `html { font-size: 62.5% }` | `main.css:335-336` | 1rem = 10px; the base of every rem value |
| `body { font-family: var(--font-body-family); font-size: var(--font-body-size, …); line-height: …; color: … }` | `main.css:339-349` | Inter, 16px, 1.5-ish line-height, 75% black |
| `h1…h6 { font-family: var(--font-heading-family); font-weight: …; margin: 0; margin-bottom: 1rem; text-transform: none }` | `main.css:1294-1305` | Playfair headings, zero UA margins (the `margin: 0` at line 1302) |
| `.link, a { text-decoration: none }` | `main.css:361-363` | no underlines |
| `img { max-width: 100%; height: auto; border: 0; vertical-align: top }` | `main.css:461-466` | images cannot overflow |
| `xo-container { display: block; max-width: 1400px; margin: auto; padding-inline: 20px }` | `main.css:419-425` | every `xo-container` layout |
| `#xo-root { overflow: clip; display: flow-root; … }` | `main.css:405-411` | page root |
| `:root { … }` (settings-adapter) | rendered `<head>`, `settings-adapter.liquid:23-116` | colors, fonts, scales, spacing |

The last row is a second boundary: even if class-less rules were carried, the converter reads only `shopify-section` wrappers (`sections.py:19-65`), so a `<style>` block in `<head>` is invisible. The `SETTINGS_VARS` special case (`convert.py:33`, `convert.py:181-183`) routes `:root`-containing **section** style blocks to chrome, but the old theme's real `:root` block is in the layout head.

Live proof of the missing baseline:

| Property | Old (real Chrome) | New (real Chrome) |
|---|---|---|
| `body` font-family | `Inter, san-serif` | `Times` |
| `body` font-size | 16px | 16px |
| `html` root / rem probe | computed 12px / **1rem = 10px** | 16px / **1rem = 16px** |
| `h1` margin-top/bottom | 0 / 0 | 48.24px / 48.24px |
| `h2` margin-top/bottom | 0 / 0 | 39.84px / 39.84px |
| `xo-container` in header | block, max-width 1400px, padding 0 20px | inline, max-width none, padding 0px |
| `xo-container--wide` padding | 140px | 224px (same 14rem declaration) |

The Times result is not a fallback surprise: the child theme dequeues picostrap/Bootstrap (`themes/md-child/functions.php:33-36`), and the WindPress entry deliberately leaves Tailwind preflight commented out (`WindPressVolume.php:43-46`). No remaining stylesheet sets `body { font-family }`, so the browser default (Times) wins.

**Affected pages:** all pages, globally.

### C3. Old design tokens are absent, and their consumers are left with undefined variables

`tokens.css` defines 12 properties: `--md-bg`, `--md-bg-deep`, `--md-bg-darkest`, `--md-gold`, `--md-gold-light`, `--md-gold-dark`, `--md-gold-accent`, `--md-cream`, `--md-whatsapp`, `--md-font-display`, `--md-font-sans`, `--md-container` (`tokens.css:35-49`). The old `settings-adapter` rendered **174** custom properties, including:

- `--heading-hero: 56px`, `--heading-xl: 42px`, `--heading-lg: 32px`, `--heading-md: 24px`, `--heading-sm: 20px` (`settings-adapter.liquid:65-69`)
- `--text-base/sm/xs` (`:70-72`)
- `--font-body-family` (`:102`), `--font-heading-family` (`:108`), `--font-body-scale: 1.0` (`:111`), `--font-heading-scale: 1.4` (`:112`), body sizes (`:100-101`)
- `--color-background`, `--color-foreground`, `--color-accent` per color scheme (`:23-56`; the first scheme is also `:root`)

None of these names appears in the new compiled CSS (`--font-body-family`: 0 occurrences; `--heading-hero`: 0). `--color-background` / `--color-foreground` / `--font-body-scale` / `--font-heading-scale` exist **only scoped to the header wrapper** in the deployed overlay (compiled cache: `#HeaderV9-… { --color-foreground:0,0,0; --color-background:255,255,255; --font-body-scale:1; --font-heading-scale:1 }`); outside the header they are undefined.

Consequences measured live:

- `.xo-section { background-color: var(--bg-color, rgb(var(--color-background))) }` (`chrome.css:113-125`). Every section without a minted `--bg-color` has an invalid fallback → transparent. Old sections resolved to white via `--color-background: 255,255,255`; new sections are transparent, so the breadcrumb band shows the dark page behind it.
- Any carried `calc(var(--font-heading-scale) * …)` or `rgba(var(--color-foreground), …)` outside the header is invalid at computed-value time; the declaration falls back, not to the old value.
- The templates still carry the old class names (`color-background-1`), but the scheme rules that made those names mean something are gone.

Volume-level count: **42 variables are used but never defined** in the exported volumes. Excluding Tailwind's runtime `--tw-*` set, the design-critical ones are `--color-foreground` (6 uses), `--font-heading-scale` (3), `--color-background`, `--color-accent`, `--font-body-scale`, `--spacing-sections-mobile`, `--spacing-sections-desktop`, `--background-menu`, `--text-menu`, `--md-header-h`.

**Affected pages:** all pages; visibly the breadcrumb/section bands and any component whose color/typography depended on the scheme variables.

### C4. The rem scale changed 1.6× (10px root → 16px root)

The old `html { font-size: 62.5% }` (`main.css:335-336`) makes the old site a 10px-rem site. A live `1rem` probe confirms: **old 1rem = 10px; new 1rem = 16px**. The old root's computed `font-size` reports 12px in this Chrome profile, but the probe's 1rem and 2rem measurements are 10px and 20px — the rem base used by layout is 10px.

The converter carried every `rem` value unchanged. 856 `rem` occurrences remain in the 36 volumes. Concrete examples:

| Declaration | Old rendered | New rendered |
|---|---:|---:|
| `.xo-container--wide { padding-left/right: 14rem }` (`main.css:8811-8813`; `chrome.css:90-94`) | 140px | 224px |
| Tailwind `gap-10` (2.5rem), measured on the home hero | 25px | 40px |
| Tailwind `pt-24` element, measured on the home page | 70px | 112px |
| Tailwind `mb-5` (1.25rem), measured | 12.5px | 20px |

Every ratio above is exactly 1.6. This affects spacing, widths, radii and any carried `rem`-based font size; it is not limited to the heading symptoms. New-authored components (`inquiry.css`, `privacy.css`, `blog.css`, `blog-index.css`, `inquiry-thank-you.css`) also use rem, but were written against a 16px root; a global root change would shrink them, so the fix needs a deliberate strategy (see §5).

**Affected pages:** all pages; strongest in the migrated sections that use rem values (home 67 rem occurrences, hero 60, faq 178, cta-band 159).

### C5. The Tailwind scale was upgraded from a custom px scale to the v4 default rem scale

The old template markup was copied verbatim, including the utility classes. The utility definitions did not come along:

| Class | Old definition (old live CSS) | New definition (WindPress compiled cache) | Rendered old → new |
|---|---|---|---:|
| `md:text-5xl` | `.md\:text-5xl { font-size: 42px }` | `--text-5xl: 3rem` → 48px | 42 → 48 |
| `lg:text-7xl` | `.lg\:text-7xl { font-size: 56px }` | `--text-7xl: 4.5rem` → 72px | 56 → 72 |
| `md:text-6xl` | `.md\:text-6xl { font-size: 48px }` | `--text-6xl: 3.75rem` → 60px | 48 → 60 |

The old scale is `scripts/tailwind/tailwind.config.js:21-36` (`xs:13, sm:14, base:16, lg:18, xl:20, 2xl:24, 3xl:30, 4xl:36, 5xl:42, 6xl:48, 7xl:56, 8xl:64, 9xl:72`). WindPress's entry imports `tailwindcss/theme.css` and `tailwindcss/utilities.css` with no site `@theme` override (`WindPressVolume.php:43-46`), so the default scale applies.

Confirmed live on the home page:

- `h1` (`.lg:text-7xl`): old 56px Playfair, new 72px Playfair.
- `h2` (`.md:text-5xl`): old 42px, new 48px.
- The family page's h1 uses `lg:text-[56px]` (an arbitrary px value), so it still computes 56px on both sites — the exception that proves the rule: only the scale-defined utilities moved.

**Affected pages:** every template using Tailwind `text-5xl`/`text-6xl`/`text-7xl`/`text-8xl`/`text-9xl`, including all home sections and family heroes.

### C6. Layout-level snippets, scripts and app assets never entered the pipeline

- **Quote modal.** `snippets/quote-modal.liquid` is a fixed dark card (`id="quote-modal"`, `x-data="quoteModal()"`) rendered once in `layout/theme.liquid:275`, outside any Shopify section. The old page has it on home, family and PDP (one `#quote-modal` in the live DOM). The new home/family/PDP have **zero** `#quote-modal`; only `custom-screens.html:52-53` has a local copy. The CTAs still point at `#open-quote` (`home.html:51`, `195`, `1053`, `1906`, `2034`), but no element has that id. `md-chrome.js` only opens a quote modal when `[x-data^="quoteModal"]` exists (`imp/58 md-chrome.js:298-307`), so on the standard pages the CTA is a dead anchor.
- **WhatsApp float.** The old fixed button is the Moosedesk app, not theme markup: the old page loads `moosedesk-whatsapp-107/assets/wa.js`, `order-button-core.js` and injects `wa-preview-corner-button-container` ("Message us 🖐") at the bottom-right. The new page has no fixed WhatsApp element; its `wa.me` links are static footer/CTA links (live probe: `fixed = []`). The landing-support-desk snippet (`snippets/landing-support-desk.liquid:269`, rendered by `layout/theme.layout.liquid:322`) is the same class of loss on landing layouts.
- **Runtime.** T3 removes every `<script>` (`transform.py:33-35`, `57-61`). The old site depended on `xo-webcomponents.min.js` for `xo-modal`, `xo-sticky`, `xo-menu`, `xo-parallax`; the production fix (`5b80a70`) restores a first-party `md-chrome.js` and Alpine for some of them, but not the quote modal markup or the WhatsApp app. The search trigger exists but its presentation depends on the runtime/overlay.

**Affected pages:** all standard pages for the quote modal and WhatsApp float; the landing layouts for the support desk.

### C7. Inline-style folding changed the cascade position, and the deployed overlay is not on `main`

The old nav label was an `<h3 class='xo-menu-horizontal-v1__label' style='…; font-size: 13px'>` (`snippets/menu-horizontal-v1.liquid:127-133`; `header-v1.liquid:73` passes `font_size: 13`). An inline declaration beats any author selector. In the new template the `style` attribute is gone and the declaration survives only as `h3.xo-menu-horizontal-v1__label { font-size: 13px }` (`chrome.css:14`). The deployed overlay adds a higher-specificity ID rule:

```css
#HeaderV9-sections--21844031144189__header_v1_main .xo-menu-horizontal-v1__label{
  font-size: 1.4rem; font-weight: 500; color: #000; margin: 0; padding: 2.2rem 0;
}
```

(`imp/58-arbitrary-values:plugins/md-site/content/windpress/chrome.css:308-315`, commit `5b80a70`)

At the new 16px root, 1.4rem = **22.4px**. CDP matched both rules and the ID rule wins. The overlay also sets the header wrapper white but never sets the icon color, so the search SVG's `currentColor` is the inherited body white on a white header — the icon is present (40×40 trigger, 20×20 SVG) and invisible. The overlay does not restore `font-family`, `text-transform: uppercase`, the header gutter, or the `--color-foreground` scheme outside the header.

The overlay is on `imp/58-arbitrary-values` (`5b80a70` and later commits through `569ca0a`), **not on `main`**. The live site runs it; the repo's `main` does not. The audit therefore distinguishes "pipeline output" (main) from "deployed overlay" (imp/58) throughout. A converter re-run from main would erase the overlay's header rules and still not restore the old look.

**Affected pages:** the header on all pages; the quote/WhatsApp assets as in C6.

---

## 3. Per-symptom map

| # | Symptom (verified live, 1440×900) | Old rule / value | New rule or gap | Affected pages | Cause |
|---|---|---|---|---|---|
| 1 | Body font Times, old Inter | `settings-adapter.liquid:102` `--font-body-family: Inter…`; `main.css:339-349` `body { font-family: var(--font-body-family) }`; live computed `Inter, san-serif` | No `body`/`:root` rule survives; `--font-body-family` 0 occurrences in the new cache; `tokens.css` has `--md-font-sans` but no consumer; picostrap CSS dequeued (`functions.php:33-36`) and preflight off (`WindPressVolume.php:45`) → UA Times | All | C2, C3 |
| 2 | Nav labels 22.4px Times, no uppercase; old 13px Playfair uppercase; CTA flush/clipped | Inline `font-size: 13px` (`menu-horizontal-v1.liquid:127-133`); `.xo-menu-horizontal-v1__label { text-transform: uppercase; font-size: calc(…1.3rem) }` (`main.css:5685-5691`); heading family (`main.css:1296`); container `main.css:419-425` | Base label rule not carried (single-quoted classes, 62/109 header classes missed); heading family rule dropped; `h3…{13px}` loses to overlay ID `1.4rem` → 22.4px (`imp/58 chrome.css:308-315`); `xo-container` inline (0 max-width/padding) → grid 1440 wide, CTA right edge at 1440; nav `scrollWidth 1079 > clientWidth 1073` | All standard pages | C1, C2, C3, C6, C7 |
| 3 | Search icon missing | Old icon 40×40, color `rgba(0,0,0,.75)`, SVG fill black | Markup present (`header.html:529-546`), but wrapper background `#fff` and inherited color `#fff`; SVG path `fill: #fff` → white on white. No rule sets the icon color | All standard pages | C2/C3 (scheme), C7 (overlay) |
| 4 | Home h1 72px vs 56px; h2 48px vs 42px | Markup `lg:text-7xl` (`home-hero-ioh.liquid:35`), `md:text-5xl` (`home-specification-map.liquid:146`); old scale `7xl:56px`, `5xl:42px` (`tailwind.config.js:22-36`); old live CSS `.lg\:text-7xl{56px}`, `.md\:text-5xl{42px}` | WindPress default `--text-7xl:4.5rem` (72px), `--text-5xl:3rem` (48px) at 16px root; no `@theme` override (`WindPressVolume.php:43-46`); escaped selector tokenizer cannot carry old definitions (`csslib.py:260-266`) | All templates using 5xl–9xl | C5 (and C4 for rem defaults) |
| 5 | Headings ~48px top/bottom margins; old 0 | `main.css:1294-1305` `h1…h6 { margin: 0; margin-bottom: 1rem }` + reset; live old margins 0 | Class-less reset dropped (`_bare`); preflight off; UA `h1` margin 0.67em = 48.24px at 72px, `h2` 0.83em = 39.84px at 48px | All | C2 |
| 6 | Breadcrumb white bar / black Inter 13 → transparent dark / white Times / blue underlined | Scheme `:root` `--color-background:255,255,255`, `--color-foreground:0,0,0` (`settings-adapter.liquid:23-56`); `.xo-section` background `rgb(var(--color-background))`; `.breadcrumb__list` flex/gap/13px (`main.css:2163-2174`); `.breadcrumb__item a` / `__link` color/13px (`main.css:2191-2216`); `a { text-decoration:none }` (`main.css:361-363`); live 13px black Inter | All breadcrumb classes are single-quoted in templates (`family-stainless-steel.html:42-43`; 9/9 missed) → no rules in volumes (`breadcrumb.css:11-14` only custom props); scheme variables undefined outside header → `.xo-section` fallback invalid/transparent (`chrome.css:119`); UA link blue/underline; Times; `.xo-section-bg-image` rules missing (`main.css:8743-8762`) → bg wrapper in flow, section 126px vs old 60px | 9 family pages, PDP, shop, series archive, about, blog faces | C1, C2, C3 |
| 7 | Hero quote card / floating quote modal missing | `snippets/quote-modal.liquid:32-46` fixed `#quote-modal`, rendered once in `layout/theme.liquid:275`; live old has one `#quote-modal` | No `#quote-modal` on home/family/PDP; only custom-screens has a local one (`custom-screens.html:52-53`); `#open-quote` links target nothing; `md-chrome.js` requires a `quoteModal` component to open | All standard pages | C6 |
| 8 | WhatsApp float missing | Moosedesk app scripts (`wa.js`, `order-button-core.js`) inject `wa-preview-corner-button-container` fixed bottom-right on the old site; support-desk variant in `landing-support-desk.liquid:269` / `theme.layout.liquid:322` | No fixed element on new pages; only static `wa.me` links in footer/CTA; app scripts not carried, T3 strips scripts | All | C6 |
| 9 | PDP hero images in wrong slots | Old jaipur PDP: hero bg `jaipur.jpg` 1024×1024; plate `pattern-media-k10nnfdj` 400×800; square `pattern-media-xqi0an66` 1200×680; gallery `[k10nnfdj, xqi0an66]` | New: hero bg = `…-01-400w` (the old **plate**, 400×800); plate = `…-02-1500w` (≈ old square); square = `…-03-1500w` (a third image); old hero bg absent. `Shortcodes.php:222-257` binds hero-bg=featured, plate=gallery[0], square=gallery[1]; the imported media set does not preserve the old roles. Template slots themselves are structurally correct (`pdp.html:78-82,114-116,145-147`) | 146 PDPs | Data binding, not CSS (coordinate #63) |

### 3.1 Note on symptom 2 and the deployed overlay

The exact 22.4px value is **not** a converter arithmetic result. It is `1.4rem` written by hand in the production fix commit `5b80a70`. The converter's contribution is that the old inline `13px` no longer outranks stylesheet rules, and that the old uppercase/Playfair rules were never carried. Any repair that only edits the overlay to `13px` fixes the number but leaves the font and case wrong; any repair that only fixes the converter and re-runs from `main` deletes the overlay and leaves the nav at 13px Times. Both layers must be reconciled.

---

## 4. What the 36 volumes and 25 templates contain today

- Volumes: 36 files, 10,821 lines. 12 token definitions. 42 used-but-undefined variables (design-critical subset above). 856 `rem` occurrences. The largest volumes are `custom-screens.css` (1,757 lines), `gallery.css` (716), `faq.css` (640), `shop.css` (636), `series-archive.css` (636), `cta-band.css` (619), `spec-table.css` (594), `chrome.css` (568). Several are near-empty because their rules were never selected: `stats-band.css` (5 lines), `faqs.css` (7), `project-gallery.css` (7), `about.css` (12), `blog-category.css` (12).
- Templates: 25 page templates + 5 partials, 32,217 lines. The markup preserves the old Tailwind classes verbatim, which is why the scale mismatch is visible: the same `lg:text-7xl` renders 56px old and 72px new. Header has 3 duplicate `class` attributes from the inline fold; breadcrumb markup has a malformed attribute left by the same quote mismatch (`family-stainless-steel.html:42`: `<nav class='breadcrumb' --display-mobile:block'>`).
- Tests: the converter's tests cover rule-unit dedupe, brace balance, section scanning, slots and transforms, but there is no test asserting (a) single-quoted class collection, (b) class-less/global rule carry, or (c) a rendered baseline value (body font, root rem, heading margin). The defects passed CI because the pipeline's contract was "volumes are brace-balanced", not "the old cascade is present".

---

## 5. Repair options

### Option A — Fix the converter and re-run

**What it takes**

1. Make the tokenizers quote-aware: `collect_classes` and the inline-style regexes accept `'` and `"` (`csslib.py:28,56-61`; `convert.py:46,322-340`).
2. Add a **base/global volume** built from (a) the class-less rules of `main.css` that the templates' tag vocabulary actually uses, and (b) the rendered `<head>` `:root`/scheme block from the raw captures. Emit it as a layer below the component volumes. This is a new converter pass, not a per-rule hand-port.
3. Decide the rem strategy. The old site is a 10px-rem site; the new site is 16px. Two viable forms:
   - **Root-10 form:** carry `html { font-size: 62.5% }`, restore the old Tailwind `fontSize` as px, and convert the new-authored components (inquiry/blog/privacy, ~89+ rem values) to px. Closest to old parity for all migrated markup; new components need an audit.
   - **Root-16 form:** keep `html` at 16px, normalize every old-derived rem to px (`× 0.625`) in the converter (including arbitrary `rem` values in templates), and override the Tailwind theme's `fontSize` to the old px scale. New-authored components stay untouched; the converter gains a value transform and must know which rules are old-derived.
   Either way, the Tailwind `fontSize` scale must be restored via the WindPress entry's `@theme` (or an imported theme file) to `13/14/16/18/20/24/30/36/42/48/56/64/72`.
4. Make folded inline styles cascade-safe. A class cannot beat an ID; either keep the inline declaration (violates the zero-inline-style rule), or emit the minted declarations with a specificity/`!important` policy that reproduces inline precedence. Add a regression test for the nav label.
5. Restore the runtime/asset layer outside the converter: quote modal partial, WhatsApp float (first-party or app), PDP image binding (ticket #63).
6. Re-capture the raw inputs. The old rendered HTML under `.scratch/32/raw` is gone (verified: the worktree and main checkout have no `32/` directory; only `.scratch/58` remains). The old site is live; the 18 representative inputs in `pageplan.PAGES` can be fetched. **Use HTTP GET, not a DOM `outerHTML` capture**, or the single quotes normalize to double quotes and the tokenizer bug is masked in the new raw set. The media state needed by `--media-state` still exists (`data/media-final/_state.json`), and the old pixel baseline (364 PNGs) remains.
7. Merge or port `imp/58` first, or move the production overlay into a volume the pipeline does not write. Otherwise the re-run deletes the overlay.

**Blast radius:** all 25 templates and all 36 volumes are regenerated; every page is affected. Cost: high (converter work + capture + diff + pixel parity across 25 templates and 146 PDPs). Risk: high (cascade regressions, overwrite of production fixes, rem-strategy decision). Strength: fixes the root cause and makes future re-runs clean.

### Option B — Global override layer plus small template patches

**What it takes**

- A new `baseline.css` (or additions to `tokens.css`) with:
  - `body { font-family: var(--md-font-sans); font-size: 16px; line-height: 1.5; color: … }`
  - `h1…h6 { font-family: var(--md-font-display); margin: 0; font-weight: 600 }`
  - the old `fontSize` scale via the WindPress entry's `@theme` (px values)
  - `a { color: inherit; text-decoration: none }` where the old site had no underline
  - `img { max-width: 100%; height: auto }`
  - `xo-container { display: block; max-width: 1400px; margin: auto; padding-inline: 20px }`
  - the color-scheme variables for the light sections that need them (surgical: `.xo-section:has(.breadcrumb), .color-background-1` etc.), not a global dark/light flip
  - breadcrumb rules: `.breadcrumb__list { display:flex; gap:1.5rem; list-style:none; margin:0; padding:0; font: 13px/1.5 Inter }`, `.breadcrumb a { color: inherit; text-decoration:none }`, the section background white
  - nav: the old values with enough specificity/order to beat the overlay's ID rule (`font-family: var(--md-font-display); font-size:13px; text-transform:uppercase`), and an explicit icon color (`color:#000`) so the search icon is visible
  - header gutter: restore `xo-container`/the header grid padding so the CTA is not flush to the viewport edge
- Small template patches: the quote modal partial (and its Alpine component), a first-party WhatsApp float or the app re-install, and the PDP image binding (ticket #63).
- No re-capture and no converter re-run. The old live site and the 364-PNG pixel baseline are the oracle.

**Blast radius:** one or two new volumes + entry `@theme` + 3-5 template/partial edits; all pages affected by the global layer but the changes are additive and reviewable. Cost: low-medium (hours). Risk: medium (cascade/layer order; global color rules can fight the dark sections; the 856 rem values are not globally fixed unless the scale strategy is applied here too). Strength: fast, reversible, does not touch generated templates; the production fix already proved the approach. Weakness: leaves the pipeline broken; the next converter run reintroduces the defects; it duplicates old rules by hand.

### Option C — Hybrid (recommended)

1. **B-lite now:** ship the global override layer for the visible baseline (body/headings/links/images/container/colors/nav/breadcrumb/icon) and the `@theme` px scale. This is the acceptance oracle and stops the visible bleeding without touching the pipeline output.
2. **A-scoped next:** fix the tokenizer (quotes + escaped selectors), add the base/global pass (class-less rules + head `:root`), normalize the rem strategy, and re-run on a branch. Diff the generated templates and volumes; accept only the expected diffs. Keep the B layer as `overrides.css` **outside** the pipeline's output directory so a re-run cannot delete it, or port the overlay into the converter's base pass once it matches.
3. **Asset/data fixes in parallel:** quote modal partial, WhatsApp float, PDP image binding (ticket #63).

**Blast radius:** the converter modules, the WindPress entry/theme, all volumes (regenerated with a diff gate), and a small number of templates/partials. Cost: medium-high; risk: medium because the stages are separated and the B layer remains the fallback. Strength: fixes the root cause without betting the live site on a single big-bang re-run.

### Option comparison

| | A: converter + re-run | B: override layer | C: hybrid |
|---|---|---|---|
| Fixes root cause | Yes | No (masks it) | Yes, in stages |
| Raw re-capture needed | Yes (18 pages) | No | Yes (for the A stage) |
| Touches generated templates | All 25 | 0-5 patches | Only the diff-accepted ones |
| Touches volumes | All 36 | +1 base volume | All (diff-gated) + preserved override |
| Rem/scale strategy | Must decide | Must decide for the layer | Decided once, reused |
| Production overlay risk | High (overwrites imp/58) | Low | Medium (merge/port first) |
| Time | Highest | Lowest | Medium-high |
| Verification | Full pixel parity | Targeted computed values + pixel checks | Staged parity |

---

## 6. Recommendation

**Option C.** Sequence:

1. **B-lite** (hours): one `baseline`/`overrides` volume with the body/heading/link/image/container/color/nav/breadcrumb/icon rules and the Tailwind `@theme` px scale. Verify against the old live computed values and the existing pixel baseline.
2. **A-scoped** (next session): quote-aware tokenizers, class-less + head-`:root` base pass, rem normalization, inline cascade policy. Re-run on a branch, diff templates/volumes, keep the override layer outside the generated set.
3. **Assets/data** in their own tickets: quote modal partial, WhatsApp float, PDP image binding.

The rem strategy is the one decision that should be made explicitly before coding: root-10 (old parity, new components need conversion) vs root-16 + rem→px normalization (new components untouched, converter gains a value transform). The measured 1.6× ratio and the 856 rem occurrences are the evidence base for that decision.

This is a recommendation, not a decision; the strategy ticket owns the choice.

---

## 7. Re-capture and verification feasibility

- **Old raw HTML:** gone from `.scratch/32/raw` (verified; no `32` directory in either checkout). The old site is live and served all audited pages with `shopify-section` wrappers intact. Re-capture the 18 inputs named by `pageplan.PAGES`: `home/index.html`, one `pdp/*.html`, `collections/pattern-organic.html`, `hub/index.html`, `shop/index.html`, and the 13 `pages/*.html` faces. Use HTTP GET to preserve the authored single quotes.
- **Media map:** `data/media-final/_state.json` exists, so `--media-state` still builds the source→final-name map.
- **Pixel baseline:** `data/pixel-baseline/` has 364 PNGs and a manifest, so visual parity after any repair is measurable even without the old raw HTML.
- **Live computed oracle:** the real-Chrome probes in this audit can be rerun against the old site at any time (old site remains live per the owner's ruling).
- **Deployment state:** the production site runs the `imp/58-arbitrary-values` line (local tip `569ca0a`; `origin/imp/58-arbitrary-values` is behind at `1ad9396`), not `main` (`8859209`). Reconcile before any re-run.

---

## 8. Out of scope / adjacent ticket

- **PDP image slots (symptom 9, ticket #63):** the template's slot positions are correct; the defect is the importer/shortcode binding (featured vs gallery order and the missing old hero-bg role). This belongs to ticket #63. The pipeline evidence to carry there: old roles are `hero bg = jaipur.jpg`, `plate = gallery[0]`, `square = gallery[1]`; the new product's media set starts at the old plate and the old hero bg is absent.
- **Dark-section color design:** the new site deliberately uses dark sections. A global `--color-background: white` override would regress them; the color fix should be scoped to the light bands (breadcrumb, etc.) or restore the old scheme classes per section.
- **New-authored components** (inquiry, privacy, blog) use rem against a 16px root; the rem-strategy decision must account for them.

---

## 9. Evidence index

### 9.1 Files read

- `tools/template-convert/src/md_template_convert/{csslib,convert,transform,sections,pageplan,slots,upgrade,cli,volumecheck,blogfork}.py`
- `plugins/md-site/content/windpress/*.css` (36 volumes, 10,821 lines), `tokens.css`
- `plugins/md-site/content/livecanvas/*.html` (25 templates) and `partials/*.html` (5)
- `plugins/md-site/includes/Presentation/{WindPressVolume.php,Shortcodes.php}`
- `themes/md-child/{functions.php,style.css}`
- Old theme: `assets/main.css`, `snippets/{settings-adapter,menu-horizontal-v1,quote-modal,landing-support-desk}.liquid`, `sections/{header-v1,home-hero-ioh}.liquid`, `config/settings_data.json`, `layout/{theme,theme.layout}.liquid`
- `~/Documents/shopify-builder/scripts/tailwind/tailwind.config.js`

### 9.2 Reproduced converter probes (tinycss2)

- `collect_classes(header) = 47`, actual `= 109`, missing `= 62`; `collect_classes(family) = 538`, actual `= 547`, missing `= 9`.
- `main.css` class tokens used in templates: 90; with a matching rule in a volume: 36; without: 54 (selector-presence cross-check).
- `main.css` class-less top-level rules dropped: 140.
- `Stylesheet("main.css").rules_for_classes({"xo-menu-horizontal-v1__label"})` returns 12 rules, including the base uppercase/size rule (`main.css:5685`); `breadcrumb__item` 7, `breadcrumb__link` 1, `breadcrumb__list` 1.
- `_selector_classes(".md\\:text-5xl") = {"md"}`, `".lg\\:text-7xl" = {"lg"}`, `".lg\\:text-\\[56px\\]" = {"lg"}`.
- Volumes: 42 used-but-undefined variables; 12 token definitions in `tokens.css`; 856 `rem` occurrences; 3,030 `px` occurrences.
- Old `settings-adapter` defines 174 custom properties.

### 9.3 Live measurements (real Chrome 1440×900)

| Probe | Old | New |
|---|---|---|
| `body` font-family / size / color | Inter, san-serif / 16px / rgba(0,0,0,.75) | Times / 16px / rgb(255,255,255) |
| `html` computed font-size / 1rem probe | 12px / **10px** | 16px / **16px** |
| Home `h1` | 56px Playfair, margin 0 | 72px Playfair, margin 48.24px |
| Home `h2` | 42px Playfair, margin 0 | 48px Playfair, margin 39.84px |
| Nav label | 13px Playfair, uppercase, weight 500, black | 22.4px Times, none, weight 500, black |
| Nav label matched rules | base `.xo-menu…__label` + h1–h6 family + inline 13px | `h3…{13px}` + ID `1.4rem` (winner) |
| Search trigger | 40×40, color rgba(0,0,0,.75), SVG visible | 40×40, color #fff, SVG fill #fff on #fff wrapper |
| Header `xo-container` | block, max-width 1400px, padding 0 20px | inline, max-width none, padding 0 |
| `xo-container--wide` padding | 140px | 224px |
| Tailwind `gap-10` / `mb-5` | 25px / 12.5px | 40px / 20px |
| Breadcrumb link | 13px Inter, black, no underline; white section, height 60px | 16px Times, #9e9eff, underline; transparent section, height 126px |
| `#quote-modal` | present (1) | absent (0 on home/family/PDP) |
| Fixed WhatsApp float | Moosedesk button bottom-right | none |

### 9.4 Live CSS checks

- Old live CSS: `.md\:text-5xl{font-size:42px}`, `.lg\:text-7xl{font-size:56px}`, `.md\:text-6xl{font-size:48px}`.
- New compiled cache: `--text-5xl:3rem`, `--text-6xl:3.75rem`, `--text-7xl:4.5rem`; `.lg\:text-7xl{font-size:var(--text-7xl)}`; `--font-body-family` 0 occurrences; `--heading-hero` 0; `--color-background`/`--color-foreground`/`--font-body-scale`/`--font-heading-scale` only inside `#HeaderV9-…`.
- New compiled cache contains `h3.xo-menu-horizontal-v1__label{font-size:13px}` and the overlay's ID rule `font-size:1.4rem`; CDP confirms the ID rule wins.
