<?php
// portals/client/my_requirements.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "client");

$ctx           = client_context($conn, (int)$_SESSION["user_id"]);
$scope_ids     = id_list($ctx['member_ids']);
$can_delete    = client_can($ctx, 'delete_requirement');
if (!client_can($ctx, 'view_projects')) {
    header("Location: " . get_base_url() . "workspace/client/docs");
    exit();
}

$msg      = "";
$msg_type = "error";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    verify_csrf_token();
    $action = $_POST["action"] ?? "";

    if ($action === "edit") {
        $req_id            = (int)($_POST["req_id"] ?? 0);
        $requirement_title = trim($_POST["requirement_title"] ?? "");
        $description       = trim($_POST["description"] ?? "");
        $expected_features = trim($_POST["expected_features"] ?? "");

        $check = mysqli_prepare($conn, "SELECT id, status FROM requirements WHERE id = ? AND user_id IN ($scope_ids)");
        mysqli_stmt_bind_param($check, "i", $req_id);
        mysqli_stmt_execute($check);
        $req_row = mysqli_fetch_assoc(mysqli_stmt_get_result($check));

        if (!$can_delete) {
            $msg = "Only your company's Project Manager can revise requirements.";
        } elseif (!$req_row) {
            $msg = "Requirement not found.";
        } elseif ($req_row["status"] === "rejected") {
            $msg = "A rejected requirement can't be revised. Submit a new one.";
        } elseif ($requirement_title === "" || $description === "") {
            $msg = "Title and description are required.";
        } else {
            // Snapshot the state as it exists right now, BEFORE the edit lands —
            // that's what turns a plain UPDATE into a diffable revision instead
            // of silently overwriting what the PM originally reviewed.
            $version = astra_reqver_snapshot($conn, $req_id, (int)$_SESSION["user_id"], $error);
            if ($version === null) {
                $msg = $error;
            } else {
                // requirement_title is a plaintext column everywhere else in the
                // app (admin/requirements.php, project_portal.php, ...) — only
                // description/expected_features are encrypted here, matching
                // config/migrations/2026_09_crypto.php. requirement_versions'
                // OWN encrypted_title column is separate and still gets the
                // encrypted copy, via astra_reqver_snapshot() above.
                $desc_enc = astra_db_encrypt($description);
                $feat_enc = $expected_features !== "" ? astra_db_encrypt($expected_features) : null;
                $upd = mysqli_prepare($conn,
                    "UPDATE requirements SET requirement_title = ?, description = ?, expected_features = ?
                     WHERE id = ? AND user_id IN ($scope_ids)");
                mysqli_stmt_bind_param($upd, "sssi", $requirement_title, $desc_enc, $feat_enc, $req_id);
                if (mysqli_stmt_execute($upd)) {
                    header("Location: " . get_base_url() . "workspace/client/requirements-diff?req_id=$req_id");
                    exit();
                }
                $msg = "Failed to save the revision.";
            }
        }
    }

    if ($action === "delete") {
        $req_id  = (int)($_POST["req_id"] ?? 0);

        // Only the Project Manager, only the company's own, only while pending_review
        $check = mysqli_prepare($conn, "SELECT id, status FROM requirements WHERE id = ? AND user_id IN ($scope_ids)");
        mysqli_stmt_bind_param($check, "i", $req_id);
        mysqli_stmt_execute($check);
        $check_result = mysqli_stmt_get_result($check);
        $req_row      = mysqli_fetch_assoc($check_result);

        if (!$can_delete) {
            $msg = "Only your company's Project Manager can delete requirements.";
        } elseif (!$req_row) {
            $msg = "Requirement not found.";
        } elseif ($req_row["status"] !== "pending_review") {
            $msg = "Only requirements with status 'Pending Review' can be deleted.";
        } else {
            $del = mysqli_prepare($conn, "DELETE FROM requirements WHERE id = ? AND user_id IN ($scope_ids)");
            mysqli_stmt_bind_param($del, "i", $req_id);
            if (mysqli_stmt_execute($del)) {
                $msg      = "Requirement deleted successfully.";
                $msg_type = "success";
            } else {
                $msg = "Failed to delete requirement. Please try again.";
            }
        }
    }
}

// ── Fetch this user's requirements ───────────────────────────────────────────
$req_result = mysqli_query($conn,
    "SELECT r.*, u.name AS submitted_by FROM requirements r
     LEFT JOIN users u ON u.id = r.user_id
     WHERE r.user_id IN ($scope_ids) ORDER BY r.created_at DESC");
$req_rows   = [];
while ($r = mysqli_fetch_assoc($req_result)) {
    $r['description']       = astra_db_decrypt($r['description']);
    $r['expected_features'] = astra_db_decrypt($r['expected_features']);
    $r['submitted_by']      = astra_db_decrypt($r['submitted_by']);
    $req_rows[] = $r;
}

// ── Status pipeline definition ────────────────────────────────────────────────
$status_order = ["pending_review", "under_review", "approved"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Requirements · Hastra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= get_base_url() ?>core/client_pages.css">
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

  .main { max-width: 1100px; margin: 0 auto; padding: 2rem; }

  .page-header { margin-bottom: 2rem; }
  .page-header h1 { font-size: 24px; font-weight: 600; color: var(--text); letter-spacing: -0.01em; margin-bottom: 4px; }
  .page-header p  { font-size: 13px; color: var(--text-dim); }

  .section {
    background: var(--navy-card);
    border: 1px solid var(--border-dim);
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 1.5rem;
  }

  .section-header {
    display: flex; align-items: center; gap: 8px;
    padding: 1rem 1.4rem;
    border-bottom: 1px solid var(--border-dim);
    background: var(--section-header-bg);
    font-size: 13px; font-weight: 600;
    color: var(--text);
    letter-spacing: 0.03em; text-transform: uppercase;
  }
  .section-header svg { width: 15px; height: 15px; fill: var(--accent-bright); }

  .section-body { padding: 1.4rem; }

  .alert {
    display: flex; align-items: flex-start; gap: 8px;
    border-radius: 3px; padding: 10px 14px;
    margin-bottom: 1.2rem; font-size: 13px;
    border-left: 3px solid; line-height: 1.5;
  }
  .alert.success { background: var(--green-bg); border-color: var(--green); color: #86efac; }
  .alert.error   { background: var(--red-bg);   border-color: var(--red);   color: #fca5a5; }
  .alert svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; }
  .alert.success svg { fill: var(--green); }
  .alert.error   svg { fill: var(--red); }

  /* ── STATUS PIPELINE ── */
  .pipeline-wrap {
    padding: 1.4rem;
    border-bottom: 1px solid var(--border-dim);
  }

  .pipeline-title {
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.08em; text-transform: uppercase;
    color: var(--text-dim); margin-bottom: 1.2rem;
  }

  .pipeline {
    display: flex;
    align-items: center;
    gap: 0;
  }

  .pipeline-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 1;
    position: relative;
  }

  .pipeline-step::after {
    content: '';
    position: absolute;
    top: 18px;
    left: 50%;
    width: 100%;
    height: 2px;
    background: var(--border-dim);
    z-index: 0;
  }

  .pipeline-step:last-child::after { display: none; }

  .pipeline-step.done::after   { background: var(--green); }
  .pipeline-step.active::after { background: var(--border-dim); }

  .step-circle {
    width: 36px; height: 36px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    position: relative; z-index: 1;
    border: 2px solid var(--border-dim);
    background: var(--navy-card);
    transition: all 0.3s;
  }

  .step-circle svg {
    width: 16px; height: 16px;
    stroke: var(--text-dim); fill: none;
    stroke-width: 1.5; stroke-linecap: round; stroke-linejoin: round;
  }

  .pipeline-step.done .step-circle {
    border-color: var(--green);
    background: rgba(34,197,94,0.1);
    box-shadow: 0 0 12px rgba(34,197,94,0.2);
  }
  .pipeline-step.done .step-circle svg { stroke: var(--green); }

  .pipeline-step.active .step-circle {
    border-color: var(--accent-bright);
    background: rgba(var(--accent-rgb),0.1);
    box-shadow: 0 0 12px rgba(var(--accent-rgb),0.25);
    animation: pulse-accent 2s infinite;
  }
  .pipeline-step.active .step-circle svg { stroke: var(--accent-bright); }

  .pipeline-step.rejected-step .step-circle {
    border-color: var(--red);
    background: rgba(var(--red-rgb),0.1);
    box-shadow: 0 0 12px rgba(var(--red-rgb),0.2);
  }
  .pipeline-step.rejected-step .step-circle svg { stroke: var(--red); }

  .pipeline-step.clarification-step .step-circle {
    border-color: var(--yellow);
    background: rgba(245,158,11,0.1);
    box-shadow: 0 0 12px rgba(245,158,11,0.2);
  }
  .pipeline-step.clarification-step .step-circle svg { stroke: var(--yellow); }

  @keyframes pulse-accent {
    0%, 100% { box-shadow: 0 0 12px rgba(var(--accent-rgb),0.25); }
    50%       { box-shadow: 0 0 20px rgba(var(--accent-rgb),0.5); }
  }

  .step-label {
    margin-top: 8px;
    font-size: 11px; text-align: center;
    color: var(--text-dim);
    font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    line-height: 1.3;
  }

  .pipeline-step.done .step-label   { color: var(--green); }
  .pipeline-step.active .step-label { color: var(--accent-bright); }
  .pipeline-step.rejected-step .step-label     { color: var(--red); }
  .pipeline-step.clarification-step .step-label { color: var(--yellow); }

  /* ── TABLE ── */
  .tbl-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }

  thead tr {
    background: var(--section-header-bg);
    border-bottom: 1px solid var(--border-dim);
  }

  th {
    padding: 10px 14px; text-align: left;
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.08em; text-transform: uppercase;
    color: var(--text-dim); font-weight: 400; white-space: nowrap;
  }

  tbody tr {
    border-bottom: 1px solid rgba(255,255,255,0.04);
    transition: background 0.15s;
  }
  tbody tr:hover { background: var(--hover-bg); }
  tbody tr:last-child { border-bottom: none; }

  td { padding: 11px 14px; color: var(--text); vertical-align: middle; }
  td.muted { color: var(--text-dim); font-family: 'Share Tech Mono', monospace; font-size: 12px; }

  .badge {
    display: inline-block; padding: 2px 8px; border-radius: 2px;
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.05em; text-transform: uppercase; font-weight: 500;
  }
  .badge-pending_review       { background: rgba(148,163,184,0.08); color: var(--text-dim);    border: 1px solid rgba(148,163,184,0.15); }
  .badge-under_review         { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .badge-approved             { background: rgba(34,197,94,0.1);    color: var(--green);       border: 1px solid rgba(34,197,94,0.2); }
  .badge-rejected             { background: rgba(var(--red-rgb),0.1);    color: var(--red);         border: 1px solid rgba(var(--red-rgb),0.2); }
  .badge-clarification_needed { background: rgba(245,158,11,0.1);   color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }

  .req-id-badge {
    font-family: 'Share Tech Mono', monospace;
    font-size: 12px; font-weight: 600;
    color: var(--accent-bright);
    background: rgba(var(--accent-rgb),0.08);
    border: 1px solid rgba(var(--accent-rgb),0.2);
    padding: 2px 8px; border-radius: 2px;
    letter-spacing: 0.08em;
  }

  .btn-expand {
    background: none; border: none;
    color: var(--accent-bright); font-size: 12px;
    cursor: pointer; text-decoration: underline; padding: 0;
    margin-right: 8px;
  }
  .btn-expand:hover { color: var(--text); }

  .btn-delete-req {
    background: none; border: none;
    color: #fca5a5; font-size: 12px;
    cursor: pointer; text-decoration: underline; padding: 0;
  }
  .btn-delete-req:hover { color: var(--red); }
  .btn-delete-req.disabled { color: var(--border-dim); cursor: not-allowed; text-decoration: none; }

  .detail-row { display: none; }
  .detail-row.open { display: table-row; }

  .detail-cell {
    padding: 0 14px 14px !important;
    background: var(--grid-line);
  }

  .detail-inner {
    border: 1px solid var(--border-dim);
    border-radius: 3px;
    padding: 12px 14px;
    font-size: 13px; color: var(--text-dim);
    line-height: 1.6;
  }

  .detail-inner strong {
    display: block; font-size: 11px;
    font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.07em; text-transform: uppercase;
    color: var(--text-dim); margin-bottom: 4px; margin-top: 10px;
  }
  .detail-inner strong:first-child { margin-top: 0; }
  .detail-inner p { color: var(--text); }

  .admin-note {
    margin-top: 10px; padding: 8px 12px;
    background: var(--yellow-bg);
    border: 1px solid rgba(245,158,11,0.2);
    border-left: 3px solid var(--yellow);
    border-radius: 3px; font-size: 12px;
    color: #fcd34d; line-height: 1.5;
  }
  .admin-note strong { color: var(--yellow) !important; margin-top: 0 !important; }

  .empty-state {
    text-align: center; padding: 3rem 1rem;
    color: var(--text-dim); font-size: 13px;
  }
  .empty-state svg { width: 36px; height: 36px; fill: var(--border-dim); margin: 0 auto 0.8rem; display: block; }
  .empty-state p   { margin-bottom: 0.3rem; }
  .empty-state span { font-size: 12px; }

  /* ── DELETE CONFIRM MODAL ── */
  .modal-overlay {
    display: flex; position: fixed; inset: 0;
    background: rgba(0,0,0,0.75); z-index: 200;
    align-items: center; justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
  }
  .modal-overlay.open {
    opacity: 1;
    pointer-events: auto;
  }

  .modal {
    background: var(--navy-card);
    border: 1px solid var(--border);
    border-radius: 4px;
    width: 100%; max-width: 380px;
    padding: 1.8rem; position: relative;
  }

  .modal::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--red), transparent);
    border-radius: 4px 4px 0 0;
  }

  .modal h3 { font-size: 16px; font-weight: 600; color: var(--text); margin-bottom: 0.5rem; }
  .modal p  { font-size: 13px; color: var(--text-dim); margin-bottom: 1.4rem; line-height: 1.5; }

  .modal-btns { display: flex; gap: 8px; }

  .modal-btn-confirm {
    flex: 1; background: var(--red); color: white;
    border: none; border-radius: 3px;
    font-family: var(--font-sans);
    font-size: 13px; font-weight: 600;
    padding: 10px; cursor: pointer;
    transition: background 0.2s, filter 0.2s; text-transform: uppercase;
  }
  .modal-btn-confirm:hover { filter: brightness(0.88); }

  .modal-btn-cancel {
    flex: 1;
    background: var(--input-bg);
    border: 1px solid var(--border-dim);
    color: var(--text-dim); border-radius: 3px;
    font-family: var(--font-sans);
    font-size: 13px; font-weight: 500;
    padding: 10px; cursor: pointer;
    transition: background 0.2s, filter 0.2s; text-transform: uppercase;
  }
  .modal-btn-cancel:hover { background: rgba(255,255,255,0.08); color: var(--text); }
</style>
</head>
<body>

<?php $nav_current = 'requirements'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>My Requirements</h1>
    <p>Track the status of everything your company has submitted.</p>
  </div>

  <?php if ($msg): ?>
  <div class="alert <?= $msg_type ?>" style="margin: 0 0 1.5rem;">
    <?php if ($msg_type === 'success'): ?>
      <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
    <?php else: ?>
      <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
    <?php endif; ?>
    <span><?= $msg ?></span>
  </div>
  <?php endif; ?>

  <div class="section">
    <div class="section-header">
      <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zm-1 9H7v-2h5v2zm3-4H7v-2h7v2z"/></svg>
      My Requirements
      <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:8px;"><?= count($req_rows) ?> total</span>
    </div>

    <?php if (empty($req_rows)): ?>
    <div class="section-body">
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zm-1 9H7v-2h5v2zm3-4H7v-2h7v2z"/></svg>
        <p>No requirements submitted yet.</p>
        <span>Use the Submit New Requirement page to submit your first project requirement.</span>
      </div>
    </div>

    <?php else: ?>

    <?php foreach ($req_rows as $req):
      $status = $req["status"];

      // Build pipeline state
      $is_rejected      = ($status === "rejected");
      $is_clarification = ($status === "clarification_needed");

      // For normal flow: pending_review → under_review → approved
      $step_states = [];
      foreach ($status_order as $s) {
        if ($is_rejected || $is_clarification) {
          // Show pending as done, under_review as active special state
          if ($s === "pending_review") {
            $step_states[$s] = "done";
          } elseif ($s === "under_review") {
            $step_states[$s] = $is_rejected ? "rejected-step" : "clarification-step";
          } else {
            $step_states[$s] = "";
          }
        } else {
          $order_pos  = array_search($s, $status_order);
          $current_pos = array_search($status, $status_order);
          if ($current_pos === false) $current_pos = 0;
          if ($order_pos < $current_pos) {
            $step_states[$s] = "done";
          } elseif ($order_pos === $current_pos) {
            $step_states[$s] = "active";
          } else {
            $step_states[$s] = "";
          }
        }
      }
    ?>

    <!-- Pipeline for this requirement -->
    <div class="pipeline-wrap">
      <div class="pipeline-title"><?= htmlspecialchars($req["requirement_id"] ?? "") ?>: <?= htmlspecialchars($req["project_title"]) ?> / <?= htmlspecialchars($req["requirement_title"]) ?></div>
      <div class="pipeline">

        <!-- Step 1: Submitted -->
        <div class="pipeline-step <?= $step_states["pending_review"] ?>">
          <div class="step-circle">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </div>
          <div class="step-label">Submitted</div>
        </div>

        <!-- Step 2: Under Review OR special state -->
        <div class="pipeline-step <?= $step_states["under_review"] ?>">
          <div class="step-circle">
            <?php if ($is_rejected): ?>
              <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <?php elseif ($is_clarification): ?>
              <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <?php else: ?>
              <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            <?php endif; ?>
          </div>
          <div class="step-label">
            <?= $is_rejected ? "Rejected" : ($is_clarification ? "Clarification" : "Under Review") ?>
          </div>
        </div>

        <!-- Step 3: Approved -->
        <div class="pipeline-step <?= $step_states["approved"] ?>">
          <div class="step-circle">
            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </div>
          <div class="step-label">Approved</div>
        </div>

      </div>
    </div>

    <?php endforeach; ?>

    <!-- Table -->
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>Req ID</th>
            <th>Project</th>
            <th>Requirement</th>
            <th>Budget</th>
            <th>Deadline</th>
            <th>Status</th>
            <th>Submitted</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($req_rows as $req): ?>
          <tr>
            <td><span class="req-id-badge"><?= htmlspecialchars($req["requirement_id"] ?? "-") ?></span></td>
            <td><?= htmlspecialchars($req["project_title"]) ?></td>
            <td><?= htmlspecialchars($req["requirement_title"]) ?></td>
            <td class="muted">
              <?php
                if ($req["budget_min"] !== null && $req["budget_max"] !== null) {
                    echo "₹" . number_format($req["budget_min"]) . " to ₹" . number_format($req["budget_max"]);
                } elseif ($req["budget_min"] !== null) {
                    echo "From ₹" . number_format($req["budget_min"]);
                } elseif ($req["budget_max"] !== null) {
                    echo "Up to ₹" . number_format($req["budget_max"]);
                } else { echo "-"; }
              ?>
            </td>
            <td class="muted"><?= $req["deadline"] ? date("d M Y", strtotime($req["deadline"])) : "-" ?></td>
            <td>
              <span class="badge badge-<?= htmlspecialchars($req["status"]) ?>">
                <?= str_replace("_", " ", htmlspecialchars($req["status"])) ?>
              </span>
            </td>
            <td class="muted"><?= date("d M Y", strtotime($req["created_at"])) ?></td>
            <td>
              <button class="btn-expand" onclick="toggleDetail(<?= $req['id'] ?>)">View</button>
              <?php if (!$can_delete): ?>
              <?php elseif ($req["status"] === "pending_review"): ?>
                <button class="btn-delete-req"
                  onclick="confirmDelete(<?= $req['id'] ?>, '<?= htmlspecialchars($req['requirement_id'], ENT_QUOTES) ?>')">
                  Delete
                </button>
              <?php else: ?>
                <span class="btn-delete-req disabled" title="Can only delete pending requirements">Delete</span>
              <?php endif; ?>
              <?php if (!empty($req['has_pending_revision'])): ?>
                <a class="btn-expand" href="<?= get_base_url() ?>workspace/client/requirements-diff?req_id=<?= $req['id'] ?>">Revision pending</a>
              <?php endif; ?>
            </td>
          </tr>
          <tr class="detail-row" id="detail-<?= $req['id'] ?>">
            <td colspan="8" class="detail-cell">
              <div class="detail-inner">
                <strong>Description</strong>
                <p><?= nl2br(htmlspecialchars($req["description"])) ?></p>

                <?php if ($req["expected_features"]): ?>
                <strong>Expected Features</strong>
                <p><?= nl2br(htmlspecialchars($req["expected_features"])) ?></p>
                <?php endif; ?>

                <?php if ($req["admin_notes"]): ?>
                <div class="admin-note">
                  <strong>Admin Note</strong>
                  <?= nl2br(htmlspecialchars($req["admin_notes"])) ?>
                </div>
                <?php endif; ?>

                <?php if ($can_delete && $req["status"] !== "rejected"): ?>
                <details style="margin-top:14px;">
                  <summary style="cursor:pointer;font-size:12px;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.05em;">Revise this requirement</summary>
                  <form method="POST" action="my-requirements" style="margin-top:10px;">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="req_id" value="<?= $req['id'] ?>">
                    <label style="display:block;font-size:11px;color:var(--text-dim);text-transform:uppercase;margin:8px 0 4px;">Title</label>
                    <input type="text" name="requirement_title" value="<?= htmlspecialchars($req['requirement_title']) ?>" required
                           style="width:100%;background:var(--input-bg);border:1px solid var(--border-dim);color:var(--text);border-radius:3px;padding:8px 10px;">
                    <label style="display:block;font-size:11px;color:var(--text-dim);text-transform:uppercase;margin:8px 0 4px;">Description</label>
                    <textarea name="description" required rows="4"
                              style="width:100%;background:var(--input-bg);border:1px solid var(--border-dim);color:var(--text);border-radius:3px;padding:8px 10px;font-family:inherit;"><?= htmlspecialchars($req['description']) ?></textarea>
                    <label style="display:block;font-size:11px;color:var(--text-dim);text-transform:uppercase;margin:8px 0 4px;">Expected Features</label>
                    <textarea name="expected_features" rows="3"
                              style="width:100%;background:var(--input-bg);border:1px solid var(--border-dim);color:var(--text);border-radius:3px;padding:8px 10px;font-family:inherit;"><?= htmlspecialchars($req['expected_features'] ?? '') ?></textarea>
                    <p style="font-size:11px;color:var(--text-dim);margin-top:8px;">Saving this preserves the current version and requires your PM's acknowledgment before it's incorporated.</p>
                    <button type="submit" class="btn-delete-req" style="background:var(--accent);margin-top:8px;">Save Revision</button>
                  </form>
                </details>
                <?php endif; ?>
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

<!-- ── DELETE CONFIRM MODAL ── -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal">
    <h3>Delete Requirement</h3>
    <p id="deleteModalDesc">Are you sure you want to delete this requirement? This cannot be undone.</p>
    <div class="modal-btns">
      <form method="POST" action="my-requirements" id="deleteForm">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="req_id" id="deleteReqId" value="">
        <div class="modal-btns">
          <button type="submit" class="modal-btn-confirm">Yes, Delete</button>
          <button type="button" class="modal-btn-cancel" onclick="closeDeleteModal()">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function toggleDetail(id) {
  const row = document.getElementById('detail-' + id);
  row.classList.toggle('open');
}

function confirmDelete(id, reqId) {
  document.getElementById('deleteReqId').value  = id;
  document.getElementById('deleteModalDesc').textContent = 'Are you sure you want to delete requirement ' + reqId + '? This cannot be undone.';
  document.getElementById('deleteModal').classList.add('open');
}

function closeDeleteModal() {
  document.getElementById('deleteModal').classList.remove('open');
}
</script>

</body>
</html>
