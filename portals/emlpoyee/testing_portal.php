<?php
// portals/emlpoyee/testing_portal.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "employee");

$msg      = "";
$msg_type = "error";
$user_id  = (int)$_SESSION["user_id"];

// ── Determine project memberships relevant to this user ──────────────────────
$mem_q = mysqli_prepare($conn,
    "SELECT pm.project_id, pm.project_role, p.title, p.project_code, p.status AS project_status
     FROM project_members pm
     JOIN projects p ON pm.project_id = p.id
     WHERE pm.user_id = ?"
);
mysqli_stmt_bind_param($mem_q, "i", $user_id);
mysqli_stmt_execute($mem_q);
$mem_result = mysqli_stmt_get_result($mem_q);

$projects_membership = []; // project_id => [id,title,code,status,roles[]]
while ($m = mysqli_fetch_assoc($mem_result)) {
    $pid = (int)$m['project_id'];
    if (!isset($projects_membership[$pid])) {
        $projects_membership[$pid] = [
            'id'     => $pid,
            'title'  => $m['title'],
            'code'   => $m['project_code'],
            'status' => $m['project_status'],
            'roles'  => [],
        ];
    }
    $projects_membership[$pid]['roles'][] = $m['project_role'];
}

$qa_projects   = array_filter($projects_membership, fn($p) => array_intersect($p['roles'], ['team_lead','tester','security_tester']));
$lead_projects = array_filter($projects_membership, fn($p) => in_array('team_lead', $p['roles']));
$qa_project_ids = array_keys($qa_projects);

// Developer members & tasks for each QA project (used in the report-bug form)
$project_developers = []; // pid => [ [id,name], ... ]
$project_tasks      = []; // pid => [ [id,task_code,title], ... ]

if (!empty($qa_project_ids)) {
    $ids_csv = implode(',', array_map('intval', $qa_project_ids));

    $dev_result = mysqli_query($conn,
        "SELECT pm.project_id, u.id, u.name
         FROM project_members pm
         JOIN users u ON pm.user_id = u.id
         WHERE pm.project_role = 'developer' AND pm.project_id IN ($ids_csv)"
    );
    while ($d = mysqli_fetch_assoc($dev_result)) {
        $project_developers[(int)$d['project_id']][] = ['id' => (int)$d['id'], 'name' => astra_db_decrypt($d['name'])];
    }
    foreach ($project_developers as &$__devs) {
        usort($__devs, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    }
    unset($__devs);

    $task_result = mysqli_query($conn,
        "SELECT id, project_id, task_code, title FROM tasks WHERE project_id IN ($ids_csv) ORDER BY created_at DESC"
    );
    while ($t = mysqli_fetch_assoc($task_result)) {
        $project_tasks[(int)$t['project_id']][] = ['id' => (int)$t['id'], 'task_code' => $t['task_code'], 'title' => $t['title']];
    }
}

// ── Helper: validate + store a bug evidence file ──────────────────────────────
function save_bug_file($conn, $bug_id, $uploader_id, $file, &$warning) {
    $warning  = "";
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

    if ($file['size'] > $max_size) {
        $warning = " (Attachment skipped: file too large, max 10MB.)";
        return false;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_types)) {
        $warning = " (Attachment skipped: file type not allowed.)";
        return false;
    }

    $upload_dir = __DIR__ . '/../../uploads/bugs/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $ext       = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safe_name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest      = $upload_dir . $safe_name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        $warning = " (Attachment skipped: could not save file.)";
        return false;
    }

    $orig_name = htmlspecialchars($file['name']);
    $ins = mysqli_prepare($conn,
        "INSERT INTO bug_files (bug_id, uploaded_by, file_name, file_path, file_size) VALUES (?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($ins, "iissi", $bug_id, $uploader_id, $orig_name, $dest, $file['size']);

    if (!mysqli_stmt_execute($ins)) {
        unlink($dest);
        $warning = " (Attachment skipped: failed to save file record.)";
        return false;
    }
    return true;
}

function fetch_bug_files($conn, $bug_id) {
    $fq = mysqli_prepare($conn,
        "SELECT bf.*, u.name AS uploader_name FROM bug_files bf
         JOIN users u ON bf.uploaded_by = u.id
         WHERE bf.bug_id = ? ORDER BY bf.uploaded_at DESC"
    );
    mysqli_stmt_bind_param($fq, "i", $bug_id);
    mysqli_stmt_execute($fq);
    $files = mysqli_stmt_get_result($fq)->fetch_all(MYSQLI_ASSOC);
    foreach ($files as &$f) $f['uploader_name'] = astra_db_decrypt($f['uploader_name']);
    unset($f);
    return $files;
}

function format_bytes($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// ── ACTION: report_bug ─────────────────────────────────────────────────────────
// ── ACTION: start_testing (tester/security_tester/team_lead) ─────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "start_testing") {
    verify_csrf_token();
    $pid = (int)($_POST["project_id"] ?? 0);
    if (!isset($qa_projects[$pid])) {
        $msg = "You are not a tester on this project.";
    } elseif (!in_array($qa_projects[$pid]['status'], ['team_assigned', 'development_started'], true)) {
        $msg = "Testing can only start while the project is in development.";
    } else {
        $st = mysqli_prepare($conn,
            "UPDATE projects SET status = 'testing' WHERE id = ? AND status IN ('team_assigned','development_started')");
        mysqli_stmt_bind_param($st, "i", $pid);
        mysqli_stmt_execute($st);
        if (mysqli_stmt_affected_rows($st) > 0) {
            $projects_membership[$pid]['status'] = $qa_projects[$pid]['status'] = 'testing';
            try { log_activity($conn, $user_id, "testing_started " . $qa_projects[$pid]['code'], $_SESSION["user_name"] ?? null); } catch (Throwable $e) {}
            $msg      = "Testing started for <strong>" . htmlspecialchars($qa_projects[$pid]['code']) . "</strong>.";
            $msg_type = "success";
        } else {
            $msg = "The project status changed in the meantime. Reload and try again.";
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "report_bug") {
    verify_csrf_token();

    $project_id  = (int)($_POST["project_id"] ?? 0);
    $task_id_raw = trim($_POST["task_id"] ?? "");
    $task_id     = ($task_id_raw !== "") ? (int)$task_id_raw : null;
    $title       = trim($_POST["title"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $bug_type    = trim($_POST["bug_type"] ?? "");
    $vuln_class  = trim($_POST["vuln_class"] ?? "");
    $vuln_other  = mb_substr(trim($_POST["vuln_class_other"] ?? ""), 0, 100);
    $cwe_id      = normalize_cwe($_POST["cwe_id"] ?? "");
    $severity    = trim($_POST["severity"] ?? "medium");
    $assigned_to = (int)($_POST["assigned_to"] ?? 0);

    $allowed_types      = ["functional", "ui", "performance", "security"];
    $allowed_severities = ["low", "medium", "high", "critical"];

    $verify_role = mysqli_prepare($conn,
        "SELECT id FROM project_members WHERE project_id = ? AND user_id = ? AND project_role IN ('team_lead','tester','security_tester')"
    );
    mysqli_stmt_bind_param($verify_role, "ii", $project_id, $user_id);
    mysqli_stmt_execute($verify_role);
    mysqli_stmt_store_result($verify_role);

    $verify_dev = mysqli_prepare($conn,
        "SELECT id FROM project_members WHERE project_id = ? AND user_id = ? AND project_role = 'developer'"
    );
    mysqli_stmt_bind_param($verify_dev, "ii", $project_id, $assigned_to);
    mysqli_stmt_execute($verify_dev);
    mysqli_stmt_store_result($verify_dev);

    if (!$project_id || $title === "" || $description === "" || !in_array($bug_type, $allowed_types) || !in_array($severity, $allowed_severities) || !$assigned_to) {
        $msg = "Project, title, description, bug type, severity and assignee are all required.";
    } elseif (mysqli_stmt_num_rows($verify_role) === 0) {
        $msg = "You are not authorized to report bugs on this project.";
    } elseif (mysqli_stmt_num_rows($verify_dev) === 0) {
        $msg = "Selected assignee is not a developer on this project.";
    } elseif ($bug_type === "security" && !isset(VULN_CLASSES[$vuln_class])) {
        $msg = "Please select a vulnerability class from the list for security bugs.";
    } elseif ($bug_type === "security" && $vuln_class === "Other" && $vuln_other === "") {
        $msg = "Describe the vulnerability when you choose Other.";
    } elseif ($bug_type === "security" && $cwe_id === null) {
        $msg = "CWE ID should look like CWE-89 (or leave it empty).";
    } else {
        if ($task_id) {
            $tcheck = mysqli_prepare($conn, "SELECT id FROM tasks WHERE id = ? AND project_id = ?");
            mysqli_stmt_bind_param($tcheck, "ii", $task_id, $project_id);
            mysqli_stmt_execute($tcheck);
            mysqli_stmt_store_result($tcheck);
            if (mysqli_stmt_num_rows($tcheck) === 0) $task_id = null;
        }

        $final_vuln = null;
        if ($bug_type === "security") {
            $final_vuln = ($vuln_class === "Other") ? $vuln_other : $vuln_class;
        }

        // Bug code: 26B0001 (race-free, never reuses a number)
        $bug_code = next_code($conn, "B");

        $final_cwe = ($bug_type === "security" && $cwe_id !== "") ? $cwe_id : null;
        if (bugs_have_cwe($conn)) {
            $ins = mysqli_prepare($conn,
                "INSERT INTO bugs (bug_code, project_id, task_id, title, description, bug_type, vuln_class, cwe_id, severity, reported_by, assigned_to)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($ins, "siissssssii",
                $bug_code, $project_id, $task_id, $title, $description,
                $bug_type, $final_vuln, $final_cwe, $severity, $user_id, $assigned_to
            );
        } else {
            $ins = mysqli_prepare($conn,
                "INSERT INTO bugs (bug_code, project_id, task_id, title, description, bug_type, vuln_class, severity, reported_by, assigned_to)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($ins, "siisssssii",
                $bug_code, $project_id, $task_id, $title, $description,
                $bug_type, $final_vuln, $severity, $user_id, $assigned_to
            );
        }

        if (mysqli_stmt_execute($ins)) {
            $new_bug_id = mysqli_insert_id($conn);

            // Move project into "testing" once bugs start getting logged
            $status_upd = mysqli_prepare($conn,
                "UPDATE projects SET status = 'testing' WHERE id = ? AND status IN ('team_assigned','development_started')"
            );
            mysqli_stmt_bind_param($status_upd, "i", $project_id);
            mysqli_stmt_execute($status_upd);

            // Optional evidence file attached at report time
            $upload_warning = "";
            if (isset($_FILES['bug_file']) && $_FILES['bug_file']['error'] === UPLOAD_ERR_OK) {
                save_bug_file($conn, $new_bug_id, $user_id, $_FILES['bug_file'], $upload_warning);
            }

            $msg      = "Bug <strong>$bug_code</strong> reported and assigned successfully." . $upload_warning;
            $msg_type = "success";
        } else {
            $msg = "Failed to report bug.";
        }
    }
}

// ── ACTION: update_bug_status (developer: start progress / mark fixed) ──────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "update_bug_status") {
    verify_csrf_token();

    $bug_id     = (int)($_POST["bug_id"] ?? 0);
    $new_status = trim($_POST["new_status"] ?? "");
    $allowed    = ["in_progress", "fixed"];

    $bcheck = mysqli_prepare($conn, "SELECT id, status FROM bugs WHERE id = ? AND assigned_to = ?");
    mysqli_stmt_bind_param($bcheck, "ii", $bug_id, $user_id);
    mysqli_stmt_execute($bcheck);
    $brow = mysqli_fetch_assoc(mysqli_stmt_get_result($bcheck));

    if (!$bug_id || !in_array($new_status, $allowed)) {
        $msg = "Invalid request.";
    } elseif (!$brow) {
        $msg = "Bug not found or not assigned to you.";
    } elseif (!in_array($brow['status'], ['open', 'in_progress'])) {
        $msg = "This bug can't be updated from its current state.";
    } else {
        if ($new_status === 'fixed') {
            $upd = mysqli_prepare($conn, "UPDATE bugs SET status = 'fixed', resolved_at = NOW() WHERE id = ?");
        } else {
            $upd = mysqli_prepare($conn, "UPDATE bugs SET status = 'in_progress' WHERE id = ?");
        }
        mysqli_stmt_bind_param($upd, "i", $bug_id);
        if (mysqli_stmt_execute($upd)) {
            $msg      = "Bug status updated.";
            $msg_type = "success";
        } else {
            $msg = "Failed to update bug.";
        }
    }
}

// ── ACTION: retest_bug (tester/security_tester/team_lead) ───────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "retest_bug") {
    verify_csrf_token();

    $bug_id     = (int)($_POST["bug_id"] ?? 0);
    $new_status = trim($_POST["new_status"] ?? "");
    $allowed    = ["retest", "closed", "open"];

    $bcheck = mysqli_prepare($conn, "SELECT id, bug_code, project_id, status, reported_by FROM bugs WHERE id = ?");
    mysqli_stmt_bind_param($bcheck, "i", $bug_id);
    mysqli_stmt_execute($bcheck);
    $brow = mysqli_fetch_assoc(mysqli_stmt_get_result($bcheck));

    // The tester who reported the bug retests it; the Team Lead can override.
    $authorized = false;
    $override   = false;
    if ($brow) {
        $role_q = mysqli_prepare($conn,
            "SELECT project_role FROM project_members WHERE project_id = ? AND user_id = ? AND project_role IN ('team_lead','tester','security_tester')"
        );
        mysqli_stmt_bind_param($role_q, "ii", $brow['project_id'], $user_id);
        mysqli_stmt_execute($role_q);
        $my_role     = mysqli_fetch_assoc(mysqli_stmt_get_result($role_q))['project_role'] ?? null;
        $is_reporter = (int)$brow['reported_by'] === $user_id;
        $authorized  = $my_role !== null && ($is_reporter || $my_role === 'team_lead');
        $override    = $authorized && !$is_reporter;
    }

    if (!$bug_id || !in_array($new_status, $allowed)) {
        $msg = "Invalid request.";
    } elseif (!$brow) {
        $msg = "Bug not found.";
    } elseif (!$authorized) {
        $msg = "Only the tester who reported this bug (or the Team Lead) can retest or close it.";
    } elseif (!in_array($brow['status'], ['fixed', 'retest'])) {
        $msg = "This bug isn't awaiting retest.";
    } else {
        if ($new_status === 'closed') {
            $upd = mysqli_prepare($conn, "UPDATE bugs SET status = 'closed', closed_at = NOW() WHERE id = ?");
        } elseif ($new_status === 'open') {
            $upd = mysqli_prepare($conn, "UPDATE bugs SET status = 'open', resolved_at = NULL, closed_at = NULL WHERE id = ?");
        } else {
            $upd = mysqli_prepare($conn, "UPDATE bugs SET status = 'retest' WHERE id = ?");
        }
        mysqli_stmt_bind_param($upd, "i", $bug_id);

        if (mysqli_stmt_execute($upd)) {
            $labels   = ['closed' => 'Confirmed fixed and closed.', 'open' => 'Reopened — sent back to developer.', 'retest' => 'Marked as retesting.'];
            $msg      = $labels[$new_status] . ($override ? " (Team Lead override — recorded in the activity log.)" : "");
            $msg_type = "success";
            if ($override) {
                try { log_activity($conn, $user_id, "bug_override_" . $new_status . " " . $brow['bug_code'], $_SESSION["user_name"] ?? null); } catch (Throwable $e) {}
            }
        } else {
            $msg = "Failed to update bug.";
        }
    }
}

// ── ACTION: wont_fix_bug (team lead only) ────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "wont_fix_bug") {
    verify_csrf_token();

    $bug_id = (int)($_POST["bug_id"] ?? 0);

    $bcheck = mysqli_prepare($conn, "SELECT id, project_id, status FROM bugs WHERE id = ?");
    mysqli_stmt_bind_param($bcheck, "i", $bug_id);
    mysqli_stmt_execute($bcheck);
    $brow = mysqli_fetch_assoc(mysqli_stmt_get_result($bcheck));

    $is_lead = false;
    if ($brow) {
        $lcheck = mysqli_prepare($conn, "SELECT id FROM project_members WHERE project_id = ? AND user_id = ? AND project_role = 'team_lead'");
        mysqli_stmt_bind_param($lcheck, "ii", $brow['project_id'], $user_id);
        mysqli_stmt_execute($lcheck);
        mysqli_stmt_store_result($lcheck);
        $is_lead = mysqli_stmt_num_rows($lcheck) > 0;
    }

    if (!$brow) {
        $msg = "Bug not found.";
    } elseif (!$is_lead) {
        $msg = "Only the team lead can mark a bug as Won't Fix.";
    } elseif (in_array($brow['status'], ['closed', 'wont_fix'])) {
        $msg = "This bug is already closed.";
    } else {
        $upd = mysqli_prepare($conn, "UPDATE bugs SET status = 'wont_fix', closed_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($upd, "i", $bug_id);
        if (mysqli_stmt_execute($upd)) {
            $msg      = "Bug marked as Won't Fix.";
            $msg_type = "success";
        } else {
            $msg = "Failed to update bug.";
        }
    }
}

// ── ACTION: delete_bug (team lead only) ───────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "delete_bug") {
    verify_csrf_token();

    $bug_id = (int)($_POST["bug_id"] ?? 0);

    $bcheck = mysqli_prepare($conn, "SELECT id, project_id FROM bugs WHERE id = ?");
    mysqli_stmt_bind_param($bcheck, "i", $bug_id);
    mysqli_stmt_execute($bcheck);
    $brow = mysqli_fetch_assoc(mysqli_stmt_get_result($bcheck));

    $is_lead = false;
    if ($brow) {
        $lcheck = mysqli_prepare($conn, "SELECT id FROM project_members WHERE project_id = ? AND user_id = ? AND project_role = 'team_lead'");
        mysqli_stmt_bind_param($lcheck, "ii", $brow['project_id'], $user_id);
        mysqli_stmt_execute($lcheck);
        mysqli_stmt_store_result($lcheck);
        $is_lead = mysqli_stmt_num_rows($lcheck) > 0;
    }

    if (!$brow) {
        $msg = "Bug not found.";
    } elseif (!$is_lead) {
        $msg = "Only the team lead can delete bugs.";
    } else {
        $del = mysqli_prepare($conn, "DELETE FROM bugs WHERE id = ?");
        mysqli_stmt_bind_param($del, "i", $bug_id);
        if (mysqli_stmt_execute($del)) {
            $msg      = "Bug deleted.";
            $msg_type = "success";
        } else {
            $msg = "Failed to delete bug.";
        }
    }
}

// ── ACTION: upload_bug_file ───────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "upload_bug_file") {
    verify_csrf_token();

    $bug_id = (int)($_POST["bug_id"] ?? 0);

    $bcheck = mysqli_prepare($conn, "SELECT id, project_id, reported_by, assigned_to FROM bugs WHERE id = ?");
    mysqli_stmt_bind_param($bcheck, "i", $bug_id);
    mysqli_stmt_execute($bcheck);
    $brow = mysqli_fetch_assoc(mysqli_stmt_get_result($bcheck));

    $authorized = false;
    if ($brow) {
        if ((int)$brow['reported_by'] === $user_id || (int)$brow['assigned_to'] === $user_id) {
            $authorized = true;
        } else {
            $lcheck = mysqli_prepare($conn, "SELECT id FROM project_members WHERE project_id = ? AND user_id = ? AND project_role = 'team_lead'");
            mysqli_stmt_bind_param($lcheck, "ii", $brow['project_id'], $user_id);
            mysqli_stmt_execute($lcheck);
            mysqli_stmt_store_result($lcheck);
            $authorized = mysqli_stmt_num_rows($lcheck) > 0;
        }
    }

    if (!$brow) {
        $msg = "Bug not found.";
    } elseif (!$authorized) {
        $msg = "Access denied.";
    } elseif (!isset($_FILES['bug_file']) || $_FILES['bug_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = "No file uploaded or upload error.";
    } else {
        $warning = "";
        if (save_bug_file($conn, $bug_id, $user_id, $_FILES['bug_file'], $warning)) {
            $msg      = "File uploaded successfully.";
            $msg_type = "success";
        } else {
            $msg = "Upload failed." . $warning;
        }
    }
}

// ── ACTION: delete_bug_file ───────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "delete_bug_file") {
    verify_csrf_token();

    $file_id = (int)($_POST["file_id"] ?? 0);

    $fcheck = mysqli_prepare($conn,
        "SELECT bf.*, b.project_id FROM bug_files bf
         JOIN bugs b ON bf.bug_id = b.id
         WHERE bf.id = ?"
    );
    mysqli_stmt_bind_param($fcheck, "i", $file_id);
    mysqli_stmt_execute($fcheck);
    $frow = mysqli_fetch_assoc(mysqli_stmt_get_result($fcheck));

    if (!$frow) {
        $msg = "File not found.";
    } else {
        $is_uploader = ((int)$frow['uploaded_by'] === $user_id);
        $lcheck = mysqli_prepare($conn, "SELECT id FROM project_members WHERE project_id = ? AND user_id = ? AND project_role = 'team_lead'");
        mysqli_stmt_bind_param($lcheck, "ii", $frow['project_id'], $user_id);
        mysqli_stmt_execute($lcheck);
        mysqli_stmt_store_result($lcheck);
        $is_lead = mysqli_stmt_num_rows($lcheck) > 0;

        if (!$is_uploader && !$is_lead) {
            $msg = "Access denied.";
        } else {
            if (file_exists($frow['file_path'])) unlink($frow['file_path']);
            $del = mysqli_prepare($conn, "DELETE FROM bug_files WHERE id = ?");
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

// ── FETCH: bugs assigned to me (developer queue) ──────────────────────────────
$my_fix_result = mysqli_query($conn,
    "SELECT b.*, p.title AS project_title, p.project_code, t.task_code, t.title AS task_title, u.name AS reported_by_name
     FROM bugs b
     JOIN projects p ON b.project_id = p.id
     LEFT JOIN tasks t ON b.task_id = t.id
     JOIN users u ON b.reported_by = u.id
     WHERE b.assigned_to = $user_id
     ORDER BY FIELD(b.status,'open','in_progress','retest','fixed','wont_fix','closed'),
              FIELD(b.severity,'critical','high','medium','low'), b.created_at DESC"
);
$my_fix_bugs = [];
while ($b = mysqli_fetch_assoc($my_fix_result)) {
    $b['reported_by_name'] = astra_db_decrypt($b['reported_by_name']);
    $b['files']    = fetch_bug_files($conn, $b['id']);
    $my_fix_bugs[] = $b;
}

// ── FETCH: bugs I reported ────────────────────────────────────────────────────
$my_reported_result = mysqli_query($conn,
    "SELECT b.*, p.title AS project_title, p.project_code, t.task_code, t.title AS task_title, u.name AS assigned_to_name
     FROM bugs b
     JOIN projects p ON b.project_id = p.id
     LEFT JOIN tasks t ON b.task_id = t.id
     JOIN users u ON b.assigned_to = u.id
     WHERE b.reported_by = $user_id
     ORDER BY FIELD(b.status,'fixed','retest','open','in_progress','wont_fix','closed'), b.created_at DESC"
);
$my_reported_bugs = [];
while ($b = mysqli_fetch_assoc($my_reported_result)) {
    $b['assigned_to_name'] = astra_db_decrypt($b['assigned_to_name']);
    $b['files']         = fetch_bug_files($conn, $b['id']);
    $my_reported_bugs[] = $b;
}

// ── FETCH: all bugs in projects I lead ────────────────────────────────────────
$lead_bugs = [];
if (!empty($lead_projects)) {
    $lead_ids_csv = implode(',', array_map('intval', array_keys($lead_projects)));
    $lead_bugs_result = mysqli_query($conn,
        "SELECT b.*, p.title AS project_title, p.project_code, t.task_code, t.title AS task_title,
                ur.name AS reported_by_name, ua.name AS assigned_to_name
         FROM bugs b
         JOIN projects p ON b.project_id = p.id
         LEFT JOIN tasks t ON b.task_id = t.id
         JOIN users ur ON b.reported_by = ur.id
         JOIN users ua ON b.assigned_to = ua.id
         WHERE b.project_id IN ($lead_ids_csv)
         ORDER BY p.title ASC, FIELD(b.status,'open','in_progress','retest','fixed','wont_fix','closed'),
                  FIELD(b.severity,'critical','high','medium','low'), b.created_at DESC"
    );
    while ($b = mysqli_fetch_assoc($lead_bugs_result)) {
        $b['reported_by_name'] = astra_db_decrypt($b['reported_by_name']);
        $b['assigned_to_name'] = astra_db_decrypt($b['assigned_to_name']);
        $b['files']  = fetch_bug_files($conn, $b['id']);
        $lead_bugs[] = $b;
    }
}

$to_fix_count          = count(array_filter($my_fix_bugs, fn($b) => in_array($b['status'], ['open', 'in_progress'])));
$awaiting_retest_count = count(array_filter($my_reported_bugs, fn($b) => in_array($b['status'], ['fixed', 'retest'])));
$closed_count          = count(array_filter($my_reported_bugs, fn($b) => $b['status'] === 'closed'));
$lead_open_count       = count(array_filter($lead_bugs, fn($b) => !in_array($b['status'], ['closed', 'wont_fix'])));

$status_labels   = ['open' => 'Open', 'in_progress' => 'In Progress', 'fixed' => 'Fixed', 'retest' => 'Retesting', 'closed' => 'Closed', 'wont_fix' => "Won't Fix"];
$severity_labels = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];
$type_labels     = ['functional' => 'Functional', 'ui' => 'UI', 'performance' => 'Performance', 'security' => 'Security'];

$qa_form_data = [];
foreach ($qa_projects as $pid => $info) {
    $qa_form_data[$pid] = [
        'tasks'      => $project_tasks[$pid] ?? [],
        'developers' => $project_developers[$pid] ?? [],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Testing & QA · Astra</title>
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
  .stat-card.red::before    { background: var(--red); }
  .stat-card.yellow::before { background: var(--yellow); }
  .stat-card.green::before  { background: var(--green); }
  .stat-card.purple::before { background: var(--purple); }
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
  .bugstatus-open        { background: rgba(148,163,184,0.08); color: var(--text-dim);    border: 1px solid rgba(148,163,184,0.15); }
  .bugstatus-in_progress { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .bugstatus-fixed       { background: rgba(34,211,238,0.1);   color: var(--cyan);        border: 1px solid rgba(34,211,238,0.2); }
  .bugstatus-retest      { background: rgba(var(--purple-rgb),0.1);  color: var(--purple);      border: 1px solid rgba(var(--purple-rgb),0.2); }
  .bugstatus-closed      { background: rgba(34,197,94,0.1);    color: var(--green);       border: 1px solid rgba(34,197,94,0.2); }
  .bugstatus-wont_fix    { background: rgba(var(--red-rgb),0.08);   color: #fca5a5;            border: 1px solid rgba(var(--red-rgb),0.2); }

  .sev-badge {
    display: inline-block; padding: 2px 7px; border-radius: 2px;
    font-size: 10px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.05em; text-transform: uppercase; font-weight: 600;
  }
  .sev-low      { background: rgba(148,163,184,0.08); color: var(--text-dim);    border: 1px solid rgba(148,163,184,0.15); }
  .sev-medium   { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .sev-high     { background: rgba(245,158,11,0.1);   color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }
  .sev-critical { background: rgba(var(--red-rgb),0.12);   color: var(--red);         border: 1px solid rgba(var(--red-rgb),0.3); }

  .type-badge {
    display: inline-block; padding: 2px 7px; border-radius: 2px;
    font-size: 10px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.05em; text-transform: uppercase;
    background: var(--input-bg); color: var(--text-dim); border: 1px solid var(--border-dim);
  }
  .type-security { background: rgba(var(--red-rgb),0.08); color: #fca5a5; border: 1px solid rgba(var(--red-rgb),0.2); }

  .bug-code {
    font-family: 'Share Tech Mono', monospace; font-size: 11px; font-weight: 600;
    color: var(--cyan); background: rgba(34,211,238,0.08);
    border: 1px solid rgba(34,211,238,0.2); padding: 2px 7px; border-radius: 2px; letter-spacing: 0.08em;
  }
  .project-code-badge {
    font-family: 'Share Tech Mono', monospace; font-size: 11px; font-weight: 600;
    color: var(--accent-bright); background: rgba(var(--accent-rgb),0.08);
    border: 1px solid rgba(var(--accent-rgb),0.2); padding: 2px 7px; border-radius: 2px; letter-spacing: 0.08em;
  }

  /* ── REPORT BUG FORM ── */
  .field { margin-bottom: 1rem; }
  .field label {
    display: block; font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.07em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px;
  }
  .field input[type="text"], .field textarea, .field select {
    width: 100%; background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; color: var(--text); font-family: var(--font-sans);
    font-size: 13px; padding: 9px 12px; outline: none; transition: border-color 0.2s;
  }
  .field textarea { min-height: 80px; resize: vertical; }
  .field input:focus, .field textarea:focus, .field select:focus { border-color: var(--accent-bright); }
  .field select option { background: var(--navy-card); }
  .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
  .vuln-row, .vuln-other-row { display: none; }
  .vuln-row.show, .vuln-other-row.show { display: block; }

  .btn-report {
    background: var(--red); color: white; border: none; border-radius: 3px;
    font-family: var(--font-sans); font-size: 13px; font-weight: 600;
    letter-spacing: 0.04em; text-transform: uppercase;
    padding: 10px 22px; cursor: pointer; margin-top: 0.5rem;
    transition: background 0.2s, box-shadow 0.2s;
  }
  .btn-report:hover { background: var(--red); filter: brightness(0.9); box-shadow: 0 0 16px rgba(var(--accent-rgb),0.3); }

  /* ── BUG CARDS ── */
  .bug-card {
    background: var(--navy-deep); border: 1px solid var(--border-dim);
    border-radius: 3px; padding: 12px 14px; margin-bottom: 8px;
    transition: border-color 0.2s;
  }
  .bug-card:hover { border-color: rgba(var(--accent-rgb),0.2); }
  .bug-card-top { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; flex-wrap: wrap; }
  .bug-title { font-size: 13px; font-weight: 600; margin-bottom: 4px; }
  .bug-desc  { font-size: 12px; color: var(--text-dim); line-height: 1.5; margin-bottom: 6px; }
  .bug-meta  { font-size: 11px; color: var(--text-dim); margin-bottom: 8px; }
  .bug-meta strong { color: var(--text); }
  .bug-actions { display: flex; gap: 6px; flex-wrap: wrap; }

  .action-btn {
    display: flex; align-items: center; gap: 5px;
    padding: 6px 12px; border-radius: 3px; border: 1px solid;
    font-family: var(--font-sans); font-size: 11px; font-weight: 600;
    cursor: pointer; transition: background 0.15s;
    letter-spacing: 0.03em; text-transform: uppercase;
  }
  .action-btn.start    { background: rgba(var(--accent-rgb),0.1);  border-color: rgba(var(--accent-rgb),0.3);  color: var(--accent-bright); }
  .action-btn.start:hover    { background: rgba(var(--accent-rgb),0.2); }
  .action-btn.fix      { background: rgba(34,211,238,0.1);  border-color: rgba(34,211,238,0.3);  color: var(--cyan); }
  .action-btn.fix:hover      { background: rgba(34,211,238,0.2); }
  .action-btn.close     { background: rgba(34,197,94,0.1);  border-color: rgba(34,197,94,0.3);  color: var(--green); }
  .action-btn.close:hover    { background: rgba(34,197,94,0.2); }
  .action-btn.reopen    { background: rgba(var(--red-rgb),0.08); border-color: rgba(var(--red-rgb),0.3);  color: #fca5a5; }
  .action-btn.reopen:hover   { background: rgba(var(--red-rgb),0.18); }
  .action-btn.retest    { background: rgba(var(--purple-rgb),0.1); border-color: rgba(var(--purple-rgb),0.3); color: var(--purple); }
  .action-btn.retest:hover   { background: rgba(var(--purple-rgb),0.2); }
  .action-btn.wontfix   { background: var(--input-bg); border-color: var(--border-dim); color: var(--text-dim); }
  .action-btn.wontfix:hover  { background: rgba(255,255,255,0.08); }
  .action-btn.delete    { background: rgba(var(--red-rgb),0.08); border-color: rgba(var(--red-rgb),0.3);  color: #fca5a5; }
  .action-btn.delete:hover   { background: rgba(var(--red-rgb),0.18); }

  .empty-state { text-align: center; padding: 2.5rem 1rem; color: var(--text-dim); font-size: 13px; }
  .empty-state svg { width: 32px; height: 32px; fill: var(--border-dim); margin: 0 auto 0.8rem; display: block; }

  /* ── BUG FILES ── */
  .bug-file-section { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border-dim); }
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
    padding: 10px; text-align: center; cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
    position: relative; font-size: 11px;
  }
  .drop-zone:hover, .drop-zone.dragover {
    border-color: var(--accent-bright);
    background: rgba(var(--accent-rgb),0.05);
  }
  .drop-zone input[type="file"] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
  }
  .drop-zone-text { font-size: 11px; color: var(--text-dim); }
  .drop-zone-text span { color: var(--accent-bright); }
  .drop-zone.uploading { opacity: 0.6; pointer-events: none; }

  .file-attach-field {
    border: 1px dashed var(--border-dim); border-radius: 3px;
    padding: 12px; text-align: center; cursor: pointer;
    position: relative; font-size: 12px; color: var(--text-dim);
    transition: border-color 0.2s, background 0.2s; margin-top: 0.5rem;
  }
  .file-attach-field:hover { border-color: var(--accent-bright); background: var(--hover-bg); }
  .file-attach-field input[type="file"] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
  }
  .file-attach-field span { color: var(--accent-bright); }

  @media (max-width: 768px) {
    .stats-row { grid-template-columns: 1fr 1fr; }
    .form-row-2, .form-row-3 { grid-template-columns: 1fr; }
  }

</style>
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/tour.css?v=<?= ASSET_VERSION ?>">
</head>
<body data-tour-page="emlpoyee/testing_portal">

<?php $nav_current = 'testing'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>Testing & QA</h1>
    <p>Report bugs, track fixes, and verify retests.</p>
  </div>

  <div class="section-nav">
    <?php if (!empty(array_filter($qa_projects, fn($p) => in_array($p['status'], ['team_assigned','development_started'], true)))): ?>
    <a class="section-nav-card" href="#sec-ready-to-test">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg></span>
      <span class="section-nav-label">Ready to Test</span>
    </a>
    <?php endif; ?>
    <a class="section-nav-card" href="#sec-report-bug">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M20 8h-2.81c-.45-.78-1.07-1.45-1.82-1.96L17 4.41 15.59 3l-2.17 2.17C12.96 5.06 12.49 5 12 5c-.49 0-.96.06-1.41.17L8.41 3 7 4.41l1.62 1.63C7.88 6.55 7.26 7.22 6.81 8H4v2h2.09c-.05.33-.09.66-.09 1v1H4v2h2v1c0 .34.04.67.09 1H4v2h2.81c1.04 1.79 2.97 3 5.19 3s4.15-1.21 5.19-3H20v-2h-2.09c.05-.33.09-.66.09-1v-1h2v-2h-2v-1c0-.34-.04-.67-.09-1H20V8z"/></svg></span>
      <span class="section-nav-label">Report a Bug</span>
    </a>
    <a class="section-nav-card" href="#sec-bugs-assigned">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M9 11H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm2-7h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg></span>
      <span class="section-nav-label">Bugs Assigned to Me</span>
    </a>
    <a class="section-nav-card" href="#sec-bugs-reported">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg></span>
      <span class="section-nav-label">Bugs I Reported</span>
    </a>
    <?php if (!empty($lead_projects)): ?>
    <a class="section-nav-card" href="#sec-lead-bugs">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M17 12h-5v5h5v-5zM16 1v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-1V1h-2zm3 18H5V8h14v11z"/></svg></span>
      <span class="section-nav-label">All Bugs — Team Lead</span>
    </a>
    <?php endif; ?>
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
    <div class="stat-card red">
      <div class="stat-label">Bugs To Fix</div>
      <div class="stat-value"><?= $to_fix_count ?></div>
    </div>
    <div class="stat-card yellow">
      <div class="stat-label">Awaiting Retest</div>
      <div class="stat-value"><?= $awaiting_retest_count ?></div>
    </div>
    <div class="stat-card green">
      <div class="stat-label">Closed (Verified)</div>
      <div class="stat-value"><?= $closed_count ?></div>
    </div>
    <div class="stat-card purple">
      <div class="stat-label">Open in Led Projects</div>
      <div class="stat-value"><?= $lead_open_count ?></div>
    </div>
  </div>

  <?php $to_start = array_filter($qa_projects, fn($p) => in_array($p['status'], ['team_assigned','development_started'], true)); ?>
  <?php if (!empty($to_start)): ?>
  <div class="section" id="sec-ready-to-test">
    <div class="section-header">
      <div class="section-title">Ready to test?</div>
    </div>
    <div class="section-body">
      <p style="font-size:13px;color:var(--text-dim);margin-bottom:10px;">Start testing when development is ready for QA. The team lead can request deployment only after testing has started.</p>
      <?php foreach ($to_start as $pid => $info): ?>
      <form method="POST" action="testing_portal.php" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:8px 0;border-top:1px solid var(--border-dim);">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action" value="start_testing">
        <input type="hidden" name="project_id" value="<?= (int)$pid ?>">
        <span style="font-family:'Share Tech Mono',monospace;font-size:12px;"><?= htmlspecialchars($info['code']) ?></span>
        <span style="flex:1;min-width:160px;font-size:13px;"><?= htmlspecialchars($info['title']) ?></span>
        <button type="submit" class="btn-report" style="padding:6px 14px;background:var(--accent-bright,#3b82f6);">Start testing</button>
      </form>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── REPORT A BUG ── -->
  <?php if (!empty($qa_projects)): ?>
  <div class="section" id="sec-report-bug">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M20 8h-2.81c-.45-.78-1.07-1.45-1.82-1.96L17 4.41 15.59 3l-2.17 2.17C12.96 5.06 12.49 5 12 5c-.49 0-.96.06-1.41.17L8.41 3 7 4.41l1.62 1.63C7.88 6.55 7.26 7.22 6.81 8H4v2h2.09c-.05.33-.09.66-.09 1v1H4v2h2v1c0 .34.04.67.09 1H4v2h2.81c1.04 1.79 2.97 3 5.19 3s4.15-1.21 5.19-3H20v-2h-2.09c.05-.33.09-.66.09-1v-1h2v-2h-2v-1c0-.34-.04-.67-.09-1H20V8z"/></svg>
        Report a Bug
      </div>
    </div>
    <div class="section-body">
      <form method="POST" action="testing_portal" id="bugForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action" value="report_bug">

        <div class="form-row-2">
          <div class="field">
            <label>Project</label>
            <select name="project_id" id="projectSelect" required onchange="onProjectChange()">
              <option value="">— Select Project —</option>
              <?php foreach ($qa_projects as $pid => $info): ?>
              <option value="<?= $pid ?>"><?= htmlspecialchars($info['code'] . ' — ' . $info['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Related Task <span style="text-transform:none;color:#475569;font-weight:400;">(optional)</span></label>
            <select name="task_id" id="taskSelect" disabled>
              <option value="">— Select project first —</option>
            </select>
          </div>
        </div>

        <div class="field">
          <label>Bug Title</label>
          <input type="text" name="title" maxlength="200" required placeholder="e.g. Password reset OTP not expiring">
        </div>

        <div class="field">
          <label>Description</label>
          <textarea name="description" required placeholder="Describe the bug, expected vs actual behavior, steps to reproduce…"></textarea>
        </div>

        <div class="form-row-3">
          <div class="field">
            <label>Bug Type</label>
            <select name="bug_type" id="bugTypeSelect" onchange="onBugTypeChange()">
              <option value="functional">Functional</option>
              <option value="ui">UI</option>
              <option value="performance">Performance</option>
              <option value="security">Security</option>
            </select>
          </div>
          <div class="field">
            <label>Severity</label>
            <select name="severity">
              <option value="low">Low</option>
              <option value="medium" selected>Medium</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
            </select>
          </div>
          <div class="field">
            <label>Assign To Developer</label>
            <select name="assigned_to" id="developerSelect" disabled>
              <option value="">— Select project first —</option>
            </select>
          </div>
        </div>

        <div class="field vuln-row" id="vulnRow">
          <label>Vulnerability Class</label>
          <select name="vuln_class" id="vulnClassSelect" onchange="onVulnClassChange()">
            <option value="">— Select —</option>
            <?php foreach (VULN_CLASSES as $vc_value => [$vc_label, $vc_cwe]): ?>
            <option value="<?= htmlspecialchars($vc_value) ?>" data-cwe="<?= $vc_cwe ?>"><?= htmlspecialchars($vc_label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field vuln-row" id="cweRow">
          <label>CWE ID <span style="text-transform:none;color:#475569;font-weight:400;">(filled in from the class — change it if a more specific CWE fits)</span></label>
          <input type="text" name="cwe_id" id="cweInput" maxlength="12" placeholder="CWE-89" pattern="^\s*([Cc][Ww][Ee][\s\-_]*)?\d{1,4}\s*$">
        </div>
        <div class="field vuln-other-row" id="vulnOtherRow">
          <label>Specify Vulnerability</label>
          <input type="text" name="vuln_class_other" maxlength="100" placeholder="e.g. Insecure Deserialization">
        </div>

        <div class="field">
          <label>Evidence / Screenshot <span style="text-transform:none;color:#475569;font-weight:400;">(optional)</span></label>
          <label class="file-attach-field" id="reportFileLabel">
            <input type="file" name="bug_file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip"
                   onchange="updateFileLabel(this, 'reportFileLabel')">
            <span>Click to attach a file</span> or drag & drop · Max 10MB
          </label>
        </div>

        <button type="submit" class="btn-report">Report Bug</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── BUGS ASSIGNED TO ME ── -->
  <div class="section" id="sec-bugs-assigned">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M9 11H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm2-7h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg>
        Bugs Assigned to Me
        <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($my_fix_bugs) ?> total</span>
      </div>
    </div>
    <div class="section-body">
      <?php if (empty($my_fix_bugs)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        No bugs assigned to you.
      </div>
      <?php else: foreach ($my_fix_bugs as $bug): ?>
      <div class="bug-card">
        <div class="bug-card-top">
          <span class="bug-code"><?= htmlspecialchars($bug['bug_code']) ?></span>
          <span class="badge bugstatus-<?= $bug['status'] ?>"><?= $status_labels[$bug['status']] ?></span>
          <span class="sev-badge sev-<?= $bug['severity'] ?>"><?= $severity_labels[$bug['severity']] ?></span>
          <span class="type-badge <?= $bug['bug_type'] === 'security' ? 'type-security' : '' ?>">
            <?= $type_labels[$bug['bug_type']] ?><?= $bug['vuln_class'] ? ' · ' . htmlspecialchars($bug['vuln_class']) : '' ?><?= !empty($bug['cwe_id']) ? ' · <a href="' . htmlspecialchars(cwe_link($bug['cwe_id'])) . '" target="_blank" rel="noopener noreferrer" style="color:inherit;">' . htmlspecialchars($bug['cwe_id']) . '</a>' : '' ?>
          </span>
          <span class="project-code-badge"><?= htmlspecialchars($bug['project_code']) ?></span>
        </div>
        <div class="bug-title"><?= htmlspecialchars($bug['title']) ?></div>
        <div class="bug-desc"><?= htmlspecialchars($bug['description']) ?></div>
        <div class="bug-meta">
          Reported by <strong><?= htmlspecialchars($bug['reported_by_name']) ?></strong>
          <?= $bug['task_title'] ? ' · Task: ' . htmlspecialchars($bug['task_code'] . ' — ' . $bug['task_title']) : '' ?>
          · <?= date('d M Y', strtotime($bug['created_at'])) ?>
        </div>

        <div class="bug-file-section">
          <?php if (!empty($bug['files'])): ?>
          <div class="file-list">
            <?php foreach ($bug['files'] as $f): ?>
            <div class="file-chip">
              <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>
              <a class="file-chip-name" href="<?= get_base_url() ?>download?file_id=<?= $f['id'] ?>" title="<?= htmlspecialchars($f['file_name']) ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars($f['file_name']) ?></a>
              <span class="file-chip-size"><?= format_bytes($f['file_size']) ?></span>
              <?php if ((int)$f['uploaded_by'] === $user_id): ?>
              <form method="POST" action="testing_portal" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action"  value="delete_bug_file">
                <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                <button type="submit" class="btn-delete-file" onclick="return confirm('Delete this file?')">
                  <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                </button>
              </form>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <form method="POST" action="testing_portal" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action"  value="upload_bug_file">
            <input type="hidden" name="bug_id" value="<?= $bug['id'] ?>">
            <div class="drop-zone" id="dz-fix-<?= $bug['id'] ?>">
              <input type="file" name="bug_file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip"
                     onchange="submitUpload(this, 'fix-<?= $bug['id'] ?>')">
              <div class="drop-zone-text"><span>Add evidence</span> · click or drag & drop</div>
            </div>
          </form>
        </div>

        <?php if (in_array($bug['status'], ['open', 'in_progress'])): ?>
        <div class="bug-actions">
          <?php if ($bug['status'] === 'open'): ?>
          <form method="POST" action="testing_portal">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="update_bug_status">
            <input type="hidden" name="bug_id" value="<?= $bug['id'] ?>">
            <input type="hidden" name="new_status" value="in_progress">
            <button type="submit" class="action-btn start">Start Working</button>
          </form>
          <?php endif; ?>
          <form method="POST" action="testing_portal">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="update_bug_status">
            <input type="hidden" name="bug_id" value="<?= $bug['id'] ?>">
            <input type="hidden" name="new_status" value="fixed">
            <button type="submit" class="action-btn fix">Mark Fixed</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- ── BUGS I REPORTED ── -->
  <div class="section" id="sec-bugs-reported">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>
        Bugs I Reported
        <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($my_reported_bugs) ?> total</span>
      </div>
    </div>
    <div class="section-body">
      <?php if (empty($my_reported_bugs)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        You haven't reported any bugs yet.
      </div>
      <?php else: foreach ($my_reported_bugs as $bug): ?>
      <div class="bug-card">
        <div class="bug-card-top">
          <span class="bug-code"><?= htmlspecialchars($bug['bug_code']) ?></span>
          <span class="badge bugstatus-<?= $bug['status'] ?>"><?= $status_labels[$bug['status']] ?></span>
          <span class="sev-badge sev-<?= $bug['severity'] ?>"><?= $severity_labels[$bug['severity']] ?></span>
          <span class="type-badge <?= $bug['bug_type'] === 'security' ? 'type-security' : '' ?>">
            <?= $type_labels[$bug['bug_type']] ?><?= $bug['vuln_class'] ? ' · ' . htmlspecialchars($bug['vuln_class']) : '' ?><?= !empty($bug['cwe_id']) ? ' · <a href="' . htmlspecialchars(cwe_link($bug['cwe_id'])) . '" target="_blank" rel="noopener noreferrer" style="color:inherit;">' . htmlspecialchars($bug['cwe_id']) . '</a>' : '' ?>
          </span>
          <span class="project-code-badge"><?= htmlspecialchars($bug['project_code']) ?></span>
        </div>
        <div class="bug-title"><?= htmlspecialchars($bug['title']) ?></div>
        <div class="bug-meta">
          Assigned to <strong><?= htmlspecialchars($bug['assigned_to_name']) ?></strong>
          · <?= date('d M Y', strtotime($bug['created_at'])) ?>
        </div>

        <div class="bug-file-section">
          <?php if (!empty($bug['files'])): ?>
          <div class="file-list">
            <?php foreach ($bug['files'] as $f): ?>
            <div class="file-chip">
              <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>
              <a class="file-chip-name" href="<?= get_base_url() ?>download?file_id=<?= $f['id'] ?>" title="<?= htmlspecialchars($f['file_name']) ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars($f['file_name']) ?></a>
              <span class="file-chip-size"><?= format_bytes($f['file_size']) ?></span>
              <?php if ((int)$f['uploaded_by'] === $user_id): ?>
              <form method="POST" action="testing_portal" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action"  value="delete_bug_file">
                <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                <button type="submit" class="btn-delete-file" onclick="return confirm('Delete this file?')">
                  <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                </button>
              </form>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <form method="POST" action="testing_portal" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action"  value="upload_bug_file">
            <input type="hidden" name="bug_id" value="<?= $bug['id'] ?>">
            <div class="drop-zone" id="dz-rep-<?= $bug['id'] ?>">
              <input type="file" name="bug_file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip"
                     onchange="submitUpload(this, 'rep-<?= $bug['id'] ?>')">
              <div class="drop-zone-text"><span>Add evidence</span> · click or drag & drop</div>
            </div>
          </form>
        </div>

        <?php if (in_array($bug['status'], ['fixed', 'retest'])): ?>
        <div class="bug-actions">
          <?php if ($bug['status'] === 'fixed'): ?>
          <form method="POST" action="testing_portal">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="retest_bug">
            <input type="hidden" name="bug_id" value="<?= $bug['id'] ?>">
            <input type="hidden" name="new_status" value="retest">
            <button type="submit" class="action-btn retest">Mark Retesting</button>
          </form>
          <?php endif; ?>
          <form method="POST" action="testing_portal">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="retest_bug">
            <input type="hidden" name="bug_id" value="<?= $bug['id'] ?>">
            <input type="hidden" name="new_status" value="closed">
            <button type="submit" class="action-btn close">Confirm & Close</button>
          </form>
          <form method="POST" action="testing_portal">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="retest_bug">
            <input type="hidden" name="bug_id" value="<?= $bug['id'] ?>">
            <input type="hidden" name="new_status" value="open">
            <button type="submit" class="action-btn reopen"
              onclick="return confirm('Reopen this bug and send it back to the developer?')">Reopen</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- ── TEAM LEAD: ALL BUGS IN MY PROJECTS ── -->
  <?php if (!empty($lead_projects)): ?>
  <div class="section" id="sec-lead-bugs">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M17 12h-5v5h5v-5zM16 1v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-1V1h-2zm3 18H5V8h14v11z"/></svg>
        All Bugs — My Projects (Team Lead)
        <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($lead_bugs) ?> total</span>
      </div>
    </div>
    <div class="section-body">
      <?php if (empty($lead_bugs)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        No bugs reported in your projects yet.
      </div>
      <?php else: foreach ($lead_bugs as $bug): ?>
      <div class="bug-card">
        <div class="bug-card-top">
          <span class="bug-code"><?= htmlspecialchars($bug['bug_code']) ?></span>
          <span class="badge bugstatus-<?= $bug['status'] ?>"><?= $status_labels[$bug['status']] ?></span>
          <span class="sev-badge sev-<?= $bug['severity'] ?>"><?= $severity_labels[$bug['severity']] ?></span>
          <span class="type-badge <?= $bug['bug_type'] === 'security' ? 'type-security' : '' ?>">
            <?= $type_labels[$bug['bug_type']] ?><?= $bug['vuln_class'] ? ' · ' . htmlspecialchars($bug['vuln_class']) : '' ?><?= !empty($bug['cwe_id']) ? ' · <a href="' . htmlspecialchars(cwe_link($bug['cwe_id'])) . '" target="_blank" rel="noopener noreferrer" style="color:inherit;">' . htmlspecialchars($bug['cwe_id']) . '</a>' : '' ?>
          </span>
          <span class="project-code-badge"><?= htmlspecialchars($bug['project_code']) ?></span>
        </div>
        <div class="bug-title"><?= htmlspecialchars($bug['title']) ?></div>
        <div class="bug-meta">
          Reported by <strong><?= htmlspecialchars($bug['reported_by_name']) ?></strong>
          → Assigned to <strong><?= htmlspecialchars($bug['assigned_to_name']) ?></strong>
          · <?= date('d M Y', strtotime($bug['created_at'])) ?>
        </div>

        <div class="bug-file-section">
          <?php if (!empty($bug['files'])): ?>
          <div class="file-list">
            <?php foreach ($bug['files'] as $f): ?>
            <div class="file-chip">
              <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>
              <a class="file-chip-name" href="<?= get_base_url() ?>download?file_id=<?= $f['id'] ?>" title="<?= htmlspecialchars($f['file_name']) ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars($f['file_name']) ?></a>
              <span class="file-chip-size"><?= format_bytes($f['file_size']) ?></span>
              <?php if ((int)$f['uploaded_by'] === $user_id): ?>
              <form method="POST" action="testing_portal" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action"  value="delete_bug_file">
                <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                <button type="submit" class="btn-delete-file" onclick="return confirm('Delete this file?')">
                  <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                </button>
              </form>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <form method="POST" action="testing_portal" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action"  value="upload_bug_file">
            <input type="hidden" name="bug_id" value="<?= $bug['id'] ?>">
            <div class="drop-zone" id="dz-lead-<?= $bug['id'] ?>">
              <input type="file" name="bug_file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip"
                     onchange="submitUpload(this, 'lead-<?= $bug['id'] ?>')">
              <div class="drop-zone-text"><span>Add evidence</span> · click or drag & drop</div>
            </div>
          </form>
        </div>

        <?php if (!in_array($bug['status'], ['closed', 'wont_fix'])): ?>
        <div class="bug-actions">
          <?php if (in_array($bug['status'], ['fixed', 'retest']) && (int)$bug['reported_by'] !== $user_id): ?>
          <form method="POST" action="testing_portal">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="retest_bug">
            <input type="hidden" name="bug_id" value="<?= $bug['id'] ?>">
            <input type="hidden" name="new_status" value="closed">
            <button type="submit" class="action-btn close"
              onclick="return confirm('Close this bug without the reporter\'s retest? This override is recorded in the activity log.')">Close (override)</button>
          </form>
          <?php endif; ?>
          <form method="POST" action="testing_portal">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="wont_fix_bug">
            <input type="hidden" name="bug_id" value="<?= $bug['id'] ?>">
            <button type="submit" class="action-btn wontfix"
              onclick="return confirm('Mark this bug as Won\'t Fix?')">Won't Fix</button>
          </form>
          <form method="POST" action="testing_portal">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="delete_bug">
            <input type="hidden" name="bug_id" value="<?= $bug['id'] ?>">
            <button type="submit" class="action-btn delete"
              onclick="return confirm('Delete bug <?= htmlspecialchars($bug['bug_code'], ENT_QUOTES) ?> permanently?')">Delete</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
const qaProjectsData = <?= json_encode($qa_form_data) ?>;

function onProjectChange() {
  const pid          = document.getElementById('projectSelect').value;
  const taskSelect    = document.getElementById('taskSelect');
  const developerSelect = document.getElementById('developerSelect');

  if (!pid || !qaProjectsData[pid]) {
    taskSelect.innerHTML = '<option value="">— Select project first —</option>';
    taskSelect.disabled = true;
    developerSelect.innerHTML = '<option value="">— Select project first —</option>';
    developerSelect.disabled = true;
    return;
  }

  const data = qaProjectsData[pid];

  taskSelect.innerHTML = '<option value="">— No specific task —</option>' +
    data.tasks.map(t => `<option value="${t.id}">${t.task_code} — ${t.title}</option>`).join('');
  taskSelect.disabled = false;

  if (data.developers.length === 0) {
    developerSelect.innerHTML = '<option value="">— No developers on this project —</option>';
  } else {
    developerSelect.innerHTML = '<option value="">— Select Developer —</option>' +
      data.developers.map(d => `<option value="${d.id}">${d.name}</option>`).join('');
  }
  developerSelect.disabled = false;
}

let cweAutoValue = '';
function onBugTypeChange() {
  const isSecurity = document.getElementById('bugTypeSelect').value === 'security';
  document.getElementById('vulnRow').classList.toggle('show', isSecurity);
  document.getElementById('cweRow').classList.toggle('show', isSecurity);
  document.getElementById('vulnClassSelect').required = isSecurity;
  if (!isSecurity) {
    document.getElementById('vulnOtherRow').classList.remove('show');
  }
}

function onVulnClassChange() {
  const select  = document.getElementById('vulnClassSelect');
  const isOther = select.value === 'Other';
  document.getElementById('vulnOtherRow').classList.toggle('show', isOther);
  const cwe   = document.getElementById('cweInput');
  const hint  = select.options[select.selectedIndex]?.dataset.cwe || '';
  if (cwe.value === '' || cwe.value === cweAutoValue) { cwe.value = hint; cweAutoValue = hint; }
}

function updateFileLabel(input, labelId) {
  const label = document.getElementById(labelId);
  const span  = label.querySelector('span');
  if (input.files.length) span.textContent = input.files[0].name;
}

function submitUpload(input, zoneId) {
  if (!input.files.length) return;
  const zone = document.getElementById('dz-' + zoneId);
  if (zone) {
    zone.classList.add('uploading');
    zone.querySelector('.drop-zone-text').innerHTML = 'Uploading…';
  }
  input.closest('form').submit();
}

document.querySelectorAll('.drop-zone, .file-attach-field').forEach(zone => {
  zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
  zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('dragover');
    const input = zone.querySelector('input[type="file"]');
    if (input && e.dataTransfer.files.length) {
      input.files = e.dataTransfer.files;
      input.dispatchEvent(new Event('change'));
    }
  });
});
</script>

<script>
  window.ASTRA_CSRF_TOKEN = "<?= generate_csrf_token() ?>";
  window.ASTRA_BASE_URL   = "<?= get_base_url() ?>";
</script>
<script src="<?= get_base_url() ?>assets/js/tour-config.js?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= get_base_url() ?>assets/js/tour.js?v=<?= ASSET_VERSION ?>"></script>

</body>
</html>