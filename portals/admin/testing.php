<?php
// portals/admin/testing.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "admin");

// ── Fetch bug stats per project ────────────────────────────────────────────────
$bug_stats_result = mysqli_query($conn,
    "SELECT p.id, p.project_code, p.title, p.status,
            COUNT(b.id) AS total_bugs,
            COALESCE(SUM(b.status IN ('open','in_progress')),0) AS open_bugs,
            COALESCE(SUM(b.status IN ('fixed','retest')),0) AS retest_bugs,
            COALESCE(SUM(b.severity = 'critical' AND b.status NOT IN ('closed','wont_fix')),0) AS open_critical,
            COALESCE(SUM(b.status = 'closed'),0) AS closed_bugs,
            COALESCE(SUM(b.status = 'wont_fix'),0) AS wontfix_bugs
     FROM projects p
     LEFT JOIN bugs b ON b.project_id = p.id
     GROUP BY p.id
     ORDER BY open_critical DESC, open_bugs DESC, p.created_at DESC"
);
$bug_stats = [];
while ($row = mysqli_fetch_assoc($bug_stats_result)) $bug_stats[] = $row;

$total_open_bugs     = array_sum(array_column($bug_stats, 'open_bugs'));
$total_open_critical = array_sum(array_column($bug_stats, 'open_critical'));
$total_retest_bugs   = array_sum(array_column($bug_stats, 'retest_bugs'));
$total_closed_bugs   = array_sum(array_column($bug_stats, 'closed_bugs'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Testing &amp; Bugs · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    min-height: 100vh;
    background-color: var(--navy);
    background-image:
      linear-gradient(var(--grid-line) 1px, transparent 1px),
      linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
    background-size: 40px 40px;
    font-family: 'Inter', sans-serif;
    color: var(--text);
    transition: var(--transition);
  }

  .main { max-width: 1200px; margin: 0 auto; padding: 2rem; }
  .page-header { margin-bottom: 2rem; }
  .page-header h1 { font-size: 24px; font-weight: 600; letter-spacing: -0.01em; margin-bottom: 4px; }
  .page-header p { font-size: 13px; color: var(--text-dim); }

  .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.4rem; }
  .stat-card { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; padding: 1.2rem 1.4rem; position: relative; overflow: hidden; }
  .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; }
  .stat-card.yellow::before { background: var(--yellow); }
  .stat-card.green::before  { background: var(--green); }
  .stat-card.red::before    { background: var(--red); }
  .stat-label { font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px; }
  .stat-value { font-size: 28px; font-weight: 700; line-height: 1; }

  .section { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; overflow: hidden; margin-bottom: 1.5rem; }
  .section-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.4rem; border-bottom: 1px solid var(--border-dim); background: var(--section-header-bg); }
  .section-title { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; }
  .section-title svg { width: 15px; height: 15px; fill: var(--accent-bright); }
  .section-body { padding: 1.4rem; }

  .tbl-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead tr { background: var(--section-header-bg); border-bottom: 1px solid var(--border-dim); }
  th { padding: 10px 14px; text-align: left; font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); font-weight: 400; white-space: nowrap; }
  tbody tr { border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.15s; }
  tbody tr:hover { background: var(--hover-bg); }
  tbody tr:last-child { border-bottom: none; }
  td { padding: 11px 14px; color: var(--text); vertical-align: middle; }

  .badge { display: inline-block; padding: 2px 8px; border-radius: 2px; font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.05em; text-transform: uppercase; font-weight: 500; }
  .badge-team_assigned       { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .badge-development_started { background: rgba(34,211,238,0.1);   color: #22d3ee;            border: 1px solid rgba(34,211,238,0.2); }
  .badge-testing              { background: rgba(245,158,11,0.1);  color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }
  .badge-deployment_pending  { background: rgba(167,139,250,0.1);  color: var(--purple);      border: 1px solid rgba(167,139,250,0.2); }
  .badge-completed           { background: rgba(34,197,94,0.1);    color: var(--green);       border: 1px solid rgba(34,197,94,0.2); }

  .bug-stat-num { font-family: 'Share Tech Mono', monospace; font-weight: 600; }
  .bug-stat-num.zero   { color: var(--text-dim); font-weight: 400; }
  .bug-stat-num.warn   { color: var(--yellow); }
  .bug-stat-num.danger { color: var(--red); }
  tr.critical-row { background: rgba(239,68,68,0.05) !important; }

  .req-id-badge { font-family: 'Share Tech Mono', monospace; font-size: 12px; font-weight: 600; color: var(--accent-bright); background: rgba(var(--accent-rgb),0.08); border: 1px solid rgba(var(--accent-rgb),0.2); padding: 2px 8px; border-radius: 2px; letter-spacing: 0.08em; }

  .empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-dim); font-size: 13px; }
  .empty-state svg { width: 32px; height: 32px; fill: var(--border-dim); margin: 0 auto 0.8rem; display: block; }

  @media (max-width: 768px) {
    .stats-row { grid-template-columns: 1fr 1fr; }
  }
</style>
</head>
<body>

<?php include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>Testing &amp; Bug Overview</h1>
    <p>Track open bugs and test progress across all projects.</p>
  </div>

  <div class="section" id="sec-testing">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M20 8h-2.81c-.45-.78-1.07-1.45-1.82-1.96L17 4.41 15.59 3l-2.17 2.17C12.96 5.06 12.49 5 12 5c-.49 0-.96.06-1.41.17L8.41 3 7 4.41l1.62 1.63C7.88 6.55 7.26 7.22 6.81 8H4v2h2.09c-.05.33-.09.66-.09 1v1H4v2h2v1c0 .34.04.67.09 1H4v2h2.81c1.04 1.79 2.97 3 5.19 3s4.15-1.21 5.19-3H20v-2h-2.09c.05-.33.09-.66.09-1v-1h2v-2h-2v-1c0-.34-.04-.67-.09-1H20V8z"/></svg>
        Testing &amp; Bug Overview
        <span style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;margin-left:6px;"><?= count($bug_stats) ?> project<?= count($bug_stats) !== 1 ? 's' : '' ?></span>
      </div>
    </div>
    <div class="section-body">

      <div class="stats-row">
        <div class="stat-card red">
          <div class="stat-label">Open Bugs</div>
          <div class="stat-value"><?= $total_open_bugs ?></div>
        </div>
        <div class="stat-card red">
          <div class="stat-label">Open Critical</div>
          <div class="stat-value"><?= $total_open_critical ?></div>
        </div>
        <div class="stat-card yellow">
          <div class="stat-label">Awaiting Retest</div>
          <div class="stat-value"><?= $total_retest_bugs ?></div>
        </div>
        <div class="stat-card green">
          <div class="stat-label">Closed (Verified)</div>
          <div class="stat-value"><?= $total_closed_bugs ?></div>
        </div>
      </div>

      <?php if (empty($bug_stats)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
        No projects yet.
      </div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>Project</th>
              <th>Status</th>
              <th>Total Bugs</th>
              <th>Open</th>
              <th>Critical Open</th>
              <th>Awaiting Retest</th>
              <th>Closed</th>
              <th>Won't Fix</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bug_stats as $bs): ?>
            <tr class="<?= $bs['open_critical'] > 0 ? 'critical-row' : '' ?>">
              <td>
                <span class="req-id-badge"><?= htmlspecialchars($bs['project_code']) ?></span>
                <div style="font-size:12px;color:var(--text-dim);margin-top:3px;"><?= htmlspecialchars($bs['title']) ?></div>
              </td>
              <td><span class="badge badge-<?= htmlspecialchars($bs['status']) ?>"><?= str_replace('_', ' ', $bs['status']) ?></span></td>
              <td class="bug-stat-num <?= $bs['total_bugs'] == 0 ? 'zero' : '' ?>"><?= $bs['total_bugs'] ?></td>
              <td class="bug-stat-num <?= $bs['open_bugs'] > 0 ? 'warn' : 'zero' ?>"><?= $bs['open_bugs'] ?></td>
              <td class="bug-stat-num <?= $bs['open_critical'] > 0 ? 'danger' : 'zero' ?>"><?= $bs['open_critical'] ?></td>
              <td class="bug-stat-num <?= $bs['retest_bugs'] > 0 ? 'warn' : 'zero' ?>"><?= $bs['retest_bugs'] ?></td>
              <td class="bug-stat-num"><?= $bs['closed_bugs'] ?></td>
              <td class="bug-stat-num zero"><?= $bs['wontfix_bugs'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

</body>
</html>
