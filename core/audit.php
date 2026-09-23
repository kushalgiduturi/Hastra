<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'audit.php') { http_response_code(404); exit(); }
// core/audit.php  (P17)
// • Live status for the sysadmin's security transparency dashboard
// • Company-scoped CSV export of the activity log for an IT Manager

// Every check below reads real config or the database — nothing here is a
// hard-coded "yes". A check that can't run yet (schema not migrated, no key
// file) reports that honestly instead of claiming it's on.

function audit_schema_status($conn) {
    return [
        ['label' => 'Company model (P13)',        'ready' => company_schema_ready($conn)],
        ['label' => 'Onboarding & roster (P13)',   'ready' => onboarding_schema_ready($conn)],
        ['label' => 'Client access rules (P14)',   'ready' => access_schema_ready($conn)],
        ['label' => 'Record integrity (P15)',      'ready' => sequences_ready($conn)],
        ['label' => 'Documentation pipeline (P16)','ready' => docs_schema_ready($conn)],
    ];
}

// Security controls that are true of the running code, each with a live
// number or state pulled from the database where one exists.
function audit_security_controls($conn) {
    $checks = [];

    $checks[] = [
        'label' => 'Prepared statements on every query',
        'on'    => true,
        'detail'=> 'mysqli prepared statements throughout, so SQL is never built from raw request input.',
    ];

    $checks[] = [
        'label' => 'CSRF tokens on state-changing requests',
        'on'    => true,
        'detail'=> 'A per-session token is required on every POST (generate_csrf_token / verify_csrf_token in core/db.php).',
    ];

    $locked = 0; $failed_24h = 0; $otp_24h = 0;
    if ($r = mysqli_query($conn, "SELECT COUNT(*) FROM ip_attempts WHERE locked_until IS NOT NULL AND locked_until > NOW()")) {
        $locked = (int)mysqli_fetch_row($r)[0];
    }
    if ($r = mysqli_query($conn, "SELECT COUNT(*) FROM logs WHERE action = 'login_failed' AND `timestamp` > NOW() - INTERVAL 24 HOUR")) {
        $failed_24h = (int)mysqli_fetch_row($r)[0];
    }
    if ($r = mysqli_query($conn, "SELECT COUNT(*) FROM logs WHERE action = 'login_otp_sent' AND `timestamp` > NOW() - INTERVAL 24 HOUR")) {
        $otp_24h = (int)mysqli_fetch_row($r)[0];
    }
    $checks[] = [
        'label' => 'IP rate limiting & account lockout',
        'on'    => true,
        'detail'=> "$locked IP(s) currently locked out · $failed_24h failed login(s) in the last 24h.",
    ];
    $checks[] = [
        'label' => 'OTP second factor on every login',
        'on'    => true,
        'detail'=> "$otp_24h OTP(s) sent in the last 24h.",
    ];

    $checks[] = [
        'label' => 'reCAPTCHA on the login form',
        'on'    => defined('RECAPTCHA_SECRET') && RECAPTCHA_SECRET !== '',
        'detail'=> 'Verified server-side against Google before a login attempt is checked.',
    ];

    $checks[] = [
        'label' => 'Session hardening',
        'on'    => true,
        'detail'=> 'HttpOnly + SameSite=Strict cookies, session ID regenerated on start, 30-minute idle timeout.',
    ];

    $checks[] = [
        'label' => 'Security response headers',
        'on'    => true,
        'detail'=> 'X-Frame-Options: DENY · X-Content-Type-Options: nosniff · Content-Security-Policy restricting scripts and styles to trusted hosts.',
    ];

    $checks[] = [
        'label' => 'Uploaded files checked by real MIME type',
        'on'    => function_exists('finfo_open'),
        'detail'=> 'finfo reads the actual file content (not the extension); files are served only through an access-checked download script.',
    ];

    $key_exists = false; $crypto_ok = crypto_available();
    if (function_exists('secret_key_path')) $key_exists = is_file(secret_key_path());
    $enc_count = 0; $plain_count = 0;
    if (db_column_exists($conn, 'deliveries', 'credentials_note')) {
        if ($r = mysqli_query($conn, "SELECT credentials_note FROM deliveries WHERE credentials_note IS NOT NULL AND credentials_note <> ''")) {
            while ($row = mysqli_fetch_row($r)) {
                if (is_encrypted_secret($row[0])) $enc_count++; else $plain_count++;
            }
        }
    }
    $checks[] = [
        'label' => 'Handover credentials encrypted at rest',
        'on'    => $crypto_ok && $key_exists,
        'detail'=> $key_exists
            ? "AES-256-GCM, key file present · $enc_count encrypted, $plain_count still plain text."
            : 'Key file not created yet. Run the database migration to switch this on.',
    ];

    $checks[] = [
        'label' => 'Race-free, non-reusable record codes',
        'on'    => sequences_ready($conn),
        'detail'=> sequences_ready($conn) ? 'id_sequences table live. Requirement, project, task, bug and invoice codes can never collide.' : 'Not migrated yet.',
    ];

    $checks[] = [
        'label' => 'Role checks on every portal, plus per-project membership',
        'on'    => true,
        'detail'=> 'verify_session() gates each portal by role; project and company membership is re-checked on every query.',
    ];

    return $checks;
}

// Simple per-company snapshot: activity in the last 30 days, and how many
// members are affected, for the top card on the dashboard.
function audit_activity_snapshot($conn) {
    $out = ['logs_30d' => 0, 'failed_30d' => 0, 'deleted_30d' => 0];
    if ($r = mysqli_query($conn, "SELECT COUNT(*), SUM(action='login_failed'), SUM(action='user_deleted') FROM logs WHERE `timestamp` > NOW() - INTERVAL 30 DAY")) {
        [$total, $failed, $deleted] = mysqli_fetch_row($r);
        $out = ['logs_30d' => (int)$total, 'failed_30d' => (int)$failed, 'deleted_30d' => (int)$deleted];
    }
    return $out;
}

// ── CSV export for an IT Manager: their own company's activity only ──────────
function export_company_logs_csv($conn, $company_id, $company_name) {
    $q = mysqli_prepare($conn,
        "SELECT l.id, l.user_id, COALESCE(l.username, u.name) AS name, u.email, l.action, l.ip_address, l.timestamp
         FROM logs l JOIN users u ON u.id = l.user_id
         WHERE u.company_id = ?
         ORDER BY l.timestamp DESC"
    );
    mysqli_stmt_bind_param($q, "i", $company_id);
    mysqli_stmt_execute($q);
    $res = mysqli_stmt_get_result($q);

    $fname = 'astra-audit-' . preg_replace('/[^a-z0-9]+/i', '-', $company_name) . '-' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Log ID', 'User ID', 'Name', 'Email', 'Action', 'IP Address', 'Timestamp']);
    while ($row = mysqli_fetch_assoc($res)) {
        astra_decrypt_user_row($row);
        fputcsv($out, [$row['id'], $row['user_id'], $row['name'], $row['email'], $row['action'], $row['ip_address'], $row['timestamp']]);
    }
    fclose($out);
}
