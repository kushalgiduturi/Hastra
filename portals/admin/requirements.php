<?php
// portals/admin/requirements.php
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

// ── PHASE 3: Review requirement ───────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] === "review_requirement") {
    verify_csrf_token();

    $req_id      = (int)($_POST["req_id"] ?? 0);
    $new_status  = trim($_POST["new_status"] ?? "");
    $admin_notes = trim($_POST["admin_notes"] ?? "");

    $allowed_statuses = ["under_review", "approved", "rejected", "clarification_needed"];

    if (!$req_id || !in_array($new_status, $allowed_statuses)) {
        $msg      = "Invalid request.";
        $msg_type = "error";
    } else {
        $fetch = mysqli_prepare($conn,
            "SELECT r.*, u.name AS client_name, u.email AS client_email
             FROM requirements r
             JOIN users u ON r.user_id = u.id
             WHERE r.id = ?"
        );
        mysqli_stmt_bind_param($fetch, "i", $req_id);
        mysqli_stmt_execute($fetch);
        $req = mysqli_fetch_assoc(mysqli_stmt_get_result($fetch));
        if ($req) { $req['client_name'] = astra_db_decrypt($req['client_name']); $req['client_email'] = astra_db_decrypt($req['client_email']); }

        if (!$req) {
            $msg      = "Requirement not found.";
            $msg_type = "error";
        } else {
            $update = mysqli_prepare($conn,
                "UPDATE requirements SET status = ?, admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?"
            );
            $admin_id = (int)$_SESSION["user_id"];
            mysqli_stmt_bind_param($update, "ssii", $new_status, $admin_notes, $admin_id, $req_id);

            if (mysqli_stmt_execute($update)) {
                $status_labels = [
                    "under_review"         => "Under Review",
                    "approved"             => "Approved",
                    "rejected"             => "Rejected",
                    "clarification_needed" => "Clarification Needed",
                ];
                $label = $status_labels[$new_status];

                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host        = MAIL_HOST;
                    $mail->SMTPAuth    = MAIL_AUTH;
                    $mail->Port        = MAIL_PORT;
                    $mail->SMTPSecure  = MAIL_SECURE;
                    $mail->SMTPAutoTLS = false;
                    $mail->setFrom(MAIL_FROM, MAIL_NAME);
                    $mail->addAddress($req["client_email"]);
                    $mail->Subject = "Requirement Update — {$req['requirement_id']} Status: $label";

                    $notes_section = $admin_notes
                        ? "\n\nAdmin Note:\n$admin_notes"
                        : "";

                    $mail->Body =
                        "Hi {$req['client_name']},\n\n" .
                        "Your requirement has been updated.\n\n" .
                        "Requirement ID : {$req['requirement_id']}\n" .
                        "Project        : {$req['project_title']}\n" .
                        "Requirement    : {$req['requirement_title']}\n" .
                        "New Status     : $label" .
                        $notes_section .
                        "\n\nLog in to your portal to view full details.\n\nRegards,\nAstra Team";

                    $mail->send();
                } catch (Exception $e) {
                    // Email failed silently — status still updated
                }

                $msg      = "Requirement <strong>{$req['requirement_id']}</strong> updated to <strong>$label</strong>.";
                $msg_type = "success";
            } else {
                $msg      = "Failed to update requirement.";
                $msg_type = "error";
            }
        }
    }
}

// ── Fetch all requirements for review ────────────────────────────────────────
$reqs_result = mysqli_query($conn,
    "SELECT r.*, u.name AS client_name, u.email AS client_email
     FROM requirements r
     JOIN users u ON r.user_id = u.id
     ORDER BY r.created_at DESC"
);
$all_reqs = [];
while ($r = mysqli_fetch_assoc($reqs_result)) {
    $r['description']       = astra_db_decrypt($r['description']);
    $r['expected_features'] = astra_db_decrypt($r['expected_features']);
    $r['client_name']       = astra_db_decrypt($r['client_name']);
    $r['client_email']      = astra_db_decrypt($r['client_email']);
    $all_reqs[] = $r;
}

$pending_count  = count(array_filter($all_reqs, fn($r) => $r["status"] === "pending_review"));
$review_count   = count(array_filter($all_reqs, fn($r) => $r["status"] === "under_review"));
$approved_count = count(array_filter($all_reqs, fn($r) => $r["status"] === "approved"));
$rejected_count = count(array_filter($all_reqs, fn($r) => in_array($r["status"], ["rejected","clarification_needed"])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Requirement Review · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/custom-dropdowns.css?v=<?= ASSET_VERSION ?>">
<script src="<?= get_base_url() ?>assets/js/custom-dropdowns.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/gooey-search.css?v=<?= ASSET_VERSION ?>">
<script src="<?= get_base_url() ?>assets/js/gooey-search.js?v=<?= ASSET_VERSION ?>" defer></script>
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

  .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem; }
  .stat-card { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; padding: 1.2rem 1.4rem; position: relative; overflow: hidden; }
  .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; }
  .stat-card.blue::before   { background: var(--blue-bright); }
  .stat-card.yellow::before { background: var(--yellow); }
  .stat-card.green::before  { background: var(--green); }
  .stat-card.red::before    { background: var(--red); }
  .stat-label { font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px; }
  .stat-value { font-size: 28px; font-weight: 700; line-height: 1; }

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
  .badge-pending_review       { background: rgba(148,163,184,0.08); color: var(--text-dim);    border: 1px solid rgba(148,163,184,0.15); }
  .badge-under_review         { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .badge-approved             { background: rgba(34,197,94,0.1);    color: var(--green);       border: 1px solid rgba(34,197,94,0.2); }
  .badge-rejected             { background: rgba(var(--red-rgb),0.1);    color: var(--red);         border: 1px solid rgba(var(--red-rgb),0.2); }
  .badge-clarification_needed { background: rgba(245,158,11,0.1);   color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }

  .req-id-badge { font-family: 'Share Tech Mono', monospace; font-size: 12px; font-weight: 600; color: var(--accent-bright); background: rgba(var(--accent-rgb),0.08); border: 1px solid rgba(var(--accent-rgb),0.2); padding: 2px 8px; border-radius: 2px; letter-spacing: 0.08em; }

  .filter-bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 1rem 1.4rem; border-bottom: 1px solid var(--border-dim); background: rgba(255,255,255,0.01); }
  .filter-bar select, .filter-bar input { background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text); border-radius: 3px; color: var(--text); font-family: var(--font-sans); font-size: 12px; padding: 6px 10px; outline: none; transition: border-color 0.2s; }
  .filter-bar select:focus, .filter-bar input:focus { border-color: var(--accent-bright); }
  .filter-bar select option { background: var(--navy-card); }
  .filter-bar input::placeholder { color: var(--text-dim); }
  .filter-count { font-size: 11px; font-family: 'Share Tech Mono', monospace; color: var(--text-dim); letter-spacing: 0.05em; margin-left: auto; }

  .btn-expand { background: none; border: none; color: var(--accent-bright); font-size: 12px; cursor: pointer; text-decoration: underline; padding: 0; }
  .btn-expand:hover { color: var(--accent-bright); }

  .detail-row { display: none; }
  .detail-row.open { display: table-row; }
  .detail-cell { padding: 0 14px 16px !important; background: rgba(var(--accent-rgb),0.02); }

  .review-panel { border: 1px solid var(--border-dim); border-radius: 3px; overflow: hidden; }
  .review-top { display: grid; grid-template-columns: 1fr 1fr; gap: 0; border-bottom: 1px solid var(--border-dim); }
  .review-info { padding: 14px 16px; border-right: 1px solid var(--border-dim); }
  .review-info strong { display: block; font-size: 10px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 5px; }
  .review-info p { font-size: 13px; color: var(--text); line-height: 1.6; }
  .review-info p.muted-text { color: var(--text-dim); font-size: 12px; }

  .admin-note-existing { margin: 14px 16px; padding: 8px 12px; background: var(--yellow-bg); border: 1px solid rgba(245,158,11,0.2); border-left: 3px solid var(--yellow); border-radius: 3px; font-size: 12px; color: #fcd34d; line-height: 1.5; }
  .admin-note-existing strong { display: block; font-size: 10px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.07em; text-transform: uppercase; color: var(--yellow); margin-bottom: 4px; }

  .review-form { padding: 14px 16px; background: rgba(255,255,255,0.01); }
  .review-form-title { font-size: 10px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 10px; }
  .review-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }

  .action-btn { display: flex; align-items: center; gap: 5px; padding: 7px 13px; border-radius: 3px; border: 1px solid; font-family: var(--font-sans); font-size: 12px; font-weight: 500; cursor: pointer; transition: background 0.15s, box-shadow 0.15s; letter-spacing: 0.03em; text-transform: uppercase; }
  .action-btn svg { width: 12px; height: 12px; flex-shrink: 0; }
  .action-btn.review { background: rgba(var(--accent-rgb),0.1); border-color: rgba(var(--accent-rgb),0.3); color: var(--accent-bright); }
  .action-btn.review:hover, .action-btn.review.selected { background: rgba(var(--accent-rgb),0.2); box-shadow: 0 0 10px rgba(var(--accent-rgb),0.2); }
  .action-btn.approve { background: rgba(34,197,94,0.1); border-color: rgba(34,197,94,0.3); color: var(--green); }
  .action-btn.approve:hover, .action-btn.approve.selected { background: rgba(34,197,94,0.2); box-shadow: 0 0 10px rgba(34,197,94,0.2); }
  .action-btn.clarify { background: rgba(245,158,11,0.08); border-color: rgba(245,158,11,0.3); color: var(--yellow); }
  .action-btn.clarify:hover, .action-btn.clarify.selected { background: rgba(245,158,11,0.18); box-shadow: 0 0 10px rgba(245,158,11,0.2); }
  .action-btn.reject { background: rgba(var(--red-rgb),0.08); border-color: rgba(var(--red-rgb),0.3); color: #fca5a5; }
  .action-btn.reject:hover, .action-btn.reject.selected { background: rgba(var(--red-rgb),0.18); box-shadow: 0 0 10px rgba(var(--red-rgb),0.2); }
  .action-btn.selected { outline: 2px solid currentColor; outline-offset: 1px; }

  .notes-field { margin-top: 8px; }
  .notes-field label { display: block; font-size: 10px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.07em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 5px; }
  .notes-field textarea { width: 100%; background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text); border-radius: 3px; color: var(--text); font-family: var(--font-sans); font-size: 13px; padding: 9px 12px; outline: none; resize: vertical; min-height: 72px; transition: border-color 0.2s; }
  .notes-field textarea:focus { border-color: var(--accent-bright); }
  .notes-field textarea::placeholder { color: var(--text-dim); }

  .btn-save-review { margin-top: 10px; background: var(--accent); color: white; border: none; border-radius: 3px; font-family: var(--font-sans); font-size: 12px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; padding: 9px 20px; cursor: pointer; transition: background 0.2s, box-shadow 0.2s; }
  .btn-save-review:hover { background: var(--accent-dim); box-shadow: 0 0 14px rgba(var(--accent-rgb),0.3); }
  .btn-save-review:disabled { background: #1e3a5f; color: var(--text-dim); cursor: not-allowed; }

  .empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-dim); font-size: 13px; }
  .empty-state svg { width: 32px; height: 32px; fill: var(--border-dim); margin: 0 auto 0.8rem; display: block; }

  .btn-nav-portal { display: inline-flex; align-items: center; gap: 6px; background: rgba(var(--accent-rgb),0.1); border: 1px solid rgba(var(--accent-rgb),0.3); color: var(--accent-bright); font-family: var(--font-sans); font-size: 12px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; padding: 6px 14px; border-radius: 3px; text-decoration: none; transition: background 0.2s, box-shadow 0.2s; }
  .btn-nav-portal:hover { background: rgba(var(--accent-rgb),0.2); box-shadow: 0 0 12px rgba(var(--accent-rgb),0.25); }
  .btn-nav-portal svg { width: 14px; height: 14px; fill: var(--accent-bright); }

  @media (max-width: 768px) {
    .stats-row { grid-template-columns: 1fr 1fr; }
    .review-top { grid-template-columns: 1fr; }
    .review-info { border-right: none; border-bottom: 1px solid var(--border-dim); }
  }
</style>
</head>
<body>

<?php $nav_current = 'requirements'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>Requirement Review</h1>
    <p>Review and act on client requirements.</p>
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

  <div class="stats-row">
    <div class="stat-card yellow">
      <div class="stat-label">Pending Review</div>
      <div class="stat-value"><?= $pending_count ?></div>
    </div>
    <div class="stat-card blue">
      <div class="stat-label">Under Review</div>
      <div class="stat-value"><?= $review_count ?></div>
    </div>
    <div class="stat-card green">
      <div class="stat-label">Approved</div>
      <div class="stat-value"><?= $approved_count ?></div>
    </div>
    <div class="stat-card red">
      <div class="stat-label">Rejected / Clarify</div>
      <div class="stat-value"><?= $rejected_count ?></div>
    </div>
  </div>

  <div class="section" id="sec-requirements">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zm-1 9H7v-2h5v2zm3-4H7v-2h7v2z"/></svg>
        Requirement Review
        <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($all_reqs) ?> total</span>
      </div>
      <a href="<?= get_base_url() ?>portals/admin/project_portal" class="btn-nav-portal">
        <svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
        Project Portal
      </a>
    </div>

    <div class="filter-bar">
      <?php render_gooey_search('f_search', 'Search project / client…', 'filterReqs()'); ?>
      <select id="f_status" onchange="filterReqs()">
        <option value="">All Statuses</option>
        <option value="pending_review">Pending Review</option>
        <option value="under_review">Under Review</option>
        <option value="approved">Approved</option>
        <option value="clarification_needed">Clarification Needed</option>
        <option value="rejected">Rejected</option>
      </select>
      <span class="filter-count" id="filterCount"><?= count($all_reqs) ?> shown</span>
    </div>

    <?php if (empty($all_reqs)): ?>
    <div class="empty-state">
      <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zm-1 9H7v-2h5v2zm3-4H7v-2h7v2z"/></svg>
      No requirements submitted yet.
    </div>
    <?php else: ?>
    <div class="tbl-wrap">
      <table id="reqTable">
        <thead>
          <tr>
            <th>Req ID</th>
            <th>Client</th>
            <th>Project</th>
            <th>Requirement</th>
            <th>Budget</th>
            <th>Deadline</th>
            <th>Status</th>
            <th>Submitted</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($all_reqs as $req): ?>
          <tr class="req-row"
              data-status="<?= htmlspecialchars($req['status']) ?>"
              data-search="<?= strtolower(htmlspecialchars($req['project_title'] . ' ' . $req['client_name'] . ' ' . $req['requirement_title'])) ?>">
            <td><span class="req-id-badge"><?= htmlspecialchars($req['requirement_id'] ?? '—') ?></span></td>
            <td>
              <?= htmlspecialchars($req['client_name']) ?>
              <div style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-top:2px;"><?= htmlspecialchars($req['client_email']) ?></div>
            </td>
            <td><?= htmlspecialchars($req['project_title']) ?></td>
            <td>
              <?= htmlspecialchars($req['requirement_title']) ?>
              <?php if (($req['request_type'] ?? '') === 'change_request'): ?>
              <br><span class="badge" style="background:rgba(var(--purple-rgb),0.1);color:var(--purple);border:1px solid rgba(var(--purple-rgb),0.2);margin-top:3px;">Change Request</span>
              <?php endif; ?>
            </td>
            <td class="muted">
              <?php
                if ($req['budget_min'] !== null && $req['budget_max'] !== null)
                    echo '₹' . number_format($req['budget_min']) . '–₹' . number_format($req['budget_max']);
                elseif ($req['budget_min'] !== null)
                    echo 'From ₹' . number_format($req['budget_min']);
                elseif ($req['budget_max'] !== null)
                    echo 'Up to ₹' . number_format($req['budget_max']);
                else echo '—';
              ?>
            </td>
            <td class="muted"><?= $req['deadline'] ? date('d M Y', strtotime($req['deadline'])) : '—' ?></td>
            <td>
              <span class="badge badge-<?= htmlspecialchars($req['status']) ?>">
                <?= str_replace('_', ' ', $req['status']) ?>
              </span>
            </td>
            <td class="muted"><?= date('d M Y', strtotime($req['created_at'])) ?></td>
            <td>
              <button class="btn-expand" onclick="toggleReview(<?= $req['id'] ?>)">Review</button>
            </td>
          </tr>

          <tr class="detail-row" id="detail-<?= $req['id'] ?>">
            <td colspan="9" class="detail-cell">
              <div class="review-panel">

                <div class="review-top">
                  <div class="review-info">
                    <strong>Description</strong>
                    <p><?= nl2br(htmlspecialchars($req['description'])) ?></p>
                  </div>
                  <div class="review-info" style="border-right:none;">
                    <strong>Expected Features</strong>
                    <p><?= $req['expected_features'] ? nl2br(htmlspecialchars($req['expected_features'])) : '<span class="muted-text">None provided.</span>' ?></p>
                    <?php if ($req['reviewed_at']): ?>
                    <div style="margin-top:10px;">
                      <strong>Last Reviewed</strong>
                      <p class="muted-text"><?= date('d M Y, H:i', strtotime($req['reviewed_at'])) ?></p>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if ($req['admin_notes']): ?>
                <div class="admin-note-existing">
                  <strong>Current Admin Note</strong>
                  <?= nl2br(htmlspecialchars($req['admin_notes'])) ?>
                </div>
                <?php endif; ?>

                <div class="review-form">
                  <div class="review-form-title">Update Status</div>
                  <form method="POST" action="requirements">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action"  value="review_requirement">
                    <input type="hidden" name="req_id"  value="<?= $req['id'] ?>">
                    <input type="hidden" name="new_status" id="status_<?= $req['id'] ?>" value="">

                    <div class="review-actions">
                      <button type="button" class="action-btn review"
                        onclick="selectStatus(<?= $req['id'] ?>, 'under_review', this)">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        Mark Under Review
                      </button>
                      <button type="button" class="action-btn approve"
                        onclick="selectStatus(<?= $req['id'] ?>, 'approved', this)">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Approve
                      </button>
                      <button type="button" class="action-btn clarify"
                        onclick="selectStatus(<?= $req['id'] ?>, 'clarification_needed', this)">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Need Clarification
                      </button>
                      <button type="button" class="action-btn reject"
                        onclick="selectStatus(<?= $req['id'] ?>, 'rejected', this)">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Reject
                      </button>
                    </div>

                    <div class="notes-field">
                      <label for="notes_<?= $req['id'] ?>">Admin Note <span style="text-transform:none;color:#475569;font-weight:400;">(optional — sent to client)</span></label>
                      <textarea name="admin_notes" id="notes_<?= $req['id'] ?>"
                        placeholder="Explain your decision, ask a question, or leave feedback for the client…"><?= htmlspecialchars($req['admin_notes'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn-save-review" id="saveBtn_<?= $req['id'] ?>" disabled>
                      Save &amp; Notify Client
                    </button>
                  </form>
                </div>

              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>

<script>
function toggleReview(id) {
  const row = document.getElementById('detail-' + id);
  const isOpen = row.classList.toggle('open');
  const btn = row.previousElementSibling.querySelector('.btn-expand');
  if (btn) btn.textContent = isOpen ? 'Close' : 'Review';
}

function selectStatus(reqId, status, clickedBtn) {
  document.getElementById('status_' + reqId).value = status;
  const panel = clickedBtn.closest('.review-panel');
  panel.querySelectorAll('.action-btn').forEach(b => b.classList.remove('selected'));
  clickedBtn.classList.add('selected');
  document.getElementById('saveBtn_' + reqId).disabled = false;
}

function filterReqs() {
  const search = document.getElementById('f_search').value.toLowerCase();
  const status = document.getElementById('f_status').value;
  let visible  = 0;

  document.querySelectorAll('.req-row').forEach(row => {
    const matchSearch = search === '' || row.dataset.search.includes(search);
    const matchStatus = status  === '' || row.dataset.status === status;
    const show = matchSearch && matchStatus;
    row.style.display = show ? '' : 'none';

    const detail = document.getElementById('detail-' + row.querySelector('.btn-expand')?.getAttribute('onclick')?.match(/\d+/)?.[0]);
    if (detail && !show) detail.style.display = 'none';

    if (show) visible++;
  });

  document.getElementById('filterCount').textContent = visible + ' shown';
}
</script>

</body>
</html>
