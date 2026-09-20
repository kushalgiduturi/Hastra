<?php
// auth/set_password.php
include __DIR__ . '/../core/db.php';
secure_session_start();

$token = $_GET["token"] ?? "";
$msg = "";

if ($token === "") {
    header("Location: " . get_base_url() . "auth/login.php");
    exit();
}

$check = mysqli_prepare($conn, "SELECT *, created_at < NOW() - INTERVAL 24 HOUR AS is_expired FROM password_set_tokens WHERE token = ? AND is_used = 0");
mysqli_stmt_bind_param($check, "s", $token);
mysqli_stmt_execute($check);
$result = mysqli_stmt_get_result($check);
$token_row = mysqli_fetch_assoc($result);

if (!$token_row) {
    $msg = "This link is invalid or has already been used.";
} elseif ($token_row["is_expired"]) {
    $msg = "This link has expired. Ask your admin or IT Manager to send a new one.";
} else {
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        verify_csrf_token();

        $password = $_POST["password"] ?? "";
        $confirm  = $_POST["confirm"] ?? "";

        if (strlen($password) < 8) {
            $msg = "Password must be at least 8 characters.";
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $msg = "Password must contain at least 1 uppercase letter.";
        } elseif (!preg_match('/[a-z]/', $password)) {
            $msg = "Password must contain at least 1 lowercase letter.";
        } elseif (!preg_match('/[0-9]/', $password)) {
            $msg = "Password must contain at least 1 number.";
        } elseif (!preg_match('/[^a-zA-Z0-9]/', $password)) {
            $msg = "Password must contain at least 1 special character.";
        } elseif ($password !== $confirm) {
            $msg = "Passwords do not match.";
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $update = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?");
            mysqli_stmt_bind_param($update, "si", $hashed, $token_row["user_id"]);
            mysqli_stmt_execute($update);

            $mark_used = mysqli_prepare($conn, "UPDATE password_set_tokens SET is_used = 1 WHERE id = ?");
            mysqli_stmt_bind_param($mark_used, "i", $token_row["id"]);
            mysqli_stmt_execute($mark_used);

            $who = mysqli_prepare($conn, "SELECT role FROM users WHERE id = ?");
            mysqli_stmt_bind_param($who, "i", $token_row["user_id"]);
            mysqli_stmt_execute($who);
            $who_role = mysqli_fetch_assoc(mysqli_stmt_get_result($who))["role"] ?? "";
            $msg = $who_role === "client"
                ? "success"
                : "success_pending";
        }
    }
}

$is_success = ($msg === "success" || $msg === "success_pending");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Set Your Password · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    min-height: 100vh;
    background-color: var(--navy);
    background-image:
      linear-gradient(var(--grid-line) 1px, transparent 1px),
      linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
    background-size: 40px 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Inter', sans-serif;
    padding: 1.5rem;
  }

  .card {
    position: relative;
    z-index: 1;
    background: var(--navy-card);
    border: 1px solid var(--border);
    border-radius: 4px;
    width: 100%;
    max-width: 400px;
    padding: 2.5rem 2.5rem 2rem;
    box-shadow: 0 0 0 1px rgba(var(--accent-rgb),0.08),
                0 20px 60px rgba(0,0,0,0.5),
                0 0 40px var(--accent-glow);
  }

  .card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--green), transparent);
    border-radius: 4px 4px 0 0;
  }

  .brand {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 2rem;
  }

  .brand-icon {
    width: 36px;
    height: 36px;
    background: var(--accent);
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .brand-icon svg { width: 18px; height: 18px; fill: white; }

  .brand-text .title {
    font-family: 'Share Tech Mono', monospace;
    font-size: 15px;
    color: var(--text);
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }

  .brand-text .sub {
    font-size: 10px;
    color: var(--text-dim);
    letter-spacing: 0.12em;
    text-transform: uppercase;
    margin-top: 2px;
  }

  h2 {
    font-size: 22px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 0.3rem;
    letter-spacing: -0.01em;
  }

  .subtitle {
    font-size: 13px;
    color: var(--text-dim);
    margin-bottom: 1.8rem;
    line-height: 1.5;
  }

  .divider {
    height: 1px;
    background: var(--border-dim);
    margin-bottom: 1.8rem;
  }

  .field { margin-bottom: 1.2rem; }

  label {
    display: block;
    font-size: 11px;
    font-weight: 500;
    color: var(--text-dim);
    letter-spacing: 0.07em;
    text-transform: uppercase;
    margin-bottom: 6px;
  }

  .input-wrap { position: relative; }

  input[type="password"],
  input[type="text"] {
    width: 100%;
    background: var(--input-bg);
    border: 1px solid var(--border-dim);
    border-radius: 3px;
    color: var(--text);
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    padding: 10px 40px 10px 14px;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
  }

  input[type="password"]:focus,
  input[type="text"]:focus {
    border-color: var(--accent-bright);
    box-shadow: 0 0 0 3px rgba(var(--accent-rgb),0.12);
  }

  input::placeholder { color: var(--text-dim); }

  .eye-btn {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-dim);
    padding: 4px;
    display: flex;
    align-items: center;
    transition: color 0.2s;
  }

  .eye-btn:hover { color: var(--accent-bright); }
  .eye-btn svg { width: 16px; height: 16px; }

  .req-box {
    background: var(--section-header-bg);
    border: 1px solid var(--border-dim);
    border-radius: 3px;
    padding: 10px 12px;
    margin-bottom: 1.2rem;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 5px 12px;
  }

  .req-item {
    font-size: 11px;
    color: var(--text-dim);
    display: flex;
    align-items: center;
    gap: 5px;
    transition: color 0.2s;
  }

  .req-item .dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #334155;
    flex-shrink: 0;
    transition: background 0.2s;
  }

  .req-item.valid { color: var(--green); }
  .req-item.valid .dot { background: var(--green); box-shadow: 0 0 4px var(--green); }
  .req-item.invalid { color: var(--red); }
  .req-item.invalid .dot { background: var(--red); }

  .match-msg {
    font-size: 12px;
    margin-top: 5px;
    height: 16px;
  }

  .match-msg.ok  { color: var(--green); }
  .match-msg.err { color: var(--red); }

  .alert {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    border-radius: 3px;
    padding: 10px 12px;
    margin-bottom: 1.2rem;
    font-size: 13px;
    line-height: 1.4;
    border-left-width: 3px;
    border-left-style: solid;
  }

  .alert.error {
    background: var(--red-bg);
    border-color: var(--red);
    color: #fca5a5;
  }

  .alert svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; fill: var(--red); }

  .btn-submit {
    width: 100%;
    background: var(--accent);
    color: white;
    border: none;
    border-radius: 3px;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 600;
    letter-spacing: 0.04em;
    padding: 11px;
    cursor: pointer;
    transition: background 0.2s, box-shadow 0.2s;
    text-transform: uppercase;
    margin-top: 0.4rem;
  }

  .btn-submit:hover {
    background: #2563eb;
    box-shadow: 0 0 20px rgba(var(--accent-rgb),0.3);
  }

  .footer-links {
    margin-top: 1.4rem;
    padding-top: 1.2rem;
    border-top: 1px solid var(--border-dim);
    display: flex;
    justify-content: center;
    gap: 6px;
    font-size: 13px;
    color: var(--text-dim);
  }

  .footer-links a {
    color: var(--accent-bright);
    text-decoration: none;
    transition: color 0.2s;
  }

  .footer-links a:hover { color: var(--accent-bright); }

  .btn-theme-toggle {
    display: flex; align-items: center; gap: 6px;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.07);
    color: #94a3b8;
    font-family: 'Inter', sans-serif;
    font-size: 12px; font-weight: 500;
    letter-spacing: 0.04em; text-transform: uppercase;
    padding: 6px 12px; border-radius: 3px;
    cursor: pointer;
    transition: background 0.3s, color 0.3s, border-color 0.3s;
  }
  .btn-theme-toggle:hover {
    background: rgba(var(--accent-rgb),0.08);
    color: #f1f5f9;
  }
  .btn-theme-toggle .theme-icon svg {
    width: 13px; height: 13px;
    vertical-align: middle;
    fill: currentColor;
  }

  .success-wrap {
    text-align: center;
    padding: 1rem 0;
  }

  .success-icon {
    width: 56px;
    height: 56px;
    background: var(--green-bg);
    border: 1px solid rgba(34,197,94,0.25);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.2rem;
  }

  .success-icon svg { width: 28px; height: 28px; fill: var(--green); }

  .success-wrap h3 {
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 0.4rem;
  }

  .success-wrap p {
    font-size: 13px;
    color: var(--text-dim);
    margin-bottom: 1.6rem;
    line-height: 1.5;
  }

  .btn-login {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--accent);
    color: white;
    border: none;
    border-radius: 3px;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 600;
    letter-spacing: 0.04em;
    padding: 10px 24px;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.2s, box-shadow 0.2s;
    text-transform: uppercase;
  }

  .btn-login:hover {
    background: #2563eb;
    box-shadow: 0 0 20px rgba(var(--accent-rgb),0.3);
  }

  .btn-login svg { width: 14px; height: 14px; fill: white; }

  /* ── Intro: ambient glow fades in, palm-point pulses, then the card
     emerges from that same point (scale + blur-to-sharp + fade in) ── */
  .reveal-wrap { position: fixed; top: 34%; left: 50%; width: 620px; height: 620px;
    margin: -310px 0 0 -310px; pointer-events: none; z-index: 0; }

  .glow { position: absolute; inset: 0; border-radius: 50%;
    background: radial-gradient(ellipse, var(--accent-glow) 0%, transparent 68%);
    opacity: 0; filter: blur(20px);
    animation: glowIn 1.3s .3s cubic-bezier(.16,1,.3,1) forwards,
               glowBreathe 3.2s 1.8s ease-in-out infinite alternate;
    transition: background .5s ease; }
  @keyframes glowIn { to { opacity: .55; } }
  @keyframes glowBreathe { from { opacity: .4; transform: scale(1); } to { opacity: .6; transform: scale(1.045); } }

  .pulse { position: absolute; left: 50%; top: 50%; width: 10px; height: 10px; margin: -5px 0 0 -5px;
    border-radius: 50%; border: 1.5px solid var(--accent-bright); opacity: 0; pointer-events: none;
    animation: pulseOut 1.25s cubic-bezier(.2,.7,.2,1) forwards; transition: border-color .4s ease; }
  .pulse.p1 { animation-delay: 1.4s; }
  .pulse.p2 { animation-delay: 1.68s; }
  @keyframes pulseOut {
    0%   { width: 10px; height: 10px; margin: -5px 0 0 -5px; opacity: .8; }
    100% { width: 300px; height: 300px; margin: -150px 0 0 -150px; opacity: 0; }
  }

  .card {
    position: relative; z-index: 1;
    opacity: 0; transform: translateY(4px) scale(.34); filter: blur(13px);
    animation: cardEmerge 1s 1.9s cubic-bezier(.16,1,.3,1) forwards;
  }
  @keyframes cardEmerge { to { opacity: 1; transform: translateY(0) scale(1); filter: blur(0); } }

  @media (prefers-reduced-motion: reduce) {
    .pulse { display: none; }
    .glow { opacity: .5; animation: none; }
    .card { opacity: 1; transform: none; filter: none; animation: none; }
  }

  /* ═══ VISUAL REFRESH — match reference mockup: glass card, Sora type,
     bare icon, gradient sentence-case button, no grid/box chrome ═══ */
  .card {
    background: rgba(20,10,10,.55) !important;
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border: 1px solid rgba(var(--accent-rgb),.28) !important;
    border-radius: 12px !important;
    box-shadow: 0 30px 80px -20px rgba(0,0,0,.55), 0 0 40px var(--accent-glow) !important;
  }
  [data-theme="light"] .card { background: rgba(255,255,255,.72) !important; }
  .card::before { display: none !important; }

  .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 1.6rem !important; }
  .brand-icon {
    width: 26px !important; height: 26px !important;
    background: none !important; border-radius: 0 !important;
  }
  .brand-icon svg { width: 26px !important; height: 26px !important; fill: var(--accent) !important; }
  .brand-text { display: flex; align-items: center; }
  .brand-text .title {
    font-family: 'Sora', sans-serif !important;
    font-size: 21px !important; font-weight: 700 !important;
    letter-spacing: .01em !important; text-transform: none !important;
    color: var(--text) !important;
  }
  .brand-text .sub { display: none !important; }

  .card h1, .card h2 {
    font-family: 'Sora', sans-serif !important;
    font-size: 19px !important; font-weight: 600 !important;
    letter-spacing: .005em !important; margin: 0 0 4px !important;
  }
  .card .subtitle, .card p.sub {
    font-size: 13.5px !important; margin: 0 0 1.8rem !important;
  }

  .card label {
    font-size: 11px !important; letter-spacing: .07em !important; font-weight: 500 !important;
  }

  .card input[type="email"], .card input[type="password"], .card input[type="text"],
  .card input[type="tel"], .card select, .card textarea {
    background: rgba(127,127,127,.06) !important;
    border-radius: 7px !important;
    padding: 11px 13px !important;
  }

  .card button[type="submit"], .card .btn-login, .card .btn-send,
  .card .btn-submit, .card .btn-primary, .card a.btn-primary {
    background: linear-gradient(135deg, var(--accent-bright), var(--accent)) !important;
    border-radius: 7px !important;
    font-family: 'Sora', sans-serif !important;
    font-weight: 600 !important;
    letter-spacing: .02em !important;
    text-transform: none !important;
    box-shadow: 0 8px 24px -8px var(--accent-glow) !important;
    transition: transform .15s, box-shadow .15s !important;
  }
  .card button[type="submit"]:hover, .card .btn-login:hover, .card .btn-send:hover,
  .card .btn-submit:hover, .card .btn-primary:hover, .card a.btn-primary:hover {
    transform: translateY(-1px);
  }

  .card .footer-links a, .card .forgot-link { color: var(--accent-bright) !important; }
</style>
</head>
<body>

<div class="reveal-wrap">
  <div class="glow"></div>
  <div class="pulse p1"></div>
  <div class="pulse p2"></div>
</div>

<div class="card">

  <div class="brand">
    <div class="brand-icon">
      <svg viewBox="0 0 48 48"><defs><linearGradient id="astraMark" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="var(--accent-bright)"/><stop offset="1" stop-color="var(--purple, #a78bfa)"/></linearGradient></defs><path fill="url(#astraMark)" d="M24 3 L45 45 H34.4 L24 24.2 L13.6 45 H3 Z"/><path fill="url(#astraMark)" d="M24 14.5 L27.7 22 L35 25.5 L27.7 29 L24 36.5 L20.3 29 L13 25.5 L20.3 22 Z"/></svg>
    </div>
    <div class="brand-text">
      <div class="title">Astra</div>
      <div class="sub">Account Activation</div>
    </div>
  </div>

  <?php if ($is_success): ?>

  <div class="success-wrap">
    <div class="success-icon">
      <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
    </div>
    <h3>Password Set!</h3>
    <p>
      <?php if ($msg === 'success'): ?>
        Your account is active. You can sign in now.
      <?php else: ?>
        Your password has been saved. Your account is pending sysadmin approval — you'll be able to sign in once it's approved.
      <?php endif; ?>
    </p>
    <a href="login.php" class="btn-login">
      <svg viewBox="0 0 24 24"><path d="M11 7L9.6 8.4l2.6 2.6H2v2h10.2l-2.6 2.6L11 17l5-5-5-5z"/></svg>
      Go to Login
    </a>
  </div>

  <?php else: ?>

  <h2>Set Your Password</h2>
  <p class="subtitle">Choose a password to activate your Astra account.</p>
  <div class="divider"></div>

  <?php if ($msg): ?>
  <div class="alert error">
    <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
    <?= htmlspecialchars($msg) ?>
  </div>
  <?php endif; ?>

  <?php if ($token_row): ?>
  <form method="POST" id="setPasswordForm">
    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

    <div class="field">
      <label for="password">New Password</label>
      <div class="input-wrap">
        <input type="password" name="password" id="password"
               maxlength="128" required placeholder="••••••••••••"
               oninput="checkPassword(this.value)">
        <button type="button" class="eye-btn" onclick="toggleEye('password','eye1')">
          <svg id="eye1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
            <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
            <line x1="1" y1="1" x2="23" y2="23"/>
          </svg>
        </button>
      </div>
    </div>

    <div class="req-box">
      <div class="req-item" id="req_length"> <span class="dot"></span> 8+ characters </div>
      <div class="req-item" id="req_upper">  <span class="dot"></span> Uppercase (A-Z) </div>
      <div class="req-item" id="req_lower">  <span class="dot"></span> Lowercase (a-z) </div>
      <div class="req-item" id="req_special"><span class="dot"></span> Special character </div>
    </div>

    <div class="field">
      <label for="confirm">Confirm Password</label>
      <div class="input-wrap">
        <input type="password" name="confirm" id="confirm"
               maxlength="128" required placeholder="••••••••••••"
               oninput="checkMatch()">
        <button type="button" class="eye-btn" onclick="toggleEye('confirm','eye2')">
          <svg id="eye2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
            <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
            <line x1="1" y1="1" x2="23" y2="23"/>
          </svg>
        </button>
      </div>
      <div class="match-msg" id="matchMsg"></div>
    </div>

    <button type="submit" class="btn-submit">Set Password</button>
  </form>
  <?php endif; ?>

  <div style="display:flex; align-items:center; justify-content:center; margin-top:1rem; padding-top:0.8rem; border-top:1px solid rgba(255,255,255,0.07);">
    <button id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle">
      <span class="theme-icon"></span>
      <span class="theme-label"></span>
    </button>
  </div>
  <div class="footer-links"><a href="login.php">Back to login</a></div>

  <?php endif; ?>

</div>

<script>
const eyeOpenSVG   = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" fill="none"/>`;
const eyeClosedSVG = `<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/><line x1="1" y1="1" x2="23" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>`;

function toggleEye(inputId, iconId) {
  const input = document.getElementById(inputId);
  const icon  = document.getElementById(iconId);
  const show  = input.type === 'password';
  input.type     = show ? 'text' : 'password';
  icon.innerHTML = show ? eyeOpenSVG : eyeClosedSVG;
}

function setReq(id, passed) {
  const el = document.getElementById(id);
  if (!el) return;
  el.className = 'req-item ' + (passed ? 'valid' : 'invalid');
}

function checkPassword(value) {
  setReq('req_length',  value.length >= 8);
  setReq('req_upper',   /[A-Z]/.test(value));
  setReq('req_lower',   /[a-z]/.test(value));
  setReq('req_special', /[^a-zA-Z0-9]/.test(value));
  checkMatch();
}

function checkMatch() {
  const passwordEl = document.getElementById('password');
  const confirmEl  = document.getElementById('confirm');
  if (!passwordEl || !confirmEl) return;
  const password = passwordEl.value;
  const confirm  = confirmEl.value;
  const msg      = document.getElementById('matchMsg');
  if (!confirm.length) { msg.textContent = ''; msg.className = 'match-msg'; return; }
  if (password === confirm) {
    msg.className   = 'match-msg ok';
    msg.textContent = '✓ Passwords match';
  } else {
    msg.className   = 'match-msg err';
    msg.textContent = '✗ Passwords do not match';
  }
}
</script>

</body>
</html>
