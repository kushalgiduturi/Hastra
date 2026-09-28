<?php
include __DIR__ . '/../../core/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../../PHPMailer/PHPMailer.php';
require __DIR__ . '/../../PHPMailer/SMTP.php';
require __DIR__ . '/../../PHPMailer/Exception.php';

secure_session_start();
verify_session($conn);

$msg      = "";
$msg_type = "error";
$user_id  = (int)$_SESSION["user_id"];
$role     = $_SESSION["user_role"];

// ── Fetch current user data ───────────────────────────────────────────────────
$fetch = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
mysqli_stmt_bind_param($fetch, "i", $user_id);
mysqli_stmt_execute($fetch);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($fetch));

if (!$user) {
    session_unset(); session_destroy();
    header("Location: " . APP_URL . "signin");
    exit();
}
$user['phone_number'] = astra_db_decrypt($user['phone_number']);
astra_decrypt_user_row($user);

// ── ACTION: update_profile (name, phone, github, linkedin) ───────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "update_profile") {
    verify_csrf_token();

    $name     = trim($_POST["name"] ?? "");
    $phone    = trim($_POST["phone_number"] ?? "");
    $github   = trim($_POST["github_url"] ?? "");
    $linkedin = trim($_POST["linkedin_url"] ?? "");

    if ($name === "") {
        $msg = "Name cannot be empty.";
    } elseif ($phone !== "" && !preg_match('/^\+[1-9]\d{6,14}$/', $phone)) {
        $msg = "Please enter a valid phone number.";
    } elseif ($github !== "" && !filter_var($github, FILTER_VALIDATE_URL)) {
        $msg = "Please enter a valid GitHub URL.";
    } elseif ($linkedin !== "" && !filter_var($linkedin, FILTER_VALIDATE_URL)) {
        $msg = "Please enter a valid LinkedIn URL.";
    } else {
        $phone_enc    = astra_db_encrypt($phone);
        $phone_bindex = astra_blind_index($phone);
        $name_enc     = astra_db_encrypt($name);
        $upd = mysqli_prepare($conn,
            "UPDATE users SET name = ?, phone_number = ?, phone_bindex = ?, github_url = ?, linkedin_url = ? WHERE id = ?"
        );
        mysqli_stmt_bind_param($upd, "sssssi", $name_enc, $phone_enc, $phone_bindex, $github, $linkedin, $user_id);
        if (mysqli_stmt_execute($upd)) {
            $_SESSION["user_name"] = $name;
            $msg      = "Profile updated successfully.";
            $msg_type = "success";
            // refresh user data
            $fetch2 = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
            mysqli_stmt_bind_param($fetch2, "i", $user_id);
            mysqli_stmt_execute($fetch2);
            $user = mysqli_fetch_assoc(mysqli_stmt_get_result($fetch2));
            $user['phone_number'] = astra_db_decrypt($user['phone_number']);
            astra_decrypt_user_row($user);
        } else {
            $msg = "Failed to update profile.";
        }
    }
}

// ── ACTION: request_email_otp ─────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "request_email_otp") {
    verify_csrf_token();
    header('Content-Type: application/json');

    $new_email = trim($_POST["new_email"] ?? "");

    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        exit();
    }

    if ($new_email === $user['email']) {
        echo json_encode(['success' => false, 'message' => 'This is already your current email.']);
        exit();
    }

    // Check if email already taken
    $chk = mysqli_prepare($conn, "SELECT id FROM users WHERE email_bindex = ? AND id != ?");
    $new_email_bindex = astra_blind_index($new_email);
    mysqli_stmt_bind_param($chk, "si", $new_email_bindex, $user_id);
    mysqli_stmt_execute($chk);
    mysqli_stmt_store_result($chk);
    if (mysqli_stmt_num_rows($chk) > 0) {
        echo json_encode(['success' => false, 'message' => 'This email is already in use by another account.']);
        exit();
    }

    $otp = rand(100000, 999999);
    $upd = mysqli_prepare($conn,
        "UPDATE users SET otp = ?, otp_expiry = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?"
    );
    mysqli_stmt_bind_param($upd, "si", $otp, $user_id);
    mysqli_stmt_execute($upd);

    // Store pending email in session and give this OTP a fresh attempt budget
    $_SESSION["pending_email"]      = $new_email;
    $_SESSION["email_otp_attempts"] = 0;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host        = MAIL_HOST;
        $mail->SMTPAuth    = MAIL_AUTH; astra_mail_auth($mail);
        $mail->Port        = MAIL_PORT;
        $mail->SMTPSecure  = MAIL_SECURE;
        $mail->SMTPAutoTLS = false;
        $mail->setFrom(MAIL_FROM, MAIL_NAME);
        $mail->addAddress($new_email);
        $mail->Subject = 'Verify your new email address';
        $mail->Body    = "Hi {$user['name']},\n\nYour OTP to change your email to $new_email is: $otp\n\nThis OTP expires in 10 minutes.\n\nIf you did not request this, please ignore this email.";
        $mail->send();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Could not send OTP. Please try again.']);
    }
    exit();
}

// ── ACTION: verify_email_otp ──────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "verify_email_otp") {
    verify_csrf_token();
    header('Content-Type: application/json');

    $otp       = trim($_POST["otp"] ?? "");
    $new_email = $_SESSION["pending_email"] ?? "";

    if (!$new_email) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please start over.']);
        exit();
    }

    // Rate-limit OTP verification attempts: 5 failures invalidates the OTP.
    $_SESSION["email_otp_attempts"] = $_SESSION["email_otp_attempts"] ?? 0;
    if ($_SESSION["email_otp_attempts"] >= 5) {
        $inv = mysqli_prepare($conn, "UPDATE users SET otp = NULL, otp_expiry = NULL WHERE id = ?");
        mysqli_stmt_bind_param($inv, "i", $user_id);
        mysqli_stmt_execute($inv);
        unset($_SESSION["pending_email"], $_SESSION["email_otp_attempts"]);
        echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please request a new OTP.']);
        exit();
    }

    $chk = mysqli_prepare($conn,
        "SELECT id FROM users WHERE id = ? AND otp = ? AND otp_expiry > NOW()"
    );
    mysqli_stmt_bind_param($chk, "is", $user_id, $otp);
    mysqli_stmt_execute($chk);
    mysqli_stmt_store_result($chk);

    if (mysqli_stmt_num_rows($chk) === 0) {
        $_SESSION["email_otp_attempts"]++;
        if ($_SESSION["email_otp_attempts"] >= 5) {
            $inv = mysqli_prepare($conn, "UPDATE users SET otp = NULL, otp_expiry = NULL WHERE id = ?");
            mysqli_stmt_bind_param($inv, "i", $user_id);
            mysqli_stmt_execute($inv);
            echo json_encode(['success' => false, 'message' => 'Too many failed attempts. OTP invalidated. Please request a new one.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP.']);
        }
        exit();
    }

    $upd = mysqli_prepare($conn,
        "UPDATE users SET email = ?, email_bindex = ?, otp = NULL, otp_expiry = NULL WHERE id = ?"
    );
    $new_email_enc = astra_db_encrypt($new_email);
    $new_email_bindex_confirm = astra_blind_index($new_email);
    mysqli_stmt_bind_param($upd, "ssi", $new_email_enc, $new_email_bindex_confirm, $user_id);
    if (mysqli_stmt_execute($upd)) {
        unset($_SESSION["pending_email"], $_SESSION["email_otp_attempts"]);
        echo json_encode(['success' => true, 'new_email' => $new_email]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update email.']);
    }
    exit();
}

// ── ACTION: request_password_otp ─────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "request_password_otp") {
    verify_csrf_token();
    header('Content-Type: application/json');

    $otp = rand(100000, 999999);
    $upd = mysqli_prepare($conn,
        "UPDATE users SET otp = ?, otp_expiry = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?"
    );
    mysqli_stmt_bind_param($upd, "si", $otp, $user_id);
    mysqli_stmt_execute($upd);

    // Fresh OTP, fresh attempt budget.
    $_SESSION["password_otp_attempts"] = 0;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host        = MAIL_HOST;
        $mail->SMTPAuth    = MAIL_AUTH; astra_mail_auth($mail);
        $mail->Port        = MAIL_PORT;
        $mail->SMTPSecure  = MAIL_SECURE;
        $mail->SMTPAutoTLS = false;
        $mail->setFrom(MAIL_FROM, MAIL_NAME);
        $mail->addAddress($user['email']);
        $mail->Subject = 'Password Change OTP';
        $mail->Body    = "Hi {$user['name']},\n\nYour OTP to change your password is: $otp\n\nThis OTP expires in 10 minutes.\n\nIf you did not request this, please ignore this email.";
        $mail->send();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Could not send OTP. Please try again.']);
    }
    exit();
}

// ── ACTION: change_password ───────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "change_password") {
    verify_csrf_token();
    header('Content-Type: application/json');

    $otp      = trim($_POST["otp"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm  = $_POST["confirm"] ?? "";

    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
        exit();
    } elseif (!preg_match('/[A-Z]/', $password)) {
        echo json_encode(['success' => false, 'message' => 'Password must contain at least 1 uppercase letter.']);
        exit();
    } elseif (!preg_match('/[a-z]/', $password)) {
        echo json_encode(['success' => false, 'message' => 'Password must contain at least 1 lowercase letter.']);
        exit();
    } elseif (!preg_match('/[0-9]/', $password)) {
        echo json_encode(['success' => false, 'message' => 'Password must contain at least 1 number.']);
        exit();
    } elseif (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        echo json_encode(['success' => false, 'message' => 'Password must contain at least 1 special character.']);
        exit();
    } elseif ($password !== $confirm) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit();
    }

    // Rate-limit OTP verification attempts: 5 failures invalidates the OTP.
    $_SESSION["password_otp_attempts"] = $_SESSION["password_otp_attempts"] ?? 0;
    if ($_SESSION["password_otp_attempts"] >= 5) {
        $inv = mysqli_prepare($conn, "UPDATE users SET otp = NULL, otp_expiry = NULL WHERE id = ?");
        mysqli_stmt_bind_param($inv, "i", $user_id);
        mysqli_stmt_execute($inv);
        unset($_SESSION["password_otp_attempts"]);
        echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please request a new OTP.']);
        exit();
    }

    $chk = mysqli_prepare($conn,
        "SELECT id FROM users WHERE id = ? AND otp = ? AND otp_expiry > NOW()"
    );
    mysqli_stmt_bind_param($chk, "is", $user_id, $otp);
    mysqli_stmt_execute($chk);
    mysqli_stmt_store_result($chk);

    if (mysqli_stmt_num_rows($chk) === 0) {
        $_SESSION["password_otp_attempts"]++;
        if ($_SESSION["password_otp_attempts"] >= 5) {
            $inv = mysqli_prepare($conn, "UPDATE users SET otp = NULL, otp_expiry = NULL WHERE id = ?");
            mysqli_stmt_bind_param($inv, "i", $user_id);
            mysqli_stmt_execute($inv);
            echo json_encode(['success' => false, 'message' => 'Too many failed attempts. OTP invalidated. Please request a new one.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP.']);
        }
        exit();
    }

    $hashed = password_hash($password, PASSWORD_ARGON2ID);
    $upd    = mysqli_prepare($conn,
        "UPDATE users SET password = ?, otp = NULL, otp_expiry = NULL WHERE id = ?"
    );
    mysqli_stmt_bind_param($upd, "si", $hashed, $user_id);
    if (mysqli_stmt_execute($upd)) {
        unset($_SESSION["password_otp_attempts"]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update password.']);
    }
    exit();
}

// ── Fetch activity logs (for employees) ──────────────────────────────────────
$activity_logs = [];
if ($role === 'employee') {
    $log_q = mysqli_prepare($conn,
        "SELECT action, ip_address, timestamp, geo FROM logs WHERE user_id = ? ORDER BY timestamp DESC LIMIT 50"
    );
    mysqli_stmt_bind_param($log_q, "i", $user_id);
    mysqli_stmt_execute($log_q);
    $activity_logs = mysqli_stmt_get_result($log_q)->fetch_all(MYSQLI_ASSOC);
    foreach ($activity_logs as &$__log) { $__log['geo'] = astra_db_decrypt($__log['geo']); }
    unset($__log);

    // Task activity
    $task_q = mysqli_prepare($conn,
        "SELECT t.task_code, t.title, t.status, t.priority, t.updated_at, p.project_code, p.title AS project_title
         FROM tasks t
         JOIN projects p ON t.project_id = p.id
         WHERE t.assigned_to = ?
         ORDER BY t.updated_at DESC"
    );
    mysqli_stmt_bind_param($task_q, "i", $user_id);
    mysqli_stmt_execute($task_q);
    $task_activity = mysqli_stmt_get_result($task_q)->fetch_all(MYSQLI_ASSOC);

    // Bug activity
    $bug_q = mysqli_prepare($conn,
        "SELECT b.bug_code, b.title, b.status, b.severity, b.updated_at, p.project_code
         FROM bugs b
         JOIN projects p ON b.project_id = p.id
         WHERE b.assigned_to = ? OR b.reported_by = ?
         ORDER BY b.updated_at DESC"
    );
    mysqli_stmt_bind_param($bug_q, "ii", $user_id, $user_id);
    mysqli_stmt_execute($bug_q);
    $bug_activity = mysqli_stmt_get_result($bug_q)->fetch_all(MYSQLI_ASSOC);
}

// ── Portal back link per role ─────────────────────────────────────────────────
$back_links = [
    'sysadmin'         => ['url' => 'workspace/sysadmin/', 'label' => '← Sysadmin Portal'],
    'admin'            => ['url' => 'workspace/admin/',        'label' => '← Admin Portal'],
    'employee'         => ['url' => 'workspace/employee/',  'label' => '← Employee Portal'],
    'client'           => ['url' => 'workspace/client/',      'label' => '← Client Portal'],
    'pending_employee' => ['url' => 'workspace/user/newuser-portal',       'label' => '← Portal'],
];
$back = $back_links[$role] ?? ['url' => 'signin', 'label' => '← Back'];

$show_social = in_array($role, ['employee', 'admin', 'sysadmin']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile · Hastra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
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
    font-family: var(--font-sans);
    color: var(--text);
    transition: var(--transition);
  }

  /* ── TOPNAV ── */
  .topnav {
    position: sticky; top: 0; z-index: 100;
    background: var(--topnav-bg); 
    border-bottom: 1px solid var(--border);
    padding: 0 2rem; height: 56px;
    display: flex; align-items: center; justify-content: space-between;
  }
  .nav-left  { display: flex; align-items: center; gap: 12px; }
  .nav-right { display: flex; align-items: center; gap: 16px; }
  .nav-icon  {
    width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
  }
  .nav-icon svg { width: 16px; height: 16px; fill: white; }
  .nav-title { font-family: 'Share Tech Mono', monospace; font-size: 14px; letter-spacing: 0.1em; text-transform: uppercase; }
  .nav-divider { width: 1px; height: 20px; background: var(--border-dim); }
  .nav-badge {
    text-decoration: none; cursor: pointer; display: inline-block;
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.08em; text-transform: uppercase;
    color: var(--cyan); background: rgba(34,211,238,0.1);
    border: 1px solid rgba(34,211,238,0.2); padding: 2px 8px; border-radius: 2px;
  }
  .nav-user { font-size: 13px; color: var(--text-dim); }
  .nav-user span { color: var(--text); font-weight: 500; }
  .nav-link {
    font-size: 12px; color: var(--text-dim); text-decoration: none;
    padding: 5px 10px; border-radius: 3px; border: 1px solid var(--border-dim);
    transition: background 0.2s, color 0.2s;
  }
  .nav-link:hover { background: rgba(255,255,255,0.05); color: var(--text); }
  .btn-logout {
    display: flex; align-items: center; gap: 6px;
    background: rgba(var(--red-rgb),0.1); border: 1px solid rgba(var(--red-rgb),0.25);
    color: #fca5a5; font-family: var(--font-sans);
    font-size: 12px; font-weight: 500; letter-spacing: 0.05em; text-transform: uppercase;
    padding: 6px 12px; border-radius: 3px; text-decoration: none;
    transition: background 0.2s, border-color 0.2s;
  }
  .btn-logout:hover { background: rgba(var(--red-rgb),0.2); border-color: rgba(var(--red-rgb),0.5); }
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

  /* ── LAYOUT ── */
  .main { max-width: 900px; margin: 0 auto; padding: 2rem; }
  .page-header { margin-bottom: 2rem; }
  .page-header h1 { font-size: 24px; font-weight: 600; letter-spacing: -0.01em; margin-bottom: 4px; }
  .page-header p  { font-size: 13px; color: var(--text-dim); }

  /* ── TABS ── */
  .tab-bar {
    display: flex; gap: 4px;
    border-bottom: 1px solid var(--border-dim);
    margin-bottom: 1.5rem;
  }
  .tab-btn {
    background: none; border: none; border-bottom: 2px solid transparent;
    color: var(--text-dim); font-family: var(--font-sans);
    font-size: 13px; font-weight: 500; padding: 10px 18px;
    cursor: pointer; margin-bottom: -1px; transition: color 0.2s, border-color 0.2s;
  }
  .tab-btn:hover { color: var(--text); }
  .tab-btn.active { color: var(--accent-bright); border-bottom-color: var(--accent-bright); }
  .tab-panel { display: none; }
  .tab-panel.active { display: block; }

  /* ── SECTION ── */
  .section {
    background: var(--navy-card); border: 1px solid var(--border-dim);
    border-radius: 4px; overflow: hidden; margin-bottom: 1.5rem;
  }
  .section-header {
    display: flex; align-items: center; gap: 8px;
    padding: 1rem 1.4rem; border-bottom: 1px solid var(--border-dim);
    background: var(--section-header-bg);
    font-size: 13px; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase;
  }
  .section-header svg { width: 15px; height: 15px; fill: var(--accent-bright); }
  .section-body { padding: 1.4rem; }

  /* ── ALERT ── */
  .alert {
    display: flex; align-items: flex-start; gap: 8px;
    border-radius: 3px; padding: 10px 14px; margin-bottom: 1.2rem;
    font-size: 13px; border-left: 3px solid; line-height: 1.5;
  }
  .alert.success { background: var(--green-bg); border-color: var(--green); color: #86efac; }
  .alert.error   { background: var(--red-bg);   border-color: var(--red);   color: #fca5a5; }
  .alert svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; }
  .alert.success svg { fill: var(--green); }
  .alert.error   svg { fill: var(--red); }

  /* ── FORM ── */
  .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .field { margin-bottom: 1rem; }
  .field label {
    display: block; font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.07em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px;
  }
  .field input[type="text"],
  .field input[type="email"],
  .field input[type="tel"],
  .field input[type="url"],
  .field input[type="password"] {
    width: 100%; background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; color: var(--text); font-family: var(--font-sans);
    font-size: 13px; padding: 9px 12px; outline: none; transition: border-color 0.2s;
  }
  .field input:focus { border-color: var(--accent-bright); }
  .field input::placeholder { color: var(--text-dim); }
  .field .hint { font-size: 11px; color: var(--text-dim); margin-top: 4px; }

  /* intl-tel-input theming to match Hastra's dark inputs */
  .iti { width: 100%; display: block; }
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

  .btn-save {
    background: var(--accent); color: white; border: none; border-radius: 3px;
    font-family: var(--font-sans); font-size: 13px; font-weight: 600;
    letter-spacing: 0.04em; text-transform: uppercase;
    padding: 10px 22px; cursor: pointer; margin-top: 0.5rem;
    transition: background 0.2s, box-shadow 0.2s;
  }
  .btn-save:hover { background: var(--accent-dim); box-shadow: 0 0 16px rgba(var(--accent-rgb),0.3); }

  .btn-action {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(var(--accent-rgb),0.1); border: 1px solid rgba(var(--accent-rgb),0.3);
    color: var(--accent-bright); font-family: var(--font-sans);
    font-size: 12px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase;
    padding: 8px 16px; border-radius: 3px; cursor: pointer;
    transition: background 0.2s; margin-top: 0.5rem;
  }
  .btn-action:hover { background: rgba(var(--accent-rgb),0.2); }

  /* ── OTP BOXES ── */
  .otp-wrap { display: flex; gap: 8px; margin: 1rem 0; }
  .otp-box {
    width: 46px; height: 52px; background: var(--input-bg);
    border: 1px solid var(--border-dim); border-radius: 4px;
    color: var(--text); font-family: 'Share Tech Mono', monospace;
    font-size: 22px; text-align: center; outline: none;
    transition: border-color 0.2s; caret-color: var(--accent-bright);
  }
  .otp-box:focus { border-color: var(--accent-bright); box-shadow: 0 0 0 3px rgba(var(--accent-rgb),0.1); }
  .otp-box.filled { border-color: rgba(var(--accent-rgb),0.4); background: rgba(var(--accent-rgb),0.05); }

  /* ── PASSWORD REQ BOX ── */
  .req-box {
    background: var(--section-header-bg); border: 1px solid var(--border-dim);
    border-radius: 3px; padding: 10px 12px; margin: 0.8rem 0;
    display: grid; grid-template-columns: 1fr 1fr; gap: 5px 12px;
  }
  .req-item {
    font-size: 11px; color: var(--text-dim);
    display: flex; align-items: center; gap: 5px; transition: color 0.2s;
  }
  .req-item .dot {
    width: 5px; height: 5px; border-radius: 50%;
    background: #334155; flex-shrink: 0; transition: background 0.2s;
  }
  .req-item.valid { color: var(--green); }
  .req-item.valid .dot { background: var(--green); box-shadow: 0 0 4px var(--green); }
  .req-item.invalid { color: var(--red); }
  .req-item.invalid .dot { background: var(--red); }

  .match-msg { font-size: 12px; margin-top: 4px; height: 16px; }
  .match-msg.ok  { color: var(--green); }
  .match-msg.err { color: var(--red); }

  /* ── INLINE ALERT ── */
  .inline-alert {
    padding: 8px 12px; border-radius: 3px; font-size: 12px;
    border-left: 3px solid; margin: 0.8rem 0; display: none; line-height: 1.4;
  }
  .inline-alert.error   { background: var(--red-bg);   border-color: var(--red);   color: #fca5a5; display: block; }
  .inline-alert.success { background: var(--green-bg); border-color: var(--green); color: #86efac; display: block; }

  /* ── PROFILE AVATAR ── */
  .profile-avatar {
    width: 72px; height: 72px; border-radius: 50%;
    background: rgba(var(--accent-rgb),0.12); border: 2px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; font-weight: 700; color: var(--accent-bright);
    margin-bottom: 1rem;
  }

  .profile-info-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.04);
    font-size: 13px;
  }
  .profile-info-row:last-child { border-bottom: none; }
  .profile-info-label { font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.07em; text-transform: uppercase; color: var(--text-dim); }
  .profile-info-value { color: var(--text); font-weight: 500; }

  /* ── BADGE ── */
  .badge {
    display: inline-block; padding: 2px 8px; border-radius: 2px;
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.05em; text-transform: uppercase; font-weight: 500;
  }
  .badge-sysadmin  { background: rgba(var(--purple-rgb),0.12); color: var(--purple);      border: 1px solid rgba(var(--purple-rgb),0.2); }
  .badge-admin     { background: rgba(34,197,94,0.1);    color: var(--green);       border: 1px solid rgba(34,197,94,0.2); }
  .badge-employee  { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .badge-client    { background: rgba(var(--purple-rgb),0.1);  color: var(--purple);      border: 1px solid rgba(var(--purple-rgb),0.2); }

  /* ── ACTIVITY TABLE ── */
  .tbl-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead tr { background: var(--section-header-bg); border-bottom: 1px solid var(--border-dim); }
  th {
    padding: 10px 14px; text-align: left; font-size: 11px;
    font-family: 'Share Tech Mono', monospace; letter-spacing: 0.08em;
    text-transform: uppercase; color: var(--text-dim); font-weight: 400;
  }
  tbody tr { border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.15s; }
  tbody tr:hover { background: var(--hover-bg); }
  tbody tr:last-child { border-bottom: none; }
  td { padding: 10px 14px; color: var(--text); vertical-align: middle; }
  td.muted { color: var(--text-dim); font-family: 'Share Tech Mono', monospace; font-size: 12px; }

  .log-badge-success  { background: rgba(34,197,94,0.1);    color: var(--green);       border: 1px solid rgba(34,197,94,0.2); }
  .log-badge-danger   { background: rgba(var(--red-rgb),0.1);    color: var(--red);         border: 1px solid rgba(var(--red-rgb),0.2); }
  .log-badge-info     { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .log-badge-warning  { background: rgba(245,158,11,0.1);   color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }

  .priority-critical { background: rgba(var(--red-rgb),0.12);   color: var(--red);         border: 1px solid rgba(var(--red-rgb),0.3); }
  .priority-high     { background: rgba(245,158,11,0.1);   color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }
  .priority-medium   { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .priority-low      { background: rgba(148,163,184,0.08); color: var(--text-dim);    border: 1px solid rgba(148,163,184,0.15); }

  .status-completed  { background: rgba(34,197,94,0.1);    color: var(--green);       border: 1px solid rgba(34,197,94,0.2); }
  .status-in_progress{ background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .status-pending    { background: rgba(148,163,184,0.08); color: var(--text-dim);    border: 1px solid rgba(148,163,184,0.15); }
  .status-blocked    { background: rgba(var(--red-rgb),0.1);    color: var(--red);         border: 1px solid rgba(var(--red-rgb),0.2); }
  .status-closed     { background: rgba(34,197,94,0.1);    color: var(--green);       border: 1px solid rgba(34,197,94,0.2); }
  .status-open       { background: rgba(148,163,184,0.08); color: var(--text-dim);    border: 1px solid rgba(148,163,184,0.15); }
  .status-fixed      { background: rgba(34,211,238,0.1);   color: var(--cyan);        border: 1px solid rgba(34,211,238,0.2); }
  .status-retest     { background: rgba(var(--purple-rgb),0.1);  color: var(--purple);      border: 1px solid rgba(var(--purple-rgb),0.2); }
  .status-wont_fix   { background: rgba(var(--red-rgb),0.08);   color: #fca5a5;            border: 1px solid rgba(var(--red-rgb),0.2); }

  .eye-btn {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer; color: var(--text-dim);
    padding: 4px; display: flex; align-items: center; transition: color 0.2s;
  }
  .eye-btn:hover { color: var(--accent-bright); }
  .eye-btn svg { width: 16px; height: 16px; }
  .input-wrap { position: relative; }
  .input-wrap input { padding-right: 38px !important; }

  .step-indicator {
    display: flex; align-items: center; gap: 8px; margin-bottom: 1.2rem;
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.07em; text-transform: uppercase; color: var(--text-dim);
  }
  .step-dot {
    width: 20px; height: 20px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 700; flex-shrink: 0;
    border: 1px solid var(--border-dim); background: rgba(255,255,255,0.03);
    color: var(--text-dim);
  }
  .step-dot.active { background: var(--accent); border-color: var(--accent); color: white; }
  .step-dot.done   { background: rgba(34,197,94,0.2); border-color: var(--green); color: var(--green); }
  .step-line { flex: 1; height: 1px; background: var(--border-dim); }

  .empty-state { text-align: center; padding: 2.5rem 1rem; color: var(--text-dim); font-size: 13px; }
  .empty-state svg { width: 32px; height: 32px; fill: var(--border-dim); margin: 0 auto 0.8rem; display: block; }

  @media (max-width: 768px) {
    .form-grid-2 { grid-template-columns: 1fr; }
    .otp-wrap .otp-box { width: 40px; height: 46px; font-size: 18px; }
  }
</style>
</head>
<body>

<?php render_profile_barrier($conn); ?>

<nav class="topnav">
  <div class="nav-left">
    <a href="<?= get_base_url() ?>workspace/" class="nav-brand-link" title="All pages for your role">
    <div class="nav-icon">
      <svg viewBox="0 0 48 48"><defs><linearGradient id="hastra-crimson" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#7a1224"/><stop offset="1" stop-color="#e11d3c"/></linearGradient><linearGradient id="hastra-cobalt" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#1e3a8a"/><stop offset="1" stop-color="#3b82f6"/></linearGradient></defs><path class="hastra-primary-fill" fill="url(#hastra-crimson)" d="M8 5H16V43H8V5ZM32 5H40V43H32V5ZM4 21H44V27H4V21Z"/><path class="hastra-primary-fill" fill="url(#hastra-crimson)" d="M24 15L30 24L24 33L18 24Z"/></svg>
    </div>
    <span class="nav-title">Hastra</span>
    </a>
    <div class="nav-divider"></div>
    <a class="nav-badge" href="<?= get_base_url() . $back['url'] ?>">Profile</a>
  </div>
  <div class="nav-right">
    <span class="nav-user">Signed in as <span><?= htmlspecialchars($_SESSION["user_name"]) ?></span></span>
    <button id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle">
      <span class="theme-icon"></span>
      <span class="theme-label"></span>
    </button>
    <a href="<?= get_base_url() . $back['url'] ?>" class="nav-link"><?= $back['label'] ?></a>
    <a href="<?= get_base_url() ?>signout" class="btn-logout">
      <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
      Logout
    </a>
  </div>
</nav>

<div class="main">

  <div class="page-header">
    <h1>My Profile</h1>
    <p>Manage your account details, security settings<?= $role === 'employee' ? ', and activity history' : '' ?>.</p>
  </div>

  <?php if ($msg): ?>
  <div class="alert <?= $msg_type ?>">
    <?php if ($msg_type === 'success'): ?>
      <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
    <?php else: ?>
      <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
    <?php endif; ?>
    <span><?= $msg ?></span>
  </div>
  <?php endif; ?>

  <!-- ── TABS ── -->
  <div class="tab-bar">
    <button class="tab-btn active" onclick="switchTab('overview')">Overview</button>
    <button class="tab-btn" onclick="switchTab('edit')">Edit Profile</button>
    <button class="tab-btn" onclick="switchTab('email')">Change Email</button>
    <button class="tab-btn" onclick="switchTab('password')">Change Password</button>
    <?php if ($role === 'employee'): ?>
    <button class="tab-btn" onclick="switchTab('activity')">Activity</button>
    <?php endif; ?>
  </div>

  <!-- ── TAB: OVERVIEW ── -->
  <div class="tab-panel active" id="tab-overview">
    <div class="section">
      <div class="section-header">
        <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
        Account Overview
      </div>
      <div class="section-body">
        <div class="profile-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>

        <div class="profile-info-row">
          <span class="profile-info-label">Full Name</span>
          <span class="profile-info-value"><?= htmlspecialchars($user['name']) ?></span>
        </div>
        <div class="profile-info-row">
          <span class="profile-info-label">Email</span>
          <span class="profile-info-value"><?= htmlspecialchars($user['email']) ?></span>
        </div>
        <div class="profile-info-row">
          <span class="profile-info-label">Phone</span>
          <span class="profile-info-value"><?= $user['phone_number'] ? htmlspecialchars($user['phone_number']) : '<span style="color:var(--text-dim);">Not set</span>' ?></span>
        </div>
        <div class="profile-info-row">
          <span class="profile-info-label">Role</span>
          <span class="profile-info-value"><span class="badge badge-<?= $user['role'] ?>"><?= str_replace('_', ' ', $user['role']) ?></span></span>
        </div>
        <div class="profile-info-row">
          <span class="profile-info-label">User ID</span>
          <span class="profile-info-value" style="font-family:'Share Tech Mono',monospace;">#<?= $user['id'] ?></span>
        </div>
        <?php if ($show_social): ?>
        <?php if ($user['github_url']): ?>
        <div class="profile-info-row">
          <span class="profile-info-label">GitHub</span>
          <a href="<?= htmlspecialchars($user['github_url']) ?>" target="_blank" style="color:var(--accent-bright);font-size:13px;"><?= htmlspecialchars($user['github_url']) ?></a>
        </div>
        <?php endif; ?>
        <?php if ($user['linkedin_url']): ?>
        <div class="profile-info-row">
          <span class="profile-info-label">LinkedIn</span>
          <a href="<?= htmlspecialchars($user['linkedin_url']) ?>" target="_blank" style="color:var(--accent-bright);font-size:13px;"><?= htmlspecialchars($user['linkedin_url']) ?></a>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── TAB: EDIT PROFILE ── -->
  <div class="tab-panel" id="tab-edit">
    <div class="section">
      <div class="section-header">
        <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
        Edit Profile
      </div>
      <div class="section-body">
        <form method="POST" action="profile" id="profileEditForm">
          <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
          <input type="hidden" name="action" value="update_profile">

          <div class="form-grid-2">
            <div class="field">
              <label>Full Name</label>
              <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" maxlength="100" required placeholder="Your full name">
            </div>
            <div class="field">
              <label>Phone Number <span style="text-transform:none;color:#475569;font-weight:400;">(optional)</span></label>
              <input type="tel" name="phone_number" id="phone_number" value="<?= htmlspecialchars($user['phone_number'] ?? '') ?>" maxlength="20" placeholder="98765 43210">
            </div>
          </div>

          <?php if ($show_social): ?>
          <div class="form-grid-2">
            <div class="field">
              <label>GitHub Profile URL <span style="text-transform:none;color:#475569;font-weight:400;">(optional)</span></label>
              <input type="url" name="github_url" value="<?= htmlspecialchars($user['github_url'] ?? '') ?>" placeholder="https://github.com/username">
              <div class="hint">Your public GitHub profile link</div>
            </div>
            <div class="field">
              <label>LinkedIn Profile URL <span style="text-transform:none;color:#475569;font-weight:400;">(optional)</span></label>
              <input type="url" name="linkedin_url" value="<?= htmlspecialchars($user['linkedin_url'] ?? '') ?>" placeholder="https://linkedin.com/in/username">
              <div class="hint">Your public LinkedIn profile link</div>
            </div>
          </div>
          <?php endif; ?>

          <button type="submit" class="btn-save">Save Changes</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── TAB: CHANGE EMAIL ── -->
  <div class="tab-panel" id="tab-email">
    <div class="section">
      <div class="section-header">
        <svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
        Change Email
      </div>
      <div class="section-body">

        <div style="font-size:13px;color:var(--text-dim);margin-bottom:1.2rem;line-height:1.6;">
          Current email: <strong style="color:var(--text);" id="currentEmailDisplay"><?= htmlspecialchars($user['email']) ?></strong>
        </div>

        <!-- Step indicators -->
        <div class="step-indicator">
          <div class="step-dot active" id="emailStep1Dot">1</div>
          <div class="step-line"></div>
          <div class="step-dot" id="emailStep2Dot">2</div>
          <div class="step-line"></div>
          <div class="step-dot" id="emailStep3Dot">✓</div>
        </div>

        <!-- Step 1: Enter new email -->
        <div id="emailStep1">
          <div class="field">
            <label>New Email Address</label>
            <input type="email" id="newEmailInput" placeholder="newemail@example.com" maxlength="100">
          </div>
          <div class="inline-alert" id="emailStep1Alert"></div>
          <button class="btn-action" id="btnSendEmailOtp" onclick="sendEmailOtp()">
            Send Verification OTP
          </button>
        </div>

        <!-- Step 2: Enter OTP -->
        <div id="emailStep2" style="display:none;">
          <div style="font-size:13px;color:var(--text-dim);margin-bottom:0.8rem;">
            Enter the 6-digit OTP sent to <strong style="color:var(--text);" id="otpSentTo"></strong>
          </div>
          <div class="otp-wrap" id="emailOtpBoxes">
            <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="eo0">
            <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="eo1">
            <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="eo2">
            <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="eo3">
            <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="eo4">
            <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="eo5">
          </div>
          <div class="inline-alert" id="emailStep2Alert"></div>
          <div style="display:flex;gap:8px;margin-top:0.5rem;">
            <button class="btn-save" id="btnVerifyEmailOtp" onclick="verifyEmailOtp()" disabled>Verify & Update Email</button>
            <button class="btn-action" onclick="resetEmailFlow()" style="background:none;border-color:var(--border-dim);color:var(--text-dim);">Back</button>
          </div>
        </div>

        <!-- Step 3: Done -->
        <div id="emailStep3" style="display:none;text-align:center;padding:1.5rem 0;">
          <div style="width:52px;height:52px;background:var(--green-bg);border:1px solid rgba(34,197,94,0.25);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
            <svg style="width:26px;height:26px;fill:var(--green);" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
          </div>
          <div style="font-size:16px;font-weight:600;margin-bottom:4px;">Email Updated!</div>
          <div style="font-size:13px;color:var(--text-dim);" id="emailUpdatedTo"></div>
        </div>

      </div>
    </div>
  </div>

  <!-- ── TAB: CHANGE PASSWORD ── -->
  <div class="tab-panel" id="tab-password">
    <div class="section">
      <div class="section-header">
        <svg viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
        Change Password
      </div>
      <div class="section-body">

        <div class="step-indicator">
          <div class="step-dot active" id="pwStep1Dot">1</div>
          <div class="step-line"></div>
          <div class="step-dot" id="pwStep2Dot">2</div>
          <div class="step-line"></div>
          <div class="step-dot" id="pwStep3Dot">✓</div>
        </div>

        <!-- Step 1: Request OTP -->
        <div id="pwStep1">
          <div style="font-size:13px;color:var(--text-dim);margin-bottom:1rem;line-height:1.6;">
            We'll send a verification OTP to <strong style="color:var(--text);"><?= htmlspecialchars($user['email']) ?></strong> before you can set a new password.
          </div>
          <div class="inline-alert" id="pwStep1Alert"></div>
          <button class="btn-action" onclick="sendPasswordOtp()">Send OTP to My Email</button>
        </div>

        <!-- Step 2: OTP + new password -->
        <div id="pwStep2" style="display:none;">
          <div style="font-size:13px;color:var(--text-dim);margin-bottom:0.8rem;">
            Enter the OTP sent to your email, then set your new password.
          </div>

          <div class="field">
            <label>OTP</label>
            <div class="otp-wrap">
              <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="po0">
              <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="po1">
              <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="po2">
              <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="po3">
              <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="po4">
              <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="po5">
            </div>
          </div>

          <div class="field">
            <label>New Password</label>
            <div class="input-wrap">
              <input type="password" id="newPassword" placeholder="••••••••••••" maxlength="128"
                     oninput="checkPwReqs(this.value)">
              <button type="button" class="eye-btn" onclick="toggleEye('newPassword','eyePw1')">
                <svg id="eyePw1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
                  <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
                  <line x1="1" y1="1" x2="23" y2="23"/>
                </svg>
              </button>
            </div>
          </div>

          <div class="req-box">
            <div class="req-item" id="pr_length"> <span class="dot"></span> 8+ characters </div>
            <div class="req-item" id="pr_upper">  <span class="dot"></span> Uppercase (A-Z) </div>
            <div class="req-item" id="pr_lower">  <span class="dot"></span> Lowercase (a-z) </div>
            <div class="req-item" id="pr_number"> <span class="dot"></span> Number (0-9) </div>
            <div class="req-item" id="pr_special"><span class="dot"></span> Special character </div>
          </div>

          <div class="field">
            <label>Confirm New Password</label>
            <div class="input-wrap">
              <input type="password" id="confirmPassword" placeholder="••••••••••••" maxlength="128"
                     oninput="checkPwMatch()">
              <button type="button" class="eye-btn" onclick="toggleEye('confirmPassword','eyePw2')">
                <svg id="eyePw2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
                  <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
                  <line x1="1" y1="1" x2="23" y2="23"/>
                </svg>
              </button>
            </div>
            <div class="match-msg" id="pwMatchMsg"></div>
          </div>

          <div class="inline-alert" id="pwStep2Alert"></div>
          <div style="display:flex;gap:8px;margin-top:0.5rem;">
            <button class="btn-save" onclick="changePassword()">Update Password</button>
            <button class="btn-action" onclick="resetPwFlow()" style="background:none;border-color:var(--border-dim);color:var(--text-dim);">Back</button>
          </div>
        </div>

        <!-- Step 3: Done -->
        <div id="pwStep3" style="display:none;text-align:center;padding:1.5rem 0;">
          <div style="width:52px;height:52px;background:var(--green-bg);border:1px solid rgba(34,197,94,0.25);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
            <svg style="width:26px;height:26px;fill:var(--green);" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
          </div>
          <div style="font-size:16px;font-weight:600;margin-bottom:4px;">Password Updated!</div>
          <div style="font-size:13px;color:var(--text-dim);">Your password has been changed successfully.</div>
        </div>

      </div>
    </div>
  </div>

  <?php if ($role === 'employee'): ?>
  <!-- ── TAB: ACTIVITY ── -->
  <div class="tab-panel" id="tab-activity">

    <!-- Login activity -->
    <div class="section">
      <div class="section-header">
        <svg viewBox="0 0 24 24"><path d="M11 7L9.6 8.4l2.6 2.6H2v2h10.2l-2.6 2.6L11 17l5-5-5-5z"/></svg>
        Login Activity
        <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($activity_logs) ?> records</span>
      </div>
      <?php if (empty($activity_logs)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        No login activity found.
      </div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr><th>Action</th><th>IP Address</th><th>Location</th><th>Time</th></tr>
          </thead>
          <tbody>
            <?php foreach ($activity_logs as $log):
              if ($log['action'] === 'login_success')                                       $lc = 'log-badge-success';
              elseif ($log['action'] === 'logout')                                          $lc = 'log-badge-info';
              elseif (in_array($log['action'], ['login_failed','account_locked']))          $lc = 'log-badge-danger';
              else                                                                          $lc = 'log-badge-warning';

              $geo_parts = $log['geo'] ? explode(' | ', $log['geo']) : [];
              $location  = $geo_parts[0] ?? ($log['ip_address'] === '::1' ? 'Localhost' : 'Unknown');
            ?>
            <tr>
              <td><span class="badge <?= $lc ?>"><?= htmlspecialchars($log['action']) ?></span></td>
              <td class="muted"><?= htmlspecialchars($log['ip_address']) ?></td>
              <td class="muted"><?= htmlspecialchars($location) ?></td>
              <td class="muted"><?= htmlspecialchars($log['timestamp']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Task activity -->
    <div class="section">
      <div class="section-header">
        <svg viewBox="0 0 24 24"><path d="M9 11H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm2-7h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg>
        Task History
        <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($task_activity) ?> tasks</span>
      </div>
      <?php if (empty($task_activity)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M9 11H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm2-7h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg>
        No tasks assigned yet.
      </div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr><th>Task</th><th>Project</th><th>Priority</th><th>Status</th><th>Last Updated</th></tr>
          </thead>
          <tbody>
            <?php foreach ($task_activity as $t): ?>
            <tr>
              <td>
                <span style="font-family:'Share Tech Mono',monospace;font-size:11px;font-weight:600;color:var(--cyan);background:rgba(34,211,238,0.08);border:1px solid rgba(34,211,238,0.2);padding:2px 7px;border-radius:2px;"><?= htmlspecialchars($t['task_code']) ?></span>
                <div style="font-size:12px;margin-top:3px;"><?= htmlspecialchars($t['title']) ?></div>
              </td>
              <td class="muted"><?= htmlspecialchars($t['project_code']) ?></td>
              <td><span class="badge priority-<?= $t['priority'] ?>"><?= ucfirst($t['priority']) ?></span></td>
              <td><span class="badge status-<?= $t['status'] ?>"><?= str_replace('_', ' ', $t['status']) ?></span></td>
              <td class="muted"><?= date('d M Y, H:i', strtotime($t['updated_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Bug activity -->
    <div class="section">
      <div class="section-header">
        <svg viewBox="0 0 24 24"><path d="M20 8h-2.81c-.45-.78-1.07-1.45-1.82-1.96L17 4.41 15.59 3l-2.17 2.17C12.96 5.06 12.49 5 12 5c-.49 0-.96.06-1.41.17L8.41 3 7 4.41l1.62 1.63C7.88 6.55 7.26 7.22 6.81 8H4v2h2.09c-.05.33-.09.66-.09 1v1H4v2h2v1c0 .34.04.67.09 1H4v2h2.81c1.04 1.79 2.97 3 5.19 3s4.15-1.21 5.19-3H20v-2h-2.09c.05-.33.09-.66.09-1v-1h2v-2h-2v-1c0-.34-.04-.67-.09-1H20V8z"/></svg>
        Bug History
        <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($bug_activity) ?> bugs</span>
      </div>
      <?php if (empty($bug_activity)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        No bug activity yet.
      </div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr><th>Bug</th><th>Project</th><th>Severity</th><th>Status</th><th>Last Updated</th></tr>
          </thead>
          <tbody>
            <?php foreach ($bug_activity as $b): ?>
            <tr>
              <td>
                <span style="font-family:'Share Tech Mono',monospace;font-size:11px;font-weight:600;color:var(--cyan);background:rgba(34,211,238,0.08);border:1px solid rgba(34,211,238,0.2);padding:2px 7px;border-radius:2px;"><?= htmlspecialchars($b['bug_code']) ?></span>
                <div style="font-size:12px;margin-top:3px;"><?= htmlspecialchars($b['title']) ?></div>
              </td>
              <td class="muted"><?= htmlspecialchars($b['project_code']) ?></td>
              <td><span class="badge priority-<?= $b['severity'] ?>"><?= ucfirst($b['severity']) ?></span></td>
              <td><span class="badge status-<?= $b['status'] ?>"><?= str_replace('_', ' ', $b['status']) ?></span></td>
              <td class="muted"><?= date('d M Y, H:i', strtotime($b['updated_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>
  <?php endif; ?>

</div>

<script>
const CSRF = "<?= generate_csrf_token() ?>";

// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(name) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  event.currentTarget.classList.add('active');
}

// ── Eye toggle ────────────────────────────────────────────────────────────────
const eyeOpenSVG   = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" fill="none"/>`;
const eyeClosedSVG = `<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/><line x1="1" y1="1" x2="23" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>`;

function toggleEye(inputId, iconId) {
  const input = document.getElementById(inputId);
  const icon  = document.getElementById(iconId);
  const show  = input.type === 'password';
  input.type     = show ? 'text' : 'password';
  icon.innerHTML = show ? eyeOpenSVG : eyeClosedSVG;
}

// ── OTP box helper ────────────────────────────────────────────────────────────
function initOtpBoxes(prefix, count, onComplete) {
  const boxes = Array.from({length: count}, (_, i) => document.getElementById(prefix + i));
  boxes.forEach((box, idx) => {
    box.addEventListener('input', () => {
      box.value = box.value.replace(/[^0-9]/g, '');
      box.classList.toggle('filled', box.value !== '');
      if (box.value && idx < count - 1) boxes[idx + 1].focus();
      if (onComplete) onComplete(boxes.map(b => b.value).join(''));
    });
    box.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !box.value && idx > 0) {
        boxes[idx - 1].value = '';
        boxes[idx - 1].classList.remove('filled');
        boxes[idx - 1].focus();
        if (onComplete) onComplete(boxes.map(b => b.value).join(''));
      }
    });
    box.addEventListener('paste', e => {
      e.preventDefault();
      const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g,'').slice(0, count);
      pasted.split('').forEach((char, i) => { if (boxes[i]) { boxes[i].value = char; boxes[i].classList.add('filled'); } });
      if (boxes[Math.min(pasted.length, count - 1)]) boxes[Math.min(pasted.length, count - 1)].focus();
      if (onComplete) onComplete(boxes.map(b => b.value).join(''));
    });
  });
  return boxes;
}

function getOtpValue(prefix, count) {
  return Array.from({length: count}, (_, i) => document.getElementById(prefix + i).value).join('');
}

function clearOtpBoxes(prefix, count) {
  for (let i = 0; i < count; i++) {
    const b = document.getElementById(prefix + i);
    b.value = ''; b.classList.remove('filled');
  }
}

// ── Email change flow ─────────────────────────────────────────────────────────
initOtpBoxes('eo', 6, val => {
  const btn = document.getElementById('btnVerifyEmailOtp');
  if (btn) btn.disabled = val.length < 6;
});

function showInlineAlert(id, type, msg) {
  const el = document.getElementById(id);
  el.className  = 'inline-alert ' + type;
  el.textContent = msg;
}

function sendEmailOtp() {
  const email = document.getElementById('newEmailInput').value.trim();
  if (!email) { showInlineAlert('emailStep1Alert','error','Please enter an email address.'); return; }

  const btn = document.getElementById('btnSendEmailOtp');
  btn.disabled     = true;
  btn.textContent  = 'Sending…';

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action',     'request_email_otp');
  fd.append('new_email',  email);

  fetch('profile', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      btn.disabled    = false;
      btn.textContent = 'Send Verification OTP';
      if (data.success) {
        document.getElementById('emailStep1').style.display = 'none';
        document.getElementById('emailStep2').style.display = 'block';
        document.getElementById('otpSentTo').textContent    = email;
        document.getElementById('emailStep1Dot').className  = 'step-dot done';
        document.getElementById('emailStep2Dot').className  = 'step-dot active';
        document.getElementById('eo0').focus();
      } else {
        showInlineAlert('emailStep1Alert', 'error', data.message);
      }
    })
    .catch(() => {
      btn.disabled    = false;
      btn.textContent = 'Send Verification OTP';
      showInlineAlert('emailStep1Alert', 'error', 'Network error. Please try again.');
    });
}

function verifyEmailOtp() {
  const otp = getOtpValue('eo', 6);
  if (otp.length < 6) return;

  const btn = document.getElementById('btnVerifyEmailOtp');
  btn.disabled    = true;
  btn.textContent = 'Verifying…';

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action',     'verify_email_otp');
  fd.append('otp',        otp);

  fetch('profile', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      btn.disabled    = false;
      btn.textContent = 'Verify & Update Email';
      if (data.success) {
        document.getElementById('emailStep2').style.display   = 'none';
        document.getElementById('emailStep3').style.display   = 'block';
        document.getElementById('emailStep2Dot').className    = 'step-dot done';
        document.getElementById('emailStep3Dot').className    = 'step-dot done';
        document.getElementById('emailUpdatedTo').textContent = 'Your email has been updated to ' + data.new_email;
        document.getElementById('currentEmailDisplay').textContent = data.new_email;
      } else {
        showInlineAlert('emailStep2Alert', 'error', data.message);
        clearOtpBoxes('eo', 6);
        document.getElementById('eo0').focus();
      }
    })
    .catch(() => {
      btn.disabled    = false;
      btn.textContent = 'Verify & Update Email';
      showInlineAlert('emailStep2Alert', 'error', 'Network error. Please try again.');
    });
}

function resetEmailFlow() {
  document.getElementById('emailStep1').style.display = 'block';
  document.getElementById('emailStep2').style.display = 'none';
  document.getElementById('emailStep1Dot').className  = 'step-dot active';
  document.getElementById('emailStep2Dot').className  = 'step-dot';
  clearOtpBoxes('eo', 6);
  document.getElementById('emailStep1Alert').className = 'inline-alert';
}

// ── Password change flow ──────────────────────────────────────────────────────
initOtpBoxes('po', 6, null);

function sendPasswordOtp() {
  const btn = document.querySelector('#pwStep1 .btn-action');
  btn.textContent = 'Sending…';
  btn.disabled    = true;

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action',     'request_password_otp');

  fetch('profile', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      btn.textContent = 'Send OTP to My Email';
      btn.disabled    = false;
      if (data.success) {
        document.getElementById('pwStep1').style.display = 'none';
        document.getElementById('pwStep2').style.display = 'block';
        document.getElementById('pwStep1Dot').className  = 'step-dot done';
        document.getElementById('pwStep2Dot').className  = 'step-dot active';
        document.getElementById('po0').focus();
      } else {
        showInlineAlert('pwStep1Alert', 'error', data.message);
      }
    })
    .catch(() => {
      btn.textContent = 'Send OTP to My Email';
      btn.disabled    = false;
      showInlineAlert('pwStep1Alert', 'error', 'Network error. Please try again.');
    });
}

function checkPwReqs(value) {
  const setReq = (id, passed) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.className = 'req-item ' + (passed ? 'valid' : 'invalid');
  };
  setReq('pr_length',  value.length >= 8);
  setReq('pr_upper',   /[A-Z]/.test(value));
  setReq('pr_lower',   /[a-z]/.test(value));
  setReq('pr_number',  /[0-9]/.test(value));
  setReq('pr_special', /[^a-zA-Z0-9]/.test(value));
  checkPwMatch();
}

function checkPwMatch() {
  const pw  = document.getElementById('newPassword')?.value || '';
  const cfm = document.getElementById('confirmPassword')?.value || '';
  const msg = document.getElementById('pwMatchMsg');
  if (!msg) return;
  if (!cfm.length) { msg.textContent = ''; msg.className = 'match-msg'; return; }
  if (pw === cfm) { msg.className = 'match-msg ok'; msg.textContent = '✓ Passwords match'; }
  else            { msg.className = 'match-msg err'; msg.textContent = '✗ Passwords do not match'; }
}

function changePassword() {
  const otp     = getOtpValue('po', 6);
  const pw      = document.getElementById('newPassword').value;
  const confirm = document.getElementById('confirmPassword').value;

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action',     'change_password');
  fd.append('otp',        otp);
  fd.append('password',   pw);
  fd.append('confirm',    confirm);

  fetch('profile', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        document.getElementById('pwStep2').style.display = 'none';
        document.getElementById('pwStep3').style.display = 'block';
        document.getElementById('pwStep2Dot').className  = 'step-dot done';
        document.getElementById('pwStep3Dot').className  = 'step-dot done';
      } else {
        showInlineAlert('pwStep2Alert', 'error', data.message);
        clearOtpBoxes('po', 6);
        document.getElementById('po0').focus();
      }
    })
    .catch(() => showInlineAlert('pwStep2Alert', 'error', 'Network error. Please try again.'));
}

function resetPwFlow() {
  document.getElementById('pwStep1').style.display = 'block';
  document.getElementById('pwStep2').style.display = 'none';
  document.getElementById('pwStep1Dot').className  = 'step-dot active';
  document.getElementById('pwStep2Dot').className  = 'step-dot';
  clearOtpBoxes('po', 6);
  document.getElementById('pwStep1Alert').className = 'inline-alert';
}

// ── Phone input: flags, dial codes, IP-based country auto-detect ──────────────
const phoneInputEl = document.getElementById('phone_number');
if (phoneInputEl) {
  const existingPhone = phoneInputEl.value.trim();
  phoneInputEl.value = '';
  const phoneIti = window.intlTelInput(phoneInputEl, {
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
  if (existingPhone) {
    phoneIti.promise.then(function () { phoneIti.setNumber(existingPhone); });
  }
  document.getElementById('profileEditForm').addEventListener('submit', function () {
    phoneInputEl.value = phoneInputEl.value.trim() === '' ? '' : phoneIti.getNumber();
  });
}
</script>

</body>
</html>