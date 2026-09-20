<?php
// portals/client/my_projects.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "client");

$ctx           = client_context($conn, (int)$_SESSION["user_id"]);
$scope_ids     = id_list($ctx['member_ids']);
$can_comment   = client_can($ctx, 'comment');
if (!client_can($ctx, 'view_projects')) {
    header("Location: " . get_base_url() . "portals/client/docs");
    exit();
}

$msg      = "";
$msg_type = "error";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    verify_csrf_token();
    $action = $_POST["action"] ?? "";

    if ($action === "post_comment") {
        $user_id    = (int)$_SESSION["user_id"];
        $project_id = (int)($_POST["project_id"] ?? 0);
        $comment    = trim($_POST["comment"] ?? "");

        if (!$can_comment) {
            $msg = "You can't comment on projects.";
        } elseif ($project_id && $comment !== "") {
            // Verify this project belongs to this client
            $proj_check = mysqli_prepare($conn,
                "SELECT p.id FROM projects p
                 JOIN requirements r ON p.requirement_id = r.id
                 WHERE p.id = ? AND r.user_id IN ($scope_ids)"
            );
            mysqli_stmt_bind_param($proj_check, "i", $project_id);
            mysqli_stmt_execute($proj_check);
            mysqli_stmt_store_result($proj_check);

            if (mysqli_stmt_num_rows($proj_check) > 0) {
                $ins = mysqli_prepare($conn,
                    "INSERT INTO project_comments (project_id, user_id, comment) VALUES (?, ?, ?)"
                );
                mysqli_stmt_bind_param($ins, "iis", $project_id, $user_id, $comment);
                if (mysqli_stmt_execute($ins)) {
                    $msg      = "Comment posted.";
                    $msg_type = "success";
                } else {
                    $msg = "Failed to post comment.";
                }
            } else {
                $msg = "Project not found or access denied.";
            }
        } else {
            $msg = "Comment cannot be empty.";
        }
    }
}

// ── Fetch projects for this client's approved requirements ───────────────────
$client_projects = [];
$proj_stmt = mysqli_prepare($conn,
    "SELECT p.id, p.project_code, p.title, p.status, p.created_at,
            r.requirement_id AS req_code, r.project_title AS req_title,
            (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id) AS total_tasks,
            (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status = 'completed') AS completed_tasks,
            (SELECT COUNT(*) FROM bugs b WHERE b.project_id = p.id AND b.status NOT IN ('closed','wont_fix')) AS open_bugs
     FROM projects p
     JOIN requirements r ON p.requirement_id = r.id
     WHERE r.user_id IN ($scope_ids)
     ORDER BY p.created_at DESC"
);
mysqli_stmt_execute($proj_stmt);
$proj_result = mysqli_stmt_get_result($proj_stmt);

while ($p = mysqli_fetch_assoc($proj_result)) {
    // Fetch team members
    $mem_q = mysqli_prepare($conn,
        "SELECT u.name, pm.project_role
         FROM project_members pm
         JOIN users u ON pm.user_id = u.id
         WHERE pm.project_id = ?
         ORDER BY FIELD(pm.project_role,'team_lead','developer','tester','security_tester','debugger')"
    );
    mysqli_stmt_bind_param($mem_q, "i", $p['id']);
    mysqli_stmt_execute($mem_q);
    $p['members'] = mysqli_stmt_get_result($mem_q)->fetch_all(MYSQLI_ASSOC);

    // Fetch comments
    $com_q = mysqli_prepare($conn,
        "SELECT pc.comment, pc.created_at, u.name, u.role
         FROM project_comments pc
         JOIN users u ON pc.user_id = u.id
         WHERE pc.project_id = ?
         ORDER BY pc.created_at ASC"
    );
    mysqli_stmt_bind_param($com_q, "i", $p['id']);
    mysqli_stmt_execute($com_q);
    $p['comments'] = mysqli_stmt_get_result($com_q)->fetch_all(MYSQLI_ASSOC);

    $p['progress'] = $p['total_tasks'] > 0
        ? round(($p['completed_tasks'] / $p['total_tasks']) * 100)
        : 0;

    $client_projects[] = $p;
}

$project_status_labels = [
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Projects · Astra</title>
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

  .badge {
    display: inline-block; padding: 2px 8px; border-radius: 2px;
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.05em; text-transform: uppercase; font-weight: 500;
  }

  .empty-state {
    text-align: center; padding: 3rem 1rem;
    color: var(--text-dim); font-size: 13px;
  }
  .empty-state svg { width: 36px; height: 36px; fill: var(--border-dim); margin: 0 auto 0.8rem; display: block; }
  .empty-state p   { margin-bottom: 0.3rem; }
  .empty-state span { font-size: 12px; }

  /* ── PROJECT PROGRESS SECTION ── */
  .proj-card {
    background: var(--navy-card);
    border: 1px solid var(--border-dim);
    border-radius: 4px; overflow: hidden; margin-bottom: 1.2rem;
  }
  .proj-card-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    padding: 1.1rem 1.4rem; border-bottom: 1px solid var(--border-dim);
    background: var(--section-header-bg); gap: 12px; cursor: pointer;
    transition: background 0.15s;
  }
  .proj-card-header:hover { background: var(--hover-bg); }
  .proj-card-body { padding: 1.4rem; }
  .proj-code {
    font-family: 'Share Tech Mono', monospace; font-size: 11px; font-weight: 600;
    color: #22d3ee; background: rgba(34,211,238,0.08);
    border: 1px solid rgba(34,211,238,0.2); padding: 2px 7px; border-radius: 2px;
    letter-spacing: 0.08em; margin-bottom: 5px; display: inline-block;
  }
  .proj-title { font-size: 15px; font-weight: 600; margin-bottom: 3px; }
  .proj-meta  { font-size: 12px; color: var(--text-dim); }

  .badge-team_assigned       { background: rgba(59,130,246,0.1);   color: #3b82f6; border: 1px solid rgba(59,130,246,0.2); }
  .badge-development_started { background: rgba(34,211,238,0.1);   color: #22d3ee; border: 1px solid rgba(34,211,238,0.2); }
  .badge-testing             { background: rgba(245,158,11,0.1);   color: #f59e0b; border: 1px solid rgba(245,158,11,0.2); }
  .badge-deployment_pending  { background: rgba(167,139,250,0.1);  color: #a78bfa; border: 1px solid rgba(167,139,250,0.2); }
  .badge-completed           { background: rgba(34,197,94,0.1);    color: #22c55e; border: 1px solid rgba(34,197,94,0.2); }

  .progress-track {
    height: 10px; background: rgba(255,255,255,0.06);
    border-radius: 5px; overflow: hidden; margin: 8px 0 4px;
  }
  .progress-fill-bar {
    height: 100%; border-radius: 5px;
    background: linear-gradient(90deg, var(--accent-bright), #22d3ee);
    transition: width 1.2s cubic-bezier(0.4,0,0.2,1);
  }
  .progress-fill-bar.complete { background: linear-gradient(90deg, #22c55e, #4ade80); }
  .progress-meta { font-size: 11px; color: var(--text-dim); font-family: 'Share Tech Mono', monospace; }

  .members-row { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 1rem; }
  .member-pill {
    display: flex; align-items: center; gap: 6px;
    background: var(--input-bg); border: 1px solid var(--border-dim);
    border-radius: 3px; padding: 4px 10px; font-size: 12px;
  }
  .member-pill-avatar {
    width: 20px; height: 20px; border-radius: 50%;
    background: rgba(var(--accent-rgb),0.12); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: 9px; font-weight: 700; color: var(--accent-bright);
  }
  .role-pill {
    font-size: 9px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.05em; text-transform: uppercase;
    padding: 1px 6px; border-radius: 2px;
  }
  .role-team_lead       { background: rgba(167,139,250,0.12); color: #a78bfa; border: 1px solid rgba(167,139,250,0.2); }
  .role-developer       { background: rgba(59,130,246,0.1);   color: #3b82f6; border: 1px solid rgba(59,130,246,0.2); }
  .role-tester          { background: rgba(34,211,238,0.1);   color: #22d3ee; border: 1px solid rgba(34,211,238,0.2); }
  .role-security_tester { background: rgba(239,68,68,0.1);    color: #fca5a5; border: 1px solid rgba(239,68,68,0.2); }
  .role-debugger        { background: rgba(245,158,11,0.1);   color: #f59e0b; border: 1px solid rgba(245,158,11,0.2); }

  /* ── COMMENTS ── */
  .comments-section { margin-top: 1.2rem; padding-top: 1.2rem; border-top: 1px solid var(--border-dim); }
  .comments-title {
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.08em; text-transform: uppercase;
    color: var(--text-dim); margin-bottom: 10px;
  }
  .comment-item {
    display: flex; gap: 10px; margin-bottom: 10px;
  }
  .comment-avatar {
    width: 28px; height: 28px; flex-shrink: 0; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700;
    background: rgba(var(--accent-rgb),0.12); border: 1px solid var(--border);
    color: var(--accent-bright);
  }
  .comment-avatar.admin-av { background: rgba(34,197,94,0.12); border-color: rgba(34,197,94,0.2); color: #22c55e; }
  .comment-body {
    flex: 1; background: var(--input-bg);
    border: 1px solid var(--border-dim); border-radius: 3px;
    padding: 8px 12px;
  }
  .comment-body-top {
    display: flex; align-items: center; gap: 8px; margin-bottom: 4px;
  }
  .comment-name  { font-size: 12px; font-weight: 600; }
  .comment-time  { font-size: 11px; color: var(--text-dim); font-family: 'Share Tech Mono', monospace; }
  .comment-text  { font-size: 13px; color: var(--text); line-height: 1.5; }
  .comment-form  { display: flex; gap: 8px; margin-top: 10px; }
  .comment-input {
    flex: 1; background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; color: var(--text); font-family: var(--font-sans);
    font-size: 13px; padding: 9px 12px; outline: none; resize: none;
    min-height: 70px; transition: border-color 0.2s;
  }
  .comment-input:focus { border-color: var(--accent-bright); }
  .comment-input::placeholder { color: var(--text-dim); }
  .btn-comment {
    background: #1d4ed8; color: white; border: none; border-radius: 3px;
    font-family: var(--font-sans); font-size: 12px; font-weight: 600;
    letter-spacing: 0.04em; text-transform: uppercase;
    padding: 0 16px; cursor: pointer; transition: background 0.2s;
    align-self: flex-end; height: 36px;
  }
  .btn-comment:hover { background: #2563eb; }

  .chevron-icon {
    width: 16px; height: 16px; fill: var(--text-dim);
    transition: transform 0.25s; flex-shrink: 0;
  }
  .chevron-icon.open { transform: rotate(180deg); }
  .bug-warn {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; color: #fca5a5;
    background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.2);
    border-radius: 2px; padding: 2px 7px;
    font-family: 'Share Tech Mono', monospace; letter-spacing: 0.04em;
  }
</style>
</head>
<body>

<?php $nav_current = 'projects'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>My Projects</h1>
    <p>Track progress, team assignments and comments for your active projects.</p>
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

  <div class="section">
    <div class="section-header">
      <svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
      My Projects
      <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:8px;"><?= count($client_projects) ?> total</span>
    </div>

    <?php if (empty($client_projects)): ?>
    <div class="empty-state">
      <svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
      <p>No projects yet.</p>
      <span>Projects appear here once a submitted requirement is approved.</span>
    </div>
    <?php else: ?>

    <div style="padding:1.4rem;">
      <?php foreach ($client_projects as $proj): ?>
      <div class="proj-card">

        <!-- Header (clickable to expand) -->
        <div class="proj-card-header" onclick="toggleProj(<?= $proj['id'] ?>)">
          <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">
              <span class="proj-code"><?= htmlspecialchars($proj['project_code']) ?></span>
              <span class="badge badge-<?= $proj['status'] ?>">
                <?= htmlspecialchars($project_status_labels[$proj['status']] ?? $proj['status']) ?>
              </span>
              <?php if ($proj['open_bugs'] > 0): ?>
              <span class="bug-warn">⚠ <?= $proj['open_bugs'] ?> open bug<?= $proj['open_bugs'] != 1 ? 's' : '' ?></span>
              <?php endif; ?>
            </div>
            <div class="proj-title"><?= htmlspecialchars($proj['title']) ?></div>
            <div class="proj-meta">Based on requirement <strong><?= htmlspecialchars($proj['req_code']) ?></strong> · Created <?= date('d M Y', strtotime($proj['created_at'])) ?></div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:13px;font-weight:700;color:<?= $proj['progress'] >= 100 ? '#22c55e' : 'var(--text)' ?>;"><?= $proj['progress'] ?>%</span>
            <svg class="chevron-icon" id="chev-<?= $proj['id'] ?>" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5z"/></svg>
          </div>
        </div>

        <!-- Expandable body -->
        <div class="proj-card-body" id="proj-body-<?= $proj['id'] ?>" style="display:none;">

          <!-- Progress bar -->
          <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
              <span style="font-size:11px;font-family:'Share Tech Mono',monospace;letter-spacing:0.07em;text-transform:uppercase;color:var(--text-dim);">Task Completion</span>
              <span style="font-size:13px;font-weight:700;"><?= $proj['progress'] ?>%</span>
            </div>
            <div class="progress-track">
              <div class="progress-fill-bar <?= $proj['progress'] >= 100 ? 'complete' : '' ?>"
                   style="width:<?= $proj['progress'] ?>%"></div>
            </div>
            <div class="progress-meta"><?= $proj['completed_tasks'] ?> of <?= $proj['total_tasks'] ?> tasks completed</div>
          </div>

          <!-- Phase timeline -->
          <?php
            $phases = [
              'team_assigned'       => 1,
              'development_started' => 2,
              'testing'             => 3,
              'deployment_pending'  => 4,
              'completed'           => 5,
            ];
            $phase_labels = [
              'team_assigned'       => 'Team Assigned',
              'development_started' => 'Development',
              'testing'             => 'Testing',
              'deployment_pending'  => 'Deployment',
              'completed'           => 'Completed',
            ];
            $current_phase = $phases[$proj['status']] ?? 1;
          ?>
          <div style="margin-top:1.2rem;">
            <div style="font-size:11px;font-family:'Share Tech Mono',monospace;letter-spacing:0.07em;text-transform:uppercase;color:var(--text-dim);margin-bottom:10px;">Project Phase</div>
            <div style="display:flex;align-items:center;gap:0;">
              <?php foreach ($phases as $phase_key => $phase_num): ?>
              <div style="flex:1;display:flex;flex-direction:column;align-items:center;position:relative;">
                <?php if ($phase_num < count($phases)): ?>
                <div style="position:absolute;top:14px;left:50%;width:100%;height:2px;background:<?= $phase_num < $current_phase ? '#22c55e' : 'rgba(255,255,255,0.07)' ?>;z-index:0;"></div>
                <?php endif; ?>
                <div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;position:relative;z-index:1;
                  border:2px solid <?= $phase_num < $current_phase ? '#22c55e' : ($phase_num === $current_phase ? 'var(--accent-bright)' : 'rgba(255,255,255,0.07)') ?>;
                  background:<?= $phase_num < $current_phase ? 'rgba(34,197,94,0.12)' : ($phase_num === $current_phase ? 'rgba(var(--accent-rgb),0.12)' : 'rgba(255,255,255,0.03)') ?>;">
                  <?php if ($phase_num < $current_phase): ?>
                  <svg style="width:13px;height:13px;fill:#22c55e;" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                  <?php elseif ($phase_num === $current_phase): ?>
                  <div style="width:8px;height:8px;border-radius:50%;background:var(--accent-bright);animation:pulseAccent 2s infinite;"></div>
                  <?php else: ?>
                  <div style="width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,0.1);"></div>
                  <?php endif; ?>
                </div>
                <div style="font-size:10px;font-family:'Share Tech Mono',monospace;letter-spacing:0.04em;text-align:center;margin-top:6px;
                  color:<?= $phase_num < $current_phase ? '#22c55e' : ($phase_num === $current_phase ? 'var(--accent-bright)' : 'var(--text-dim)') ?>;">
                  <?= $phase_labels[$phase_key] ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Team members -->
          <?php if (!empty($proj['members'])): ?>
          <div style="margin-top:1.2rem;">
            <div style="font-size:11px;font-family:'Share Tech Mono',monospace;letter-spacing:0.07em;text-transform:uppercase;color:var(--text-dim);margin-bottom:8px;">Team</div>
            <div class="members-row">
              <?php foreach ($proj['members'] as $m): ?>
              <div class="member-pill">
                <div class="member-pill-avatar"><?= strtoupper(substr($m['name'], 0, 1)) ?></div>
                <span style="font-size:12px;"><?= htmlspecialchars($m['name']) ?></span>
                <span class="role-pill role-<?= $m['project_role'] ?>"><?= str_replace('_', ' ', $m['project_role']) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- Comments -->
          <div class="comments-section">
            <div class="comments-title">Comments & Clarifications (<?= count($proj['comments']) ?>)</div>

            <?php if (empty($proj['comments'])): ?>
            <div style="font-size:13px;color:var(--text-dim);padding:0.5rem 0;">No comments yet. Use the box below to ask a question or leave a note for the team.</div>
            <?php else: ?>
            <?php foreach ($proj['comments'] as $c):
              $is_staff = in_array($c['role'], ['admin','employee','sysadmin']);
            ?>
            <div class="comment-item">
              <div class="comment-avatar <?= $is_staff ? 'admin-av' : '' ?>"><?= strtoupper(substr($c['name'], 0, 1)) ?></div>
              <div class="comment-body">
                <div class="comment-body-top">
                  <span class="comment-name"><?= htmlspecialchars($c['name']) ?></span>
                  <?php if ($is_staff): ?>
                  <span style="font-size:10px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.2);color:#22c55e;padding:1px 6px;border-radius:2px;font-family:'Share Tech Mono',monospace;letter-spacing:0.05em;">TEAM</span>
                  <?php endif; ?>
                  <span class="comment-time"><?= date('d M Y, H:i', strtotime($c['created_at'])) ?></span>
                </div>
                <div class="comment-text"><?= nl2br(htmlspecialchars($c['comment'])) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Post comment form -->
            <?php if ($can_comment): ?>
            <form method="POST" action="my_projects">
              <input type="hidden" name="csrf_token"  value="<?= generate_csrf_token() ?>">
              <input type="hidden" name="action"      value="post_comment">
              <input type="hidden" name="project_id"  value="<?= $proj['id'] ?>">
              <div class="comment-form">
                <textarea class="comment-input" name="comment"
                  placeholder="Ask a question, request a clarification, or leave a note for the team…"></textarea>
                <button type="submit" class="btn-comment">Post</button>
              </div>
            </form>
            <?php endif; ?>
          </div>

        </div><!-- /proj-card-body -->
      </div><!-- /proj-card -->
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<script>
// ── Project card expand/collapse ──────────────────────────────────────────────
function toggleProj(id) {
  const body  = document.getElementById('proj-body-' + id);
  const chev  = document.getElementById('chev-' + id);
  const isOpen = body.style.display !== 'none';
  body.style.display = isOpen ? 'none' : 'block';
  chev.classList.toggle('open', !isOpen);
}

// ── Pulse animation for current phase dot ─────────────────────────────────────
const style = document.createElement('style');
style.textContent = `@keyframes pulseAccent {
  0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(var(--accent-rgb),0.4); }
  50%       { opacity: 0.8; box-shadow: 0 0 0 5px rgba(var(--accent-rgb),0); }
}`;
document.head.appendChild(style);
</script>

</body>
</html>
