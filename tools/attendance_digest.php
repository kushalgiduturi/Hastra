<?php
// Astra — daily attendance digest for cron / Windows Task Scheduler.
//
// Summarizes one day's attendance for the internal company (present, absent,
// half-day, on-leave, WFH, and anyone with no record at all for that date),
// using the same `attendance` data portals/admin/attendance.php shows.
//
// Usage:
//   C:\xampp\php\php.exe tools\attendance_digest.php [--date=YYYY-MM-DD] [--email]
//
// Without --date, summarizes yesterday (the natural "morning after" digest).
// With --email, sends the digest to every role=admin user instead of (in
// addition to) printing it — see astra_send_mail() in core/onboarding.php.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require __DIR__ . '/../core/db.php';

$date = date('Y-m-d', strtotime('-1 day'));
$send_email = false;
foreach ($argv as $arg) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) $date = $m[1];
    if ($arg === '--email') $send_email = true;
}

$company = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, company_name FROM companies WHERE is_internal = 1 LIMIT 1"));
if (!$company) {
    fwrite(STDERR, "No internal company found, so there is nothing to summarize.\n");
    exit(1);
}
$company_id = (int)$company['id'];

$emp_stmt = mysqli_prepare($conn, "SELECT id, name FROM users WHERE role = 'employee' AND company_id = ?");
mysqli_stmt_bind_param($emp_stmt, "i", $company_id);
mysqli_stmt_execute($emp_stmt);
$employees = astra_decrypt_user_rows(mysqli_fetch_all(mysqli_stmt_get_result($emp_stmt), MYSQLI_ASSOC));
usort($employees, fn($a, $b) => strcasecmp($a['name'], $b['name']));

$att_stmt = mysqli_prepare($conn, "SELECT user_id, status FROM attendance WHERE company_id = ? AND work_date = ?");
mysqli_stmt_bind_param($att_stmt, "is", $company_id, $date);
mysqli_stmt_execute($att_stmt);
$att_by_user = [];
foreach (mysqli_fetch_all(mysqli_stmt_get_result($att_stmt), MYSQLI_ASSOC) as $row) {
    $att_by_user[(int)$row['user_id']] = $row['status'];
}

$labels = ['present' => 'Present', 'absent' => 'Absent', 'half_day' => 'Half day', 'on_leave' => 'On leave', 'wfh' => 'WFH', 'unmarked' => 'Unmarked'];
$buckets = array_fill_keys(array_keys($labels), []);

foreach ($employees as $emp) {
    $status = $att_by_user[(int)$emp['id']] ?? 'unmarked';
    $buckets[$status][] = $emp['name'];
}

$lines = [];
$lines[] = "Attendance digest: {$company['company_name']}, $date";
$lines[] = str_repeat('-', 40);
foreach ($labels as $key => $label) {
    $names = $buckets[$key];
    $lines[] = sprintf("%-10s (%d): %s", $label, count($names), $names ? implode(', ', $names) : '-');
}
$lines[] = str_repeat('-', 40);
$lines[] = sprintf("Total employees: %d", count($employees));
$report = implode("\n", $lines);

echo $report . "\n";

if ($send_email) {
    $admins = astra_decrypt_user_rows(mysqli_fetch_all(mysqli_query($conn, "SELECT email FROM users WHERE role = 'admin'"), MYSQLI_ASSOC));
    $sent = 0;
    foreach ($admins as $a) {
        try {
            astra_send_mail($a['email'], "Attendance digest: $date", nl2br(htmlspecialchars($report)));
            $sent++;
        } catch (Throwable $e) {
            fwrite(STDERR, "Failed to email {$a['email']}: " . $e->getMessage() . "\n");
        }
    }
    echo "\nEmailed to $sent admin(s).\n";
}
