<?php
// auth/register.php
include __DIR__ . '/../core/db.php';
secure_session_start();
require_once __DIR__ . '/../core/countries.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../PHPMailer/PHPMailer.php';
require __DIR__ . '/../PHPMailer/SMTP.php';
require __DIR__ . '/../PHPMailer/Exception.php';

$msg = "";
if ($_SERVER["REQUEST_METHOD"] === "GET") $msg = htmlspecialchars(astra_google_error_message());

if (isset($_SESSION["user_id"])) {
    switch ($_SESSION["user_role"]) {
        case "sysadmin":         header("Location: " . APP_URL . "workspace/sysadmin/"); break;
        case "admin":            header("Location: " . APP_URL . "workspace/admin/");       break;
        case "employee":         header("Location: " . APP_URL . "workspace/employee/"); break;
        case "pending_employee": header("Location: " . APP_URL . "workspace/user/newuser-portal");      break;
        default:                 header("Location: " . APP_URL . "workspace/user/newuser-portal");      break;
    }
    exit();
}

// Four self-serve tracks (auth/login.php's "Ask your admin for an invite"
// dead end is gone — Enterprise Workspace now provisions its own company
// and admin account here, same as Client Gateway always has):
//   client_company     — Client Gateway → Company
//   client_individual  — Client Gateway → Individual / Freelancer
//   enterprise_full    — Enterprise Workspace → Full Organization
//   enterprise_solo    — Enterprise Workspace → Solo Enterprise / Developer
$VALID_FLOWS = ['client_company', 'client_individual', 'enterprise_full', 'enterprise_solo'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    verify_csrf_token();

    $flow = $_POST["flow"] ?? "client_company";
    if (!in_array($flow, $VALID_FLOWS, true)) $flow = "client_company";

    $needs_org_name  = in_array($flow, ['client_company', 'enterprise_full', 'enterprise_solo'], true);
    $needs_org_size  = in_array($flow, ['client_company', 'enterprise_full'], true);
    $needs_country   = in_array($flow, ['client_individual', 'enterprise_solo'], true);
    $needs_leave     = $flow === 'enterprise_full';
    $role            = in_array($flow, ['enterprise_full', 'enterprise_solo'], true) ? 'admin' : 'client';

    $name         = trim($_POST["name"] ?? "");
    $email        = trim($_POST["email"] ?? "");
    $phone        = trim($_POST["phone_number"] ?? "");
    $password     = $_POST["password"] ?? "";
    $confirm      = $_POST["confirm"] ?? "";
    $country      = trim($_POST["country"] ?? "");
    $company_name = $needs_org_name ? trim($_POST["company_name"] ?? "") : ($name !== "" ? "$name (Individual)" : "");
    $company_size = $needs_org_size ? trim($_POST["company_size"] ?? "") : "";
    $contract_ref = ($flow === 'client_company') ? trim($_POST["contract_ref"] ?? "") : "";
    $leave_cycle  = in_array($_POST["leave_cycle"] ?? "", ["monthly", "yearly_rollover"], true) ? $_POST["leave_cycle"] : "monthly";
    $gen_leaves   = max(0, min(30, (int)($_POST["monthly_general_leaves"] ?? 1)));
    $sick_leaves  = max(0, min(30, (int)($_POST["monthly_sick_leaves"] ?? 1)));
    $annual_leave = max(0, min(90, (int)($_POST["annual_leave_allowance"] ?? 18)));
    $existing_co  = $company_name !== "" ? find_company_by_name($conn, $company_name) : null;

    if (($_POST["accept_terms"] ?? "") !== "1") {
        $msg = "Please accept the Terms and Conditions and acknowledge the Privacy Policy to continue.";
    } elseif ($name === "" || $email === "" || $phone === "" || $password === "" || $confirm === "") {
        $msg = "All required fields must be filled.";
    } elseif ($needs_org_name && $company_name === "") {
        $msg = $flow === 'enterprise_solo' ? "Enter your studio or developer name." : "Enter your organization's name.";
    } elseif ($needs_org_size && !isset(COMPANY_SIZES[$company_size])) {
        $msg = "Choose your company's size.";
    } elseif ($needs_country && !astra_country_valid($country)) {
        $msg = "Choose your country from the list.";
    } elseif (mb_strlen($contract_ref) > 60) {
        $msg = "The contract reference can be at most 60 characters.";
    } elseif ($existing_co) {
        $msg = htmlspecialchars($existing_co["company_name"]) . " is already registered on Hastra. Ask its admin or IT Manager to add you to the team.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "Please enter a valid email address.";
    } elseif (!preg_match('/^\+[1-9]\d{6,14}$/', $phone)) {
        $msg = "Please enter a valid phone number.";
    } elseif ($password !== $confirm) {
        $msg = "Passwords do not match.";
    } elseif (strlen($password) < 8) {
        $msg = "Password must be at least 8 characters.";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $msg = "Password must contain at least 1 uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $msg = "Password must contain at least 1 lowercase letter.";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $msg = "Password must contain at least 1 number.";
    } elseif (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        $msg = "Password must contain at least 1 special character.";
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email_bindex = ?");
        $email_bindex_check = astra_blind_index($email);
        mysqli_stmt_bind_param($stmt, "s", $email_bindex_check);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);

        [$pcol, $pval] = pending_reg_key($conn, $email);
        $stmt2 = mysqli_prepare($conn, "SELECT id FROM pending_registrations WHERE $pcol = ?");
        mysqli_stmt_bind_param($stmt2, "s", $pval);
        mysqli_stmt_execute($stmt2);
        mysqli_stmt_store_result($stmt2);

        if (mysqli_stmt_num_rows($stmt) > 0) {
            $msg = "An account with that email already exists.";
        } elseif (mysqli_stmt_num_rows($stmt2) > 0) {
            $msg = "A verification is already pending for this email. <a href='verify_register'>Verify here</a>.";
        } else {
            $hashed    = password_hash($password, PASSWORD_ARGON2ID);
            $otp       = rand(100000, 999999);
            $logo_data = trim($_POST["logo_data"] ?? "");
            if (mb_strlen($logo_data) > 900000) $logo_data = ""; // guard against an oversized payload
            $has_leave_cols = db_column_exists($conn, 'pending_registrations', 'monthly_general_leaves');
            $has_logo_col   = db_column_exists($conn, 'pending_registrations', 'logo_data');
            $has_flow_col   = db_column_exists($conn, 'pending_registrations', 'flow');

            $cols = ['name', 'email', 'phone_number', 'password', 'role', 'company_name'];
            $vals = [$name, $email, $phone, $hashed, $role, $company_name];
            $types = 'ssssss';
            if (pending_reg_encrypted($conn)) {
                // PII encrypted at rest until the account is verified.
                $vals[0] = astra_db_encrypt($name);
                $vals[1] = astra_db_encrypt($email);
                $vals[2] = astra_db_encrypt($phone);
                $cols[] = 'email_bindex'; $vals[] = astra_blind_index($email); $types .= 's';
            }

            if (onboarding_schema_ready($conn)) {
                $cols[] = 'company_size'; $vals[] = $company_size; $types .= 's';
                $cols[] = 'contract_ref'; $vals[] = $contract_ref; $types .= 's';
            }
            if ($has_leave_cols && $needs_leave) {
                $cols[] = 'leave_cycle';             $vals[] = $leave_cycle;  $types .= 's';
                $cols[] = 'monthly_general_leaves';  $vals[] = $gen_leaves;   $types .= 'i';
                $cols[] = 'monthly_sick_leaves';     $vals[] = $sick_leaves;  $types .= 'i';
                $cols[] = 'annual_leave_allowance';  $vals[] = $annual_leave; $types .= 'i';
            }
            if ($has_logo_col && $logo_data !== '') {
                $cols[] = 'logo_data'; $vals[] = $logo_data; $types .= 's';
            }
            if ($has_flow_col) {
                $cols[] = 'flow'; $vals[] = $flow; $types .= 's';
                $cols[] = 'country'; $vals[] = $country; $types .= 's';
            }
            if (db_column_exists($conn, 'pending_registrations', 'terms_version')) {
                $cols[] = 'terms_version'; $vals[] = LEGAL_TERMS_VERSION; $types .= 's';
            }
            $cols[] = 'otp'; $vals[] = $otp; $types .= 's';

            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $terms_col = db_column_exists($conn, 'pending_registrations', 'terms_accepted_at');
            $sql = "INSERT INTO pending_registrations (" . implode(', ', $cols) . ", otp_expiry" . ($terms_col ? ", terms_accepted_at" : "") . ")
                    VALUES ($placeholders, DATE_ADD(NOW(), INTERVAL 10 MINUTE)" . ($terms_col ? ", NOW()" : "") . ")";
            $pend_insert = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($pend_insert, $types, ...$vals);

            if (mysqli_stmt_execute($pend_insert)) {
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
                    $mail->Subject = 'Verify your email';
                    $mail->Body    = "Hi $name,\n\nThank you for registering!\n\nYour email verification OTP is: $otp\n\nThis OTP will expire in 10 minutes.\n\nIf you did not register, please ignore this email.";

                    $mail->send();

                    $_SESSION["verify_email"] = $email;
                    header("Location: " . APP_URL . "verify-email");
                    exit();

                } catch (Exception $e) {
                    $del = mysqli_prepare($conn, "DELETE FROM pending_registrations WHERE $pcol = ?");
                    mysqli_stmt_bind_param($del, "s", $pval);
                    mysqli_stmt_execute($del);
                    $msg = "Could not send verification email. Please try again.";
                }
            } else {
                $msg = "Registration failed. Please try again.";
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
<title>Create your account · Hastra</title>
<?php astra_seo_meta(['title' => 'Create your account · Hastra', 'description' => 'Create a Hastra client or enterprise workspace.', 'path' => 'signup', 'robots' => 'noindex, follow']); ?>
<?php astra_compliance_css(); ?>
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/hastra-select.css?v=<?= ASSET_VERSION ?>">
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-authkit.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/css/intlTelInput.css">
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/intlTelInput.min.js"></script>
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
    max-width: 1040px;
    padding: 0;
    box-shadow: 0 0 0 1px rgba(var(--accent-rgb),0.08), 0 20px 60px rgba(0,0,0,0.5), 0 0 40px var(--accent-glow);
    overflow: hidden;
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
    margin-bottom: 1.8rem;
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
    margin-bottom: 1.6rem;
  }

  .divider {
    height: 1px;
    background: var(--border-dim);
    margin-bottom: 1.6rem;
  }

  .row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
  }

  .field { margin-bottom: 1.1rem; }

  label {
    display: block;
    font-size: 11px;
    font-weight: 500;
    color: var(--text-dim);
    letter-spacing: 0.07em;
    text-transform: uppercase;
    margin-bottom: 6px;
  }

  label .optional {
    text-transform: none;
    color: var(--text-dim);
    font-weight: 400;
    letter-spacing: 0;
  }

  .input-wrap { position: relative; }

  input[type="text"],
  input[type="email"],
  input[type="tel"],
  input[type="password"],
  select {
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

  select { cursor: pointer; }
  select option { background: var(--navy-card); color: var(--text); }

  input:focus, select:focus {
    border-color: var(--accent-bright);
    box-shadow: 0 0 0 3px rgba(var(--accent-rgb),0.12);
  }

  input::placeholder { color: var(--text-dim); }

  input[type="password"] { padding-right: 40px; }

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

  /* intl-tel-input theming to match Hastra's dark inputs */
  .iti { width: 100%; display: block; }
  /* .card input[...] sets padding-left:13px !important above, which would
     otherwise swallow the space intl-tel-input reserves for the flag/dial
     code — force enough room for it regardless. */
  #phone_number { padding-left: 88px !important; }
  .iti__flag-container { border-radius: 3px 0 0 3px; }
  .iti__selected-flag { background: transparent !important; border-radius: 3px 0 0 3px; }
  .iti__selected-flag:hover, .iti__selected-flag:focus { background: var(--hover-bg) !important; }
  .iti__country-list {
    background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px;
    box-shadow: 0 12px 28px -8px rgba(0,0,0,0.5); color: var(--text);
  }
  .iti__country { color: var(--text); }
  .iti__country.iti__highlight { background: var(--hover-bg); }
  .iti__divider { border-bottom: 1px solid var(--border-dim); }
  .iti__dial-code { color: var(--text-dim); }

  .req-box {
    background: var(--section-header-bg);
    border: 1px solid var(--border-dim);
    border-radius: 3px;
    padding: 10px 12px;
    margin-bottom: 1.1rem;
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

  .req-item.valid   { color: var(--green); }
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

  .btn-register {
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
    margin-top: 0.5rem;
  }

  .btn-register:hover {
    background: var(--accent-bright);
    box-shadow: 0 0 20px rgba(var(--accent-rgb),0.3);
  }

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

  .alert.error a { color: #fca5a5; text-decoration: underline; }

  .alert svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; fill: var(--red); }

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

  /* ── Registration track tabs + sub-toggle ── */
  .registration-tabs {
    display: flex; gap: 4px; background: rgba(127,127,127,.08);
    border: 1px solid var(--border-dim); border-radius: 8px; padding: 4px;
    margin-bottom: 12px;
  }
  .reg-tab {
    flex: 1 1 0; border: none; background: transparent; color: var(--text-dim);
    font-family: var(--font-sans); font-size: 13px; font-weight: 600;
    padding: 10px 8px; border-radius: 7px; cursor: pointer; transition: background .2s, color .2s;
  }
  .reg-tab.active {
    background: linear-gradient(135deg, var(--accent-bright), var(--accent));
    color: #fff; box-shadow: 0 6px 16px -6px var(--accent-glow);
  }
  .reg-tab:not(.active):hover { color: var(--text); }

  .registration-subtoggle {
    display: flex; gap: 4px; margin-bottom: 1.4rem;
  }
  .reg-sub {
    flex: 1 1 0; border: 1.5px solid var(--border-dim); background: var(--input-bg); color: var(--text-dim);
    font-family: var(--font-sans); font-size: 12.5px; font-weight: 600;
    padding: 9px 10px; border-radius: 8px; cursor: pointer; transition: border-color .2s, color .2s, background .2s;
  }
  .reg-sub.active { border-color: var(--accent-bright); color: var(--accent-bright); background: rgba(var(--accent-rgb),0.08); }
  .reg-sub:not(.active):hover { color: var(--text); }

  .row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
  @media (max-width: 560px) { .row-3 { grid-template-columns: 1fr; } }

  /* ── Dual-column stage ─────────────────────────────────────────────────
     Client Gateway (default): overview on the LEFT, card on the RIGHT.
     Enterprise: the card slides LEFT and the overview slides RIGHT, by a
     pixel-measured `translate` (650ms, cubic-bezier(0.16, 1, 0.3, 1)) that
     composes with everything else; only the overview text crossfades,
     mid-slide. Same mechanics as auth/login.php. ── */
  .reg-stage {
    --slide-ease: cubic-bezier(0.16, 1, 0.3, 1);
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 560px);
    column-gap: clamp(2rem, 5vw, 5rem);
    align-items: center;
    width: 100%; max-width: 1180px;
  }
  .reg-stage .card { grid-column: 2; grid-row: 1; max-width: 560px; }
  .reg-stage[data-portal="enterprise"] .card      { translate: calc(-1 * var(--card-shift, 0px)) 0; }
  .reg-stage[data-portal="enterprise"] .auth-info { translate: var(--info-shift, 0px) 0; }
  .reg-stage.ready .card { transition: translate 650ms var(--slide-ease); }
  .auth-info {
    grid-column: 1; grid-row: 1; justify-self: start; max-width: 34rem; min-width: 0; color: var(--text);
    /* the card is tall (multi-step form), so keep the overview in view while it scrolls */
    align-self: start; position: sticky; top: max(2rem, 12vh);
  }
  .reg-stage.ready .auth-info { transition: translate 650ms var(--slide-ease); }
  .info-stack { display: grid; }
  .info-block {
    grid-area: 1 / 1;
    opacity: 0; scale: .98; visibility: hidden;
    transition: opacity .3s ease, scale .3s ease, visibility 0s .3s;
  }
  .info-block.is-active {
    opacity: 1; scale: 1; visibility: visible;
    transition: opacity .35s ease .3s, scale .45s var(--slide-ease) .3s, visibility 0s 0s;
  }
  .info-badge {
    display: inline-flex; align-items: center; gap: .55rem; padding: .35rem .8rem;
    border: 1px solid var(--border-dim); border-radius: 999px;
    font: 600 .72rem/1 var(--font-sans); letter-spacing: .14em; text-transform: uppercase; color: var(--text-dim);
  }
  .info-badge i { width: .45rem; height: .45rem; border-radius: 50%; background: var(--accent-bright); box-shadow: 0 0 10px 2px rgba(var(--accent-rgb), .8); }
  .info-title { margin: 1.1rem 0 0; font: 800 clamp(2.4rem, 5vw, 4.2rem)/1 var(--font-sans); letter-spacing: .06em; text-transform: uppercase; color: var(--text); }
  .info-title .info-portal { display: block; margin-top: .6rem; font-size: clamp(.95rem, 1.6vw, 1.25rem); font-weight: 700; letter-spacing: .22em; color: var(--accent-bright); }
  .info-sub { margin: 1.4rem 0 0; font: 600 1.15rem/1.4 var(--font-sans); color: var(--text); }
  .info-body { margin: .9rem 0 0; font: 400 1rem/1.7 var(--font-sans); color: var(--text-dim); }
  .info-feats { margin: 1.3rem 0 0; padding: 0; list-style: none; display: grid; gap: .55rem; }
  .info-feats li { position: relative; padding-left: 1.3rem; font: 500 .92rem/1.5 var(--font-sans); color: var(--text); }
  .info-feats li::before { content: ''; position: absolute; left: 0; top: .6em; width: .5rem; height: 1px; background: var(--accent-bright); }
  .info-flow { margin-top: 1.5rem; padding: .9rem 1rem; border-left: 2px solid var(--accent-bright); font: 400 .88rem/1.6 var(--font-sans); color: var(--text-dim); }
  .info-flow b { display: block; color: var(--text); font-weight: 600; margin-bottom: .25rem; }
  .card-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; padding: 1.4rem 2.8rem 0; }
  .card-head .brand { margin-bottom: 0; }
  @media (max-width: 1000px) {
    .reg-stage { grid-template-columns: minmax(0, 1fr); row-gap: 1.5rem; max-width: 560px; }
    .auth-info, .reg-stage .card { grid-column: 1; justify-self: center; width: 100%; }
    .auth-info { grid-row: 1; text-align: center; }
    .reg-stage .card { grid-row: 2; }
    .info-title { font-size: 2rem; margin-top: .8rem; }
    .info-body, .info-feats, .info-flow { display: none; }
    .card-head { padding: 1.2rem 1.6rem 0; }
  }
  @media (prefers-reduced-motion: reduce) {
    .reg-stage.ready .card, .reg-stage.ready .auth-info, .info-block, .info-block.is-active { transition-duration: .01s !important; transition-delay: 0s !important; }
  }

  .sheet-steps { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: row; flex-wrap: wrap; gap: .5rem 1.4rem; }
  .sheet-steps li {
    display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-dim);
    transition: color 0.2s;
  }
  .sheet-steps .step-num {
    width: 24px; height: 24px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-family: 'Share Tech Mono', monospace; font-size: 11px;
    border: 1.5px solid var(--border-dim); color: var(--text-dim);
    transition: background 0.2s, border-color 0.2s, color 0.2s;
  }
  .sheet-steps li.current { color: var(--text); font-weight: 600; }
  .sheet-steps li.current .step-num,
  .sheet-steps li.done .step-num { background: var(--accent-bright); border-color: var(--accent-bright); color: #fff; }
  .sheet-steps li.done { color: var(--text-dim); }

  .navbar-preview {
    margin-top: 1rem; padding-top: .4rem;
  }
  .navbar-preview .np-label { font-size: 10.5px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 8px; }
  .navbar-preview .np-bar {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    background: rgba(127,127,127,0.08); border: 1px solid var(--border-dim); border-radius: 8px;
    padding: 10px 14px;
  }
  .navbar-preview .np-bar .np-hastra { font-family: 'Share Tech Mono', monospace; font-size: 11px; color: var(--text-dim); letter-spacing: 0.06em; }
  .navbar-preview .np-logo {
    width: 26px; height: 26px; border-radius: 50%; object-fit: cover;
    border: 1px solid var(--border-dim); background: #fff;
  }
  .navbar-preview .np-logo-placeholder {
    width: 26px; height: 26px; border-radius: 50%; border: 1.5px dashed var(--border-dim);
    display: flex; align-items: center; justify-content: center; color: var(--text-dim); font-size: 10px;
  }

  .sheet-main { padding: 2.6rem 2.8rem; }
  @media (max-width: 800px) { .sheet-main { padding: 2rem 1.6rem; } }

  .step-panel { display: none; }
  .step-panel.active { display: block; animation: stepFadeIn 0.35s ease; }
  @keyframes stepFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }

  .step-actions { display: flex; gap: 10px; margin-top: 1.4rem; }
  .step-actions .btn-back {
    flex: 0 0 auto; background: transparent; border: 1px solid var(--border-dim); color: var(--text-dim);
    border-radius: 7px; padding: 11px 18px; font-family: var(--font-sans); font-weight: 600; font-size: 13.5px;
    cursor: pointer; transition: border-color 0.2s, color 0.2s;
  }
  .step-actions .btn-back:hover { border-color: var(--accent-bright); color: var(--text); }
  .step-actions .btn-register { margin-top: 0; }

  /* ── Logo finder ── */
  .logo-finder { margin-top: 0.4rem; }
  .logo-finder-label {
    font-size: 11px; font-weight: 500; color: var(--text-dim); letter-spacing: 0.07em;
    text-transform: uppercase; margin-bottom: 8px; display: flex; align-items: center; gap: 8px;
  }
  .logo-finder-label .spinner {
    width: 11px; height: 11px; border-radius: 50%; border: 2px solid var(--border-dim);
    border-top-color: var(--accent-bright); animation: spin 0.7s linear infinite; display: none;
  }
  .logo-finder-label.searching .spinner { display: inline-block; }
  @keyframes spin { to { transform: rotate(360deg); } }

  .logo-tiles { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; }
  .logo-tile {
    width: 64px; height: 64px; border-radius: 50%; border: 2px solid var(--border-dim);
    background: #fff; cursor: pointer; padding: 10px; display: flex; align-items: center; justify-content: center;
    transition: border-color 0.15s, transform 0.15s, box-shadow 0.15s;
  }
  .logo-tile img { width: 100%; height: 100%; object-fit: contain; }
  .logo-tile:hover { transform: translateY(-2px); }
  .logo-tile.selected {
    border-color: var(--accent-bright);
    box-shadow: 0 0 0 3px rgba(var(--accent-rgb),0.18), 0 0 20px -4px rgba(var(--accent-rgb),0.5);
  }

  .logo-dropzone {
    border: 1.5px dashed var(--border-dim); border-radius: 8px; padding: 1.2rem;
    display: flex; align-items: center; gap: 12px; cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
  }
  .logo-dropzone:hover, .logo-dropzone.drag-over { border-color: var(--accent-bright); background: rgba(var(--accent-rgb),0.05); }
  .logo-dropzone .dz-icon {
    width: 38px; height: 38px; border-radius: 8px; flex-shrink: 0;
    background: rgba(var(--accent-rgb),0.12); display: flex; align-items: center; justify-content: center;
    color: var(--accent-bright);
  }
  .logo-dropzone .dz-icon svg { width: 18px; height: 18px; }
  .logo-dropzone .dz-text { font-size: 12.5px; color: var(--text-dim); line-height: 1.5; }
  .logo-dropzone .dz-text b { color: var(--text); }
  .logo-dropzone .dz-preview { width: 44px; height: 44px; border-radius: 50%; object-fit: contain; background: #fff; border: 1px solid var(--border-dim); display: none; }
  .logo-dropzone.has-file .dz-preview { display: block; }

  .logo-status { font-size: 12px; color: var(--text-dim); margin-top: 8px; min-height: 16px; }

  /* Touch screens: every control is at least 48 px tall; nothing scrolls sideways */
  html, body { overflow-x: hidden; }
  @media (pointer: coarse) {
    .card button, .card select, .card input:not([type="checkbox"]):not([type="radio"]):not([type="range"]),
    .portal-toggle button, .reg-tab, .reg-sub, .btn-theme-toggle { min-height: 48px; }
    .card .footer-links a, .card .forgot-link, .checkbox-wrap { display: inline-flex; align-items: center; min-height: 48px; }
    .card input[type="checkbox"] { min-width: 24px; min-height: 24px; }
  }
  html.low-perf-mode .card { backdrop-filter: none !important; -webkit-backdrop-filter: none !important; }
</style>
</head>
<body>


<div class="reveal-wrap">
  <div class="glow"></div>
  <div class="pulse p1"></div>
  <div class="pulse p2"></div>
</div>

<div class="reg-stage" id="reg-stage" data-portal="client">

<!-- ── Overview: sits LEFT for Client Gateway, slides RIGHT for Enterprise ── -->
<aside class="auth-info" id="reg-info" aria-label="About the selected registration track">
  <div class="info-stack">
    <div class="info-block is-active" id="info-client" data-block="client">
      <span class="info-badge"><i></i>Client onboarding</span>
      <h1 class="info-title">Hastra<span class="info-portal">Client Gateway</span></h1>
      <p class="info-sub">Provision your secure client portal</p>
      <p class="info-body">Provision your secure client portal in seconds. Collaborate with engineering teams, trace code deliverables live, verify automated compliance reports, and access zero-trust audit records.</p>
      <ul class="info-feats">
        <li>Live deliverable and sprint tracking</li>
        <li>Automated compliance reports</li>
        <li>Tamper-evident audit records</li>
      </ul>
      <div class="info-flow"><b data-role="heading">Register your company</b><span data-role="intro">You'll be your company's IT Manager on Hastra. After signing in you can upload your team roster, invite everyone, and finish setting up your workspace.</span></div>
    </div>
    <div class="info-block" id="info-enterprise" data-block="enterprise" inert aria-hidden="true">
      <span class="info-badge"><i></i>Workspace provisioning</span>
      <h1 class="info-title">Hastra<span class="info-portal">Enterprise Deployment</span></h1>
      <p class="info-sub">Provision an enterprise security tenant</p>
      <p class="info-body">Provision an enterprise security tenant. Integrate custom STIX/TAXII threat intelligence pipelines, establish post-quantum encrypted vaults, and automate organization-wide SOC compliance.</p>
      <ul class="info-feats">
        <li>Multi-seat SOC workspace</li>
        <li>SIEM and threat-intelligence pipelines</li>
        <li>Post-quantum encrypted vaults and compliance specs</li>
      </ul>
      <div class="info-flow"><b data-role="heading">Set up your organization</b><span data-role="intro">You'll be the admin of your own Hastra workspace, ready immediately. Invite your team, configure leave policy, and start tracking delivery today.</span></div>
    </div>
  </div>
</aside>

<div class="card" id="reg-card">

  <div class="card-head">
    <div class="brand">
      <div class="brand-icon">
        <svg viewBox="0 0 48 48" role="img" aria-label="Hastra star brandmark logo"><defs><linearGradient id="hastraMark" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="var(--accent-bright)"/><stop offset="1" stop-color="var(--purple, var(--accent-bright))"/></linearGradient></defs><path fill="url(#hastraMark)" d="M8 5H16V43H8V5ZM32 5H40V43H32V5ZM4 21H44V27H4V21Z"/><path fill="url(#hastraMark)" d="M24 15L30 24L24 33L18 24Z"/></svg>
      </div>
      <div class="brand-text">
        <div class="title">Hastra</div>
      </div>
    </div>
    <ul class="sheet-steps" id="sheetSteps">
      <li class="current" data-step="1"><span class="step-num">1</span> <span id="step1Label">Workspace details</span></li>
      <li data-step="2"><span class="step-num">2</span> Your account</li>
    </ul>
  </div>

  <div class="sheet-main">

    <?php if ($msg): ?>
    <div class="alert error" role="alert">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
      <span><?= $msg ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" action="signup" id="registerForm">
      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
      <input type="hidden" name="logo_data" id="logoData" value="">
      <input type="hidden" name="flow" id="flowInput" value="client_company">

      <!-- ── STEP 1: Workspace details (fields shown vary by track) ── -->
      <div class="step-panel active" id="step1">

        <div class="registration-tabs" id="regTabs">
          <button type="button" class="reg-tab" data-tab="enterprise">Enterprise Workspace</button>
          <button type="button" class="reg-tab active" data-tab="client">Client Gateway</button>
        </div>
        <div class="registration-subtoggle" id="regSubtoggle">
          <button type="button" class="reg-sub active" data-sub="org">Company</button>
          <button type="button" class="reg-sub" data-sub="solo">Individual / Freelancer</button>
        </div>

        <h2 id="step1Heading">Tell us about your company</h2>
        <p class="subtitle" id="step1Subtitle">We'll try to find your brand logo automatically.</p>
        <div class="divider"></div>

        <div class="field" data-flows="client_company,enterprise_full,enterprise_solo">
          <label for="company_name" id="orgNameLabel">Company Name</label>
          <input type="text" name="company_name" id="company_name" maxlength="150" placeholder="Acme Inc."
                 value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>">
        </div>

        <div class="field" data-flows="client_company,enterprise_full">
          <label for="company_domain">Company Website <span class="optional">(optional, improves logo match)</span></label>
          <input type="text" id="company_domain" maxlength="150" placeholder="acme.com" autocomplete="off">
        </div>

        <div class="field logo-finder" data-flows="client_company,enterprise_full">
          <div class="logo-finder-label" id="logoFinderLabel"><span class="spinner"></span> Select your official brand logo</div>
          <div class="logo-tiles" id="logoTiles"></div>
          <div class="logo-dropzone" id="logoDropzone">
            <div class="dz-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V3m0 0L7 8m5-5l5 5"/><path d="M4 15v3a2 2 0 002 2h12a2 2 0 002-2v-3"/></svg>
            </div>
            <img class="dz-preview" id="dzPreview" alt="">
            <div class="dz-text"><b>Upload your logo</b><br>or drag a PNG/JPG/WebP here (max 700KB)</div>
            <input type="file" id="logoFileInput" accept="image/png,image/jpeg,image/webp,image/gif" style="display:none;">
          </div>
          <div class="logo-status" id="logoStatus"></div>
        </div>

        <div class="field" data-flows="client_company,enterprise_full">
          <label for="company_size">Company Size</label>
          <select name="company_size" id="company_size" data-ax-select>
            <option value="" disabled <?= empty($_POST['company_size']) ? 'selected' : '' ?>>Choose size</option>
            <?php foreach (COMPANY_SIZES as $val => $label): ?>
            <option value="<?= $val ?>" <?= ($_POST['company_size'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field" data-flows="client_company">
          <label for="contract_ref">Contract / PO Reference <span class="optional">(optional)</span></label>
          <input type="text" name="contract_ref" id="contract_ref" maxlength="60" placeholder="PO-2026-014"
                 value="<?= htmlspecialchars($_POST['contract_ref'] ?? '') ?>">
        </div>

        <div class="field" data-flows="client_individual,enterprise_solo">
          <label for="country">Country</label>
          <?php $picked_country = (string)($_POST['country'] ?? ''); ?>
          <select name="country" id="country" data-ax-select required>
            <option value="" disabled<?= astra_country_valid($picked_country) ? '' : ' selected' ?>>Select your country</option>
            <?php foreach (ASTRA_COUNTRIES as $iso => $country_name): ?>
            <option value="<?= htmlspecialchars($country_name) ?>" data-iso="<?= $iso ?>"<?= $picked_country === $country_name ? ' selected' : '' ?>><?= htmlspecialchars($country_name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field" data-flows="enterprise_full">
          <label for="leave_cycle">Employee Leave Policy</label>
          <select name="leave_cycle" id="leave_cycle" data-ax-select>
            <option value="monthly">Monthly allowance</option>
            <option value="yearly_rollover">Annual pool</option>
          </select>
        </div>
        <div class="row-3" data-flows="enterprise_full">
          <div class="field">
            <div class="ax-range-head"><label for="monthly_general_leaves">General / month</label><output for="monthly_general_leaves">1</output></div>
            <input type="range" class="ax-range" name="monthly_general_leaves" id="monthly_general_leaves" min="0" max="30" step="1" value="1">
          </div>
          <div class="field">
            <div class="ax-range-head"><label for="monthly_sick_leaves">Sick / month</label><output for="monthly_sick_leaves">1</output></div>
            <input type="range" class="ax-range" name="monthly_sick_leaves" id="monthly_sick_leaves" min="0" max="30" step="1" value="1">
          </div>
          <div class="field">
            <div class="ax-range-head"><label for="annual_leave_allowance">Annual total</label><output for="annual_leave_allowance">18</output></div>
            <input type="range" class="ax-range" name="annual_leave_allowance" id="annual_leave_allowance" min="0" max="90" step="1" value="18">
          </div>
        </div>
        <p class="logo-status" data-flows="enterprise_full" style="margin-top:-0.6rem;">A biometric attendance webhook secret is generated automatically. Find it under Attendance once you're signed in.</p>

        <div class="navbar-preview" data-flows="client_company,enterprise_full">
          <div class="np-label">Top bar preview</div>
          <div class="np-bar">
            <span class="np-hastra">HASTRA</span>
            <img id="navPreviewLogo" class="np-logo" style="display:none;" alt="Company logo preview">
            <span id="navPreviewPlaceholder" class="np-logo-placeholder">?</span>
          </div>
        </div>

        <div class="step-actions">
          <button type="button" class="btn-register" id="toStep2">Continue</button>
        </div>
      </div>

      <!-- ── STEP 2: Your account ── -->
      <div class="step-panel" id="step2">
        <h2 id="step2Heading">Your account</h2>
        <p class="subtitle" id="step2Subtitle">You'll sign in with this email once your company is set up.</p>
        <div class="divider"></div>

        <label class="consent-check">
          <input type="checkbox" name="accept_terms" id="accept_terms" value="1" required>
          <span>I accept the <a href="<?= get_base_url() ?>legal/terms" target="_blank" rel="noopener">Terms and Conditions</a> and acknowledge the <a href="<?= get_base_url() ?>legal/privacy" target="_blank" rel="noopener">Privacy Policy</a>.</span>
        </label>

        <?php astra_google_button('submit', 'Sign up with Google'); astra_sso_divider(); ?>

        <div class="row-2">
          <div class="field">
            <label for="name">Full Name</label>
            <input type="text" name="name" id="name" maxlength="100" required placeholder="John Doe">
          </div>
          <div class="field">
            <label for="email">Email</label>
            <input type="email" name="email" id="email" maxlength="100" required placeholder="you@example.com" autocomplete="email">
          </div>
        </div>

        <div class="field">
          <label for="phone_number">Phone Number</label>
          <input type="tel" name="phone_number" id="phone_number" required placeholder="9876543210" autocomplete="tel" inputmode="tel" maxlength="20">
        </div>

        <div class="field">
          <label for="password">Password</label>
          <div class="input-wrap">
            <input type="password" name="password" id="password"
                   maxlength="128" required placeholder="••••••••••••"
                   oninput="checkPassword(this.value)" autocomplete="new-password">
            <button type="button" class="eye-btn" onclick="toggleEye('password', 'eye1')" aria-label="Show or hide password">
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
          <div class="req-item" id="req_number"> <span class="dot"></span> Number (0-9) </div>
          <div class="req-item" id="req_special"><span class="dot"></span> Special character </div>
        </div>

        <div class="field">
          <label for="confirm">Confirm Password</label>
          <div class="input-wrap">
            <input type="password" name="confirm" id="confirm"
                   maxlength="128" required placeholder="••••••••••••"
                   oninput="checkMatch()" autocomplete="new-password">
            <button type="button" class="eye-btn" onclick="toggleEye('confirm', 'eye2')" aria-label="Show or hide password confirmation">
              <svg id="eye2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
                <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
                <line x1="1" y1="1" x2="23" y2="23"/>
              </svg>
            </button>
          </div>
          <div class="match-msg" id="matchMsg"></div>
        </div>

        <div class="step-actions">
          <button type="button" class="btn-back" id="toStep1">Back</button>
          <button type="submit" class="btn-register" style="flex:1 1 auto;">Create Account</button>
        </div>
      </div>
    </form>

    <div style="display:flex; align-items:center; justify-content:center; margin-top:1rem; padding-top:0.8rem; border-top:1px solid rgba(255,255,255,0.07);">
      <button type="button" id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle" aria-label="Switch between light and dark theme">
        <span class="theme-icon" aria-hidden="true"></span>
        <span class="theme-label"></span>
      </button>
    </div>
    <div class="footer-links">
      <span>Already have an account?</span>
      <a href="signin">Sign in</a>
    </div>
    <?php astra_legal_footer('card'); ?>
  </div>

</div>

</div>

<script>
const eyeOpenSVG   = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" fill="none"/>`;
const eyeClosedSVG = `<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/><line x1="1" y1="1" x2="23" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>`;

function toggleEye(inputId, iconId) {
  const input = document.getElementById(inputId);
  const icon  = document.getElementById(iconId);
  const show  = input.type === 'password';
  input.type  = show ? 'text' : 'password';
  icon.innerHTML = show ? eyeOpenSVG : eyeClosedSVG;
}

function setReq(id, passed) {
  const el = document.getElementById(id);
  el.className = 'req-item ' + (passed ? 'valid' : 'invalid');
}

function checkPassword(value) {
  setReq('req_length',  value.length >= 8);
  setReq('req_upper',   /[A-Z]/.test(value));
  setReq('req_lower',   /[a-z]/.test(value));
  setReq('req_number',  /[0-9]/.test(value));
  setReq('req_special', /[^a-zA-Z0-9]/.test(value));
  checkMatch();
}

function checkMatch() {
  const password = document.getElementById('password').value;
  const confirm  = document.getElementById('confirm').value;
  const msg      = document.getElementById('matchMsg');
  if (confirm.length === 0) { msg.textContent = ''; msg.className = 'match-msg'; return; }
  if (password === confirm) {
    msg.className   = 'match-msg ok';
    msg.textContent = '✓ Passwords match';
  } else {
    msg.className   = 'match-msg err';
    msg.textContent = '✗ Passwords do not match';
  }
}

// ── Phone input: flags, dial codes, IP-based country auto-detect ──────────────
const phoneInput = document.getElementById('phone_number');
const phoneIti = window.intlTelInput(phoneInput, {
  initialCountry: 'auto',
  preferredCountries: ['in', 'us', 'gb'],
  separateDialCode: true,
  utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/utils.js',
  geoIpLookup: function (callback) {
    fetch('<?= get_base_url() ?>api/geo_country.php')
      .then(function (r) { return r.json(); })
      .then(function (d) { callback(d.country || 'us'); })
      .catch(function () { callback('us'); });
  },
});

// Choosing a country switches the phone dial code to match.
(function () {
  const countrySelect = document.getElementById('country');
  if (!countrySelect) return;
  countrySelect.addEventListener('change', function () {
    const opt = countrySelect.selectedOptions[0];
    const iso = opt && opt.dataset.iso;
    if (iso) phoneIti.setCountry(iso.toLowerCase());
  });
})();

document.getElementById('registerForm').addEventListener('submit', function (e) {
  if (e.submitter && e.submitter.hasAttribute('data-google-sso')) {
    const terms = document.getElementById('accept_terms');
    if (!terms.checked) { e.preventDefault(); terms.reportValidity(); terms.focus(); }
    return;
  }
  if (phoneInput.value.trim() !== '') {
    phoneInput.value = phoneIti.getNumber(); // E.164, e.g. +919876543210
  }
});

// ── Step navigation ─────────────────────────────────────────────────────────
const step1 = document.getElementById('step1');
const step2 = document.getElementById('step2');
const stepsList = document.querySelectorAll('#sheetSteps li');

function goToStep(n) {
  step1.classList.toggle('active', n === 1);
  step2.classList.toggle('active', n === 2);
  stepsList.forEach(function (li) {
    const s = parseInt(li.dataset.step, 10);
    li.classList.toggle('current', s === n);
    li.classList.toggle('done', s < n);
  });
  if (n === 2) document.getElementById('name').focus();
}

document.getElementById('toStep2').addEventListener('click', function () {
  const step1Fields = step1.querySelectorAll('input:not([disabled]), select:not([disabled])');
  for (const f of step1Fields) { if (!f.reportValidity()) return; }
  goToStep(2);
});
document.getElementById('toStep1').addEventListener('click', function () { goToStep(1); });

// ── Registration track (tab) + sub-toggle (org vs solo) ─────────────────────
// Four flows: client_company, client_individual, enterprise_full, enterprise_solo.
// Every field that only applies to some flows is wrapped in an element with
// data-flows="flow1,flow2"; applyFlow() shows/hides + disables/enables those
// wrappers' inputs so hidden fields are neither validated nor submitted.
const regTabs      = document.querySelectorAll('.reg-tab');
const regSubs      = document.querySelectorAll('.reg-sub');
const flowInput    = document.getElementById('flowInput');
const orgNameLabel = document.getElementById('orgNameLabel');

const regStage       = document.getElementById('reg-stage');
const regInfo        = document.getElementById('reg-info');
const regCard        = document.getElementById('reg-card');
const infoClient     = document.getElementById('info-client');
const infoEnterprise = document.getElementById('info-enterprise');

// Exact pixel distances for the card/overview swap, so the slide lands on the
// other column at any width. Stacked layout (narrow): no slide.
function layoutStage() {
  const narrow = window.matchMedia('(max-width: 1000px)').matches;
  const gap = parseFloat(getComputedStyle(regStage).columnGap) || 0;
  regStage.style.setProperty('--card-shift', narrow ? '0px' : (regInfo.offsetWidth + gap) + 'px');
  regStage.style.setProperty('--info-shift', narrow ? '0px' : (regCard.offsetWidth + gap) + 'px');
}

const FLOW_COPY = {
  client_company: {
    heading: 'Register your company', intro: "You'll be your company's IT Manager on Hastra. After signing in you can upload your team roster, invite everyone, and finish setting up your workspace.",
    stepLabel: 'Company & brand logo', step1Heading: 'Tell us about your company', step1Subtitle: "We'll try to find your brand logo automatically.",
    step2Heading: 'Your account', step2Subtitle: "You'll sign in with this email once your company is set up.",
    orgLabel: 'Company Name',
  },
  client_individual: {
    heading: 'Register as a client', intro: "You're joining as an individual client. You'll be able to submit requirements, track delivery, and settle invoices directly.",
    stepLabel: 'Your details', step1Heading: 'Where are you based?', step1Subtitle: 'Just enough to set up a personal workspace.',
    step2Heading: 'Your account', step2Subtitle: "You'll sign in with this email once you're verified.",
    orgLabel: 'Company Name',
  },
  enterprise_full: {
    heading: 'Set up your organization', intro: "You'll be the admin of your own Hastra workspace, ready immediately. Invite your team, configure leave policy, and start tracking delivery today.",
    stepLabel: 'Organization & brand logo', step1Heading: 'Tell us about your organization', step1Subtitle: "We'll try to find your brand logo automatically, and set up your leave policy.",
    step2Heading: 'Your admin account', step2Subtitle: "You'll sign in as the admin of this workspace.",
    orgLabel: 'Organization Name',
  },
  enterprise_solo: {
    heading: 'Set up your solo workspace', intro: "A lightweight workspace built for a solo developer or studio, with the SDLC pipeline, delivery tracking, and invoicing.",
    stepLabel: 'Your studio', step1Heading: 'Tell us about you', step1Subtitle: 'A lean setup with just the essentials.',
    step2Heading: 'Your admin account', step2Subtitle: "You'll sign in as the admin of this workspace.",
    orgLabel: 'Studio / Developer Name',
  },
};

function computeFlow() {
  const tab = document.querySelector('.reg-tab.active').dataset.tab;
  const sub = document.querySelector('.reg-sub.active').dataset.sub;
  if (tab === 'client') return sub === 'org' ? 'client_company' : 'client_individual';
  return sub === 'org' ? 'enterprise_full' : 'enterprise_solo';
}

function applyFlow() {
  const flow = computeFlow();
  flowInput.value = flow;

  document.querySelectorAll('[data-flows]').forEach(function (el) {
    const show = el.dataset.flows.split(',').includes(flow);
    el.style.display = show ? '' : 'none';
    el.querySelectorAll('input, select, textarea').forEach(function (inp) { inp.disabled = !show; });
  });

  const copy = FLOW_COPY[flow];
  const blockSel = '#info-' + (flow.indexOf('enterprise') === 0 ? 'enterprise' : 'client');
  document.querySelector(blockSel + ' [data-role="heading"]').textContent = copy.heading;
  document.querySelector(blockSel + ' [data-role="intro"]').textContent   = copy.intro;
  document.getElementById('step1Label').textContent    = copy.stepLabel;
  document.getElementById('step1Heading').textContent  = copy.step1Heading;
  document.getElementById('step1Subtitle').textContent = copy.step1Subtitle;
  document.getElementById('step2Heading').textContent  = copy.step2Heading;
  document.getElementById('step2Subtitle').textContent = copy.step2Subtitle;
  orgNameLabel.textContent = copy.orgLabel;

  const enterprise = flow.indexOf('enterprise') === 0;
  regStage.dataset.portal = enterprise ? 'enterprise' : 'client';
  infoClient.classList.toggle('is-active', !enterprise);
  infoEnterprise.classList.toggle('is-active', enterprise);
  infoClient.inert = enterprise;      infoClient.setAttribute('aria-hidden', enterprise ? 'true' : 'false');
  infoEnterprise.inert = !enterprise; infoEnterprise.setAttribute('aria-hidden', enterprise ? 'false' : 'true');

  const subLabels = tab => tab === 'client' ? ['Company', 'Individual / Freelancer'] : ['Full Organization', 'Solo Enterprise'];
  const activeTab = document.querySelector('.reg-tab.active').dataset.tab;
  const [orgLabel, soloLabel] = subLabels(activeTab);
  regSubs[0].textContent = orgLabel;
  regSubs[1].textContent = soloLabel;
}

regTabs.forEach(function (btn) {
  btn.addEventListener('click', function () {
    regTabs.forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
    regSubs.forEach(function (b) { b.classList.remove('active'); });
    regSubs[0].classList.add('active'); // reset sub-toggle to the first option on tab switch
    applyFlow();
  });
});
regSubs.forEach(function (btn) {
  btn.addEventListener('click', function () {
    regSubs.forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
    applyFlow();
  });
});

// Honor ?track=enterprise (linked from auth/login.php's "New team member?"
// footer, which used to be a dead-end "ask your admin" notice).
if (new URLSearchParams(window.location.search).get('track') === 'enterprise') {
  document.querySelector('.reg-tab[data-tab="enterprise"]').click();
} else {
  applyFlow();
}
layoutStage();
window.addEventListener('resize', layoutStage);
// enable the slide only after the first measured layout, so a page that opens
// straight into Enterprise (?track=enterprise) never visibly slides
window.addEventListener('load', function () {
  layoutStage();
  requestAnimationFrame(function () { requestAnimationFrame(function () { regStage.classList.add('ready'); }); });
});

// ── Automated brand-logo finder ─────────────────────────────────────────────
// Builds a domain guess from the company name/website, then probes a few
// public logo endpoints as plain <img> loads — no API keys, no CORS/fetch
// needed. Whichever ones actually load become selectable tiles; a manual
// upload is always available underneath as a fallback.
const logoTiles      = document.getElementById('logoTiles');
const logoStatus     = document.getElementById('logoStatus');
const logoFinderLbl  = document.getElementById('logoFinderLabel');
const logoDataInput  = document.getElementById('logoData');
const navPreviewImg  = document.getElementById('navPreviewLogo');
const navPreviewPh   = document.getElementById('navPreviewPlaceholder');

function guessDomain(nameOrDomain) {
  const v = (nameOrDomain || '').trim().toLowerCase();
  if (!v) return '';
  if (/^[a-z0-9.-]+\.[a-z]{2,}$/i.test(v.replace(/^https?:\/\//, '').split('/')[0])) {
    return v.replace(/^https?:\/\//, '').split('/')[0];
  }
  const slug = v.replace(/[^a-z0-9]+/g, '');
  return slug ? slug + '.com' : '';
}

function selectLogo(url, tileEl) {
  logoTiles.querySelectorAll('.logo-tile').forEach(function (t) { t.classList.remove('selected'); });
  if (tileEl) tileEl.classList.add('selected');
  logoDataInput.value = url;
  dropzone.classList.remove('has-file');
  updateNavPreview(url);
}

function updateNavPreview(url) {
  if (url) {
    navPreviewImg.src = url;
    navPreviewImg.style.display = 'block';
    navPreviewPh.style.display = 'none';
  } else {
    navPreviewImg.style.display = 'none';
    navPreviewPh.style.display = 'flex';
  }
}

let logoSearchSeq = 0;
let logoLookupOnce = false;   // one-off permission from the placeholder button
function searchLogos(domain) {
  logoSearchSeq++;
  const seq = logoSearchSeq;
  logoTiles.innerHTML = '';
  logoStatus.textContent = '';
  if (!domain) { logoFinderLbl.classList.remove('searching'); return; }
  if (!logoLookupOnce && !(window.HastraConsent && HastraConsent.allows('thirdparty'))) {
    const ph = document.createElement('button');
    ph.type = 'button';
    ph.className = 'consent-ph';
    ph.textContent = 'Click to load external asset: logo suggestions from Clearbit, Google and DuckDuckGo';
    ph.addEventListener('click', function () { logoLookupOnce = true; searchLogos(domain); });
    logoTiles.appendChild(ph);
    logoStatus.textContent = 'Logo lookups contact third-party services, so they wait for your permission. You can also upload your logo below.';
    return;
  }

  logoFinderLbl.classList.add('searching');
  const candidates = [
    'https://logo.clearbit.com/' + domain,
    'https://www.google.com/s2/favicons?domain=' + domain + '&sz=128',
    'https://icons.duckduckgo.com/ip3/' + domain + '.ico',
  ];
  let pending = candidates.length;
  let found = 0;

  candidates.forEach(function (src) {
    const probe = new Image();
    probe.onload = function () {
      if (seq !== logoSearchSeq) return;
      pending--; found++;
      const tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'logo-tile';
      const img = document.createElement('img');
      img.src = src; img.alt = 'Suggested logo for ' + domain;
      tile.appendChild(img);
      tile.addEventListener('click', function () { selectLogo(src, tile); });
      logoTiles.appendChild(tile);
      if (!logoDataInput.value) selectLogo(src, tile); // auto-pick the first hit
      if (pending === 0) finishSearch(seq, found);
    };
    probe.onerror = function () {
      if (seq !== logoSearchSeq) return;
      pending--;
      if (pending === 0) finishSearch(seq, found);
    };
    probe.src = src;
  });
}

function finishSearch(seq, found) {
  if (seq !== logoSearchSeq) return;
  logoFinderLbl.classList.remove('searching');
  logoStatus.textContent = found > 0
    ? 'Pick the one that matches your brand, or upload your own below.'
    : "Couldn't find a logo automatically. Upload your own below.";
}

let domainDebounce = null;
function onDomainInputChanged() {
  clearTimeout(domainDebounce);
  domainDebounce = setTimeout(function () {
    const explicit = document.getElementById('company_domain').value.trim();
    const domain = guessDomain(explicit || document.getElementById('company_name').value);
    searchLogos(domain);
  }, 500);
}
document.getElementById('company_domain').addEventListener('input', onDomainInputChanged);
document.getElementById('company_name').addEventListener('input', onDomainInputChanged);

// ── Manual upload fallback (click or drag-and-drop) ─────────────────────────
const dropzone   = document.getElementById('logoDropzone');
const fileInput  = document.getElementById('logoFileInput');
const dzPreview  = document.getElementById('dzPreview');

dropzone.addEventListener('click', function () { fileInput.click(); });
dropzone.addEventListener('dragover', function (e) { e.preventDefault(); dropzone.classList.add('drag-over'); });
dropzone.addEventListener('dragleave', function () { dropzone.classList.remove('drag-over'); });
dropzone.addEventListener('drop', function (e) {
  e.preventDefault();
  dropzone.classList.remove('drag-over');
  if (e.dataTransfer.files && e.dataTransfer.files[0]) handleLogoFile(e.dataTransfer.files[0]);
});
fileInput.addEventListener('change', function () {
  if (fileInput.files[0]) handleLogoFile(fileInput.files[0]);
});

function handleLogoFile(file) {
  if (!/^image\/(png|jpeg|jpg|webp|gif)$/.test(file.type)) {
    logoStatus.textContent = 'Please choose a PNG, JPG, WebP, or GIF image.';
    return;
  }
  if (file.size > 700 * 1024) {
    logoStatus.textContent = 'That image is too large. Please use one under 700KB.';
    return;
  }
  const reader = new FileReader();
  reader.onload = function () {
    logoTiles.querySelectorAll('.logo-tile').forEach(function (t) { t.classList.remove('selected'); });
    logoDataInput.value = reader.result;
    dzPreview.src = reader.result;
    dropzone.classList.add('has-file');
    updateNavPreview(reader.result);
    logoStatus.textContent = 'Using your uploaded logo.';
  };
  reader.readAsDataURL(file);
}
</script>

<script src="<?= get_base_url() ?>assets/js/hastra-select.js?v=<?= ASSET_VERSION ?>"></script>
<?php astra_consent_banner(); ?>
</body>
</html>