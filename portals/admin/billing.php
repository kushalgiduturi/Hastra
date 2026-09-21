<?php
// portals/admin/billing.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "admin");

$msg      = "";
$msg_type = "error";

// ── GENERATE INVOICE ───────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] === "generate_invoice") {
    verify_csrf_token();

    $project_id  = (int)($_POST["project_id"] ?? 0);
    $dev_hours   = (float)($_POST["dev_hours"] ?? 0);
    $hourly_rate = (float)($_POST["hourly_rate"] ?? 0);
    $infra_cost  = (float)($_POST["infra_cost"] ?? 0);
    $notes       = trim($_POST["invoice_notes"] ?? "");

    if (!$project_id || $dev_hours <= 0 || $hourly_rate <= 0) {
        $msg = "Project, dev hours and hourly rate are required.";
        $msg_type = "error";
    } else {
        $pcheck = mysqli_prepare($conn, "SELECT id, status, project_code FROM projects WHERE id = ?");
        mysqli_stmt_bind_param($pcheck, "i", $project_id);
        mysqli_stmt_execute($pcheck);
        $proj = mysqli_fetch_assoc(mysqli_stmt_get_result($pcheck));

        $already = mysqli_prepare($conn, "SELECT id FROM invoices WHERE project_id = ?");
        mysqli_stmt_bind_param($already, "i", $project_id);
        mysqli_stmt_execute($already);
        mysqli_stmt_store_result($already);

        if (!$proj || $proj['status'] !== 'completed') {
            $msg = "Project must be completed before generating an invoice.";
            $msg_type = "error";
        } elseif (!astra_signoff_is_complete($conn, $project_id)) {
            $msg = "This project needs a completed dual-key \"" . ASTRA_DELIVERY_MILESTONE . "\" milestone sign-off (both the project lead and the client) before an invoice can be generated.";
            $msg_type = "error";
        } elseif (mysqli_stmt_num_rows($already) > 0) {
            $msg = "An invoice already exists for this project.";
            $msg_type = "error";
        } else {
            $total = ($dev_hours * $hourly_rate) + $infra_cost;

            $invoice_code = next_code($conn, "INV");
            $admin_id = (int)$_SESSION["user_id"];

            $ins = mysqli_prepare($conn,
                "INSERT INTO invoices (invoice_code, project_id, generated_by, dev_hours, hourly_rate, infra_cost, total_amount, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($ins, "siidddds",
                $invoice_code, $project_id, $admin_id, $dev_hours, $hourly_rate, $infra_cost, $total, $notes
            );

            if (mysqli_stmt_execute($ins)) {
                $msg      = "Invoice <strong>$invoice_code</strong> generated — total <strong>₹" . number_format($total, 2) . "</strong>.";
                $msg_type = "success";
            } else {
                $msg = "Failed to generate invoice.";
                $msg_type = "error";
            }
        }
    }
}

// ── MARK INVOICE PAID ─────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] === "mark_invoice_paid") {
    verify_csrf_token();
    $invoice_id = (int)($_POST["invoice_id"] ?? 0);
    $upd = mysqli_prepare($conn, "UPDATE invoices SET status = 'paid', paid_at = NOW() WHERE id = ? AND status != 'paid'");
    mysqli_stmt_bind_param($upd, "i", $invoice_id);
    if (mysqli_stmt_execute($upd) && mysqli_affected_rows($conn) > 0) {
        $msg = "Invoice marked as paid."; $msg_type = "success";
    } else {
        $msg = "Could not update invoice."; $msg_type = "error";
    }
}

// ── Fetch completed projects without an invoice yet ───────────────────────────
$billable_result = mysqli_query($conn,
    "SELECT p.id, p.project_code, p.title
     FROM projects p
     LEFT JOIN invoices i ON i.project_id = p.id
     WHERE p.status = 'completed' AND i.id IS NULL
     ORDER BY p.updated_at DESC"
);
$billable_projects = [];
while ($row = mysqli_fetch_assoc($billable_result)) $billable_projects[] = $row;

// ── Fetch all invoices ─────────────────────────────────────────────────────────
$invoices_result = mysqli_query($conn,
    "SELECT inv.*, p.project_code, p.title AS project_title, u.name AS generated_by_name
     FROM invoices inv
     JOIN projects p ON inv.project_id = p.id
     JOIN users u ON inv.generated_by = u.id
     ORDER BY inv.created_at DESC"
);
$all_invoices = [];
while ($row = mysqli_fetch_assoc($invoices_result)) {
    $row['generated_by_name'] = astra_db_decrypt($row['generated_by_name']);
    $all_invoices[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Billing · Astra</title>
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
  td.muted { color: var(--text-dim); font-family: 'Share Tech Mono', monospace; font-size: 12px; }

  .badge { display: inline-block; padding: 2px 8px; border-radius: 2px; font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.05em; text-transform: uppercase; font-weight: 500; }
  .badge-success { background: rgba(34,197,94,0.1); color: var(--green); border: 1px solid rgba(34,197,94,0.2); }
  .badge-warning { background: rgba(245,158,11,0.1); color: var(--yellow); border: 1px solid rgba(245,158,11,0.2); }

  .req-id-badge { font-family: 'Share Tech Mono', monospace; font-size: 12px; font-weight: 600; color: var(--accent-bright); background: rgba(var(--accent-rgb),0.08); border: 1px solid rgba(var(--accent-rgb),0.2); padding: 2px 8px; border-radius: 2px; letter-spacing: 0.08em; }
  .btn-expand { background: none; border: none; color: var(--accent-bright); font-size: 12px; cursor: pointer; text-decoration: underline; padding: 0; }

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
    <h1>Billing</h1>
    <p>Generate invoices for completed projects and track payment status.</p>
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

  <div class="section" id="sec-billing">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg>
        Billing
        <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($all_invoices) ?> invoice<?= count($all_invoices) !== 1 ? 's' : '' ?></span>
      </div>
    </div>
    <div class="section-body">

      <?php if (!empty($billable_projects)): ?>
      <div style="background:var(--navy-deep,#0d1424);border:1px solid var(--border-dim);border-radius:3px;padding:1.2rem 1.4rem;margin-bottom:1.4rem;">
        <div style="font-size:11px;font-family:'Share Tech Mono',monospace;letter-spacing:0.08em;text-transform:uppercase;color:var(--text-dim);margin-bottom:10px;">Generate Invoice</div>
        <form method="POST" action="billing">
          <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
          <input type="hidden" name="action" value="generate_invoice">
          <div class="form-grid">
            <div class="field">
              <label>Project</label>
              <select name="project_id" required>
                <option value="">— Select Completed Project —</option>
                <?php foreach ($billable_projects as $bp): ?>
                <option value="<?= $bp['id'] ?>"><?= htmlspecialchars($bp['project_code'] . ' — ' . $bp['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Dev Hours</label>
              <input type="text" name="dev_hours" required placeholder="e.g. 150">
            </div>
          </div>
          <div class="form-grid">
            <div class="field">
              <label>Hourly Rate (₹)</label>
              <input type="text" name="hourly_rate" required placeholder="e.g. 500">
            </div>
            <div class="field">
              <label>Infra Cost (₹) <span style="text-transform:none;color:#475569;font-weight:400;">(optional)</span></label>
              <input type="text" name="infra_cost" placeholder="e.g. 5000">
            </div>
          </div>
          <div class="field">
            <label>Notes <span style="text-transform:none;color:#475569;font-weight:400;">(optional)</span></label>
            <input type="text" name="invoice_notes" placeholder="Internal billing notes">
          </div>
          <button type="submit" class="btn-create">Generate Invoice</button>
        </form>
      </div>
      <?php endif; ?>

      <?php if (empty($all_invoices)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>
        No invoices generated yet.
      </div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>Invoice</th><th>Project</th><th>Hours</th><th>Rate</th><th>Infra</th><th>Total</th><th>Status</th><th>Generated</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($all_invoices as $inv): ?>
            <tr>
              <td><span class="req-id-badge"><?= htmlspecialchars($inv['invoice_code']) ?></span></td>
              <td>
                <span class="req-id-badge"><?= htmlspecialchars($inv['project_code']) ?></span>
                <div style="font-size:12px;color:var(--text-dim);margin-top:3px;"><?= htmlspecialchars($inv['project_title']) ?></div>
              </td>
              <td class="muted"><?= number_format($inv['dev_hours'], 1) ?> hrs</td>
              <td class="muted">₹<?= number_format($inv['hourly_rate'], 2) ?></td>
              <td class="muted">₹<?= number_format($inv['infra_cost'], 2) ?></td>
              <td><strong>₹<?= number_format($inv['total_amount'], 2) ?></strong></td>
              <td>
                <span class="badge badge-<?= $inv['status'] === 'paid' ? 'success' : 'warning' ?>"><?= htmlspecialchars($inv['status']) ?></span>
              </td>
              <td class="muted"><?= date('d M Y', strtotime($inv['created_at'])) ?></td>
              <td>
                <?php if ($inv['status'] !== 'paid'): ?>
                <form method="POST" action="billing">
                  <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                  <input type="hidden" name="action" value="mark_invoice_paid">
                  <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                  <button type="submit" class="btn-expand">Mark Paid</button>
                </form>
                <?php else: ?>
                <span class="muted">Paid <?= $inv['paid_at'] ? date('d M Y', strtotime($inv['paid_at'])) : '' ?></span>
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
