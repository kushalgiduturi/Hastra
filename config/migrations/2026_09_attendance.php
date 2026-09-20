<?php
// Astra — attendance & leave management migration (Sep 2026). Run after
// 2026_09_crypto.
//
// Adds: companies leave-policy columns, users.gender/profile_updated/
// maternity_leave_eligible, leave_requests, attendance. Safe to run more
// than once — every step checks before it acts.

$__astra_att_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_att_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

function astra_migrate_attendance($conn, callable $out) {
    $out("");
    $out("== attendance & leave: companies leave policy");
    if (!db_column_exists($conn, 'companies', 'leave_cycle')) {
        mysqli_query($conn, "ALTER TABLE companies ADD COLUMN leave_cycle ENUM('monthly','yearly_rollover') NOT NULL DEFAULT 'monthly'");
        $out("   added companies.leave_cycle");
    }
    if (!db_column_exists($conn, 'companies', 'monthly_general_leaves')) {
        mysqli_query($conn, "ALTER TABLE companies ADD COLUMN monthly_general_leaves INT NOT NULL DEFAULT 1");
        $out("   added companies.monthly_general_leaves");
    }
    if (!db_column_exists($conn, 'companies', 'monthly_sick_leaves')) {
        mysqli_query($conn, "ALTER TABLE companies ADD COLUMN monthly_sick_leaves INT NOT NULL DEFAULT 1");
        $out("   added companies.monthly_sick_leaves");
    }
    if (!db_column_exists($conn, 'companies', 'annual_leave_allowance')) {
        mysqli_query($conn, "ALTER TABLE companies ADD COLUMN annual_leave_allowance INT NOT NULL DEFAULT 18");
        $out("   added companies.annual_leave_allowance");
    }
    if (!db_column_exists($conn, 'companies', 'attendance_webhook_secret')) {
        mysqli_query($conn, "ALTER TABLE companies ADD COLUMN attendance_webhook_secret VARCHAR(64) NULL");
        $out("   added companies.attendance_webhook_secret");
    }

    $out("");
    $out("== attendance & leave: users");
    if (!db_column_exists($conn, 'users', 'gender')) {
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN gender ENUM('male','female','prefer_not_to_say') NULL");
        $out("   added users.gender");
    }
    if (!db_column_exists($conn, 'users', 'profile_updated')) {
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN profile_updated TINYINT(1) NOT NULL DEFAULT 0");
        $out("   added users.profile_updated");
    }
    if (!db_column_exists($conn, 'users', 'maternity_leave_eligible')) {
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN maternity_leave_eligible TINYINT(1) NOT NULL DEFAULT 0");
        $out("   added users.maternity_leave_eligible");
    }

    $out("");
    $out("== attendance & leave: pending_registrations leave policy fields");
    if (!db_column_exists($conn, 'pending_registrations', 'leave_cycle')) {
        mysqli_query($conn, "ALTER TABLE pending_registrations ADD COLUMN leave_cycle ENUM('monthly','yearly_rollover') NULL");
        $out("   added pending_registrations.leave_cycle");
    }
    if (!db_column_exists($conn, 'pending_registrations', 'monthly_general_leaves')) {
        mysqli_query($conn, "ALTER TABLE pending_registrations ADD COLUMN monthly_general_leaves INT NULL");
        $out("   added pending_registrations.monthly_general_leaves");
    }
    if (!db_column_exists($conn, 'pending_registrations', 'monthly_sick_leaves')) {
        mysqli_query($conn, "ALTER TABLE pending_registrations ADD COLUMN monthly_sick_leaves INT NULL");
        $out("   added pending_registrations.monthly_sick_leaves");
    }
    if (!db_column_exists($conn, 'pending_registrations', 'annual_leave_allowance')) {
        mysqli_query($conn, "ALTER TABLE pending_registrations ADD COLUMN annual_leave_allowance INT NULL");
        $out("   added pending_registrations.annual_leave_allowance");
    }

    $out("");
    $out("== attendance & leave: leave_requests");
    $exists = mysqli_fetch_row(mysqli_query($conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests'"));
    if (!$exists) {
        mysqli_query($conn, "CREATE TABLE leave_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            company_id INT NOT NULL,
            leave_type ENUM('general','sick','maternity','unpaid') NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            total_days DECIMAL(4,1) NOT NULL,
            reason TEXT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            reviewed_by INT NULL,
            reviewed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_leave_user (user_id),
            INDEX idx_leave_company_status (company_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $out("   created leave_requests");
    } else {
        $out("   already present");
    }

    $out("");
    $out("== attendance & leave: attendance");
    $exists = mysqli_fetch_row(mysqli_query($conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'"));
    if (!$exists) {
        mysqli_query($conn, "CREATE TABLE attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            company_id INT NOT NULL,
            work_date DATE NOT NULL,
            status ENUM('present','absent','half_day','on_leave') NOT NULL,
            check_in TIME NULL,
            check_out TIME NULL,
            source ENUM('manual_manager','excel_import','automated_api') NOT NULL DEFAULT 'manual_manager',
            is_overridden TINYINT(1) NOT NULL DEFAULT 0,
            overridden_by INT NULL,
            notes VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_attendance_user_date (user_id, work_date),
            INDEX idx_attendance_company_date (company_id, work_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $out("   created attendance");
    } else {
        $out("   already present");
    }

    $out("");
    $out("== attendance & leave: work-from-home status");
    $col = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'status'"));
    if ($col && strpos($col['COLUMN_TYPE'], "'wfh'") === false) {
        mysqli_query($conn, "ALTER TABLE attendance MODIFY COLUMN status
            ENUM('present','absent','half_day','on_leave','wfh') NOT NULL");
        $out("   added 'wfh' to attendance.status");
    } else {
        $out("   already present");
    }
}

if ($__astra_att_cli) {
    require __DIR__ . '/../config.php';
    require __DIR__ . '/../../core/company.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    astra_migrate_attendance($conn, function ($line) { echo $line, "\n"; });
    echo "\nDone.\n";
}
