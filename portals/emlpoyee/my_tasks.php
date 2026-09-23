<?php
// portals/emlpoyee/my_tasks.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "employee");

$msg      = "";
$msg_type = "error";
$user_id  = (int)$_SESSION["user_id"];

// ── UPDATE TASK STATUS ────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "update_task_status") {
    verify_csrf_token();

    $task_id    = (int)($_POST["task_id"] ?? 0);
    $new_status = trim($_POST["new_status"] ?? "");
    $allowed    = ["pending", "in_progress", "completed", "blocked"];

    $verify_task = mysqli_prepare($conn,
        "SELECT id FROM tasks WHERE id = ? AND assigned_to = ?"
    );
    mysqli_stmt_bind_param($verify_task, "ii", $task_id, $user_id);
    mysqli_stmt_execute($verify_task);
    mysqli_stmt_store_result($verify_task);

    if (!$task_id || !in_array($new_status, $allowed)) {
        $msg = "Invalid request.";
    } elseif (mysqli_stmt_num_rows($verify_task) === 0) {
        $msg = "Task not found or not assigned to you.";
    } else {
        $upd = mysqli_prepare($conn, "UPDATE tasks SET status = ? WHERE id = ?");
        mysqli_stmt_bind_param($upd, "si", $new_status, $task_id);
        if (mysqli_stmt_execute($upd)) {
            $msg      = "Task status updated.";
            $msg_type = "success";
        } else {
            $msg = "Failed to update task.";
        }
    }
}

// ── UPLOAD FILE ───────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "upload_file") {
    verify_csrf_token();

    $task_id = (int)($_POST["task_id"] ?? 0);

    // Verify user is assigned to this task OR is team lead of its project
    $access = mysqli_prepare($conn,
        "SELECT t.id FROM tasks t
         LEFT JOIN project_members pm ON pm.project_id = t.project_id AND pm.user_id = ? AND pm.project_role = 'team_lead'
         WHERE t.id = ? AND (t.assigned_to = ? OR pm.id IS NOT NULL)"
    );
    mysqli_stmt_bind_param($access, "iii", $user_id, $task_id, $user_id);
    mysqli_stmt_execute($access);
    mysqli_stmt_store_result($access);

    if (!$task_id || mysqli_stmt_num_rows($access) === 0) {
        $msg = "Access denied.";
    } elseif (!isset($_FILES['task_file']) || $_FILES['task_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = "No file uploaded or upload error.";
    } else {
        $file     = $_FILES['task_file'];
        $max_size = 10 * 1024 * 1024; // 10MB
        $allowed_types = [
            'image/jpeg','image/png','image/gif','image/webp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain','text/csv',
            'application/zip','application/x-zip-compressed',
        ];

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mime     = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($file['size'] > $max_size) {
            $msg = "File too large. Maximum size is 10MB.";
        } elseif (!in_array($mime, $allowed_types)) {
            $msg = "File type not allowed.";
        } else {
            $upload_dir = __DIR__ . '/../../uploads/tasks/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $ext       = pathinfo($file['name'], PATHINFO_EXTENSION);
            $safe_name = bin2hex(random_bytes(16)) . '.' . $ext;
            $dest      = $upload_dir . $safe_name;

            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $ins = mysqli_prepare($conn,
                    "INSERT INTO task_files (task_id, uploaded_by, file_name, file_path, file_size)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $orig_name = htmlspecialchars($file['name']);
                mysqli_stmt_bind_param($ins, "iissi",
                    $task_id, $user_id, $orig_name, $dest, $file['size']
                );
                if (mysqli_stmt_execute($ins)) {
                    $msg      = "File uploaded successfully.";
                    $msg_type = "success";
                } else {
                    unlink($dest);
                    $msg = "Failed to save file record.";
                }
            } else {
                $msg = "Failed to move uploaded file.";
            }
        }
    }
}

// ── DELETE FILE ───────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "delete_file") {
    verify_csrf_token();

    $file_id = (int)($_POST["file_id"] ?? 0);

    $fcheck = mysqli_prepare($conn,
        "SELECT tf.*, t.project_id FROM task_files tf
         JOIN tasks t ON tf.task_id = t.id
         WHERE tf.id = ?"
    );
    mysqli_stmt_bind_param($fcheck, "i", $file_id);
    mysqli_stmt_execute($fcheck);
    $frow = mysqli_fetch_assoc(mysqli_stmt_get_result($fcheck));

    if (!$frow) {
        $msg = "File not found.";
    } else {
        // Allow uploader or team lead to delete
        $is_uploader = ($frow['uploaded_by'] === $user_id);
        $lead_chk = mysqli_prepare($conn,
            "SELECT id FROM project_members WHERE project_id = ? AND user_id = ? AND project_role = 'team_lead'"
        );
        mysqli_stmt_bind_param($lead_chk, "ii", $frow['project_id'], $user_id);
        mysqli_stmt_execute($lead_chk);
        mysqli_stmt_store_result($lead_chk);
        $is_proj_lead = mysqli_stmt_num_rows($lead_chk) > 0;

        if (!$is_uploader && !$is_proj_lead) {
            $msg = "Access denied.";
        } else {
            if (file_exists($frow['file_path'])) unlink($frow['file_path']);
            $del = mysqli_prepare($conn, "DELETE FROM task_files WHERE id = ?");
            mysqli_stmt_bind_param($del, "i", $file_id);
            if (mysqli_stmt_execute($del)) {
                $msg      = "File deleted.";
                $msg_type = "success";
            } else {
                $msg = "Failed to delete file.";
            }
        }
    }
}

// ── FETCH: my assigned tasks with files ───────────────────────────────────────
$my_tasks_result = mysqli_query($conn,
    "SELECT t.*, p.title AS project_title, p.project_code,
            u.name AS assigned_by_name
     FROM tasks t
     JOIN projects p ON t.project_id = p.id
     JOIN users u ON t.created_by = u.id
     WHERE t.assigned_to = $user_id
     ORDER BY FIELD(t.priority,'critical','high','medium','low'), t.created_at DESC"
);
$my_tasks = [];
while ($t = mysqli_fetch_assoc($my_tasks_result)) {
    $t['assigned_by_name'] = astra_db_decrypt($t['assigned_by_name']);
    $fq = mysqli_prepare($conn,
        "SELECT tf.*, u.name AS uploader_name FROM task_files tf
         JOIN users u ON tf.uploaded_by = u.id
         WHERE tf.task_id = ? ORDER BY tf.uploaded_at DESC"
    );
    mysqli_stmt_bind_param($fq, "i", $t['id']);
    mysqli_stmt_execute($fq);
    $files = mysqli_stmt_get_result($fq)->fetch_all(MYSQLI_ASSOC);
    foreach ($files as &$__f) $__f['uploader_name'] = astra_db_decrypt($__f['uploader_name']);
    unset($__f);
    $t['files'] = $files;
    $my_tasks[] = $t;
}

$priority_labels = [
    "low" => "Low", "medium" => "Medium", "high" => "High", "critical" => "Critical",
];
$task_status_labels = [
    "pending" => "Pending", "in_progress" => "In Progress",
    "completed" => "Completed", "blocked" => "Blocked",
];

function format_bytes($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Tasks · Astra</title>
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
  .page-header p  { font-size: 13px; color: var(--text-dim); }

  .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem; }
  .stat-card {
    background: var(--navy-card); border: 1px solid var(--border-dim);
    border-radius: 4px; padding: 1.2rem 1.4rem; position: relative; overflow: hidden;
  }
  .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; }
  .stat-card.blue::before   { background: var(--blue-bright); }
  .stat-card.yellow::before { background: var(--yellow); }
  .stat-card.green::before  { background: var(--green); }
  .stat-card.orange::before { background: var(--orange); }
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
    display: flex; align-items: center; justify-content: space-between;
    padding: 1rem 1.4rem; border-bottom: 1px solid var(--border-dim);
    background: var(--section-header-bg);
  }
  .section-title { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; }
  .section-title svg { width: 15px; height: 15px; fill: var(--accent-bright); }
  .section-body { padding: 1.4rem; }

  .badge {
    display: inline-block; padding: 2px 8px; border-radius: 2px;
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.05em; text-transform: uppercase; font-weight: 500;
  }
  .badge-pending     { background: rgba(148,163,184,0.08); color: var(--text-dim);    border: 1px solid rgba(148,163,184,0.15); }
  .badge-in_progress { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .badge-completed   { background: rgba(34,197,94,0.1);    color: var(--green);       border: 1px solid rgba(34,197,94,0.2); }
  .badge-blocked     { background: rgba(var(--red-rgb),0.1);    color: var(--red);         border: 1px solid rgba(var(--red-rgb),0.2); }

  .priority-badge {
    display: inline-block; padding: 2px 7px; border-radius: 2px;
    font-size: 10px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.05em; text-transform: uppercase; font-weight: 600;
  }
  .priority-low      { background: rgba(148,163,184,0.08); color: var(--text-dim);    border: 1px solid rgba(148,163,184,0.15); }
  .priority-medium   { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .priority-high     { background: rgba(245,158,11,0.1);   color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }
  .priority-critical { background: rgba(var(--red-rgb),0.12);   color: var(--red);         border: 1px solid rgba(var(--red-rgb),0.3); }

  .task-code {
    font-family: 'Share Tech Mono', monospace; font-size: 11px; font-weight: 600;
    color: var(--cyan); background: rgba(34,211,238,0.08);
    border: 1px solid rgba(34,211,238,0.2); padding: 2px 7px; border-radius: 2px; letter-spacing: 0.08em;
  }
  .project-code-badge {
    font-family: 'Share Tech Mono', monospace; font-size: 11px; font-weight: 600;
    color: var(--accent-bright); background: rgba(var(--accent-rgb),0.08);
    border: 1px solid rgba(var(--accent-rgb),0.2); padding: 2px 7px; border-radius: 2px; letter-spacing: 0.08em;
  }

  .tbl-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead tr { background: var(--section-header-bg); border-bottom: 1px solid var(--border-dim); }
  th {
    padding: 10px 14px; text-align: left; font-size: 11px;
    font-family: 'Share Tech Mono', monospace; letter-spacing: 0.08em;
    text-transform: uppercase; color: var(--text-dim); font-weight: 400; white-space: nowrap;
  }
  tbody tr { border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.15s; }
  tbody tr:hover { background: var(--hover-bg); }
  tbody tr:last-child { border-bottom: none; }
  td { padding: 11px 14px; color: var(--text); vertical-align: middle; }
  td.muted { color: var(--text-dim); font-family: 'Share Tech Mono', monospace; font-size: 12px; }

  .status-select {
    background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; color: var(--text); font-family: var(--font-sans);
    font-size: 12px; padding: 5px 8px; outline: none; cursor: pointer; transition: border-color 0.2s;
  }
  .status-select:focus { border-color: var(--accent-bright); }
  .status-select option { background: var(--navy-card); }

  .btn-update-status {
    background: var(--accent); color: white; border: none; border-radius: 3px;
    font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;
    padding: 5px 12px; cursor: pointer; transition: background 0.2s;
  }
  .btn-update-status:hover { background: var(--accent-bright); }

  .empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-dim); font-size: 13px; }
  .empty-state svg { width: 32px; height: 32px; fill: var(--border-dim); margin: 0 auto 0.8rem; display: block; }

  .file-list { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
  .file-chip {
    display: flex; align-items: center; gap: 6px;
    background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; padding: 4px 8px; font-size: 11px;
    transition: border-color 0.15s;
  }
  .file-chip:hover { border-color: rgba(var(--accent-rgb),0.3); }
  .file-chip svg { width: 12px; height: 12px; fill: var(--accent-bright); flex-shrink: 0; }
  .file-chip-name { color: var(--text); max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .file-chip-size { color: var(--text-dim); font-family: 'Share Tech Mono', monospace; }

  .btn-delete-file {
    background: none; border: none; color: var(--text-dim);
    cursor: pointer; padding: 2px; border-radius: 2px;
    transition: color 0.15s; line-height: 0;
  }
  .btn-delete-file:hover { color: var(--red); }
  .btn-delete-file svg { width: 11px; height: 11px; fill: currentColor; }

  .drop-zone {
    border: 1px dashed var(--border-dim); border-radius: 3px;
    padding: 12px; text-align: center; cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
    position: relative;
  }
  .drop-zone:hover, .drop-zone.dragover {
    border-color: var(--accent-bright);
    background: rgba(var(--accent-rgb),0.05);
  }
  .drop-zone input[type="file"] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
  }
  .drop-zone-icon { width: 20px; height: 20px; fill: var(--text-dim); margin: 0 auto 6px; display: block; }
  .drop-zone-text { font-size: 12px; color: var(--text-dim); }
  .drop-zone-text span { color: var(--accent-bright); }
  .drop-zone-hint { font-size: 10px; color: var(--text-dim); margin-top: 3px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.04em; }
  .drop-zone.uploading { opacity: 0.6; pointer-events: none; }

  @media (max-width: 768px) {
    .stats-row { grid-template-columns: 1fr 1fr; }
  }
</style>
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/tour.css?v=<?= ASSET_VERSION ?>">
</head>
<body data-tour-page="emlpoyee/my_tasks">

<?php $nav_current = 'my_tasks'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>My Tasks</h1>
    <p>View and update your assigned tasks.</p>
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

  <?php
    $total   = count($my_tasks);
    $pending = count(array_filter($my_tasks, fn($t) => $t['status'] === 'pending'));
    $inprog  = count(array_filter($my_tasks, fn($t) => $t['status'] === 'in_progress'));
    $done    = count(array_filter($my_tasks, fn($t) => $t['status'] === 'completed'));
  ?>

  <div class="stats-row">
    <div class="stat-card blue">
      <div class="stat-label">Total Tasks</div>
      <div class="stat-value"><?= $total ?></div>
    </div>
    <div class="stat-card yellow">
      <div class="stat-label">Pending</div>
      <div class="stat-value"><?= $pending ?></div>
    </div>
    <div class="stat-card orange">
      <div class="stat-label">In Progress</div>
      <div class="stat-value"><?= $inprog ?></div>
    </div>
    <div class="stat-card green">
      <div class="stat-label">Completed</div>
      <div class="stat-value"><?= $done ?></div>
    </div>
  </div>

  <div class="section" id="sec-my-tasks">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M9 11H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm2-7h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg>
        My Tasks
        <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= $total ?> assigned</span>
      </div>
    </div>

    <?php if (empty($my_tasks)): ?>
    <div class="empty-state">
      <svg viewBox="0 0 24 24"><path d="M9 11H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm2-7h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg>
      No tasks assigned to you yet.
    </div>
    <?php else: ?>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>Task</th>
            <th>Project</th>
            <th>Priority</th>
            <th>Deadline</th>
            <th>Assigned By</th>
            <th>Status</th>
            <th>Update</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($my_tasks as $task): ?>
          <tr>
            <td>
              <span class="task-code"><?= htmlspecialchars($task['task_code']) ?></span>
              <div style="font-size:13px;font-weight:600;margin-top:4px;"><?= htmlspecialchars($task['title']) ?></div>
              <?php if ($task['description']): ?>
              <div style="font-size:12px;color:var(--text-dim);margin-top:2px;"><?= htmlspecialchars(substr($task['description'], 0, 80)) ?><?= strlen($task['description']) > 80 ? '…' : '' ?></div>
              <?php endif; ?>

              <div style="margin-top:10px;">
                <?php if (!empty($task['files'])): ?>
                <div class="file-list" style="margin-bottom:6px;">
                  <?php foreach ($task['files'] as $f): ?>
                  <div class="file-chip">
                    <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>
                    <a class="file-chip-name" href="<?= get_base_url() ?>download?file_id=<?= $f['id'] ?>" title="<?= htmlspecialchars($f['file_name']) ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars($f['file_name']) ?></a>
                    <span class="file-chip-size"><?= format_bytes($f['file_size']) ?></span>
                    <?php if ($f['uploaded_by'] == $user_id): ?>
                    <form method="POST" action="my_tasks" style="display:inline;">
                      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                      <input type="hidden" name="action"  value="delete_file">
                      <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                      <button type="button" class="btn-delete-file" onclick="openConfirmModal(this.form, 'Delete this file?')">
                        <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                      </button>
                    </form>
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="my_tasks" enctype="multipart/form-data">
                  <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                  <input type="hidden" name="action"  value="upload_file">
                  <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                  <div class="drop-zone" id="dz-<?= $task['id'] ?>">
                    <input type="file" name="task_file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip"
                           onchange="submitUpload(this, <?= $task['id'] ?>)">
                    <svg class="drop-zone-icon" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                    <div class="drop-zone-text"><span>Click to upload</span> or drag & drop</div>
                    <div class="drop-zone-hint">PDF, DOC, XLS, IMG, ZIP · Max 10MB</div>
                  </div>
                </form>
              </div>
            </td>
            <td>
              <span class="project-code-badge"><?= htmlspecialchars($task['project_code']) ?></span>
              <div style="font-size:12px;color:var(--text-dim);margin-top:3px;"><?= htmlspecialchars($task['project_title']) ?></div>
            </td>
            <td><span class="priority-badge priority-<?= $task['priority'] ?>"><?= $priority_labels[$task['priority']] ?></span></td>
            <td class="muted"><?= $task['deadline'] ? date('d M Y', strtotime($task['deadline'])) : '-' ?></td>
            <td class="muted"><?= htmlspecialchars($task['assigned_by_name']) ?></td>
            <td><span class="badge badge-<?= $task['status'] ?>"><?= $task_status_labels[$task['status']] ?></span></td>
            <td>
              <form method="POST" action="my_tasks" style="display:flex;gap:6px;align-items:center;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action"  value="update_task_status">
                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                <select name="new_status" class="status-select">
                  <option value="pending"     <?= $task['status'] === 'pending'     ? 'selected' : '' ?>>Pending</option>
                  <option value="in_progress" <?= $task['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                  <option value="completed"   <?= $task['status'] === 'completed'   ? 'selected' : '' ?>>Completed</option>
                  <option value="blocked"     <?= $task['status'] === 'blocked'     ? 'selected' : '' ?>>Blocked</option>
                </select>
                <button type="submit" class="btn-update-status">Save</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>

<div class="modal-overlay" id="confirmModal">
  <div class="modal">
    <h3>Are you sure?</h3>
    <p>This action cannot be undone.</p>
    <div class="modal-btns">
      <button type="button" class="modal-btn-confirm" id="confirmModalBtn">Confirm</button>
      <button type="button" class="modal-btn-cancel" onclick="closeConfirmModal()">Cancel</button>
    </div>
  </div>
</div>
<style>
  .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center; }
  .modal-overlay.open { display:flex; }
  .modal { background:var(--navy-card); border:1px solid var(--border-dim); border-radius:6px; padding:1.6rem; max-width:380px; width:90%; }
  .modal h3 { font-size:16px; margin-bottom:8px; }
  .modal p { font-size:13px; color:var(--text-dim); margin-bottom:1.2rem; }
  .modal-btns { display:flex; gap:10px; justify-content:flex-end; }
  .modal-btn-confirm, .modal-btn-cancel {
    border:none; border-radius:3px; padding:8px 16px; font-size:13px; font-weight:600; cursor:pointer;
  }
  .modal-btn-confirm { background: var(--red); color:white; }
  .modal-btn-cancel  { background: var(--input-bg); color: var(--text); border:1px solid var(--border-dim); }
</style>

<script>
let pendingForm = null;
function openConfirmModal(form, desc) {
  pendingForm = form;
  document.querySelector('#confirmModal p').textContent = desc;
  document.getElementById('confirmModal').classList.add('open');
}
function closeConfirmModal() {
  pendingForm = null;
  document.getElementById('confirmModal').classList.remove('open');
}
document.getElementById('confirmModalBtn').addEventListener('click', () => {
  if (pendingForm) { const f = pendingForm; closeConfirmModal(); f.submit(); }
});

document.querySelectorAll('.drop-zone').forEach(zone => {
  zone.addEventListener('dragover', e => {
    e.preventDefault();
    zone.classList.add('dragover');
  });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
  zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('dragover');
    const input = zone.querySelector('input[type="file"]');
    if (input && e.dataTransfer.files.length) {
      input.files = e.dataTransfer.files;
      submitUpload(input, zone.id.replace('dz-', ''));
    }
  });
});

function submitUpload(input, zoneId) {
  if (!input.files.length) return;
  const zone = document.getElementById('dz-' + zoneId);
  if (zone) {
    zone.classList.add('uploading');
    zone.querySelector('.drop-zone-text').innerHTML = 'Uploading…';
  }
  input.closest('form').submit();
}
</script>

<script>
  window.ASTRA_CSRF_TOKEN = "<?= generate_csrf_token() ?>";
  window.ASTRA_BASE_URL   = "<?= get_base_url() ?>";
</script>
<script src="<?= get_base_url() ?>assets/js/tour-config.js?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= get_base_url() ?>assets/js/tour.js?v=<?= ASSET_VERSION ?>"></script>

</body>
</html>
