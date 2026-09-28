/* Hastra — mounts the sign-in ring halo (login-halo.js) as a backdrop
   behind the auth card's .brand mark, powering it up once the card finishes
   its reveal — the same moment login-rings.js hands off from the wrist
   bracelets. .brand itself is a tight flex row (~40px tall), too small for
   a ring to read in, so the halo gets its own sized, centred backdrop
   rather than filling .brand's own box. */
const v = new URL(import.meta.url).search;
const { createLoginHalo } = await import(`./login-halo.js${v}`);

const brand = document.querySelector('.brand');
const card = document.getElementById('auth-card');
if (brand && brand.parentElement) {
  brand.parentElement.style.position ||= 'relative';
  const backdrop = document.createElement('div');
  backdrop.className = 'brand-halo';
  backdrop.style.cssText = 'position:absolute;left:50%;top:-4px;width:170px;height:110px;'
    + 'transform:translateX(-50%);pointer-events:none;z-index:0;';
  brand.before(backdrop);
  brand.style.position = 'relative';
  brand.style.zIndex = '1';

  const halo = createLoginHalo(backdrop);
  if (halo && card) {
    const onReveal = () => halo.power(true);
    if (card.classList.contains('revealed')) onReveal();
    else new MutationObserver((_, mo) => {
      if (card.classList.contains('revealed')) { mo.disconnect(); onReveal(); }
    }).observe(card, { attributes: true, attributeFilter: ['class'] });
  }
}
