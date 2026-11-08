// Load on a page (<script src>) then call hastraContrastAudit().
// Forces light theme, then reports every visible text element whose WCAG contrast
// ratio against its effective background is below AA (4.5, or 3 for large text).
// Backgrounds that are gradients/images/video fall back to the page background
// colour, so treat results over hero art as hints rather than proof.
window.hastraContrastAudit = function () {
  document.documentElement.setAttribute('data-theme', 'light');
  const parse = (c) => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const lum = ({ r, g, b }) => { const f = (v) => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(r) + .7152 * f(g) + .0722 * f(b); };
  const over = (top, bot) => ({ r: top.r * top.a + bot.r * (1 - top.a), g: top.g * top.a + bot.g * (1 - top.a), b: top.b * top.a + bot.b * (1 - top.a), a: 1 });
  const bgOf = (el) => {
    const stack = [];
    for (let e = el; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c && c.a > 0) { stack.push(c); if (c.a >= 1) break; } }
    let base = { r: 255, g: 255, b: 255, a: 1 };
    for (let i = stack.length - 1; i >= 0; i--) base = over(stack[i], base);
    return base;
  };
  const out = [], seen = new Set();
  const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let n; (n = w.nextNode());) {
    if (!n.nodeValue.trim()) continue;
    const el = n.parentElement; if (!el || seen.has(el)) continue; seen.add(el);
    const cs = getComputedStyle(el), r = el.getBoundingClientRect();
    if (cs.visibility === 'hidden' || cs.display === 'none' || +cs.opacity === 0 || r.width < 2 || r.height < 2) continue;
    if (['SCRIPT', 'STYLE', 'NOSCRIPT'].includes(el.tagName)) continue;
    let fg = parse(cs.color); if (!fg) continue;
    const bg = bgOf(el); fg = over({ ...fg, a: fg.a * (+cs.opacity || 1) }, bg);
    const L1 = lum(fg), L2 = lum(bg), ratio = (Math.max(L1, L2) + .05) / (Math.min(L1, L2) + .05);
    const px = parseFloat(cs.fontSize), large = px >= 24 || (px >= 18.66 && +cs.fontWeight >= 700);
    if (ratio < (large ? 3 : 4.5)) out.push({ ratio: +ratio.toFixed(2), text: n.nodeValue.trim().slice(0, 40), sel: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''), color: cs.color });
  }
  return out.sort((a, b) => a.ratio - b.ratio);
};

// Spatial UI: call hastraGlassTokenAudit() on any themed page. Reads the live
// --sp-* tokens in both themes and reports every text-on-glass pair, with the
// glass composited over a reference ground for the world behind it (defaults
// match tests/suites/15_spatial_ui.php: #4a4a4a by night, #727478 by day).
// Returns rows sorted worst first; anything under 4.5 fails AA for body text.
window.hastraGlassTokenAudit = function (grounds) {
  grounds = grounds || { dark: [74, 74, 74], light: [114, 116, 120] };
  const root = document.documentElement, was = root.getAttribute('data-theme');
  const probe = document.createElement('i');
  probe.style.cssText = 'position:absolute;width:0;height:0;overflow:hidden';
  document.body.appendChild(probe);
  const rgb = (name) => {
    probe.style.color = 'var(' + name + ')';
    const m = getComputedStyle(probe).color.match(/rgba?\(([^)]+)\)/);
    if (!m) return null;
    const p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number);
    return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
  };
  const over = (t, b) => ({ r: t.r * t.a + b.r * (1 - t.a), g: t.g * t.a + b.g * (1 - t.a), b: t.b * t.a + b.b * (1 - t.a), a: 1 });
  const lum = ({ r, g, b }) => { const f = (v) => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(r) + .7152 * f(g) + .0722 * f(b); };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); };
  const texts = ['--sp-text', '--sp-text-2', '--sp-text-3', '--sp-accent-text', '--sp-ok', '--sp-warn', '--sp-bad', '--sp-info'];
  const fills = ['--sp-fill-2', '--sp-fill-3', '--sp-fill-4', '--sp-solid-1', '--sp-solid-2', '--sp-solid-3', '--sp-solid-4'];
  const out = [];
  for (const theme of ['dark', 'light']) {
    root.setAttribute('data-theme', theme);
    const g = { r: grounds[theme][0], g: grounds[theme][1], b: grounds[theme][2], a: 1 };
    const surfaces = fills.map((f) => [f, over(rgb(f), g)]);
    surfaces.push(['--sp-fill-1 in the panel', over(rgb('--sp-fill-1'), over(rgb('--sp-fill-2'), g))]);
    for (const [sname, bg] of surfaces)
      for (const t of texts) {
        const fg = over(rgb(t), bg);
        out.push({ theme, text: t, on: sname, ratio: +ratio(fg, bg).toFixed(2) });
      }
  }
  probe.remove();
  if (was === null) root.removeAttribute('data-theme'); else root.setAttribute('data-theme', was);
  return out.sort((a, b) => a.ratio - b.ratio);
};
