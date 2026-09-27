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
        case "sysadmin":         header("Location: " . get_base_url() . "workspace/sysadmin/"); break;
        case "admin":            header("Location: " . get_base_url() . "workspace/admin/");       break;
        case "employee":         header("Location: " . get_base_url() . "workspace/employee/"); break;
        case "pending_employee": header("Location: " . get_base_url() . "workspace/user/newuser-portal");      break;
        case "client": header("Location: " . get_base_url() . "workspace/client/"); break;      break;
        default:                 header("Location: " . get_base_url() . "workspace/user/newuser-portal");      break;
    }
    exit();
}

$msg = "";
$active_portal = ($_POST["portal"] ?? "") === "client" ? "client" : "enterprise";

// Why the previous session ended (core/session_guard.php redirects here).
// Shown on both panels, since we don't know which one the user signs in on.
$session_notices = [
    'session_breach'  => "Your session was ended because it was used from a different device or network. Sign in again with your password and email code.",
    'session_revoked' => "That session was signed out. Sign in again to continue.",
    'reauth'          => "For your security, please sign in again.",
];
$session_notice = $_SERVER["REQUEST_METHOD"] === "GET" ? ($session_notices[$_GET["error"] ?? ""] ?? "") : "";
if ($session_notice) $msg = $session_notice;
if (!$msg && $_SERVER["REQUEST_METHOD"] === "GET") $msg = astra_google_error_message();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    verify_csrf_token();

    // Anti-VPN/proxy gate — runs before any credential lookup so a blocked
    // request never touches the users table at all.
    $client_ip  = astra_get_client_ip();
    $ip_inspect = astra_inspect_ip($client_ip, $conn);

    if ($ip_inspect["is_vpn"]) {
        $ip_error = "VPN or Proxy connection detected. Please disable your VPN to continue into the Hastra platform.";
        log_activity($conn, null, "login_blocked_vpn", $ip_inspect["isp"] ?? "Unknown ISP", $client_ip);
    } else {
        $ip_error = check_ip_limit($conn);
    }

    if ($ip_error) {
        $msg = $ip_error;
    } else {
        if (!astra_verify_recaptcha((string)($_POST["g-recaptcha-response"] ?? ""))) {
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
                        header("Location: verify");
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
                        $update_attempts = mysqli_prepare($conn, "UPDATE users SET login_attempts = ? WHERE email_bindex = ?");
                        mysqli_stmt_bind_param($update_attempts, "is", $attempts, $email_bindex);
                        mysqli_stmt_execute($update_attempts);
                        // Same text as an unknown email: a remaining-attempts count
                        // would confirm that the account exists.
                        $msg = "Invalid email or password.";
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
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · Hastra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-authkit.css?v=<?= ASSET_VERSION ?>">
<?php astra_compliance_css(); ?>
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

  /* ── Cinematic reveal, two stages, both deterministic CSS/video —
     no WebGL, no camera orbit, nothing here is ever driven by the
     pointer. A login form's one interactive element has to be exactly
     where a user expects it and clickable without a run-up; the earlier
     pointer-orbited 3-D version of this page violated that and was torn
     back out. Everything below only ever changes on a fixed timeline
     (video playback reaching 4.05s, or the fail-safe timers), the same
     for every visitor, every time.

     Layers, back to front:
       #hastra-kage-scene   the shared temple world (assets/js/kage-scene.js)
       #temple-hand-stage  stage 1: the hand rising out of it on video
       #crack-particles    stage 2a: the spark burst at the fracture
       .auth-wrapper        stage 2b: the login card

     Stage 1 — the hand rises out of the temple's fog and settles, playing
     until the crack forms in the palm at 4.05s, then freezes there. The
     clip is matted — login-hand-alpha.webm carries its own alpha, the
     night sky keyed out on chroma, the cloud kept as mist around the
     wrist — so the temple shows straight through it; a browser without
     VP9 alpha falls back to the plain clip and no-alpha's mix-blend-mode
     screens it into the scene instead. It starts small and low (scale
     .45, anchored bottom-centre) and dim, growing to full size and
     brightness over the same span the temple-alone beat hands off into
     — no blur on it or on the temple: a blurred, mostly-transparent
     layer that covers nearly the whole screen would visibly soften
     whatever shows through it too, which is the temple.

     Stage 2 — the crack sparks (#crack-particles, a one-shot CSS burst,
     no draw loop) and the card grows out of that point along its own
     depth axis (translateZ + rotateX under .auth-wrapper's perspective),
     while .auth-wrapper itself never moves: it is a full-viewport flex
     box that keeps the card dead centre at every instant regardless of
     what the card's own transform is doing. ── */
  #temple-hand-stage { position: fixed; inset: 0; z-index: 5; pointer-events: none; }
  /* Contained to the temple's own gate, not the whole screen. object-fit:
     cover at 100vw/100vh (the earlier version) meant "grow to scale(1)"
     was "grow to fill the entire viewport" — that's the "so big" the hand
     became. The box below is fixed at an arch-sized footprint, centred on
     roughly where the torii gate sits in the scene (49%/58%, the same
     point the vignette, sparks and card all already anchor to); only the
     content INSIDE that fixed box scales up, so the ceiling is "as big as
     the gate", never "as big as the screen". */
  /* the box: fixed size and position, never itself animated. #bg-video
     (a separate element inside it, not the same node) does the actual
     scale/filter growth — the box is what makes "big as the gate, never
     big as the screen" a hard limit rather than a starting value. */
  .video-bg {
    position: fixed;
    left: 49%; top: 58%;
    width: min(60vw, 78vh);
    aspect-ratio: 3 / 4;
    transform: translate(-50%, -50%);
    pointer-events: none;
    opacity: 0;
    overflow: hidden;
    transition: opacity 1.2s ease;
  }
  .video-bg.is-rising { opacity: 1; }
  /* No blur: the video is mostly-transparent (the alpha-matted mist and
     sky), so a blur on it would smear whatever shows through it too,
     including the temple. Brightness alone (dim at rest, lifting as it
     rises) still reads as "in the shadow, then in the light". */
  #bg-video {
    display: block; width: 100%; height: 100%; object-fit: contain;
    pointer-events: none; background: transparent;
    transform: scale(.4) translateY(10%);
    filter: brightness(.4) contrast(1.15) saturate(.82) drop-shadow(0 0 40px rgba(224, 35, 28, .22));
    transition: transform 3.4s cubic-bezier(.22, .61, .36, 1), filter 3.4s cubic-bezier(.22, .61, .36, 1);
  }
  /* settled state: contained to the box, and clearly lit rather than
     perpetually dim — the dimness above is only for the temple-alone beat
     before this class lands */
  .video-bg.is-rising #bg-video { transform: scale(1) translateY(0); filter: brightness(.96) contrast(1.02) saturate(.98) drop-shadow(0 0 40px rgba(224, 35, 28, .22)); }
  [data-theme="light"] #bg-video { filter: brightness(.55) contrast(1.06) saturate(.65) sepia(.1) drop-shadow(0 0 36px rgba(254, 215, 102, .4)); }
  [data-theme="light"] .video-bg.is-rising #bg-video { filter: brightness(1.05) contrast(1) saturate(1.08) sepia(.04) drop-shadow(0 0 36px rgba(254, 215, 102, .4)); }
  #bg-video.no-alpha { mix-blend-mode: screen; background: var(--navy); }
  [data-theme="light"] #bg-video.no-alpha { mix-blend-mode: multiply; background: var(--navy); }

  /* a light vignette to seat the card — soft enough that the temple and
     hand stay clearly visible around it, not blacked out at the edges */
  .video-overlay {
    position: fixed;
    inset: 0;
    z-index: 6;
    pointer-events: none;
    background: radial-gradient(circle at 49% 58%, rgba(var(--accent-rgb),.14) 0%, rgba(3,5,12,.3) 62%, rgba(3,5,12,.48) 100%);
    transition: background .6s ease;
  }
  [data-theme="light"] .video-overlay {
    background: radial-gradient(circle at 49% 58%, rgba(var(--accent-rgb),.18) 0%, rgba(6,10,24,.28) 62%, rgba(4,7,18,.44) 100%);
  }

  /* stage 2a: the crack burst — a fixed handful of CSS-animated sparks
     fired once at reveal, positioned at the same 49%/58% the vignette and
     the card's motion are both anchored to. No canvas, no draw loop: each
     spark is one <i>, one keyframe animation, play-state toggled by class. */
  #crack-particles { position: fixed; inset: 0; z-index: 8; pointer-events: none; }
  #crack-particles i {
    position: absolute; left: 49%; top: 58%; width: 5px; height: 5px; border-radius: 50%;
    margin: -2.5px 0 0 -2.5px; opacity: 0;
    background: radial-gradient(circle, #fff 0%, #d1e4fa 35%, var(--accent) 75%, transparent 100%);
    box-shadow: 0 0 10px 1px rgba(var(--accent-rgb),.8);
    animation-duration: .9s; animation-timing-function: cubic-bezier(.16,1,.3,1); animation-fill-mode: forwards;
  }
  #crack-particles.go i { animation-name: crackSpark; }
  @keyframes crackSpark {
    0%   { opacity: 1; transform: translate(0, 0) scale(1); }
    100% { opacity: 0; transform: translate(var(--dx), var(--dy)) scale(.2); }
  }

  /* stage 2b: the card. .auth-wrapper is the stable frame — full
     viewport, flex-centred, never itself animated — so the card is
     always exactly where a user expects a login form, no matter what
     its own transform is doing. Only .auth-card moves, and only along
     its own depth axis back to that centred position. */
  .auth-wrapper {
    position: fixed; inset: 0; z-index: 20;
    display: flex; align-items: center; justify-content: center;
    padding: 1.5rem; pointer-events: none;
    perspective: 1400px;
  }
  .card {
    position: relative;
    z-index: 1;
    background: var(--navy-card);
    border: 1px solid var(--border);
    border-radius: 4px;
    width: 100%;
    max-width: 420px;
    /* On a short viewport (a phone in landscape, a small/split window)
       the card can be taller than the screen, and without a cap here its
       top (logo/header) and bottom (Sign In button) render off-screen
       with no way to reach them. Capping the height and scrolling
       internally — the standard centered-dialog pattern — keeps every
       field reachable. overscroll-behavior:contain stops that internal
       scroll from bubbling into a page-level bounce on touch devices. */
    max-height: calc(100vh - 2rem);
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
    padding: 1.3rem 2rem 1.1rem;
    box-shadow: 0 0 0 1px rgba(0,0,0,0.06), 0 20px 60px rgba(0,0,0,0.5), 0 0 40px var(--accent-glow);
    opacity: 0;
    /* recessed into the fracture: small, pushed back along z, tipped
       away, dark and blurred */
    transform: scale(0.1) translate3d(0, 40px, -280px) rotateX(18deg);
    filter: blur(20px) brightness(.4);
    pointer-events: none;
    transition: transform 1.3s cubic-bezier(0.16, 1, 0.3, 1),
                opacity 1s ease-out,
                filter 1s ease-out,
                background .5s ease, border-color .5s ease;
  }
  /* Set from JS the instant the video pauses on the crack frame (or the
     fail-safe timer fires) — see the script near the end of the page. */
  .card.revealed {
    opacity: 1;
    transform: scale(1) translate3d(0, 0, 0) rotateX(0deg);
    filter: blur(0) brightness(1);
    pointer-events: auto;
  }
  /* A tab that has already sat through the full entrance once (sessionStorage
     astra_login_seen — see the script near the end of the page: logging out
     and back in, or a mistyped password kicking back to this same page)
     gets a quick, simple fade instead of the full temple/hand/launch
     sequence — the cinematic is a first impression, not a tax on every
     retry. */
  .card.quick {
    transition: transform .4s ease-out, opacity .35s ease-out, filter .35s ease-out,
                background .5s ease, border-color .5s ease;
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
    animation: hastraShockwave 1.1s cubic-bezier(.16, 1, .3, 1) forwards;
  }
  @keyframes hastraShockwave {
    0%   { box-shadow: 0 0 0 0 var(--accent-glow); opacity: .9; }
    55%  { opacity: .4; }
    100% { box-shadow: 0 0 0 70px transparent; opacity: 0; }
  }

  /* Inside the card: icon pops in, then "Hastra" projects outward from it
     — the exact same hastraTextIntro/hastraIconPop keyframes the portal nav
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
    animation: hastraIconPop .45s .3s cubic-bezier(.34,1.56,.64,1) forwards;
  }
  .card.revealed .brand-text .title {
    animation: hastraTextIntro .6s .6s cubic-bezier(.16,1,.3,1) forwards;
  }
  .card.revealed h2,
  .card.revealed .subtitle,
  .card.revealed .divider,
  .card.revealed form,
  .card.revealed .footer-links,
  .card.revealed .sys-row {
    animation: hastraFadeUp .55s 1s cubic-bezier(.16,1,.3,1) forwards;
  }

  @media (prefers-reduced-motion: reduce) {
    .card { transition: opacity .3s ease; transform: none !important; filter: none !important; }
    .card.revealed { transform: none; }
    .card.revealed::after { animation: none; }
    .card .brand-icon, .card .brand-text .title,
    .card h2, .card .subtitle, .card .divider, .card form, .card .footer-links, .card .sys-row {
      opacity: 1 !important; transform: none !important; animation: none !important;
    }
    #crack-particles i { animation: none !important; opacity: 0 !important; }
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
    border-color: var(--accent) !important;
    background: rgba(199, 211, 234, 0.06) !important;
    box-shadow: 0 0 0 3px rgba(var(--accent-rgb), 0.18) !important;
  }

  .card button[type="submit"], .card .btn-login, .card .btn-send,
  .card .btn-submit, .card .btn-primary, .card a.btn-primary,
  [data-theme="light"] .card button[type="submit"], [data-theme="light"] .card .btn-login,
  [data-theme="light"] .card .btn-send, [data-theme="light"] .card .btn-submit,
  [data-theme="light"] .card .btn-primary, [data-theme="light"] .card a.btn-primary {
    background: var(--accent) !important;
    color: #ffffff !important;
    border-color: var(--accent) !important;
    border-radius: var(--radius-control) !important;
    font-family: var(--font-sans) !important;
    font-weight: 600 !important;
    letter-spacing: .02em !important;
    text-transform: none !important;
    box-shadow: 0 8px 24px -8px rgba(var(--accent-rgb), 0.5) !important;
    transition: transform .15s, box-shadow .15s, background .15s !important;
  }
  .card button[type="submit"]:hover, .card .btn-login:hover, .card .btn-send:hover,
  .card .btn-submit:hover, .card .btn-primary:hover, .card a.btn-primary:hover,
  [data-theme="light"] .card button[type="submit"]:hover, [data-theme="light"] .card .btn-login:hover,
  [data-theme="light"] .card .btn-send:hover, [data-theme="light"] .card .btn-submit:hover,
  [data-theme="light"] .card .btn-primary:hover, [data-theme="light"] .card a.btn-primary:hover {
    background: var(--accent-dim) !important;
    box-shadow: 0 8px 28px -6px rgba(var(--accent-rgb), 0.7) !important;
    transform: translateY(-1px);
  }

  [data-theme="light"] .card input[type="email"], [data-theme="light"] .card input[type="password"],
  [data-theme="light"] .card input[type="text"], [data-theme="light"] .card input[type="tel"] {
    background: #f8fafc !important; border-color: #cbd5e1 !important; color: #0f172a !important;
  }
  [data-theme="light"] .card input[type="email"]::placeholder, [data-theme="light"] .card input[type="password"]::placeholder,
  [data-theme="light"] .card input[type="text"]::placeholder, [data-theme="light"] .card input[type="tel"]::placeholder {
    color: #5f6c82 !important;
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
    background: var(--accent);
    color: #fff; box-shadow: 0 6px 16px -6px rgba(var(--accent-rgb), 0.6);
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

  /* ── This page's own cursor engine (assets/js/hybrid-hand-cursor.js is
     opted out via <body data-cursor-off>): a visible pointer ring plus a
     camera-space-styled wisp trail, restored here specifically per a later
     request — every other page keeps the plain, simplified motes-only
     trail. #hastra-wisp-mesh is the trail's own canvas, z-index 10 between
     the hand stage and the card; .cur-dot is the ring, z-index 80, always
     on top. Both are pointer-events:none — see requirement 3. ── */
  #hastra-wisp-mesh { position: fixed; inset: 0; z-index: 10; pointer-events: none; }
  .cur-dot {
    position: fixed; z-index: 80; top: 0; left: 0; width: 26px; height: 26px; margin: -13px 0 0 -13px;
    border: 1px solid rgba(223, 231, 224, 0.42); border-radius: 50%; pointer-events: none;
    transition: width 0.35s cubic-bezier(0.16, 1, 0.3, 1), height 0.35s cubic-bezier(0.16, 1, 0.3, 1),
                margin 0.35s cubic-bezier(0.16, 1, 0.3, 1), background 0.35s, border-color 0.35s, opacity 0.3s;
    opacity: 0;
  }
  .cur-dot.act {
    width: 52px; height: 52px; margin: -26px 0 0 -26px;
    background: rgba(223, 231, 224, 0.07); border-color: rgba(223, 231, 224, 0.6);
  }
  @media (hover: hover) and (pointer: fine) { .cur-dot { opacity: 1; } }
  @media (prefers-reduced-motion: reduce) { .cur-dot, #hastra-wisp-mesh { display: none; } }

</style>
</head>
<body data-intro-manual data-cursor-off>

<!-- Cinematic background: a giant hand opening out of the clouds, full
     screen, over the same temple scene every other page stands on
     (assets/js/kage-scene.js, mounted by core/theme.js since this body
     has no data-atmosphere-scene-off). The video covers it while it
     plays; the temple is what's left once the video's opacity or object
     no longer covers the frame, and it costs nothing extra to keep
     mounted underneath. Purely decorative, so it's hidden from assistive
     tech; the fail-safes in the script below guarantee the card appears
     even if this never plays. -->
<div id="temple-hand-stage">
<!-- .video-bg is the fixed-size, fixed-position arch box; #bg-video is a
     separate element inside it that does its own scale/filter growth.
     They used to be the same element (class and id on one <video>) and
     fought each other's transform — the ID's always won, so the box's own
     centering transform never applied at all. -->
<div class="video-bg">
<!-- no autoplay: playback starts from JS after RISE_DELAY, once the
     temple has had its own beat on screen (see the script near the end
     of the page) — the native attribute would start it immediately and
     race ahead of that delay --><video id="bg-video" muted playsinline preload="auto" aria-hidden="true">
  <source src="<?= get_base_url() ?>assets/video/login-hand-alpha.webm" type='video/webm; codecs="vp9"'>
  <source src="<?= get_base_url() ?>assets/video/login-reveal.mp4" type="video/mp4">
</video>
</div>
</div>
<div class="video-overlay"></div>

<!-- stage 2a: the crack burst. Angles and radii are fixed server-side
     (--dx/--dy per spark) so the sparks fan out in a real radial pattern
     with zero JS math; .go (added at the same moment .card.revealed is)
     is the only thing that ever starts the animation. -->
<div id="crack-particles" aria-hidden="true">
<?php for ($i = 0; $i < 18; $i++):
    $angle = deg2rad($i * 20 + (($i % 3) * 5));
    $radius = 70 + ($i % 3) * 34;
    $dx = round(cos($angle) * $radius, 1);
    $dy = round(sin($angle) * $radius * .8, 1); // flattened vertically to match the card's wider stance
?><i style="--dx:<?= $dx ?>px; --dy:<?= $dy ?>px; animation-delay:<?= round(($i % 5) * .02, 2) ?>s;"></i><?php endfor; ?>
</div>

<!-- this page's own cursor engine (see the <style> block and the script
     near the end of the page): the wisp trail's canvas, and the ring -->
<canvas id="hastra-wisp-mesh" aria-hidden="true"></canvas>
<div class="cur-dot" id="cursor" aria-hidden="true"></div>

<div class="auth-wrapper">
<div class="card" id="auth-card">

  <!-- Brand -->
  <div class="brand">
    <div class="brand-icon">
      <svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <defs>
          <linearGradient id="hastraMark" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="var(--accent-bright)"/>
            <stop offset="1" stop-color="var(--purple, var(--accent-bright))"/>
          </linearGradient>
        </defs>
        <!-- Torii-post H frame -->
        <path fill="url(#hastraMark)" d="M8 5H16V43H8V5ZM32 5H40V43H32V5ZM4 21H44V27H4V21Z"/>
        <!-- Integrated core spark -->
        <path fill="url(#hastraMark)" d="M24 15L30 24L24 33L18 24Z"/>
      </svg>
    </div>
    <div class="brand-text">
      <div class="title">Hastra</div>
      <div class="sub">Secure Access Portal</div>
    </div>
  </div>

  <!-- Portal toggle -->
  <div class="portal-toggle" role="tablist" aria-label="Choose sign-in portal">
    <button type="button" id="toggleEnterprise" class="<?= $active_portal === 'enterprise' ? 'active' : '' ?>" role="tab" aria-controls="panel-enterprise" aria-selected="<?= $active_portal === 'enterprise' ? 'true' : 'false' ?>">Enterprise Workspace</button>
    <button type="button" id="toggleClient" class="<?= $active_portal === 'client' ? 'active' : '' ?>" role="tab" aria-controls="panel-client" aria-selected="<?= $active_portal === 'client' ? 'true' : 'false' ?>">Client Gateway</button>
  </div>

  <div class="login-viewport">
    <div class="login-track<?= $active_portal === 'client' ? ' show-client' : '' ?>" id="loginTrack">

      <!-- ── Enterprise Workspace panel ── -->
      <div class="login-panel" id="panel-enterprise" role="tabpanel" aria-labelledby="toggleEnterprise">
        <h2>Sign In</h2>
        <p class="subtitle">Enter your workspace credentials to access Hastra.</p>
        <div class="divider"></div>

        <?php if ($msg && ($active_portal === 'enterprise' || $session_notice)): ?>
        <div class="alert" role="alert">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
          <?= htmlspecialchars($msg) ?>
        </div>
        <?php endif; ?>

        <?php astra_google_button('link'); astra_sso_divider(); ?>

        <form method="POST" action="signin" class="loginForm" autocomplete="on">
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
            <a href="forgot-password" class="forgot-link">Forgot password?</a>
          </div>

          <div class="recaptcha-wrap">
            <div class="recaptcha-slot" id="recaptchaContainer-enterprise"></div>
          </div>

          <button type="submit" class="btn-login">Sign In</button>
        </form>

        <div class="footer-links">
          <span>New organization?</span>
          <a href="signup?track=enterprise">Set up your workspace</a>
        </div>
      </div>

      <!-- ── Client Gateway panel ── -->
      <div class="login-panel" id="panel-client" role="tabpanel" aria-labelledby="toggleClient">
        <h2>Client Sign In</h2>
        <p class="subtitle">Access the project workspace your company was onboarded into.</p>
        <div class="divider"></div>

        <?php if ($msg && ($active_portal === 'client' || $session_notice)): ?>
        <div class="alert" role="alert">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
          <?= htmlspecialchars($msg) ?>
        </div>
        <?php endif; ?>

        <?php astra_google_button('link'); astra_sso_divider(); ?>

        <form method="POST" action="signin" class="loginForm" autocomplete="on">
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
            <a href="forgot-password" class="forgot-link">Forgot password?</a>
          </div>

          <div class="recaptcha-wrap">
            <div class="recaptcha-slot" id="recaptchaContainer-client"></div>
          </div>

          <button type="submit" class="btn-login">Sign In</button>
        </form>

        <div class="footer-links">
          <span>New client company?</span>
          <a href="signup">Create your workspace</a>
        </div>
      </div>

    </div>
  </div>

  <!-- System status -->
  <div class="sys-row" style="display:flex; align-items:center; justify-content:flex-end; margin-top:0.7rem;">
  <button type="button" id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle" aria-label="Switch between light and dark theme">
    <span class="theme-icon" aria-hidden="true"></span>
    <span class="theme-label"></span>
  </button>
</div>

<?php astra_legal_footer('card'); ?>

</div>
</div>

<script>
// ── Cinematic reveal: freeze the video the instant the crack opens (before
//    the trees sprout) and zoom the card out of that rupture ──
(function() {
  const video     = document.getElementById('bg-video');
  const videoBox  = video && video.closest('.video-bg');
  const card      = document.querySelector('.card');
  const particles = document.getElementById('crack-particles');
  const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const SEEN_KEY = 'astra_login_seen';
  let alreadySeen = false;
  try { alreadySeen = sessionStorage.getItem(SEEN_KEY) === '1'; } catch (e) { /* private mode */ }
  let revealed = false;

  function reveal() {
    if (revealed) return;
    revealed = true;
    // Freeze right here regardless of how we got here (crack frame or the
    // fail-safe) — the card must never emerge while trees are sprouting.
    if (video) video.pause();
    card.classList.add('revealed');
    // the spark burst: a one-shot CSS animation, started the same instant
    if (particles && !reduceMotion && !alreadySeen) particles.classList.add('go');
    try { sessionStorage.setItem(SEEN_KEY, '1'); } catch (e) { /* private mode */ }
  }

  if (reduceMotion || alreadySeen) {
    // Reduced motion: skip the video motion entirely, no autoplaying
    // background to fight with. A tab that already sat through the full
    // entrance once this session: same idea, but with a quick fade
    // instead of an instant snap, and the video never even starts —
    // nothing left to see behind the card that hasn't already been seen.
    card.classList.add('quick');
    if (video) { video.pause(); video.removeAttribute('autoplay'); video.style.display = 'none'; }
    if (alreadySeen && !reduceMotion) window.setTimeout(reveal, 120);
    else reveal();
  } else if (video) {
    const setSpeed = () => { video.playbackRate = 1.5; };
    setSpeed();
    video.addEventListener('loadedmetadata', setSpeed);
    // The alpha clip is what lets the temple show through; a browser that
    // fell back to the plain mp4 has no alpha to give and is screened into
    // the scene instead (.no-alpha, in the CSS above).
    video.addEventListener('loadeddata', function() {
      if (/\.mp4(\?|$)/.test(video.currentSrc)) video.classList.add('no-alpha');
    });

    // The temple gets its own beat first: nothing here starts until
    // RISE_DELAY after the page's own loader (core/theme.js) has actually
    // cleared — before that the video sits at opacity 0, scaled down, so
    // whatever's on screen is the scene behind it, not this timer racing
    // a full-screen loading spinner nobody can see past. Counting
    // RISE_DELAY from script-parse time instead (this script runs near
    // the top of <body>, well before 'load') was the bug: it expired
    // while the opaque loader was still covering everything, so by the
    // time a real visitor could see anything the hand had already grown
    // in — there was never a beat where the temple stood alone.
    const RISE_DELAY = 1300;   /* + the loader's own 300ms fade = 1.6s, phase 1's length */
    function startRise() {
      if (revealed) return;
      // .is-rising goes on the box (fades it in) — the CSS descendant
      // selector .video-bg.is-rising #bg-video then handles the video's
      // own scale/filter growth inside it
      if (videoBox) videoBox.classList.add('is-rising');
      const p = video.play();
      if (p && p.catch) p.catch(reveal);
      // A hand that never starts (blocked autoplay, bad codec, slow
      // network) must never keep the form away.
      window.setTimeout(function() {
        if (!revealed && (video.paused || video.currentTime < 0.15)) reveal();
      }, 2500);
    }
    function afterLoader(fn, delay) {
      // 300ms: the loader's own fade-out (core/theme.js hides it in 260ms)
      const go = () => window.setTimeout(fn, delay + 300);
      if (document.readyState === 'complete') go();
      else window.addEventListener('load', go, { once: true });
    }
    afterLoader(startRise, RISE_DELAY);

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

  // Last resort: whatever happens to the scene or the clip, the form is
  // on screen within eight seconds.
  if (!video) reveal();
  window.setTimeout(reveal, 8000);
})();

// ── This page's own cursor engine: the ring (.cur-dot) and a wisp trail in
//    the same visual language as the shared engine's texWisp() falloff
//    sprites and camera-space drift — built as a 2-D canvas rather than a
//    real three.js camera (this page has no scene of its own to hang
//    particles in), so it reads the same without adding a WebGL dependency
//    just for a cursor. assets/js/hybrid-hand-cursor.js is opted out for
//    this page via <body data-cursor-off> in core/theme.js. ──────────────
(function () {
  'use strict';
  const REDUCE = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const COARSE = matchMedia('(hover: none)').matches || matchMedia('(pointer: coarse)').matches;
  if (REDUCE || COARSE) return;

  const dot = document.getElementById('cursor');
  const canvas = document.getElementById('hastra-wisp-mesh');
  if (!dot || !canvas) return;
  const cx = canvas.getContext('2d');
  const lerp = (a, b, t) => a + (b - a) * t;

  /* ---------------------------------------------------- the ring */
  let dx = innerWidth / 2, dy = innerHeight / 2, tx = dx, ty = dy;
  addEventListener('pointermove', e => { tx = e.clientX; ty = e.clientY; }, { passive: true });
  (function tick() {
    dx = lerp(dx, tx, .18); dy = lerp(dy, ty, .18);
    dot.style.transform = `translate3d(${dx.toFixed(1)}px,${dy.toFixed(1)}px,0)`;
    requestAnimationFrame(tick);
  })();
  /* every clickable thing in the card, the portal tabs and the theme
     toggle swells the ring — the spec's [data-cursor] convention, applied
     here by selector instead of by hand-annotating every element */
  const CURSOR_TARGETS = 'a, button, input[type="checkbox"], input[type="email"], input[type="password"], ' +
                          '.portal-toggle button, .checkbox-wrap, [role="tab"], #themeToggleBtn';
  document.querySelectorAll(CURSOR_TARGETS).forEach(el => {
    el.addEventListener('mouseenter', () => dot.classList.add('act'));
    el.addEventListener('mouseleave', () => dot.classList.remove('act'));
  });

  /* ---------------------------------------------------- the wisp trail
     texWisp()'s falloff, ported to a canvas sprite: a hard bright core in
     a soft halo, tinted per mote. Frost Glow / accent-red at night;
     additive light vanishes into a white page by day, so it paints over
     instead, in accent-blue and a deeper steel. */
  function sprite(rgb) {
    const S = 64, c = document.createElement('canvas'); c.width = c.height = S;
    const x = c.getContext('2d'), g = x.createRadialGradient(S / 2, S / 2, 0, S / 2, S / 2, S / 2);
    const [r, gg, b] = rgb;
    g.addColorStop(0, 'rgba(255,255,255,1)');
    g.addColorStop(.07, `rgba(${r},${gg},${b},.92)`);
    g.addColorStop(.16, `rgba(${r},${gg},${b},.40)`);
    g.addColorStop(.34, `rgba(${r},${gg},${b},.13)`);
    g.addColorStop(.62, `rgba(${r},${gg},${b},.035)`);
    g.addColorStop(1, `rgba(${r},${gg},${b},0)`);
    x.fillStyle = g; x.fillRect(0, 0, S, S);
    return c;
  }
  const NIGHT = [sprite([209, 228, 250]), sprite([224, 46, 60])];
  const DAY = [sprite([2, 101, 220]), sprite([63, 92, 140])];
  let day = false;
  const readTheme = () => { day = document.documentElement.getAttribute('data-theme') === 'light'; };
  readTheme();
  new MutationObserver(readTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

  const N = 190, STEP = .030 * 900;      /* the shared engine's camera-space STEP, rescaled into CSS pixels */
  const W = { list: [], i: 0, acc: 0, ex: 0, ey: 0, lx: 0, ly: 0, idle: 0, seen: false, quiet: false };
  for (let i = 0; i < N; i++) W.list.push({ life: 1, max: 1, x: 0, y: 0, vx: 0, vy: 0, sz: 0, ph: 0, c: 0 });
  let dpr = 1, raf = 0, running = false, tPrev = 0, clock = 0;

  function size() {
    dpr = Math.min(devicePixelRatio || 1, 2);
    canvas.width = Math.round(innerWidth * dpr); canvas.height = Math.round(innerHeight * dpr);
  }
  function spawn(x, y, a, weak) {
    const w = W.list[W.i]; W.i = (W.i + 1) % N;
    w.x = x + (Math.random() + Math.random() - 1) * 10;
    w.y = y + (Math.random() + Math.random() - 1) * 10;
    w.life = 0; w.max = (weak ? 2.1 : 1.45) + Math.random() * 1.3;
    w.vx = -Math.cos(a) * 24 + (Math.random() - .5) * 90;
    w.vy = -Math.sin(a) * 24 + (Math.random() - .5) * 80 + 5;
    w.sz = (weak ? 12 : 16) + Math.random() * 16;
    w.ph = Math.random() * Math.PI * 2;
    w.c = Math.random() < .62 ? 0 : 1;
  }
  function frame(now) {
    const dt = Math.min((now - tPrev) / 1000 || 0, .05); tPrev = now; clock += dt;
    W.ex += (tx - W.ex) * Math.min(1, 16 * dt); W.ey += (ty - W.ey) * Math.min(1, 16 * dt);
    const ddx = W.ex - W.lx, ddy = W.ey - W.ly, moved = Math.hypot(ddx, ddy);
    const ang = moved > 1e-5 ? Math.atan2(ddy, ddx) : 0;
    W.acc += moved;
    let guard = 0;
    while (W.acc >= STEP && guard++ < 14) {
      W.acc -= STEP;
      const t = moved > 1e-6 ? Math.min(1, guard * STEP / moved) : 0;
      spawn(W.lx + ddx * t, W.ly + ddy * t, ang, false);
    }
    W.idle += dt;
    if (W.idle > .42 && W.seen) { W.idle = 0; spawn(W.ex, W.ey, Math.random() * Math.PI * 2, true); }
    W.lx = W.ex; W.ly = W.ey;

    cx.setTransform(1, 0, 0, 1, 0, 0);
    cx.clearRect(0, 0, canvas.width, canvas.height);
    cx.globalCompositeOperation = day ? 'source-over' : 'lighter';
    const set = day ? DAY : NIGHT;
    let live = 0;
    for (const w of W.list) {
      if (w.life >= w.max) continue;
      live++;
      w.life += dt;
      const u = w.life / w.max;
      w.x += (w.vx + Math.sin(clock * 1.3 + w.ph) * 14) * dt;
      w.y += (w.vy + Math.cos(clock * 1.1 + w.ph * 1.7) * 12) * dt;
      w.vx *= 1 - .5 * dt; w.vy = w.vy * (1 - .5 * dt) - 4 * dt;
      const smooth = (a, b, x) => { const t = Math.min(1, Math.max(0, (x - a) / (b - a))); return t * t * (3 - 2 * t); };
      const a = smooth(0, .12, u) * (1 - smooth(.22, 1, u)) * (day ? .7 : .9);
      if (a <= .003) continue;
      const px = w.sz * (1 + u * .55) * dpr;
      cx.globalAlpha = a;
      cx.drawImage(set[w.c], w.x * dpr - px / 2, w.y * dpr - px / 2, px, px);
    }
    cx.globalAlpha = 1;
    if (!live && moved < 1e-4 && W.idle > .21 && W.quiet) { running = false; return; }
    raf = requestAnimationFrame(frame);
  }
  function wake() { if (running || document.hidden) return; running = true; tPrev = performance.now(); raf = requestAnimationFrame(frame); }
  size(); addEventListener('resize', size, { passive: true });
  let still = 0;
  addEventListener('pointermove', e => {
    if (!W.seen) { W.ex = W.lx = e.clientX; W.ey = W.ly = e.clientY; W.seen = true; }
    W.quiet = false; clearTimeout(still);
    still = setTimeout(() => { W.quiet = true; }, 2000);
    wake();
  }, { passive: true });
  document.addEventListener('visibilitychange', () => { if (document.hidden) { running = false; cancelAnimationFrame(raf); } });
})();

// ── Two-portal sliding gate ─────────────────────────────────────────────────
const loginTrack       = document.getElementById('loginTrack');
const toggleEnterprise  = document.getElementById('toggleEnterprise');
const toggleClient      = document.getElementById('toggleClient');

const panelEnterprise  = document.getElementById('panel-enterprise');
const panelClient      = document.getElementById('panel-client');

function setActivePortal(portal, focusTab) {
  const client = portal === 'client';
  loginTrack.classList.toggle('show-client', client);
  toggleEnterprise.classList.toggle('active', !client);
  toggleClient.classList.toggle('active', client);
  toggleEnterprise.setAttribute('aria-selected', client ? 'false' : 'true');
  toggleClient.setAttribute('aria-selected', client ? 'true' : 'false');
  // roving tabindex, and the off-screen panel leaves the tab order entirely
  toggleEnterprise.tabIndex = client ? -1 : 0;
  toggleClient.tabIndex = client ? 0 : -1;
  panelEnterprise.inert = client;
  panelClient.inert = !client;
  if (focusTab) (client ? toggleClient : toggleEnterprise).focus();
}
toggleEnterprise.addEventListener('click', function() { setActivePortal('enterprise'); });
toggleClient.addEventListener('click', function() { setActivePortal('client'); });
[toggleEnterprise, toggleClient].forEach(function(tab) {
  tab.addEventListener('keydown', function(e) {
    if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(e.key) === -1) return;
    e.preventDefault();
    const toClient = e.key === 'End' || (e.key !== 'Home' && tab === toggleEnterprise);
    setActivePortal(toClient ? 'client' : 'enterprise', true);
  });
});
setActivePortal(loginTrack.classList.contains('show-client') ? 'client' : 'enterprise');

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
      sitekey: '6LfuhdEtAAAAAOHipza25gYF5igkq4TV2iagaCAN',
      theme: currentSiteTheme()
    });
  });
}
window.onRecaptchaApiLoad = renderRecaptcha;
document.addEventListener('hastra:themechange', function() {
  // The reCAPTCHA client doesn't reliably repaint the new theme on the
  // first re-render right after a live theme toggle — a follow-up render
  // shortly after consistently fixes it, so do both.
  setTimeout(renderRecaptcha, 80);
  setTimeout(renderRecaptcha, 350);
});
</script>

<?php astra_consent_banner(); ?>
<!-- the ten-rings bracelets on the hand (assets/js/shang-chi-rings.js) -->
<script type="module" src="<?= get_base_url() ?>assets/js/login-rings.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>