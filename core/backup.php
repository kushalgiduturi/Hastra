<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// Astra — pure-PHP database backup (no mysqldump needed).
// Shared by portals/sysadmin/migrate.php (manual, in-browser) and
// tools/backup_db.php (headless, for cron / Task Scheduler).

if (!defined('BACKUP_DIR')) {
    define('BACKUP_DIR', __DIR__ . '/../config/backups');
}

function astra_backup_database($conn) {
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0700, true);
    $file    = BACKUP_DIR . '/' . DB_NAME . '-' . date('Ymd-His') . '.sql';
    $fh      = fopen($file, 'w');
    $charset = mysqli_character_set_name($conn);
    $tables  = 0; $rows = 0;

    fwrite($fh, "-- Astra backup of `" . DB_NAME . "` taken " . date('Y-m-d H:i:s') . "\n");
    $tz = mysqli_fetch_row(mysqli_query($conn, "SELECT @@session.time_zone"))[0];
    fwrite($fh, "SET NAMES $charset;\nSET time_zone = '$tz';\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

    $list = mysqli_query($conn, "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    while ($t = mysqli_fetch_row($list)) {
        $table = $t[0];
        $create = mysqli_fetch_row(mysqli_query($conn, "SHOW CREATE TABLE `$table`"))[1];
        fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n$create;\n\n");
        $tables++;

        $res   = mysqli_query($conn, "SELECT * FROM `$table`", MYSQLI_USE_RESULT);
        $batch = [];
        while ($r = mysqli_fetch_row($res)) {
            $batch[] = '(' . implode(',', array_map(
                fn($v) => $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $v) . "'", $r)) . ')';
            $rows++;
            if (count($batch) === 100) {
                fwrite($fh, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        mysqli_free_result($res);
        if ($batch) fwrite($fh, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($fh);
    return ['file' => $file, 'bytes' => filesize($file), 'tables' => $tables, 'rows' => $rows];
}
