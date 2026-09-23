<?php
// portals/admin/deployments.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "admin");

$msg      = "";
$msg_type = "error";

// ── APPROVE / REJECT DEPLOYMENT ───────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] === "review_deployment") {
    verify_csrf_token();

    $project_id  = (int)($_POST["project_id"] ?? 0);
    $decision    = trim($_POST["decision"] ?? "");
    $admin_notes = trim($_POST["admin_deployment_notes"] ?? "");

    if (!$project_id || !in_array($decision, ["approve", "reject"])) {
        $msg = "Invalid request."; $msg_type = "error";
    } elseif ($decision === "approve" && docs_schema_ready($conn) && !approved_doc($conn, $project_id)) {
        $msg = "Approve the project documentation first. Open the documentation editor, review the draft, and approve a version.";
        $msg_type = "error";
    } else {
        $new_status = $decision === "approve" ? "completed" : "testing";
        $upd = mysqli_prepare($conn,
            "UPDATE projects SET status = ?, admin_deployment_notes = ? WHERE id = ? AND status = 'deployment_pending'"
        );
        mysqli_stmt_bind_param($upd, "ssi", $new_status, $admin_notes, $project_id);
        if (mysqli_stmt_execute($upd) && mysqli_affected_rows($conn) > 0) {
            $msg      = $decision === "approve" ? "Project approved and marked <strong>Completed</strong>." : "Deployment rejected. Project moved back to <strong>Testing</strong>.";
            $msg_type = "success";
        } else {
            $msg = "Failed to update project.";
        }
    }
}

// ── Fetch deployment pending projects ─────────────────────────────────────────
$deploy_result = mysqli_query($conn,
    "SELECT p.*, u.name AS requester_name
     FROM projects p
     LEFT JOIN users u ON u.id = p.deployment_requested_by
     WHERE p.status = 'deployment_pending'
     ORDER BY p.deployment_requested_at DESC"
);
$deploy_pending = [];
$docs_ready = docs_schema_ready($conn);
while ($row = mysqli_fetch_assoc($deploy_result)) {
    $row['requester_name'] = astra_db_decrypt($row['requester_name']);
    if ($docs_ready) {
        $row['doc_approved'] = approved_doc($conn, (int)$row['id']);
        $row['doc_count']    = count(doc_versions($conn, (int)$row['id']));
    }
    $deploy_pending[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Deployment Approvals · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
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

  .alert { display: flex; align-items: flex-start; gap: 8px; border-radius: 3px; padding: 10px 14px; margin-bottom: 1.2rem; font-size: 13px; border-left: 3px solid; line-height: 1.5; }
  .alert.success { background: var(--green-bg); border-color: var(--green); color: #86efac; }
  .alert.error   { background: var(--red-bg);   border-color: var(--red);   color: #fca5a5; }
  .alert svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; }
  .alert.success svg { fill: var(--green); }
  .alert.error   svg { fill: var(--red); }

  .badge { display: inline-block; padding: 2px 8px; border-radius: 2px; font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.05em; text-transform: uppercase; font-weight: 500; }
  .badge-deployment_pending { background: rgba(var(--purple-rgb),0.1); color: var(--purple); border: 1px solid rgba(var(--purple-rgb),0.2); }

  .empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-dim); font-size: 13px; }
  .empty-state svg { width: 32px; height: 32px; fill: var(--border-dim); margin: 0 auto 0.8rem; display: block; }

  .field { margin-bottom: 1rem; }
  label { display: block; font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; }

  @media (max-width: 768px) {}
</style>
</head>
<body>

<?php $nav_current = 'deployments'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>Deployment Approvals</h1>
    <p>Review and approve or reject projects awaiting deployment.</p>
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

  <div class="section" id="sec-deployments">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
        Deployment Approvals
        <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($deploy_pending) ?> pending</span>
      </div>
    </div>
    <?php if (empty($deploy_pending)): ?>
    <div class="empty-state">
      <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
      No deployment requests pending.
    </div>
    <?php else: foreach ($deploy_pending as $proj): ?>
    <div style="background:var(--navy-deep);border:1px solid rgba(var(--purple-rgb),0.2);border-radius:3px;padding:1.2rem 1.4rem;margin:1rem 1.4rem;">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
        <span style="font-family:'Share Tech Mono',monospace;font-size:11px;font-weight:600;color:var(--cyan);background:rgba(34,211,238,0.08);border:1px solid rgba(34,211,238,0.2);padding:2px 7px;border-radius:2px;"><?= htmlspecialchars($proj['project_code']) ?></span>
        <span class="badge badge-deployment_pending">Deployment Pending</span>
      </div>
      <div style="font-size:15px;font-weight:600;margin-bottom:4px;"><?= htmlspecialchars($proj['title']) ?></div>
      <div style="font-size:12px;color:var(--text-dim);margin-bottom:10px;">
        Requested by <strong style="color:var(--text);"><?= htmlspecialchars($proj['requester_name'] ?? '-') ?></strong>
        · <?= $proj['deployment_requested_at'] ? date('d M Y, H:i', strtotime($proj['deployment_requested_at'])) : '-' ?>
      </div>
      <?php if ($proj['deployment_notes']): ?>
      <div style="padding:8px 12px;background:rgba(var(--purple-rgb),0.06);border:1px solid rgba(var(--purple-rgb),0.2);border-left:3px solid var(--purple);border-radius:3px;font-size:12px;color:var(--purple);margin-bottom:12px;line-height:1.5;">
        <strong style="display:block;font-size:10px;font-family:'Share Tech Mono',monospace;letter-spacing:0.07em;text-transform:uppercase;color:var(--purple);margin-bottom:3px;">Team Lead Notes</strong>
        <?= nl2br(htmlspecialchars($proj['deployment_notes'])) ?>
      </div>
      <?php endif; ?>
      <?php if ($docs_ready):
        $doc_ok = !empty($proj['doc_approved']); ?>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:8px 12px;margin-bottom:12px;border-radius:3px;font-size:12px;border:1px solid <?= $doc_ok ? 'rgba(34,197,94,0.3)' : 'rgba(234,179,8,0.35)' ?>;background:<?= $doc_ok ? 'rgba(34,197,94,0.06)' : 'rgba(234,179,8,0.06)' ?>;">
        <strong style="font-size:10px;font-family:'Share Tech Mono',monospace;letter-spacing:0.07em;text-transform:uppercase;">Documentation</strong>
        <span style="flex:1;min-width:180px;color:var(--text-dim);">
          <?php if ($doc_ok): ?>Version <?= (int)$proj['doc_approved']['version'] ?> approved on <?= date('d M Y', strtotime($proj['doc_approved']['approved_at'])) ?>.
          <?php elseif ($proj['doc_count'] > 0): ?><?= (int)$proj['doc_count'] ?> draft<?= $proj['doc_count'] != 1 ? 's' : '' ?>, none approved yet. Approve one before completing the project.
          <?php else: ?>No draft yet. Generate one from the <?= !empty($proj['repo_url']) ? 'repository' : 'project records' ?>, review it, then approve it.<?php endif; ?>
          <?php if (!empty($proj['repo_url']) && safe_url($proj['repo_url'])): ?><br>Repository: <a href="<?= htmlspecialchars($proj['repo_url']) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--cyan);"><?= htmlspecialchars($proj['repo_url']) ?></a><?php endif; ?>
        </span>
        <a href="doc_editor.php?project=<?= (int)$proj['id'] ?>" style="color:var(--cyan);border:1px solid rgba(34,211,238,0.3);padding:5px 12px;border-radius:3px;text-decoration:none;font-weight:600;">Open documentation editor</a>
      </div>
      <?php endif; ?>
      <form method="POST" action="deployments">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action"     value="review_deployment">
        <input type="hidden" name="project_id" value="<?= $proj['id'] ?>">
        <div style="margin-bottom:10px;">
          <label style="display:block;font-size:10px;font-family:'Share Tech Mono',monospace;letter-spacing:0.07em;text-transform:uppercase;color:var(--text-dim);margin-bottom:6px;">Admin Note <span style="text-transform:none;color:#475569;font-weight:400;">(optional)</span></label>
          <textarea name="admin_deployment_notes" style="width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border-dim);border-radius:3px;color:var(--text);font-family:var(--font-sans);font-size:13px;padding:9px 12px;outline:none;resize:vertical;min-height:60px;" placeholder="Add a note for the team lead…"></textarea>
        </div>
        <div style="display:flex;gap:8px;">
          <?php $can_approve = !$docs_ready || !empty($proj['doc_approved']); ?>
          <button type="submit" name="decision" value="approve" <?= $can_approve ? '' : 'disabled title="Approve the documentation first"' ?>
            style="<?= $can_approve ? '' : 'opacity:0.45;cursor:not-allowed;' ?>background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:var(--green);font-family:var(--font-sans);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;padding:8px 18px;border-radius:3px;cursor:pointer;"
            onclick="return confirm('Approve deployment and mark project as Completed?')">
            ✓ Approve & Complete
          </button>
          <button type="submit" name="decision" value="reject"
            style="background:rgba(var(--red-rgb),0.08);border:1px solid rgba(var(--red-rgb),0.3);color:#fca5a5;font-family:var(--font-sans);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;padding:8px 18px;border-radius:3px;cursor:pointer;"
            onclick="return confirm('Reject deployment and move project back to Testing?')">
            ✗ Reject
          </button>
        </div>
      </form>
    </div>
    <?php endforeach; endif; ?>
  </div>

</div>

</body>
</html>
