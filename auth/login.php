<?php
include __DIR__ . '/../core/db.php';
secure_session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../PHPMailer/PHPMailer.php';
require '../PHPMailer/SMTP.php';
require '../PHPMailer/Exception.php';

if (isset($_SESSION["user_id"])) {
    switch ($_SESSION["user_role"]) {
        case "sysadmin":         header("Location: " . get_base_url() . "portals/sysadmin/sysadmin_portal"); break;
        case "admin":            header("Location: " . get_base_url() . "portals/admin/admin_portal");       break;
        case "employee":         header("Location: " . get_base_url() . "portals/emlpoyee/employee_portal"); break;
        case "pending_employee": header("Location: " . get_base_url() . "portals/user/newuser_portal");      break;
        case "client": header("Location: " . get_base_url() . "portals/client/client_portal"); break;      break;
        default:                 header("Location: " . get_base_url() . "portals/user/newuser_portal");      break;
    }
    exit();
}

$msg = "";
$active_portal = ($_POST["portal"] ?? "") === "client" ? "client" : "enterprise";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    verify_csrf_token();

    // Anti-VPN/proxy gate — runs before any credential lookup so a blocked
    // request never touches the users table at all.
    $client_ip  = astra_get_client_ip();
    $ip_inspect = astra_inspect_ip($client_ip, $conn);

    if ($ip_inspect["is_vpn"]) {
        $ip_error = "VPN or Proxy connection detected. Please disable your VPN to continue into the Astra platform.";
        log_activity($conn, null, "login_blocked_vpn", $ip_inspect["isp"] ?? "Unknown ISP", $client_ip);
    } else {
        $ip_error = check_ip_limit($conn);
    }

    if ($ip_error) {
        $msg = $ip_error;
    } else {
        $captcha = $_POST["g-recaptcha-response"] ?? "";
        $verify  = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=" . RECAPTCHA_SECRET . "&response=$captcha");
        $result  = json_decode($verify);

        if (!$result->success) {
            $msg = "Please complete the CAPTCHA.";
        } else {
            $email    = trim($_POST["email"]);
            $password = $_POST["password"];

            // Honeytoken trap: a decoy email that no real account has ever
            // used. Checked before the real lookup — a match terminates the
            // request from inside astra_canary_check() and never falls
            // through to a credential check at all.
            astra_canary_check($conn, 'canary_user', $email);

            $email_bindex = astra_blind_index($email);

            $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email_bindex = ?");
            mysqli_stmt_bind_param($stmt, "s", $email_bindex);
            mysqli_stmt_execute($stmt);
            $result2 = mysqli_stmt_get_result($stmt);
            $user    = mysqli_fetch_assoc($result2);
            astra_decrypt_user_row($user);

            if ($user) {
                if ($user["locked_until"] && strtotime($user["locked_until"]) > time()) {
                    $minutes = ceil((strtotime($user["locked_until"]) - time()) / 60);
                    $msg = "Account locked. Try again in $minutes minute(s).";
                    increment_ip_attempts($conn);

                } elseif (password_verify($password, $user["password"])) {
                    reset_ip_attempts($conn);

                    $reset = mysqli_prepare($conn, "UPDATE users SET login_attempts = 0, locked_until = NULL WHERE email_bindex = ?");
                    mysqli_stmt_bind_param($reset, "s", $email_bindex);
                    mysqli_stmt_execute($reset);

                    $otp    = rand(100000, 999999);
                    $update = mysqli_prepare($conn, "UPDATE users SET otp = ?, otp_expiry = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE email_bindex = ?");
                    mysqli_stmt_bind_param($update, "ss", $otp, $email_bindex);
                    mysqli_stmt_execute($update);

                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host        = MAIL_HOST;
                        $mail->SMTPAuth    = MAIL_AUTH;
                        $mail->Port        = MAIL_PORT;
                        $mail->SMTPSecure  = MAIL_SECURE;
                        $mail->SMTPAutoTLS = false;

                        $mail->setFrom(MAIL_FROM, MAIL_NAME);
                        $mail->addAddress($email);
                        $mail->Subject = 'Your OTP Code';
                        $mail->Body    = "Your OTP is: $otp\n\nExpires in 10 minutes.\n\nIgnore if not requested.";

                        $mail->send();

                        log_activity($conn, $user["id"], "login_otp_sent",$user["name"]);
                        $_SESSION["otp_email"] = $email;
                        header("Location: otp");
                        exit();

                    } catch (Exception $e) {
                        $msg = "Could not send OTP. Please try again.";
                    }

                } else {
                    increment_ip_attempts($conn);
                    log_activity($conn, $user["id"], "login_failed",$user["name"]);
                    $attempts = $user["login_attempts"] + 1;

                    if ($attempts >= 5) {
                        $lock_time = date("Y-m-d H:i:s", strtotime("+15 minutes"));
                        $lock = mysqli_prepare($conn, "UPDATE users SET login_attempts = ?, locked_until = ? WHERE email_bindex = ?");
                        mysqli_stmt_bind_param($lock, "iss", $attempts, $lock_time, $email_bindex);
                        mysqli_stmt_execute($lock);
                        log_activity($conn, $user["id"], "account_locked",$user["name"]);
                        $msg = "Too many failed attempts. Account locked for 15 minutes.";
                    } else {
                        $remaining = 5 - $attempts;
                        $update_attempts = mysqli_prepare($conn, "UPDATE users SET login_attempts = ? WHERE email_bindex = ?");
                        mysqli_stmt_bind_param($update_attempts, "is", $attempts, $email_bindex);
                        mysqli_stmt_execute($update_attempts);
                        $msg = "Invalid email or password. $remaining attempt(s) remaining.";
                    }
                }
            } else {
                increment_ip_attempts($conn);
                $msg = "Invalid email or password.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-authkit.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<script src="https://www.google.com/recaptcha/api.js?onload=onRecaptchaApiLoad&render=explicit" async defer></script>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  html { height: auto; }
  body {
    min-height: 100vh;
    height: auto;
    background-color: var(--navy);
    background-image:
      radial-gradient(ellipse 80% 55% at 50% -8%, var(--accent-glow), transparent 60%),
      linear-gradient(var(--grid-line) 1px, transparent 1px),
      linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
    background-size: auto, 40px 40px, 40px 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--font-sans);
    padding: 1.5rem;
    position: relative;
    /* Was `overflow: hidden` — on a short viewport (small window, a phone in
       landscape, a maximized-but-short browser) the centered .card can be
       taller than the viewport, and `hidden` clipped it top and bottom with
       no way to reach the Sign In button. Horizontal stays clipped (the
       full-bleed background video/gradient shouldn't ever cause a horizontal
       scrollbar); vertical now scrolls instead of clipping. */
    overflow-x: hidden;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    transition: var(--transition);
  }

  /* ── Cinematic reveal: a giant hand opens out of the clouds on video;
     the login card fades/rises into view right as the palm opens ── */
  .video-bg {
    position: fixed;
    inset: 0;
    z-index: -2;
    width: 100%;
    height: 100%;
    object-fit: cover;
    pointer-events: none;
    background: var(--navy); /* shows while the video buffers */
  }
  /* Dark theme (default): crimson accent wash, matching --accent-rgb's red.
     Light theme flips both the video's hue and the overlay wash to the
     same blue/cyan --accent-rgb the rest of the light theme uses — see
     core/theme.css's [data-theme="light"] :root block. */
  #bg-video {
    filter: brightness(0.35) contrast(1.25) saturate(0.7);
    transition: filter .6s ease;
  }
  [data-theme="light"] #bg-video {
    filter: brightness(0.45) contrast(1.15) saturate(0.55) hue-rotate(190deg);
  }

  .video-overlay {
    position: fixed;
    inset: 0;
    z-index: -1;
    pointer-events: none;
    background: radial-gradient(circle at center,
                  rgba(var(--accent-rgb), .22) 0%,
                  rgba(3,5,12,.78) 55%,
                  rgba(3,5,12,.9) 100%);
    transition: background .6s ease;
  }
  [data-theme="light"] .video-overlay {
    background: radial-gradient(circle at center,
                  rgba(var(--accent-rgb), .28) 0%,
                  rgba(6,10,24,.75) 55%,
                  rgba(4,7,18,.9) 100%);
  }

  .card {
    position: fixed;
    top: 50%;
    left: 50%;
    z-index: 1;
    background: var(--navy-card);
    border: 1px solid var(--border);
    border-radius: 4px;
    width: 100%;
    max-width: 420px;
    /* Fixed + centered-via-transform means this box never moves when the
       page scrolls — body's own overflow setting can't help it. On a short
       viewport (a phone in landscape, a small/split window) the card can be
       taller than the screen, and without a cap here its top (logo/header)
       and bottom (Sign In button) render off-screen with literally no way
       to reach them. Capping the height and scrolling internally — the
       standard centered-dialog pattern — keeps every field reachable while
       leaving the look identical on any viewport tall enough to fit it.
       overscroll-behavior:contain stops that internal scroll from bubbling
       into a page-level bounce/scroll on touch devices. */
    max-height: calc(100vh - 2rem);
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
    padding: 1.3rem 2rem 1.1rem;
    box-shadow: 0 0 0 1px rgba(0,0,0,0.06), 0 20px 60px rgba(0,0,0,0.5), 0 0 40px var(--accent-glow);
    opacity: 0;
    transform: translate(-50%, -50%) scale(0.05);
    filter: blur(12px);
    pointer-events: none;
    transition: transform 1s cubic-bezier(0.16, 1, 0.3, 1),
                opacity 0.8s ease-out,
                filter 0.8s ease-out,
                background .5s ease, border-color .5s ease;
  }
  /* Set from JS the instant the video pauses on the crack frame (or the
     fail-safe timer fires) — see the script near the end of the page. */
  .card.revealed {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1);
    filter: blur(0);
    pointer-events: auto;
  }

  /* Energy-burst shockwave: an expanding, fading ring behind the card,
     as if the portal erupted out of the crack the instant it opens. */
  .card::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -1;
    border-radius: inherit;
    box-shadow: 0 0 0 0 var(--accent-glow);
    opacity: 0;
    pointer-events: none;
  }
  .card.revealed::after {
    animation: astraShockwave 1.1s cubic-bezier(.16, 1, .3, 1) forwards;
  }
  @keyframes astraShockwave {
    0%   { box-shadow: 0 0 0 0 var(--accent-glow); opacity: .9; }
    55%  { opacity: .4; }
    100% { box-shadow: 0 0 0 70px transparent; opacity: 0; }
  }

  /* Inside the card: icon pops in, then "Astra" projects outward from it
     — the exact same astraTextIntro/astraIconPop keyframes the portal nav
     bars use once per session (core/theme.js) — then the rest of the form
     fades up. This page opts out of that automatic trigger
     (<body data-intro-manual>) and fires the identical animation itself,
     timed to the video/card reveal instead of page load. */
  .card .brand-icon { opacity: 0; transform: scale(.6); }
  .card .brand-text .title { display: inline-block; opacity: 0; transform: scaleX(0); transform-origin: center; }
  .card h2, .card .subtitle, .card .divider, .card form, .card .footer-links, .card .sys-row {
    opacity: 0;
  }
  .card.revealed .brand-icon {
    animation: astraIconPop .45s .3s cubic-bezier(.34,1.56,.64,1) forwards;
  }
  .card.revealed .brand-text .title {
    animation: astraTextIntro .6s .6s cubic-bezier(.16,1,.3,1) forwards;
  }
  .card.revealed h2,
  .card.revealed .subtitle,
  .card.revealed .divider,
  .card.revealed form,
  .card.revealed .footer-links,
  .card.revealed .sys-row {
    animation: astraFadeUp .55s 1s cubic-bezier(.16,1,.3,1) forwards;
  }

  @media (prefers-reduced-motion: reduce) {
    .card { transition: opacity .3s ease; transform: translate(-50%, -50%) !important; filter: none !important; }
    .card.revealed { transform: translate(-50%, -50%); }
    .card.revealed::after { animation: none; }
    .card .brand-icon, .card .brand-text .title,
    .card h2, .card .subtitle, .card .divider, .card form, .card .footer-links, .card .sys-row {
      opacity: 1 !important; transform: none !important; animation: none !important;
    }
  }

  /* Top accent line */
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

  .brand-text { line-height: 1; }
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
    margin-bottom: 1.1rem;
  }

  .divider {
    height: 1px;
    background: var(--border-dim);
    margin-bottom: 1.1rem;
  }

  .field { margin-bottom: 0.55rem; }

  label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-dim);
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-bottom: 6px;
  }

  .input-wrap { position: relative; }

  input[type="email"],
  input[type="password"],
  input[type="text"] {
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

  input[type="email"]:focus,
  input[type="password"]:focus,
  input[type="text"]:focus {
    border-color: var(--accent-bright);
    box-shadow: 0 0 0 3px rgba(var(--accent-rgb),0.12);
  }

  input[type="email"]::placeholder,
  input[type="password"]::placeholder { color: var(--text-dim); }

  /* Eye toggle button */
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

  input[type="password"],
  input[type="text"] { padding-right: 40px; }

  .row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.6rem;
  }

  .checkbox-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
  }

  .checkbox-wrap input[type="checkbox"] {
    width: 15px;
    height: 15px;
    accent-color: var(--accent-bright);
    cursor: pointer;
  }

  .checkbox-wrap span {
    font-size: 12px;
    color: var(--text-dim);
  }

  .forgot-link {
    font-size: 12px;
    color: var(--accent-bright);
    text-decoration: none;
    transition: color 0.2s;
  }
  .forgot-link:hover { color: var(--text); }

  /* reCAPTCHA wrapper */
  .recaptcha-wrap {
    margin-bottom: 0.6rem;
    transform-origin: left top;
  }

  /* scale down recaptcha to fit card */
  @media (max-width: 480px) {
    .recaptcha-wrap { transform: scale(0.88); }
  }

  .btn-login {
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

  .btn-login:hover {
    background: var(--accent-bright);
    box-shadow: 0 0 20px rgba(var(--accent-rgb),0.3);
  }

  .btn-login:active { background: var(--accent-dim); }

  .alert {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    background: var(--red-bg);
    border: 1px solid rgba(var(--red-rgb),0.3);
    border-left: 3px solid var(--red);
    border-radius: 3px;
    padding: 10px 12px;
    margin-bottom: 1.4rem;
    font-size: 13px;
    color: #fca5a5;
    line-height: 1.4;
  }

  .alert svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; fill: var(--red); }

  .footer-links {
    margin-top: 0.6rem;
    padding-top: 0.6rem;
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
  .footer-links a:hover { color: var(--text); }

  .status-dot {
    display: inline-block;
    width: 6px;
    height: 6px;
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

  .sys-status {
    font-size: 11px;
    color: var(--text-dim);
    text-align: center;
    margin-top: 1.2rem;
    letter-spacing: 0.04em;
    font-family: 'Share Tech Mono', monospace;
  }

  /* ═══ AuthKit "Frosted Glass Cathedral at Midnight": deep-glass card,
     void-violet submit, pill ghost tabs ═══
     Uses AuthKit's own fixed tokens (assets/css/theme-authkit.css) rather
     than the app's --navy-card/--accent pair, which flips with the
     light/dark toggle — AuthKit is one deliberate dark aesthetic, so the
     card looks the same regardless of the visitor's theme preference. The
     `[data-theme="light"] .card` block re-asserts the same values for
     exactly the reason the comment it replaces already explained: that
     selector is more specific than a bare `.card` and would otherwise win. */
  .card {
    background: var(--surface-deep-glass) !important;
    backdrop-filter: blur(16px) !important;
    -webkit-backdrop-filter: blur(16px) !important;
    border: 1px solid var(--color-glass-edge) !important;
    border-radius: var(--radius-card) !important;
    box-shadow: var(--shadow-modal) !important;
  }
  [data-theme="light"] .card {
    background: var(--surface-deep-glass) !important;
    backdrop-filter: blur(16px) !important;
    -webkit-backdrop-filter: blur(16px) !important;
    border: 1px solid var(--color-glass-edge) !important;
    border-radius: var(--radius-card) !important;
    box-shadow: var(--shadow-modal) !important;
  }
  .card::before { display: none !important; }

  .brand {
    display: flex; flex-direction: column; align-items: center;
    text-align: center; gap: 6px; margin-bottom: 1rem !important;
  }
  .brand-icon {
    width: 34px !important; height: 34px !important;
    background: none !important; border-radius: 0 !important;
  }
  .brand-icon svg { width: 34px !important; height: 34px !important; fill: var(--color-frost-glow) !important; }
  .brand-text { display: flex; flex-direction: column; align-items: center; }
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

  /* core/theme.css carries app-wide [data-theme="light"] overrides for
     bare `input[type=...]` and `.btn-login.btn-login` (a doubled-class
     specificity trick) so every OTHER page's buttons/fields stay legible in
     light mode. Those rules tie or beat a plain `.card input[...]`/
     `.card .btn-login` selector on specificity, so every declaration below
     is duplicated under an explicit `[data-theme="light"]` prefix to
     guarantee AuthKit's own look wins regardless of the visitor's theme
     toggle — this card is deliberately theme-invariant. */
  .card input[type="email"], .card input[type="password"], .card input[type="text"],
  .card input[type="tel"], .card select, .card textarea,
  [data-theme="light"] .card input[type="email"], [data-theme="light"] .card input[type="password"],
  [data-theme="light"] .card input[type="text"], [data-theme="light"] .card input[type="tel"],
  [data-theme="light"] .card select, [data-theme="light"] .card textarea {
    background: rgba(199, 211, 234, 0.06) !important;
    border: 1px solid var(--color-glass-edge) !important;
    border-radius: var(--radius-control) !important;
    color: #ffffff !important;
    padding: 11px 13px !important;
  }
  .card input[type="email"]::placeholder, .card input[type="password"]::placeholder,
  .card input[type="text"]::placeholder, .card input[type="tel"]::placeholder,
  [data-theme="light"] .card input[type="email"]::placeholder, [data-theme="light"] .card input[type="password"]::placeholder,
  [data-theme="light"] .card input[type="text"]::placeholder, [data-theme="light"] .card input[type="tel"]::placeholder {
    color: rgba(199, 211, 234, 0.6) !important;
  }
  .card input[type="email"]:focus, .card input[type="password"]:focus,
  .card input[type="text"]:focus, .card input[type="tel"]:focus,
  [data-theme="light"] .card input[type="email"]:focus, [data-theme="light"] .card input[type="password"]:focus,
  [data-theme="light"] .card input[type="text"]:focus, [data-theme="light"] .card input[type="tel"]:focus {
    border-color: var(--color-void-violet) !important;
    background: rgba(199, 211, 234, 0.06) !important;
    box-shadow: 0 0 0 3px rgba(102, 58, 243, 0.18) !important;
  }

  .card button[type="submit"], .card .btn-login, .card .btn-send,
  .card .btn-submit, .card .btn-primary, .card a.btn-primary,
  [data-theme="light"] .card button[type="submit"], [data-theme="light"] .card .btn-login,
  [data-theme="light"] .card .btn-send, [data-theme="light"] .card .btn-submit,
  [data-theme="light"] .card .btn-primary, [data-theme="light"] .card a.btn-primary {
    background: var(--color-void-violet) !important;
    color: #ffffff !important;
    border-color: var(--color-void-violet) !important;
    border-radius: var(--radius-control) !important;
    font-family: var(--font-sans) !important;
    font-weight: 600 !important;
    letter-spacing: .02em !important;
    text-transform: none !important;
    box-shadow: 0 8px 24px -8px rgba(102, 58, 243, 0.5) !important;
    transition: transform .15s, box-shadow .15s, background .15s !important;
  }
  .card button[type="submit"]:hover, .card .btn-login:hover, .card .btn-send:hover,
  .card .btn-submit:hover, .card .btn-primary:hover, .card a.btn-primary:hover,
  [data-theme="light"] .card button[type="submit"]:hover, [data-theme="light"] .card .btn-login:hover,
  [data-theme="light"] .card .btn-send:hover, [data-theme="light"] .card .btn-submit:hover,
  [data-theme="light"] .card .btn-primary:hover, [data-theme="light"] .card a.btn-primary:hover {
    background: #7548f5 !important;
    box-shadow: 0 8px 28px -6px rgba(102, 58, 243, 0.7) !important;
    transform: translateY(-1px);
  }

  .card .footer-links a, .card .forgot-link { color: var(--color-frost-glow) !important; }

  /* ── Two-portal sliding gate: ghost pill tabs, per AuthKit's "Ghost
     Buttons & Social Providers" treatment (999px radius, translucent
     moon-mist fill, glass-edge inset border) — the active tab gets the
     same void-violet fill as the primary submit button, since it's
     functionally this form's other "which action am I taking" choice. ── */
  .portal-toggle {
    display: flex; gap: 4px; background: rgba(199, 211, 234, 0.06);
    border: 1px solid var(--color-glass-edge); border-radius: var(--radius-pill); padding: 4px;
    margin-bottom: 0.8rem;
  }
  .portal-toggle button {
    flex: 1 1 0; border: none; background: transparent; color: var(--color-moon-mist);
    font-family: var(--font-sans); font-size: 12.5px; font-weight: 600;
    letter-spacing: .01em; padding: 9px 8px; border-radius: var(--radius-pill); cursor: pointer;
    transition: background .2s, color .2s;
  }
  .portal-toggle button.active {
    background: var(--color-void-violet);
    color: #fff; box-shadow: 0 6px 16px -6px rgba(102, 58, 243, 0.6);
  }
  .portal-toggle button:not(.active):hover { color: #ffffff; }

  .login-viewport { overflow: hidden; position: relative; }
  .login-track {
    display: flex; width: 200%;
    transition: transform 0.45s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .login-track.show-client { transform: translateX(-50%); }
  .login-panel { flex: 0 0 50%; width: 50%; min-width: 0; }
  .login-panel:first-child { padding-right: 1px; }
  .login-panel:last-child { padding-left: 1px; }

</style>
</head>
<body data-intro-manual>

<!-- Cinematic background: a giant hand opening out of the clouds. Purely
     decorative, so it's hidden from assistive tech; the fail-safes in the
     script below guarantee the card appears even if this never plays. -->
<video class="video-bg" id="bg-video" autoplay muted playsinline preload="auto" aria-hidden="true">
  <source src="<?= get_base_url() ?>assets/video/login-reveal.mp4" type="video/mp4">
</video>
<div class="video-overlay"></div>

<div class="card">

  <!-- Brand -->
  <div class="brand">
    <div class="brand-icon">
      <svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <defs>
          <linearGradient id="astraMark" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="var(--accent-bright)"/>
            <stop offset="1" stop-color="var(--purple, #a78bfa)"/>
          </linearGradient>
        </defs>
        <!-- Peaked "A" -->
        <path fill="url(#astraMark)" d="M24 3 L45 45 H34.4 L24 24.2 L13.6 45 H3 Z"/>
        <!-- Four-point spark at the apex -->
        <path fill="url(#astraMark)" d="M24 14.5 L27.7 22 L35 25.5 L27.7 29 L24 36.5 L20.3 29 L13 25.5 L20.3 22 Z"/>
      </svg>
    </div>
    <div class="brand-text">
      <div class="title">Astra</div>
      <div class="sub">Secure Access Portal</div>
    </div>
  </div>

  <!-- Portal toggle -->
  <div class="portal-toggle" role="tablist" aria-label="Choose sign-in portal">
    <button type="button" id="toggleEnterprise" class="<?= $active_portal === 'enterprise' ? 'active' : '' ?>" role="tab" aria-selected="<?= $active_portal === 'enterprise' ? 'true' : 'false' ?>">Enterprise Workspace</button>
    <button type="button" id="toggleClient" class="<?= $active_portal === 'client' ? 'active' : '' ?>" role="tab" aria-selected="<?= $active_portal === 'client' ? 'true' : 'false' ?>">Client Gateway</button>
  </div>

  <div class="login-viewport">
    <div class="login-track<?= $active_portal === 'client' ? ' show-client' : '' ?>" id="loginTrack">

      <!-- ── Enterprise Workspace panel ── -->
      <div class="login-panel">
        <h2>Sign In</h2>
        <p class="subtitle">Enter your workspace credentials to access Astra.</p>
        <div class="divider"></div>

        <?php if ($msg && $active_portal === 'enterprise'): ?>
        <div class="alert">
          <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
          <?= htmlspecialchars($msg) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="login" class="loginForm" autocomplete="on">
          <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
          <input type="hidden" name="portal" value="enterprise">

          <div class="field">
            <label for="email-enterprise">Email Address</label>
            <input type="email" name="email" id="email-enterprise"
                   maxlength="100" required autocomplete="username"
                   placeholder="you@company.com">
          </div>

          <div class="field">
            <label for="password-enterprise">Password</label>
            <div class="input-wrap">
              <input type="password" name="password" id="password-enterprise" class="pwd-input"
                     maxlength="128" required autocomplete="current-password"
                     placeholder="••••••••••••">
              <button type="button" class="eye-btn" aria-label="Toggle password visibility">
                <svg class="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
                  <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
                  <line x1="1" y1="1" x2="23" y2="23"/>
                </svg>
              </button>
            </div>
          </div>

          <div class="row">
            <label class="checkbox-wrap">
              <input type="checkbox" name="remember_me">
              <span>Remember me</span>
            </label>
            <a href="forgot" class="forgot-link">Forgot password?</a>
          </div>

          <div class="recaptcha-wrap">
            <div class="recaptcha-slot" id="recaptchaContainer-enterprise"></div>
          </div>

          <button type="submit" class="btn-login">Sign In</button>
        </form>

        <div class="footer-links">
          <span>New organization?</span>
          <a href="register?track=enterprise">Set up your workspace</a>
        </div>
      </div>

      <!-- ── Client Gateway panel ── -->
      <div class="login-panel">
        <h2>Client Sign In</h2>
        <p class="subtitle">Access the project workspace your company was onboarded into.</p>
        <div class="divider"></div>

        <?php if ($msg && $active_portal === 'client'): ?>
        <div class="alert">
          <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
          <?= htmlspecialchars($msg) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="login" class="loginForm" autocomplete="on">
          <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
          <input type="hidden" name="portal" value="client">

          <div class="field">
            <label for="email-client">Email Address</label>
            <input type="email" name="email" id="email-client"
                   maxlength="100" required autocomplete="username"
                   placeholder="you@example.com">
          </div>

          <div class="field">
            <label for="password-client">Password</label>
            <div class="input-wrap">
              <input type="password" name="password" id="password-client" class="pwd-input"
                     maxlength="128" required autocomplete="current-password"
                     placeholder="••••••••••••">
              <button type="button" class="eye-btn" aria-label="Toggle password visibility">
                <svg class="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
                  <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
                  <line x1="1" y1="1" x2="23" y2="23"/>
                </svg>
              </button>
            </div>
          </div>

          <div class="row">
            <label class="checkbox-wrap">
              <input type="checkbox" name="remember_me">
              <span>Remember me</span>
            </label>
            <a href="forgot" class="forgot-link">Forgot password?</a>
          </div>

          <div class="recaptcha-wrap">
            <div class="recaptcha-slot" id="recaptchaContainer-client"></div>
          </div>

          <button type="submit" class="btn-login">Sign In</button>
        </form>

        <div class="footer-links">
          <span>New client company?</span>
          <a href="register">Create your workspace</a>
        </div>
      </div>

    </div>
  </div>

  <!-- System status -->
  <div class="sys-row" style="display:flex; align-items:center; justify-content:space-between; margin-top:0.7rem;">
  <div class="sys-status" style="margin-top:0;">
    <span class="status-dot"></span>All systems operational
  </div>
  <button id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle">
    <span class="theme-icon"></span>
    <span class="theme-label"></span>
  </button>
</div>

</div>

<script>
// ── Cinematic reveal: freeze the video the instant the crack opens (before
//    the trees sprout) and pop the card out of that rupture ──
(function() {
  const video = document.getElementById('bg-video');
  const card  = document.querySelector('.card');
  const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let revealed = false;

  function reveal() {
    if (revealed) return;
    revealed = true;
    // Freeze right here regardless of how we got here (crack frame or the
    // fail-safe) — the card must never emerge while trees are sprouting.
    if (video) video.pause();
    card.classList.add('revealed');
  }

  if (reduceMotion) {
    // Respect the user's preference: skip the video motion entirely and
    // just show the card — no autoplaying background to fight with.
    if (video) { video.pause(); video.removeAttribute('autoplay'); video.style.display = 'none'; }
    reveal();
  } else if (video) {
    const setSpeed = () => { video.playbackRate = 1.5; };
    setSpeed();
    video.addEventListener('loadedmetadata', setSpeed);

    // Crack forms ~4.0s in; pause+reveal at 4.05s so it never plays into
    // the tree growth that starts at 4.8s.
    video.addEventListener('timeupdate', function() {
      if (video.currentTime >= 4.05) reveal();
    });

    // If the video can't load or play (blocked autoplay, bad codec, slow
    // network…) the card must still appear — never lock a user out of
    // the login form because of a background video.
    video.addEventListener('error', reveal);
  }

  // Fail-safe: only for when autoplay is actually blocked or the video
  // never loads. At 1.5x speed, reaching the 4.05s crack frame legitimately
  // takes ~2.7s of real time — a blind 2.5s timeout would fire first on
  // every normal playback and freeze the palm a beat too early, before the
  // crack has formed. So only force-reveal here if the video never actually
  // started playing (still paused/at 0 by 2.5s); a video that's progressing
  // fine is left to its own timeupdate handler just above.
  window.setTimeout(function() {
    if (revealed) return;
    if (!video || video.paused || video.currentTime < 0.15) reveal();
  }, 2500);
})();

// ── Two-portal sliding gate ─────────────────────────────────────────────────
const loginTrack       = document.getElementById('loginTrack');
const toggleEnterprise  = document.getElementById('toggleEnterprise');
const toggleClient      = document.getElementById('toggleClient');

function setActivePortal(portal) {
  loginTrack.classList.toggle('show-client', portal === 'client');
  toggleEnterprise.classList.toggle('active', portal === 'enterprise');
  toggleClient.classList.toggle('active', portal === 'client');
  toggleEnterprise.setAttribute('aria-selected', portal === 'enterprise' ? 'true' : 'false');
  toggleClient.setAttribute('aria-selected', portal === 'client' ? 'true' : 'false');
}
toggleEnterprise.addEventListener('click', function() { setActivePortal('enterprise'); });
toggleClient.addEventListener('click', function() { setActivePortal('client'); });

// ── Show / Hide password (one eye button per panel) ────────────────────────
const eyeOpen = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" fill="none"/>`;
const eyeClosed = `<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>`;

document.querySelectorAll('.eye-btn').forEach(function(btn) {
  const wrap  = btn.closest('.input-wrap');
  const input = wrap.querySelector('.pwd-input');
  const icon  = btn.querySelector('.eye-icon');
  btn.addEventListener('click', function() {
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    icon.innerHTML = isHidden ? eyeOpen : eyeClosed;
  });
});

// ── Credential Management API ─────────────────────────────────────────────────
if (window.PasswordCredential) {
  navigator.credentials.get({ password: true, mediation: 'optional' })
    .then(credential => {
      if (credential) {
        document.querySelectorAll('input[name="email"]').forEach(el => el.value = credential.id);
        document.querySelectorAll('.pwd-input').forEach(el => el.value = credential.password);
      }
    }).catch(() => {});

  document.querySelectorAll('.loginForm').forEach(function(form) {
    form.addEventListener('submit', async function() {
      const email    = form.querySelector('input[name="email"]').value;
      const password = form.querySelector('.pwd-input').value;
      if (email && password) {
        try {
          const cred = new PasswordCredential({ id: email, password: password, name: email });
          await navigator.credentials.store(cred);
        } catch(e) {}
      }
    });
  });
} else {
  const savedEmail = "<?= isset($_COOKIE['remember_email']) ? htmlspecialchars($_COOKIE['remember_email']) : '' ?>";
  if (savedEmail) document.querySelectorAll('input[name="email"]').forEach(el => el.value = savedEmail);
}

// ── reCAPTCHA: one widget per panel, rendered explicitly so its theme can
//    match the site's ──────────────────────────────────────────────────────
let recaptchaWidgetIds = {};
function currentSiteTheme() {
  return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
}
function renderRecaptcha() {
  if (!window.grecaptcha || !window.grecaptcha.render) return;
  document.querySelectorAll('.recaptcha-slot').forEach(function(slot) {
    // reCAPTCHA binds to the actual DOM node — swap in a fresh node each
    // re-render (e.g. on theme change) so nothing confuses it with a stale one.
    const key = slot.id;
    const fresh = document.createElement('div');
    fresh.id = key;
    fresh.className = 'recaptcha-slot';
    slot.replaceWith(fresh);
    recaptchaWidgetIds[key] = window.grecaptcha.render(fresh, {
      sitekey: '6LcjNg4tAAAAALrE033V1uMvYdaDs4jCQ8qboPIL',
      theme: currentSiteTheme()
    });
  });
}
window.onRecaptchaApiLoad = renderRecaptcha;
document.addEventListener('astra:themechange', function() {
  // The reCAPTCHA client doesn't reliably repaint the new theme on the
  // first re-render right after a live theme toggle — a follow-up render
  // shortly after consistently fixes it, so do both.
  setTimeout(renderRecaptcha, 80);
  setTimeout(renderRecaptcha, 350);
});
</script>

</body>
</html>