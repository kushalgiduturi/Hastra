/* Hastra Labs — UEBA composite risk scoring (pure functions, no DOM).

     Risk = min(100, Σ Wᵢ·Sᵢ + correlation bonus)

   Four normalized signals Sᵢ ∈ [0, 100]:
     time    login-time anomaly (distance from the user's usual hours)
     geo     geo-velocity / impossible travel
     volume  data-volume deviation, entered as a z-score and scaled ×10
     c2      contact with a known-malicious IP or C2 beacon pattern
   Weights are renormalized to sum to 1, so the weighted part stays in [0, 100]
   whatever the analyst sets. Correlation bonuses reward signals that fire
   together, since co-occurring anomalies are far stronger evidence than any
   one of them alone; the bonus total is capped so no rule pile-up can push a
   single weak signal to Critical. */

export const SIGNALS = [
  { key: 'time',   label: 'Login-time anomaly',        hint: '0 = usual hours · 100 = never seen at this hour' },
  { key: 'geo',    label: 'Geo / impossible travel',   hint: '0 = usual location · 100 = physically impossible hop' },
  { key: 'volume', label: 'Volume deviation (z-score)', hint: 'z × 10, capped at 100 · 3σ above baseline = 30' },
  { key: 'c2',     label: 'Malicious IP / C2 beacon',  hint: '0 = clean · 100 = confirmed C2 or periodic beaconing' },
];

export const DEFAULT_WEIGHTS = { time: 0.15, geo: 0.30, volume: 0.20, c2: 0.35 };
export const BONUS_CAP = 25;

export const RULES = [
  { id: 'ato-c2',     bonus: 15, label: 'Account takeover + C2: impossible travel with beaconing (geo ≥ 60 and C2 ≥ 60)',
    test: s => s.geo >= 60 && s.c2 >= 60 },
  { id: 'exfil',      bonus: 10, label: 'Off-hours bulk access: unusual hour and volume spike (time ≥ 60 and volume ≥ 60)',
    test: s => s.time >= 60 && s.volume >= 60 },
  { id: 'staging',    bonus: 10, label: 'Data staging from a new location (geo ≥ 60 and volume ≥ 60)',
    test: s => s.geo >= 60 && s.volume >= 60 },
  { id: 'converge',   bonus: 10, label: 'Multi-signal convergence: three or more signals ≥ 50',
    test: s => ['time', 'geo', 'volume', 'c2'].filter(k => s[k] >= 50).length >= 3 },
];

export const TIERS = [
  { max: 30,  key: 'normal',   label: 'Normal',   tone: 'ok',   action: 'No action. Keep collecting baseline.' },
  { max: 60,  key: 'watch',    label: 'Watch',    tone: 'warn', action: 'Add to the watchlist and raise logging for this identity for 24 hours.' },
  { max: 80,  key: 'high',     label: 'High',     tone: 'high', action: 'Open a SOC ticket; require step-up MFA on the next sign-in; review recent sessions.' },
  { max: 100, key: 'critical', label: 'Critical', tone: 'crit', action: 'Auto-escalate: revoke sessions and tokens, isolate the host, page the on-call analyst.' },
];

export const PRESETS = {
  baseline:  { label: 'Baseline user',       time: 8,  geo: 0,  z: 0.4, c2: 0 },
  traveller: { label: 'Travelling executive', time: 45, geo: 70, z: 0.8, c2: 0 },
  insider:   { label: 'Insider exfiltration', time: 78, geo: 5,  z: 7.5, c2: 10 },
  takeover:  { label: 'Takeover + C2',        time: 62, geo: 88, z: 6.2, c2: 91 },
};

export const clamp = (v, lo = 0, hi = 100) => Math.min(hi, Math.max(lo, Number.isFinite(v) ? v : 0));
export const volumeFromZ = z => clamp(Math.max(0, z) * 10);

export function normalizeWeights(w) {
  const raw = Object.fromEntries(Object.keys(DEFAULT_WEIGHTS).map(k => [k, Math.max(0, Number(w?.[k]) || 0)]));
  const sum = Object.values(raw).reduce((a, b) => a + b, 0);
  if (sum <= 0) return { ...DEFAULT_WEIGHTS };
  return Object.fromEntries(Object.entries(raw).map(([k, v]) => [k, v / sum]));
}

export function tierFor(score) {
  return TIERS.find(t => score <= t.max) || TIERS[TIERS.length - 1];
}

// signals: { time, geo, volume, c2 } already on the 0–100 scale.
export function score(signals, weights = DEFAULT_WEIGHTS) {
  const s = Object.fromEntries(Object.keys(DEFAULT_WEIGHTS).map(k => [k, clamp(signals[k])]));
  const w = normalizeWeights(weights);
  const parts = Object.keys(s).map(k => ({ key: k, signal: s[k], weight: w[k], points: s[k] * w[k] }));
  const weighted = parts.reduce((a, p) => a + p.points, 0);
  const hits = RULES.filter(r => r.test(s));
  const rawBonus = hits.reduce((a, r) => a + r.bonus, 0);
  const bonus = Math.min(BONUS_CAP, rawBonus);
  const total = Math.min(100, Math.round(weighted + bonus));
  return { score: total, weighted, bonus, rawBonus, parts, hits, tier: tierFor(total), weights: w, signals: s };
}
