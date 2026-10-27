(() => {
  const norm = (s) => (s || '').replace(/\s+/g, ' ').trim();
  const out = { url: location.href, viewport: { w: innerWidth, h: innerHeight }, doc: { w: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight }, items: [] };
  const add = (kind, sev, el, note) => {
    if (out.items.length > 400) return;
    const r = el && el.getBoundingClientRect ? el.getBoundingClientRect() : { x: 0, y: 0, width: 0, height: 0 };
    out.items.push({
      kind, sev,
      sel: el ? (el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.') : '')) : '',
      y: Math.round(r.y + scrollY),
      w: Math.round(r.width),
      h: Math.round(r.height),
      text: el ? norm(el.textContent).slice(0, 90) : '',
      note: note || ''
    });
  };
  const shown = (el) => {
    const cs = getComputedStyle(el);
    return cs.display !== 'none' && cs.visibility !== 'hidden' && parseFloat(cs.opacity) > 0.01;
  };

  // 1. Horizontal overflow — the single most common "看不到全部" cause.
  if (out.doc.w > out.viewport.w + 1) {
    const wide = [...document.querySelectorAll('body *')].filter((el) => {
      if (!shown(el)) return false;
      const r = el.getBoundingClientRect();
      return r.width > 0 && (r.right > out.viewport.w + 2 || r.left < -2);
    }).slice(0, 12);
    wide.forEach((el) => add('h-scroll', 'high', el, 'doc ' + out.doc.w + ' > viewport ' + out.viewport.w));
  }

  // 2. Blocks that hold space but show nothing — the "空卡槽 / 大面积空白".
  //
  //    The rule has to survive three kinds of legitimate block, each of which
  //    an earlier version of this check reported by the hundred:
  //      • a decorative overlay (gradient, scrim, blur) — no text by design;
  //      • a photograph — an <img> has no textContent, so any text test calls
  //        it empty;
  //      • a wrapper whose content lives in its children.
  //    So: a block is empty when it shows no text, holds no loaded image
  //    anywhere inside, is not marked decorative, and paints nothing of its
  //    own. Verified against the live stainless page, which reported 5 empty
  //    blocks before this change and 1 after — and that one is a bg-black/55
  //    scrim.
  const decorative = (el) => {
    const cls = typeof el.className === 'string' ? el.className : '';
    if (el.getAttribute('aria-hidden') === 'true') return true;
    if (/\bpointer-events-none\b/.test(cls)) return true;
    if (/\bbg-gradient|\bbackdrop-blur|\bscrim\b/.test(cls)) return true;
    return false;
  };
  [...document.querySelectorAll('main section, main article, main figure, main div')].forEach((el) => {
    if (!shown(el)) return;
    if (decorative(el)) return;
    const r = el.getBoundingClientRect();
    if (r.width < 120 || r.height < 100) return;
    // Any <img> child means this block is a picture slot, whatever the image's
    // fetch state is right now. Judging by pixels-at-this-instant is what made
    // the sweep call eight fully-loaded factory cards empty: the decode landed
    // a frame after the measurement. A slot with a src is not a design gap; a
    // slot whose src 404s is caught by the img-broken rule instead.
    if ([...el.querySelectorAll('img')].some((i) => (i.getAttribute('src') || '') !== '')) return;
    if (norm(el.textContent).length > 0) return;
    const cs = getComputedStyle(el);
    if (cs.backgroundImage !== 'none') return;
    add('empty-block', 'high', el, Math.round(r.width) + 'x' + Math.round(r.height) + ' with no text, no image, no paint');
  });

  // 3. Images that should show but render nothing.
  //    An image with a zero-size box is not broken — it is unrendered, which is
  //    what a hidden slide or an off-canvas carousel item looks like. Only an
  //    image that occupies space and still has no pixels is a defect.
  [...document.querySelectorAll('img')].forEach((i) => {
    const box = i.getBoundingClientRect();
    const laidOut = box.width > 8 && box.height > 8;
    if (!laidOut) return;
    // `loading="lazy"` images off screen are legitimately not fetched yet: the
    // browser has no reason to load them and no pixels exist. Only a picture
    // that the browser already asked for (eager, or scrolled into view) and
    // still got nothing is broken. Verified on the live stainless page: three
    // images the sweep called broken all reported complete=true with
    // naturalWidth set once the page was given time to settle.
    const src = i.currentSrc || i.getAttribute('src') || '';
    // A lazy image the browser has not fetched yet is not broken. But once it
    // reports `complete: true` with `naturalWidth: 0`, the fetch finished and
    // produced nothing — that is a broken picture, lazy or not. Verified on the
    // live home page: Alhambra_Star sits in the viewport, complete=true,
    // naturalWidth=0, currentSrc empty, because its 768w candidate is a 404 and
    // the 480w candidate is smaller than `sizes` asks for.
    const lazyWaiting = i.getAttribute('loading') === 'lazy' && !i.complete;
    // An `<img>` with no source at all is a slot a `<picture>` may or may not
    // fill; it is not a broken reference. Only a src that exists and returned
    // no pixels is a defect.
    if ('' === src) return;
    if (lazyWaiting) return;
    if (!i.complete || i.naturalWidth === 0) { add('img-broken', 'high', i, src + ' box ' + Math.round(box.width) + 'x' + Math.round(box.height)); return; }
    if (i.naturalWidth / i.naturalHeight > box.width / Math.max(box.height, 1) * 2.2) add('img-ratio', 'low', i, 'natural ' + i.naturalWidth + 'x' + i.naturalHeight + ' in ' + Math.round(box.width) + 'x' + Math.round(box.height));
  });

  // 4. Content forced against the viewport edge — "字体贴边".
  //
  //    Only the LEFT edge is a defect signal. A block whose right edge sits past
  //    the viewport is a carousel slide waiting to be scrolled to; reported as
  //    edge-hugging it produced 29 hits on the home page, all of them slides at
  //    left=809 in a 1224px viewport. Text genuinely pinned to the left edge has
  //    no padding in three ancestor levels.
  const padOf = (el) => {
    const cs = getComputedStyle(el);
    return { l: parseFloat(cs.paddingLeft) || 0, r: parseFloat(cs.paddingRight) || 0 };
  };
  [...document.querySelectorAll('main h1, main h2, main h3, main p, main li, main label')].forEach((el) => {
    if (!shown(el)) return;
    const r = el.getBoundingClientRect();
    if (r.width < 30) return;
    // Off-screen to the right, or below the fold: not a layout defect.
    if (r.left >= out.viewport.w) return;
    if (r.left >= 6) return;
    let p = el.parentElement, pl = 0;
    for (let k = 0; k < 3 && p; k++) { pl += padOf(p).l; p = p.parentElement; }
    if (pl < 8) add('edge-hug', 'high', el, 'left ' + Math.round(r.left) + ' with ' + Math.round(pl) + 'px of ancestor padding');
  });

  // 5. Panels that carry real content but are forced hidden.
  //
  //    A tab strip hides every panel but the selected one; that is the widget
  //    working, not a defect. What this looks for is the other thing: a block
  //    that is hidden while the control that owns it claims to be open — the
  //    accordion that says "expanded" over an empty slot. So the test reads the
  //    control: if the sibling trigger says aria-expanded="true" (or is marked
  //    active) and the panel it names is still hidden, that is the defect.
  //    84 of the 84 the earlier rule reported were ordinary tab panels.
  [...document.querySelectorAll('[hidden]')].forEach((el) => {
    const t = norm(el.textContent);
    if (t.length < 20) return;
    const owner = el.closest('[data-sps-row], [role="tabpanel"], section, li, article');
    if (!owner) return;
    const trigger = owner.querySelector('[aria-expanded="true"], .is-active[aria-expanded="true"], button[aria-expanded="true"]');
    const saysOpen = !!trigger && (trigger.getAttribute('aria-controls') || '') !== ''
      && (el.id === trigger.getAttribute('aria-controls') || owner.contains(el));
    if (!saysOpen) return;
    add('hidden-content', 'high', el, 'its own trigger reports expanded, the panel is still hidden');
  });

  // 6. Unstyled native controls — browser default chrome leaking through.
  [...document.querySelectorAll('main input:not([type=hidden]), main select, main textarea')].forEach((el) => {
    if (!shown(el)) return;
    const cs = getComputedStyle(el);
    const dfltBorder = /^(2px inset|1px solid rgb\(118, 118, 118\)|1px solid rgba\(0, 0, 0, 0\.\d+\))/.test(cs.borderTopWidth + ' ' + cs.borderTopStyle + ' ' + cs.borderTopColor) || cs.borderTopColor === 'rgb(118, 118, 118)';
    if (dfltBorder && /^(none|normal|-webkit-small-control)$/.test(cs.appearance)) add('native-control', 'med', el, 'border ' + cs.borderTopWidth + ' ' + cs.borderTopStyle + ' ' + cs.borderTopColor + ' appearance ' + cs.appearance);
  });

  // 7. Type too small to read, or a single line stretched past a sane measure.
  [...document.querySelectorAll('main p, main li, main span')].forEach((el) => {
    if (!shown(el)) return;
    const t = norm(el.textContent);
    if (t.length < 40) return;
    const fs = parseFloat(getComputedStyle(el).fontSize);
    if (fs < 12) { add('tiny-type', 'med', el, fs + 'px'); return; }
    const r = el.getBoundingClientRect();
    // A measure is long when the line runs wider than the readable range, not
    // when it is a large share of the viewport: 40em ≈ 640–720px. Report only
    // what is genuinely hard to track back to the next line.
    if (t.length > 90 && r.width > 760) add('long-measure', 'med', el, Math.round(r.width) + 'px for ' + t.length + ' chars');
  });

  // 8. Big dead vertical gaps between content — "莫名的大块空白间隙".
  [...document.querySelectorAll('main section, main > div')].forEach((el) => {
    if (!shown(el)) return;
    const kids = [...el.children].filter(shown);
    if (kids.length < 2) return;
    for (let i = 1; i < kids.length; i++) {
      const a = kids[i - 1].getBoundingClientRect();
      const b = kids[i].getBoundingClientRect();
      const gap = Math.round(b.top - a.bottom);
      if (gap > 140) add('dead-gap', 'med', kids[i], gap + 'px gap above');
    }
  });

  // 9. Sections that render zero words and zero images.
  [...document.querySelectorAll('main > *')].forEach((el) => {
    if (!shown(el)) return;
    const r = el.getBoundingClientRect();
    if (r.height < 40) return;
    const words = norm(el.textContent).split(' ').filter(Boolean).length;
    const loaded = [...el.querySelectorAll('img')].filter((i) => i.complete && i.naturalWidth > 0).length;
    if (words === 0 && loaded === 0) add('empty-section', 'high', el, Math.round(r.height) + 'px tall, no words, no images');
  });

  const sevRank = { high: 0, med: 1, low: 2 };
  out.items.sort((a, b) => sevRank[a.sev] - sevRank[b.sev] || a.y - b.y);
  out.summary = out.items.reduce((acc, it) => { acc[it.kind] = (acc[it.kind] || 0) + 1; return acc; }, {});
  return JSON.stringify(out);
})()
