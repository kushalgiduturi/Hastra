<?php
// portals/admin/delivery.php
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

// ── Allow the client's PM to open the one-time security summary again ─────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST["action"] ?? "") === "reset_security_view" && access_schema_ready($conn)) {
    verify_csrf_token();
    $delivery_id = (int)($_POST["delivery_id"] ?? 0);
    $rs = mysqli_prepare($conn, "UPDATE deliveries SET security_viewed_at = NULL, security_viewed_by = NULL WHERE id = ?");
    mysqli_stmt_bind_param($rs, "i", $delivery_id);
    mysqli_stmt_execute($rs);
    $cl = mysqli_prepare($conn, "DELETE FROM security_disclosures WHERE delivery_id = ?");
    mysqli_stmt_bind_param($cl, "i", $delivery_id);
    mysqli_stmt_execute($cl);
    try { log_activity($conn, $_SESSION["user_id"], "security_view_reset"); } catch (Throwable $e) {}
    $msg = "The client's Project Manager can open the security summary once more.";
    $msg_type = "success";
}

// ── MARK PROJECT DELIVERED ────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] === "mark_delivered") {
    verify_csrf_token();

    $project_id   = (int)($_POST["project_id"] ?? 0);
    $source_link  = trim($_POST["source_code_link"] ?? "");
    $docs_link    = trim($_POST["documentation_link"] ?? "");
    $deploy_link  = trim($_POST["deployment_link"] ?? "");
    $cred_note    = trim($_POST["credentials_note"] ?? "");

    $pcheck = mysqli_prepare($conn, "SELECT id, status FROM projects WHERE id = ?");
    mysqli_stmt_bind_param($pcheck, "i", $project_id);
    mysqli_stmt_execute($pcheck);
    $proj = mysqli_fetch_assoc(mysqli_stmt_get_result($pcheck));

    $already = mysqli_prepare($conn, "SELECT id FROM deliveries WHERE project_id = ?");
    mysqli_stmt_bind_param($already, "i", $project_id);
    mysqli_stmt_execute($already);
    mysqli_stmt_store_result($already);

    if (!$proj || $proj['status'] !== 'completed') {
        $msg = "Project must be completed before marking it delivered.";
        $msg_type = "error";
    } elseif (mysqli_stmt_num_rows($already) > 0) {
        $msg = "This project has already been delivered.";
        $msg_type = "error";
    } elseif (!$source_link && !$docs_link && !$deploy_link) {
        $msg = "Provide at least one link (source code, docs, or deployment).";
        $msg_type = "error";
    } elseif (($source_link !== "" && !safe_url($source_link)) || ($docs_link !== "" && !safe_url($docs_link)) || ($deploy_link !== "" && !safe_url($deploy_link))) {
        $msg = "Links must be full web addresses starting with http:// or https://.";
        $msg_type = "error";
    } else {
        $admin_id = (int)$_SESSION["user_id"];
        if ($cred_note !== "" && crypto_available()) {
            $cred_note = encrypt_secret($cred_note);
        }
        $ins = mysqli_prepare($conn,
            "INSERT INTO deliveries (project_id, delivered_by, source_code_link, documentation_link, deployment_link, credentials_note)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($ins, "isssss", $project_id, $admin_id, $source_link, $docs_link, $deploy_link, $cred_note);

        if (mysqli_stmt_execute($ins)) {
            $client_q = mysqli_prepare($conn,
                "SELECT u.name, u.email, p.project_code, p.title FROM projects p
                 JOIN requirements r ON p.requirement_id = r.id
                 JOIN users u ON r.user_id = u.id
                 WHERE p.id = ?"
            );
            mysqli_stmt_bind_param($client_q, "i", $project_id);
            mysqli_stmt_execute($client_q);
            $client_data = mysqli_fetch_assoc(mysqli_stmt_get_result($client_q));
            astra_decrypt_user_row($client_data);

            if ($client_data) {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host        = MAIL_HOST;
                    $mail->SMTPAuth    = MAIL_AUTH;
                    $mail->Port        = MAIL_PORT;
                    $mail->SMTPSecure  = MAIL_SECURE;
                    $mail->SMTPAutoTLS = false;
                    $mail->setFrom(MAIL_FROM, MAIL_NAME);
                    $mail->addAddress($client_data["email"]);
                    $mail->Subject = "Project Delivered — {$client_data['project_code']}";

                    $links_section = "";
                    if ($source_link) $links_section .= "Source Code: $source_link\n";
                    if ($docs_link)   $links_section .= "Documentation: $docs_link\n";
                    if ($deploy_link) $links_section .= "Live Deployment: $deploy_link\n";

                    $mail->Body =
                        "Hi {$client_data['name']},\n\n" .
                        "Great news — your project has been delivered!\n\n" .
                        "Project: {$client_data['project_code']} — {$client_data['title']}\n\n" .
                        $links_section .
                        "\nPlease log in to your portal for full delivery details.\n\nRegards,\nAstra Team";
                    $mail->send();
                } catch (Exception $e) {
                    // Silent fail — delivery still recorded
                }
            }

            $msg      = "Project marked as delivered to the client.";
            $msg_type = "success";
        } else {
            $msg = "Failed to record delivery.";
            $msg_type = "error";
        }
    }
}

// ── Fetch completed projects without a delivery record yet ────────────────────
$deliverable_result = mysqli_query($conn,
    "SELECT p.id, p.project_code, p.title
     FROM projects p
     LEFT JOIN deliveries d ON d.project_id = p.id
     WHERE p.status = 'completed' AND d.id IS NULL
     ORDER BY p.updated_at DESC"
);
$deliverable_projects = [];
while ($row = mysqli_fetch_assoc($deliverable_result)) $deliverable_projects[] = $row;

// ── Fetch all deliveries ────────────────────────────────────────────────────────
$deliveries_result = mysqli_query($conn,
    "SELECT d.*, p.project_code, p.title AS project_title, u.name AS delivered_by_name
     FROM deliveries d
     JOIN projects p ON d.project_id = p.id
     JOIN users u ON d.delivered_by = u.id
     ORDER BY d.delivered_at DESC"
);
$all_deliveries = [];
while ($row = mysqli_fetch_assoc($deliveries_result)) {
    $row['delivered_by_name'] = astra_db_decrypt($row['delivered_by_name']);
    $all_deliveries[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Delivery · Astra</title>
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

  .tbl-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead tr { background: var(--section-header-bg); border-bottom: 1px solid var(--border-dim); }
  th { padding: 10px 14px; text-align: left; font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); font-weight: 400; white-space: nowrap; }
  tbody tr { border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.15s; }
  tbody tr:hover { background: var(--hover-bg); }
  tbody tr:last-child { border-bottom: none; }
  td { padding: 11px 14px; color: var(--text); vertical-align: middle; }
  td.muted, .muted { color: var(--text-dim); font-family: 'Share Tech Mono', monospace; font-size: 12px; }

  .req-id-badge { font-family: 'Share Tech Mono', monospace; font-size: 12px; font-weight: 600; color: var(--accent-bright); background: rgba(var(--accent-rgb),0.08); border: 1px solid rgba(var(--accent-rgb),0.2); padding: 2px 8px; border-radius: 2px; letter-spacing: 0.08em; }

  .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
  .field { margin-bottom: 1rem; }
  label { display: block; font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; }
  input[type="text"], select {
    width: 100%; background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; color: var(--text); font-size: 14px; padding: 9px 12px; outline: none;
    transition: border-color 0.2s;
  }
  input:focus, select:focus { border-color: var(--accent-bright); }
  select option { background: var(--navy-card); }
  .btn-create { background: var(--accent); color: white; border: none; border-radius: 3px; font-size: 13px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; padding: 10px 20px; cursor: pointer; margin-top: 0.5rem; transition: background 0.2s; }
  .btn-create:hover { background: var(--accent-dim); }

  .empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-dim); font-size: 13px; }
  .empty-state svg { width: 32px; height: 32px; fill: var(--border-dim); margin: 0 auto 0.8rem; display: block; }

  @media (max-width: 768px) {
    .form-grid { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<?php include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>Delivery</h1>
    <p>Mark completed projects as delivered and track delivery records.</p>
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

  <div class="section" id="sec-delivery">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm13.5-9l1.96 2.5H17V9.5h2.5zM18 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
        Delivery
        <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($all_deliveries) ?> delivered</span>
      </div>
    </div>
    <div class="section-body">

      <?php if (!empty($deliverable_projects)): ?>
      <div style="background:var(--navy-deep,#0d1424);border:1px solid var(--border-dim);border-radius:3px;padding:1.2rem 1.4rem;margin-bottom:1.4rem;">
        <div style="font-size:11px;font-family:'Share Tech Mono',monospace;letter-spacing:0.08em;text-transform:uppercase;color:var(--text-dim);margin-bottom:10px;">Mark Project Delivered</div>
        <form method="POST" action="delivery">
          <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
          <input type="hidden" name="action" value="mark_delivered">
          <div class="field">
            <label>Project</label>
            <select name="project_id" required>
              <option value="">— Select Completed Project —</option>
              <?php foreach ($deliverable_projects as $dp): ?>
              <option value="<?= $dp['id'] ?>"><?= htmlspecialchars($dp['project_code'] . ' — ' . $dp['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-grid">
            <div class="field">
              <label>Source Code Link <span style="text-transform:none;color:#475569;font-weight:400;">(GitHub/Drive)</span></label>
              <input type="text" name="source_code_link" placeholder="https://github.com/...">
            </div>
            <div class="field">
              <label>Documentation Link</label>
              <input type="text" name="documentation_link" placeholder="https://docs...">
            </div>
          </div>
          <div class="field">
            <label>Deployment Link <span style="text-transform:none;color:#475569;font-weight:400;">(optional)</span></label>
            <input type="text" name="deployment_link" placeholder="https://yourapp.com">
          </div>
          <div class="field">
            <label>Credentials Note <span style="text-transform:none;color:#475569;font-weight:400;">(sent securely, optional)</span></label>
            <input type="text" name="credentials_note" placeholder="Admin login: ... / DB creds: ..." autocomplete="off">
          </div>
          <button type="submit" class="btn-create">Mark Delivered</button>
        </form>
      </div>
      <?php endif; ?>

      <?php if (empty($all_deliveries)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4z"/></svg>
        No projects delivered yet.
      </div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>Project</th><th>Source</th><th>Docs</th><th>Deployment</th><th>Delivered By</th><th>Date</th><th>Security summary</th></tr></thead>
          <tbody>
            <?php foreach ($all_deliveries as $d): ?>
            <tr>
              <td>
                <span class="req-id-badge"><?= htmlspecialchars($d['project_code']) ?></span>
                <div style="font-size:12px;color:var(--text-dim);margin-top:3px;"><?= htmlspecialchars($d['project_title']) ?></div>
              </td>
              <td><?= safe_url($d['source_code_link']) ? '<a href="'.htmlspecialchars(safe_url($d['source_code_link'])).'" target="_blank" rel="noopener noreferrer" style="color:var(--accent-bright);">Link</a>' : '<span class="muted">—</span>' ?></td>
              <td><?= safe_url($d['documentation_link']) ? '<a href="'.htmlspecialchars(safe_url($d['documentation_link'])).'" target="_blank" rel="noopener noreferrer" style="color:var(--accent-bright);">Link</a>' : '<span class="muted">—</span>' ?></td>
              <td><?= safe_url($d['deployment_link']) ? '<a href="'.htmlspecialchars(safe_url($d['deployment_link'])).'" target="_blank" rel="noopener noreferrer" style="color:var(--accent-bright);">Link</a>' : '<span class="muted">—</span>' ?></td>
              <td class="muted"><?= htmlspecialchars($d['delivered_by_name']) ?></td>
              <td class="muted"><?= date('d M Y', strtotime($d['delivered_at'])) ?></td>
              <td>
                <?php if (!empty($d['security_viewed_at'])): ?>
                <form method="POST" action="delivery" onsubmit="return confirm('Let the client open the security summary one more time?')">
                  <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                  <input type="hidden" name="action" value="reset_security_view">
                  <input type="hidden" name="delivery_id" value="<?= (int)$d['id'] ?>">
                  <span class="muted">Viewed <?= date('d M', strtotime($d['security_viewed_at'])) ?></span>
                  <button type="submit" style="margin-left:6px;background:none;border:1px solid var(--border);color:var(--text-dim);font-size:11px;padding:3px 8px;border-radius:3px;cursor:pointer;">Allow again</button>
                </form>
                <?php elseif (access_schema_ready($conn)): ?>
                <span class="muted">Not viewed yet</span>
                <?php else: ?>
                <span class="muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

</body>
</html>
