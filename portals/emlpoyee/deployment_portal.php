<?php
// portals/emlpoyee/deployment_portal.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "employee");

$msg      = "";
$msg_type = "error";
$user_id  = (int)$_SESSION["user_id"];

// ── Verify user is a team lead on at least one project ───────────────────────
$lead_check = mysqli_prepare($conn,
    "SELECT pm.project_id FROM project_members pm
     WHERE pm.user_id = ? AND pm.project_role = 'team_lead'"
);
mysqli_stmt_bind_param($lead_check, "i", $user_id);
mysqli_stmt_execute($lead_check);
mysqli_stmt_store_result($lead_check);

if (mysqli_stmt_num_rows($lead_check) === 0) {
    header("Location: " . APP_URL . "workspace/employee/");
    exit();
}

function count_open_tasks($conn, $project_id) {
    $q = mysqli_prepare($conn, "SELECT COUNT(*) FROM tasks WHERE project_id = ? AND status <> 'completed'");
    mysqli_stmt_bind_param($q, "i", $project_id);
    mysqli_stmt_execute($q);
    mysqli_stmt_bind_result($q, $n);
    mysqli_stmt_fetch($q);
    mysqli_stmt_close($q);
    return (int)$n;
}

// ── ACTION: request_deployment ────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "request_deployment") {
    verify_csrf_token();

    $project_id = (int)($_POST["project_id"] ?? 0);
    $notes      = trim($_POST["notes"] ?? "");
    $repo_raw   = trim($_POST["repo_url"] ?? "");
    $repo_url   = normalize_repo_url($repo_raw);

    // Verify user is team lead of this project
    $verify_lead = mysqli_prepare($conn,
        "SELECT id FROM project_members WHERE project_id = ? AND user_id = ? AND project_role = 'team_lead'"
    );
    mysqli_stmt_bind_param($verify_lead, "ii", $project_id, $user_id);
    mysqli_stmt_execute($verify_lead);
    mysqli_stmt_store_result($verify_lead);

    if (!$project_id || mysqli_stmt_num_rows($verify_lead) === 0) {
        $msg = "You are not the team lead of this project.";
    } elseif ($repo_url === null) {
        $msg = "The repository link must be a GitHub address like https://github.com/owner/repo.";
    } else {
        // Check project status is 'testing'
        $pcheck = mysqli_prepare($conn, "SELECT id, status, title, project_code FROM projects WHERE id = ?");
        mysqli_stmt_bind_param($pcheck, "i", $project_id);
        mysqli_stmt_execute($pcheck);
        $project = mysqli_fetch_assoc(mysqli_stmt_get_result($pcheck));

        if (!$project) {
            $msg = "Project not found.";
        } elseif ($project['status'] !== 'testing') {
            $msg = "Deployment can be requested only after testing has started. A tester starts it from the Testing Portal.";
        } elseif (($open_tasks = count_open_tasks($conn, $project_id)) > 0) {
            $msg = "Cannot request deployment: <strong>$open_tasks</strong> task(s) are not completed yet.";
        } else {
            // Check all bugs are closed or wont_fix
            $bug_check = mysqli_prepare($conn,
                "SELECT COUNT(*) FROM bugs WHERE project_id = ? AND status NOT IN ('closed','wont_fix')"
            );
            mysqli_stmt_bind_param($bug_check, "i", $project_id);
            mysqli_stmt_execute($bug_check);
            mysqli_stmt_bind_result($bug_check, $open_bug_count);
            mysqli_stmt_fetch($bug_check);
            mysqli_stmt_close($bug_check);

            if ($open_bug_count > 0) {
                $msg = "Cannot request deployment: <strong>$open_bug_count</strong> bug(s) are still open. All bugs must be closed or marked Won't Fix before deployment can be requested.";
            } else {
                // Update project status to deployment_pending
                $upd = mysqli_prepare($conn,
                    "UPDATE projects SET status = 'deployment_pending', deployment_notes = ?, deployment_requested_by = ?, deployment_requested_at = NOW() WHERE id = ?"
                );
                mysqli_stmt_bind_param($upd, "sii", $notes, $user_id, $project_id);

                if (mysqli_stmt_execute($upd)) {
                    if (docs_schema_ready($conn)) {
                        $ru = mysqli_prepare($conn, "UPDATE projects SET repo_url = ? WHERE id = ?");
                        $repo_store = $repo_url !== '' ? $repo_url : null;
                        mysqli_stmt_bind_param($ru, "si", $repo_store, $project_id);
                        mysqli_stmt_execute($ru);
                    }
                    $msg      = "Deployment request submitted for project <strong>{$project['project_code']}</strong>. Admin will review shortly.";
                    $msg_type = "success";
                } else {
                    $msg = "Failed to submit deployment request. Please try again.";
                }
            }
        }
    }
}

// ── FETCH: projects where user is team lead ───────────────────────────────────
$projects_result = mysqli_query($conn,
    "SELECT p.*, pm.project_role,
            u.name AS requester_name,
            (SELECT COUNT(*) FROM bugs b WHERE b.project_id = p.id AND b.status NOT IN ('closed','wont_fix')) AS open_bugs,
            (SELECT COUNT(*) FROM bugs b WHERE b.project_id = p.id) AS total_bugs,
            (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status <> 'completed') AS open_tasks,
            (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id) AS total_tasks,
            (SELECT COUNT(*) FROM bugs b WHERE b.project_id = p.id AND b.status IN ('closed','wont_fix')) AS closed_bugs
     FROM projects p
     JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = $user_id AND pm.project_role = 'team_lead'
     LEFT JOIN users u ON u.id = p.deployment_requested_by
     ORDER BY p.created_at DESC"
);

$projects = [];
while ($p = mysqli_fetch_assoc($projects_result)) {
    $p['requester_name'] = astra_db_decrypt($p['requester_name']);
    $projects[] = $p;
}

$in_progress  = ['testing','development_started','team_assigned'];
$is_ready     = fn($p) => $p['status'] === 'testing' && $p['open_bugs'] == 0 && $p['open_tasks'] == 0;
$deployable   = array_filter($projects, $is_ready);
$pending      = array_filter($projects, fn($p) => $p['status'] === 'deployment_pending');
$completed    = array_filter($projects, fn($p) => $p['status'] === 'completed');
$not_ready    = array_filter($projects, fn($p) => in_array($p['status'], $in_progress) && !$is_ready($p));

$status_labels = [
    "team_assigned"       => "Team Assigned",
    "development_started" => "Development Started",
    "testing"             => "Testing",
    "deployment_pending"  => "Deployment Pending",
    "completed"           => "Completed",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Deployment Portal · Hastra</title>
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

  .main { max-width: 1100px; margin: 0 auto; padding: 2rem; }
  .page-header { margin-bottom: 2rem; }
  .page-header h1 { font-size: 24px; font-weight: 600; letter-spacing: -0.01em; margin-bottom: 4px; }
  .page-header p  { font-size: 13px; color: var(--text-dim); }

  .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem; }
  .stat-card {
    background: var(--navy-card); border: 1px solid var(--border-dim);
    border-radius: 4px; padding: 1.2rem 1.4rem; position: relative; overflow: hidden;
  }
  .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; }
  .stat-card.green::before  { background: var(--green); }
  .stat-card.yellow::before { background: var(--yellow); }
  .stat-card.cyan::before   { background: var(--cyan); }
  .stat-card.red::before    { background: var(--red); }
  .stat-label { font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px; }
  .stat-value { font-size: 28px; font-weight: 700; line-height: 1; }

  .alert {
    display: flex; align-items: flex-start; gap: 8px;
    border-radius: 3px; padding: 10px 14px; margin-bottom: 1.5rem;
    font-size: 13px; border-left: 3px solid; line-height: 1.5;
  }
  .alert.success { background: var(--green-bg); border-color: var(--green); color: #86efac; }
  .alert.error   { background: var(--red-bg);   border-color: var(--red);   color: #fca5a5; }
  .alert svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; }
  .alert.success svg { fill: var(--green); }
  .alert.error   svg { fill: var(--red); }

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

  .badge {
    display: inline-block; padding: 2px 8px; border-radius: 2px;
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.05em; text-transform: uppercase; font-weight: 500;
  }
  .badge-team_assigned       { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .badge-development_started { background: rgba(34,211,238,0.1);   color: var(--cyan);        border: 1px solid rgba(34,211,238,0.2); }
  .badge-testing             { background: rgba(245,158,11,0.1);   color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }
  .badge-deployment_pending  { background: rgba(var(--purple-rgb),0.1);  color: var(--purple);      border: 1px solid rgba(var(--purple-rgb),0.2); }
  .badge-completed           { background: rgba(34,197,94,0.1);    color: var(--green);       border: 1px solid rgba(34,197,94,0.2); }

  .project-code-badge {
    font-family: 'Share Tech Mono', monospace; font-size: 11px; font-weight: 600;
    color: var(--cyan); background: rgba(34,211,238,0.08);
    border: 1px solid rgba(34,211,238,0.2); padding: 2px 7px; border-radius: 2px; letter-spacing: 0.08em;
  }

  /* ── PROJECT CARDS ── */
  .project-card {
    background: var(--navy-deep); border: 1px solid var(--border-dim);
    border-radius: 3px; padding: 1.2rem 1.4rem; margin-bottom: 1rem;
    transition: border-color 0.2s;
  }
  .project-card:hover { border-color: rgba(var(--accent-rgb),0.2); }
  .project-card.deployable { border-color: rgba(34,197,94,0.2); background: rgba(34,197,94,0.03); }
  .project-card.pending    { border-color: rgba(var(--purple-rgb),0.2); background: rgba(var(--purple-rgb),0.03); }
  .project-card.completed  { border-color: rgba(34,197,94,0.15); }
  .project-card.not-ready  { border-color: rgba(var(--red-rgb),0.15); background: rgba(var(--red-rgb),0.02); }

  .project-card-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 10px; gap: 12px; }
  .project-card-left { flex: 1; }
  .project-card-title { font-size: 15px; font-weight: 600; margin-bottom: 4px; }
  .project-card-meta  { font-size: 12px; color: var(--text-dim); }

  .bug-stats {
    display: flex; gap: 12px; margin: 10px 0; flex-wrap: wrap;
  }
  .bug-stat {
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    padding: 3px 8px; border-radius: 2px; letter-spacing: 0.04em;
  }
  .bug-stat.open   { background: rgba(var(--red-rgb),0.08);  color: #fca5a5;       border: 1px solid rgba(var(--red-rgb),0.2); }
  .bug-stat.closed { background: rgba(34,197,94,0.08);  color: var(--green);  border: 1px solid rgba(34,197,94,0.2); }
  .bug-stat.total  { background: var(--input-bg); color: var(--text-dim); border: 1px solid var(--border-dim); }

  /* ── DEPLOYMENT FORM ── */
  .deploy-form { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-dim); }
  .field label {
    display: block; font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.07em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px;
  }
  .field { margin-bottom: 10px; }
  .field-hint { font-size: 11px; color: var(--text-dim); margin-top: 4px; }
  .field textarea, .field input[type=url] {
    width: 100%; background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; color: var(--text); font-family: var(--font-sans);
    font-size: 13px; padding: 9px 12px; outline: none; resize: vertical;
    min-height: 80px; transition: border-color 0.2s;
  }
  .field input[type=url] { min-height: 0; resize: none; }
  .field textarea:focus, .field input[type=url]:focus { border-color: var(--accent-bright); }
  .field textarea::placeholder { color: var(--text-dim); }

  .btn-deploy {
    background: var(--green); color: white; border: none; border-radius: 3px;
    font-family: var(--font-sans); font-size: 13px; font-weight: 600;
    letter-spacing: 0.04em; text-transform: uppercase;
    padding: 10px 22px; cursor: pointer; margin-top: 10px;
    transition: background 0.2s, box-shadow 0.2s;
    display: flex; align-items: center; gap: 7px;
  }
  .btn-deploy svg { width: 14px; height: 14px; fill: white; }
  .btn-deploy:hover { background: #16a34a; box-shadow: 0 0 16px rgba(34,197,94,0.3); }

  .empty-state { text-align: center; padding: 2.5rem 1rem; color: var(--text-dim); font-size: 13px; }
  .empty-state svg { width: 32px; height: 32px; fill: var(--border-dim); margin: 0 auto 0.8rem; display: block; }

  .pending-note {
    margin-top: 10px; padding: 8px 12px;
    background: rgba(var(--purple-rgb),0.08); border: 1px solid rgba(var(--purple-rgb),0.2);
    border-left: 3px solid var(--purple); border-radius: 3px;
    font-size: 12px; color: var(--purple); line-height: 1.5;
  }
  .pending-note strong { color: var(--purple); font-size: 10px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.07em; text-transform: uppercase; display: block; margin-bottom: 3px; }

  .completed-note {
    margin-top: 10px; padding: 8px 12px;
    background: rgba(34,197,94,0.06); border: 1px solid rgba(34,197,94,0.2);
    border-left: 3px solid var(--green); border-radius: 3px;
    font-size: 12px; color: #86efac; line-height: 1.5;
  }
  .completed-note strong { color: var(--green); font-size: 10px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.07em; text-transform: uppercase; display: block; margin-bottom: 3px; }

  .not-ready-note {
    margin-top: 10px; padding: 8px 12px;
    background: rgba(var(--red-rgb),0.06); border: 1px solid rgba(var(--red-rgb),0.2);
    border-left: 3px solid var(--red); border-radius: 3px;
    font-size: 12px; color: #fca5a5; line-height: 1.5;
  }

  @media (max-width: 768px) {
    .stats-row { grid-template-columns: 1fr 1fr; }
  }
</style>
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/tour.css?v=<?= ASSET_VERSION ?>">
</head>
<body data-tour-page="emlpoyee/deployment_portal">

<?php $nav_current = 'deployment'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>Deployment Portal</h1>
    <p>Request deployment for your projects once all bugs are resolved.</p>
  </div>

  <div class="section-nav">
    <a class="section-nav-card" href="#sec-ready-deploy">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg></span>
      <span class="section-nav-label">Ready for Deployment</span>
    </a>
    <a class="section-nav-card" href="#sec-awaiting-approval">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/></svg></span>
      <span class="section-nav-label">Awaiting Approval</span>
    </a>
    <a class="section-nav-card" href="#sec-not-ready">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg></span>
      <span class="section-nav-label">Not Ready Yet</span>
    </a>
    <a class="section-nav-card" href="#sec-completed">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg></span>
      <span class="section-nav-label">Completed Projects</span>
    </a>
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
    <div class="stat-card green">
      <div class="stat-label">Ready to Deploy</div>
      <div class="stat-value"><?= count($deployable) ?></div>
    </div>
    <div class="stat-card yellow">
      <div class="stat-label">Awaiting Admin</div>
      <div class="stat-value"><?= count($pending) ?></div>
    </div>
    <div class="stat-card cyan">
      <div class="stat-label">Completed</div>
      <div class="stat-value"><?= count($completed) ?></div>
    </div>
    <div class="stat-card red">
      <div class="stat-label">Bugs Remaining</div>
      <div class="stat-value"><?= count($not_ready) ?></div>
    </div>
  </div>

  <!-- ── READY TO DEPLOY ── -->
  <div class="section" id="sec-ready-deploy">
    <div class="section-header">
      <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
      Ready for Deployment
      <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($deployable) ?> project<?= count($deployable) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="section-body">
      <?php if (empty($deployable)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
        No projects ready for deployment yet.
      </div>
      <?php else: foreach ($deployable as $proj): ?>
      <div class="project-card deployable">
        <div class="project-card-top">
          <div class="project-card-left">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">
              <span class="project-code-badge"><?= htmlspecialchars($proj['project_code']) ?></span>
              <span class="badge badge-<?= $proj['status'] ?>"><?= $status_labels[$proj['status']] ?></span>
            </div>
            <div class="project-card-title"><?= htmlspecialchars($proj['title']) ?></div>
          </div>
        </div>

        <div class="bug-stats">
          <span class="bug-stat total">Total Bugs: <?= $proj['total_bugs'] ?></span>
          <span class="bug-stat closed">Closed/Won't Fix: <?= $proj['closed_bugs'] ?></span>
          <span class="bug-stat open" style="background:rgba(34,197,94,0.08);color:var(--green);border-color:rgba(34,197,94,0.2);">Open: 0 ✓</span>
          <span class="bug-stat closed">Tasks done: <?= (int)$proj['total_tasks'] ?>/<?= (int)$proj['total_tasks'] ?></span>
        </div>

        <div class="deploy-form">
          <form method="POST" action="deployment-portal">
            <input type="hidden" name="csrf_token"  value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action"      value="request_deployment">
            <input type="hidden" name="project_id"  value="<?= $proj['id'] ?>">
            <div class="field">
              <label for="repo_<?= $proj['id'] ?>">GitHub repository (optional)</label>
              <input type="url" id="repo_<?= $proj['id'] ?>" name="repo_url" maxlength="255" placeholder="https://github.com/owner/repo" value="<?= htmlspecialchars($proj['repo_url'] ?? '') ?>">
              <div class="field-hint">A public repository lets the admin generate the project documentation from the code.</div>
            </div>
            <div class="field">
              <label>Deployment Notes / Summary</label>
              <textarea name="notes" placeholder="Summarise what was built, tested, and is ready for deployment. Include any important notes for the admin…"></textarea>
            </div>
            <button type="submit" class="btn-deploy">
              <svg viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
              Request Deployment
            </button>
          </form>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- ── AWAITING ADMIN APPROVAL ── -->
  <div class="section" id="sec-awaiting-approval">
    <div class="section-header">
      <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/></svg>
      Awaiting Admin Approval
      <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($pending) ?> project<?= count($pending) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="section-body">
      <?php if (empty($pending)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/></svg>
        No deployment requests pending.
      </div>
      <?php else: foreach ($pending as $proj): ?>
      <div class="project-card pending">
        <div class="project-card-top">
          <div class="project-card-left">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">
              <span class="project-code-badge"><?= htmlspecialchars($proj['project_code']) ?></span>
              <span class="badge badge-deployment_pending">Deployment Pending</span>
            </div>
            <div class="project-card-title"><?= htmlspecialchars($proj['title']) ?></div>
            <div class="project-card-meta">
              Requested <?= $proj['deployment_requested_at'] ? date('d M Y, H:i', strtotime($proj['deployment_requested_at'])) : '-' ?>
            </div>
          </div>
        </div>
        <?php if ($proj['deployment_notes']): ?>
        <div class="pending-note">
          <strong>Your Deployment Notes</strong>
          <?= nl2br(htmlspecialchars($proj['deployment_notes'])) ?>
        </div>
        <?php endif; ?>
        <?php if ($proj['admin_deployment_notes']): ?>
        <div class="pending-note" style="background:rgba(59,130,246,0.06);border-color:rgba(59,130,246,0.2);border-left-color:var(--blue-bright);color:#93c5fd;">
          <strong style="color:var(--blue-bright);">Admin Note</strong>
          <?= nl2br(htmlspecialchars($proj['admin_deployment_notes'])) ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- ── NOT READY (BUGS REMAINING) ── -->
  <?php if (!empty($not_ready)): ?>
  <div class="section" id="sec-not-ready">
    <div class="section-header">
      <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
      Not Ready Yet
      <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($not_ready) ?> project<?= count($not_ready) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="section-body">
      <?php foreach ($not_ready as $proj): ?>
      <div class="project-card not-ready">
        <div class="project-card-top">
          <div class="project-card-left">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">
              <span class="project-code-badge"><?= htmlspecialchars($proj['project_code']) ?></span>
              <span class="badge badge-<?= $proj['status'] ?>"><?= $status_labels[$proj['status']] ?></span>
            </div>
            <div class="project-card-title"><?= htmlspecialchars($proj['title']) ?></div>
          </div>
        </div>
        <div class="bug-stats">
          <span class="bug-stat total">Total Bugs: <?= $proj['total_bugs'] ?></span>
          <span class="bug-stat open">Open Bugs: <?= $proj['open_bugs'] ?></span>
          <span class="bug-stat closed">Closed: <?= $proj['closed_bugs'] ?></span>
          <span class="bug-stat <?= $proj['open_tasks'] > 0 ? 'open' : 'closed' ?>">Open Tasks: <?= (int)$proj['open_tasks'] ?></span>
        </div>
        <div class="not-ready-note">
          <?php if ($proj['status'] !== 'testing'): ?>
          Testing hasn't started. A tester or security tester starts it from the <a href="<?= get_base_url() ?>workspace/employee/testing-portal" style="color:#fca5a5;">Testing Portal</a>.<br>
          <?php endif; ?>
          <?php if ($proj['open_bugs'] > 0): ?>
          <strong><?= $proj['open_bugs'] ?></strong> bug<?= $proj['open_bugs'] != 1 ? 's' : '' ?> must be closed or marked Won't Fix.<br>
          <?php endif; ?>
          <?php if ($proj['open_tasks'] > 0): ?>
          <strong><?= $proj['open_tasks'] ?></strong> task<?= $proj['open_tasks'] != 1 ? 's' : '' ?> still need to be completed.
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── COMPLETED ── -->
  <?php if (!empty($completed)): ?>
  <div class="section" id="sec-completed">
    <div class="section-header">
      <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
      Completed Projects
      <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($completed) ?> project<?= count($completed) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="section-body">
      <?php foreach ($completed as $proj): ?>
      <div class="project-card completed">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">
          <span class="project-code-badge"><?= htmlspecialchars($proj['project_code']) ?></span>
          <span class="badge badge-completed">Completed</span>
        </div>
        <div class="project-card-title"><?= htmlspecialchars($proj['title']) ?></div>
        <?php if ($proj['admin_deployment_notes']): ?>
        <div class="completed-note">
          <strong>Admin Note on Completion</strong>
          <?= nl2br(htmlspecialchars($proj['admin_deployment_notes'])) ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
  window.ASTRA_CSRF_TOKEN = "<?= generate_csrf_token() ?>";
  window.ASTRA_BASE_URL   = "<?= get_base_url() ?>";
</script>
<script src="<?= get_base_url() ?>assets/js/tour-config.js?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= get_base_url() ?>assets/js/tour.js?v=<?= ASSET_VERSION ?>"></script>

</body>
</html>