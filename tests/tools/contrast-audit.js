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
