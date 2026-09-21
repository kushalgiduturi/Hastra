<?php
// portals/emlpoyee/team_lead.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "employee");

$msg      = "";
$msg_type = "error";
$user_id  = (int)$_SESSION["user_id"];

// ── Check if this employee is a team lead on any project ─────────────────────
$lead_check = mysqli_prepare($conn,
    "SELECT pm.project_id, p.title, p.project_code, p.status AS project_status
     FROM project_members pm
     JOIN projects p ON pm.project_id = p.id
     WHERE pm.user_id = ? AND pm.project_role = 'team_lead'"
);
mysqli_stmt_bind_param($lead_check, "i", $user_id);
mysqli_stmt_execute($lead_check);
$lead_projects_result = mysqli_stmt_get_result($lead_check);
$lead_projects = [];
while ($lp = mysqli_fetch_assoc($lead_projects_result)) {
    $lead_projects[] = $lp;
}
$is_lead = count($lead_projects) > 0;

if (!$is_lead) {
    header("Location: " . get_base_url() . "portals/emlpoyee/employee_portal");
    exit();
}

// ── CREATE TASK ───────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "create_task") {
    verify_csrf_token();

    $project_id  = (int)($_POST["project_id"] ?? 0);
    $title       = trim($_POST["title"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $assigned_to = (int)($_POST["assigned_to"] ?? 0);
    $priority    = trim($_POST["priority"] ?? "medium");
    $deadline    = ($_POST["deadline"] !== "") ? $_POST["deadline"] : null;

    $allowed_priorities = ["low", "medium", "high", "critical"];

    $verify_lead = mysqli_prepare($conn,
        "SELECT id FROM project_members WHERE project_id = ? AND user_id = ? AND project_role = 'team_lead'"
    );
    mysqli_stmt_bind_param($verify_lead, "ii", $project_id, $user_id);
    mysqli_stmt_execute($verify_lead);
    mysqli_stmt_store_result($verify_lead);

    if (!$project_id || $title === "" || !$assigned_to || !in_array($priority, $allowed_priorities)) {
        $msg = "Project, title, assignee and priority are required.";
    } elseif (mysqli_stmt_num_rows($verify_lead) === 0) {
        $msg = "You are not the team lead of this project.";
    } else {
        $task_code = next_code($conn, "T");   // 26T0001, never reuses a number

        $ins = mysqli_prepare($conn,
            "INSERT INTO tasks (task_code, project_id, title, description, assigned_to, created_by, priority, deadline)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($ins, "siississ",
            $task_code, $project_id, $title, $description,
            $assigned_to, $user_id, $priority, $deadline
        );

        if (mysqli_stmt_execute($ins)) {
            $msg      = "Task <strong>$task_code</strong> created and assigned successfully.";
            $msg_type = "success";
        } else {
            $msg = "Failed to create task.";
        }
    }
}

// ── DELETE TASK ───────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "delete_task") {
    verify_csrf_token();

    $task_id = (int)($_POST["task_id"] ?? 0);

    $verify = mysqli_prepare($conn,
        "SELECT t.id FROM tasks t
         JOIN project_members pm ON pm.project_id = t.project_id
         WHERE t.id = ? AND pm.user_id = ? AND pm.project_role = 'team_lead'"
    );
    mysqli_stmt_bind_param($verify, "ii", $task_id, $user_id);
    mysqli_stmt_execute($verify);
    mysqli_stmt_store_result($verify);

    if (mysqli_stmt_num_rows($verify) === 0) {
        $msg = "Task not found or you are not the team lead.";
    } else {
        $files_q = mysqli_prepare($conn, "SELECT file_path FROM task_files WHERE task_id = ?");
        mysqli_stmt_bind_param($files_q, "i", $task_id);
        mysqli_stmt_execute($files_q);
        $files_res = mysqli_stmt_get_result($files_q);
        while ($f = mysqli_fetch_assoc($files_res)) {
            if (file_exists($f['file_path'])) unlink($f['file_path']);
        }

        $del = mysqli_prepare($conn, "DELETE FROM tasks WHERE id = ?");
        mysqli_stmt_bind_param($del, "i", $task_id);
        if (mysqli_stmt_execute($del)) {
            $msg      = "Task deleted.";
            $msg_type = "success";
        } else {
            $msg = "Failed to delete task.";
        }
    }
}

// ── UPLOAD FILE ───────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "upload_file") {
    verify_csrf_token();

    $task_id = (int)($_POST["task_id"] ?? 0);

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

// ── FETCH: lead projects with members, tasks, files, progress ─────────────────
$lead_full = [];
foreach ($lead_projects as $lp) {
    $pid = $lp['project_id'];

    $mem_q = mysqli_prepare($conn,
        "SELECT pm.id AS member_id, pm.project_role, u.id AS user_id, u.name, u.email
         FROM project_members pm
         JOIN users u ON pm.user_id = u.id
         WHERE pm.project_id = ?
         ORDER BY FIELD(pm.project_role,'team_lead','developer','tester','security_tester','debugger')"
    );
    mysqli_stmt_bind_param($mem_q, "i", $pid);
    mysqli_stmt_execute($mem_q);
    $members = astra_decrypt_user_rows(mysqli_stmt_get_result($mem_q)->fetch_all(MYSQLI_ASSOC));

    $task_q = mysqli_prepare($conn,
        "SELECT t.*, u.name AS assignee_name
         FROM tasks t
         JOIN users u ON t.assigned_to = u.id
         WHERE t.project_id = ?
         ORDER BY FIELD(t.priority,'critical','high','medium','low'), t.created_at DESC"
    );
    mysqli_stmt_bind_param($task_q, "i", $pid);
    mysqli_stmt_execute($task_q);
    $tasks_raw = mysqli_stmt_get_result($task_q)->fetch_all(MYSQLI_ASSOC);

    $tasks = [];
    foreach ($tasks_raw as $tr) {
        $tr['assignee_name'] = astra_db_decrypt($tr['assignee_name']);
        $fq = mysqli_prepare($conn,
            "SELECT tf.*, u.name AS uploader_name FROM task_files tf
             JOIN users u ON tf.uploaded_by = u.id
             WHERE tf.task_id = ? ORDER BY tf.uploaded_at DESC"
        );
        mysqli_stmt_bind_param($fq, "i", $tr['id']);
        mysqli_stmt_execute($fq);
        $files = mysqli_stmt_get_result($fq)->fetch_all(MYSQLI_ASSOC);
        foreach ($files as &$__f) $__f['uploader_name'] = astra_db_decrypt($__f['uploader_name']);
        unset($__f);
        $tr['files'] = $files;
        $tasks[] = $tr;
    }

    $total_tasks     = count($tasks);
    $completed_tasks = count(array_filter($tasks, fn($t) => $t['status'] === 'completed'));
    $progress_pct    = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;

    $lead_full[] = array_merge($lp, [
        'members'         => $members,
        'tasks'           => $tasks,
        'total_tasks'     => $total_tasks,
        'completed_tasks' => $completed_tasks,
        'progress_pct'    => $progress_pct,
    ]);
}

$priority_labels = [
    "low" => "Low", "medium" => "Medium", "high" => "High", "critical" => "Critical",
];
$task_status_labels = [
    "pending" => "Pending", "in_progress" => "In Progress",
    "completed" => "Completed", "blocked" => "Blocked",
];
$project_status_labels = [
    "team_assigned" => "Team Assigned", "development_started" => "Development Started",
    "testing" => "Testing", "deployment_pending" => "Deployment Pending", "completed" => "Completed",
];
$role_labels = [
    "team_lead" => "Team Lead", "developer" => "Developer", "tester" => "Tester",
    "security_tester" => "Security Tester", "debugger" => "Debugger",
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
<title>Team Lead · Astra</title>
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

  .badge-team_assigned       { background: rgba(59,130,246,0.1);  color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .badge-development_started { background: rgba(34,211,238,0.1);  color: var(--cyan);        border: 1px solid rgba(34,211,238,0.2); }
  .badge-testing             { background: rgba(245,158,11,0.1);  color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }
  .badge-deployment_pending  { background: rgba(var(--purple-rgb),0.1); color: var(--purple);      border: 1px solid rgba(var(--purple-rgb),0.2); }
  .badge-completed-proj      { background: rgba(34,197,94,0.1);   color: var(--green);       border: 1px solid rgba(34,197,94,0.2); }

  .priority-badge {
    display: inline-block; padding: 2px 7px; border-radius: 2px;
    font-size: 10px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.05em; text-transform: uppercase; font-weight: 600;
  }
  .priority-low      { background: rgba(148,163,184,0.08); color: var(--text-dim);    border: 1px solid rgba(148,163,184,0.15); }
  .priority-medium   { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .priority-high     { background: rgba(245,158,11,0.1);   color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }
  .priority-critical { background: rgba(var(--red-rgb),0.12);   color: var(--red);         border: 1px solid rgba(var(--red-rgb),0.3); }

  .role-badge {
    display: inline-block; padding: 2px 7px; border-radius: 2px;
    font-size: 10px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.05em; text-transform: uppercase;
  }
  .role-team_lead       { background: rgba(var(--purple-rgb),0.12); color: var(--purple);      border: 1px solid rgba(var(--purple-rgb),0.2); }
  .role-developer       { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .role-tester          { background: rgba(34,211,238,0.1);   color: var(--cyan);        border: 1px solid rgba(34,211,238,0.2); }
  .role-security_tester { background: rgba(var(--red-rgb),0.1);    color: #fca5a5;            border: 1px solid rgba(var(--red-rgb),0.2); }
  .role-debugger        { background: rgba(245,158,11,0.1);   color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }

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

  .project-block {
    background: var(--navy-deep); border: 1px solid var(--border-dim);
    border-radius: 4px; margin-bottom: 1.2rem; overflow: hidden;
  }
  .project-block-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1rem 1.4rem; border-bottom: 1px solid var(--border-dim);
    background: var(--section-header-bg); cursor: pointer; transition: background 0.15s;
  }
  .project-block-header:hover { background: var(--hover-bg); }
  .project-block-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .project-block-title { font-size: 15px; font-weight: 600; }
  .project-block-body { padding: 1.4rem; }

  .members-strip { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 1.2rem; padding-bottom: 1.2rem; border-bottom: 1px solid var(--border-dim); }
  .member-chip {
    display: flex; align-items: center; gap: 6px;
    background: rgba(255,255,255,0.03); border: 1px solid var(--border-dim);
    border-radius: 3px; padding: 5px 10px;
  }
  .member-chip-avatar {
    width: 22px; height: 22px; border-radius: 50%;
    background: rgba(var(--accent-rgb),0.12); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 700; color: var(--accent-bright); flex-shrink: 0;
  }
  .member-chip-name { font-size: 12px; font-weight: 500; }

  .create-task-form {
    background: var(--section-header-bg); border: 1px solid var(--border-dim);
    border-radius: 3px; padding: 1.2rem; margin-bottom: 1.4rem;
  }
  .create-task-title {
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 1rem;
  }
  .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
  .field { margin-bottom: 0; }
  .field label {
    display: block; font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.07em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px;
  }
  .field input[type="text"],
  .field input[type="date"],
  .field textarea,
  .field select {
    width: 100%; background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; color: var(--text); font-family: var(--font-sans);
    font-size: 13px; padding: 8px 10px; outline: none; transition: border-color 0.2s;
  }
  .field textarea { min-height: 70px; resize: vertical; }
  .field input:focus, .field textarea:focus, .field select:focus { border-color: var(--accent-bright); }
  .field input::placeholder, .field textarea::placeholder { color: var(--text-dim); }
  .field select option { background: var(--navy-card); }

  .btn-create-task {
    background: var(--accent); color: white; border: none; border-radius: 3px;
    font-family: var(--font-sans); font-size: 13px; font-weight: 600;
    letter-spacing: 0.04em; text-transform: uppercase;
    padding: 10px 22px; cursor: pointer; margin-top: 1rem;
    transition: background 0.2s, box-shadow 0.2s;
  }
  .btn-create-task:hover { background: var(--accent-bright); box-shadow: 0 0 16px rgba(var(--accent-rgb),0.3); }

  .tasks-title {
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim);
    margin-bottom: 10px; margin-top: 1.4rem;
  }
  .task-card {
    background: var(--section-header-bg); border: 1px solid var(--border-dim);
    border-radius: 3px; margin-bottom: 8px; overflow: hidden;
    transition: border-color 0.2s;
  }
  .task-card:hover { border-color: rgba(var(--accent-rgb),0.2); }
  .task-card-main {
    display: flex; align-items: flex-start; justify-content: space-between;
    padding: 10px 14px; gap: 12px;
  }
  .task-card-left  { flex: 1; min-width: 0; }
  .task-card-top   { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap; }
  .task-title      { font-size: 13px; font-weight: 600; }
  .task-meta       { font-size: 12px; color: var(--text-dim); }
  .task-card-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

  .file-section {
    border-top: 1px solid var(--border-dim);
    padding: 10px 14px;
    background: rgba(255,255,255,0.01);
  }
  .file-section-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 8px;
  }
  .file-section-label {
    font-size: 10px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim);
    display: flex; align-items: center; gap: 6px;
  }
  .file-section-label svg { width: 12px; height: 12px; fill: var(--text-dim); }

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

  .btn-delete-task {
    background: none; border: none; color: var(--text-dim);
    cursor: pointer; padding: 4px; border-radius: 3px;
    transition: color 0.2s, background 0.2s;
  }
  .btn-delete-task:hover { color: var(--red); background: rgba(var(--red-rgb),0.08); }
  .btn-delete-task svg { width: 14px; height: 14px; fill: currentColor; display: block; }

  .chevron { width: 16px; height: 16px; fill: var(--text-dim); transition: transform 0.25s; flex-shrink: 0; }
  .chevron.open { transform: rotate(180deg); }

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

  @media (max-width: 768px) {
    .form-row-2 { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<?php $nav_current = 'team_lead'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>Team Lead</h1>
    <p>Manage tasks and track progress for the projects you lead.</p>
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

  <div class="section" id="sec-team-lead">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M17 12h-5v5h5v-5zM16 1v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-1V1h-2zm3 18H5V8h14v11z"/></svg>
        My Projects — Team Lead
        <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($lead_full) ?> project<?= count($lead_full) !== 1 ? 's' : '' ?></span>
      </div>
    </div>
    <div class="section-body">

      <?php foreach ($lead_full as $proj): ?>
      <div class="project-block">

        <div class="project-block-header" onclick="toggleProject(<?= $proj['project_id'] ?>)">
          <div class="project-block-left">
            <span class="project-code-badge"><?= htmlspecialchars($proj['project_code']) ?></span>
            <span class="project-block-title"><?= htmlspecialchars($proj['title']) ?></span>
            <span class="badge badge-<?= $proj['project_status'] ?>" style="font-size:10px;">
              <?= htmlspecialchars($project_status_labels[$proj['project_status']] ?? $proj['project_status']) ?>
            </span>
          </div>
          <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:12px;color:var(--text-dim);"><?= $proj['completed_tasks'] ?>/<?= $proj['total_tasks'] ?> done</span>
            <svg class="chevron" id="chevron-<?= $proj['project_id'] ?>" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5z"/></svg>
          </div>
        </div>

        <div class="project-block-body" id="proj-body-<?= $proj['project_id'] ?>">

          <div class="progress-wrap">
            <div class="progress-header">
              <span class="progress-label">Project Progress</span>
              <span class="progress-pct" id="pct-<?= $proj['project_id'] ?>">0%</span>
            </div>
            <div class="progress-track">
              <div class="progress-fill <?= $proj['progress_pct'] >= 100 ? 'complete' : '' ?>"
                   id="bar-<?= $proj['project_id'] ?>"
                   data-target="<?= $proj['progress_pct'] ?>">
              </div>
            </div>
            <div class="progress-counts"><?= $proj['completed_tasks'] ?> of <?= $proj['total_tasks'] ?> tasks completed</div>
          </div>

          <div class="members-strip" style="margin-top:1rem;">
            <?php foreach ($proj['members'] as $m): ?>
            <div class="member-chip">
              <div class="member-chip-avatar"><?= strtoupper(substr($m['name'], 0, 1)) ?></div>
              <div>
                <div class="member-chip-name"><?= htmlspecialchars($m['name']) ?></div>
                <span class="role-badge role-<?= $m['project_role'] ?>"><?= $role_labels[$m['project_role']] ?? $m['project_role'] ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="create-task-form">
            <div class="create-task-title">Create New Task</div>
            <form method="POST" action="team_lead">
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
              <input type="hidden" name="action"     value="create_task">
              <input type="hidden" name="project_id" value="<?= $proj['project_id'] ?>">

              <div class="form-row-2">
                <div class="field">
                  <label>Task Title</label>
                  <input type="text" name="title" maxlength="200" required placeholder="e.g. Build Login Module">
                </div>
                <div class="field">
                  <label>Assign To</label>
                  <select name="assigned_to" required>
                    <option value="">— Select Member —</option>
                    <?php foreach ($proj['members'] as $m): ?>
                    <?php if ($m['user_id'] !== $user_id): ?>
                    <option value="<?= $m['user_id'] ?>"><?= htmlspecialchars($m['name']) ?> — <?= $role_labels[$m['project_role']] ?? $m['project_role'] ?></option>
                    <?php endif; ?>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="field" style="margin-bottom:1rem;">
                <label>Description <span style="text-transform:none;color:#475569;font-weight:400;">(optional)</span></label>
                <textarea name="description" placeholder="What needs to be done…"></textarea>
              </div>

              <div class="form-row-2">
                <div class="field">
                  <label>Priority</label>
                  <select name="priority">
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                    <option value="critical">Critical</option>
                  </select>
                </div>
                <div class="field">
                  <label>Deadline <span style="text-transform:none;color:#475569;font-weight:400;">(optional)</span></label>
                  <input type="date" name="deadline">
                </div>
              </div>

              <button type="submit" class="btn-create-task">Create &amp; Assign Task</button>
            </form>
          </div>

          <?php if (!empty($proj['tasks'])): ?>
          <div class="tasks-title">Tasks (<?= count($proj['tasks']) ?>)</div>
          <?php foreach ($proj['tasks'] as $task): ?>
          <div class="task-card">
            <div class="task-card-main">
              <div class="task-card-left">
                <div class="task-card-top">
                  <span class="task-code"><?= htmlspecialchars($task['task_code']) ?></span>
                  <span class="priority-badge priority-<?= $task['priority'] ?>"><?= $priority_labels[$task['priority']] ?></span>
                  <span class="badge badge-<?= $task['status'] ?>"><?= $task_status_labels[$task['status']] ?></span>
                </div>
                <div class="task-title"><?= htmlspecialchars($task['title']) ?></div>
                <?php if ($task['description']): ?>
                <div class="task-meta" style="margin-top:3px;"><?= htmlspecialchars(substr($task['description'], 0, 100)) ?><?= strlen($task['description']) > 100 ? '…' : '' ?></div>
                <?php endif; ?>
                <div class="task-meta" style="margin-top:5px;">
                  Assigned to <strong style="color:var(--text);"><?= htmlspecialchars($task['assignee_name']) ?></strong>
                  <?= $task['deadline'] ? ' · Due ' . date('d M Y', strtotime($task['deadline'])) : '' ?>
                  · Created <?= date('d M Y', strtotime($task['created_at'])) ?>
                </div>
              </div>
              <div class="task-card-right">
                <form method="POST" action="team_lead">
                  <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                  <input type="hidden" name="action"  value="delete_task">
                  <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                  <button type="button" class="btn-delete-task"
                    onclick="openConfirmModal(this.form, 'Delete task <?= htmlspecialchars($task['task_code'], ENT_QUOTES) ?>?')">
                    <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                  </button>
                </form>
              </div>
            </div>

            <div class="file-section">
              <div class="file-section-header">
                <span class="file-section-label">
                  <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>
                  Submissions (<?= count($task['files']) ?>)
                </span>
              </div>

              <?php if (!empty($task['files'])): ?>
              <div class="file-list">
                <?php foreach ($task['files'] as $f): ?>
                <div class="file-chip">
                  <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>
                  <a class="file-chip-name" href="<?= get_base_url() ?>download?file_id=<?= $f['id'] ?>" title="<?= htmlspecialchars($f['file_name']) ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars($f['file_name']) ?></a>
                  <span class="file-chip-size"><?= format_bytes($f['file_size']) ?></span>
                  <span style="font-size:10px;color:var(--text-dim);">by <?= htmlspecialchars($f['uploader_name']) ?></span>
                  <form method="POST" action="team_lead" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action"  value="delete_file">
                    <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                    <button type="button" class="btn-delete-file" onclick="openConfirmModal(this.form, 'Delete this file?')">
                      <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                    </button>
                  </form>
                </div>
                <?php endforeach; ?>
              </div>
              <?php else: ?>
              <div style="font-size:12px;color:var(--text-dim);">No files submitted yet.</div>
              <?php endif; ?>

              <form method="POST" action="team_lead" enctype="multipart/form-data" style="margin-top:8px;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action"  value="upload_file">
                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                <div class="drop-zone" id="dz-lead-<?= $task['id'] ?>">
                  <input type="file" name="task_file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip"
                         onchange="submitUpload(this, 'lead-<?= $task['id'] ?>')">
                  <svg class="drop-zone-icon" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                  <div class="drop-zone-text"><span>Upload file</span> for this task</div>
                  <div class="drop-zone-hint">PDF, DOC, XLS, IMG, ZIP · Max 10MB</div>
                </div>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
          <?php else: ?>
          <div style="text-align:center;padding:1.5rem;color:var(--text-dim);font-size:13px;border:1px dashed var(--border-dim);border-radius:3px;">
            No tasks created yet. Use the form above to create your first task.
          </div>
          <?php endif; ?>

        </div>
      </div>
      <?php endforeach; ?>

    </div>
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

function toggleProject(id) {
  const body    = document.getElementById('proj-body-' + id);
  const chevron = document.getElementById('chevron-' + id);
  const isOpen  = body.style.display !== 'none';
  body.style.display = isOpen ? 'none' : 'block';
  chevron.classList.toggle('open', !isOpen);
}

function animateProgressBars() {
  document.querySelectorAll('.progress-fill').forEach(bar => {
    const target = parseInt(bar.dataset.target) || 0;
    const pctEl  = document.getElementById(
      bar.id.replace('bar-', 'pct-')
    );
    setTimeout(() => {
      bar.style.width = target + '%';
      let current = 0;
      const step  = target / 60;
      const timer = setInterval(() => {
        current = Math.min(current + step, target);
        if (pctEl) pctEl.textContent = Math.round(current) + '%';
        if (current >= target) clearInterval(timer);
      }, 20);
    }, 300);
  });
}

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

animateProgressBars();
</script>

</body>
</html>
