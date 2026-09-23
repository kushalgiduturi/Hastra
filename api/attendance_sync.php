<?php
// api/attendance_sync.php
// Automated biometric/attendance webhook. No session — authenticated purely
// by the X-Astra-Webhook-Secret header, scoped to whichever company that
// secret belongs to (see portals/admin/attendance.php to generate one).
//
//   POST /api/attendance_sync.php
//   Header: X-Astra-Webhook-Secret: <secret>
//   Body:   [{"email":"...","work_date":"YYYY-MM-DD","check_in":"HH:MM","status":"present"}, ...]
//
// A record whose date was already manually overridden by a manager is left
// alone — the API never silently clobbers a human correction.
include __DIR__ . '/../core/db.php';
header('Content-Type: application/json');

function attendance_sync_fail($code, $message) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $message]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    attendance_sync_fail(405, 'POST required.');
}

$secret = $_SERVER['HTTP_X_ASTRA_WEBHOOK_SECRET'] ?? '';
if ($secret === '') {
    attendance_sync_fail(401, 'Missing X-Astra-Webhook-Secret header.');
}

// Honeytoken trap: a decoy API key planted for reconnaissance to find. A
// match terminates the request from inside astra_canary_check() before the
// real secret lookup ever runs.
astra_canary_check($conn, 'canary_key', $secret);

$stmt = mysqli_prepare($conn, "SELECT id FROM companies WHERE attendance_webhook_secret = ?");
mysqli_stmt_bind_param($stmt, "s", $secret);
mysqli_stmt_execute($stmt);
$company = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$company) {
    attendance_sync_fail(403, 'Invalid webhook secret.');
}
$company_id = (int)$company['id'];

$raw = file_get_contents('php://input');
$records = json_decode($raw, true);
if (!is_array($records)) {
    attendance_sync_fail(400, 'Body must be a JSON array of attendance records.');
}

$allowed_status = ['present', 'absent', 'half_day', 'on_leave', 'wfh'];

$find_user = mysqli_prepare($conn,
    "SELECT id FROM users WHERE email_bindex = ? AND company_id = ? AND role = 'employee'"
);
$check_override = mysqli_prepare($conn,
    "SELECT is_overridden FROM attendance WHERE user_id = ? AND work_date = ?"
);
$upsert = mysqli_prepare($conn,
    "INSERT INTO attendance (user_id, company_id, work_date, status, check_in, source, is_overridden)
     VALUES (?, ?, ?, ?, ?, 'automated_api', 0)
     ON DUPLICATE KEY UPDATE status = VALUES(status), check_in = VALUES(check_in), source = 'automated_api'"
);

$processed = 0;
$skipped   = [];
$errors    = [];

foreach ($records as $i => $rec) {
    if (!is_array($rec)) { $errors[] = "Record $i: not an object."; continue; }

    $email     = trim($rec['email'] ?? '');
    $work_date = trim($rec['work_date'] ?? '');
    $check_in  = trim($rec['check_in'] ?? '') ?: null;
    $status    = trim($rec['status'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Record $i: invalid email."; continue;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $work_date)) {
        $errors[] = "Record $i: work_date must be YYYY-MM-DD."; continue;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $work_date));
    if (!checkdate($m, $d, $y)) {
        $errors[] = "Record $i: invalid calendar date."; continue;
    }
    if (!in_array($status, $allowed_status, true)) {
        $errors[] = "Record $i: status must be one of " . implode(', ', $allowed_status) . "."; continue;
    }
    if ($check_in !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $check_in)) {
        $errors[] = "Record $i: check_in must be HH:MM."; continue;
    }

    $email_bindex = astra_blind_index($email);
    mysqli_stmt_bind_param($find_user, "si", $email_bindex, $company_id);
    mysqli_stmt_execute($find_user);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($find_user));
    if (!$user) { $skipped[] = "Record $i: no employee with email $email in this company."; continue; }
    $uid = (int)$user['id'];

    mysqli_stmt_bind_param($check_override, "is", $uid, $work_date);
    mysqli_stmt_execute($check_override);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($check_override));
    if ($existing && (int)$existing['is_overridden'] === 1) {
        $skipped[] = "Record $i: $email on $work_date was manually overridden, so it was left as-is.";
        continue;
    }

    mysqli_stmt_bind_param($upsert, "iisss", $uid, $company_id, $work_date, $status, $check_in);
    mysqli_stmt_execute($upsert);
    $processed++;
}

echo json_encode([
    'ok'        => true,
    'processed' => $processed,
    'skipped'   => $skipped,
    'errors'    => $errors,
]);
