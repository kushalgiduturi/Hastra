/* Hastra — the rings on the sign-in hand (auth/login.php).

   The same ten-rings-in-a-circle formation as the Labs showcase centrepiece
   and the reference art (assets/js/shang-chi-rings.js, mode: 'circle'):
   engraved gunmetal rings linked in a ring, each woven over one neighbour
   and under the other, turning and tilting in space — centred on the
   hand's wrist as it rises out of the mist, not stacked as bracelets along
   the forearm. The back canvas is slotted in under the hand video and the
   front canvas over it (both inside #temple-hand-stage), so the formation
   passes behind the wrist and comes back around in front.

   Performance: the canvases cover only a box around the wrist, each on its
   own compositor layer. Full-viewport canvases here made the browser redo
   the hand video's drop-shadow/brightness filter across the whole screen on
   every ring frame — ~5 s stalls and 19 frames in 7 s in testing. Positions
   are fractions of the .video-bg box (sized to the gate, never animated), so
   they track the hand at any screen size. ?rings=0 turns them off. */
const v = new URL(import.meta.url).search;
const { createPowerRings } = await import(`./shang-chi-rings.js${v}`);

const stage = document.getElementById('temple-hand-stage');
const box = stage?.querySelector('.video-bg');
const off = document.body.hasAttribute('data-rings-off') || new URLSearchParams(location.search).get('rings') === '0';
if (stage && box && !off) {
  const make = cls => {
    const c = document.createElement('canvas');
    c.className = cls;
    c.setAttribute('aria-hidden', 'true');
    c.style.cssText = 'position:fixed;pointer-events:none;will-change:transform;transform:translateZ(0);contain:strict;';
    return c;
  };
  const back = make('rings-back'), front = make('rings-front');
  box.before(back);
  box.after(front);

  // the wrist geometry in viewport space, and the (square, circle-formation-
  // sized) canvas box around it
  let geo = null;
  const layout = () => {
    const b = box.getBoundingClientRect();
    if (!b.width) return;
    const x = b.left + b.width * 0.465, y = b.top + b.height * 0.585;
    const F = b.width * 0.135 * 2.2;         // formation radius (was the bracelet ring radius)
    const half = Math.round(F * 1.6);
    const L = Math.round(x - half), T = Math.round(y - half), W = half * 2, H = half * 2;
    geo = { x: x - L, y: y - T, radius: F };
    for (const c of [back, front]) Object.assign(c.style, { left: L + 'px', top: T + 'px', width: W + 'px', height: H + 'px' });
  };
  layout();
  addEventListener('resize', layout, { passive: true });

  const rings = createPowerRings({ back, front, mode: 'circle', startHidden: true, anchor: () => geo });

  // The rings are the entrance's power-up: they charge in while the hand is
  // still rising, then discharge (sparks, 2.5× spin) the instant the card
  // breaks out of the palm. After that the card covers the wrist, so they
  // wind down and are torn down rather than spin unseen behind it.
  const onClass = (el, cls, fn) => {
    if (el.classList.contains(cls)) return fn();
    new MutationObserver((_, mo) => { if (el.classList.contains(cls)) { mo.disconnect(); fn(); } })
      .observe(el, { attributes: true, attributeFilter: ['class'] });
  };
  onClass(box, 'is-rising', () => { layout(); setTimeout(() => rings.power(true), 900); });
  const card = document.getElementById('auth-card');
  if (card) onClass(card, 'revealed', () => {
    rings.boost(1600); rings.burst();
    setTimeout(() => rings.power(false), 2600);
    setTimeout(() => { rings.destroy(); back.remove(); front.remove(); removeEventListener('resize', layout); }, 5200);
  });
}
