<?php
// portals/admin/security.php
// Company-scoped security posture for a tenant admin — the same live
// controls the sysadmin dashboard shows (core/audit.php's
// audit_security_controls(), true platform-wide), plus this company's own
// login activity, currently-locked accounts, and a CSV export of its
// audit trail (export_company_logs_csv() — previously only wired to the
// client IT Manager's Team Roster page).
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "admin");

$company    = astra_session_company($conn);
$company_id = $company['id'] ?? null;

if ($_SERVER["REQUEST_METHOD"] === "GET" && ($_GET["export"] ?? "") === "csv" && $company_id) {
    export_company_logs_csv($conn, $company_id, $company['company_name']);
    exit();
}

$controls = audit_security_controls($conn);
$on_count = count(array_filter($controls, fn($c) => $c['on']));

$snap = ['logs_30d' => 0, 'failed_30d' => 0, 'success_30d' => 0, 'locked_now' => 0];
$events = [];
$locked_accounts = [];

if ($company_id) {
    $sq = mysqli_prepare($conn,
        "SELECT COUNT(*), SUM(l.action='login_failed'), SUM(l.action='login_success')
         FROM logs l JOIN users u ON u.id = l.user_id
         WHERE u.company_id = ? AND l.timestamp > NOW() - INTERVAL 30 DAY");
    mysqli_stmt_bind_param($sq, "i", $company_id);
    mysqli_stmt_execute($sq);
    [$total, $failed, $success] = mysqli_fetch_row(mysqli_stmt_get_result($sq));
    $snap['logs_30d']    = (int)$total;
    $snap['failed_30d']  = (int)$failed;
    $snap['success_30d'] = (int)$success;

    $lc = mysqli_prepare($conn, "SELECT COUNT(*) FROM users WHERE company_id = ? AND locked_until IS NOT NULL AND locked_until > NOW()");
    mysqli_stmt_bind_param($lc, "i", $company_id);
    mysqli_stmt_execute($lc);
    $snap['locked_now'] = (int)mysqli_fetch_row(mysqli_stmt_get_result($lc))[0];

    $eq = mysqli_prepare($conn,
        "SELECT l.action, l.ip_address, l.timestamp, COALESCE(l.username, u.name) AS name
         FROM logs l JOIN users u ON u.id = l.user_id
         WHERE u.company_id = ? ORDER BY l.timestamp DESC LIMIT 25");
    mysqli_stmt_bind_param($eq, "i", $company_id);
    mysqli_stmt_execute($eq);
    $events = mysqli_fetch_all(mysqli_stmt_get_result($eq), MYSQLI_ASSOC);
    foreach ($events as &$__ev) { $__ev['name'] = astra_db_decrypt($__ev['name']); }
    unset($__ev);

    $la = mysqli_prepare($conn, "SELECT name, email, locked_until FROM users WHERE company_id = ? AND locked_until IS NOT NULL AND locked_until > NOW() ORDER BY locked_until DESC");
    mysqli_stmt_bind_param($la, "i", $company_id);
    mysqli_stmt_execute($la);
    $locked_accounts = mysqli_fetch_all(mysqli_stmt_get_result($la), MYSQLI_ASSOC);
    $locked_accounts = astra_decrypt_user_rows($locked_accounts);
}

$action_labels = [
    'login_success'     => 'Signed in',
    'login_failed'      => 'Failed login',
    'login_otp_sent'     => 'OTP sent',
    'login_blocked_vpn' => 'Blocked (VPN)',
    'account_locked'    => 'Account locked',
    'logout'            => 'Signed out',
    'user_deleted'      => 'Account deleted',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Security · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--navy); color: var(--text); font-family: var(--font-sans); font-size: 14px; }
  .wrap { max-width: 1040px; margin: 0 auto; padding: 2rem 1.2rem 4rem; }
  h1 { font-size: 22px; font-weight: 600; margin: 0.8rem 0 0.3rem; }
  .lede { color: var(--text-dim); margin: 0 0 1.6rem; line-height: 1.6; max-width: 70ch; }

  .snap { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 1.8rem; }
  @media (max-width: 760px) { .snap { grid-template-columns: repeat(2, 1fr); } }
  .snap-card { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; padding: 1rem 1.1rem; }
  .snap-card .n { font-family: 'Share Tech Mono', monospace; font-size: 26px; font-variant-numeric: tabular-nums; }
  .snap-card .l { color: var(--text-dim); font-size: 12px; margin-top: 2px; }
  .snap-card.good .n { color: var(--green); }
  .snap-card.warn .n { color: var(--yellow); }

  h2.section { font-family: 'Share Tech Mono', monospace; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); font-weight: 400; margin: 1.8rem 0 0.8rem; display: flex; align-items: center; justify-content: space-between; }

  .controls { display: grid; gap: 10px; }
  .ctrl { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; padding: 0.9rem 1.1rem; display: flex; gap: 12px; align-items: flex-start; }
  .dot { width: 9px; height: 9px; border-radius: 50%; margin-top: 6px; flex: none; background: var(--yellow); }
  .ctrl.on .dot { background: var(--green); }
  .ctrl h3 { margin: 0 0 3px; font-size: 14px; font-weight: 600; }
  .ctrl p { margin: 0; color: var(--text-dim); font-size: 12.5px; line-height: 1.5; }
  .ctrl .state { margin-left: auto; font-family: 'Share Tech Mono', monospace; font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--yellow); flex: none; }
  .ctrl.on .state { color: var(--green); }

  table.events { width: 100%; border-collapse: collapse; background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; overflow: hidden; }
  table.events th, table.events td { text-align: left; padding: 8px 12px; font-size: 12.5px; border-bottom: 1px solid var(--border-dim); }
  table.events th { color: var(--text-dim); font-family: 'Share Tech Mono', monospace; font-size: 10.5px; letter-spacing: 0.06em; text-transform: uppercase; font-weight: 400; }
  table.events td.mono { font-family: 'Share Tech Mono', monospace; font-variant-numeric: tabular-nums; color: var(--text-dim); }
  table.events tr:last-child td { border-bottom: none; }
  .action-badge { font-family: 'Share Tech Mono', monospace; font-size: 11px; padding: 2px 8px; border-radius: 3px; border: 1px solid var(--border-dim); }
  .action-badge.warn { color: var(--yellow); border-color: rgba(234,179,8,0.35); background: rgba(234,179,8,0.06); }
  .action-badge.bad  { color: var(--red); border-color: rgba(224,46,60,0.35); background: var(--red-bg); }
  .action-badge.good { color: var(--green); border-color: rgba(34,197,94,0.35); background: rgba(34,197,94,0.06); }

  .empty { color: var(--text-dim); font-size: 13px; padding: 1.2rem; text-align: center; background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; }
  .export-link { font-family: 'Share Tech Mono', monospace; font-size: 11px; color: var(--accent-bright); text-decoration: none; letter-spacing: 0.04em; }
  .export-link:hover { text-decoration: underline; }
</style>
</head>
<body>
<?php $nav_current = 'security'; include __DIR__ . '/_nav.php'; ?>
<div class="wrap">
  <h1>System security</h1>
  <p class="lede">What Astra's security controls are actually doing right now, read live from the config and the database, plus <?= $company ? htmlspecialchars($company['company_name']) : 'your workspace' ?>'s own login activity.</p>

  <div class="snap">
    <div class="snap-card"><div class="n"><?= $on_count ?>/<?= count($controls) ?></div><div class="l">Controls active</div></div>
    <div class="snap-card"><div class="n"><?= number_format($snap['logs_30d']) ?></div><div class="l">Logged events, last 30 days</div></div>
    <div class="snap-card <?= $snap['failed_30d'] > 0 ? 'warn' : 'good' ?>"><div class="n"><?= number_format($snap['failed_30d']) ?></div><div class="l">Failed logins, last 30 days</div></div>
    <div class="snap-card <?= $snap['locked_now'] > 0 ? 'warn' : 'good' ?>"><div class="n"><?= number_format($snap['locked_now']) ?></div><div class="l">Accounts locked right now</div></div>
  </div>

  <?php if ($snap['locked_now'] > 0): ?>
  <h2 class="section">Currently locked accounts</h2>
  <table class="events">
    <thead><tr><th>Name</th><th>Email</th><th>Locked until</th></tr></thead>
    <tbody>
      <?php foreach ($locked_accounts as $la): ?>
      <tr>
        <td><?= htmlspecialchars($la['name']) ?></td>
        <td class="mono"><?= htmlspecialchars($la['email']) ?></td>
        <td class="mono"><?= htmlspecialchars($la['locked_until']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <h2 class="section">
    Recent activity
    <?php if ($company_id): ?><a class="export-link" href="?export=csv">↓ Export CSV</a><?php endif; ?>
  </h2>
  <?php if ($events): ?>
  <table class="events">
    <thead><tr><th>Event</th><th>User</th><th>IP</th><th>When</th></tr></thead>
    <tbody>
      <?php foreach ($events as $e):
        $label = $action_labels[$e['action']] ?? $e['action'];
        $cls = in_array($e['action'], ['login_failed', 'account_locked', 'user_deleted'], true) ? 'bad'
             : ($e['action'] === 'login_success' ? 'good' : 'warn');
      ?>
      <tr>
        <td><span class="action-badge <?= $cls ?>"><?= htmlspecialchars($label) ?></span></td>
        <td><?= htmlspecialchars($e['name'] ?? '-') ?></td>
        <td class="mono"><?= htmlspecialchars($e['ip_address'] ?? '-') ?></td>
        <td class="mono"><?= htmlspecialchars($e['timestamp']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div class="empty">No logged activity for this workspace yet.</div>
  <?php endif; ?>

  <h2 class="section">Live controls</h2>
  <div class="controls">
    <?php foreach ($controls as $c): ?>
    <div class="ctrl <?= $c['on'] ? 'on' : '' ?>">
      <span class="dot"></span>
      <div>
        <h3><?= htmlspecialchars($c['label']) ?></h3>
        <p><?= htmlspecialchars($c['detail']) ?></p>
      </div>
      <span class="state"><?= $c['on'] ? 'On' : 'Not yet' ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</body>
</html>
