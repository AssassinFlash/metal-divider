(async () => {
  // Click every interactive control on the page and record whether the page
  // actually changed. This finds dead controls and empty panels empirically:
  // it does not need to know which data attributes the markup chose, only that
  // something claiming to be interactive must do something when clicked.
  const norm = (s) => (s || '').replace(/\s+/g, ' ').trim();
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  const shown = (el) => {
    if (!el || !el.isConnected) return false;
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) < 0.02) return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  };

  const fingerprint = () => {
    const root = document.querySelector('main') || document.body;
    let chars = 0, visibleNodes = 0;
    const blocks = [];
    root.querySelectorAll('p,h1,h2,h3,h4,h5,h6,li,td,th,button,a,label,figcaption').forEach((el) => {
      if (!shown(el)) return;
      const r = el.getBoundingClientRect();
      chars += norm(el.textContent).length;
      visibleNodes++;
      if (r.height > 60) blocks.push(Math.round(r.height));
    });
    return {
      chars,
      visibleNodes,
      docH: document.documentElement.scrollHeight,
      blockSig: blocks.slice(0, 400).join(',')
    };
  };

  const describe = (el) => {
    const own = norm(el.textContent).slice(0, 60);
    return {
      tag: el.tagName.toLowerCase(),
      sel: el.tagName.toLowerCase()
        + (el.id ? '#' + el.id : '')
        + (typeof el.className === 'string' && el.className.trim()
          ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.')
          : ''),
      text: own,
      role: el.getAttribute('role') || '',
      expanded: el.getAttribute('aria-expanded'),
      selected: el.getAttribute('aria-selected'),
      controls: el.getAttribute('aria-controls') || el.getAttribute('data-target') || el.getAttribute('href') || ''
    };
  };

  // Every element that presents itself as a state switch. Deliberately broad:
  // a control that claims interactivity and does nothing is the defect.
  const selector = [
    '[aria-expanded]',
    '[aria-selected]',
    '[role="tab"]',
    '[role="button"]',
    '[data-sps-trigger]',
    'summary',
    'button[aria-controls]',
    'a[aria-controls]'
  ].join(',');

  // A control that navigates is not a state switch: clicking it would leave the
  // page and the audit would report every remaining control as dead.
  const navigates = (el) => {
    if (el.tagName !== 'A') return false;
    const href = el.getAttribute('href') || '';
    if (!href) return false;
    if (href.charAt(0) === '#') {
      const id = href.slice(1);
      if (!id) return false;
      const dest = document.getElementById(id);
      return !!dest && dest.getBoundingClientRect().height > innerHeight * 1.5;
    }
    if (href.indexOf('javascript:') === 0) return false;
    return true;
  };

  const seen = new Set();
  const targets = [...document.querySelectorAll(selector)].filter((el) => {
    if (!shown(el)) return false;
    if (el.disabled) return false;
    if (navigates(el)) return false;
    const r = el.getBoundingClientRect();
    if (r.width < 8 || r.height < 8) return false;
    // A control inside another control (icon inside a button) is not separate.
    if (el.parentElement && el.parentElement.closest(selector) && el.parentElement.closest(selector) !== el) return false;
    const sig = describe(el).sel + '|' + describe(el).text;
    if (seen.has(sig)) return false;
    seen.add(sig);
    return true;
  });

  const results = [];

  for (let i = 0; i < targets.length && i < 90; i++) {
    const el = targets[i];
    if (!el.isConnected) continue;
    const before = fingerprint();
    const info = describe(el);

    // Measure the panel this control claims to own, before the click.
    let panelBefore = null;
    const id = info.controls.startsWith('#') ? info.controls.slice(1) : info.controls;
    if (id && document.getElementById(id)) {
      const p = document.getElementById(id);
      const r = p.getBoundingClientRect();
      panelBefore = { h: Math.round(r.height), hidden: p.hasAttribute('hidden') || !shown(p), words: norm(p.textContent).split(' ').filter(Boolean).length };
    }

    try {
      el.click();
    } catch (e) {
      results.push({ ...info, verdict: 'click-threw', error: String(e).slice(0, 120) });
      continue;
    }

    // A CSS transition needs the frame after the state change; the height
    // transitions here run ~350ms.
    await sleep(420);

    const after = fingerprint();
    let panelAfter = null;
    if (id && document.getElementById(id)) {
      const p = document.getElementById(id);
      const r = p.getBoundingClientRect();
      panelAfter = { h: Math.round(r.height), hidden: p.hasAttribute('hidden') || !shown(p), words: norm(p.textContent).split(' ').filter(Boolean).length };
    }

    const deltaChars = after.chars - before.chars;
    const deltaNodes = after.visibleNodes - before.visibleNodes;
    const deltaH = after.docH - before.docH;

    let verdict = 'ok';
    if (deltaChars === 0 && deltaNodes === 0 && deltaH === 0 && before.blockSig === after.blockSig) {
      verdict = 'dead-control';
    } else if (panelAfter && panelAfter.h === 0 && panelAfter.words > 0) {
      verdict = 'empty-panel';
    } else if (panelAfter && panelAfter.h === 0 && panelAfter.words === 0) {
      verdict = 'empty-panel-blank';
    } else if (deltaChars < -200) {
      verdict = 'content-disappeared';
    }

    results.push({
      ...info,
      verdict,
      panelBefore,
      panelAfter,
      deltaChars,
      deltaNodes,
      deltaH
    });

    // Put the page back the way it was so the next control starts clean.
    if (verdict !== 'dead-control') {
      try { el.click(); await sleep(300); } catch (e) { /* leave it */ }
    }
  }

  window.scrollTo(0, 0);
  const summary = results.reduce((acc, r) => { acc[r.verdict] = (acc[r.verdict] || 0) + 1; return acc; }, {});
  return JSON.stringify({
    url: location.href,
    controls: targets.length,
    tested: results.length,
    summary,
    findings: results.filter((r) => r.verdict !== 'ok')
  });
})()
