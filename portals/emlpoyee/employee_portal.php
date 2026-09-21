<?php
// portals/emlpoyee/employee_portal.php — hub page (cards only)
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "employee");

$user_id = (int)$_SESSION["user_id"];

$lead_check = mysqli_prepare($conn,
    "SELECT 1 FROM project_members WHERE user_id = ? AND project_role = 'team_lead' LIMIT 1"
);
mysqli_stmt_bind_param($lead_check, "i", $user_id);
mysqli_stmt_execute($lead_check);
mysqli_stmt_store_result($lead_check);
$is_lead = mysqli_stmt_num_rows($lead_check) > 0;

$me_stmt = mysqli_prepare($conn, "SELECT company_id, gender, maternity_leave_eligible FROM users WHERE id = ?");
mysqli_stmt_bind_param($me_stmt, "i", $user_id);
mysqli_stmt_execute($me_stmt);
$me = mysqli_fetch_assoc(mysqli_stmt_get_result($me_stmt));

$company = null;
if (!empty($me["company_id"])) {
    $co_stmt = mysqli_prepare($conn,
        "SELECT leave_cycle, monthly_general_leaves, monthly_sick_leaves, annual_leave_allowance FROM companies WHERE id = ?");
    mysqli_stmt_bind_param($co_stmt, "i", $me["company_id"]);
    mysqli_stmt_execute($co_stmt);
    $company = mysqli_fetch_assoc(mysqli_stmt_get_result($co_stmt));
}

$leave_msg      = "";
$leave_msg_type = "error";

// ── ACTION: request_leave ──────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "request_leave") {
    verify_csrf_token();

    $leave_type = $_POST["leave_type"] ?? "";
    $start      = $_POST["start_date"] ?? "";
    $end        = $_POST["end_date"] ?? "";
    $reason     = trim($_POST["reason"] ?? "");

    $start_ts = DateTime::createFromFormat("Y-m-d", $start);
    $end_ts   = DateTime::createFromFormat("Y-m-d", $end);

    if (!in_array($leave_type, ["general", "sick", "maternity", "unpaid"], true)) {
        $leave_msg = "Choose a valid leave type.";
    } elseif (!$start_ts || !$end_ts) {
        $leave_msg = "Please enter valid start and end dates.";
    } elseif ($end_ts < $start_ts) {
        $leave_msg = "The end date can't be before the start date.";
    } elseif ($leave_type === "maternity" && empty($me["maternity_leave_eligible"])) {
        $leave_msg = "You're not marked as eligible for maternity leave. Contact your admin.";
    } else {
        $total_days = ((int)$end_ts->diff($start_ts)->format("%a")) + 1;
        $bal = $company ? astra_leave_balance($conn, $user_id, $company, $leave_type) : null;
        if ($bal !== null && $total_days > $bal["remaining"]) {
            $leave_msg = sprintf(
                "That's %s day(s), but you only have %s %s leave day(s) left this %s.",
                rtrim(rtrim(number_format($total_days, 1), '0'), '.'),
                rtrim(rtrim(number_format($bal["remaining"], 1), '0'), '.'),
                $leave_type,
                ($company["leave_cycle"] ?? 'monthly') === 'yearly_rollover' ? 'year' : 'month'
            );
        } else {
            $ins = mysqli_prepare($conn,
                "INSERT INTO leave_requests (user_id, company_id, leave_type, start_date, end_date, total_days, reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($ins, "iisssds", $user_id, $me["company_id"], $leave_type, $start, $end, $total_days, $reason);
            if (mysqli_stmt_execute($ins)) {
                $leave_msg      = "Leave request submitted — pending approval.";
                $leave_msg_type = "success";
            } else {
                $leave_msg = "Failed to submit leave request. Please try again.";
            }
        }
    }
}

// ── Fetch this employee's leave requests ─────────────────────────────────────
$my_leave_result = mysqli_prepare($conn,
    "SELECT leave_type, start_date, end_date, total_days, status, created_at
     FROM leave_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 20"
);
mysqli_stmt_bind_param($my_leave_result, "i", $user_id);
mysqli_stmt_execute($my_leave_result);
$my_leave = mysqli_fetch_all(mysqli_stmt_get_result($my_leave_result), MYSQLI_ASSOC);

$leave_type_labels = ["general" => "General", "sick" => "Sick", "maternity" => "Maternity", "unpaid" => "Unpaid"];

$leave_bal_general = $company ? astra_leave_balance($conn, $user_id, $company, "general") : null;
$leave_bal_sick    = $company ? astra_leave_balance($conn, $user_id, $company, "sick") : null;
$leave_period_label = ($company["leave_cycle"] ?? 'monthly') === 'yearly_rollover' ? 'this year' : 'this month';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Portal · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/custom-dropdowns.css?v=<?= ASSET_VERSION ?>">
<script src="<?= get_base_url() ?>assets/js/custom-dropdowns.js?v=<?= ASSET_VERSION ?>"></script>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/tour.css?v=<?= ASSET_VERSION ?>">
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
  .page-header p  { font-size: 13px; color: var(--text-dim); }

  /* ── Leave modal ── */
  .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; padding: 1rem; }
  .modal-overlay.open { display: flex; }
  .modal { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 6px; padding: 1.6rem; max-width: 460px; width: 100%; max-height: 90vh; overflow-y: auto; }
  .modal h2 { font-size: 17px; font-weight: 600; margin-bottom: 1.2rem; }
  .modal .close-x { position: absolute; }
  .field { margin-bottom: 1rem; }
  .field label {
    display: block; font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.07em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px;
  }
  .field input, .field select, .field textarea {
    width: 100%; background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; font-family: var(--font-sans);
    font-size: 13px; padding: 9px 12px; outline: none; transition: border-color 0.2s;
  }
  .field textarea { min-height: 70px; resize: vertical; }
  .field input:focus, .field select:focus, .field textarea:focus { border-color: var(--accent-bright); }
  .field-hint { margin-top: 6px; font-size: 11.5px; color: var(--text-dim); }
  .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .modal-btns { display: flex; gap: 10px; margin-top: 1.4rem; }
  .modal-btn-confirm {
    flex: 1; background: var(--accent); color: #fff; border: none; border-radius: 3px;
    font-family: var(--font-sans); font-size: 13px; font-weight: 600;
    padding: 10px; cursor: pointer; transition: background 0.2s;
  }
  .modal-btn-confirm:hover { background: var(--accent-dim); }
  .modal-btn-cancel {
    background: var(--input-bg); color: var(--text-dim); border: 1px solid var(--border-dim); border-radius: 3px;
    font-family: var(--font-sans); font-size: 13px; font-weight: 600;
    padding: 10px 16px; cursor: pointer; transition: background 0.2s;
  }
  .modal-btn-cancel:hover { background: var(--hover-bg); }
  .alert { display: flex; align-items: flex-start; gap: 8px; border-radius: 3px; padding: 10px 14px; margin-bottom: 1rem; font-size: 13px; border-left: 3px solid; line-height: 1.5; }
  .alert.success { background: var(--green-bg); border-color: var(--green); color: #86efac; }
  .alert.error   { background: var(--red-bg);   border-color: var(--red);   color: #fca5a5; }

  .leave-list { margin-top: 1.4rem; border-top: 1px solid var(--border-dim); padding-top: 1rem; }
  .leave-list-title { font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.07em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 8px; }
  .leave-row {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    padding: 8px 10px; border-radius: 4px; font-size: 12.5px;
    border: 1px solid var(--border-dim); margin-bottom: 6px;
  }
  .leave-row .lt { font-weight: 600; }
  .leave-row .ld { color: var(--text-dim); }
  .leave-badge { font-size: 10.5px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.06em; text-transform: uppercase; padding: 2px 8px; border-radius: 2px; }
  .leave-badge.pending  { color: var(--yellow); background: rgba(234,179,8,0.1); border: 1px solid rgba(234,179,8,0.25); }
  .leave-badge.approved { color: var(--green);  background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.25); }
  .leave-badge.rejected { color: var(--red);    background: rgba(var(--red-rgb),0.1); border: 1px solid rgba(var(--red-rgb),0.25); }
  .no-leave { font-size: 12.5px; color: var(--text-dim); }
</style>
</head>
<body data-tour-page="emlpoyee/employee_portal">

<?php $nav_current = ''; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>Employee Portal</h1>
    <p><?= $is_lead ? 'You are a Team Lead — manage tasks and view your assignments.' : 'View and update your assigned tasks.' ?></p>
  </div>

  <div class="section-nav">
    <a class="section-nav-card" id="tour-my-tasks-card" href="<?= get_base_url() ?>portals/emlpoyee/my_tasks">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M9 11H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm2-7h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg></span>
      <span class="section-nav-label">My Tasks</span>
    </a>
    <?php if ($is_lead): ?>
    <a class="section-nav-card" id="tour-team-lead-card" href="<?= get_base_url() ?>portals/emlpoyee/team_lead">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M17 12h-5v5h5v-5zM16 1v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-1V1h-2zm3 18H5V8h14v11z"/></svg></span>
      <span class="section-nav-label">My Projects — Team Lead</span>
    </a>
    <?php endif; ?>
    <a class="section-nav-card" href="javascript:void(0)" onclick="openLeaveModal()">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg></span>
      <span class="section-nav-label">Request Leave</span>
    </a>
    <?php if ($is_lead): ?>
    <a class="section-nav-card" href="<?= get_base_url() ?>portals/projects/signoff">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg></span>
      <span class="section-nav-label">Milestone Sign-Off</span>
    </a>
    <?php endif; ?>
  </div>

</div>

<div class="modal-overlay" id="leaveModal">
  <div class="modal">
    <h2>Request Leave</h2>

    <?php if ($leave_msg): ?>
    <div class="alert <?= $leave_msg_type ?>"><span><?= htmlspecialchars($leave_msg) ?></span></div>
    <?php endif; ?>

    <form method="POST" action="employee_portal">
      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
      <input type="hidden" name="action" value="request_leave">

      <div class="field">
        <label for="leave_type">Leave Type</label>
        <select name="leave_type" id="leave_type" required>
          <option value="general">General</option>
          <option value="sick">Sick</option>
          <?php if (!empty($me["maternity_leave_eligible"])): ?>
          <option value="maternity">Maternity</option>
          <?php endif; ?>
          <option value="unpaid">Unpaid</option>
        </select>
        <?php if ($leave_bal_general !== null || $leave_bal_sick !== null): ?>
        <p class="field-hint">
          <?php if ($leave_bal_general !== null): ?>General: <?= rtrim(rtrim(number_format($leave_bal_general["remaining"], 1), '0'), '.') ?>/<?= (int)$leave_bal_general["limit"] ?> left <?= htmlspecialchars($leave_period_label) ?><?php endif; ?>
          <?php if ($leave_bal_general !== null && $leave_bal_sick !== null): ?> &middot; <?php endif; ?>
          <?php if ($leave_bal_sick !== null): ?>Sick: <?= rtrim(rtrim(number_format($leave_bal_sick["remaining"], 1), '0'), '.') ?>/<?= (int)$leave_bal_sick["limit"] ?> left <?= htmlspecialchars($leave_period_label) ?><?php endif; ?>
        </p>
        <?php endif; ?>
      </div>

      <div class="form-row-2">
        <div class="field">
          <label for="start_date">Start Date</label>
          <input type="date" name="start_date" id="start_date" required onchange="updateLeaveSpan()">
        </div>
        <div class="field">
          <label for="end_date">End Date</label>
          <input type="date" name="end_date" id="end_date" required onchange="updateLeaveSpan()">
        </div>
      </div>

      <p id="leaveSpan" style="font-size:12px;color:var(--text-dim);margin:-4px 0 12px;"></p>

      <div class="field">
        <label for="reason">Reason <span style="text-transform:none;color:#475569;font-weight:400;">(optional)</span></label>
        <textarea name="reason" id="reason" maxlength="500" placeholder="Briefly describe the reason for this leave…"></textarea>
      </div>

      <div class="modal-btns">
        <button type="submit" class="modal-btn-confirm">Submit Request</button>
        <button type="button" class="modal-btn-cancel" onclick="closeLeaveModal()">Cancel</button>
      </div>
    </form>

    <div class="leave-list">
      <div class="leave-list-title">Your Recent Requests</div>
      <?php if (!$my_leave): ?>
      <p class="no-leave">No leave requests yet.</p>
      <?php else: foreach ($my_leave as $lr): ?>
      <div class="leave-row">
        <div>
          <span class="lt"><?= htmlspecialchars($leave_type_labels[$lr["leave_type"]] ?? $lr["leave_type"]) ?></span>
          <span class="ld"> · <?= htmlspecialchars(date("d M", strtotime($lr["start_date"]))) ?>–<?= htmlspecialchars(date("d M Y", strtotime($lr["end_date"]))) ?> · <?= (float)$lr["total_days"] ?>d</span>
        </div>
        <span class="leave-badge <?= htmlspecialchars($lr["status"]) ?>"><?= htmlspecialchars(ucfirst($lr["status"])) ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<script>
  window.ASTRA_CSRF_TOKEN = "<?= generate_csrf_token() ?>";
  window.ASTRA_BASE_URL   = "<?= get_base_url() ?>";
</script>
<script src="<?= get_base_url() ?>assets/js/tour-config.js?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= get_base_url() ?>assets/js/tour.js?v=<?= ASSET_VERSION ?>"></script>
<script>
  function openLeaveModal()  { document.getElementById('leaveModal').classList.add('open'); }
  function closeLeaveModal() { document.getElementById('leaveModal').classList.remove('open'); }
  document.getElementById('leaveModal').addEventListener('click', function (e) {
    if (e.target === this) closeLeaveModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeLeaveModal();
  });
  function updateLeaveSpan() {
    var s = document.getElementById('start_date').value;
    var e = document.getElementById('end_date').value;
    var el = document.getElementById('leaveSpan');
    if (!s || !e) { el.textContent = ''; return; }
    var sd = new Date(s + 'T00:00:00'), ed = new Date(e + 'T00:00:00');
    if (ed < sd) { el.textContent = 'End date is before start date.'; return; }
    var days = Math.round((ed - sd) / 86400000) + 1;
    el.textContent = days + ' day' + (days === 1 ? '' : 's') + ' requested.';
  }
  <?php if ($leave_msg): ?>
  openLeaveModal();
  <?php endif; ?>
</script>

</body>
</html>
