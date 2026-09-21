<?php
// portals/admin/project_portal.php
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

// ── Helper: send assignment email ─────────────────────────────────────────────
function send_assignment_email($to_email, $to_name, $project_code, $project_title, $role_label, $is_lead = false) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host        = MAIL_HOST;
        $mail->SMTPAuth    = MAIL_AUTH;
        $mail->Port        = MAIL_PORT;
        $mail->SMTPSecure  = MAIL_SECURE;
        $mail->SMTPAutoTLS = false;
        $mail->setFrom(MAIL_FROM, MAIL_NAME);
        $mail->addAddress($to_email);
        $mail->Subject = "Project Assignment — $project_code";
        $mail->Body    =
            "Hi $to_name,\n\n" .
            "You have been assigned to a project.\n\n" .
            "Project Code : $project_code\n" .
            "Project Title: $project_title\n" .
            "Your Role    : $role_label\n\n" .
            ($is_lead
                ? "As Team Lead, you are responsible for planning, coordination, and client communication.\n\n"
                : "Please log in to your portal to view your assigned tasks once the Team Lead creates them.\n\n"
            ) .
            "Regards,\nAstra Team";
        $mail->send();
    } catch (Exception $e) {
        // Silent fail — project still created
    }
}

// ── CREATE PROJECT ────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "create_project") {
    verify_csrf_token();

    $req_id      = (int)($_POST["requirement_id"] ?? 0);
    $title       = trim($_POST["title"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $team_lead   = (int)($_POST["team_lead"] ?? 0);

    // Member arrays: user_id => project_role
    $member_ids   = $_POST["member_ids"]   ?? [];
    $member_roles = $_POST["member_roles"] ?? [];

    $allowed_roles = ["developer","tester","security_tester","debugger"];

    if (!$req_id || $title === "" || !$team_lead) {
        $msg = "Requirement, project title and team lead are all required.";
    } else {
        // Verify requirement is approved and not already a project
        $req_check = mysqli_prepare($conn,
            "SELECT r.*, u.name AS client_name, u.email AS client_email
             FROM requirements r
             JOIN users u ON r.user_id = u.id
             WHERE r.id = ? AND r.status = 'approved'"
        );
        mysqli_stmt_bind_param($req_check, "i", $req_id);
        mysqli_stmt_execute($req_check);
        $req = mysqli_fetch_assoc(mysqli_stmt_get_result($req_check));
        if ($req) { $req['client_name'] = astra_db_decrypt($req['client_name']); $req['client_email'] = astra_db_decrypt($req['client_email']); }

        if (!$req) {
            $msg = "Requirement not found or not in approved status.";
        } else {
            $already = mysqli_prepare($conn, "SELECT id FROM projects WHERE requirement_id = ?");
            mysqli_stmt_bind_param($already, "i", $req_id);
            mysqli_stmt_execute($already);
            mysqli_stmt_store_result($already);

            if (mysqli_stmt_num_rows($already) > 0) {
                $msg = "A project already exists for this requirement.";
            } else {
                // Project code: 26P0001 (race-free, never reuses a number)
                $project_code = next_code($conn, "P");

                $admin_id = (int)$_SESSION["user_id"];

                $ins = mysqli_prepare($conn,
                    "INSERT INTO projects (project_code, requirement_id, title, description, status, created_by)
                     VALUES (?, ?, ?, ?, 'team_assigned', ?)"
                );
                mysqli_stmt_bind_param($ins, "sissi",
                    $project_code, $req_id, $title, $description, $admin_id
                );

                if (!mysqli_stmt_execute($ins)) {
                    $msg = "Failed to create project.";
                } else {
                    $project_id = mysqli_insert_id($conn);

                    $assigned_user_ids = []; // tracks who already holds a role on this project
                    $duplicate_skips    = []; // names skipped for trying to hold a 2nd role

                    // Insert team lead
                    $lead_ins = mysqli_prepare($conn,
                        "INSERT INTO project_members (project_id, user_id, project_role, assigned_by)
                         VALUES (?, ?, 'team_lead', ?)"
                    );
                    mysqli_stmt_bind_param($lead_ins, "iii", $project_id, $team_lead, $admin_id);
                    mysqli_stmt_execute($lead_ins);
                    $assigned_user_ids[] = $team_lead;

                    // Fetch team lead info for email
                    $lead_info = mysqli_prepare($conn, "SELECT name, email FROM users WHERE id = ?");
                    mysqli_stmt_bind_param($lead_info, "i", $team_lead);
                    mysqli_stmt_execute($lead_info);
                    $lead_data = mysqli_fetch_assoc(mysqli_stmt_get_result($lead_info));
                    astra_decrypt_user_row($lead_data);
                    if ($lead_data) {
                        send_assignment_email(
                            $lead_data["email"], $lead_data["name"],
                            $project_code, $title, "Team Lead", true
                        );
                    }

                    // Insert other members
                    $role_labels = [
                        "developer"       => "Developer",
                        "tester"          => "Tester",
                        "security_tester" => "Security Tester",
                        "debugger"        => "Debugger",
                    ];

                    foreach ($member_ids as $idx => $uid) {
                        $uid  = (int)$uid;
                        $role = trim($member_roles[$idx] ?? "");
                        if (!$uid || !in_array($role, $allowed_roles)) continue;

                        // One person = one role per project. Skip if already assigned
                        // (whether as team lead or an earlier row in this same form).
                        if (in_array($uid, $assigned_user_ids)) {
                            $name_q = mysqli_prepare($conn, "SELECT name FROM users WHERE id = ?");
                            mysqli_stmt_bind_param($name_q, "i", $uid);
                            mysqli_stmt_execute($name_q);
                            $name_row = mysqli_fetch_assoc(mysqli_stmt_get_result($name_q));
                            astra_decrypt_user_row($name_row);
                            $duplicate_skips[] = $name_row['name'] ?? "User #$uid";
                            continue;
                        }

                        $mem_ins = mysqli_prepare($conn,
                            "INSERT IGNORE INTO project_members (project_id, user_id, project_role, assigned_by)
                             VALUES (?, ?, ?, ?)"
                        );
                        mysqli_stmt_bind_param($mem_ins, "iisi", $project_id, $uid, $role, $admin_id);
                        mysqli_stmt_execute($mem_ins);
                        $assigned_user_ids[] = $uid;

                        // Email each member
                        $mem_info = mysqli_prepare($conn, "SELECT name, email FROM users WHERE id = ?");
                        mysqli_stmt_bind_param($mem_info, "i", $uid);
                        mysqli_stmt_execute($mem_info);
                        $mem_data = mysqli_fetch_assoc(mysqli_stmt_get_result($mem_info));
                        astra_decrypt_user_row($mem_data);
                        if ($mem_data) {
                            send_assignment_email(
                                $mem_data["email"], $mem_data["name"],
                                $project_code, $title,
                                $role_labels[$role] ?? $role
                            );
                        }
                    }
                    // Mark requirement as team_assigned (add to status enum if needed)
                    mysqli_prepare($conn, "UPDATE requirements SET status = 'approved' WHERE id = ?");

                    $msg = "Project <strong>$project_code</strong> created successfully with team assigned.";
                    if (!empty($duplicate_skips)) {
                        $msg .= " Skipped duplicate role for: <strong>" . htmlspecialchars(implode(', ', $duplicate_skips)) . "</strong> — one person can only hold one role per project.";
                    }
                    $msg_type = "success";
                }
            }
        }
    }
}

// ── REMOVE MEMBER ─────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "remove_member") {
    verify_csrf_token();
    $member_id = (int)($_POST["member_id"] ?? 0);
    if ($member_id) {
        $del = mysqli_prepare($conn, "DELETE FROM project_members WHERE id = ? AND project_role != 'team_lead'");
        mysqli_stmt_bind_param($del, "i", $member_id);
        mysqli_stmt_execute($del);
        $msg      = "Member removed.";
        $msg_type = "success";
    }
}

// ── ADD MEMBER TO EXISTING PROJECT ───────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "add_member") {
    verify_csrf_token();
    $project_id = (int)($_POST["project_id"] ?? 0);
    $uid        = (int)($_POST["user_id"] ?? 0);
    $role       = trim($_POST["project_role"] ?? "");
    $allowed    = ["developer","tester","security_tester","debugger"];

    if (!$project_id || !$uid || !in_array($role, $allowed)) {
        $msg = "Invalid member data.";
    } else {
        // One person = one role per project. Check for ANY existing membership first
        // (including team_lead), regardless of which role is being requested now.
        $existing_check = mysqli_prepare($conn,
            "SELECT pm.project_role, u.name FROM project_members pm
             JOIN users u ON pm.user_id = u.id
             WHERE pm.project_id = ? AND pm.user_id = ?"
        );
        mysqli_stmt_bind_param($existing_check, "ii", $project_id, $uid);
        mysqli_stmt_execute($existing_check);
        $existing_row = mysqli_fetch_assoc(mysqli_stmt_get_result($existing_check));
        astra_decrypt_user_row($existing_row);

        if ($existing_row) {
            $role_labels_check = [
                "team_lead"       => "Team Lead",
                "developer"       => "Developer",
                "tester"          => "Tester",
                "security_tester" => "Security Tester",
                "debugger"        => "Debugger",
            ];
            $existing_role_label = $role_labels_check[$existing_row['project_role']] ?? $existing_row['project_role'];
            $msg = htmlspecialchars($existing_row['name']) . " is already on this project as <strong>$existing_role_label</strong>. One person can only hold one role per project.";
        } else {
            $admin_id = (int)$_SESSION["user_id"];
            $add = mysqli_prepare($conn,
                "INSERT IGNORE INTO project_members (project_id, user_id, project_role, assigned_by)
                 VALUES (?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($add, "iisi", $project_id, $uid, $role, $admin_id);

            if (mysqli_stmt_execute($add) && mysqli_affected_rows($conn) > 0) {
                // Fetch project + member info for email
                $pinfo = mysqli_prepare($conn, "SELECT project_code, title FROM projects WHERE id = ?");
                mysqli_stmt_bind_param($pinfo, "i", $project_id);
                mysqli_stmt_execute($pinfo);
                $pdata = mysqli_fetch_assoc(mysqli_stmt_get_result($pinfo));

                $minfo = mysqli_prepare($conn, "SELECT name, email FROM users WHERE id = ?");
                mysqli_stmt_bind_param($minfo, "i", $uid);
                mysqli_stmt_execute($minfo);
                $mdata = mysqli_fetch_assoc(mysqli_stmt_get_result($minfo));
                astra_decrypt_user_row($mdata);

                $role_labels = [
                    "developer"       => "Developer",
                    "tester"          => "Tester",
                    "security_tester" => "Security Tester",
                    "debugger"        => "Debugger",
                ];

                if ($pdata && $mdata) {
                    send_assignment_email(
                        $mdata["email"], $mdata["name"],
                        $pdata["project_code"], $pdata["title"],
                        $role_labels[$role] ?? $role
                    );
                }
                $msg      = "Member added successfully.";
                $msg_type = "success";
            } else {
                $msg = "Insert failed. Please try again.";
            }
        }
    }
}

// ── FETCH DATA ────────────────────────────────────────────────────────────────

// Approved requirements without a project yet
$open_reqs_result = mysqli_query($conn,
    "SELECT r.*, u.name AS client_name
     FROM requirements r
     JOIN users u ON r.user_id = u.id
     WHERE r.status = 'approved'
       AND r.id NOT IN (SELECT requirement_id FROM projects)
     ORDER BY r.created_at DESC"
);
$open_reqs = [];
while ($r = mysqli_fetch_assoc($open_reqs_result)) {
    $r['client_name'] = astra_db_decrypt($r['client_name']); // u.name AS client_name — not caught by astra_decrypt_user_row()'s literal 'name' key
    $open_reqs[] = $r;
}

// All employees (for dropdowns)
$emp_result = mysqli_query($conn,
    "SELECT id, name, email FROM users WHERE role = 'employee'"
);
$employees = [];
while ($e = mysqli_fetch_assoc($emp_result)) $employees[] = $e;
$employees = astra_decrypt_user_rows($employees);
usort($employees, fn($a, $b) => strcasecmp($a['name'], $b['name']));

// All existing projects with members
$proj_result = mysqli_query($conn,
    "SELECT p.*,
            r.requirement_id AS req_code, r.project_title AS req_project,
            u.name AS created_by_name
     FROM projects p
     JOIN requirements r ON p.requirement_id = r.id
     JOIN users u ON p.created_by = u.id
     ORDER BY p.created_at DESC"
);
$projects = [];
while ($p = mysqli_fetch_assoc($proj_result)) {
    // Fetch members for this project
    $mem_q = mysqli_prepare($conn,
        "SELECT pm.id AS member_id, pm.project_role, pm.assigned_at,
                u.id AS user_id, u.name, u.email
         FROM project_members pm
         JOIN users u ON pm.user_id = u.id
         WHERE pm.project_id = ?
         ORDER BY FIELD(pm.project_role,'team_lead','developer','tester','security_tester','debugger')"
    );
    mysqli_stmt_bind_param($mem_q, "i", $p["id"]);
    mysqli_stmt_execute($mem_q);
    $p["members"] = astra_decrypt_user_rows(mysqli_stmt_get_result($mem_q)->fetch_all(MYSQLI_ASSOC));
    $p["created_by_name"] = astra_db_decrypt($p["created_by_name"]);
    $projects[] = $p;
}

$role_labels = [
    "team_lead"       => "Team Lead",
    "developer"       => "Developer",
    "tester"          => "Tester",
    "security_tester" => "Security Tester",
    "debugger"        => "Debugger",
];

$status_labels = [
    "team_assigned"        => "Team Assigned",
    "development_started"  => "Development Started",
    "testing"              => "Testing",
    "deployment_pending"   => "Deployment Pending",
    "completed"            => "Completed",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Project Portal · Astra</title>
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

  /* ── TOPNAV ── */
  /* ── LAYOUT ── */
  .main { max-width: 1200px; margin: 0 auto; padding: 2rem; }
  .page-header { margin-bottom: 2rem; }
  .page-header h1 { font-size: 24px; font-weight: 600; letter-spacing: -0.01em; margin-bottom: 4px; }
  .page-header p  { font-size: 13px; color: var(--text-dim); }

  /* ── ALERT ── */
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

  /* ── SECTION ── */
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

  /* ── OPEN REQUIREMENTS ── */
  .req-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1rem;
  }
  .req-card {
    background: var(--navy-deep); border: 1px solid var(--border-dim);
    border-radius: 3px; padding: 1rem 1.2rem; cursor: pointer;
    transition: border-color 0.2s, box-shadow 0.2s;
    position: relative;
  }
  .req-card:hover { border-color: var(--accent-bright); box-shadow: 0 0 12px rgba(var(--accent-rgb),0.1); }
  .req-card.selected {
    border-color: var(--accent-bright);
    box-shadow: 0 0 0 2px rgba(var(--accent-rgb),0.25), 0 0 16px rgba(var(--accent-rgb),0.15);
    background: rgba(var(--accent-rgb),0.06);
  }
  .req-card-id {
    font-family: 'Share Tech Mono', monospace; font-size: 11px; font-weight: 600;
    color: var(--accent-bright); letter-spacing: 0.08em; margin-bottom: 5px;
  }
  .req-card-title { font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 3px; }
  .req-card-sub   { font-size: 12px; color: var(--text-dim); margin-bottom: 8px; }
  .req-card-client {
    font-size: 11px; color: var(--text-dim);
    display: flex; align-items: center; gap: 5px;
  }
  .req-card-client svg { width: 11px; height: 11px; fill: var(--text-dim); }
  .check-mark {
    position: absolute; top: 10px; right: 10px;
    width: 20px; height: 20px; border-radius: 50%;
    background: var(--accent); display: none;
    align-items: center; justify-content: center;
  }
  .req-card.selected .check-mark { display: flex; }
  .check-mark svg { width: 12px; height: 12px; fill: white; }

  .empty-cards {
    text-align: center; padding: 2.5rem 1rem; color: var(--text-dim); font-size: 13px;
  }
  .empty-cards svg { width: 32px; height: 32px; fill: var(--border-dim); margin: 0 auto 0.8rem; display: block; }

  /* ── CREATE FORM ── */
  .create-form { display: none; }
  .create-form.open { display: block; }

  .form-divider {
    height: 1px; background: var(--border-dim); margin: 1.4rem 0;
  }
  .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .field { margin-bottom: 1rem; }
  .field label {
    display: block; font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.07em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px;
  }
  .field input[type="text"],
  .field textarea,
  .field select {
    width: 100%; background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; color: var(--text); font-family: var(--font-sans);
    font-size: 13px; padding: 9px 12px; outline: none; transition: border-color 0.2s;
  }
  .field textarea   { min-height: 80px; resize: vertical; }
  .field input:focus, .field textarea:focus, .field select:focus { border-color: var(--accent-bright); }
  .field input::placeholder, .field textarea::placeholder { color: var(--text-dim); }
  .field select option { background: var(--navy-card); }

  /* ── MEMBERS BUILDER ── */
  .members-title {
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim);
    margin-bottom: 10px; margin-top: 1.2rem;
  }

  .member-row {
    display: grid; grid-template-columns: 1fr 180px 36px; gap: 8px;
    align-items: center; margin-bottom: 8px;
  }
  .member-row select {
    background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; color: var(--text); font-family: var(--font-sans);
    font-size: 13px; padding: 8px 10px; outline: none; width: 100%;
    transition: border-color 0.2s;
  }
  .member-row select:focus { border-color: var(--accent-bright); }
  .member-row select option { background: var(--navy-card); }

  .btn-remove-row {
    width: 36px; height: 36px; background: rgba(var(--red-rgb),0.08);
    border: 1px solid rgba(var(--red-rgb),0.2); border-radius: 3px;
    color: #fca5a5; cursor: pointer; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; transition: background 0.2s;
  }
  .btn-remove-row:hover { background: rgba(var(--red-rgb),0.18); }
  .btn-remove-row svg { width: 14px; height: 14px; fill: #fca5a5; }

  .btn-add-member {
    display: flex; align-items: center; gap: 6px;
    background: rgba(var(--accent-rgb),0.08); border: 1px solid var(--border);
    color: var(--accent-bright); font-family: var(--font-sans);
    font-size: 12px; font-weight: 500; padding: 7px 14px; border-radius: 3px;
    cursor: pointer; margin-top: 4px; transition: background 0.2s;
  }
  .btn-add-member:hover { background: rgba(var(--accent-rgb),0.15); }
  .btn-add-member svg { width: 14px; height: 14px; fill: var(--accent-bright); }

  .btn-create-project {
    background: var(--accent); color: white; border: none; border-radius: 3px;
    font-family: var(--font-sans); font-size: 14px; font-weight: 600;
    letter-spacing: 0.04em; text-transform: uppercase;
    padding: 11px 28px; cursor: pointer; margin-top: 1rem;
    transition: background 0.2s, box-shadow 0.2s;
  }
  .btn-create-project:hover { background: var(--accent-dim); box-shadow: 0 0 20px rgba(var(--accent-rgb),0.3); }

  /* ── PROJECT CARDS ── */
  .project-card {
    background: var(--navy-deep); border: 1px solid var(--border-dim);
    border-radius: 4px; overflow: hidden; margin-bottom: 1rem;
    transition: border-color 0.2s;
  }
  .project-card:hover { border-color: rgba(var(--accent-rgb),0.15); }

  .project-card-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    padding: 1.1rem 1.4rem; border-bottom: 1px solid var(--border-dim);
    gap: 12px;
  }
  .project-code {
    font-family: 'Share Tech Mono', monospace; font-size: 12px; font-weight: 600;
    color: var(--cyan); background: rgba(34,211,238,0.08);
    border: 1px solid rgba(34,211,238,0.2); padding: 2px 8px; border-radius: 2px;
    letter-spacing: 0.08em; margin-bottom: 5px; display: inline-block;
  }
  .project-title { font-size: 16px; font-weight: 600; margin-bottom: 3px; }
  .project-req-ref { font-size: 12px; color: var(--text-dim); }

  /* ── BADGES ── */
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

  .role-badge {
    display: inline-block; padding: 2px 7px; border-radius: 2px;
    font-size: 10px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.05em; text-transform: uppercase; font-weight: 500;
  }
  .role-team_lead       { background: rgba(var(--purple-rgb),0.12); color: var(--purple);      border: 1px solid rgba(var(--purple-rgb),0.2); }
  .role-developer       { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .role-tester          { background: rgba(34,211,238,0.1);   color: var(--cyan);        border: 1px solid rgba(34,211,238,0.2); }
  .role-security_tester { background: rgba(var(--red-rgb),0.1);    color: #fca5a5;            border: 1px solid rgba(var(--red-rgb),0.2); }
  .role-debugger        { background: rgba(245,158,11,0.1);   color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }

  /* ── TEAM TABLE ── */
  .team-section { padding: 1rem 1.4rem; }
  .team-title {
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 10px;
  }
  .team-grid { display: flex; flex-direction: column; gap: 6px; }
  .team-member {
    display: flex; align-items: center; justify-content: space-between;
    background: var(--section-header-bg); border: 1px solid var(--border-dim);
    border-radius: 3px; padding: 8px 12px;
  }
  .team-member-left { display: flex; align-items: center; gap: 10px; }
  .member-avatar {
    width: 28px; height: 28px; border-radius: 50%;
    background: rgba(var(--accent-rgb),0.12); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 600; color: var(--accent-bright);
    flex-shrink: 0;
  }
  .member-name  { font-size: 13px; font-weight: 500; }
  .member-email { font-size: 11px; color: var(--text-dim); font-family: 'Share Tech Mono', monospace; }

  .btn-remove-member {
    background: none; border: none; color: var(--text-dim);
    cursor: pointer; padding: 4px; border-radius: 3px;
    transition: color 0.2s, background 0.2s;
  }
  .btn-remove-member:hover { color: var(--red); background: rgba(var(--red-rgb),0.08); }
  .btn-remove-member svg { width: 14px; height: 14px; fill: currentColor; display: block; }

  /* ── ADD MEMBER INLINE ── */
  .add-member-row {
    display: none; grid-template-columns: 1fr 160px 80px; gap: 8px;
    align-items: center; margin-top: 8px;
  }
  .add-member-row.open { display: grid; }
  .add-member-row select {
    background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; color: var(--text); font-family: var(--font-sans);
    font-size: 12px; padding: 7px 10px; outline: none; width: 100%;
  }
  .add-member-row select:focus { border-color: var(--accent-bright); }
  .add-member-row select option { background: var(--navy-card); }

  .btn-toggle-add {
    display: flex; align-items: center; gap: 5px;
    background: none; border: 1px dashed var(--border-dim);
    color: var(--text-dim); font-family: var(--font-sans);
    font-size: 12px; padding: 7px 12px; border-radius: 3px;
    cursor: pointer; margin-top: 8px; width: 100%; justify-content: center;
    transition: border-color 0.2s, color 0.2s;
  }
  .btn-toggle-add:hover { border-color: var(--accent-bright); color: var(--accent-bright); }
  .btn-toggle-add svg { width: 13px; height: 13px; fill: currentColor; }

  .btn-confirm-add {
    background: var(--accent); color: white; border: none; border-radius: 3px;
    font-size: 12px; font-weight: 600; padding: 7px 14px; cursor: pointer;
    font-family: var(--font-sans); text-transform: uppercase; letter-spacing: 0.04em;
    transition: background 0.2s;
  }
  .btn-confirm-add:hover { background: var(--accent-dim); }

  .project-meta {
    font-size: 12px; color: var(--text-dim);
    display: flex; align-items: center; gap: 4px;
  }

  .empty-state {
    text-align: center; padding: 3rem; color: var(--text-dim); font-size: 13px;
  }
  .empty-state svg { width: 36px; height: 36px; fill: var(--border-dim); margin: 0 auto 0.8rem; display: block; }

  @media (max-width: 768px) {
    .form-row   { grid-template-columns: 1fr; }
    .member-row { grid-template-columns: 1fr 1fr 36px; }
    .add-member-row { grid-template-columns: 1fr 1fr; }
  }
</style>
</head>
<body>

<?php $nav_current = 'projects'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>Project Management</h1>
    <p>Create projects from approved requirements and assign teams.</p>
  </div>

  <div class="section-nav">
    <a class="section-nav-card" href="#sec-create-project">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg></span>
      <span class="section-nav-label">Create New Project</span>
    </a>
    <a class="section-nav-card" href="#sec-all-projects">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg></span>
      <span class="section-nav-label">All Projects</span>
    </a>
  </div>

  <?php if ($msg): ?>
  <div class="alert <?= $msg_type ?>">
    <?php if ($msg_type === "success"): ?>
      <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
    <?php else: ?>
      <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
    <?php endif; ?>
    <span><?= $msg ?></span>
  </div>
  <?php endif; ?>

  <!-- ── CREATE PROJECT ── -->
  <div class="section" id="sec-create-project">
    <div class="section-header">
      <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg>
      Create New Project
      <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($open_reqs) ?> approved requirement<?= count($open_reqs) !== 1 ? 's' : '' ?> awaiting project</span>
    </div>
    <div class="section-body">

      <?php if (empty($open_reqs)): ?>
      <div class="empty-cards">
        <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        All approved requirements already have projects. Approve more requirements in the Admin Portal.
      </div>
      <?php else: ?>

      <!-- Step 1: Pick requirement -->
      <p style="font-size:12px;color:var(--text-dim);margin-bottom:12px;">
        <span style="font-family:'Share Tech Mono',monospace;color:var(--accent-bright);">STEP 1</span>
        &nbsp;Select an approved requirement to base this project on
      </p>
      <div class="req-grid" id="reqGrid">
        <?php foreach ($open_reqs as $req): ?>
        <div class="req-card" id="reqCard_<?= $req['id'] ?>"
             onclick="selectReq(<?= $req['id'] ?>, '<?= htmlspecialchars($req['project_title'], ENT_QUOTES) ?>', '<?= htmlspecialchars($req['requirement_id'], ENT_QUOTES) ?>')">
          <div class="check-mark">
            <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/></svg>
          </div>
          <div class="req-card-id"><?= htmlspecialchars($req['requirement_id']) ?></div>
          <div class="req-card-title"><?= htmlspecialchars($req['project_title']) ?></div>
          <div class="req-card-sub"><?= htmlspecialchars($req['requirement_title']) ?></div>
          <div class="req-card-client">
            <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
            <?= htmlspecialchars($req['client_name']) ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Step 2: Fill project details (shown after selecting a req) -->
      <div class="create-form" id="createForm">
        <div class="form-divider"></div>
        <p style="font-size:12px;color:var(--text-dim);margin-bottom:12px;">
          <span style="font-family:'Share Tech Mono',monospace;color:var(--accent-bright);">STEP 2</span>
          &nbsp;Fill in project details and assign the team
        </p>

        <form method="POST" action="project_portal" id="projectForm">
          <input type="hidden" name="csrf_token"     value="<?= generate_csrf_token() ?>">
          <input type="hidden" name="action"         value="create_project">
          <input type="hidden" name="requirement_id" id="selectedReqId" value="">

          <div class="form-row">
            <div class="field">
              <label>Project Title</label>
              <input type="text" name="title" id="projectTitle" maxlength="200" required
                     placeholder="e.g. LearnEasy Platform">
            </div>
            <div class="field">
              <label>Team Lead</label>
              <select name="team_lead" required>
                <option value="">— Select Team Lead —</option>
                <?php foreach ($employees as $emp): ?>
                <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?> &lt;<?= htmlspecialchars($emp['email']) ?>&gt;</option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="field">
            <label>Project Description <span style="text-transform:none;color:#475569;font-weight:400;">(optional)</span></label>
            <textarea name="description" placeholder="Internal notes about this project's scope, goals, or constraints…"></textarea>
          </div>

          <!-- Dynamic members builder -->
          <div class="members-title">Team Members</div>
          <div id="membersContainer"></div>

          <button type="button" class="btn-add-member" onclick="addMemberRow()">
            <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Add Member
          </button>

          <br>
          <button type="submit" class="btn-create-project">Create Project &amp; Assign Team</button>
        </form>
      </div>

      <?php endif; ?>
    </div>
  </div>

  <!-- ── EXISTING PROJECTS ── -->
  <div class="section" id="sec-all-projects">
    <div class="section-header">
      <svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
      All Projects
      <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($projects) ?> total</span>
    </div>
    <div class="section-body">

      <?php if (empty($projects)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
        No projects created yet.
      </div>
      <?php else: ?>

      <?php foreach ($projects as $proj): ?>
      <div class="project-card">

        <!-- Header -->
        <div class="project-card-header">
          <div>
            <div class="project-code"><?= htmlspecialchars($proj['project_code']) ?></div>
            <div class="project-title"><?= htmlspecialchars($proj['title']) ?></div>
            <div class="project-req-ref">
              Based on requirement <strong><?= htmlspecialchars($proj['req_code']) ?></strong>
              — <?= htmlspecialchars($proj['req_project']) ?>
            </div>
          </div>
          <div style="text-align:right;flex-shrink:0;">
            <span class="badge badge-<?= htmlspecialchars($proj['status']) ?>">
              <?= htmlspecialchars($status_labels[$proj['status']] ?? $proj['status']) ?>
            </span>
            <div class="project-meta" style="margin-top:6px;justify-content:flex-end;">
              Created <?= date('d M Y', strtotime($proj['created_at'])) ?>
              by <?= htmlspecialchars($proj['created_by_name']) ?>
            </div>
          </div>
        </div>

        <!-- Team -->
        <div class="team-section">
          <div class="team-title">Team (<?= count($proj['members']) ?> member<?= count($proj['members']) !== 1 ? 's' : '' ?>)</div>
          <div class="team-grid">
            <?php foreach ($proj['members'] as $member): ?>
            <div class="team-member">
              <div class="team-member-left">
                <div class="member-avatar"><?= strtoupper(substr($member['name'], 0, 1)) ?></div>
                <div>
                  <div class="member-name"><?= htmlspecialchars($member['name']) ?></div>
                  <div class="member-email"><?= htmlspecialchars($member['email']) ?></div>
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:8px;">
                <span class="role-badge role-<?= htmlspecialchars($member['project_role']) ?>">
                  <?= htmlspecialchars($role_labels[$member['project_role']] ?? $member['project_role']) ?>
                </span>
                <?php if ($member['project_role'] !== 'team_lead'): ?>
                <form method="POST" action="project_portal" style="display:inline;">
                  <input type="hidden" name="csrf_token"  value="<?= generate_csrf_token() ?>">
                  <input type="hidden" name="action"      value="remove_member">
                  <input type="hidden" name="member_id"   value="<?= $member['member_id'] ?>">
                  <button type="submit" class="btn-remove-member" title="Remove member"
                    onclick="return confirm('Remove <?= htmlspecialchars($member['name'], ENT_QUOTES) ?> from this project?')">
                    <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                  </button>
                </form>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Add member to existing project -->
          <button type="button" class="btn-toggle-add" onclick="toggleAddMember(<?= $proj['id'] ?>)">
            <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Add Member
          </button>
          <form method="POST" action="project_portal">
            <div class="add-member-row" id="addRow_<?= $proj['id'] ?>">
              <input type="hidden" name="csrf_token"  value="<?= generate_csrf_token() ?>">
              <input type="hidden" name="action"      value="add_member">
              <input type="hidden" name="project_id"  value="<?= $proj['id'] ?>">
              <select name="user_id" required>
                <option value="">— Select Employee —</option>
                <?php
                $already_in_project = array_column($proj['members'], 'user_id');
                foreach ($employees as $emp):
                  if (in_array($emp['id'], $already_in_project)) continue;
                ?>
                <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <select name="project_role" required>
                <option value="">— Role —</option>
                <option value="developer">Developer</option>
                <option value="tester">Tester</option>
                <option value="security_tester">Security Tester</option>
                <option value="debugger">Debugger</option>
              </select>
              <button type="submit" class="btn-confirm-add">Add</button>
            </div>
          </form>
        </div>

      </div>
      <?php endforeach; ?>

      <?php endif; ?>
    </div>
  </div>

</div>

<script>
// ── Employee data for dropdowns ───────────────────────────────────────────────
const employees = <?= json_encode(array_map(fn($e) => [
    'id'    => $e['id'],
    'name'  => $e['name'],
    'email' => $e['email'],
], $employees)) ?>;

// ── Select requirement card ───────────────────────────────────────────────────
function selectReq(id, title, reqCode) {
  document.querySelectorAll('.req-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('reqCard_' + id).classList.add('selected');
  document.getElementById('selectedReqId').value = id;
  document.getElementById('projectTitle').value  = title;
  document.getElementById('createForm').classList.add('open');
  document.getElementById('createForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Dynamic member rows ───────────────────────────────────────────────────────
let memberCount = 0;

function addMemberRow() {
  const idx       = memberCount++;
  const container = document.getElementById('membersContainer');

  const usedIds = new Set();
  const leadVal = document.querySelector('[name="team_lead"]')?.value;
  if (leadVal) usedIds.add(parseInt(leadVal));
  document.querySelectorAll('#membersContainer select[name="member_ids[]"]').forEach(s => {
    if (s.value) usedIds.add(parseInt(s.value));
  });

  const empOptions = employees
    .filter(e => !usedIds.has(e.id))
    .map(e => `<option value="${e.id}">${e.name} &lt;${e.email}&gt;</option>`)
    .join('');

  const row = document.createElement('div');
  row.className = 'member-row';
  row.id = 'mrow_' + idx;
  row.innerHTML = `
    <select name="member_ids[]" required>
      <option value="">— Select Employee —</option>
      ${empOptions}
    </select>
    <select name="member_roles[]" required>
      <option value="">— Role —</option>
      <option value="developer">Developer</option>
      <option value="tester">Tester</option>
      <option value="security_tester">Security Tester</option>
      <option value="debugger">Debugger</option>
    </select>
    <button type="button" class="btn-remove-row" onclick="removeMemberRow('mrow_${idx}')">
      <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
    </button>
  `;
  container.appendChild(row);
}

function removeMemberRow(id) {
  document.getElementById(id)?.remove();
}

// ── Toggle add member row on existing projects ────────────────────────────────
function toggleAddMember(projectId) {
  const row = document.getElementById('addRow_' + projectId);
  row.classList.toggle('open');
}

document.addEventListener('change', function(e) {
  if (e.target && e.target.name === 'team_lead') {
    document.querySelectorAll('#membersContainer .member-row').forEach(row => {
      const sel = row.querySelector('select[name="member_ids[]"]');
      if (sel && sel.value === e.target.value) {
        row.remove();
      }
    });
  }
});
</script>

</body>
</html>