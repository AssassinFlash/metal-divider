// Find elements the page left at a placeholder.
//
// The owner found four quality counters reading "0" on a page whose whole job is
// to prove manufacturing capability: "0 checkpoints, ±0 mm, 0 % traced". They
// were not styled wrong. The old site animated them from an inline script; the
// conversion dropped the script and nothing replaced it, so the placeholders
// survived to production and stayed there.
//
// That is a class of defect, and neither the viewport sweep nor the contrast
// check can see it: a number stuck at zero is perfectly laid out, perfectly
// legible, and completely wrong.
//
// This judge reads only the rendered document and reports what still looks like
// a placeholder. It deliberately reports rather than decides — "0" is a
// legitimate value for a counter that has not scrolled into view yet, so the
// finding carries the evidence a reader needs to tell those apart.
(() => {
  const norm = (s) => (s || '').replace(/\s+/g, ' ').trim();
  // Visible means visible to a person, which includes the ancestors.
  //
  // The first version read only the element's own computed style, so a closed
  // dialog hidden by an ancestor's `opacity: 0` counted as visible. That reported
  // eighteen "empty" images inside the pattern lightbox on
  // /custom-metal-divider-screens/ — every one of them a correct pre-open state
  // whose src is filled on the first show() (verified: naturalWidth 900 once the
  // lightbox opens). A judge that counts what nobody can see produces findings
  // nobody can act on.
  const shown = (el) => {
    if (!el || !el.isConnected) return false;
    if (el.closest('[inert]')) return false;
    let node = el;
    let opacity = 1;
    for (let i = 0; i < 12 && node && node.nodeType === 1; i++) {
      const cs = getComputedStyle(node);
      if (cs.display === 'none' || cs.visibility === 'hidden') return false;
      opacity *= parseFloat(cs.opacity);
      if (opacity < 0.02) return false;
      node = node.parentElement;
    }
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  };
  const where = (el) => {
    let node = el;
    for (let i = 0; i < 8 && node; i++) {
      const h = node.previousElementSibling && /^H[1-6]$/.test(node.previousElementSibling.tagName)
        ? node.previousElementSibling
        : node.querySelector && node.querySelector('h1,h2,h3,h4');
      if (h && norm(h.textContent)) return norm(h.textContent).slice(0, 70);
      node = node.parentElement;
    }
    return '';
  };
  const findings = [];

  // 1. A data-* hook that names a value, whose text is still the literal 0.
  //    Matched by name so a future template using data-count/data-value is
  //    caught without editing this file.
  const HOOK = /^data-(num|count|value|amount|figure|metric)$/;
  document.querySelectorAll('[data-num],[data-count],[data-value],[data-amount],[data-figure],[data-metric]').forEach((el) => {
    const name = [...el.attributes].map((a) => a.name).find((n) => HOOK.test(n));
    if (!name) return;
    if (!shown(el)) return;
    const text = norm(el.textContent);
    if (text === '0' || text === '0.0' || text === '') {
      const card = el.closest('[data-counter],[data-target]');
      findings.push({
        kind: 'placeholder-value',
        hook: name,
        text: text || '(empty)',
        hasTarget: card ? !!card.getAttribute('data-target') : false,
        target: card ? card.getAttribute('data-target') : null,
        where: where(el),
        hint: card ? 'a card with a target that never wrote it' : 'no target on any ancestor'
      });
    }
  });

  // 2. An empty image slot: an <img> whose src is empty or a data: placeholder.
  document.querySelectorAll('img').forEach((img) => {
    const src = img.getAttribute('src') || '';
    if (!src.trim() || src === 'data:,' || /^data:image\/gif;base64,R0lGOD/.test(src)) {
      if (!shown(img)) return;
      findings.push({kind: 'empty-image', src: src.slice(0, 40), where: where(img)});
    }
  });

  // 3. A ratio or count still at its 1-of-1 default, which is what a gallery
  //    prints before its script fills in the real position.
  document.querySelectorAll('span,div,p').forEach((el) => {
    if (el.children.length) return;
    if (!shown(el)) return;
    const t = norm(el.textContent);
    if (t === '1 / 1' || t === '0 / 0') {
      findings.push({kind: 'default-ratio', text: t, where: where(el)});
    }
  });

  // 4. A visible progress bar or meter at zero with no value attribute.
  document.querySelectorAll('progress,[role="progressbar"]').forEach((el) => {
    const v = el.getAttribute('value') || el.getAttribute('aria-valuenow');
    if (v === null || v === '0') {
      findings.push({kind: 'empty-meter', value: v, where: where(el)});
    }
  });

  const summary = findings.reduce((acc, f) => {
    acc[f.kind] = (acc[f.kind] || 0) + 1;
    return acc;
  }, {});
  return JSON.stringify({
    url: location.pathname,
    width: innerWidth,
    summary,
    findings: findings.slice(0, 40)
  });
})()
