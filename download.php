<?php
include __DIR__ . '/core/db.php';
secure_session_start();
if (!isset($_SESSION["user_id"])) { http_response_code(403); exit("Access denied."); }
$user_id = (int)$_SESSION["user_id"];
$role    = $_SESSION["user_role"] ?? "";
$file_id = (int)($_GET["file_id"] ?? 0);
if (!$file_id) { http_response_code(400); exit("Bad request."); }

$row = null;
// Try task files
$q = mysqli_prepare($conn,
  "SELECT tf.file_path, tf.file_name, t.project_id, t.assigned_to, r.user_id AS req_owner_id
   FROM task_files tf
   JOIN tasks t ON tf.task_id = t.id
   JOIN projects p ON t.project_id = p.id
   LEFT JOIN requirements r ON p.requirement_id = r.id
   WHERE tf.id = ?");
mysqli_stmt_bind_param($q, "i", $file_id);
mysqli_stmt_execute($q);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($q));

if (!$row) {
  // Try bug files
  $q = mysqli_prepare($conn,
    "SELECT bf.file_path, bf.file_name, b.project_id, r.user_id AS req_owner_id
     FROM bug_files bf
     JOIN bugs b ON bf.bug_id = b.id
     JOIN projects p ON b.project_id = p.id
     LEFT JOIN requirements r ON p.requirement_id = r.id
     WHERE bf.id = ?");
  mysqli_stmt_bind_param($q, "i", $file_id);
  mysqli_stmt_execute($q);
  $row = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
}
if (!$row) { http_response_code(404); exit("Not found."); }

// Access: admin/sysadmin, a member of the project, or the client who owns
// the requirement the project was created from.
$allowed = in_array($role, ["admin", "sysadmin"], true);
if (!$allowed) {
  $chk = mysqli_prepare($conn, "SELECT id FROM project_members WHERE project_id = ? AND user_id = ?");
  mysqli_stmt_bind_param($chk, "ii", $row["project_id"], $user_id);
  mysqli_stmt_execute($chk);
  mysqli_stmt_store_result($chk);
  $allowed = mysqli_stmt_num_rows($chk) > 0;
}
if (!$allowed && $role === "client") {
  $allowed = $row["req_owner_id"] !== null && (int)$row["req_owner_id"] === $user_id;
}
if (!$allowed) { http_response_code(403); exit("Access denied."); }

// Resolve the canonical path and make sure it can't escape uploads/, even
// if a file_path in the database were ever malformed or tampered with.
$uploads_base = realpath(__DIR__ . '/uploads');
if ($uploads_base === false) { http_response_code(500); exit("Server misconfiguration."); }

$real_path = realpath($row["file_path"]);
if ($real_path === false) { http_response_code(404); exit("File missing."); }

if (strncmp($real_path, $uploads_base . DIRECTORY_SEPARATOR, strlen($uploads_base) + 1) !== 0) {
  http_response_code(403); exit("Access denied.");
}

header("Content-Type: application/octet-stream");
header('Content-Disposition: attachment; filename="' . basename($row["file_name"]) . '"');
header("Content-Length: " . filesize($real_path));
readfile($real_path);
exit();