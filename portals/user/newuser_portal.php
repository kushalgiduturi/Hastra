<?php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, ["client", "pending_employee"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
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

  /* ── TOPNAV ── */
  .topnav {
    position: sticky;
    top: 0;
    z-index: 100;
    background: var(--topnav-bg);
    border-bottom: 1px solid var(--border);
    padding: 0 2rem;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .nav-left { display: flex; align-items: center; gap: 12px; }

  .nav-icon {
    width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
  }

  .nav-icon svg { width: 16px; height: 16px; fill: white; }

  .nav-title {
    font-family: 'Share Tech Mono', monospace;
    font-size: 14px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--text);
  }

  .nav-divider { width: 1px; height: 20px; background: var(--border-dim); }

  .nav-badge {
    text-decoration: none; cursor: pointer; display: inline-block;
    font-size: 11px;
    font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-dim);
    background: rgba(148,163,184,0.08);
    border: 1px solid rgba(148,163,184,0.15);
    padding: 2px 8px;
    border-radius: 2px;
  }

  .nav-right { display: flex; align-items: center; gap: 16px; }

  .nav-user { font-size: 13px; color: var(--text-dim); }
  .nav-user span { color: var(--text); font-weight: 500; }

  .btn-logout {
    display: flex; align-items: center; gap: 6px;
    background: rgba(239,68,68,0.1);
    border: 1px solid rgba(239,68,68,0.25);
    color: #fca5a5;
    font-family: var(--font-sans);
    font-size: 12px; font-weight: 500;
    letter-spacing: 0.05em; text-transform: uppercase;
    padding: 6px 12px; border-radius: 3px;
    text-decoration: none;
    transition: background 0.2s, border-color 0.2s;
  }

  .btn-logout:hover { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.5); }
  .btn-logout svg { width: 13px; height: 13px; fill: #fca5a5; }

  .btn-theme-toggle {
    display: flex; align-items: center; gap: 6px;
    background: var(--input-bg);
    border: 1px solid var(--border-dim);
    color: var(--text-dim);
    font-family: var(--font-sans);
    font-size: 12px; font-weight: 500;
    letter-spacing: 0.04em; text-transform: uppercase;
    padding: 6px 12px; border-radius: 3px;
    cursor: pointer;
    transition: var(--transition);
  }
  .btn-theme-toggle:hover {
    background: var(--hover-bg);
    color: var(--text);
    border-color: var(--border);
  }
  .btn-theme-toggle .theme-icon svg {
    width: 13px; height: 13px;
    vertical-align: middle;
    fill: currentColor;
  }
  .btn-profile {
    display: flex;
    align-items: center;
    gap: 7px;
    background: rgba(var(--accent-rgb),0.08);
    border: 1px solid rgba(var(--accent-rgb),0.25);
    color: var(--accent-bright);
    font-family: var(--font-sans);
    font-size: 12px;
    font-weight: 500;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    padding: 6px 13px;
    border-radius: 3px;
    text-decoration: none;
    transition: background 0.2s, border-color 0.2s, color 0.2s;
  }
  .btn-profile:hover {
    background: rgba(var(--accent-rgb),0.18);
    border-color: rgba(var(--accent-rgb),0.5);
    color: var(--accent-bright);
  }
  .btn-profile svg { width: 14px; height: 14px; fill: currentColor; flex-shrink: 0; }
  .btn-profile .avatar-dot {
    width: 18px; height: 18px; border-radius: 50%;
    background: rgba(var(--accent-rgb),0.25);
    border: 1px solid rgba(var(--accent-rgb),0.4);
    display: flex; align-items: center; justify-content: center;
    font-size: 9px; font-weight: 700; color: var(--accent-bright);
    font-family: 'Share Tech Mono', monospace; flex-shrink: 0;
  }

  /* ── MAIN ── */
  .main {
    max-width: 640px;
    margin: 0 auto;
    padding: 3rem 2rem;
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  /* ── WELCOME CARD ── */
  .welcome-card {
    width: 100%;
    background: var(--navy-card);
    border: 1px solid var(--border-dim);
    border-radius: 4px;
    overflow: hidden;
    position: relative;
  }

  .welcome-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--accent-bright), transparent);
  }

  .card-top {
    padding: 2.5rem 2rem 2rem;
    text-align: center;
    border-bottom: 1px solid var(--border-dim);
  }

  /* Avatar circle */
  .avatar {
    width: 72px; height: 72px;
    border-radius: 50%;
    background: rgba(var(--accent-rgb),0.12);
    border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.4rem;
  }

  .avatar svg { width: 32px; height: 32px; fill: var(--accent-bright); }

  .card-top h2 {
    font-size: 22px; font-weight: 600;
    color: var(--text);
    letter-spacing: -0.01em;
    margin-bottom: 0.4rem;
  }

  .card-top p {
    font-size: 13px;
    color: var(--text-dim);
    line-height: 1.6;
  }

  /* ── STATUS STRIP ── */
  .status-strip {
    padding: 1.2rem 1.6rem;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid var(--border-dim);
    background: rgba(245,158,11,0.04);
  }

  .status-icon {
    width: 36px; height: 36px; flex-shrink: 0;
    background: rgba(245,158,11,0.1);
    border: 1px solid rgba(245,158,11,0.2);
    border-radius: 3px;
    display: flex; align-items: center; justify-content: center;
  }

  .status-icon svg { width: 18px; height: 18px; fill: var(--yellow); }

  .status-text { flex: 1; }

  .status-text strong {
    display: block;
    font-size: 13px; font-weight: 600;
    color: var(--text);
    margin-bottom: 2px;
  }

  .status-text span {
    font-size: 12px;
    color: var(--text-dim);
  }

  .status-badge {
    font-size: 10px;
    font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--yellow);
    background: rgba(245,158,11,0.1);
    border: 1px solid rgba(245,158,11,0.2);
    padding: 3px 8px;
    border-radius: 2px;
    white-space: nowrap;
  }

  /* ── INFO ROWS ── */
  .info-rows {
    padding: 0.6rem 0;
  }

  .info-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1.6rem;
    border-bottom: 1px solid rgba(255,255,255,0.03);
    font-size: 13px;
  }

  .info-row:last-child { border-bottom: none; }

  .info-label {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--text-dim);
    font-size: 12px;
    font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.06em;
    text-transform: uppercase;
  }

  .info-label svg { width: 14px; height: 14px; fill: var(--text-dim); }

  .info-value {
    color: var(--text);
    font-family: 'Share Tech Mono', monospace;
    font-size: 13px;
  }

  /* ── STEPS BOX ── */
  .steps-box {
    margin: 1.5rem 2rem;
    background: var(--section-header-bg);
    border: 1px solid var(--border-dim);
    border-radius: 3px;
    padding: 1.2rem 1.4rem;
  }

  .steps-title {
    font-size: 11px;
    font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-dim);
    margin-bottom: 1rem;
  }

  .step {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 0.9rem;
  }

  .step:last-child { margin-bottom: 0; }

  .step-num {
    width: 20px; height: 20px; flex-shrink: 0;
    border-radius: 50%;
    background: rgba(var(--accent-rgb),0.12);
    border: 1px solid var(--border);
    color: var(--accent-bright);
    font-size: 10px;
    font-family: 'Share Tech Mono', monospace;
    display: flex; align-items: center; justify-content: center;
    margin-top: 1px;
  }

  .step-num.done {
    background: rgba(34,197,94,0.12);
    border-color: rgba(34,197,94,0.25);
    color: var(--green);
  }

  .step-body { flex: 1; }

  .step-body strong {
    display: block;
    font-size: 13px; font-weight: 500;
    color: var(--text);
    margin-bottom: 2px;
  }

  .step-body span {
    font-size: 12px;
    color: var(--text-dim);
    line-height: 1.5;
  }

  /* ── SYS STATUS ── */
  .sys-status {
    font-size: 11px;
    color: var(--text-dim);
    text-align: center;
    margin-top: 1.6rem;
    font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.04em;
  }

  .status-dot {
    display: inline-block;
    width: 6px; height: 6px;
    background: var(--green);
    border-radius: 50%;
    margin-right: 5px;
    box-shadow: 0 0 6px var(--green);
    animation: pulse 2s infinite;
  }

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.4; }
  }
</style>
</head>
<body>

<?php render_profile_barrier($conn); ?>

<!-- TOPNAV -->
<nav class="topnav">
  <div class="nav-left">
    <a href="<?= get_base_url() ?>portals/index" class="nav-brand-link" title="All pages for your role">
    <div class="nav-icon">
      <svg viewBox="0 0 48 48"><defs><linearGradient id="astraMark" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="var(--accent-bright)"/><stop offset="1" stop-color="var(--purple, #a78bfa)"/></linearGradient></defs><path fill="url(#astraMark)" d="M24 3 L45 45 H34.4 L24 24.2 L13.6 45 H3 Z"/><path fill="url(#astraMark)" d="M24 14.5 L27.7 22 L35 25.5 L27.7 29 L24 36.5 L20.3 29 L13 25.5 L20.3 22 Z"/></svg>
    </div>
    <span class="nav-title">Astra</span>
    </a>
    <div class="nav-divider"></div>
    <a class="nav-badge" href="<?= get_base_url() ?>portals/user/newuser_portal">New User</a>
  </div>
  <div class="nav-right">
    <span class="nav-user">Signed in as <span><?= htmlspecialchars($_SESSION["user_name"]) ?></span></span>
    <button id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle">
      <span class="theme-icon"></span>
      <span class="theme-label"></span>
    </button>
    <a href="<?= get_base_url() ?>auth/logout" class="btn-logout">
      <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
      Logout
    </a>
  </div>
</nav>

<!-- MAIN -->
<div class="main">

  <div class="welcome-card">

    <!-- Top section -->
    <div class="card-top">
      <div class="avatar">
        <svg viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
      </div>
      <h2>Welcome, <?= htmlspecialchars($_SESSION["user_name"]) ?>!</h2>
      <p>Your account has been created successfully.<br>An admin will assign your role shortly.</p>
    </div>

    <!-- Pending status -->
    <div class="status-strip">
      <div class="status-icon">
        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/></svg>
      </div>
      <div class="status-text">
        <strong>Role Assignment Pending</strong>
        <span>Your account is active and awaiting role assignment</span>
      </div>
      <span class="status-badge">Pending</span>
    </div>

    <!-- Account details -->
    <div class="info-rows">
      <div class="info-row">
        <span class="info-label">
          <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
          User ID
        </span>
        <span class="info-value">#<?= htmlspecialchars($_SESSION["user_id"]) ?></span>
      </div>
      <div class="info-row">
        <span class="info-label">
          <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
          Account Status
        </span>
        <span class="info-value" style="color: var(--green);">Active</span>
      </div>
      <div class="info-row">
        <span class="info-label">
          <svg viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
          Current Role
        </span>
        <span class="info-value">New User</span>
      </div>
    </div>

    <!-- What's next steps -->
    <div class="steps-box">
      <div class="steps-title">What happens next</div>
      <div class="step">
        <div class="step-num done">✓</div>
        <div class="step-body">
          <strong>Account Created</strong>
          <span>Your account is verified and active.</span>
        </div>
      </div>
      <div class="step">
        <div class="step-num">2</div>
        <div class="step-body">
          <strong>Role Assignment</strong>
          <span>An admin or sysadmin will assign your role based on your access needs.</span>
        </div>
      </div>
      <div class="step">
        <div class="step-num">3</div>
        <div class="step-body">
          <strong>Access Granted</strong>
          <span>Once your role is set, you'll have access to your portal on next login.</span>
        </div>
      </div>
    </div>

  </div>

  <div class="sys-status">
    <span class="status-dot"></span>All systems operational
  </div>

</div>

</body>
</html>