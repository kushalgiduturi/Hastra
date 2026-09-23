<?php
// portals/projects/signoff.php
// Dual-key cryptographic milestone sign-off.
//   Step 1 — the project lead (admin, or the assigned team_lead) initiates.
//            An HMAC-SHA256 signature binds project + milestone + signer +
//            timestamp + IP. Status becomes pending_client.
//   Step 2 — the client stakeholder (the requirement's owner) reviews and,
//            after an emailed OTP confirms it's really them, countersigns.
//            Only once BOTH signatures exist does the milestone read as
//            "completed" — and only a completed "Final Delivery" milestone
//            lets portals/admin/delivery.php mark a project delivered or
//            portals/admin/billing.php generate its invoice.
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn); // admin, employee (team lead) or client — scoped below

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../../PHPMailer/PHPMailer.php';
require __DIR__ . '/../../PHPMailer/SMTP.php';
require __DIR__ . '/../../PHPMailer/Exception.php';

const SIGNOFF_OTP_TTL      = 600; // seconds
const SIGNOFF_OTP_ATTEMPTS = 5;

$user_id   = (int)$_SESSION["user_id"];
$user_role = $_SESSION["user_role"] ?? '';
$msg       = "";
$msg_type  = "error";

function signoff_send_otp($to, $otp) {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host        = MAIL_HOST;
    $mail->SMTPAuth    = MAIL_AUTH;
    $mail->Port        = MAIL_PORT;
    $mail->SMTPSecure  = MAIL_SECURE;
    $mail->SMTPAutoTLS = false;
    $mail->setFrom(MAIL_FROM, MAIL_NAME);
    $mail->addAddress($to);
    $mail->Subject = "Milestone Sign-Off Confirmation Code";
    $mail->Body    = "Your milestone sign-off code is: $otp\n\nExpires in 10 minutes. If you didn't request this, ignore this email.";
    $mail->send();
}

// Is this user the project's team_lead, or an internal admin (either counts
// as "project lead" for initiation)?
function signoff_is_project_lead($conn, $project_id, $user_id, $role) {
    if ($role === 'admin') return true;
    if ($role !== 'employee') return false;
    $q = mysqli_prepare($conn, "SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? AND project_role = 'team_lead'");
    mysqli_stmt_bind_param($q, "ii", $project_id, $user_id);
    mysqli_stmt_execute($q);
    return (bool) mysqli_fetch_row(mysqli_stmt_get_result($q));
}

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $action = $_POST["action"] ?? "";

    if ($action === "initiate") {
        $project_id     = (int)($_POST["project_id"] ?? 0);
        $milestone_name = trim($_POST["milestone_name"] ?? "");

        if (!signoff_is_project_lead($conn, $project_id, $user_id, $user_role)) {
            $msg = "Only an admin or this project's team lead can initiate a milestone sign-off.";
        } else {
            $cq = mysqli_prepare($conn, "SELECT r.user_id FROM projects p JOIN requirements r ON r.id = p.requirement_id WHERE p.id = ?");
            mysqli_stmt_bind_param($cq, "i", $project_id);
            mysqli_stmt_execute($cq);
            $client_row = mysqli_fetch_assoc(mysqli_stmt_get_result($cq));
            $client_user_id = (int)($client_row['user_id'] ?? 0);

            if (!$client_user_id) {
                $msg = "Couldn't resolve the client for this project.";
            } else {
                $ip  = astra_get_client_ip();
                $row = astra_signoff_initiate($conn, $project_id, $milestone_name, $user_id, $client_user_id, $ip, $error);
                if ($row) {
                    $msg = "Sign-off initiated for \"" . htmlspecialchars($milestone_name) . "\". Awaiting the client's countersignature.";
                    $msg_type = "success";
                    try { log_activity($conn, $user_id, "milestone_signoff_initiated", $milestone_name); } catch (Throwable $e) {}
                } else {
                    $msg = $error;
                }
            }
        }
    }

    elseif ($action === "request_client_otp") {
        $project_id     = (int)($_POST["project_id"] ?? 0);
        $milestone_name = trim($_POST["milestone_name"] ?? "");
        $row = astra_signoff_get($conn, $project_id, $milestone_name);

        if ($user_role !== 'client' || !$row || (int)$row['client_user_id'] !== $user_id || $row['status'] !== 'pending_client') {
            $msg = "Nothing awaiting your sign-off here.";
        } else {
            $mq = mysqli_prepare($conn, "SELECT email FROM users WHERE id = ?");
            mysqli_stmt_bind_param($mq, "i", $user_id);
            mysqli_stmt_execute($mq);
            $me = mysqli_fetch_assoc(mysqli_stmt_get_result($mq));
            astra_decrypt_user_row($me);

            $otp = (string) random_int(100000, 999999);
            $_SESSION["signoff_otp"] = [
                "project_id" => $project_id, "milestone_name" => $milestone_name,
                "hash" => password_hash($otp, PASSWORD_DEFAULT), "expires" => time() + SIGNOFF_OTP_TTL, "attempts" => 0,
            ];
            try {
                signoff_send_otp($me["email"], $otp);
                $msg = "A confirmation code was sent to your email. Enter it below to countersign.";
                $msg_type = "success";
            } catch (Exception $e) {
                unset($_SESSION["signoff_otp"]);
                $msg = "Couldn't send the confirmation code. Try again.";
            }
        }
    }

    elseif ($action === "verify_client_otp") {
        $pending = $_SESSION["signoff_otp"] ?? null;
        $entered = trim($_POST["otp"] ?? "");
        $project_id     = (int)($_POST["project_id"] ?? 0);
        $milestone_name = trim($_POST["milestone_name"] ?? "");

        if (!$pending || $pending["project_id"] !== $project_id || $pending["milestone_name"] !== $milestone_name || $pending["expires"] < time()) {
            unset($_SESSION["signoff_otp"]);
            $msg = "This confirmation code has expired. Request a new one.";
        } elseif (++$_SESSION["signoff_otp"]["attempts"] > SIGNOFF_OTP_ATTEMPTS) {
            unset($_SESSION["signoff_otp"]);
            $msg = "Too many wrong attempts. Request a new code.";
        } elseif (!preg_match('/^\d{6}$/', $entered) || !password_verify($entered, $pending["hash"])) {
            $msg = "Incorrect code.";
        } else {
            unset($_SESSION["signoff_otp"]);
            $ip  = astra_get_client_ip();
            $row = astra_signoff_client_sign($conn, $project_id, $milestone_name, $user_id, $ip, $error);
            if ($row) {
                $msg = "Milestone \"" . htmlspecialchars($milestone_name) . "\" is now sealed with both signatures on record.";
                $msg_type = "success";
                try { log_activity($conn, $user_id, "milestone_signoff_completed", $milestone_name); } catch (Throwable $e) {}
            } else {
                $msg = $error;
            }
        }
    }

    elseif ($action === "dispute") {
        $project_id     = (int)($_POST["project_id"] ?? 0);
        $milestone_name = trim($_POST["milestone_name"] ?? "");
        $row = astra_signoff_get($conn, $project_id, $milestone_name);
        $authorized = $row && ($user_role === 'admin' || (int)$row['client_user_id'] === $user_id);
        if (!$authorized) {
            $msg = "Not authorized to dispute this milestone.";
        } elseif (astra_signoff_dispute($conn, $project_id, $milestone_name, $error)) {
            $msg = "Milestone flagged as disputed.";
            $msg_type = "success";
        } else {
            $msg = $error;
        }
    }
}

// ── Data for display ──────────────────────────────────────────────────────────
$rows = [];
if ($user_role === 'admin') {
    $r = mysqli_query($conn, "SELECT * FROM milestone_signoffs ORDER BY created_at DESC LIMIT 100");
    $rows = $r ? mysqli_fetch_all($r, MYSQLI_ASSOC) : [];
} elseif ($user_role === 'employee') {
    $r = mysqli_query($conn,
        "SELECT ms.* FROM milestone_signoffs ms
         JOIN project_members pm ON pm.project_id = ms.project_id AND pm.project_role = 'team_lead'
         WHERE pm.user_id = $user_id ORDER BY ms.created_at DESC LIMIT 100");
    $rows = $r ? mysqli_fetch_all($r, MYSQLI_ASSOC) : [];
} elseif ($user_role === 'client') {
    $r = mysqli_prepare($conn, "SELECT * FROM milestone_signoffs WHERE client_user_id = ? ORDER BY created_at DESC LIMIT 100");
    mysqli_stmt_bind_param($r, "i", $user_id);
    mysqli_stmt_execute($r);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($r), MYSQLI_ASSOC);
}

// project + signer names, decrypted
$project_cache = []; $user_cache = [];
foreach ($rows as &$row) {
    $pid = (int)$row['project_id'];
    if (!isset($project_cache[$pid])) {
        $pq = mysqli_prepare($conn, "SELECT project_code, title FROM projects WHERE id = ?");
        mysqli_stmt_bind_param($pq, "i", $pid); mysqli_stmt_execute($pq);
        $project_cache[$pid] = mysqli_fetch_assoc(mysqli_stmt_get_result($pq)) ?: ['project_code' => '?', 'title' => '?'];
    }
    foreach (['pm_user_id', 'client_user_id'] as $col) {
        $uid = (int)$row[$col];
        if ($uid && !isset($user_cache[$uid])) {
            $uq = mysqli_prepare($conn, "SELECT name FROM users WHERE id = ?");
            mysqli_stmt_bind_param($uq, "i", $uid); mysqli_stmt_execute($uq);
            $u = mysqli_fetch_assoc(mysqli_stmt_get_result($uq));
            astra_decrypt_user_row($u);
            $user_cache[$uid] = $u['name'] ?? "User #$uid";
        }
    }
    $row['_project'] = $project_cache[$pid];
    $row['_pm_name']     = $user_cache[(int)$row['pm_user_id']] ?? '-';
    $row['_client_name'] = $user_cache[(int)$row['client_user_id']] ?? '-';
    $row['_verify'] = astra_signoff_verify($conn, $row);
}
unset($row);

// projects the current lead can initiate a milestone for
$initiable_projects = [];
if ($user_role === 'admin') {
    $r = mysqli_query($conn, "SELECT id, project_code, title FROM projects ORDER BY updated_at DESC LIMIT 100");
    $initiable_projects = $r ? mysqli_fetch_all($r, MYSQLI_ASSOC) : [];
} elseif ($user_role === 'employee') {
    $r = mysqli_query($conn,
        "SELECT p.id, p.project_code, p.title FROM projects p
         JOIN project_members pm ON pm.project_id = p.id AND pm.project_role = 'team_lead'
         WHERE pm.user_id = $user_id ORDER BY p.updated_at DESC");
    $initiable_projects = $r ? mysqli_fetch_all($r, MYSQLI_ASSOC) : [];
}

$pending_otp = $_SESSION['signoff_otp'] ?? null;
$nav_path = $user_role === 'client' ? 'client' : ($user_role === 'employee' ? 'emlpoyee' : 'admin');
$status_labels = ['pending_client' => 'Awaiting client', 'completed' => 'Completed', 'disputed' => 'Disputed'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Milestone Sign-Off · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; background: var(--navy); color: var(--text); font-family: var(--font-sans);
         background-image: linear-gradient(var(--grid-line) 1px, transparent 1px), linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
         background-size: 40px 40px; }
  .main { max-width: 900px; margin: 0 auto; padding: 2rem 1.2rem 4rem; }
  h1 { font-size: 22px; font-weight: 600; margin: 0 0 4px; }
  .lede { color: var(--text-dim); font-size: 13px; margin: 0 0 1.4rem; }
  .section { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; padding: 1.4rem; margin-bottom: 1.4rem; }
  .alert { border-radius: 3px; padding: 12px 14px; margin-bottom: 1.2rem; font-size: 13px; border-left: 3px solid; }
  .alert.success { background: var(--green-bg); border-color: var(--green); color: #86efac; }
  .alert.error   { background: var(--red-bg);   border-color: var(--red);   color: #fca5a5; }
  label { display: block; font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em; margin: 12px 0 6px; }
  input[type="text"], select { width: 100%; background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; font-size: 14px; padding: 9px 12px; outline: none; }
  .btn { background: var(--accent); color: #fff; border: none; border-radius: 3px; font-size: 13px; font-weight: 600;
         letter-spacing: 0.04em; text-transform: uppercase; padding: 9px 18px; cursor: pointer; margin-top: 1rem; }
  .btn:hover { background: var(--accent-dim); }
  .btn.small { padding: 5px 12px; margin: 0; font-size: 11px; }
  .btn.danger { background: var(--red); }
  .card { border: 1px solid var(--border-dim); border-radius: 3px; padding: 1rem; margin-bottom: 10px; }
  .card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
  .badge { padding: 3px 10px; border-radius: 10px; font-size: 10px; text-transform: uppercase; white-space: nowrap; }
  .badge.pending_client { background: var(--yellow-bg, rgba(234,179,8,0.12)); color: var(--yellow, #facc15); }
  .badge.completed { background: var(--green-bg); color: #86efac; }
  .badge.disputed { background: var(--red-bg); color: #fca5a5; }
  .hashline { font-family: 'Share Tech Mono', monospace; font-size: 10.5px; color: var(--text-dim); word-break: break-all; margin-top: 4px; }
  .verify-ok { color: var(--green); } .verify-bad { color: var(--red); }
  .otp-box { background: rgba(255,255,255,0.03); border: 1px dashed var(--border-dim); border-radius: 3px; padding: 1rem; margin-top: 10px; }
  .back { font-size: 12px; color: var(--text-dim); text-decoration: none; }
</style>
</head>
<body>
<div class="main">
  <a class="back" href="<?= get_base_url() ?>portals/<?= $nav_path ?>/<?= $nav_path === 'admin' ? 'admin_portal' : ($nav_path === 'client' ? 'client_portal' : 'employee_portal') ?>">&larr; Back</a>
  <h1>Milestone Sign-Off</h1>
  <p class="lede">A milestone only reads as complete once both the project lead and the client stakeholder have cryptographically signed it. Neither signature alone is enough.</p>

  <?php if ($msg): ?>
  <div class="alert <?= $msg_type ?>"><?= $msg /* already htmlspecialchars'd at each construction site */ ?></div>
  <?php endif; ?>

  <?php if (in_array($user_role, ['admin', 'employee'], true) && $initiable_projects): ?>
  <div class="section">
    <div style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;text-transform:uppercase;margin-bottom:10px;">Initiate sign-off</div>
    <form method="POST" action="signoff">
      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
      <input type="hidden" name="action" value="initiate">
      <label>Project</label>
      <select name="project_id" required>
        <option value="">Select project</option>
        <?php foreach ($initiable_projects as $p): ?>
        <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['project_code'] . ': ' . $p['title']) ?></option>
        <?php endforeach; ?>
      </select>
      <label>Milestone name</label>
      <input type="text" name="milestone_name" value="Final Delivery" maxlength="128" required>
      <button type="submit" class="btn">Sign as Project Lead</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($pending_otp): ?>
  <div class="section otp-box">
    <div style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;text-transform:uppercase;margin-bottom:10px;">Enter your confirmation code</div>
    <form method="POST" action="signoff">
      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
      <input type="hidden" name="action" value="verify_client_otp">
      <input type="hidden" name="project_id" value="<?= (int)$pending_otp['project_id'] ?>">
      <input type="hidden" name="milestone_name" value="<?= htmlspecialchars($pending_otp['milestone_name']) ?>">
      <label>6-digit code</label>
      <input type="text" name="otp" maxlength="6" pattern="\d{6}" required autofocus>
      <button type="submit" class="btn">Confirm & Sign</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="section">
    <div style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;text-transform:uppercase;margin-bottom:10px;">
      Sign-offs <span style="color:var(--text-dim);"><?= count($rows) ?></span>
    </div>
    <?php if (!$rows): ?>
    <p style="color:var(--text-dim);font-size:13px;">No milestone sign-offs yet.</p>
    <?php endif; ?>
    <?php foreach ($rows as $row): ?>
    <div class="card">
      <div class="card-top">
        <div>
          <strong><?= htmlspecialchars($row['milestone_name']) ?></strong>
          <div style="font-size:12px;color:var(--text-dim);"><?= htmlspecialchars($row['_project']['project_code'] . ': ' . $row['_project']['title']) ?></div>
        </div>
        <span class="badge <?= $row['status'] ?>"><?= $status_labels[$row['status']] ?? $row['status'] ?></span>
      </div>

      <div style="font-size:12px;margin-top:10px;color:var(--text-dim);">
        Lead: <strong style="color:var(--text);"><?= htmlspecialchars($row['_pm_name']) ?></strong>
        signed <?= $row['pm_signed_at'] ? htmlspecialchars(date('d M Y, H:i', strtotime($row['pm_signed_at']))) : '-' ?>
        <?php if ($row['_verify']['pm_valid'] !== null): ?>
          <span class="<?= $row['_verify']['pm_valid'] ? 'verify-ok' : 'verify-bad' ?>"><?= $row['_verify']['pm_valid'] ? '✓ verified' : '✗ signature mismatch' ?></span>
        <?php endif; ?>
        <div class="hashline">pm_signature_hash: <?= htmlspecialchars($row['pm_signature_hash'] ?? '-') ?></div>
      </div>

      <div style="font-size:12px;margin-top:8px;color:var(--text-dim);">
        Client: <strong style="color:var(--text);"><?= htmlspecialchars($row['_client_name']) ?></strong>
        <?php if ($row['client_signed_at']): ?>
          signed <?= htmlspecialchars(date('d M Y, H:i', strtotime($row['client_signed_at']))) ?>
          <?php if ($row['_verify']['client_valid'] !== null): ?>
            <span class="<?= $row['_verify']['client_valid'] ? 'verify-ok' : 'verify-bad' ?>"><?= $row['_verify']['client_valid'] ? '✓ verified' : '✗ signature mismatch' ?></span>
          <?php endif; ?>
          <div class="hashline">client_signature_hash: <?= htmlspecialchars($row['client_signature_hash']) ?></div>
        <?php else: ?>
          not yet signed
        <?php endif; ?>
      </div>

      <?php if ($user_role === 'client' && $row['status'] === 'pending_client' && (int)$row['client_user_id'] === $user_id && !$pending_otp): ?>
      <form method="POST" action="signoff" style="margin-top:10px;">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action" value="request_client_otp">
        <input type="hidden" name="project_id" value="<?= (int)$row['project_id'] ?>">
        <input type="hidden" name="milestone_name" value="<?= htmlspecialchars($row['milestone_name']) ?>">
        <button type="submit" class="btn small">Review & Sign</button>
      </form>
      <?php endif; ?>

      <?php if ($row['status'] !== 'disputed' && ($user_role === 'admin' || ((int)$row['client_user_id'] === $user_id))): ?>
      <form method="POST" action="signoff" style="margin-top:6px;display:inline-block;" onsubmit="return confirm('Flag this milestone as disputed?')">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action" value="dispute">
        <input type="hidden" name="project_id" value="<?= (int)$row['project_id'] ?>">
        <input type="hidden" name="milestone_name" value="<?= htmlspecialchars($row['milestone_name']) ?>">
        <button type="submit" class="btn small danger">Dispute</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</body>
</html>
