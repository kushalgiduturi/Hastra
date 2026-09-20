<?php
// portals/sysadmin/migrate.php
// Database migration page for the primary sysadmin: back up, dry run, apply.
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "sysadmin");

define('ASTRA_MIGRATION_INCLUDE', true);
require __DIR__ . '/../../config/migrations/2026_09_companies.php';
require __DIR__ . '/../../config/migrations/2026_09_onboarding.php';
require __DIR__ . '/../../config/migrations/2026_09_access.php';
require __DIR__ . '/../../config/migrations/2026_09_integrity.php';
require __DIR__ . '/../../config/migrations/2026_09_docs.php';
require __DIR__ . '/../../config/migrations/2026_09_tours.php';
require __DIR__ . '/../../config/migrations/2026_09_crypto.php';
require __DIR__ . '/../../config/migrations/2026_09_attendance.php';
require __DIR__ . '/../../config/migrations/2026_09_company_logo.php';
require __DIR__ . '/../../config/migrations/2026_09_account_type.php';

const BACKUP_DIR        = __DIR__ . '/../../config/backups';
const BACKUP_VALID_SECS = 3600;
require __DIR__ . '/../../core/backup.php';

// Only the primary sysadmin may change the schema.
$me = mysqli_prepare($conn, "SELECT name, email FROM users WHERE id = ?");
mysqli_stmt_bind_param($me, "i", $_SESSION["user_id"]);
mysqli_stmt_execute($me);
$me = mysqli_fetch_assoc(mysqli_stmt_get_result($me));
if (!$me || strcasecmp($me["email"], PRIMARY_SYSADMIN_EMAIL) !== 0) {
    http_response_code(403);
    exit("Only the primary sysadmin can run database migrations.");
}

$log     = [];
$result  = null;   // 'ok' | 'error'
$ran     = '';
$backup  = $_SESSION['astra_backup'] ?? null;
$backup_fresh = $backup && $backup['at'] >= time() - BACKUP_VALID_SECS && is_file($backup['file']);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $action = $_POST["action"] ?? "";
    $out    = function ($line) use (&$log) { $log[] = $line; };

    try {
        if ($action === "backup") {
            $b = astra_backup_database($conn);
            $_SESSION['astra_backup'] = ['file' => $b['file'], 'at' => time()];
            $backup = $_SESSION['astra_backup']; $backup_fresh = true;
            $log[] = "Backup written: config/backups/" . basename($b['file']);
            $log[] = sprintf("%d tables, %d rows, %s KB", $b['tables'], $b['rows'], number_format($b['bytes'] / 1024, 1));
            $ran = "Backup"; $result = 'ok';
        } elseif ($action === "dry_run") {
            if (!$backup_fresh) throw new RuntimeException("Take a backup first (step 1).");
            astra_migrate_companies($conn, false, $out);
            astra_migrate_onboarding($conn, $out);
            astra_migrate_access($conn, $out);
            astra_migrate_integrity($conn, $out);
            astra_migrate_docs($conn, $out);
            astra_migrate_tours($conn, $out);
            astra_migrate_crypto($conn, $out);
            astra_migrate_attendance($conn, $out);
            astra_migrate_company_logo($conn, $out);
            astra_migrate_account_type($conn, $out);
            $ran = "Dry run"; $result = 'ok';
        } elseif ($action === "apply") {
            if (!$backup_fresh) throw new RuntimeException("Take a backup first (step 1). Backups older than an hour don't count.");
            if (($_POST["confirm"] ?? "") !== "yes") throw new RuntimeException("Tick the confirmation box before moving users.");
            astra_migrate_companies($conn, true, $out);
            astra_migrate_onboarding($conn, $out);
            astra_migrate_access($conn, $out);
            astra_migrate_integrity($conn, $out);
            astra_migrate_docs($conn, $out);
            astra_migrate_tours($conn, $out);
            astra_migrate_crypto($conn, $out);
            astra_migrate_attendance($conn, $out);
            astra_migrate_company_logo($conn, $out);
            astra_migrate_account_type($conn, $out);
            $ran = "Apply"; $result = 'ok';
            // Your own ID may have moved — keep this session pointing at it.
            $again = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
            mysqli_stmt_bind_param($again, "s", $me["email"]);
            mysqli_stmt_execute($again);
            $new_me = mysqli_fetch_assoc(mysqli_stmt_get_result($again));
            if ($new_me && (int)$new_me["id"] !== (int)$_SESSION["user_id"]) {
                $log[] = "   You are now user #{$new_me['id']} (was #{$_SESSION['user_id']}). You stay signed in.";
                $_SESSION["user_id"] = (int)$new_me["id"];
            }
            try { log_activity($conn, $_SESSION["user_id"], "schema_migrated", $me["name"]); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        $log[] = "";
        $log[] = "!! " . $e->getMessage();
        $result = 'error';
    }
}

$schema_ready  = db_column_exists($conn, 'users', 'company_id') && db_column_exists($conn, 'companies', 'id_block_start');
$onboard_ready = db_column_exists($conn, 'users', 'client_role') && db_column_exists($conn, 'roster_staging', 'import_id')
              && db_column_exists($conn, 'deliveries', 'security_viewed_at')
              && db_column_exists($conn, 'bugs', 'cwe_id') && sequences_ready($conn)
              && db_column_exists($conn, 'projects', 'repo_url');
$tours_ready   = tours_schema_ready($conn);
$crypto_ready  = astra_crypto_column_type($conn, 'users', 'phone_number') === 'text'
              && astra_crypto_column_type($conn, 'logs', 'geo') === 'text';
$attendance_ready = db_column_exists($conn, 'users', 'gender') && db_column_exists($conn, 'companies', 'leave_cycle');
$logo_ready = db_column_exists($conn, 'companies', 'logo_url');
$account_type_ready = db_column_exists($conn, 'companies', 'account_type');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Database Migration · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--navy); color: var(--text); font-family: var(--font-sans); font-size: 14px; }
  .wrap { max-width: 880px; margin: 0 auto; padding: 2rem 1.2rem 4rem; }
  .back { font-size: 12px; color: var(--text-dim); text-decoration: none; }
  .back:hover { color: var(--text); }
  h1 { font-size: 22px; font-weight: 600; margin: 0.8rem 0 0.3rem; }
  .lede { color: var(--text-dim); margin: 0 0 1.4rem; line-height: 1.6; }
  .status { display: flex; flex-wrap: wrap; gap: 8px 20px; font-family: 'Share Tech Mono', monospace; font-size: 12px; color: var(--text-dim); margin-bottom: 1.4rem; }
  .status b { color: var(--text); font-weight: 400; }
  .ok   { color: var(--green) !important; }
  .warn { color: var(--yellow) !important; }
  .steps { display: grid; gap: 12px; }
  .step { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; padding: 1rem 1.2rem; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
  .step h2 { margin: 0 0 4px; font-size: 15px; font-weight: 600; }
  .step p { margin: 0; color: var(--text-dim); font-size: 13px; max-width: 56ch; line-height: 1.5; }
  .num { font-family: 'Share Tech Mono', monospace; color: var(--accent-bright); margin-right: 6px; }
  .actions { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
  button { font-family: var(--font-sans); font-size: 13px; font-weight: 600; border-radius: 3px; padding: 9px 16px; cursor: pointer; border: 1px solid var(--border); background: var(--input-bg); color: var(--text); }
  button:hover:not(:disabled) { border-color: var(--accent-bright); }
  button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  button.danger  { background: rgba(239,68,68,0.12); border-color: rgba(239,68,68,0.4); color: var(--red); }
  button:disabled { opacity: 0.45; cursor: not-allowed; }
  button:focus-visible { outline: 2px solid var(--accent-bright); outline-offset: 2px; }
  label.confirm { font-size: 12px; color: var(--text-dim); display: flex; gap: 6px; align-items: center; }
  .result { margin-top: 1.6rem; }
  .result h3 { font-family: 'Share Tech Mono', monospace; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); margin: 0 0 8px; font-weight: 400; }
  pre { margin: 0; background: var(--navy-deep); border: 1px solid var(--border-dim); border-radius: 4px; padding: 1rem; overflow-x: auto; font-family: 'Share Tech Mono', monospace; font-size: 12.5px; line-height: 1.6; color: var(--text); white-space: pre; }
  pre.error { border-color: rgba(239,68,68,0.4); }

  /* ── TOPNAV ── */
</style>
</head>
<body>
<?php $nav_current = 'migrate'; include __DIR__ . '/_nav.php'; ?>
<div class="wrap">
  <h1>Database migration</h1>
  <p class="lede">Sets up companies, company email domains, per-company user ID ranges and team onboarding (client roles, roster imports) client access rules (one-time security summary) and data integrity (race-free record codes, CWE IDs, encrypted credentials) and documentation drafts, and removes the old scheduled-deletion table. Take a backup, check the dry run, then apply. Users whose ID changes are signed out and need to log in again.</p>

  <div class="status">
    <span>Database <b><?= htmlspecialchars(DB_NAME) ?></b></span>
    <span>Company schema <b class="<?= $schema_ready ? 'ok' : 'warn' ?>"><?= $schema_ready ? 'installed' : 'not installed' ?></b></span>
    <span>P13–P16 schema <b class="<?= $onboard_ready ? 'ok' : 'warn' ?>"><?= $onboard_ready ? 'installed' : 'not installed' ?></b></span>
    <span>Onboarding tours (P18) <b class="<?= $tours_ready ? 'ok' : 'warn' ?>"><?= $tours_ready ? 'installed' : 'not installed' ?></b></span>
    <span>Column encryption <b class="<?= $crypto_ready ? 'ok' : 'warn' ?>"><?= $crypto_ready ? 'installed' : 'not installed' ?></b></span>
    <span>Attendance & leave <b class="<?= $attendance_ready ? 'ok' : 'warn' ?>"><?= $attendance_ready ? 'installed' : 'not installed' ?></b></span>
    <span>Company logo <b class="<?= $logo_ready ? 'ok' : 'warn' ?>"><?= $logo_ready ? 'installed' : 'not installed' ?></b></span>
    <span>Account types <b class="<?= $account_type_ready ? 'ok' : 'warn' ?>"><?= $account_type_ready ? 'installed' : 'not installed' ?></b></span>
    <span>Backup <b class="<?= $backup_fresh ? 'ok' : 'warn' ?>"><?= $backup_fresh ? htmlspecialchars(basename($backup['file'])) . ' · ' . date('H:i', $backup['at']) : 'none this hour' ?></b></span>
  </div>

  <div class="steps">
    <form class="step" method="POST">
      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
      <input type="hidden" name="action" value="backup">
      <div>
        <h2><span class="num">1</span>Back up the database</h2>
        <p>Saves every table to <code>config/backups/</code>, which the web server won't serve. Restore it in phpMyAdmin → Import if anything goes wrong.</p>
      </div>
      <div class="actions"><button type="submit" id="btnBackup" class="primary">Back up now</button></div>
    </form>

    <form class="step" method="POST">
      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
      <input type="hidden" name="action" value="dry_run">
      <div>
        <h2><span class="num">2</span>Dry run</h2>
        <p>Adds the new columns and tables, and lists which users would get new IDs. No user IDs change in this step.</p>
      </div>
      <div class="actions"><button type="submit" id="btnDryRun" <?= $backup_fresh ? '' : 'disabled' ?>>Run dry run</button></div>
    </form>

    <form class="step" method="POST">
      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
      <input type="hidden" name="action" value="apply">
      <div>
        <h2><span class="num">3</span>Apply and move users</h2>
        <p>Moves each user into their company's ID range and updates every table that references them.</p>
      </div>
      <div class="actions">
        <label class="confirm"><input type="checkbox" name="confirm" value="yes" id="confirmApply" <?= $backup_fresh ? '' : 'disabled' ?>> I've checked the dry run</label>
        <button type="submit" id="btnApply" class="danger" <?= $backup_fresh ? '' : 'disabled' ?>>Apply migration</button>
      </div>
    </form>
  </div>

  <?php if ($log): ?>
  <div class="result">
    <h3><?= htmlspecialchars($ran ?: 'Result') ?> — <?= $result === 'ok' ? 'finished' : 'stopped with an error' ?></h3>
    <pre id="migrationLog" class="<?= $result === 'error' ? 'error' : '' ?>"><?= htmlspecialchars(implode("\n", $log)) ?></pre>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
