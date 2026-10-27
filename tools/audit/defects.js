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
  [...document.querySelectorAll('main > *, main section, main div, main article, main figure')].forEach((el) => {
    if (!shown(el)) return;
    const r = el.getBoundingClientRect();
    if (r.height < 72 || r.width < 40) return;
    if (el.children.length > 3) return;
    const hasImg = el.querySelector('img') && [...el.querySelectorAll('img')].some((i) => i.complete && i.naturalWidth > 0);
    const txt = norm(el.textContent);
    if (hasImg || txt.length > 0) return;
    add('empty-block', 'high', el, 'height ' + Math.round(r.height) + ' with no text and no loaded image');
  });

  // 3. Images that never loaded (lazy tiles that never enter the fold).
  [...document.querySelectorAll('img')].forEach((i) => {
    if (!shown(i)) return;
    if (!i.complete || i.naturalWidth === 0) { add('img-broken', 'high', i, i.getAttribute('src') || ''); return; }
    const box = i.getBoundingClientRect();
    if (box.width > 8 && i.naturalWidth / i.naturalHeight > box.width / Math.max(box.height, 1) * 2.2) add('img-ratio', 'low', i, 'natural ' + i.naturalWidth + 'x' + i.naturalHeight + ' in ' + Math.round(box.width) + 'x' + Math.round(box.height));
  });

  // 4. Content forced against the viewport edge — "字体贴边".
  const padOf = (el) => {
    const cs = getComputedStyle(el);
    return { l: parseFloat(cs.paddingLeft) || 0, r: parseFloat(cs.paddingRight) || 0 };
  };
  [...document.querySelectorAll('main h1, main h2, main h3, main p, main li, main label')].forEach((el) => {
    if (!shown(el)) return;
    const r = el.getBoundingClientRect();
    if (r.width < 30) return;
    if (r.left < 6 || r.right > out.viewport.w - 6) {
      let p = el.parentElement, pl = 0, pr = 0;
      for (let k = 0; k < 3 && p; k++) { const q = padOf(p); pl += q.l; pr += q.r; p = p.parentElement; }
      if (pl < 8 && pr < 8) add('edge-hug', 'high', el, 'left ' + Math.round(r.left) + ' right ' + Math.round(r.right) + ' viewport ' + out.viewport.w);
    }
  });

  // 5. Panels that carry real content but are forced hidden.
  [...document.querySelectorAll('[hidden]')].forEach((el) => {
    const t = norm(el.textContent);
    if (t.length < 20) return;
    if (el.querySelector('img') || el.querySelectorAll('a,button').length) {
      const wrap = el.parentElement;
      if (wrap && wrap.querySelector('.is-active, [data-active], [aria-expanded="true"]')) add('hidden-content', 'high', el, 'has ' + t.length + ' chars and controls, sibling is active');
    }
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
    if (t.length > 90 && r.width > innerWidth * 0.62) add('long-measure', 'med', el, Math.round(r.width) + 'px for ' + t.length + ' chars');
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
