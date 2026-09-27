/* Hastra — landing-page showcase for Hastra Labs (landing-pages/hastra.html).
   Native only (no GSAP): Web Animations API + IntersectionObserver for the
   staggered entrance, pointer-tracked spotlights, three live previews, and
   the ten-rings centrepiece that powers up the group each module maps to. */
import { createPowerRings, RING_GROUPS } from './shang-chi-rings.js?v=2';
import { score as uebaScore } from './ueba-calculator.js?v=2';

const root = document.getElementById('labs-showcase');
if (root) init(root);

function init(root) {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const items = [...root.querySelectorAll('.hx-item')];

  // ── ten rings ──
  const stage = root.querySelector('.hx-rings');
  const rings = createPowerRings({
    back: stage.querySelector('.hx-back'), front: stage.querySelector('.hx-front'), mode: 'circle', core: true,
    anchor: () => {
      const r = stage.getBoundingClientRect();
      return { x: r.width / 2, y: r.height / 2, radius: Math.min(r.width * 0.36, r.height * 0.33) };
    },
  });
  const legend = [...root.querySelectorAll('.hx-legend button')];
  let pinned = null;
  const light = () => document.documentElement.classList.contains('light') || document.documentElement.getAttribute('data-theme') === 'light';
  const paintLegend = active => legend.forEach(b => {
    const g = +b.dataset.group;
    b.setAttribute('aria-pressed', String(active === g));
    b.querySelector('i').style.background = `rgb(${RING_GROUPS[g][light() ? 'light' : 'dark'].join(',')})`;
  });
  const focusGroup = g => { rings.highlight(g ?? pinned); paintLegend(g ?? pinned); };
  paintLegend(null);
  legend.forEach(b => b.addEventListener('click', () => {
    const g = +b.dataset.group;
    pinned = pinned === g ? null : g;
    focusGroup(pinned);
    if (pinned !== null) { rings.boost(); rings.burst(); }
  }));
  document.addEventListener('hastra:themechange', () => paintLegend(pinned));
  new MutationObserver(() => paintLegend(pinned)).observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme'] });

  // ── columns: power their ring group, rise, and track a spotlight ──
  items.forEach(item => {
    const g = +item.dataset.group;
    item.addEventListener('pointerenter', () => { focusGroup(g); rings.boost(900); });
    item.addEventListener('pointerleave', () => focusGroup(null));
    item.addEventListener('focusin', () => focusGroup(g));
    item.addEventListener('focusout', e => { if (!item.contains(e.relatedTarget)) focusGroup(null); });
    item.addEventListener('pointermove', e => {
      const r = item.getBoundingClientRect();
      item.style.setProperty('--mouse-x', `${e.clientX - r.left}px`);
      item.style.setProperty('--mouse-y', `${e.clientY - r.top}px`);
    });
    const btn = item.querySelector('.ring-power-beam');
    btn.addEventListener('pointerenter', () => { rings.boost(); rings.burst(); btn.classList.add('is-active'); });
    btn.addEventListener('pointerleave', () => btn.classList.remove('is-active'));
    btn.addEventListener('click', () => { rings.boost(2000); rings.burst(); });
  });

  // ── staggered entrance (y 40 → 0, fade, 150 ms apart, quint-out) ──
  if (!reduce && 'animate' in Element.prototype) {
    items.forEach(i => i.classList.add('hx-pre'));
    const io = new IntersectionObserver(entries => {
      if (!entries.some(e => e.isIntersecting)) return;
      io.disconnect();
      items.forEach((item, i) => {
        item.classList.remove('hx-pre');
        item.animate([{ transform: 'translateY(40px)', opacity: 0 }, { transform: 'translateY(0)', opacity: 1 }],
                     { duration: 1000, delay: i * 150, easing: 'cubic-bezier(.23, 1, .32, 1)', fill: 'backwards' });
      });
      rings.burst();
    }, { threshold: 0.2 });
    io.observe(root.querySelector('.hx-grid'));
  }

  // ── preview 1: live text → SHA-256, in the browser ──
  const hin = root.querySelector('#hx-hash-in'), hout = root.querySelector('#hx-hash-out');
  let hseq = 0;
  const hashNow = async () => {
    const seq = ++hseq;
    if (!crypto.subtle) { hout.textContent = 'WebCrypto needs a secure context (https or localhost).'; return; }
    const d = new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(hin.value)));
    if (seq === hseq) hout.textContent = Array.from(d, b => b.toString(16).padStart(2, '0')).join('');
  };
  hin.addEventListener('input', hashNow);
  hashNow();

  // ── preview 2: one UEBA signal, the real composite formula ──
  const geo = root.querySelector('#hx-geo'), sOut = root.querySelector('#hx-score'), tier = root.querySelector('#hx-tier'), geoVal = root.querySelector('#hx-geo-v');
  const paintScore = () => {
    const r = uebaScore({ time: 55, geo: +geo.value, volume: 62, c2: 30 });
    sOut.textContent = r.score;
    geoVal.textContent = geo.value;
    tier.textContent = r.tier.label;
    tier.dataset.tone = r.tier.tone;
  };
  geo.addEventListener('input', paintScore);
  paintScore();

  // ── preview 3: the dislike → next-best slide swap ──
  const DEMO = [
    ['STIX 2.1 objects and patterns', 'Rank #1 · 58 min'], ['TAXII 2.1 collections explained', 'Rank #2 · 41 min'],
    ['Threat feed normalization walkthrough', 'Rank #3 · 1:06:12'], ['Building an IOC pipeline', 'Rank #4 · 37 min'],
  ];
  const vbox = root.querySelector('.hx-vbox');
  let v = 0;
  const card = i => {
    const c = document.createElement('div');
    c.className = 'hx-vcard';
    const rank = document.createElement('span'); rank.className = 'hx-vrank'; rank.textContent = `#${i + 1}`;
    const t = document.createElement('b'); t.textContent = DEMO[i][0];
    const m = document.createElement('span'); m.textContent = DEMO[i][1];
    c.append(rank, t, m);
    return c;
  };
  vbox.append(card(0));
  root.querySelector('.hx-vnext').addEventListener('click', () => {
    const cur = vbox.querySelector('.hx-vcard');
    v = (v + 1) % DEMO.length;
    const next = () => {
      const c = card(v);
      c.classList.add('is-entering');
      cur.replaceWith(c);
      requestAnimationFrame(() => requestAnimationFrame(() => c.classList.remove('is-entering')));
    };
    if (reduce) return next();
    cur.classList.add('is-leaving');
    setTimeout(next, 350);
  });
}
