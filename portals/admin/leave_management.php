<?php
// portals/admin/leave_management.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "admin");

$msg      = "";
$msg_type = "error";

$company = get_internal_company($conn);
$company_id = $company["id"] ?? null;

// ── ACTION: save_leave_policy ─────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_leave_policy" && $company_id) {
    verify_csrf_token();

    $leave_cycle  = in_array($_POST["leave_cycle"] ?? "", ["monthly", "yearly_rollover"], true) ? $_POST["leave_cycle"] : "monthly";
    $gen    = max(0, min(30, (int)($_POST["monthly_general_leaves"] ?? 1)));
    $sick   = max(0, min(30, (int)($_POST["monthly_sick_leaves"] ?? 1)));
    $annual = max(0, min(90, (int)($_POST["annual_leave_allowance"] ?? 18)));

    $upd = mysqli_prepare($conn,
        "UPDATE companies SET leave_cycle = ?, monthly_general_leaves = ?, monthly_sick_leaves = ?, annual_leave_allowance = ? WHERE id = ?"
    );
    mysqli_stmt_bind_param($upd, "siiii", $leave_cycle, $gen, $sick, $annual, $company_id);
    if (mysqli_stmt_execute($upd)) {
        $msg      = "Leave policy updated.";
        $msg_type = "success";
        $company  = get_company($conn, $company_id);
    } else {
        $msg = "Failed to update leave policy.";
    }
}

// ── ACTION: review_leave (approve / reject) ───────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "review_leave") {
    verify_csrf_token();

    $leave_id   = (int)($_POST["leave_id"] ?? 0);
    $new_status = $_POST["new_status"] ?? "";

    if (!$leave_id || !in_array($new_status, ["approved", "rejected"], true)) {
        $msg = "Invalid request.";
    } else {
        $upd = mysqli_prepare($conn,
            "UPDATE leave_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $admin_id = (int)$_SESSION["user_id"];
        mysqli_stmt_bind_param($upd, "sii", $new_status, $admin_id, $leave_id);
        if (mysqli_stmt_execute($upd) && mysqli_stmt_affected_rows($upd) > 0) {
            $msg      = "Leave request " . $new_status . ".";
            $msg_type = "success";
            if ($new_status === "approved") {
                $lq = mysqli_prepare($conn, "SELECT * FROM leave_requests WHERE id = ?");
                mysqli_stmt_bind_param($lq, "i", $leave_id);
                mysqli_stmt_execute($lq);
                $days = astra_leave_apply_to_attendance($conn, mysqli_fetch_assoc(mysqli_stmt_get_result($lq)), $admin_id);
                if ($days) $msg .= " $days day(s) marked On Leave in attendance.";
            }
        } else {
            $msg = "That request was already reviewed.";
        }
    }
}

// ── Fetch all leave requests ──────────────────────────────────────────────────
$leave_result = mysqli_query($conn,
    "SELECT lr.*, u.name AS employee_name, u.email AS employee_email, r.name AS reviewer_name
     FROM leave_requests lr
     JOIN users u ON u.id = lr.user_id
     LEFT JOIN users r ON r.id = lr.reviewed_by
     ORDER BY FIELD(lr.status,'pending','approved','rejected'), lr.created_at DESC"
);
$all_leave = mysqli_fetch_all($leave_result, MYSQLI_ASSOC);
foreach ($all_leave as &$__lr) {
    $__lr['employee_name']  = astra_db_decrypt($__lr['employee_name']);
    $__lr['employee_email'] = astra_db_decrypt($__lr['employee_email']);
    $__lr['reviewer_name']  = astra_db_decrypt($__lr['reviewer_name']);
}
unset($__lr);

$pending_count  = count(array_filter($all_leave, fn($l) => $l["status"] === "pending"));
$approved_count = count(array_filter($all_leave, fn($l) => $l["status"] === "approved"));
$rejected_count = count(array_filter($all_leave, fn($l) => $l["status"] === "rejected"));

$leave_type_labels = ["general" => "General", "sick" => "Sick", "maternity" => "Maternity", "unpaid" => "Unpaid"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leave Management · Hastra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>"><link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-contrast.css?v=<?= ASSET_VERSION ?>">
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

  .stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 2rem; }
  .stat-card { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; padding: 1.2rem 1.4rem; position: relative; overflow: hidden; }
  .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; }
  .stat-card.yellow::before { background: var(--yellow); }
  .stat-card.green::before  { background: var(--green); }
  .stat-card.red::before    { background: var(--red); }
  .stat-label { font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px; }
  .stat-value { font-size: 28px; font-weight: 700; line-height: 1; }

  .section { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; overflow: hidden; margin-bottom: 1.5rem; }
  .section-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.4rem; border-bottom: 1px solid var(--border-dim); background: var(--section-header-bg); }
  .section-body { padding: 1.4rem; }
  .section-title { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; }
  .section-title svg { width: 15px; height: 15px; fill: var(--accent-bright); }

  .alert { display: flex; align-items: flex-start; gap: 8px; border-radius: 3px; padding: 10px 14px; margin-bottom: 1.2rem; font-size: 13px; border-left: 3px solid; line-height: 1.5; }
  .alert.success { background: var(--green-bg); border-color: var(--green); color: #86efac; }
  .alert.error   { background: var(--red-bg);   border-color: var(--red);   color: #fca5a5; }

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
  .badge-pending  { background: rgba(234,179,8,0.1);  color: var(--yellow); border: 1px solid rgba(234,179,8,0.25); }
  .badge-approved { background: rgba(34,197,94,0.1);  color: var(--green);  border: 1px solid rgba(34,197,94,0.25); }
  .badge-rejected { background: rgba(var(--red-rgb),0.1);  color: var(--red);    border: 1px solid rgba(var(--red-rgb),0.25); }

  .review-btns { display: flex; gap: 6px; }
  .action-btn { display: inline-flex; align-items: center; gap: 5px; padding: 6px 11px; border-radius: 3px; border: 1px solid; font-family: var(--font-sans); font-size: 11.5px; font-weight: 600; cursor: pointer; transition: background 0.15s; letter-spacing: 0.03em; text-transform: uppercase; background: none; }
  .action-btn.approve { border-color: rgba(34,197,94,0.3); color: var(--green); }
  .action-btn.approve:hover { background: rgba(34,197,94,0.15); }
  .action-btn.reject { border-color: rgba(var(--red-rgb),0.3); color: #fca5a5; }
  .action-btn.reject:hover { background: rgba(var(--red-rgb),0.15); }

  .empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-dim); font-size: 13px; }

  .field label {
    display: block; font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.07em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px;
  }
  .field input, .field select {
    width: 100%; background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; font-family: var(--font-sans); font-size: 13px; padding: 9px 12px; outline: none;
    transition: border-color 0.2s;
  }
  .field input:focus, .field select:focus { border-color: var(--accent-bright); }
  .policy-row { display: grid; grid-template-columns: 1.4fr 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.2rem; }
  .btn-save-policy {
    background: var(--accent); color: #fff; border: none; border-radius: 3px;
    font-family: var(--font-sans); font-size: 12px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase;
    padding: 9px 20px; cursor: pointer; transition: background 0.2s;
  }
  .btn-save-policy:hover { background: var(--accent-dim); }

  @media (max-width: 768px) { .stats-row { grid-template-columns: 1fr; } .policy-row { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<?php $nav_current = 'leave'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>Leave Management</h1>
    <p>Review and act on employee leave requests.</p>
  </div>

  <?php if ($msg): ?>
  <div class="alert <?= $msg_type ?>"><span><?= htmlspecialchars($msg) ?></span></div>
  <?php endif; ?>

  <div class="stats-row">
    <div class="stat-card yellow">
      <div class="stat-label">Pending</div>
      <div class="stat-value"><?= $pending_count ?></div>
    </div>
    <div class="stat-card green">
      <div class="stat-label">Approved</div>
      <div class="stat-value"><?= $approved_count ?></div>
    </div>
    <div class="stat-card red">
      <div class="stat-label">Rejected</div>
      <div class="stat-value"><?= $rejected_count ?></div>
    </div>
  </div>

  <div class="section">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg>
        Leave Policy
      </div>
    </div>
    <div class="section-body">
      <form method="POST" action="leave-management" class="policy-form">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action" value="save_leave_policy">
        <div class="policy-row">
          <div class="field">
            <label for="leave_cycle">Leave Cycle</label>
            <select name="leave_cycle" id="leave_cycle">
              <option value="monthly" <?= ($company["leave_cycle"] ?? "monthly") === "monthly" ? "selected" : "" ?>>Monthly quota</option>
              <option value="yearly_rollover" <?= ($company["leave_cycle"] ?? "") === "yearly_rollover" ? "selected" : "" ?>>Yearly, unused leave rolls over</option>
            </select>
          </div>
          <div class="field">
            <label for="monthly_general_leaves">General Leaves / Month</label>
            <input type="number" name="monthly_general_leaves" id="monthly_general_leaves" min="0" max="30" value="<?= (int)($company["monthly_general_leaves"] ?? 1) ?>">
          </div>
          <div class="field">
            <label for="monthly_sick_leaves">Sick Leaves / Month</label>
            <input type="number" name="monthly_sick_leaves" id="monthly_sick_leaves" min="0" max="30" value="<?= (int)($company["monthly_sick_leaves"] ?? 1) ?>">
          </div>
          <div class="field">
            <label for="annual_leave_allowance">Annual Leave Days</label>
            <input type="number" name="annual_leave_allowance" id="annual_leave_allowance" min="0" max="90" value="<?= (int)($company["annual_leave_allowance"] ?? 18) ?>">
          </div>
        </div>
        <button type="submit" class="btn-save-policy">Save Policy</button>
      </form>
    </div>
  </div>

  <div class="section">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
        Leave Requests
        <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($all_leave) ?> total</span>
      </div>
    </div>

    <?php if (empty($all_leave)): ?>
    <div class="empty-state">No leave requests yet.</div>
    <?php else: ?>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>Employee</th>
            <th>Type</th>
            <th>Dates</th>
            <th>Days</th>
            <th>Reason</th>
            <th>Status</th>
            <th>Reviewed By</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($all_leave as $lr): ?>
          <tr>
            <td>
              <?= htmlspecialchars($lr["employee_name"]) ?><br>
              <span class="muted"><?= htmlspecialchars($lr["employee_email"]) ?></span>
            </td>
            <td class="muted"><?= htmlspecialchars($leave_type_labels[$lr["leave_type"]] ?? $lr["leave_type"]) ?></td>
            <td class="muted"><?= htmlspecialchars(date("d M Y", strtotime($lr["start_date"]))) ?> to <?= htmlspecialchars(date("d M Y", strtotime($lr["end_date"]))) ?></td>
            <td class="muted"><?= (float)$lr["total_days"] ?></td>
            <td class="muted"><?= $lr["reason"] ? htmlspecialchars($lr["reason"]) : '-' ?></td>
            <td><span class="badge badge-<?= htmlspecialchars($lr["status"]) ?>"><?= htmlspecialchars(ucfirst($lr["status"])) ?></span></td>
            <td class="muted"><?= $lr["reviewer_name"] ? htmlspecialchars($lr["reviewer_name"]) : '-' ?></td>
            <td>
              <?php if ($lr["status"] === "pending"): ?>
              <div class="review-btns">
                <form method="POST" action="leave-management" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                  <input type="hidden" name="action" value="review_leave">
                  <input type="hidden" name="leave_id" value="<?= (int)$lr["id"] ?>">
                  <input type="hidden" name="new_status" value="approved">
                  <button type="submit" class="action-btn approve">Approve</button>
                </form>
                <form method="POST" action="leave-management" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                  <input type="hidden" name="action" value="review_leave">
                  <input type="hidden" name="leave_id" value="<?= (int)$lr["id"] ?>">
                  <input type="hidden" name="new_status" value="rejected">
                  <button type="submit" class="action-btn reject">Reject</button>
                </form>
              </div>
              <?php else: ?>
              <span class="muted">-</span>
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

</body>
</html>
