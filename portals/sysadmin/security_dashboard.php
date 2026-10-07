<?php
// portals/sysadmin/security_dashboard.php  (P17)
// Live status of Hastra's own security controls, for the sysadmin. Every
// figure on this page is read from the running config and the database —
// nothing here is a static claim.
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "sysadmin");

$schema   = audit_schema_status($conn);
$controls = audit_security_controls($conn);
$snap     = audit_activity_snapshot($conn);
$on_count = count(array_filter($controls, fn($c) => $c['on']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Security Dashboard · Hastra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>"><link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-contrast.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--navy); color: var(--text); font-family: var(--font-sans); font-size: 14px; }
  .wrap { max-width: 1040px; margin: 0 auto; padding: 2rem 1.2rem 4rem; }
  .back { font-size: 12px; color: var(--text-dim); text-decoration: none; }
  .back:hover { color: var(--text); }
  h1 { font-size: 22px; font-weight: 600; margin: 0.8rem 0 0.3rem; }
  .lede { color: var(--text-dim); margin: 0 0 1.6rem; line-height: 1.6; max-width: 70ch; }

  .snap { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 1.8rem; }
  @media (max-width: 760px) { .snap { grid-template-columns: repeat(2, 1fr); } }
  .snap-card { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; padding: 1rem 1.1rem; }
  .snap-card .n { font-family: 'Share Tech Mono', monospace; font-size: 26px; font-variant-numeric: tabular-nums; }
  .snap-card .l { color: var(--text-dim); font-size: 12px; margin-top: 2px; }
  .snap-card.good .n { color: var(--green); }
  .snap-card.warn .n { color: var(--yellow); }

  h2.section { font-family: 'Share Tech Mono', monospace; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); font-weight: 400; margin: 1.8rem 0 0.8rem; }

  .schema-row { display: flex; flex-wrap: wrap; gap: 8px; }
  .chip { font-family: 'Share Tech Mono', monospace; font-size: 12px; padding: 5px 10px; border-radius: 3px; border: 1px solid var(--border-dim); }
  .chip.on  { color: var(--green); border-color: rgba(34,197,94,0.35); background: rgba(34,197,94,0.06); }
  .chip.off { color: var(--yellow); border-color: rgba(234,179,8,0.35); background: rgba(234,179,8,0.06); }

  .controls { display: grid; gap: 10px; }
  .ctrl { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; padding: 0.9rem 1.1rem; display: flex; gap: 12px; align-items: flex-start; }
  .dot { width: 9px; height: 9px; border-radius: 50%; margin-top: 6px; flex: none; background: var(--yellow); }
  .ctrl.on .dot { background: var(--green); }
  .ctrl h3 { margin: 0 0 3px; font-size: 14px; font-weight: 600; }
  .ctrl p { margin: 0; color: var(--text-dim); font-size: 12.5px; line-height: 1.5; }
  .ctrl .state { margin-left: auto; font-family: 'Share Tech Mono', monospace; font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--yellow); flex: none; }
  .ctrl.on .state { color: var(--green); }

  /* ── TOPNAV ── */
</style>
</head>
<body>
<?php $nav_current = 'security'; include __DIR__ . '/_nav.php'; ?>
<div class="wrap">
  <h1>Security dashboard</h1>
  <p class="lede">What Hastra's own security controls are actually doing right now, read live from the config and the database. These are the same measures a client's Project Manager sees once in the delivery handover, kept always on for the sysadmin.</p>

  <div class="snap">
    <div class="snap-card"><div class="n"><?= $on_count ?>/<?= count($controls) ?></div><div class="l">Controls active</div></div>
    <div class="snap-card"><div class="n"><?= number_format($snap['logs_30d']) ?></div><div class="l">Logged events, last 30 days</div></div>
    <div class="snap-card <?= $snap['failed_30d'] > 0 ? 'warn' : 'good' ?>"><div class="n"><?= number_format($snap['failed_30d']) ?></div><div class="l">Failed logins, last 30 days</div></div>
    <div class="snap-card"><div class="n"><?= number_format($snap['deleted_30d']) ?></div><div class="l">Accounts deleted, last 30 days</div></div>
  </div>

  <h2 class="section">Schema readiness</h2>
  <div class="schema-row">
    <?php foreach ($schema as $s): ?>
    <span class="chip <?= $s['ready'] ? 'on' : 'off' ?>"><?= $s['ready'] ? '✓' : '-' ?> <?= htmlspecialchars($s['label']) ?></span>
    <?php endforeach; ?>
  </div>

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

  <h2 class="section">Related</h2>
  <div class="schema-row">
    <a class="chip off" style="text-decoration:none;" href="<?= get_base_url() ?>workspace/sysadmin/migrate">Run database migration</a>
    <a class="chip off" style="text-decoration:none;" href="<?= get_base_url() ?>workspace/sysadmin/">Activity log &amp; users</a>
  </div>
</div>
</body>
</html>
