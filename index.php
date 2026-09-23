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
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-authkit.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/authkit-ambient.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/authkit-typography.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/feature-showcase.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/resizable-navbar.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/hero-authkit.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<meta name="description" content="Astra: encrypted, audited, governed software delivery for teams that can't afford to guess.">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  /* No grid texture — the canvas is plain obsidian, with the spotlight beam
     (authkit-ambient.css) as the only ambient layer. position:relative is
     what the full-height beam is measured against. */
  body {
    position: relative;
    height: auto;
    min-height: 100vh;
    background-color: var(--navy);
    background-image: none;
    font-family: var(--font-sans);
    color: var(--text);
    transition: var(--transition);
  }

  a { color: inherit; }
  section { padding: 5rem 1.5rem; }
  .wrap { max-width: 1100px; margin: 0 auto; }

  /* Top nav is .astra-resizable-nav (assets/css/resizable-navbar.css), which
     is position:fixed; the hero's own top padding keeps content clear of it. */

  /* ── Hero: see assets/css/hero-authkit.css ── */
  /* Still used by the CTA band at the bottom of the page. */
  .btn-hero-primary {
    font-size: 14px; font-weight: 700; color: #fff; text-decoration: none;
    background: var(--accent); padding: 13px 26px; border-radius: 4px;
    transition: background 0.2s, box-shadow 0.2s;
  }
  .btn-hero-primary:hover { background: var(--accent-dim); box-shadow: 0 0 22px rgba(var(--accent-rgb),0.4); }

  /* ── Section heading ── */
  .section-head { text-align: center; max-width: 640px; margin: 0 auto 3rem; }

  /* Transparent so the full-page spotlight beam shows through every
     section. The canvas colour comes from the fixed .authkit-ambient layer
     (which follows the light/dark tokens); !important beats the inline
     background:var(--navy-deep) on #features and #hierarchy. */
  #features, #security, #hierarchy, #terms {
    background: transparent !important;
  }
  #features .feat-card p, #security .sec-summary,
  #hierarchy .hflow-node p, #terms p:not(.authkit-subtitle) {
    color: var(--color-fog-veil);
  }
  /* h3 card titles inherit body's --text, which is a dark color in light
     theme — invisible against the now-permanently-dark section background
     above without this. */
  #features h3, #security h3, #hierarchy h3, #terms h3 {
    color: var(--color-ice-highlight);
  }
  /* .feat-card/.sec-card/.hflow-node also flip white via --navy-card —
     matching frosted-glass surfaces instead of stray white boxes. */
  #features .feat-card, #security .sec-card, #hierarchy .hflow-node {
    background: var(--surface-frosted-glass) !important;
    border-color: var(--color-glass-edge) !important;
  }
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

  /* ═══ High-contrast text (dark theme) ═══
     Lifts every text tier off the muddy greys (#9da7ba fog-veil, #8b93a3
     --text-dim) so copy reads cleanly over the obsidian canvas and beam.
     Scoped to the dark theme: these near-white values would vanish on the
     light theme's #f8fafc canvas, which keeps its own dark-ink tokens.
     Re-pointing the tokens catches everything built on them (showcase
     labels, chip tags, timestamps, the footer); the explicit rules below set
     the key tiers to their exact values. */
  :root:not([data-theme="light"]) {
    --text-dim: #cbd5e1;
    --color-moon-mist: #e2ecf8;
    --color-fog-veil: #cbd5e1;
  }

  /* Primary descriptions & subheadings */
  :root:not([data-theme="light"]) .authkit-subtitle,
  :root:not([data-theme="light"]) .section-head p,
  :root:not([data-theme="light"]) .showcase-card-desc,
  :root:not([data-theme="light"]) .feat-card p,
  :root:not([data-theme="light"]) .sec-card .sec-summary,
  :root:not([data-theme="light"]) .sec-detail,
  :root:not([data-theme="light"]) .hflow-node p,
  :root:not([data-theme="light"]) .terms-card p,
  :root:not([data-theme="light"]) .cta-band p {
    color: #e2ecf8 !important;
    line-height: 1.6;
    font-weight: 400;
  }
  /* Card titles go to pure white so they stay a step above the now-brighter
     body copy beneath them. */
  :root:not([data-theme="light"]) #features h3,
  :root:not([data-theme="light"]) #security h3,
  :root:not([data-theme="light"]) #hierarchy h4,
  :root:not([data-theme="light"]) #terms h4 {
    color: #ffffff;
  }

  /* Eyebrow labels & section markers */
  :root:not([data-theme="light"]) .authkit-eyebrow-label,
  :root:not([data-theme="light"]) .section-eyebrow {
    color: #d1e4fa !important;
  }
  :root:not([data-theme="light"]) .authkit-eyebrow-line {
    background: linear-gradient(90deg, transparent, rgba(216, 236, 248, 0.35), transparent);
  }

  /* Helper text */
  :root:not([data-theme="light"]) .sec-toggle-hint { opacity: 1; }

  /* Secondary CTA (navbar "Sign In"). The hero's ghost button is styled in
     hero-authkit.css. */
  :root:not([data-theme="light"]) .arn-btn-ghost {
    color: #ffffff !important;
    background: rgba(255, 255, 255, 0.08) !important;
    border: 1px solid rgba(216, 236, 248, 0.3) !important;
  }
  :root:not([data-theme="light"]) .arn-btn-ghost:hover {
    background: rgba(255, 255, 255, 0.14) !important;
  }
</style>
</head>
<body>
<?php render_authkit_ambient(); ?>
<div class="authkit-content">

<nav class="astra-resizable-nav" id="astraNav">
  <div class="arn-inner">
    <a href="<?= get_base_url() ?>" class="arn-brand">
      <div class="arn-brand-icon"><svg viewBox="0 0 48 48"><defs><linearGradient id="astraMark" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="var(--accent-bright)"/><stop offset="1" stop-color="var(--purple, #a78bfa)"/></linearGradient></defs><path fill="url(#astraMark)" d="M24 3 L45 45 H34.4 L24 24.2 L13.6 45 H3 Z"/><path fill="url(#astraMark)" d="M24 14.5 L27.7 22 L35 25.5 L27.7 29 L24 36.5 L20.3 29 L13 25.5 L20.3 22 Z"/></svg></div>
      <span class="arn-brand-title">Astra</span>
    </a>
    <div class="arn-links">
      <a href="#features" class="arn-link">Features</a>
      <a href="#security" class="arn-link">Security</a>
      <a href="#hierarchy" class="arn-link">Governance</a>
    </div>
    <div class="arn-actions">
      <button id="themeToggleBtn" onclick="toggleTheme()" class="arn-theme-toggle">
        <span class="theme-icon"></span>
        <span class="theme-label"></span>
      </button>
      <a href="<?= get_base_url() ?>auth/login" class="arn-btn-ghost">Sign In</a>
      <a href="<?= get_base_url() ?>auth/register" class="arn-btn-primary">Get Started</a>
      <button type="button" class="arn-mobile-toggle" id="arnMobileOpen" aria-label="Open menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
    </div>
  </div>
</nav>

<div class="arn-mobile-drawer" id="arnMobileDrawer">
  <button type="button" class="arn-mobile-close" id="arnMobileClose" aria-label="Close menu">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
  </button>
  <a href="#features" class="arn-link">Features</a>
  <a href="#security" class="arn-link">Security</a>
  <a href="#hierarchy" class="arn-link">Governance</a>
  <div class="arn-mobile-actions">
    <a href="<?= get_base_url() ?>auth/login" class="arn-btn-ghost">Sign In</a>
    <a href="<?= get_base_url() ?>auth/register" class="arn-btn-primary">Get Started</a>
  </div>
</div>

<!-- ── HERO ── -->
<section class="h10" aria-labelledby="h10Title">
  <div class="h10-beam" aria-hidden="true"></div>

  <span class="h10-badge"><span class="h10-badge-dot" aria-hidden="true"></span>Enterprise software delivery, governed end-to-end</span>
  <h1 class="h10-title" id="h10Title">Ship client work with a paper trail that survives an audit.</h1>
  <p class="h10-sub">Astra runs requirements, projects, testing, deployment, billing, and credentials in one workspace. Every record is encrypted at rest, and every change is logged the moment it happens.</p>

  <div class="h10-actions">
    <a href="<?= get_base_url() ?>auth/register" class="h10-btn h10-btn-primary">Register your company
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/></svg></a>
    <a href="<?= get_base_url() ?>auth/login" class="h10-btn h10-btn-ghost">Sign in</a>
  </div>

  <ul class="h10-stats">
    <li><span class="h10-stat-num">AES-256</span><span class="h10-stat-lbl">Column encryption</span></li>
    <li><span class="h10-stat-num">2FA</span><span class="h10-stat-lbl">OTP on every login</span></li>
    <li><span class="h10-stat-num">100%</span><span class="h10-stat-lbl">Actions logged</span></li>
  </ul>

  <!-- Product mockup: illustrative data only, rendered as a static window. -->
  <div class="h10-stage" role="img" aria-label="Astra delivery console showing a project pipeline, a pending dual sign-off, and a live audit log">
    <div class="h10-chrome">
      <span class="h10-chrome-dots" aria-hidden="true"><i></i><i></i><i></i></span>
      <span class="h10-chrome-title"><b>PRJ-1042</b> · Northwind Freight client portal</span>
      <span class="h10-chrome-lock">AES-256-GCM · TLS</span>
    </div>
    <div class="h10-body">
      <div class="h10-pane">
        <p class="h10-pane-label">Delivery pipeline</p>
        <ul class="h10-pipe">
          <li class="done"><span class="st">✓</span>Requirement approved<span class="meta">REQ-2291 · 12 Sep</span></li>
          <li class="done"><span class="st">✓</span>Project kicked off<span class="meta">14 Sep</span></li>
          <li class="active"><span class="st">3</span>Testing: 3 bugs open<span class="meta">In progress</span></li>
          <li><span class="st">4</span>Deployment<span class="meta">Awaiting sign-off</span></li>
        </ul>
        <div class="h10-sign">
          Milestone: Release 1.0 needs both signatures
          <div class="h10-sign-row"><span>Project lead · Priya Nair</span><span class="ok">SIGNED</span></div>
          <div class="h10-sign-row"><span>Client · Northwind Freight</span><span class="wait">PENDING</span></div>
        </div>
      </div>
      <div class="h10-pane">
        <p class="h10-pane-label">Audit log</p>
        <ul class="h10-feed">
          <li><time>14:02:11</time><span class="what">Handover credentials viewed once <small>· 203.0.113.24</small></span><span class="h10-tag enc">Encrypted</span></li>
          <li><time>13:58:40</time><span class="what">BUG-318 closed after retest <small>· QA</small></span><span class="h10-tag">Verified</span></li>
          <li><time>13:51:02</time><span class="what">REQ-2291 v3 acknowledged by PM</span><span class="h10-tag">Signed</span></li>
          <li><time>13:44:17</time><span class="what">Login passed OTP check <small>· Hyderabad</small></span><span class="h10-tag">2FA</span></li>
          <li><time>13:40:05</time><span class="what">Deployment request raised</span><span class="h10-tag">Queued</span></li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- ── SECURITY ARCHITECTURE ── -->
<!-- ── WHAT ASTRA DOES ── -->
<section id="features" style="background:var(--navy-deep);">
  <div class="wrap">
    <?php render_authkit_heading(
        'One platform, the whole delivery lifecycle',
        'From client requirement submission to live deployment, every milestone happens directly in Astra instead of scattered emails, spreadsheets, and chat logs.',
        'What Astra Does'
    ); ?>
    <div class="feat-grid">
      <div class="feat-card">
        <div class="feat-icon"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg></div>
        <h3>Requirements &amp; Approvals</h3>
        <p>Clients submit new requirements or change requests; project managers review, request clarification, or approve them into a project. Every decision is timestamped.</p>
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
        <p>Source, docs, and deployment links are handed over with encrypted credentials the client can view exactly once, plus invoices tied to the delivery.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zm-1 9H7v-2h5v2zm3-4H7v-2h7v2z"/></svg></div>
        <h3>Living Documentation</h3>
        <p>Per-project docs are drafted from real project data (and optionally AI-assisted), so documentation never drifts out of sync with what shipped.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon"><svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11zM7 11h5v5H7z"/></svg></div>
        <h3>Attendance &amp; Leave</h3>
        <p>Managers mark attendance manually, import it from a spreadsheet, or feed it automatically from a biometric device. Leave requests route to one-click approval.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon"><svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg></div>
        <h3>Audit &amp; Activity Logs</h3>
        <p>Every login, approval, and schema change is written to a log with IP and geolocation. Sysadmins can review it, and it is locked against edits from the app itself.</p>
      </div>
    </div>
  </div>
</section>

<!-- ── FEATURE SHOWCASE (4-panel bento grid + cobe globe) ── -->
<section id="showcase">
  <div class="wrap">
    <?php render_authkit_heading(
        'Governance you can see, not just trust',
        'Four pieces of Astra that turn "we have a process" into something an auditor can actually watch happen.',
        'Platform In Motion'
    ); ?>

    <div class="showcase-grid">

      <!-- Card 1 — span 4, top left -->
      <div class="showcase-card span-4">
        <h3 class="showcase-card-title">Deterministic SDLC Issue Governance</h3>
        <p class="showcase-card-desc">Real-time Kanban deliverable tracking and defect audits. Every task and bug moves through one reviewable pipeline.</p>
        <div class="showcase-card-visual">
          <div class="sc-kanban">
            <div class="sc-kanban-col">
              <span class="sc-kanban-col-label">Pending</span>
              <div class="sc-chip">Fix OTP resend cooldown<span class="sc-chip-tag">26T0041</span></div>
              <div class="sc-chip">Client portal empty state<span class="sc-chip-tag">26T0044</span></div>
            </div>
            <div class="sc-kanban-col">
              <span class="sc-kanban-col-label">In Progress</span>
              <div class="sc-chip violet">Audit log IP export<span class="sc-chip-tag">26T0038</span></div>
              <div class="sc-chip">Bug: XSS in comment field<span class="sc-chip-tag">26B0012</span></div>
            </div>
            <div class="sc-kanban-col">
              <span class="sc-kanban-col-label">Verified</span>
              <div class="sc-chip">Deploy pipeline gate<span class="sc-chip-tag">26T0035</span></div>
              <div class="sc-chip">CWE-89 regression test<span class="sc-chip-tag">26B0009</span></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Card 2 — span 2, top right -->
      <div class="showcase-card span-2">
        <h3 class="showcase-card-title">Verified Identity &amp; Biometric Ingestion</h3>
        <p class="showcase-card-desc">Floating employee and device credential cards, verified before attendance or access ever gets recorded.</p>
        <div class="showcase-card-visual">
          <div class="sc-id-stack">
            <div class="sc-id-card c1">
              <div class="sc-id-row">
                <span class="sc-id-avatar"></span>
                <span>
                  <span class="sc-id-name" style="display:block;">Rachel Kim</span>
                  <span class="sc-id-role">Terminal A-204 · Biometric</span>
                </span>
              </div>
              <div class="sc-id-verified">Verified <span class="sc-id-time">2s ago</span></div>
            </div>
            <div class="sc-id-card c2">
              <div class="sc-id-row">
                <span class="sc-id-avatar"></span>
                <span>
                  <span class="sc-id-name" style="display:block;">Marcus Alvarez</span>
                  <span class="sc-id-role">Badge #7731 · Manual entry</span>
                </span>
              </div>
              <div class="sc-id-verified">Verified <span class="sc-id-time">19s ago</span></div>
            </div>
            <div class="sc-id-card c3">
              <div class="sc-id-row">
                <span class="sc-id-avatar"></span>
                <span>
                  <span class="sc-id-name" style="display:block;">Priya Nair</span>
                  <span class="sc-id-role">Terminal B-112 · Biometric</span>
                </span>
              </div>
              <div class="sc-id-verified">Verified <span class="sc-id-time">47s ago</span></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Card 3 — span 3, bottom left -->
      <div class="showcase-card span-3">
        <h3 class="showcase-card-title">Cryptographic Milestone Sign-Off</h3>
        <p class="showcase-card-desc">A milestone reads as complete only after both the project lead and the client cryptographically sign it.</p>
        <div class="showcase-card-visual">
          <div class="sc-signoff">
            <div class="sc-signoff-party">
              <span class="sc-signoff-avatar"><svg viewBox="0 0 24 24"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zm0 2c-3.9 0-9.5 1.96-9.5 5.5V21h19v-1.5c0-3.54-5.6-5.5-9.5-5.5z"/></svg></span>
              <span class="sc-signoff-label">Project Lead</span>
              <span class="sc-signoff-sub">HMAC-signed</span>
            </div>
            <div class="sc-signoff-link"></div>
            <div class="sc-signoff-party">
              <span class="sc-signoff-avatar"><svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg></span>
              <span class="sc-signoff-label">Client</span>
              <span class="sc-signoff-sub">HMAC-signed</span>
            </div>
            <div class="sc-signoff-hash">pm_sig: 7f3a…e91c · client_sig: 2b8d…a04f</div>
          </div>
        </div>
      </div>

      <!-- Card 4 — span 3, bottom right -->
      <div class="showcase-card span-3">
        <h3 class="showcase-card-title">Global Anti-VPN &amp; Geo-Fencing Shield</h3>
        <p class="showcase-card-desc">Every login is checked against live IP reputation before credentials are looked up. Secure gateway nodes are shown below.</p>
        <div class="showcase-card-visual">
          <div class="sc-globe-wrap">
            <canvas id="showcaseGlobe" aria-label="Globe showing Astra's secure gateway regions" role="img"></canvas>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>
<script type="module" src="<?= get_base_url() ?>assets/js/feature-showcase.js?v=<?= ASSET_VERSION ?>"></script>

<section id="security">
  <div class="wrap">
    <?php render_authkit_heading(
        'Built to be audited, not just used',
        'Every layer below is live in the platform, not a roadmap item. Click a card for how it actually works.',
        'Security Architecture'
    ); ?>
    <div class="sec-grid" id="secGrid">
      <div class="sec-card" data-card>
        <div class="sec-icon"><svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg></div>
        <h3>AES-256-GCM Column Encryption</h3>
        <p class="sec-summary">Sensitive fields are encrypted before they ever reach disk.</p>
        <div class="sec-detail">Phone numbers, delivery credentials, and requirement text are encrypted individually with AES-256-GCM, with a random IV and auth tag per value and the master key held outside the web-servable path. A stolen database dump alone can't be read.</div>
        <div class="sec-toggle-hint">Tap to expand</div>
      </div>
      <div class="sec-card" data-card>
        <div class="sec-icon"><svg viewBox="0 0 24 24"><path d="M13 3a9 9 0 00-9 9H1l3.89 3.89.07.14L9 12H6a7 7 0 1113.5 2.5l1.66 1.66A9 9 0 0013 3zm-1 6v5l4.28 2.54.72-1.21-3.5-2.08V9H12z"/></svg></div>
        <h3>Anti-VPN / Proxy Gating</h3>
        <p class="sec-summary">Suspicious IPs are stopped before a password is even checked.</p>
        <div class="sec-detail">Every login resolves the real client IP and checks it against a 24-hour-cached reputation lookup for VPN, proxy, and datacenter-hosting signals before any credential query runs. A flagged connection never learns whether the account exists.</div>
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
        <div class="sec-detail">File downloads resolve to a canonical real path and are rejected with 403 unless that path is strictly inside the authorized uploads directory. This closes off crafted paths, symlink tricks, and stale database references alike. Access is re-checked per request against project membership.</div>
        <div class="sec-toggle-hint">Tap to expand</div>
      </div>
      <div class="sec-card" data-card>
        <div class="sec-icon"><svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg></div>
        <h3>Tamper-Resistant Logging</h3>
        <p class="sec-summary">Who did what, from where, is never a guess.</p>
        <div class="sec-detail">Every login, permission change, and schema migration is written to an append-style audit log with IP, geolocation, and encrypted context. Sysadmins review it in the sysadmin portal, and the application layer has no way to edit it.</div>
        <div class="sec-toggle-hint">Tap to expand</div>
      </div>
      <div class="sec-card" data-card>
        <div class="sec-icon"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg></div>
        <h3>Least-Privilege Access</h3>
        <p class="sec-summary">Every page checks role, project membership, and company boundary.</p>
        <div class="sec-detail">Client, employee, admin, and sysadmin roles are enforced server-side on every request. A client can only see their own company's work, an employee only their assigned projects, and schema-level changes are gated to a single named sysadmin account.</div>
        <div class="sec-toggle-hint">Tap to expand</div>
      </div>
    </div>
  </div>
</section>

<!-- ── CORPORATE HIERARCHY ── -->
<section id="hierarchy" style="background:var(--navy-deep);">
  <div class="wrap">
    <?php render_authkit_heading(
        'A chain of accountability, not a flat inbox',
        'Every account sits at exactly one level, and every level has exactly the access its job requires.',
        'Governance Model'
    ); ?>
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
        <p>Sees exactly what Astra shows their IT Manager, scoped strictly to their own company.</p>
      </div>
    </div>
  </div>
</section>

<!-- ── TERMS & OPERATIONAL CONDITIONS ── -->
<section id="terms">
  <div class="wrap">
    <?php render_authkit_heading(
        'What using Astra actually commits you to',
        'The short version of the agreement every registered company operates under.',
        'Terms & Operational Conditions'
    ); ?>
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
<script src="<?= get_base_url() ?>assets/js/resizable-navbar.js?v=<?= ASSET_VERSION ?>" defer></script>

</div>
</body>
</html>
