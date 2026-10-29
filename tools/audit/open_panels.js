// Static judge for the defect the owner photographed: a panel that is already
// open on load, contains copy, and renders at zero height — an empty rectangle
// in the middle of the page. `interactions.js` cannot see it, because it only
// judges what changes after a click, and this panel never needed a click.
//
// Two shapes, both visible without touching anything:
//
//   open-but-hollow  aria-expanded="true" (or a panel not hidden) whose body has
//                    words but a rendered height of 0. The copy exists in the
//                    DOM and is invisible: a CSS collapse, not missing content.
//   open-but-blank   the same, with no words at all inside. Nothing was ever
//                    put there: a content gap, and it needs different work.
//
// Both are reported with the surrounding heading so the finding can be located
// in a template without a second pass.
(() => {
  const norm = (s) => (s || '').replace(/\s+/g, ' ').trim();
  const shown = (el) => {
    if (!el || !el.isConnected) return false;
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) < 0.02) return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  };
  const words = (el) => norm(el.textContent).split(' ').filter(Boolean).length;

  const nearestHeading = (el) => {
    let node = el;
    for (let i = 0; i < 8 && node; i++) {
      const prev = node.previousElementSibling;
      if (prev && /^H[1-6]$/.test(prev.tagName)) return norm(prev.textContent).slice(0, 80);
      const own = node.querySelector('h1,h2,h3,h4');
      if (own && norm(own.textContent)) return norm(own.textContent).slice(0, 80);
      node = node.parentElement;
    }
    const section = el.closest('section,[data-section],main');
    const h = section && section.querySelector('h1,h2,h3');
    return h ? norm(h.textContent).slice(0, 80) : '';
  };

  const findings = [];
  const seen = new Set();

  // Every control that claims a panel, whatever the panel is called.
  const controls = [...document.querySelectorAll('[aria-controls]')];
  for (const c of controls) {
    const id = c.getAttribute('aria-controls');
    if (!id) continue;
    const panel = document.getElementById(id);
    if (!panel || !panel.isConnected) continue;
    if (seen.has(id)) continue;
    const expanded = c.getAttribute('aria-expanded') === 'true' && !panel.hasAttribute('hidden');
    if (!expanded) continue;
    seen.add(id);
    const h = Math.round(panel.getBoundingClientRect().height);
    const w = words(panel);
    if (h > 0) continue;
    findings.push({
      verdict: w > 0 ? 'open-but-hollow' : 'open-but-blank',
      kind: 'aria-controls',
      heading: nearestHeading(panel),
      control: norm(c.textContent).slice(0, 60),
      panel: id,
      words: w,
      height: h
    });
  }

  // Details/summary elements open on load, same defect wearing a different tag.
  for (const d of document.querySelectorAll('details[open]')) {
    const body = d.querySelector('.details-content, .accordion__content, [data-accordion-content]')
      || [...d.children].find((el) => el.tagName !== 'SUMMARY');
    if (!body) continue;
    const h = Math.round(body.getBoundingClientRect().height);
    if (h > 0) continue;
    const w = words(body);
    findings.push({
      verdict: w > 0 ? 'open-but-hollow' : 'open-but-blank',
      kind: 'details',
      heading: nearestHeading(d),
      control: norm((d.querySelector('summary') || d).textContent).slice(0, 60),
      panel: d.id || '(no id)',
      words: w,
      height: h
    });
  }

  // A wrapper that reserves a tall box and paints nothing in it. This is the
  // "large blank gap" shape: the space is allocated, the content is not there.
  //
  // The first version of this rule reported 277 boxes and nearly all were
  // decoration: gradient scrims (`absolute inset-0 bg-gradient-to-t`), glow
  // blobs, `pointer-events-none` watermarks, and sections whose content IS a CSS
  // background image. A judgement that calls a design layer a defect is worse
  // than no judgement, so the rule now only accepts a box that would be a
  // layout container in normal flow:
  //   * position static or relative — absolutely positioned layers are overlays,
  //     and an overlay with nothing in it is a design decision, not a hole
  //   * no background-image of its own — a box painting a gradient or a photo
  //     is not empty, whatever its children say
  //   * not pointer-events-none — that is a decoration marker worth trusting
  //   * nothing focusable or readable inside, and no media inside
  const tall = [...document.querySelectorAll('main div, main section, main article')];
  for (const el of tall) {
    const r = el.getBoundingClientRect();
    if (r.height < 120 || r.width < 120) continue;
    if (!shown(el)) continue;
    const cs = getComputedStyle(el);
    if (cs.position === 'absolute' || cs.position === 'fixed' || cs.position === 'sticky') continue;
    if (cs.pointerEvents === 'none') continue;
    if (cs.backgroundImage && cs.backgroundImage !== 'none') continue;
    if (el.querySelector('img,p,h1,h2,h3,h4,h5,h6,a,button,input,select,textarea,svg,video,canvas,table,li')) continue;
    if (words(el) > 0) continue;
    if (el.getAttribute('aria-hidden') === 'true') continue;
    if (el.className && typeof el.className === 'string' && /pointer-events-none|bg-gradient|bg-\[radial|bg-\[linear/.test(el.className)) continue;
    findings.push({
      verdict: 'empty-block',
      kind: 'container',
      heading: nearestHeading(el),
      control: '',
      panel: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string'
        ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.') : ''),
      words: 0,
      height: Math.round(r.height)
    });
  }

  const summary = findings.reduce((acc, f) => {
    acc[f.verdict] = (acc[f.verdict] || 0) + 1;
    return acc;
  }, {});
  return JSON.stringify({
    url: location.href,
    width: innerWidth,
    controlsWithPanels: controls.length,
    summary,
    findings: findings.slice(0, 60)
  });
})()
