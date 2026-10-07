<?php
include __DIR__ . '/../core/db.php';
secure_session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../PHPMailer/PHPMailer.php';
require '../PHPMailer/SMTP.php';
require '../PHPMailer/Exception.php';

$msg       = "";
$msg_type  = "error";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    verify_csrf_token();

    $email        = trim($_POST["email"]);
    $email_bindex = astra_blind_index($email);

    $stmt = mysqli_prepare($conn, "SELECT id, name FROM users WHERE email_bindex = ?");
    mysqli_stmt_bind_param($stmt, "s", $email_bindex);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user   = mysqli_fetch_assoc($result);
    astra_decrypt_user_row($user);

    if (!$user) {
        $msg      = "Email does not exist.";
        $msg_type = "error";
    } else {
        $rate_stmt = mysqli_prepare($conn, "SELECT otp_expiry > NOW() AS still_valid FROM users WHERE email_bindex = ?");
        mysqli_stmt_bind_param($rate_stmt, "s", $email_bindex);
        mysqli_stmt_execute($rate_stmt);
        $rate_row = mysqli_fetch_assoc(mysqli_stmt_get_result($rate_stmt));

        if ($rate_row["still_valid"]) {
            $msg      = "Please wait before requesting another OTP.";
            $msg_type = "error";
        } else {
            $otp = rand(100000, 999999);

            $update = mysqli_prepare($conn, "UPDATE users SET otp = ?, otp_expiry = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE email_bindex = ?");
            mysqli_stmt_bind_param($update, "ss", $otp, $email_bindex);
            mysqli_stmt_execute($update);

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host        = MAIL_HOST;
                $mail->SMTPAuth    = MAIL_AUTH; astra_mail_auth($mail);
                $mail->Port        = MAIL_PORT;
                $mail->SMTPSecure  = MAIL_SECURE;
                $mail->SMTPAutoTLS = false;

                $mail->setFrom(MAIL_FROM, MAIL_NAME);
                $mail->addAddress($email);
                $mail->Subject = 'Password Reset OTP';
                $mail->Body    = "Your OTP to reset your password is: $otp\n\nThis OTP will expire in 10 minutes.\n\nIf you did not request this please ignore.";

                $mail->send();

                $_SESSION["reset_email"] = $email;
                header("Location: " . APP_URL . "reset-code");
                exit();

            } catch (Exception $e) {
                $msg      = "Could not send OTP. Please try again.";
                $msg_type = "error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password · Hastra</title>
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
    box-shadow: 0 0 0 1px rgba(var(--accent-rgb),0.08),
                0 20px 60px rgba(0,0,0,0.5),
                0 0 40px var(--accent-glow);
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

  /* Icon circle */
  .icon-wrap {
    width: 56px;
    height: 56px;
    background: rgba(245,158,11,0.1);
    border: 1px solid rgba(245,158,11,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1.4rem;
  }

  .icon-wrap svg { width: 26px; height: 26px; fill: var(--yellow); }

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

  .field { margin-bottom: 1.4rem; }

  label {
    display: block;
    font-size: 11px;
    font-weight: 500;
    color: var(--text-dim);
    letter-spacing: 0.07em;
    text-transform: uppercase;
    margin-bottom: 6px;
  }

  input[type="email"] {
    width: 100%;
    background: var(--input-bg);
    border: 1px solid var(--border-dim);
    border-radius: 3px;
    color: var(--text);
    font-family: var(--font-sans);
    font-size: 14px;
    padding: 10px 14px;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
  }

  input[type="email"]:focus {
    border-color: var(--accent-bright);
    box-shadow: 0 0 0 3px rgba(var(--accent-rgb),0.12);
  }

  input[type="email"]::placeholder { color: var(--text-dim); }

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

  .alert.warning {
    background: var(--yellow-bg);
    border-color: var(--yellow);
    color: #fcd34d;
  }

  .alert svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; }
  .alert.error svg   { fill: var(--red); }
  .alert.warning svg { fill: var(--yellow); }

  .btn-send {
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
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
  }

  .btn-send svg { width: 15px; height: 15px; fill: white; }

  .btn-send:hover {
    background: var(--accent-bright);
    box-shadow: 0 0 20px rgba(var(--accent-rgb),0.3);
  }

  /* Steps info box */
  .steps-box {
    background: var(--section-header-bg);
    border: 1px solid var(--border-dim);
    border-radius: 3px;
    padding: 12px 14px;
    margin-bottom: 1.6rem;
  }

  .steps-box-title {
    font-size: 11px;
    font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-dim);
    margin-bottom: 10px;
  }

  .step {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 8px;
    font-size: 12px;
    color: var(--text-dim);
    line-height: 1.4;
  }

  .step:last-child { margin-bottom: 0; }

  .step-num {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: rgba(var(--accent-rgb),0.15);
    border: 1px solid var(--border);
    color: var(--accent-bright);
    font-size: 10px;
    font-family: 'Share Tech Mono', monospace;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: 1px;
  }

  .footer-links {
    margin-top: 1.6rem;
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
    box-shadow: 0 0 0 3px rgba(var(--accent-rgb), 0.18) !important;
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
    box-shadow: 0 8px 24px -8px rgba(var(--accent-rgb), 0.5) !important;
    transition: transform .15s, box-shadow .15s, background .15s !important;
  }
  .card button[type="submit"]:hover, .card .btn-login:hover, .card .btn-send:hover,
  .card .btn-submit:hover, .card .btn-primary:hover, .card a.btn-primary:hover {
    background: var(--accent-dim) !important;
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
      <svg viewBox="0 0 48 48"><defs><linearGradient id="hastraMark" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="var(--accent-bright)"/><stop offset="1" stop-color="var(--purple, var(--accent-bright))"/></linearGradient></defs><path fill="url(#hastraMark)" d="M8 5H16V43H8V5ZM32 5H40V43H32V5ZM4 21H44V27H4V21Z"/><path fill="url(#hastraMark)" d="M24 15L30 24L24 33L18 24Z"/></svg>
    </div>
    <div class="brand-text">
      <div class="title">Hastra</div>
      <div class="sub">Password Recovery</div>
    </div>
  </div>

  <!-- Lock icon -->
  <div class="icon-wrap">
    <svg viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
  </div>

  <h2>Forgot Password</h2>
  <p class="subtitle">Enter your registered email and we'll send you a reset OTP.</p>
  <div class="divider"></div>

  <!-- Steps info -->
  <div class="steps-box">
    <div class="steps-box-title">How it works</div>
    <div class="step">
      <div class="step-num">1</div>
      <span>Enter your registered email address below</span>
    </div>
    <div class="step">
      <div class="step-num">2</div>
      <span>We'll send a 6-digit OTP to your inbox</span>
    </div>
    <div class="step">
      <div class="step-num">3</div>
      <span>Enter the OTP and set a new password</span>
    </div>
  </div>

  <?php if ($msg): ?>
  <div class="alert <?= $msg_type === 'error' ? 'error' : 'warning' ?>">
    <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
    <?= htmlspecialchars($msg) ?>
  </div>
  <?php endif; ?>

  <form method="POST" action="forgot-password">
    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

    <div class="field">
      <label for="email">Registered Email</label>
      <input type="email" name="email" id="email"
             maxlength="100" required
             placeholder="you@example.com"
             autofocus autocomplete="email">
    </div>

    <button type="submit" class="btn-send">
      <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
      Send OTP
    </button>
  </form>

  <div style="display:flex; align-items:center; justify-content:center; margin-top:1rem; padding-top:0.8rem; border-top:1px solid rgba(255,255,255,0.07);">
    <button id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle">
      <span class="theme-icon"></span>
      <span class="theme-label"></span>
    </button>
  </div>
  <div class="footer-links">
    <span>Remembered it?</span>
    <a href="<?= get_base_url() ?>signin">Back to Login</a>
  </div>

</div>

<?php astra_legal_footer('below'); astra_consent_banner(); ?>
</body>
</html>