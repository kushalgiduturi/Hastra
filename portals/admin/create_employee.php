<?php
// portals/admin/create_employee.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "admin");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../../PHPMailer/PHPMailer.php';
require '../../PHPMailer/SMTP.php';
require '../../PHPMailer/Exception.php';

$msg      = "";
$msg_type = "error";

// ── AJAX: suggest email based on name ────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] === "suggest_email") {
    verify_csrf_token();
    $name    = trim($_POST["full_name"] ?? "");
    $company = get_company($conn, (int)($_POST["company_id"] ?? 0)) ?? get_internal_company($conn);
    $domain  = company_email_domain($company);
    echo json_encode([
        "email"  => $name !== "" ? generate_employee_email($conn, $name, $domain) : "",
        "domain" => $domain,
    ]);
    exit();
}

// ── Create employee account ───────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] === "create_employee") {
    verify_csrf_token();

    $full_name  = trim($_POST["full_name"] ?? "");
    $email      = trim($_POST["employee_email"] ?? "");
    $phone      = trim($_POST["phone_number"] ?? "");
    $company_id = (int)($_POST["company_id"] ?? 0);

    $company = company_schema_ready($conn)
        ? ($company_id ? get_company($conn, $company_id) : get_internal_company($conn))
        : null;
    $domain  = company_email_domain($company);

    if ($full_name === "" || $email === "" || $phone === "") {
        $msg      = "All fields are required to create an employee.";
        $msg_type = "error";
    } elseif (company_schema_ready($conn) && !$company) {
        $msg      = "Choose the company this employee belongs to.";
        $msg_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg      = "Please enter a valid email address.";
        $msg_type = "error";
    } elseif (!email_matches_domain($email, $domain)) {
        $msg      = "Employees of " . ($company["company_name"] ?? INTERNAL_COMPANY_NAME) . " need an email ending in $domain.";
        $msg_type = "error";
    } elseif (!preg_match('/^\+?[0-9\s\-]{7,15}$/', $phone)) {
        $msg      = "Please enter a valid phone number.";
        $msg_type = "error";
    } else {
        $check = mysqli_prepare($conn, "SELECT id FROM users WHERE email_bindex = ?");
        $email_bindex_check = astra_blind_index($email);
        mysqli_stmt_bind_param($check, "s", $email_bindex_check);
        mysqli_stmt_execute($check);
        mysqli_stmt_store_result($check);

        if (mysqli_stmt_num_rows($check) > 0) {
            $msg      = "An account with that email already exists.";
            $msg_type = "error";
        } else {
            $placeholder_hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID);

            $create_error = null;
            $new_user_id  = insert_user_in_company($conn, $company ?? get_internal_company($conn), [
                'name'         => $full_name,
                'email'        => $email,
                'phone_number' => $phone,
                'password'     => $placeholder_hash,
                'role'         => 'pending_employee',
            ], $create_error);

            if ($new_user_id) {

                $token        = bin2hex(random_bytes(32));
                $token_insert = mysqli_prepare($conn,
                    "INSERT INTO password_set_tokens (user_id, token, token_bindex) VALUES (?, ?, ?)"
                );
                $token_enc    = astra_db_encrypt($token);
                $token_bindex = astra_blind_index($token);
                mysqli_stmt_bind_param($token_insert, "iss", $new_user_id, $token_enc, $token_bindex);
                mysqli_stmt_execute($token_insert);

                $set_link = "http://" . $_SERVER['HTTP_HOST'] . get_base_url() . "set-password?token=" . $token;

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
                    $mail->Subject = 'Set up your Hastra account';
                    $mail->Body    =
                        "Hi $full_name,\n\n" .
                        "An account has been created for you at Hastra.\n\n" .
                        "Click the link below to set your password:\n$set_link\n\n" .
                        "This link expires in 24 hours.\n\n" .
                        "Your account will be active once sysadmin approves it.";
                    $mail->send();
                    $msg      = "Employee account created successfully. Setup email sent to $email.";
                    $msg_type = "success";
                } catch (Exception $e) {
                    $msg      = "Account created but email failed to send. Share this link manually: $set_link";
                    $msg_type = "error";
                }
            } else {
                $msg      = $create_error ?? "Failed to create employee account.";
                $msg_type = "error";
            }
        }
    }
}

$employee_companies = list_companies($conn);
$internal_company    = get_internal_company($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Employee · Hastra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/custom-dropdowns.css?v=<?= ASSET_VERSION ?>">
<script src="<?= get_base_url() ?>assets/js/custom-dropdowns.js?v=<?= ASSET_VERSION ?>"></script>
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

  .main { max-width: 1200px; margin: 0 auto; padding: 2rem; }
  .page-header { margin-bottom: 2rem; }
  .page-header h1 { font-size: 24px; font-weight: 600; letter-spacing: -0.01em; margin-bottom: 4px; }
  .page-header p { font-size: 13px; color: var(--text-dim); }

  .section { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; overflow: hidden; margin-bottom: 1.5rem; }
  .section-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.4rem; border-bottom: 1px solid var(--border-dim); background: var(--section-header-bg); }
  .section-title { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; }
  .section-title svg { width: 15px; height: 15px; fill: var(--accent-bright); }
  .section-body { padding: 1.4rem; }

  .alert { display: flex; align-items: flex-start; gap: 8px; border-radius: 3px; padding: 10px 14px; margin-bottom: 1.2rem; font-size: 13px; border-left: 3px solid; line-height: 1.5; }
  .alert.success { background: var(--green-bg); border-color: var(--green); color: #86efac; }
  .alert.error   { background: var(--red-bg);   border-color: var(--red);   color: #fca5a5; }
  .alert svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; }
  .alert.success svg { fill: var(--green); }
  .alert.error   svg { fill: var(--red); }

  .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
  .field { margin-bottom: 1rem; }
  label { display: block; font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; }
  input[type="text"], input[type="email"], input[type="tel"], #createForm select {
    width: 100%; background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; color: var(--text); font-size: 14px; padding: 9px 12px; outline: none;
    transition: border-color 0.2s;
  }
  input:focus, #createForm select:focus { border-color: var(--accent-bright); }
  #createForm select option { background: var(--navy-card); }
  .email-hint { font-size: 11px; color: var(--text-dim); margin-top: 5px; }
  .btn-create {
    background: var(--accent); color: white; border: none; border-radius: 3px;
    font-size: 13px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase;
    padding: 10px 20px; cursor: pointer; margin-top: 0.5rem; transition: background 0.2s;
  }
  .btn-create:hover { background: var(--accent-dim); }
</style>
</head>
<body>

<?php include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>Create Employee</h1>
    <p>Create a new employee account and send them a setup link.</p>
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

  <div class="section" id="sec-create-employee">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M12 4a4 4 0 110 8 4 4 0 010-8zm0 10c4.42 0 8 1.79 8 4v2H4v-2c0-2.21 3.58-4 8-4z"/></svg>
        Create Employee Account
      </div>
    </div>
    <div class="section-body">
      <form method="POST" action="create-employee" id="createForm">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action" value="create_employee">
        <div class="form-grid">
          <div class="field">
            <label for="full_name">Full Name</label>
            <input type="text" name="full_name" id="full_name" maxlength="100" required
                   placeholder="John Doe" oninput="suggestEmail()">
          </div>
          <div class="field">
            <label for="phone_number">Phone Number</label>
            <input type="tel" name="phone_number" id="phone_number" maxlength="20" required
                   placeholder="+91 98765 43210">
          </div>
        </div>
        <div class="field">
          <label for="company_id">Company</label>
          <select name="company_id" id="company_id" onchange="suggestEmail(true)" <?= $employee_companies ? 'required' : '' ?>>
            <?php if (!$employee_companies): ?>
            <option value="" data-domain="<?= htmlspecialchars(EMPLOYEE_EMAIL_DOMAIN) ?>"><?= htmlspecialchars(INTERNAL_COMPANY_NAME) ?> (<?= htmlspecialchars(EMPLOYEE_EMAIL_DOMAIN) ?>)</option>
            <?php endif; ?>
            <?php foreach ($employee_companies as $co): ?>
            <option value="<?= (int)$co['id'] ?>" data-domain="@<?= htmlspecialchars($co['email_domain']) ?>"
              <?= !empty($co['is_internal']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($co['company_name']) ?> (@<?= htmlspecialchars($co['email_domain']) ?>)<?= !empty($co['is_internal']) ? ' · internal' : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="employee_email">Employee Email</label>
          <input type="email" name="employee_email" id="employee_email" maxlength="100" required
                 placeholder="Auto-suggested as you type the name">
          <div class="email-hint">Must end with <span id="emailDomainHint"><?= htmlspecialchars(company_email_domain($internal_company)) ?></span>. It is auto-suggested from the company and editable before saving.</div>
        </div>
        <button type="submit" class="btn-create">Create Employee</button>
      </form>
    </div>
  </div>

</div>

<script>
let debounceTimer;
function suggestEmail(companyChanged = false) {
  const companySelect = document.getElementById('company_id');
  const picked = companySelect.options[companySelect.selectedIndex];
  if (picked && picked.dataset.domain) {
    document.getElementById('emailDomainHint').textContent = picked.dataset.domain;
  }
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(async () => {
    const name = document.getElementById('full_name').value.trim();
    if (!name) return;
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('#createForm [name=csrf_token]').value);
    fd.append('action', 'suggest_email');
    fd.append('full_name', name);
    fd.append('company_id', companySelect.value);
    try {
      const res  = await fetch('create-employee', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.email) document.getElementById('employee_email').value = data.email;
    } catch(e) {}
  }, companyChanged ? 0 : 400);
}
</script>

</body>
</html>
