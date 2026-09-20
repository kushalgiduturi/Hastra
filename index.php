<?php
require __DIR__ . '/core/db.php';
secure_session_start();
if (isset($_SESSION["user_id"])) {
    header("Location: " . get_base_url() . "portals/index");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Astra · Enterprise Software Delivery Platform</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<meta name="description" content="Astra — encrypted, audited, governed software delivery for teams that can't afford to guess.">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    min-height: 100vh;
    background-color: var(--navy);
    background-image:
      linear-gradient(var(--grid-line) 1px, transparent 1px),
      linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
    background-size: 40px 40px;
    font-family: var(--font-sans);
    color: var(--text);
    transition: var(--transition);
  }

  a { color: inherit; }
  section { padding: 5rem 1.5rem; }
  .wrap { max-width: 1100px; margin: 0 auto; }

  /* ── Top bar ── */
  .topbar {
    position: sticky; top: 0; z-index: 100;
    background: var(--topnav-bg);
    border-bottom: 1px solid var(--border);
    padding: 0 1.5rem; height: 60px;
    display: flex; align-items: center; justify-content: space-between;
  }
  .brand { display: inline-flex; align-items: center; gap: 10px; text-decoration: none; justify-content: center; }
  .brand-icon { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .brand-title { font-family: 'Share Tech Mono', monospace; font-size: 15px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text); }
  .topbar-right { display: flex; align-items: center; gap: 10px; }
  .btn-theme-toggle {
    display: flex; align-items: center; gap: 6px;
    background: var(--input-bg); border: 1px solid var(--border-dim);
    color: var(--text-dim); font-family: var(--font-sans);
    font-size: 12px; font-weight: 500; letter-spacing: 0.04em; text-transform: uppercase;
    padding: 8px 12px; border-radius: 3px; cursor: pointer; transition: var(--transition);
  }
  .btn-theme-toggle:hover { background: var(--hover-bg); color: var(--text); border-color: var(--border); }
  .btn-theme-toggle .theme-icon svg { width: 13px; height: 13px; vertical-align: middle; fill: currentColor; }
  .btn-login {
    font-size: 13px; font-weight: 600; color: var(--text-dim); text-decoration: none;
    padding: 8px 14px; border-radius: 3px; transition: color 0.2s, background 0.2s;
  }
  .btn-login:hover { color: var(--text); background: var(--hover-bg); }
  .btn-register {
    font-size: 13px; font-weight: 600; color: #fff; text-decoration: none;
    background: var(--accent); padding: 9px 16px; border-radius: 3px;
    transition: background 0.2s, box-shadow 0.2s;
  }
  .btn-register:hover { background: var(--accent-dim); box-shadow: 0 0 16px rgba(var(--accent-rgb),0.35); }

  /* ── Hero ── */
  .hero { padding: 5.5rem 1.5rem 4rem; text-align: center; }
  .hero-badge {
    display: inline-flex; align-items: center; gap: 6px;
    font-family: 'Share Tech Mono', monospace; font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase;
    color: var(--accent-bright); background: rgba(var(--accent-rgb),0.1);
    border: 1px solid var(--border); padding: 5px 12px; border-radius: 999px; margin-bottom: 1.4rem;
  }
  .hero h1 {
    font-family: var(--font-sans); font-weight: 800; letter-spacing: -0.02em;
    font-size: clamp(32px, 5.5vw, 56px); line-height: 1.08; max-width: 820px; margin: 0 auto 1.2rem;
  }
  .hero h1 span { color: var(--accent-bright); }
  .hero p.lede {
    font-size: 16px; color: var(--text-dim); line-height: 1.7; max-width: 620px; margin: 0 auto 2.2rem;
  }
  .hero-ctas { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-bottom: 3.5rem; }
  .btn-hero-primary {
    font-size: 14px; font-weight: 700; color: #fff; text-decoration: none;
    background: var(--accent); padding: 13px 26px; border-radius: 4px;
    transition: background 0.2s, box-shadow 0.2s;
  }
  .btn-hero-primary:hover { background: var(--accent-dim); box-shadow: 0 0 22px rgba(var(--accent-rgb),0.4); }
  .btn-hero-secondary {
    font-size: 14px; font-weight: 600; color: var(--text); text-decoration: none;
    background: var(--input-bg); border: 1px solid var(--border-dim); padding: 13px 26px; border-radius: 4px;
    transition: background 0.2s, border-color 0.2s;
  }
  .btn-hero-secondary:hover { background: var(--hover-bg); border-color: var(--border); }

  .hero-stats { display: flex; gap: 2.5rem; justify-content: center; flex-wrap: wrap; }
  .hero-stat { text-align: center; }
  .hero-stat .num { font-family: 'Share Tech Mono', monospace; font-size: 26px; font-weight: 700; color: var(--accent-bright); }
  .hero-stat .lbl { font-size: 11px; color: var(--text-dim); letter-spacing: 0.06em; text-transform: uppercase; margin-top: 2px; }

  /* ── Section heading ── */
  .section-head { text-align: center; max-width: 640px; margin: 0 auto 3rem; }
  .section-eyebrow {
    font-family: 'Share Tech Mono', monospace; font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase;
    color: var(--accent-bright); margin-bottom: 10px; display: block;
  }
  .section-head h2 { font-family: var(--font-sans); font-weight: 700; font-size: clamp(24px,3.5vw,32px); letter-spacing: -0.01em; margin-bottom: 10px; }
  .section-head p { font-size: 14px; color: var(--text-dim); line-height: 1.7; }

  /* ── Platform feature grid ── */
  .feat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 14px; }
  .feat-card {
    background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 6px;
    padding: 1.4rem; transition: border-color 0.2s, transform 0.2s;
  }
  .feat-card:hover { border-color: rgba(var(--accent-rgb),0.3); transform: translateY(-2px); }
  .feat-icon {
    width: 40px; height: 40px; border-radius: 8px; background: rgba(59,130,246,0.1);
    display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;
  }
  .feat-icon svg { width: 20px; height: 20px; fill: var(--blue-bright); }
  .feat-card h3 { font-size: 15px; font-weight: 600; margin-bottom: 6px; }
  .feat-card p { font-size: 12.5px; color: var(--text-dim); line-height: 1.6; }

  /* ── Security grid (interactive) ── */
  .sec-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 14px; }
  .sec-card {
    background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 6px;
    padding: 1.4rem; cursor: pointer; transition: border-color 0.2s, background 0.2s;
  }
  .sec-card:hover, .sec-card.open { border-color: rgba(var(--accent-rgb),0.35); background: var(--hover-bg); }
  .sec-icon {
    width: 40px; height: 40px; border-radius: 8px; background: rgba(var(--accent-rgb),0.1);
    display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;
  }
  .sec-icon svg { width: 20px; height: 20px; fill: var(--accent-bright); }
  .sec-card h3 { font-size: 15px; font-weight: 600; margin-bottom: 6px; }
  .sec-card .sec-summary { font-size: 12.5px; color: var(--text-dim); line-height: 1.6; }
  .sec-detail {
    max-height: 0; overflow: hidden; opacity: 0;
    transition: max-height 0.3s cubic-bezier(0.16,1,0.3,1), opacity 0.2s ease, margin-top 0.3s;
    font-size: 12.5px; color: var(--text-dim); line-height: 1.6;
  }
  .sec-card.open .sec-detail { max-height: 200px; opacity: 1; margin-top: 10px; }
  .sec-toggle-hint { font-size: 10.5px; color: var(--text-dim); margin-top: 12px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.05em; text-transform: uppercase; opacity: 0.7; }

  /* ── Hierarchy flow ── */
  .hierarchy-flow { display: flex; align-items: stretch; justify-content: center; gap: 0; flex-wrap: wrap; }
  .hflow-node {
    background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 6px;
    padding: 1.3rem 1.2rem; width: 200px; text-align: center;
  }
  .hflow-node .hflow-icon {
    width: 44px; height: 44px; border-radius: 50%; background: rgba(var(--accent-rgb),0.1);
    display: flex; align-items: center; justify-content: center; margin: 0 auto 10px;
  }
  .hflow-node .hflow-icon svg { width: 22px; height: 22px; fill: var(--accent-bright); }
  .hflow-node h4 { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
  .hflow-node p { font-size: 11.5px; color: var(--text-dim); line-height: 1.5; }
  .hflow-arrow { display: flex; align-items: center; justify-content: center; width: 44px; flex-shrink: 0; }
  .hflow-arrow svg { width: 22px; height: 22px; fill: var(--text-dim); }
  @media (max-width: 900px) {
    .hflow-arrow { width: 100%; transform: rotate(90deg); padding: 4px 0; }
    .hierarchy-flow { flex-direction: column; align-items: center; }
  }

  /* ── Terms ── */
  .terms-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
  .terms-card { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 6px; padding: 1.5rem; }
  .terms-card .terms-num {
    font-family: 'Share Tech Mono', monospace; font-size: 11px; color: var(--accent-bright);
    background: rgba(var(--accent-rgb),0.1); border: 1px solid var(--border); border-radius: 999px;
    width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; margin-bottom: 12px;
  }
  .terms-card h4 { font-size: 14.5px; font-weight: 600; margin-bottom: 8px; }
  .terms-card p { font-size: 12.5px; color: var(--text-dim); line-height: 1.7; }

  /* ── CTA band ── */
  .cta-band {
    background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 8px;
    padding: 3rem 2rem; text-align: center; margin: 0 1.5rem;
  }
  .cta-band h2 { font-family: var(--font-sans); font-size: clamp(22px,3vw,28px); font-weight: 700; margin-bottom: 10px; }
  .cta-band p { font-size: 13.5px; color: var(--text-dim); margin-bottom: 1.6rem; }

  footer { padding: 2.5rem 1.5rem; text-align: center; font-size: 12px; color: var(--text-dim); border-top: 1px solid var(--border-dim); }

  @media (prefers-reduced-motion: reduce) { .sec-detail { transition: none; } }
</style>
</head>
<body>

<nav class="topbar">
  <a href="<?= get_base_url() ?>" class="brand">
    <div class="brand-icon"><svg viewBox="0 0 48 48"><defs><linearGradient id="astraMark" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="var(--accent-bright)"/><stop offset="1" stop-color="var(--purple, #a78bfa)"/></linearGradient></defs><path fill="url(#astraMark)" d="M24 3 L45 45 H34.4 L24 24.2 L13.6 45 H3 Z"/><path fill="url(#astraMark)" d="M24 14.5 L27.7 22 L35 25.5 L27.7 29 L24 36.5 L20.3 29 L13 25.5 L20.3 22 Z"/></svg></div>
    <span class="brand-title">Astra</span>
  </a>
  <div class="topbar-right">
    <button id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle">
      <span class="theme-icon"></span>
      <span class="theme-label"></span>
    </button>
    <a href="<?= get_base_url() ?>auth/login" class="btn-login">Sign In</a>
    <a href="<?= get_base_url() ?>auth/register" class="btn-register">Get Started</a>
  </div>
</nav>

<!-- ── HERO ── -->
<div class="hero">
  <span class="hero-badge">Enterprise software delivery, governed end-to-end</span>
  <h1>Ship client work with a <span>paper trail</span> that survives an audit.</h1>
  <p class="lede">
    Astra is the delivery backbone for teams building software for other companies —
    requirements, projects, testing, deployment, billing, and every credential in
    between, encrypted at rest and logged the moment it changes hands.
  </p>
  <div class="hero-ctas">
    <a href="<?= get_base_url() ?>auth/register" class="btn-hero-primary">Register your company</a>
    <a href="<?= get_base_url() ?>auth/login" class="btn-hero-secondary">Sign in</a>
  </div>
  <div class="hero-stats">
    <div class="hero-stat"><div class="num">AES-256</div><div class="lbl">Column Encryption</div></div>
    <div class="hero-stat"><div class="num">2FA</div><div class="lbl">OTP On Every Login</div></div>
    <div class="hero-stat"><div class="num">100%</div><div class="lbl">Actions Logged</div></div>
  </div>
</div>

<!-- ── SECURITY ARCHITECTURE ── -->
<!-- ── WHAT ASTRA DOES ── -->
<section id="features" style="background:var(--navy-deep);">
  <div class="wrap">
    <div class="section-head">
      <span class="section-eyebrow">What Astra Does</span>
      <h2>One platform, the whole delivery lifecycle</h2>
      <p>From the moment a client submits a requirement to the day their deployment goes live, every step happens in Astra — not scattered across email, spreadsheets, and chat.</p>
    </div>
    <div class="feat-grid">
      <div class="feat-card">
        <div class="feat-icon"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg></div>
        <h3>Requirements &amp; Approvals</h3>
        <p>Clients submit new requirements or change requests; project managers review, request clarification, or approve them into a project — every decision timestamped.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon"><svg viewBox="0 0 24 24"><path d="M20 6h-8l-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2z"/></svg></div>
        <h3>Project &amp; Task Delivery</h3>
        <p>Team leads assign developers, testers, and reviewers to a project and track every task through pending, in-progress, and completed.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon"><svg viewBox="0 0 24 24"><path d="M20 8h-2.81c-.45-.78-1.07-1.45-1.82-1.96L17 4.41 15.59 3l-2.17 2.17C12.96 5.06 12.49 5 12 5c-.49 0-.96.06-1.41.17L8.41 3 7 4.41l1.62 1.63C7.88 6.55 7.26 7.22 6.81 8H4v2h2.09c-.05.33-.09.66-.09 1v1H4v2h2v1c0 .34.04.67.09 1H4v2h2.81c1.04 1.79 2.97 3 5.19 3s4.15-1.21 5.19-3H20v-2h-2.09c.05-.33.09-.66.09-1v-1h2v-2h-2v-1c0-.34-.04-.67-.09-1H20V8z"/></svg></div>
        <h3>Testing &amp; QA</h3>
        <p>Bugs are logged against the exact task and severity, including a dedicated security-bug workflow with CWE classification, and tracked to verified-fixed.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon"><svg viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg></div>
        <h3>Deployment Pipeline</h3>
        <p>Deployment only unlocks once testing is verified; the team lead requests it, admin approves, and the release is logged end to end.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon"><svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg></div>
        <h3>Delivery &amp; Billing</h3>
        <p>Source, docs, and deployment links are handed over with encrypted credentials the client can view exactly once — plus invoices tied to the delivery.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zm-1 9H7v-2h5v2zm3-4H7v-2h7v2z"/></svg></div>
        <h3>Living Documentation</h3>
        <p>Per-project docs are drafted from real project data (and optionally AI-assisted), so documentation never drifts out of sync with what shipped.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon"><svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11zM7 11h5v5H7z"/></svg></div>
        <h3>Attendance &amp; Leave</h3>
        <p>Managers mark attendance manually, import it from a spreadsheet, or feed it automatically from a biometric device — leave requests route to a one-click approval.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon"><svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg></div>
        <h3>Audit &amp; Activity Logs</h3>
        <p>Every login, approval, and schema change is written to a log with IP and geolocation — reviewable by sysadmins, never editable from the app itself.</p>
      </div>
    </div>
  </div>
</section>

<section id="security">
  <div class="wrap">
    <div class="section-head">
      <span class="section-eyebrow">Security Architecture</span>
      <h2>Built to be audited, not just used</h2>
      <p>Every layer below is live in the platform, not a roadmap item. Click a card for how it actually works.</p>
    </div>
    <div class="sec-grid" id="secGrid">
      <div class="sec-card" data-card>
        <div class="sec-icon"><svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg></div>
        <h3>AES-256-GCM Column Encryption</h3>
        <p class="sec-summary">Sensitive fields are encrypted before they ever reach disk.</p>
        <div class="sec-detail">Phone numbers, delivery credentials, and requirement text are encrypted individually with AES-256-GCM — random IV and auth tag per value, master key held outside the web-servable path. A stolen database dump alone can't be read.</div>
        <div class="sec-toggle-hint">Tap to expand</div>
      </div>
      <div class="sec-card" data-card>
        <div class="sec-icon"><svg viewBox="0 0 24 24"><path d="M13 3a9 9 0 00-9 9H1l3.89 3.89.07.14L9 12H6a7 7 0 1113.5 2.5l1.66 1.66A9 9 0 0013 3zm-1 6v5l4.28 2.54.72-1.21-3.5-2.08V9H12z"/></svg></div>
        <h3>Anti-VPN / Proxy Gating</h3>
        <p class="sec-summary">Suspicious IPs are stopped before a password is even checked.</p>
        <div class="sec-detail">Every login resolves the real client IP and checks it against a 24-hour-cached reputation lookup for VPN, proxy, and datacenter-hosting signals — before any credential query runs. A flagged connection never learns whether the account exists.</div>
        <div class="sec-toggle-hint">Tap to expand</div>
      </div>
      <div class="sec-card" data-card>
        <div class="sec-icon"><svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zM12 12c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm0 2c2 0 6 1 6 3v1H6v-1c0-2 4-3 6-3z"/></svg></div>
        <h3>OTP Two-Factor Authentication</h3>
        <p class="sec-summary">A password alone never signs anyone in.</p>
        <div class="sec-detail">Every successful password check sends a time-limited one-time code to the account's verified email before a session is ever created, with rate-limited retries and automatic lockout on repeated failures.</div>
        <div class="sec-toggle-hint">Tap to expand</div>
      </div>
      <div class="sec-card" data-card>
        <div class="sec-icon"><svg viewBox="0 0 24 24"><path d="M20 6h-8l-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2z"/></svg></div>
        <h3>Path-Traversal Immunity</h3>
        <p class="sec-summary">Every file download is resolved and re-verified, not trusted.</p>
        <div class="sec-detail">File downloads resolve to a canonical real path and are rejected with 403 unless that path is strictly inside the authorized uploads directory — closing off crafted paths, symlink tricks, and stale database references alike. Access is re-checked per request against project membership.</div>
        <div class="sec-toggle-hint">Tap to expand</div>
      </div>
      <div class="sec-card" data-card>
        <div class="sec-icon"><svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg></div>
        <h3>Tamper-Resistant Logging</h3>
        <p class="sec-summary">Who did what, from where, is never a guess.</p>
        <div class="sec-detail">Every login, permission change, and schema migration is written to an append-style audit log with IP, geolocation, and encrypted context — reviewable in the sysadmin portal, never editable from the application layer.</div>
        <div class="sec-toggle-hint">Tap to expand</div>
      </div>
      <div class="sec-card" data-card>
        <div class="sec-icon"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg></div>
        <h3>Least-Privilege Access</h3>
        <p class="sec-summary">Every page checks role, project membership, and company boundary.</p>
        <div class="sec-detail">Client, employee, admin, and sysadmin roles are enforced server-side on every request — a client can only see their own company's work, an employee only their assigned projects, and schema-level changes are gated to a single named sysadmin account.</div>
        <div class="sec-toggle-hint">Tap to expand</div>
      </div>
    </div>
  </div>
</section>

<!-- ── CORPORATE HIERARCHY ── -->
<section id="hierarchy" style="background:var(--navy-deep);">
  <div class="wrap">
    <div class="section-head">
      <span class="section-eyebrow">Governance Model</span>
      <h2>A chain of accountability, not a flat inbox</h2>
      <p>Every account sits at exactly one level, and every level has exactly the access its job requires.</p>
    </div>
    <div class="hierarchy-flow">
      <div class="hflow-node">
        <div class="hflow-icon"><svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg></div>
        <h4>IT Manager</h4>
        <p>Owns the company's Astra account. Sets team roles, invites everyone else.</p>
      </div>
      <div class="hflow-arrow"><svg viewBox="0 0 24 24"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg></div>
      <div class="hflow-node">
        <div class="hflow-icon"><svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg></div>
        <h4>Project Manager</h4>
        <p>Submits requirements, tracks delivery, approves milestones on the client's behalf.</p>
      </div>
      <div class="hflow-arrow"><svg viewBox="0 0 24 24"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg></div>
      <div class="hflow-node">
        <div class="hflow-icon"><svg viewBox="0 0 24 24"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm-2 14l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg></div>
        <h4>Teammate</h4>
        <p>Views documentation and delivery status for their own company's projects.</p>
      </div>
      <div class="hflow-arrow"><svg viewBox="0 0 24 24"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg></div>
      <div class="hflow-node">
        <div class="hflow-icon"><svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-8 8c0-2.67 5.33-4 8-4s8 1.33 8 4v1H4v-1z"/></svg></div>
        <h4>Client Company</h4>
        <p>Sees exactly what Astra shows their IT Manager — nothing from any other client, ever.</p>
      </div>
    </div>
  </div>
</section>

<!-- ── TERMS & OPERATIONAL CONDITIONS ── -->
<section id="terms">
  <div class="wrap">
    <div class="section-head">
      <span class="section-eyebrow">Terms & Operational Conditions</span>
      <h2>What using Astra actually commits you to</h2>
      <p>The short version of the agreement every registered company operates under.</p>
    </div>
    <div class="terms-grid">
      <div class="terms-card">
        <div class="terms-num">1</div>
        <h4>Data Ownership</h4>
        <p>Every requirement, file, and message a company submits remains that company's property. Astra stores and encrypts it on their behalf and never repurposes it for another client's engagement.</p>
      </div>
      <div class="terms-card">
        <div class="terms-num">2</div>
        <h4>Audit Trail Compliance</h4>
        <p>Logins, approvals, deployments, and schema changes are recorded automatically. Any company may request its own audit history; no entry can be edited or deleted from the application layer once written.</p>
      </div>
      <div class="terms-card">
        <div class="terms-num">3</div>
        <h4>Milestone Verification</h4>
        <p>A deliverable is only marked complete once the responsible project manager confirms it against the original requirement. Deployment credentials are released to the client only after that sign-off.</p>
      </div>
    </div>
  </div>
</section>

<!-- ── CTA ── -->
<section id="cta" style="padding-top:0;">
  <div class="cta-band">
    <h2>Bring your delivery pipeline somewhere it can be audited.</h2>
    <p>No credit card. Your company gets its own IT Manager account and ID range the moment you register.</p>
    <a href="<?= get_base_url() ?>auth/register" class="btn-hero-primary">Register your company</a>
  </div>
</section>

<footer>
  &copy; <?= date('Y') ?> Astra. Enterprise software delivery, governed end-to-end.
</footer>

<script>
  document.querySelectorAll('#secGrid [data-card]').forEach(function (card) {
    card.addEventListener('click', function () {
      var wasOpen = card.classList.contains('open');
      document.querySelectorAll('#secGrid [data-card]').forEach(function (c) { c.classList.remove('open'); });
      if (!wasOpen) card.classList.add('open');
    });
  });
</script>

</body>
</html>
