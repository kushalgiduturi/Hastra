/* Hastra Labs — one free run, then an account.

   A visitor who is not signed in gets exactly ONE free action across the
   Labs tools (an encryption, a hash, a UEBA score, a syllabus extraction ...).
   The second attempt is stopped before it runs and the "Access Gatekeeper"
   dialog asks them to create an account or sign in. Signed-in people (a Hastra
   account, or a free Labs session) are never limited; the server tells the page
   so through <meta name="labs-signed-in">.

   The count lives in localStorage and is mirrored into a first-party cookie
   with a coarse device label, so clearing one of the two does not reset it.
   This is a client-side nudge, not a security boundary: the tools run in the
   browser, so a determined visitor can still get around it.

   Live calculators (UEBA, scaling) recompute on every keystroke, so the one
   trial there is "this visit": once it has been spent on a calculator, that
   calculator stays usable until the page is reloaded.

   On localhost only, HastraTrial.reset() (or ?trial=reset) clears the count. */
import { el, svg, labsBase, toast } from './labs-common.js';

const COUNT_KEY = 'hastra_labs_trial_count';
const AT_KEY    = 'hastra_labs_trial_at';
const COOKIE    = 'hastra_labs_trial';
const LOCAL     = ['localhost', '127.0.0.1', '[::1]'].includes(location.hostname);

const signedIn = () => document.querySelector('meta[name="labs-signed-in"]')?.content === '1';

// a coarse, non-identifying device label (not a hash of anything sensitive)
function deviceLabel() {
  const s = [navigator.language, Intl.DateTimeFormat().resolvedOptions().timeZone, screen.width + 'x' + screen.height, navigator.hardwareConcurrency || 0].join('|');
  let h = 2166136261;
  for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619) >>> 0; }
  return h.toString(16).padStart(8, '0');
}

function cookieCount() {
  const m = document.cookie.split('; ').find(c => c.startsWith(COOKIE + '='));
  const n = m ? parseInt(m.slice(COOKIE.length + 1), 10) : 0;
  return Number.isFinite(n) && n > 0 ? n : 0;
}
function storedCount() {
  let n = 0;
  try { n = parseInt(localStorage.getItem(COUNT_KEY) || '0', 10); } catch { /* private mode */ }
  return Number.isFinite(n) && n > 0 ? n : 0;
}
export const getTrialCount = () => Math.max(storedCount(), cookieCount());

function setTrialCount(n) {
  try { localStorage.setItem(COUNT_KEY, String(n)); localStorage.setItem(AT_KEY, new Date().toISOString()); } catch { /* private mode */ }
  document.cookie = `${COOKIE}=${n}.${deviceLabel()}; max-age=31536000; path=/; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
}
function clearTrial() {
  try { localStorage.removeItem(COUNT_KEY); localStorage.removeItem(AT_KEY); } catch { /* ignore */ }
  document.cookie = `${COOKIE}=; max-age=0; path=/; SameSite=Lax`;
}

let trialTool = null;            // the tool this visit's free run was spent on
let lastFocus = null;
let modal = null;

const at = path => new URL('../' + path, location.origin + labsBase).href;   // enterprise pages sit one level above /labs/

function buildModal() {
  const title = el('h2', { id: 'lx-gate-title', class: 'lx-gate-title', text: 'Create a free account to keep going' });
  const body = el('p', { id: 'lx-gate-body', class: 'lx-gate-body' });
  const create = el('a', { class: 'lx-btn lx-btn--block btn-beam', href: at('signup'), text: 'Create free account' });
  const signin = el('a', { class: 'lx-btn lx-btn--block lx-btn--ghost', href: at('signin'), text: 'Sign in' });
  const dismiss = el('button', { type: 'button', class: 'lx-gate-dismiss', text: 'Not now', onclick: closeGate });
  const card = el('div', { class: 'lx-gate-card', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'lx-gate-title', 'aria-describedby': 'lx-gate-body', tabindex: '-1' },
    el('span', { class: 'lx-gate-badge' }, svg('svg', { viewBox: '0 0 24 24', width: 14, height: 14, fill: 'none', 'aria-hidden': 'true' },
      svg('path', { d: 'M12 3l7 3v6c0 4.2-2.9 7.7-7 9-4.1-1.3-7-4.8-7-9V6l7-3z', stroke: 'currentColor', 'stroke-width': 1.8 })), ' Free run used'),
    title, body,
    el('div', { class: 'lx-gate-actions' }, create, signin),
    el('p', { class: 'lx-gate-alt' }, 'Prefer no email? ', el('a', { href: labsBase + 'auth', text: 'Start a free Labs session' }), '.'),
    dismiss);
  const root = el('div', { class: 'lx-gate', id: 'lx-gate', hidden: true }, card);
  root.addEventListener('click', e => { if (e.target === root) closeGate(); });
  root.addEventListener('keydown', e => {
    if (e.key === 'Escape') { e.preventDefault(); closeGate(); return; }
    if (e.key !== 'Tab') return;
    const f = [...card.querySelectorAll('a[href], button')].filter(n => !n.disabled);
    if (!f.length) return;
    const first = f[0], last = f[f.length - 1];
    if (e.shiftKey && (document.activeElement === first || document.activeElement === card)) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });
  document.body.append(root);
  return { root, card, body };
}

function openGate(toolName) {
  if (!modal) modal = buildModal();
  modal.body.textContent = `You have used your one free run${toolName ? ' (' + toolName + ')' : ''}. Create a free Hastra account or sign in to use every Labs tool without limits.`;
  lastFocus = document.activeElement;
  modal.root.hidden = false;
  requestAnimationFrame(() => modal.root.classList.add('is-open'));
  modal.card.focus();
}
function closeGate() {
  if (!modal) return;
  modal.root.classList.remove('is-open');
  modal.root.hidden = true;
  if (lastFocus && lastFocus.focus) lastFocus.focus();
}

// Call before running an action. Returns true when it may proceed.
//   opts.live  the tool recomputes continuously (calculators): once this
//              visit's free run was spent on it, it stays usable until reload
export function checkLabAccess(toolName, opts = {}) {
  if (signedIn()) return true;
  if (getTrialCount() === 0) {
    setTrialCount(1);
    trialTool = toolName;
    toast('Free run used. Create a free account to keep using the Labs tools.', 5000);
    return true;
  }
  if (opts.live && trialTool === toolName) return true;
  openGate(toolName);
  return false;
}

// Wraps an event handler so it only runs when access is granted.
export const gated = (toolName, handler, opts) => function (...args) {
  if (!checkLabAccess(toolName, opts)) return undefined;
  return handler.apply(this, args);
};

// Gates a page's controls without touching their own handlers: a capture-phase
// listener on the document runs first and cancels the event when access is
// refused, so the tool's code never sees it.
//   clicks  { selector: toolName }  every click is one run (strict)
//   live    { selector: toolName }  calculators; any input/click inside counts
//                                   as using the tool (see the file header)
export function gateLabs({ clicks = {}, live = {} } = {}) {
  const refuse = e => { e.preventDefault(); e.stopImmediatePropagation(); };
  document.addEventListener('click', e => {
    for (const [sel, name] of Object.entries(clicks)) {
      if (e.target.closest(sel)) { if (!checkLabAccess(name)) refuse(e); return; }
    }
  }, true);
  for (const type of ['click', 'input', 'change']) {
    document.addEventListener(type, e => {
      for (const [sel, name] of Object.entries(live)) {
        if (e.target.closest(sel)) { if (!checkLabAccess(name, { live: true })) refuse(e); return; }
      }
    }, true);
  }
}

if (LOCAL) {
  const api = { reset() { clearTrial(); trialTool = null; toast('Trial count reset (local testing).'); }, count: getTrialCount };
  window.HastraTrial = api;
  if (new URLSearchParams(location.search).get('trial') === 'reset') api.reset();
}
