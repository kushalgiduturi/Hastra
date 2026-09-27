<?php
// portals/admin/attendance.php — manual attendance matrix + webhook settings
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "admin");

$admin_id = (int)$_SESSION["user_id"];
$company  = get_internal_company($conn);
$company_id = $company["id"] ?? null;

$msg      = "";
$msg_type = "error";

$status_labels = [
    'present'  => 'Present',
    'absent'   => 'Absent',
    'half_day' => 'Half-day',
    'on_leave' => 'On Leave',
    'wfh'      => 'Work From Home',
];
$status_letters = ['present' => 'P', 'absent' => 'A', 'half_day' => 'H', 'on_leave' => 'L', 'wfh' => 'W'];

// ── ACTION: save_attendance (batch grid save) ─────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_attendance") {
    verify_csrf_token();

    $month_param = $_POST["month"] ?? date("Y-m");
    $records     = $_POST["records"] ?? []; // [user_id][day] = status|''

    $upsert = mysqli_prepare($conn,
        "INSERT INTO attendance (user_id, company_id, work_date, status, source, is_overridden, overridden_by)
         VALUES (?, ?, ?, ?, 'manual_manager', 1, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), source = 'manual_manager',
                                  is_overridden = 1, overridden_by = VALUES(overridden_by)"
    );
    $delete = mysqli_prepare($conn, "DELETE FROM attendance WHERE user_id = ? AND work_date = ?");

    $saved = 0;
    if (preg_match('/^\d{4}-\d{2}$/', $month_param) && is_array($records)) {
        foreach ($records as $uid => $days) {
            $uid = (int)$uid;
            if (!$uid || !is_array($days)) continue;
            foreach ($days as $day => $status) {
                $day = (int)$day;
                if ($day < 1 || $day > 31) continue;
                $status = in_array($status, ['present', 'absent', 'half_day', 'on_leave', 'wfh'], true) ? $status : '';
                $work_date = sprintf('%s-%02d', $month_param, $day);
                if (!checkdate((int)substr($month_param, 5, 2), $day, (int)substr($month_param, 0, 4))) continue;

                if ($status === '') {
                    mysqli_stmt_bind_param($delete, "is", $uid, $work_date);
                    mysqli_stmt_execute($delete);
                } else {
                    mysqli_stmt_bind_param($upsert, "iissi", $uid, $company_id, $work_date, $status, $admin_id);
                    mysqli_stmt_execute($upsert);
                    $saved++;
                }
            }
        }
        $msg      = "Attendance saved ($saved marked day" . ($saved === 1 ? '' : 's') . ").";
        $msg_type = "success";
    } else {
        $msg = "Invalid submission.";
    }
}

// ── ACTION: generate_webhook_secret ───────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "generate_webhook_secret" && $company_id) {
    verify_csrf_token();
    $secret = bin2hex(random_bytes(24));
    $upd = mysqli_prepare($conn, "UPDATE companies SET attendance_webhook_secret = ? WHERE id = ?");
    mysqli_stmt_bind_param($upd, "si", $secret, $company_id);
    mysqli_stmt_execute($upd);
    $company["attendance_webhook_secret"] = $secret;
    $msg      = "New webhook secret generated. Copy it now. It won't be shown again in full.";
    $msg_type = "success";
}

// Header synonyms for the attendance import — separate from onboarding.php's
// roster mapper since the column set is different (email/date/check-in/status).
function attendance_map_headers(array $header) {
    $syn = [
        'email'     => ['email', 'e mail', 'email address', 'mail', 'work email', 'employee email'],
        'work_date' => ['date', 'work date', 'attendance date', 'day'],
        'check_in'  => ['check in', 'checkin', 'time in', 'clock in', 'in time'],
        'status'    => ['status', 'attendance', 'attendance status', 'present absent'],
    ];
    $map = [];
    foreach ($header as $idx => $cell) {
        $h = trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string)$cell)));
        if ($h === '') continue;
        foreach ($syn as $field => $words) {
            if (isset($map[$field])) continue;
            if (in_array($h, $words, true)) { $map[$field] = $idx; continue 2; }
        }
        foreach ($syn as $field => $words) {
            if (isset($map[$field])) continue;
            foreach ($words as $w) {
                if (strlen($w) > 3 && strpos($h, $w) !== false) { $map[$field] = $idx; continue 3; }
            }
        }
    }
    return $map;
}

function attendance_normalize_status($raw) {
    $h = trim(preg_replace('/[^a-z0-9]+/', '', strtolower((string)$raw)));
    $map = [
        'present' => 'present', 'p' => 'present', 'yes' => 'present', 'y' => 'present', 'present1' => 'present',
        'absent' => 'absent', 'a' => 'absent', 'no' => 'absent', 'n' => 'absent',
        'halfday' => 'half_day', 'half' => 'half_day', 'h' => 'half_day',
        'onleave' => 'on_leave', 'leave' => 'on_leave', 'l' => 'on_leave',
        'wfh' => 'wfh', 'workfromhome' => 'wfh', 'remote' => 'wfh', 'home' => 'wfh', 'w' => 'wfh',
    ];
    return $map[$h] ?? null;
}

function attendance_normalize_date($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        [$y, $m, $d] = array_map('intval', explode('-', $raw));
        return checkdate($m, $d, $y) ? $raw : null;
    }
    if (is_numeric($raw)) { // Excel serial date
        return gmdate('Y-m-d', ((int)$raw - 25569) * 86400);
    }
    foreach (['d/m/Y', 'm/d/Y', 'd-m-Y', 'Y/m/d'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $raw);
        if ($d && $d->format($fmt) === $raw) return $d->format('Y-m-d');
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : null;
}

// ── ACTION: import_attendance (CSV/Excel upload) ──────────────────────────────
$import_summary = null;
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "import_attendance") {
    verify_csrf_token();

    if (empty($_FILES['attendance_file']['tmp_name']) || $_FILES['attendance_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = "Please choose a CSV or Excel file to upload.";
    } else {
        $ext = strtolower(pathinfo($_FILES['attendance_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            $msg = "Only .csv or .xlsx files are supported.";
        } elseif ($_FILES['attendance_file']['size'] > 5 * 1024 * 1024) {
            $msg = "File too large. Maximum size is 5MB.";
        } else {
            $rows = null;
            try {
                $rows = $ext === 'csv'
                    ? php_read_csv($_FILES['attendance_file']['tmp_name'])
                    : php_read_xlsx($_FILES['attendance_file']['tmp_name']);
            } catch (Throwable $e) {
                $msg = "Couldn't read the file: " . $e->getMessage();
            }

            if (is_array($rows)) {
                $numbered = [];
                foreach ($rows as $i => $r) {
                    if (array_filter($r, fn($c) => trim((string)$c) !== '')) $numbered[] = [$i + 1, $r];
                }
                if (!$numbered) {
                    $msg = "The file is empty.";
                } else {
                    $best = -1; $h = 0; $map = [];
                    foreach (array_slice($numbered, 0, 15) as $i => [, $r]) {
                        $m = attendance_map_headers($r);
                        $score = count($m) + (isset($m['email']) ? 2 : 0) + (isset($m['status']) ? 1 : 0);
                        if ($score > $best) { $best = $score; $h = $i; $map = $m; }
                    }

                    if (!isset($map['email']) || !isset($map['status'])) {
                        $msg = 'Couldn\'t find both an Email column and a Status column. Add headers like "Email" and "Status".';
                    } elseif (!isset($map['work_date'])) {
                        $msg = 'Couldn\'t find a Date column. Add a header such as "Work Date".';
                    } else {
                        $find_user = mysqli_prepare($conn, "SELECT id FROM users WHERE email_bindex = ? AND company_id = ? AND role = 'employee'");
                        $check_ov  = mysqli_prepare($conn, "SELECT is_overridden FROM attendance WHERE user_id = ? AND work_date = ?");
                        $upsert2   = mysqli_prepare($conn,
                            "INSERT INTO attendance (user_id, company_id, work_date, status, check_in, source, is_overridden)
                             VALUES (?, ?, ?, ?, ?, 'excel_import', 0)
                             ON DUPLICATE KEY UPDATE status = VALUES(status), check_in = VALUES(check_in), source = 'excel_import'");

                        $processed = 0; $skipped = []; $errors = [];
                        $data_rows = array_slice($numbered, $h + 1);
                        if (count($data_rows) > ROSTER_MAX_ROWS) {
                            $errors[] = "Only the first " . ROSTER_MAX_ROWS . " rows were processed.";
                            $data_rows = array_slice($data_rows, 0, ROSTER_MAX_ROWS);
                        }

                        foreach ($data_rows as [$line, $r]) {
                            $cell = fn($f) => isset($map[$f]) ? trim((string)($r[$map[$f]] ?? '')) : '';
                            $email        = strtolower($cell('email'));
                            $date_raw     = $cell('work_date');
                            $status_raw   = $cell('status');
                            $check_in_raw = $cell('check_in');

                            if ($email === '' && $date_raw === '' && $status_raw === '') continue;

                            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "Row $line: invalid email."; continue; }
                            $work_date = attendance_normalize_date($date_raw);
                            if (!$work_date) { $errors[] = "Row $line: unrecognized date \"$date_raw\"."; continue; }
                            $status = attendance_normalize_status($status_raw);
                            if (!$status) { $errors[] = "Row $line: unrecognized status \"$status_raw\"."; continue; }
                            $check_in = ($check_in_raw !== '' && preg_match('/^\d{1,2}:\d{2}/', $check_in_raw)) ? substr($check_in_raw, 0, 5) : null;

                            $email_bindex = astra_blind_index($email);
                            mysqli_stmt_bind_param($find_user, "si", $email_bindex, $company_id);
                            mysqli_stmt_execute($find_user);
                            $u = mysqli_fetch_assoc(mysqli_stmt_get_result($find_user));
                            if (!$u) { $skipped[] = "Row $line: no employee with email $email."; continue; }
                            $uid = (int)$u['id'];

                            mysqli_stmt_bind_param($check_ov, "is", $uid, $work_date);
                            mysqli_stmt_execute($check_ov);
                            $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($check_ov));
                            if ($existing && (int)$existing['is_overridden'] === 1) {
                                $skipped[] = "Row $line: $email on $work_date was manually overridden, so it was left as-is.";
                                continue;
                            }

                            mysqli_stmt_bind_param($upsert2, "iisss", $uid, $company_id, $work_date, $status, $check_in);
                            mysqli_stmt_execute($upsert2);
                            $processed++;
                        }

                        $import_summary = ['processed' => $processed, 'skipped' => $skipped, 'errors' => $errors];
                        $msg      = "$processed attendance record(s) imported.";
                        $msg_type = $processed > 0 ? 'success' : 'error';
                    }
                }
            }
        }
    }
}

// ── Month navigation ───────────────────────────────────────────────────────────
$month = $_GET["month"] ?? date("Y-m");
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date("Y-m");
$month_ts   = strtotime($month . "-01");
$days_in    = (int)date("t", $month_ts);
$prev_month = date("Y-m", strtotime("-1 month", $month_ts));
$next_month = date("Y-m", strtotime("+1 month", $month_ts));

// ── Employees + their attendance for this month ───────────────────────────────
$employees = mysqli_fetch_all(mysqli_query($conn,
    "SELECT id, name FROM users WHERE role = 'employee'"
), MYSQLI_ASSOC);
// name is encrypted — decrypt before sorting, since SQL's ORDER BY can no
// longer sort it (ciphertext order is meaningless).
$employees = astra_decrypt_user_rows($employees);
usort($employees, fn($a, $b) => strcasecmp($a['name'], $b['name']));

$att_map = []; // [user_id][day] = status
if ($employees) {
    $ids = implode(',', array_map('intval', array_column($employees, 'id')));
    $att_res = mysqli_query($conn,
        "SELECT user_id, work_date, status FROM attendance
         WHERE user_id IN ($ids) AND work_date >= '$month-01' AND work_date < DATE_ADD('$month-01', INTERVAL 1 MONTH)"
    );
    while ($r = mysqli_fetch_assoc($att_res)) {
        $day = (int)date("j", strtotime($r["work_date"]));
        $att_map[$r["user_id"]][$day] = $r["status"];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance · Hastra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-authkit.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/calendar-authkit.css?v=<?= ASSET_VERSION ?>">
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

  .main { max-width: 1300px; margin: 0 auto; padding: 2rem; }
  .page-header { margin-bottom: 2rem; display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
  .page-header h1 { font-size: 24px; font-weight: 600; letter-spacing: -0.01em; margin-bottom: 4px; }
  .page-header p { font-size: 13px; color: var(--text-dim); }

  .month-nav { display: flex; align-items: center; gap: 10px; }
  .month-nav a { display: flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 4px; background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text); text-decoration: none; }
  .month-nav a:hover { background: var(--hover-bg); }
  .month-nav a svg { width: 14px; height: 14px; fill: currentColor; }

  /* Jump-to-day calendar popover */
  .month-nav { position: relative; }
  .cal-toggle {
    display: flex; align-items: center; gap: 6px; height: 30px; padding: 0 10px;
    border-radius: 4px; background: var(--input-bg); border: 1px solid var(--border-dim);
    color: var(--text); font: inherit; font-size: 12px; cursor: pointer;
  }
  .cal-toggle:hover, .cal-toggle[aria-expanded="true"] { background: var(--hover-bg); }
  .cal-toggle svg { width: 14px; height: 14px; fill: currentColor; }
  .cal-popover { position: absolute; top: calc(100% + 8px); right: 0; z-index: 50; }
  .cal-popover[hidden] { display: none; }
  .cal-popover .hastra-calendar-root { box-shadow: var(--shadow-modal, 0 20px 50px rgba(0,0,0,0.5)); }
  /* Column picked in the calendar */
  table.grid .day-focus { background: rgba(var(--accent-rgb), 0.14); }
  table.grid th.day-focus { color: #fff; background: var(--color-void-violet, var(--accent)); }
  .month-label { font-family: 'Share Tech Mono', monospace; font-size: 13px; letter-spacing: 0.05em; min-width: 110px; text-align: center; }

  .section { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; overflow: hidden; margin-bottom: 1.5rem; }
  .section-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.4rem; border-bottom: 1px solid var(--border-dim); background: var(--section-header-bg); flex-wrap: wrap; gap: 10px; }
  .section-title { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; }
  .section-title svg { width: 15px; height: 15px; fill: var(--accent-bright); }
  .section-body { padding: 1.2rem 1.4rem; }

  .alert { display: flex; align-items: flex-start; gap: 8px; border-radius: 3px; padding: 10px 14px; margin-bottom: 1.2rem; font-size: 13px; border-left: 3px solid; line-height: 1.5; }
  .alert.success { background: var(--green-bg); border-color: var(--green); color: #86efac; }
  .alert.error   { background: var(--red-bg);   border-color: var(--red);   color: #fca5a5; }

  .legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px; color: var(--text-dim); padding: 0 1.4rem 1rem; }
  .legend span { display: inline-flex; align-items: center; gap: 5px; }
  .legend .dot { width: 10px; height: 10px; border-radius: 2px; }

  .tbl-wrap { overflow-x: auto; }
  table.grid { border-collapse: collapse; font-size: 12px; white-space: nowrap; }
  table.grid th, table.grid td { border: 1px solid var(--border-dim); padding: 0; text-align: center; }
  table.grid th { background: var(--section-header-bg); font-size: 10.5px; font-family: 'Share Tech Mono', monospace; color: var(--text-dim); padding: 6px 4px; }
  table.grid th.emp-col, table.grid td.emp-col { text-align: left; padding: 6px 12px; position: sticky; left: 0; background: var(--navy-card); font-weight: 500; min-width: 140px; z-index: 1; }
  table.grid th.emp-col { background: var(--section-header-bg); z-index: 2; }

  .cell-btn {
    width: 30px; height: 30px; border: none; background: transparent; cursor: pointer;
    font-family: 'Share Tech Mono', monospace; font-size: 11px; font-weight: 700; color: var(--text-dim);
    transition: background 0.12s;
  }
  .cell-btn:hover { background: var(--hover-bg); }
  .cell-btn.present  { background: rgba(34,197,94,0.18);  color: var(--green); }
  .cell-btn.absent   { background: rgba(var(--red-rgb),0.18);  color: var(--red); }
  .cell-btn.half_day { background: rgba(245,158,11,0.18); color: var(--yellow); }
  .cell-btn.on_leave { background: rgba(59,130,246,0.18); color: var(--blue-bright); }
  .cell-btn.wfh      { background: rgba(var(--purple-rgb),0.18); color: var(--purple); }

  .btn-save-attendance {
    background: var(--accent); color: #fff; border: none; border-radius: 3px;
    font-family: var(--font-sans); font-size: 12px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase;
    padding: 8px 18px; cursor: pointer; transition: background 0.2s;
  }
  .btn-save-attendance:hover { background: var(--accent-dim); }

  .webhook-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .webhook-secret {
    font-family: 'Share Tech Mono', monospace; font-size: 12px; background: var(--input-bg);
    border: 1px solid var(--border-dim); border-radius: 3px; padding: 7px 10px; color: var(--text);
    flex: 1; min-width: 200px; overflow-x: auto;
  }
  .btn-gen-secret {
    background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text-dim);
    font-family: var(--font-sans); font-size: 12px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase;
    padding: 8px 14px; border-radius: 3px; cursor: pointer; transition: background 0.2s;
  }
  .btn-gen-secret:hover { background: var(--hover-bg); color: var(--text); }
  .webhook-hint { font-size: 12px; color: var(--text-dim); margin-top: 10px; line-height: 1.6; }
  .webhook-hint code { font-family: 'Share Tech Mono', monospace; background: var(--input-bg); padding: 1px 5px; border-radius: 2px; }

  .empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-dim); font-size: 13px; }

  .dropzone {
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
    border: 2px dashed var(--border-dim); border-radius: 5px; padding: 2rem 1rem;
    text-align: center; cursor: pointer; transition: border-color 0.2s, background 0.2s;
  }
  .dropzone svg { width: 26px; height: 26px; fill: var(--text-dim); }
  .dropzone span { font-size: 13px; color: var(--text-dim); }
  .dropzone:hover, .dropzone.dragover { border-color: var(--accent-bright); background: var(--hover-bg); }
  .dropzone.dragover span { color: var(--accent-bright); }

  .import-results { margin-top: 1rem; display: flex; flex-direction: column; gap: 10px; }
  .import-list { font-size: 12px; line-height: 1.7; border-radius: 4px; padding: 10px 12px; max-height: 200px; overflow-y: auto; }
  .import-list strong { display: block; font-size: 10.5px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.07em; text-transform: uppercase; margin-bottom: 4px; }
  .import-list.errors  { background: var(--red-bg); border: 1px solid rgba(var(--red-rgb),0.2); color: #fca5a5; }
  .import-list.skipped { background: var(--yellow-bg); border: 1px solid rgba(245,158,11,0.2); color: #fcd34d; }

  .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; padding: 1rem; }
  .modal-overlay.open { display: flex; }
  .modal { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 6px; padding: 1.6rem; max-width: 380px; width: 100%; }
  .modal h3 { font-size: 15px; font-weight: 600; margin-bottom: 8px; }
  .modal p { font-size: 13px; color: var(--text-dim); line-height: 1.5; }
  .modal-btns { display: flex; gap: 10px; margin-top: 1.2rem; }
  .modal-btn-confirm { flex: 1; background: var(--red); color: #fff; border: none; border-radius: 3px; font-family: var(--font-sans); font-size: 13px; font-weight: 600; padding: 9px; cursor: pointer; }
  .modal-btn-cancel { background: var(--input-bg); color: var(--text-dim); border: 1px solid var(--border-dim); border-radius: 3px; font-family: var(--font-sans); font-size: 13px; font-weight: 600; padding: 9px 16px; cursor: pointer; }
</style>
</head>
<body>

<?php $nav_current = 'attendance'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <div>
      <h1>Attendance</h1>
      <p>Mark or override daily attendance for employees.</p>
    </div>
    <div class="month-nav">
      <a href="?month=<?= $prev_month ?>" title="Previous month"><svg viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg></a>
      <span class="month-label"><?= date("F Y", $month_ts) ?></span>
      <a href="?month=<?= $next_month ?>" title="Next month"><svg viewBox="0 0 24 24"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg></a>
      <button type="button" class="cal-toggle" id="calToggle" aria-expanded="false" aria-controls="calPopover">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg>
        Jump to day
      </button>
      <div class="cal-popover" id="calPopover" hidden>
        <div class="hastra-calendar-root" id="attendanceCalendar"></div>
      </div>
    </div>
  </div>

  <?php if ($msg): ?>
  <div class="alert <?= $msg_type ?>"><span><?= htmlspecialchars($msg) ?></span></div>
  <?php endif; ?>

  <div class="section">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11zM7 11h5v5H7z"/></svg>
        Manual Attendance Matrix
      </div>
      <button type="submit" form="attendanceForm" class="btn-save-attendance">Save Attendance</button>
    </div>
    <div class="legend">
      <span><span class="dot" style="background:rgba(34,197,94,0.5);"></span>P: Present</span>
      <span><span class="dot" style="background:rgba(var(--red-rgb),0.5);"></span>A: Absent</span>
      <span><span class="dot" style="background:rgba(245,158,11,0.5);"></span>H: Half-day</span>
      <span><span class="dot" style="background:rgba(59,130,246,0.5);"></span>L: On Leave</span>
      <span><span class="dot" style="background:rgba(var(--purple-rgb),0.5);"></span>W: Work From Home</span>
      <span>Click a cell to cycle · click again to clear</span>
    </div>

    <?php if (empty($employees)): ?>
    <div class="empty-state">No employees found.</div>
    <?php else: ?>
    <form method="POST" action="attendance<?= $month !== date('Y-m') ? '?month=' . $month : '' ?>" id="attendanceForm">
      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
      <input type="hidden" name="action" value="save_attendance">
      <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
      <div class="tbl-wrap">
        <table class="grid">
          <thead>
            <tr>
              <th class="emp-col">Employee</th>
              <?php for ($d = 1; $d <= $days_in; $d++): ?>
              <th><?= $d ?></th>
              <?php endfor; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($employees as $emp): $uid = (int)$emp['id']; ?>
            <tr>
              <td class="emp-col"><?= htmlspecialchars($emp['name']) ?></td>
              <?php for ($d = 1; $d <= $days_in; $d++):
                $st = $att_map[$uid][$d] ?? '';
              ?>
              <td>
                <button type="button" class="cell-btn <?= $st ?>" data-status="<?= $st ?>" data-uid="<?= $uid ?>" data-day="<?= $d ?>"><?= $st ? $status_letters[$st] : '' ?></button>
                <input type="hidden" name="records[<?= $uid ?>][<?= $d ?>]" value="<?= $st ?>">
              </td>
              <?php endfor; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
        Excel / CSV Attendance Import
      </div>
    </div>
    <div class="section-body">
      <form method="POST" action="attendance" enctype="multipart/form-data" id="importForm">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action" value="import_attendance">
        <div class="dropzone" id="dropzone">
          <svg viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
          <span id="dropzoneLabel">Drag & drop a CSV or Excel file here, or click to browse</span>
          <input type="file" name="attendance_file" id="attendanceFile" accept=".csv,.xlsx" hidden>
        </div>
      </form>
      <p class="webhook-hint">
        One row per employee per day. Columns can be named loosely, e.g. <code>Email</code>, <code>Work Date</code>, <code>Check In</code>, <code>Status</code>.
        Status accepts <code>Present/P</code>, <code>Absent/A</code>, <code>Half-day/H</code>, <code>On Leave/L</code>. Days already manually overridden are left untouched.
      </p>

      <?php if ($import_summary): ?>
      <div class="import-results">
        <?php if ($import_summary['errors']): ?>
        <div class="import-list errors"><strong>Errors</strong><?php foreach ($import_summary['errors'] as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?></div>
        <?php endif; ?>
        <?php if ($import_summary['skipped']): ?>
        <div class="import-list skipped"><strong>Skipped</strong><?php foreach (array_slice($import_summary['skipped'], 0, 50) as $s) echo '<div>' . htmlspecialchars($s) . '</div>'; ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M13 3a9 9 0 00-9 9H1l3.89 3.89.07.14L9 12H6a7 7 0 1113.5 2.5l1.66 1.66A9 9 0 0013 3zm-1 6v5l4.28 2.54.72-1.21-3.5-2.08V9H12z"/></svg>
        Biometric API / Webhook Settings
      </div>
    </div>
    <div class="section-body">
      <div class="webhook-row">
        <div class="webhook-secret"><?= $company && $company["attendance_webhook_secret"] ? htmlspecialchars($company["attendance_webhook_secret"]) : 'No webhook secret generated yet.' ?></div>
        <form method="POST" action="attendance" id="webhookSecretForm">
          <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
          <input type="hidden" name="action" value="generate_webhook_secret">
          <button type="<?= $company && $company["attendance_webhook_secret"] ? 'button' : 'submit' ?>" id="webhookSecretBtn" class="btn-gen-secret"><?= $company && $company["attendance_webhook_secret"] ? 'Regenerate' : 'Generate Secret' ?></button>
        </form>
      </div>
      <p class="webhook-hint">
        POST biometric logs as JSON to <code><?= htmlspecialchars(get_base_url()) ?>api/attendance_sync.php</code>
        with header <code>X-Hastra-Webhook-Secret: &lt;secret&gt;</code>. Body: an array of
        <code>{"email":"...","work_date":"YYYY-MM-DD","check_in":"HH:MM","status":"present"}</code>.
      </p>
    </div>
  </div>

</div>

<div class="modal-overlay" id="regenModal">
  <div class="modal">
    <h3>Regenerate webhook secret?</h3>
    <p>Any device or integration still using the old secret will stop working immediately.</p>
    <div class="modal-btns">
      <button type="button" class="modal-btn-confirm" id="regenConfirmBtn">Regenerate</button>
      <button type="button" class="modal-btn-cancel" onclick="document.getElementById('regenModal').classList.remove('open')">Cancel</button>
    </div>
  </div>
</div>

<script src="<?= get_base_url() ?>assets/js/calendar.js?v=<?= ASSET_VERSION ?>"></script>
<script>
  let gridDirty = false;
  const CYCLE = ['', 'present', 'absent', 'half_day', 'on_leave', 'wfh'];
  const LETTERS = { '': '', present: 'P', absent: 'A', half_day: 'H', on_leave: 'L', wfh: 'W' };
  document.querySelectorAll('.cell-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const cur  = btn.dataset.status || '';
      const next = CYCLE[(CYCLE.indexOf(cur) + 1) % CYCLE.length];
      btn.dataset.status = next;
      btn.textContent = LETTERS[next];
      btn.className = 'cell-btn' + (next ? ' ' + next : '');
      btn.nextElementSibling.value = next;
      gridDirty = true;
    });
  });

  const regenBtn = document.getElementById('webhookSecretBtn');
  if (regenBtn && regenBtn.type === 'button') {
    regenBtn.addEventListener('click', function () {
      document.getElementById('regenModal').classList.add('open');
    });
    document.getElementById('regenConfirmBtn').addEventListener('click', function () {
      document.getElementById('webhookSecretForm').submit();
    });
  }
  // ── Jump-to-day calendar ──
  // A day in the loaded month is highlighted and scrolled into view with no
  // reload. Any other month's records live on the server, so that jump loads
  // ?month=…&day=…, which highlights the day on arrival.
  (function () {
    const LOADED_MONTH = <?= json_encode($month) ?>;
    const toggle  = document.getElementById('calToggle');
    const popover = document.getElementById('calPopover');
    const params  = new URLSearchParams(location.search);
    const dayParam = parseInt(params.get('day') || '', 10);

    function focusDay(day) {
      document.querySelectorAll('table.grid .day-focus').forEach(el => el.classList.remove('day-focus'));
      const th = document.querySelector('table.grid thead th:nth-child(' + (day + 1) + ')');
      if (!th) return;
      th.classList.add('day-focus');
      document.querySelectorAll('table.grid .cell-btn[data-day="' + day + '"]').forEach(b => b.parentElement.classList.add('day-focus'));
      // Scroll horizontally only, keeping the column clear of the sticky name column.
      const wrap = th.closest('.tbl-wrap');
      const nameW = wrap.querySelector('th.emp-col').offsetWidth;
      wrap.scrollTo({ left: Math.max(0, th.offsetLeft - nameW - 80), behavior: 'smooth' });
    }

    const cal = HastraCalendar.mount('#attendanceCalendar', {
      mode: 'single',
      month: LOADED_MONTH,
      onSelect(detail) {
        const [y, m, d] = detail.date.split('-');
        const picked = y + '-' + m;
        if (picked === LOADED_MONTH) {
          focusDay(parseInt(d, 10));
          closePopover();
          return;
        }
        if (gridDirty && !confirm('You have unsaved attendance changes for this month. Leave without saving?')) return;
        location.href = '?month=' + picked + '&day=' + parseInt(d, 10);
      },
    });

    function openPopover()  { popover.hidden = false; toggle.setAttribute('aria-expanded', 'true');
                              const b = popover.querySelector('.hastra-cal-day[tabindex="0"]'); if (b) b.focus(); }
    function closePopover() { popover.hidden = true;  toggle.setAttribute('aria-expanded', 'false'); }
    toggle.addEventListener('click', () => popover.hidden ? openPopover() : closePopover());
    document.addEventListener('click', (e) => {
      if (!popover.hidden && !popover.contains(e.target) && !toggle.contains(e.target)) closePopover();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !popover.hidden) { closePopover(); toggle.focus(); }
    });

    if (dayParam >= 1 && dayParam <= 31) {
      if (cal) cal.pick(new Date(+LOADED_MONTH.slice(0, 4), +LOADED_MONTH.slice(5) - 1, dayParam));
    }
  })();

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.getElementById('regenModal').classList.remove('open');
  });

  (function () {
    const zone  = document.getElementById('dropzone');
    const input = document.getElementById('attendanceFile');
    const label = document.getElementById('dropzoneLabel');
    const form  = document.getElementById('importForm');
    if (!zone || !input || !form) return;

    zone.addEventListener('click', function () { input.click(); });

    ['dragenter', 'dragover'].forEach(function (evt) {
      zone.addEventListener(evt, function (e) { e.preventDefault(); zone.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
      zone.addEventListener(evt, function (e) { e.preventDefault(); zone.classList.remove('dragover'); });
    });
    zone.addEventListener('drop', function (e) {
      if (e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        submitImport();
      }
    });
    input.addEventListener('change', function () {
      if (input.files.length) submitImport();
    });

    function submitImport() {
      label.textContent = 'Uploading ' + input.files[0].name + '…';
      form.submit();
    }
  })();
</script>

</body>
</html>
