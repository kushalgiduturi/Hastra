<?php
// Astra — headless database backup for cron / Windows Task Scheduler.
//
// Writes a timestamped .sql dump to config/backups/ (same format and
// function used by the sysadmin "Database migration" page) and prunes
// dumps older than --keep-days (default 14).
//
// Usage:
//   C:\xampp\php\php.exe tools\backup_db.php [--keep-days=14]

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../core/backup.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

$keepDays = 14;
foreach ($argv as $arg) {
    if (preg_match('/^--keep-days=(\d+)$/', $arg, $m)) {
        $keepDays = (int)$m[1];
    }
}

try {
    $b = astra_backup_database($conn);
    printf("Backup written: %s (%d tables, %d rows, %s KB)\n",
        basename($b['file']), $b['tables'], $b['rows'], number_format($b['bytes'] / 1024, 1));
} catch (Throwable $e) {
    fwrite(STDERR, "Backup failed: " . $e->getMessage() . "\n");
    exit(1);
}

$cutoff = time() - $keepDays * 86400;
$pruned = 0;
foreach (glob(BACKUP_DIR . '/*.sql') as $f) {
    if (filemtime($f) < $cutoff) {
        unlink($f);
        $pruned++;
    }
}
if ($pruned) {
    echo "Pruned $pruned backup(s) older than $keepDays day(s).\n";
}
