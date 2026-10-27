(() => {
  const norm = (s) => (s || '').replace(/\s+/g, ' ').trim();
  const visible = (el) => {
    if (!el) return false;
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0') return false;
    if (el.closest('[hidden]')) return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  };
  const root = document.querySelector('main') || document.body;

  const headings = [...root.querySelectorAll('h1,h2,h3,h4,h5,h6')]
    .filter(visible)
    .map((h) => ({ t: h.tagName.toLowerCase(), x: norm(h.textContent) }))
    .filter((h) => h.x);

  const paras = [...root.querySelectorAll('p')]
    .filter(visible)
    .map((p) => norm(p.textContent))
    .filter((t) => t.length > 1);

  // Text that lives outside p/h/li/figcaption/button/a/table — the copy a
  // rebuild silently drops when it re-types a page by hand.
  const looseText = [...root.querySelectorAll('span,div,strong,em,b,i,small,time,dt,dd,blockquote')]
    .filter(visible)
    .filter((el) => !el.querySelector('p,h1,h2,h3,h4,h5,h6,li,table,ul,ol,div,span,button,a'))
    .map((el) => norm(el.textContent))
    .filter((t) => t.length > 3);

  const lis = [...root.querySelectorAll('li')].filter(visible).map((l) => norm(l.textContent)).filter((t) => t.length > 1);

  const tables = [...root.querySelectorAll('table')].filter(visible).map((tb) => ({
    head: [...tb.querySelectorAll('thead th, tr:first-child th, tr:first-child td')].map((c) => norm(c.textContent)),
    rows: tb.querySelectorAll('tbody tr').length || tb.querySelectorAll('tr').length
  }));

  const links = [...root.querySelectorAll('a')].filter(visible).map((a) => ({
    x: norm(a.textContent).slice(0, 120),
    h: a.getAttribute('href') || ''
  })).filter((l) => l.x);

  const buttons = [...root.querySelectorAll('button,[role="button"]')].filter(visible).map((b) => norm(b.textContent)).filter((t) => t.length > 1);

  const imgs = [...root.querySelectorAll('img')].map((i) => ({
    src: (i.currentSrc || i.src || '').replace(/^https?:\/\/[^/]+/, '').replace(/-\d+w\.(webp|jpg|png|avif)$/i, ''),
    alt: i.getAttribute('alt') || '',
    ok: i.complete && i.naturalWidth > 0,
    lazy: i.getAttribute('loading') || ''
  }));

  const fields = [...root.querySelectorAll('input,select,textarea')]
    .filter((f) => f.type !== 'hidden')
    .map((f) => {
      const lab = f.closest('label') || (f.id && document.querySelector('label[for="' + CSS.escape(f.id) + '"]'));
      return {
        name: f.name || f.id || f.type,
        label: norm(lab ? lab.textContent : (f.getAttribute('aria-label') || f.getAttribute('placeholder') || '')),
        required: f.hasAttribute('required')
      };
    });

  const sections = [...root.children].filter(visible).map((s, i) => {
    const r = s.getBoundingClientRect();
    return {
      i,
      tag: s.tagName.toLowerCase(),
      id: s.id || '',
      cls: (s.className || '').toString().slice(0, 90),
      h: Math.round(r.height),
      words: norm(s.textContent).split(' ').filter(Boolean).length,
      imgs: s.querySelectorAll('img').length
    };
  });

  return JSON.stringify({
    url: location.href,
    title: document.title,
    doc: {
      w: document.documentElement.scrollWidth,
      h: document.documentElement.scrollHeight
    },
    counts: {
      headings: headings.length,
      paras: paras.length,
      loose: looseText.length,
      lis: lis.length,
      tables: tables.length,
      links: links.length,
      buttons: buttons.length,
      imgs: imgs.length,
      fields: fields.length,
      sections: sections.length
    },
    headings, paras, looseText, lis, tables, links, buttons, imgs, fields, sections
  });
})()
