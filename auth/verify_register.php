<?php
// auth/verify_register.php
include __DIR__ . '/../core/db.php';
secure_session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../PHPMailer/PHPMailer.php';
require __DIR__ . '/../PHPMailer/SMTP.php';
require __DIR__ . '/../PHPMailer/Exception.php';

$msg      = "";
$msg_type = "error";
$stage    = "email"; // "email" or "otp"
$email    = "";

if (!isset($_SESSION["verify_otp_attempts"])) {
    $_SESSION["verify_otp_attempts"] = 0;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    verify_csrf_token();

    $action = $_POST["action"] ?? "";

    // ── STEP 1: email submitted, send a fresh OTP ────────────────────────────
    if ($action === "send_otp") {
        $email = trim($_POST["email"] ?? "");

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = "Please enter a valid email address.";
        } else {
            $check = mysqli_prepare($conn, "SELECT id, name FROM pending_registrations WHERE email = ?");
            mysqli_stmt_bind_param($check, "s", $email);
            mysqli_stmt_execute($check);
            $result  = mysqli_stmt_get_result($check);
            $pending = mysqli_fetch_assoc($result);

            if (!$pending) {
                // Check if already a verified user, to give a clearer message
                $ucheck = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
                mysqli_stmt_bind_param($ucheck, "s", $email);
                mysqli_stmt_execute($ucheck);
                mysqli_stmt_store_result($ucheck);

                if (mysqli_stmt_num_rows($ucheck) > 0) {
                    $msg = "This email is already verified. <a href='login'>Log in here</a>.";
                } else {
                    $msg = "No pending registration found for this email. <a href='register'>Register here</a>.";
                }
            } else {
                $otp = rand(100000, 999999);

                $update = mysqli_prepare($conn, "UPDATE pending_registrations SET otp = ?, otp_expiry = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE email = ?");
                mysqli_stmt_bind_param($update, "ss", $otp, $email);
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
                    $mail->Subject = 'Verify your email';
                    $mail->Body    = "Hi {$pending['name']},\n\nYour verification OTP is: $otp\n\nThis OTP will expire in 10 minutes.\n\nIf you did not request this, please ignore this email.";

                    $mail->send();

                    $_SESSION["verify_email"]       = $email;
                    $_SESSION["verify_otp_attempts"] = 0;
                    $stage = "otp";
                } catch (Exception $e) {
                    $msg = "Could not send OTP. Please try again.";
                }
            }
        }
    }

    // ── STEP 2: resend OTP (same as send_otp but keeps user on otp stage) ────
    if ($action === "resend_otp") {
        $email = $_SESSION["verify_email"] ?? "";

        if ($email === "") {
            $msg = "Session expired. Please enter your email again.";
        } else {
            $check = mysqli_prepare($conn, "SELECT id, name FROM pending_registrations WHERE email = ?");
            mysqli_stmt_bind_param($check, "s", $email);
            mysqli_stmt_execute($check);
            $pending = mysqli_fetch_assoc(mysqli_stmt_get_result($check));

            if (!$pending) {
                $msg   = "This registration no longer exists. Please register again.";
                unset($_SESSION["verify_email"]);
            } else {
                $otp = rand(100000, 999999);

                $update = mysqli_prepare($conn, "UPDATE pending_registrations SET otp = ?, otp_expiry = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE email = ?");
                mysqli_stmt_bind_param($update, "ss", $otp, $email);
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
                    $mail->Subject = 'Verify your email';
                    $mail->Body    = "Hi {$pending['name']},\n\nYour verification OTP is: $otp\n\nThis OTP will expire in 10 minutes.\n\nIf you did not request this, please ignore this email.";

                    $mail->send();

                    $_SESSION["verify_otp_attempts"] = 0;
                    $msg      = "A new OTP has been sent to your email.";
                    $msg_type = "success";
                    $stage    = "otp";
                } catch (Exception $e) {
                    $msg   = "Could not resend OTP. Please try again.";
                    $stage = "otp";
                }
            }
        }
    }

    // ── STEP 3: OTP submitted ─────────────────────────────────────────────────
    if ($action === "verify_otp") {
        $email        = $_SESSION["verify_email"] ?? "";
        $entered_otp  = trim($_POST["otp"] ?? "");

        if ($email === "") {
            $msg = "Session expired. Please enter your email again.";
        } else {
            $_SESSION["verify_otp_attempts"]++;

            if ($_SESSION["verify_otp_attempts"] > 5) {
                unset($_SESSION["verify_email"]);
                unset($_SESSION["verify_otp_attempts"]);
                $msg = "Too many failed attempts. Please start over.";
            } else {
                $stmt = mysqli_prepare($conn, "SELECT * FROM pending_registrations WHERE email = ? AND otp = ? AND otp_expiry > NOW()");
                mysqli_stmt_bind_param($stmt, "ss", $email, $entered_otp);
                mysqli_stmt_execute($stmt);
                $result  = mysqli_stmt_get_result($stmt);
                $pending = mysqli_fetch_assoc($result);

                if ($pending) {
                    // Move from pending_registrations into users
                    // The person who registers a company becomes its IT Manager.
                    // Their company gets its own email domain and ID block.
                    $company_name = trim($pending["company_name"] ?? "");
                    $company      = null;
                    $blocked      = false;
                    if (company_schema_ready($conn) && $company_name !== "") {
                        if (find_company_by_name($conn, $company_name)) {
                            $blocked = true;
                        } else {
                            $company = create_company($conn, $company_name, null, [
                                'size_band'    => $pending["company_size"] ?? null,
                                'contract_ref' => ($pending["contract_ref"] ?? '') !== '' ? $pending["contract_ref"] : null,
                            ]);
                        }
                    }

                    $new_user = [
                        'name'         => $pending["name"],
                        'email'        => $pending["email"],
                        'phone_number' => $pending["phone_number"],
                        'password'     => $pending["password"],
                        'role'         => $pending["role"],
                    ];
                    if (onboarding_schema_ready($conn)) {
                        $new_user['client_role'] = $company ? 'it_manager' : 'pm';
                    }
                    $new_user_id = $blocked ? null : insert_user_in_company($conn, $company, $new_user);

                    if ($blocked) {
                        $delete_pending = mysqli_prepare($conn, "DELETE FROM pending_registrations WHERE id = ?");
                        mysqli_stmt_bind_param($delete_pending, "i", $pending["id"]);
                        mysqli_stmt_execute($delete_pending);
                        unset($_SESSION["verify_email"], $_SESSION["verify_otp_attempts"]);
                        $msg   = htmlspecialchars($company_name) . " was registered on Astra while you were verifying. Ask its IT Manager to add you to the team.";
                        $stage = "email";
                    } elseif ($new_user_id) {
                        if ($company) {
                            $owner = mysqli_prepare($conn, "UPDATE companies SET user_id = ? WHERE id = ?");
                            mysqli_stmt_bind_param($owner, "ii", $new_user_id, $company["id"]);
                            mysqli_stmt_execute($owner);
                            if (onboarding_schema_ready($conn)) {
                                $itm = mysqli_prepare($conn, "UPDATE companies SET it_manager_id = ? WHERE id = ?");
                                mysqli_stmt_bind_param($itm, "ii", $new_user_id, $company["id"]);
                                mysqli_stmt_execute($itm);
                            }
                        } elseif (!company_schema_ready($conn) && $company_name !== "") {
                            $company_insert = mysqli_prepare($conn, "INSERT INTO companies (user_id, company_name) VALUES (?, ?)");
                            mysqli_stmt_bind_param($company_insert, "is", $new_user_id, $company_name);
                            mysqli_stmt_execute($company_insert);
                        }

                        $delete_pending = mysqli_prepare($conn, "DELETE FROM pending_registrations WHERE id = ?");
                        mysqli_stmt_bind_param($delete_pending, "i", $pending["id"]);
                        mysqli_stmt_execute($delete_pending);

                        unset($_SESSION["verify_email"]);
                        unset($_SESSION["verify_otp_attempts"]);

                        $stage    = "done";
                    } else {
                        $msg   = "Verification succeeded but account creation failed. Please contact support.";
                        $stage = "otp";
                    }
                } else {
                    $remaining = 5 - $_SESSION["verify_otp_attempts"];
                    $msg       = "Invalid or expired OTP. $remaining attempt(s) remaining.";
                    $stage     = "otp";
                }
            }
        }
    }
}

// On GET (or after a failed POST action that should stay on otp stage), figure out which stage to show
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    if (isset($_SESSION["verify_email"])) {
        $stage = "otp";
        $email = $_SESSION["verify_email"];
    }
} elseif (isset($_SESSION["verify_email"]) && $stage !== "done") {
    $email = $_SESSION["verify_email"];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Email · Astra</title>
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
    font-family: 'Inter', sans-serif;
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

  .alert.success {
    background: var(--green-bg);
    border-color: var(--green);
    color: #86efac;
  }

  .alert a { text-decoration: underline; }
  .alert.error a   { color: #fca5a5; }
  .alert.success a { color: #86efac; }

  .alert svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; }
  .alert.error svg   { fill: var(--red); }
  .alert.success svg { fill: var(--green); }

  .btn-send {
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
  }

  .btn-send:hover {
    background: #2563eb;
    box-shadow: 0 0 20px rgba(var(--accent-rgb),0.3);
  }

  .btn-send:disabled {
    background: #1e3a5f;
    color: var(--text-dim);
    cursor: not-allowed;
    box-shadow: none;
  }

  /* OTP boxes */
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

  #otpHidden { display: none; }

  .attempts-bar {
    display: flex;
    gap: 6px;
    justify-content: center;
    margin-bottom: 6px;
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

  .resend-link {
    text-align: center;
    margin-top: 1rem;
    font-size: 13px;
    color: var(--text-dim);
  }

  .resend-link button {
    background: none;
    border: none;
    color: var(--accent-bright);
    font-size: 13px;
    cursor: pointer;
    text-decoration: underline;
    padding: 0;
  }

  .resend-link button:hover { color: var(--accent-bright); }

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

  /* Success state */
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
      <div class="sub">Email Verification</div>
    </div>
  </div>

  <?php if ($stage === 'done'): ?>

  <!-- ── SUCCESS STATE ── -->
  <div class="success-wrap">
    <div class="success-icon">
      <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
    </div>
    <h3>Email Verified!</h3>
    <p>Your account is now active.<br>You can log in with your email and password.</p>
    <a href="login" class="btn-login">
      <svg viewBox="0 0 24 24"><path d="M11 7L9.6 8.4l2.6 2.6H2v2h10.2l-2.6 2.6L11 17l5-5-5-5z"/></svg>
      Go to Login
    </a>
  </div>

  <?php elseif ($stage === 'otp'): ?>

  <!-- ── OTP STAGE ── -->
  <h2>Enter Verification Code</h2>
  <p class="subtitle">
    A 6-digit code was sent to<br>
    <span><?= htmlspecialchars($email) ?></span>
  </p>
  <div class="divider"></div>

  <?php if ($msg): ?>
  <div class="alert <?= $msg_type === 'success' ? 'success' : 'error' ?>">
    <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
    <span><?= $msg ?></span>
  </div>
  <?php endif; ?>

  <div class="attempts-bar">
    <?php for ($i = 0; $i < 5; $i++): ?>
      <div class="attempt-dot <?= ($i < ($_SESSION["verify_otp_attempts"] ?? 0)) ? 'used' : '' ?>"></div>
    <?php endfor; ?>
  </div>
  <div class="attempts-label">
    <?= $_SESSION["verify_otp_attempts"] ?? 0 ?> / 5 ATTEMPTS USED
  </div>

  <form method="POST" action="verify_register" id="otpForm">
    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
    <input type="hidden" name="action" value="verify_otp">
    <input type="hidden" name="otp" id="otpHidden">

    <div class="otp-wrap">
      <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="b0">
      <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="b1">
      <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="b2">
      <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="b3">
      <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="b4">
      <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="b5">
    </div>

    <button type="submit" class="btn-send" id="verifyBtn" disabled>Verify Email</button>
  </form>

  <div class="resend-link">
    Didn't get it?
    <form method="POST" action="verify_register" style="display:inline;">
      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
      <input type="hidden" name="action" value="resend_otp">
      <button type="submit">Resend OTP</button>
    </form>
  </div>

  <div style="display:flex; align-items:center; justify-content:center; margin-top:1rem; padding-top:0.8rem; border-top:1px solid rgba(255,255,255,0.07);">
    <button id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle">
      <span class="theme-icon"></span>
      <span class="theme-label"></span>
    </button>
  </div>
  <div class="footer-links">
    <a href="login">Back to Login</a>
  </div>

  <?php else: ?>

  <!-- ── EMAIL STAGE ── -->
  <h2>Verify Your Email</h2>
  <p class="subtitle">Enter the email you registered with to receive a verification code.</p>
  <div class="divider"></div>

  <?php if ($msg): ?>
  <div class="alert error">
    <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
    <span><?= $msg ?></span>
  </div>
  <?php endif; ?>

  <form method="POST" action="verify_register">
    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
    <input type="hidden" name="action" value="send_otp">

    <div class="field">
      <label for="email">Email Address</label>
      <input type="email" name="email" id="email"
             maxlength="100" required
             placeholder="you@example.com"
             autofocus>
    </div>

    <button type="submit" class="btn-send">Send Verification Code</button>
  </form>

  <div style="display:flex; align-items:center; justify-content:center; margin-top:1rem; padding-top:0.8rem; border-top:1px solid rgba(255,255,255,0.07);">
    <button id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle">
      <span class="theme-icon"></span>
      <span class="theme-label"></span>
    </button>
  </div>
  <div class="footer-links">
    <span>Already verified?</span>
    <a href="login">Back to Login</a>
  </div>

  <?php endif; ?>

</div>

<script>
const boxes     = Array.from(document.querySelectorAll('.otp-box'));
const hidden    = document.getElementById('otpHidden');
const verifyBtn = document.getElementById('verifyBtn');

if (boxes.length) {
  boxes.forEach((box, idx) => {
    box.addEventListener('input', () => {
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

    box.addEventListener('paste', (e) => {
      e.preventDefault();
      const pasted = (e.clipboardData || window.clipboardData)
        .getData('text').replace(/[^0-9]/g, '').slice(0, 6);
      pasted.split('').forEach((char, i) => {
        if (boxes[i]) { boxes[i].value = char; boxes[i].classList.add('filled'); }
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

  document.getElementById('otpForm').addEventListener('submit', syncHidden);
  boxes[0].focus();
}
</script>

</body>
</html>