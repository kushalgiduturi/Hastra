<?php
include __DIR__ . '/../core/db.php';
secure_session_start();
$msg = "";

if (!isset($_SESSION["otp_email"])) {
    header("Location: login");
    exit();
}

if (!isset($_SESSION["otp_attempts"])) {
    $_SESSION["otp_attempts"] = 0;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    verify_csrf_token();

    $_SESSION["otp_attempts"]++;
    if ($_SESSION["otp_attempts"] > 5) {
        session_unset();
        session_destroy();
        header("Location: login");
        exit();
    }

    $entered_otp  = trim($_POST["otp"]);
    $email        = $_SESSION["otp_email"];
    $email_bindex = astra_blind_index($email);

    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email_bindex = ? AND otp = ? AND otp_expiry > NOW()");
    mysqli_stmt_bind_param($stmt, "ss", $email_bindex, $entered_otp);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user   = mysqli_fetch_assoc($result);
    astra_decrypt_user_row($user);

    if ($user) {
        $clear = mysqli_prepare($conn, "UPDATE users SET otp = NULL, otp_expiry = NULL WHERE email_bindex = ?");
        mysqli_stmt_bind_param($clear, "s", $email_bindex);
        mysqli_stmt_execute($clear);

        $_SESSION["user_id"]   = $user["id"];
        $_SESSION["user_name"] = $user["name"];
        $_SESSION["user_role"] = $user["role"];
        unset($_SESSION["otp_email"]);
        unset($_SESSION["otp_attempts"]);

        session_regenerate_id(true);
        log_activity($conn, $user["id"], "login_success",$user["name"]);

        // Accurate geo for this session — astra_inspect_ip() reads from
        // ip_cache (populated moments ago by the anti-VPN gate in login.php)
        // so this is normally an instant cache hit, not a second network call.
        $login_ip = astra_get_client_ip();
        $login_geo = astra_inspect_ip($login_ip, $conn);
        $_SESSION["login_ip"]      = $login_ip;
        $_SESSION["login_city"]    = $login_geo["city"];
        $_SESSION["login_country"] = $login_geo["country"];

        switch ($user["role"]) {
          case "sysadmin":         header("Location: " . get_base_url() . "portals/sysadmin/sysadmin_portal");  break;
          case "admin":            header("Location: " . get_base_url() . "portals/admin/admin_portal");        break;
          case "employee":         header("Location: " . get_base_url() . "portals/emlpoyee/employee_portal");  break;
          case "pending_employee": header("Location: " . get_base_url() . "portals/user/newuser_portal");       break;
          case "client": header("Location: " . get_base_url() . "portals/client/client_portal"); break;
          default:                 header("Location: " . get_base_url() . "portals/user/newuser_portal");       break;
        }
        exit();
    } else {
        $remaining = 5 - $_SESSION["otp_attempts"];
        $msg = "Invalid OTP. $remaining attempt(s) remaining.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Two-Step Verification · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-authkit.css?v=<?= ASSET_VERSION ?>">
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
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--font-sans);
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
    box-shadow: 0 0 0 1px rgba(var(--accent-rgb),0.08), 0 20px 60px rgba(0,0,0,0.5), 0 0 40px var(--accent-glow);
  }

  .card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--accent-bright), transparent);
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
    flex-shrink: 0;
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

  .subtitle span {
    color: var(--accent-bright);
    font-family: 'Share Tech Mono', monospace;
    font-size: 12px;
  }

  .divider {
    height: 1px;
    background: var(--border-dim);
    margin-bottom: 1.8rem;
  }

  /* OTP input boxes */
  .otp-wrap {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin-bottom: 1.6rem;
  }

  .otp-box {
    width: 52px;
    height: 58px;
    background: var(--input-bg);
    border: 1px solid var(--border-dim);
    border-radius: 4px;
    color: var(--text);
    font-family: 'Share Tech Mono', monospace;
    font-size: 24px;
    text-align: center;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
    caret-color: var(--accent-bright);
  }

  .otp-box:focus {
    border-color: var(--accent-bright);
    box-shadow: 0 0 0 3px rgba(var(--accent-rgb),0.12);
  }

  .otp-box.filled {
    border-color: rgba(var(--accent-rgb),0.5);
    background: rgba(var(--accent-rgb),0.06);
  }

  /* Hidden real input for form submit */
  #otpHidden { display: none; }

  .alert {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    border-radius: 3px;
    padding: 10px 12px;
    margin-bottom: 1.4rem;
    font-size: 13px;
    line-height: 1.4;
  }

  .alert.error {
    background: var(--red-bg);
    border: 1px solid rgba(var(--red-rgb),0.3);
    border-left: 3px solid var(--red);
    color: #fca5a5;
  }

  .alert svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; fill: var(--red); }

  /* Attempt counter */
  .attempts-bar {
    display: flex;
    gap: 6px;
    justify-content: center;
    margin-bottom: 1.4rem;
  }

  .attempt-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: rgba(255,255,255,0.1);
    transition: background 0.3s;
  }

  .attempt-dot.used { background: var(--red); box-shadow: 0 0 5px var(--red); }

  .attempts-label {
    text-align: center;
    font-size: 11px;
    color: var(--text-dim);
    font-family: 'Share Tech Mono', monospace;
    margin-bottom: 1.4rem;
    letter-spacing: 0.05em;
  }

  .btn-verify {
    width: 100%;
    background: var(--accent);
    color: white;
    border: none;
    border-radius: 3px;
    font-family: var(--font-sans);
    font-size: 14px;
    font-weight: 600;
    letter-spacing: 0.04em;
    padding: 11px;
    cursor: pointer;
    transition: background 0.2s, box-shadow 0.2s;
    text-transform: uppercase;
  }

  .btn-verify:hover {
    background: var(--accent-bright);
    box-shadow: 0 0 20px rgba(var(--accent-rgb),0.3);
  }

  .btn-verify:disabled {
    background: #1e3a5f;
    color: var(--text-dim);
    cursor: not-allowed;
    box-shadow: none;
  }

  /* Timer */
  .timer-wrap {
    text-align: center;
    margin-top: 1.2rem;
    font-size: 12px;
    color: var(--text-dim);
    font-family: 'Share Tech Mono', monospace;
  }

  .timer-wrap #countdown {
    color: var(--yellow);
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
    font-family: var(--font-sans);
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

  /* ═══ AuthKit Frosted Glass Cathedral: deep-glass card, void-violet
     submit ═══ — token-based (var(--surface-deep-glass) etc.), so unlike
     auth/login.php's card this one follows the light/dark toggle rather
     than staying permanently dark; theme-authkit.css defines both sides. */
  .card {
    background: var(--surface-deep-glass) !important;
    backdrop-filter: blur(16px) !important;
    -webkit-backdrop-filter: blur(16px) !important;
    border: 1px solid var(--color-glass-edge) !important;
    border-radius: var(--radius-card) !important;
    box-shadow: var(--shadow-modal) !important;
  }
  .card::before { display: none !important; }

  .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 1.6rem !important; }
  .brand-icon {
    width: 26px !important; height: 26px !important;
    background: none !important; border-radius: 0 !important;
  }
  .brand-icon svg { width: 26px !important; height: 26px !important; fill: var(--color-frost-glow) !important; }
  .brand-text { display: flex; align-items: center; }
  .brand-text .title {
    font-family: var(--font-sans) !important;
    font-size: 21px !important; font-weight: 700 !important;
    letter-spacing: .01em !important; text-transform: none !important;
    background: var(--gradient-skywash) !important;
    -webkit-background-clip: text !important; background-clip: text !important;
    -webkit-text-fill-color: transparent !important;
  }
  .brand-text .sub { display: none !important; }

  .card h1, .card h2 {
    font-family: var(--font-sans) !important;
    font-size: 19px !important; font-weight: 600 !important;
    letter-spacing: .005em !important; margin: 0 0 4px !important;
    color: var(--color-ice-highlight) !important;
  }
  .card .subtitle, .card p.sub {
    font-size: 13.5px !important; margin: 0 0 1.8rem !important;
    color: var(--color-moon-mist) !important;
  }

  .card label {
    font-size: 11px !important; letter-spacing: .07em !important; font-weight: 500 !important;
    color: var(--color-moon-mist) !important;
  }

  .card input[type="email"], .card input[type="password"], .card input[type="text"],
  .card input[type="tel"], .card select, .card textarea {
    background: rgba(199, 211, 234, 0.06) !important;
    border: 1px solid var(--color-glass-edge) !important;
    border-radius: var(--radius-control) !important;
    color: var(--color-ice-highlight) !important;
    padding: 11px 13px !important;
  }
  .card input[type="email"]::placeholder, .card input[type="password"]::placeholder,
  .card input[type="text"]::placeholder, .card input[type="tel"]::placeholder {
    color: rgba(199, 211, 234, 0.6) !important;
  }
  .card input[type="email"]:focus, .card input[type="password"]:focus,
  .card input[type="text"]:focus, .card input[type="tel"]:focus {
    border-color: var(--color-void-violet) !important;
    box-shadow: 0 0 0 3px rgba(102, 58, 243, 0.18) !important;
  }

  .card button[type="submit"], .card .btn-login, .card .btn-send,
  .card .btn-submit, .card .btn-primary, .card a.btn-primary {
    background: var(--color-void-violet) !important;
    color: #ffffff !important;
    border-radius: var(--radius-control) !important;
    font-family: var(--font-sans) !important;
    font-weight: 600 !important;
    letter-spacing: .02em !important;
    text-transform: none !important;
    box-shadow: 0 8px 24px -8px rgba(102, 58, 243, 0.5) !important;
    transition: transform .15s, box-shadow .15s, background .15s !important;
  }
  .card button[type="submit"]:hover, .card .btn-login:hover, .card .btn-send:hover,
  .card .btn-submit:hover, .card .btn-primary:hover, .card a.btn-primary:hover {
    background: #7548f5 !important;
    transform: translateY(-1px);
  }

  .card .footer-links a, .card .forgot-link { color: var(--color-frost-glow) !important; }
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
      <div class="sub">Two-Step Verification</div>
    </div>
  </div>

  <h2>Enter OTP</h2>
  <p class="subtitle">
    A 6-digit code was sent to<br>
    <span><?= htmlspecialchars($_SESSION["otp_email"]) ?></span>
  </p>
  <div class="divider"></div>

  <?php if ($msg): ?>
  <div class="alert error">
    <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
    <?= htmlspecialchars($msg) ?>
  </div>
  <?php endif; ?>

  <!-- Attempt dots -->
  <div class="attempts-bar">
    <?php for ($i = 0; $i < 5; $i++): ?>
      <div class="attempt-dot <?= ($i < $_SESSION["otp_attempts"]) ? 'used' : '' ?>"></div>
    <?php endfor; ?>
  </div>
  <div class="attempts-label">
    <?= $_SESSION["otp_attempts"] ?> / 5 ATTEMPTS USED
  </div>

  <form method="POST" action="otp" id="otpForm">
    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
    <input type="hidden" name="otp" id="otpHidden">

    <!-- 6 individual boxes -->
    <div class="otp-wrap">
      <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="b0">
      <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="b1">
      <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="b2">
      <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="b3">
      <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="b4">
      <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="b5">
    </div>

    <button type="submit" class="btn-verify" id="verifyBtn" disabled>Verify Code</button>
  </form>

  <!-- Countdown timer -->
  <div class="timer-wrap">
    Code expires in <span id="countdown">10:00</span>
  </div>

  <div style="display:flex; align-items:center; justify-content:center; margin-top:1rem; padding-top:0.8rem; border-top:1px solid rgba(255,255,255,0.07);">
    <button id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle">
      <span class="theme-icon"></span>
      <span class="theme-label"></span>
    </button>
  </div>
  <div class="footer-links">
    <span>Didn't receive it?</span>
    <a href="login">Go back & resend</a>
  </div>

</div>

<script>
// ── OTP box auto-advance ──────────────────────────────────────────────────────
const boxes     = Array.from(document.querySelectorAll('.otp-box'));
const hidden    = document.getElementById('otpHidden');
const verifyBtn = document.getElementById('verifyBtn');

boxes.forEach((box, idx) => {
  box.addEventListener('input', () => {
    // Only allow digits
    box.value = box.value.replace(/[^0-9]/g, '');
    box.classList.toggle('filled', box.value !== '');

    if (box.value && idx < 5) boxes[idx + 1].focus();

    syncHidden();
  });

  box.addEventListener('keydown', (e) => {
    if (e.key === 'Backspace' && !box.value && idx > 0) {
      boxes[idx - 1].value = '';
      boxes[idx - 1].classList.remove('filled');
      boxes[idx - 1].focus();
      syncHidden();
    }
  });

  // Handle paste
  box.addEventListener('paste', (e) => {
    e.preventDefault();
    const pasted = (e.clipboardData || window.clipboardData)
      .getData('text').replace(/[^0-9]/g, '').slice(0, 6);
    pasted.split('').forEach((char, i) => {
      if (boxes[i]) {
        boxes[i].value = char;
        boxes[i].classList.add('filled');
      }
    });
    if (boxes[Math.min(pasted.length, 5)]) boxes[Math.min(pasted.length, 5)].focus();
    syncHidden();
  });
});

function syncHidden() {
  const val = boxes.map(b => b.value).join('');
  hidden.value = val;
  verifyBtn.disabled = val.length < 6;
}

// Auto submit when all 6 filled
document.getElementById('otpForm').addEventListener('submit', () => {
  syncHidden();
});

// Focus first box on load
boxes[0].focus();

// ── Countdown timer (10 minutes) ─────────────────────────────────────────────
let seconds = 600;
const countdownEl = document.getElementById('countdown');

const timer = setInterval(() => {
  seconds--;
  if (seconds <= 0) {
    clearInterval(timer);
    countdownEl.textContent = '00:00';
    countdownEl.style.color = 'var(--red)';
    verifyBtn.disabled = true;
    verifyBtn.textContent = 'Code Expired';
    return;
  }
  const m = String(Math.floor(seconds / 60)).padStart(2, '0');
  const s = String(seconds % 60).padStart(2, '0');
  countdownEl.textContent = m + ':' + s;
  if (seconds < 60) countdownEl.style.color = 'var(--red)';
}, 1000);
</script>

</body>
</html>