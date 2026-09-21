<?php
// portals/sysadmin/logs.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "sysadmin");

// ── Company sections (for the log company filter) ─────────────────────────────
$schema_ready = company_schema_ready($conn);
$STAFF_ROLES  = ['sysadmin', 'admin', 'employee', 'pending_employee'];
$role_order   = "FIELD(role,'sysadmin','admin','employee','pending_employee','client'), id";

$company_sections = [];
if ($schema_ready) {
    foreach (list_companies($conn) as $c) {
        $company_sections['c' . $c['id']] = [
            'key'  => 'c' . $c['id'],
            'name' => $c['company_name'],
            'internal' => (bool)$c['is_internal'],
            'users' => [],
        ];
    }
    $company_sections['none'] = ['key' => 'none', 'name' => 'No company', 'internal' => false, 'users' => []];
    $res = mysqli_query($conn, "SELECT id, role, company_id FROM users ORDER BY $role_order");
    while ($u = mysqli_fetch_assoc($res)) {
        $key = ($u['company_id'] && isset($company_sections['c' . $u['company_id']])) ? 'c' . $u['company_id'] : 'none';
        $company_sections[$key]['users'][] = $u;
    }
} else {
    $company_sections['staff'] = ['key' => 'staff', 'name' => INTERNAL_COMPANY_NAME, 'internal' => true, 'users' => []];
    $company_sections['none']  = ['key' => 'none', 'name' => 'Clients', 'internal' => false, 'users' => []];
    $res = mysqli_query($conn, "SELECT id, role FROM users ORDER BY $role_order");
    while ($u = mysqli_fetch_assoc($res)) {
        $company_sections[in_array($u['role'], $STAFF_ROLES, true) ? 'staff' : 'none']['users'][] = $u;
    }
}
if (empty($company_sections['none']['users'])) unset($company_sections['none']);

$user_company_key = [];
foreach ($company_sections as $sec) {
    foreach ($sec['users'] as $u) $user_company_key[(int)$u['id']] = $sec['key'];
}

$logs = mysqli_query($conn, "SELECT * FROM logs ORDER BY timestamp DESC");
$all_logs = [];
if ($logs && mysqli_num_rows($logs) > 0) {
    while ($log = mysqli_fetch_assoc($logs)) {
        $all_logs[] = $log;
    }
}
$total_logs    = count($all_logs);
$initial_limit = 10;

$user_map_query = mysqli_query($conn, "SELECT id, name, email FROM users");
$user_map = [];
$email_map = [];
while ($u = mysqli_fetch_assoc($user_map_query)) {
    $user_map[$u['id']]  = $u['name'];
    $email_map[$u['id']] = $u['email'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Activity Logs · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/custom-dropdowns.css?v=<?= ASSET_VERSION ?>">
<script src="<?= get_base_url() ?>assets/js/custom-dropdowns.js?v=<?= ASSET_VERSION ?>"></script>
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

  .main { max-width: 1200px; margin: 0 auto; padding: 2rem; }
  .page-header { margin-bottom: 2rem; }
  .page-header h1 { font-size: 24px; font-weight: 600; color: var(--text); letter-spacing: -0.01em; margin-bottom: 4px; }
  .page-header p { font-size: 13px; color: var(--text-dim); }

  .section { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; margin-bottom: 1.5rem; overflow: hidden; }
  .section-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.4rem; border-bottom: 1px solid var(--border-dim); background: var(--section-header-bg); }
  .section-title { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: var(--text); letter-spacing: 0.03em; text-transform: uppercase; }
  .section-title svg { width: 15px; height: 15px; fill: var(--accent-bright); }
  .section-body { padding: 1.4rem; }

  .badge { display: inline-block; padding: 2px 8px; border-radius: 2px; font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.05em; text-transform: uppercase; font-weight: 500; }
  .badge-success  { background: rgba(34,197,94,0.1);    color: var(--green);       border: 1px solid rgba(34,197,94,0.2); }
  .badge-warning  { background: rgba(245,158,11,0.1);   color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }
  .badge-danger   { background: rgba(var(--red-rgb),0.1);    color: var(--red);         border: 1px solid rgba(var(--red-rgb),0.2); }
  .badge-info     { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }

  .tbl-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead tr { background: var(--section-header-bg); border-bottom: 1px solid var(--border-dim); }
  th { padding: 10px 14px; text-align: left; font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); font-weight: 400; white-space: nowrap; }
  tbody tr { border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.15s; }
  tbody tr:hover { background: var(--hover-bg); }
  tbody tr:last-child { border-bottom: none; }
  td { padding: 11px 14px; color: var(--text); vertical-align: middle; }
  td.muted { color: var(--text-dim); font-family: 'Share Tech Mono', monospace; font-size: 12px; }

  .filters { display: flex; gap: 8px; margin-bottom: 1rem; flex-wrap: wrap; align-items: center; }
  .filters input, .filters select { background: var(--input-bg); border: 1px solid var(--border-dim); border-radius: 3px; color: var(--text); font-family: var(--font-sans); font-size: 12px; padding: 7px 10px; outline: none; transition: border-color 0.2s; }
  .filters input:focus, .filters select:focus { border-color: var(--accent-bright); }
  .filters input { width: 160px; }
  .filters input::placeholder { color: var(--text-dim); }
  .filters select option { background: var(--navy-card); }

  .btn-clear { background: var(--input-bg); border: 1px solid var(--border-dim); border-radius: 3px; color: var(--text-dim); font-family: var(--font-sans); font-size: 12px; padding: 7px 12px; cursor: pointer; transition: background 0.2s, color 0.2s; }
  .btn-clear:hover { background: rgba(255,255,255,0.08); color: var(--text); }

  .log-meta { font-size: 11px; font-family: 'Share Tech Mono', monospace; color: var(--text-dim); margin-bottom: 10px; letter-spacing: 0.04em; }
  .geo-cell { font-size: 11px; color: var(--text-dim); }
  .show-more-wrap { text-align: center; margin-top: 1rem; }
  .btn-more { background: rgba(var(--accent-rgb),0.08); border: 1px solid var(--border); border-radius: 3px; color: var(--accent-bright); font-family: var(--font-sans); font-size: 12px; font-weight: 500; padding: 7px 20px; cursor: pointer; transition: background 0.2s; }
  .btn-more:hover { background: rgba(var(--accent-rgb),0.15); }
</style>
</head>
<body>

<?php $nav_current = 'logs'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>Activity Logs</h1>
    <p>Monitor authentication and system activity.</p>
  </div>

  <div class="section" id="sec-logs">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg>
        Activity Logs
        <span style="font-size:11px; color:var(--text-dim); font-family:'Share Tech Mono',monospace; margin-left:8px;"><?= $total_logs ?> records</span>
      </div>
    </div>

    <div class="section-body">
      <div class="filters">
        <select id="f_company" onchange="applyFilters()">
          <option value="">All companies</option>
          <?php foreach ($company_sections as $sec): ?>
          <option value="<?= $sec['key'] ?>"><?= htmlspecialchars($sec['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" id="f_uid" placeholder="Filter by User ID" oninput="applyFilters()">
        <input type="text" id="f_ip"  placeholder="Filter by IP"      oninput="applyFilters()">
        <select id="f_action" onchange="applyFilters()">
          <option value="">All Actions</option>
          <option value="login_success">login_success</option>
          <option value="login_otp_sent">login_otp_sent</option>
          <option value="login_failed">login_failed</option>
          <option value="account_locked">account_locked</option>
          <option value="logout">logout</option>
          <option value="user_deleted">user_deleted</option>
        </select>
        <button class="btn-clear" onclick="clearFilters()">Clear</button>
      </div>

      <div class="log-meta" id="logMeta">
        Showing <span id="visibleCount"><?= min($initial_limit, $total_logs) ?></span> of <?= $total_logs ?> logs
      </div>

      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>User ID</th>
              <th>Name</th>
              <th>Email</th>
              <th>Action</th>
              <th>IP Address</th>
              <th>Location / Coordinates / ISP</th>
              <th>Timestamp</th>
            </tr>
          </thead>
          <tbody id="logBody">
            <?php foreach ($all_logs as $index => $log):
              $lid    = (int)($log["id"] ?? 0);
              $uid    = (int)($log["user_id"] ?? 0);
              $act    = htmlspecialchars($log["action"] ?? "");
              $ip     = htmlspecialchars($log["ip_address"] ?? "");
              $ts     = htmlspecialchars($log["timestamp"] ?? "");
              $name   = htmlspecialchars($log["username"] ?? $user_map[$uid] ?? "Unknown");
              $email  = htmlspecialchars($email_map[$uid] ?? "—");
              $hidden = $index >= $initial_limit ? 'display:none;' : '';

              if ($act === 'login_success')                                  $badge_class = 'badge-success';
              elseif ($act === 'logout')                                     $badge_class = 'badge-info';
              elseif ($act === 'login_failed' || $act === 'account_locked')  $badge_class = 'badge-danger';
              else                                                           $badge_class = 'badge-warning';

              $raw_geo = astra_db_decrypt($log["geo"] ?? "");
              if (!$raw_geo) {
                $geo_display = ($ip === '::1' || $ip === '127.0.0.1') ? 'Localhost' : 'N/A';
              } else {
                $geo_parts   = explode(' | ', $raw_geo);
                $location    = htmlspecialchars($geo_parts[0] ?? '');
                $coords      = htmlspecialchars($geo_parts[1] ?? '');
                $isp         = htmlspecialchars($geo_parts[2] ?? '');
                $geo_display = $location
                  . '<br><span style="font-family:\'Share Tech Mono\',monospace;font-size:10px;color:var(--text-dim);">' . $coords . '</span>'
                  . '<br><span style="font-size:10px;color:var(--text-dim);">' . $isp . '</span>';
              }
            ?>
            <tr class="log-row"
              data-index="<?= $index ?>"
              data-uid="<?= $uid ?>"
              data-ip="<?= $ip ?>"
              data-action="<?= $act ?>"
              data-company="<?= $user_company_key[$uid] ?? 'none' ?>"
              style="<?= $hidden ?>">
              <td class="muted"><?= $lid ?></td>
              <td class="muted"><?= $uid ?></td>
              <td><?= $name ?></td>
              <td class="muted"><?= $email ?></td>
              <td><span class="badge <?= $badge_class ?>"><?= $act ?></span></td>
              <td class="muted"><?= $ip ?></td>
              <td class="geo-cell"><?= $geo_display ?></td>
              <td class="muted"><?= $ts ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($total_logs > $initial_limit): ?>
      <div class="show-more-wrap">
        <button class="btn-more" id="showMoreBtn" onclick="toggleMore()">
          Show More (<?= $total_logs - $initial_limit ?> more)
        </button>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
let expanded = false;
const TOTAL   = <?= $total_logs ?>;
const INITIAL = <?= $initial_limit ?>;

function toggleMore() {
  expanded = !expanded;
  const btn = document.getElementById('showMoreBtn');
  document.querySelectorAll('.log-row').forEach(row => {
    const idx     = parseInt(row.dataset.index);
    const visible = row.dataset.visible !== 'false';
    if (idx >= INITIAL) {
      row.style.display = (expanded && visible) ? 'table-row' : 'none';
    }
  });
  btn.textContent = expanded ? 'Show Less' : 'Show More (' + (TOTAL - INITIAL) + ' more)';
  updateCount();
}

function applyFilters() {
  const fUid    = document.getElementById('f_uid').value.trim().toLowerCase();
  const fIp     = document.getElementById('f_ip').value.trim().toLowerCase();
  const fAction = document.getElementById('f_action').value.toLowerCase();
  const fCompany = document.getElementById('f_company').value;

  document.querySelectorAll('.log-row').forEach(row => {
    const uid     = String(row.dataset.uid    || '').toLowerCase();
    const ip      = String(row.dataset.ip     || '').toLowerCase();
    const action  = String(row.dataset.action || '').toLowerCase();
    const idx     = parseInt(row.dataset.index);
    const matches =
      (fCompany === '' || row.dataset.company === fCompany) &&
      (fUid    === '' || uid.includes(fUid))    &&
      (fIp     === '' || ip.includes(fIp))      &&
      (fAction === '' || action.includes(fAction));
    row.dataset.visible = matches ? 'true' : 'false';
    row.style.display   = matches && (idx < INITIAL || expanded) ? 'table-row' : 'none';
  });
  updateCount();
}

function clearFilters() {
  document.getElementById('f_uid').value    = '';
  document.getElementById('f_ip').value     = '';
  document.getElementById('f_action').value = '';
  document.getElementById('f_company').value = '';
  document.querySelectorAll('.log-row').forEach(r => r.dataset.visible = 'true');
  applyFilters();
}

function updateCount() {
  const visible = document.querySelectorAll('.log-row[style*="table-row"]').length;
  const el = document.getElementById('visibleCount');
  if (el) el.textContent = visible;
}

applyFilters();
</script>

</body>
</html>
