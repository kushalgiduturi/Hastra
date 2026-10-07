<?php
// portals/sysadmin/roles.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "sysadmin");

$msg = "";
$PRIMARY_SYSADMIN_EMAIL = PRIMARY_SYSADMIN_EMAIL;

$primary_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email_bindex = ?");
$primary_email_bindex = astra_blind_index($PRIMARY_SYSADMIN_EMAIL);
mysqli_stmt_bind_param($primary_stmt, "s", $primary_email_bindex);
mysqli_stmt_execute($primary_stmt);
$primary_result = mysqli_stmt_get_result($primary_stmt);
$primary_row    = mysqli_fetch_assoc($primary_result);
$PRIMARY_SYSADMIN_ID = $primary_row["id"] ?? null;

// ── Remove an empty client company ────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST["action"] ?? "") === "remove_company") {
    verify_csrf_token();
    $cid = (int)($_POST["company_id"] ?? 0);
    $co  = company_schema_ready($conn) ? get_company($conn, $cid) : null;
    $cnt = mysqli_prepare($conn, "SELECT COUNT(*) FROM users WHERE company_id = ?");
    mysqli_stmt_bind_param($cnt, "i", $cid);
    mysqli_stmt_execute($cnt);
    mysqli_stmt_bind_result($cnt, $member_count);
    mysqli_stmt_fetch($cnt);
    mysqli_stmt_close($cnt);

    if (!$co || !empty($co["is_internal"])) {
        $msg = "That company can't be removed.";
    } elseif ($member_count > 0) {
        $msg = "{$co['company_name']} still has $member_count user(s). Delete or move them first.";
    } else {
        if (db_column_exists($conn, 'roster_imports', 'company_id')) {
            $ri = mysqli_prepare($conn, "DELETE s FROM roster_staging s JOIN roster_imports i ON i.id = s.import_id WHERE i.company_id = ?");
            mysqli_stmt_bind_param($ri, "i", $cid);
            mysqli_stmt_execute($ri);
            $rj = mysqli_prepare($conn, "DELETE FROM roster_imports WHERE company_id = ?");
            mysqli_stmt_bind_param($rj, "i", $cid);
            mysqli_stmt_execute($rj);
        }
        $del = mysqli_prepare($conn, "DELETE FROM companies WHERE id = ? AND is_internal = 0");
        mysqli_stmt_bind_param($del, "i", $cid);
        mysqli_stmt_execute($del);
        try { log_activity($conn, $_SESSION["user_id"], "company_removed"); } catch (Throwable $e) {}
        $msg = "Company {$co['company_name']} removed successfully. Its ID range and email domain are free again.";
    }
} elseif ($_SERVER["REQUEST_METHOD"] == "POST") {
    verify_csrf_token();

    $roles   = $_POST["roles"] ?? [];
    $updated = 0;
    $failed  = 0;

    foreach ($roles as $user_id => $new_role) {
        $user_id  = (int) $user_id;
        $new_role = trim($new_role);

        if ($user_id === (int) $_SESSION["user_id"]) continue;
        if ($PRIMARY_SYSADMIN_ID && $user_id === (int) $PRIMARY_SYSADMIN_ID) continue;

        $check = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
        mysqli_stmt_bind_param($check, "i", $user_id);
        mysqli_stmt_execute($check);
        $target = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
        if (!$target) continue;

        $company     = get_company($conn, $target["company_id"] ?? null);
        $is_internal = !$company || !empty($company["is_internal"]);
        $allowed     = $is_internal ? ["sysadmin", "admin", "employee"] : ["employee"];
        if (!in_array($new_role, $allowed, true)) continue;

        $current_role = ($target["role"] === "newuser") ? "user" : $target["role"];
        if ($current_role === $new_role) continue;

        if ($is_internal) {
            $range = in_array($new_role, ["sysadmin", "admin"], true) ? INTERNAL_ADMIN_RANGE : INTERNAL_EMPLOYEE_RANGE;
        } else {
            $range = user_id_range_for($company, $new_role);
        }
        $new_id = id_in_range($user_id, $range) ? $user_id : first_free_id($conn, $range);
        if ($new_id === null) { $failed++; continue; }
        if ($new_id !== $user_id && !move_user_id($conn, $user_id, $new_id)) { $failed++; continue; }

        $update = mysqli_prepare($conn, "UPDATE users SET role = ? WHERE id = ?");
        mysqli_stmt_bind_param($update, "si", $new_role, $new_id);
        mysqli_stmt_execute($update) ? $updated++ : $failed++;
    }

    if ($updated > 0 && $failed == 0)    $msg = "$updated user(s) updated successfully.";
    elseif ($updated > 0 && $failed > 0) $msg = "$updated updated. $failed failed (no free ID in that company's range).";
    else                                 $msg = "No changes were made.";
}

// ── Users grouped by company ─────────────────────────────────────────────────
$schema_ready = company_schema_ready($conn);
$STAFF_ROLES  = ['sysadmin', 'admin', 'employee', 'pending_employee'];
$role_order   = "FIELD(role,'sysadmin','admin','employee','pending_employee','client'), id";

$company_sections = [];
if ($schema_ready) {
    foreach (list_companies($conn) as $c) {
        $company_sections['c' . $c['id']] = [
            'key'      => 'c' . $c['id'],
            'id'       => (int)$c['id'],
            'name'     => $c['company_name'],
            'internal' => (bool)$c['is_internal'],
            'domain'   => '@' . $c['email_domain'],
            'range'    => company_range_label($c),
            'size'     => $c['size_band'] ?? null,
            'status'   => $c['is_internal'] ? null : ($c['onboarding_status'] ?? null),
            'users'    => [],
        ];
    }
    $company_sections['none'] = [
        'key' => 'none', 'name' => 'No company', 'internal' => false,
        'domain' => '-', 'range' => company_range_label(null), 'users' => [],
    ];
    $client_role_col = db_column_exists($conn, 'users', 'client_role') ? 'client_role' : 'NULL AS client_role';
    $res = mysqli_query($conn, "SELECT id, name, email, role, company_id, $client_role_col FROM users ORDER BY $role_order");
    while ($u = mysqli_fetch_assoc($res)) {
        astra_decrypt_user_row($u);
        $key = ($u['company_id'] && isset($company_sections['c' . $u['company_id']])) ? 'c' . $u['company_id'] : 'none';
        $company_sections[$key]['users'][] = $u;
    }
} else {
    $company_sections['staff'] = [
        'key' => 'staff', 'name' => INTERNAL_COMPANY_NAME, 'internal' => true,
        'domain' => EMPLOYEE_EMAIL_DOMAIN, 'range' => '1000-2999', 'users' => [],
    ];
    $company_sections['none'] = [
        'key' => 'none', 'name' => 'Clients', 'internal' => false,
        'domain' => '-', 'range' => '-', 'users' => [],
    ];
    $res = mysqli_query($conn, "SELECT id, name, email, role FROM users ORDER BY $role_order");
    while ($u = mysqli_fetch_assoc($res)) {
        astra_decrypt_user_row($u);
        $company_sections[in_array($u['role'], $STAFF_ROLES, true) ? 'staff' : 'none']['users'][] = $u;
    }
}
if (empty($company_sections['none']['users'])) unset($company_sections['none']);

$user_company_key = [];
$client_company_count = 0;
foreach ($company_sections as $sec) {
    if (!$sec['internal'] && $sec['key'] !== 'none') $client_company_count++;
    foreach ($sec['users'] as $u) $user_company_key[(int)$u['id']] = $sec['key'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Role Management · Hastra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>"><link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-contrast.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/custom-dropdowns.css?v=<?= ASSET_VERSION ?>">
<script src="<?= get_base_url() ?>assets/js/custom-dropdowns.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/gooey-search.css?v=<?= ASSET_VERSION ?>">
<script src="<?= get_base_url() ?>assets/js/gooey-search.js?v=<?= ASSET_VERSION ?>" defer></script>
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

  .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
  .stat-card { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; padding: 1.2rem 1.4rem; position: relative; overflow: hidden; }
  .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; }
  .stat-card.blue::before   { background: var(--blue-bright); }
  .stat-card.green::before  { background: var(--green); }
  .stat-card.yellow::before { background: var(--yellow); }
  .stat-card.purple::before { background: var(--purple); }
  .stat-label { font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px; }
  .stat-value { font-size: 28px; font-weight: 700; color: var(--text); line-height: 1; }

  .section { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; margin-bottom: 1.5rem; overflow: hidden; }
  .section-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.4rem; border-bottom: 1px solid var(--border-dim); background: var(--section-header-bg); }
  .section-title { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: var(--text); letter-spacing: 0.03em; text-transform: uppercase; }
  .section-title svg { width: 15px; height: 15px; fill: var(--accent-bright); }
  .section-body { padding: 1.4rem; }

  .migration-note { margin: 1rem 1.4rem 0; padding: 0.7rem 0.9rem; font-size: 12px; line-height: 1.6; color: var(--yellow); background: var(--yellow-bg); border: 1px solid rgba(245,158,11,0.25); border-radius: 3px; }
  .migration-note a { color: var(--text); font-weight: 600; }
  .company-index { display: flex; flex-wrap: wrap; gap: 8px; padding: 0.9rem 1.4rem; border-bottom: 1px solid var(--border-dim); }
  .company-chip { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; background: var(--input-bg); border: 1px solid var(--border-dim); border-radius: 3px; padding: 6px 12px; cursor: pointer; color: var(--text); text-align: left; font-family: var(--font-sans); transition: var(--transition); }
  .company-chip:hover { border-color: var(--border); }
  .company-chip.active { border-color: var(--accent-bright); background: rgba(var(--accent-rgb),0.12); }
  .company-chip:focus-visible, .role-filters .btn-clear:focus-visible { outline: 2px solid var(--accent-bright); outline-offset: 2px; }
  .chip-name { font-size: 13px; font-weight: 600; }
  .chip-meta { font-family: 'Share Tech Mono', monospace; font-size: 10px; color: var(--text-dim); letter-spacing: 0.04em; }
  .filters.user-search { padding: 0.6rem 1.4rem; border-bottom: 1px solid var(--border-dim); margin: 0; }
  .filters.user-search input { width: 260px; max-width: 100%; }
  .company-block { border: 1px solid var(--border-dim); border-radius: 4px; margin-bottom: 1.2rem; overflow: hidden; }
  .company-block:last-child { margin-bottom: 0; }
  .company-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; padding: 0.8rem 1rem; background: var(--section-header-bg); border-bottom: 1px solid var(--border-dim); }
  .company-name { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; font-size: 15px; font-weight: 600; }
  .company-meta { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 4px; font-family: 'Share Tech Mono', monospace; font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em; }
  .company-meta b { color: var(--text); font-weight: 400; text-transform: none; }
  .role-filters { display: flex; flex-wrap: wrap; gap: 6px; }
  .role-filters .btn-clear.active { background: rgba(var(--accent-rgb),0.15); color: var(--text); }
  .company-empty { padding: 1rem; font-size: 13px; color: var(--text-dim); }
  .cell-note { font-size: 12px; color: var(--text-dim); }
  .modal-done { text-align: center; color: var(--green); font-size: 14px; padding: 1rem 0; }

  .alert { display: flex; align-items: center; gap: 8px; border-radius: 3px; padding: 10px 14px; margin-bottom: 1.2rem; font-size: 13px; }
  .alert.success { background: var(--green-bg); border: 1px solid rgba(34,197,94,0.25); border-left: 3px solid var(--green); color: #86efac; }
  .alert.error { background: var(--red-bg); border: 1px solid rgba(var(--red-rgb),0.25); border-left: 3px solid var(--red); color: #fca5a5; }
  .alert svg { width: 14px; height: 14px; flex-shrink: 0; }
  .alert.success svg { fill: var(--green); }
  .alert.error svg   { fill: var(--red); }

  .tbl-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead tr { background: var(--section-header-bg); border-bottom: 1px solid var(--border-dim); }
  th { padding: 10px 14px; text-align: left; font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); font-weight: 400; white-space: nowrap; }
  tbody tr { border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.15s; }
  tbody tr:hover { background: var(--hover-bg); }
  tbody tr:last-child { border-bottom: none; }
  td { padding: 11px 14px; color: var(--text); vertical-align: middle; }
  td.muted { color: var(--text-dim); font-family: 'Share Tech Mono', monospace; font-size: 12px; }
  tbody tr.changed { background: var(--yellow-bg) !important; }

  .badge { display: inline-block; padding: 2px 8px; border-radius: 2px; font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.05em; text-transform: uppercase; font-weight: 500; }
  .badge-sysadmin { background: rgba(var(--purple-rgb),0.15); color: var(--purple);      border: 1px solid rgba(var(--purple-rgb),0.2); }
  .badge-admin    { background: rgba(34,197,94,0.1);    color: var(--green);       border: 1px solid rgba(34,197,94,0.2); }
  .badge-employee { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .badge-user,
  .badge-newuser  { background: rgba(148,163,184,0.08); color: var(--text-dim);    border: 1px solid rgba(148,163,184,0.15); }
  .badge-client { background: rgba(var(--purple-rgb),0.1); color: var(--purple); border: 1px solid rgba(var(--purple-rgb),0.2); }
  .badge-pending_employee { background: rgba(245,158,11,0.1); color: var(--yellow); border: 1px solid rgba(245,158,11,0.2); }
  .badge-success  { background: rgba(34,197,94,0.1);    color: var(--green);       border: 1px solid rgba(34,197,94,0.2); }
  .badge-warning  { background: rgba(245,158,11,0.1);   color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }
  .badge-danger   { background: rgba(var(--red-rgb),0.1);    color: var(--red);         border: 1px solid rgba(var(--red-rgb),0.2); }
  .badge-info     { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }

  .role-select { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 3px; color: var(--text); font-family: var(--font-sans); font-size: 12px; padding: 5px 8px; outline: none; cursor: pointer; transition: border-color 0.2s; appearance: auto; }
  .role-select option { background: var(--navy-card); color: var(--text); }
  .role-select:focus  { border-color: var(--accent-bright); background: var(--navy-mid); }

  .btn-save { background: var(--accent); color: white; border: none; border-radius: 3px; font-family: var(--font-sans); font-size: 13px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; padding: 9px 20px; cursor: pointer; transition: background 0.2s, box-shadow 0.2s; display: flex; align-items: center; gap: 6px; }
  .btn-save svg { width: 14px; height: 14px; fill: white; }
  .btn-save:hover { background: var(--accent-dim); box-shadow: 0 0 16px rgba(var(--accent-rgb),0.3); }

  .filters { display: flex; gap: 8px; margin-bottom: 1rem; flex-wrap: wrap; align-items: center; }
  .filters input, .filters select { background: var(--input-bg); border: 1px solid var(--border-dim); border-radius: 3px; color: var(--text); font-family: var(--font-sans); font-size: 12px; padding: 7px 10px; outline: none; transition: border-color 0.2s; }
  .filters input:focus, .filters select:focus { border-color: var(--accent-bright); }
  .filters input { width: 160px; }
  .filters input::placeholder { color: var(--text-dim); }
  .filters select option { background: var(--navy-card); }

  .btn-clear { background: var(--input-bg); border: 1px solid var(--border-dim); border-radius: 3px; color: var(--text-dim); font-family: var(--font-sans); font-size: 12px; padding: 7px 12px; cursor: pointer; transition: background 0.2s, color 0.2s; }
  .btn-clear:hover { background: rgba(255,255,255,0.08); color: var(--text); }

  .own-row td { color: var(--text-dim); font-size: 12px; font-style: italic; }

  .btn-delete { background: rgba(var(--red-rgb),0.1); border: 1px solid rgba(var(--red-rgb),0.25); color: #fca5a5; font-family: var(--font-sans); font-size: 12px; font-weight: 500; padding: 5px 12px; border-radius: 3px; cursor: pointer; transition: background 0.2s, border-color 0.2s; }
  .btn-delete:hover { background: rgba(var(--red-rgb),0.2); border-color: rgba(var(--red-rgb),0.5); }

  .modal-overlay { display: flex; position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 200; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s ease; }
  .modal-overlay.open { opacity: 1; pointer-events: auto; }
  .modal { background: var(--navy-card); border: 1px solid var(--border); border-radius: 4px; width: 100%; max-width: 420px; padding: 2rem; position: relative; }
  .modal::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--red), transparent); border-radius: 4px 4px 0 0; }
  .modal h3 { font-size: 16px; font-weight: 600; color: var(--text); margin-bottom: 0.4rem; }
  .modal p { font-size: 13px; color: var(--text-dim); margin-bottom: 1.4rem; line-height: 1.5; }
  .modal-alert { font-size: 12px; padding: 8px 12px; border-radius: 3px; margin-bottom: 1.2rem; display: none; line-height: 1.4; }
  .modal-alert.error   { background: var(--red-bg); border: 1px solid rgba(var(--red-rgb),0.3); border-left: 3px solid var(--red); color: #fca5a5; display: block; }
  .modal-alert.success { background: var(--green-bg); border: 1px solid rgba(34,197,94,0.3); border-left: 3px solid var(--green); color: #86efac; display: block; }

  .otp-boxes { display: flex; gap: 8px; justify-content: center; margin-bottom: 1.4rem; }
  .otp-box { width: 48px; height: 54px; background: var(--input-bg); border: 1px solid var(--border-dim); border-radius: 4px; color: var(--text); font-family: 'Share Tech Mono', monospace; font-size: 22px; text-align: center; outline: none; transition: border-color 0.2s, box-shadow 0.2s; caret-color: var(--red); }
  .otp-box:focus { border-color: var(--red); box-shadow: 0 0 0 3px rgba(var(--red-rgb),0.12); }
  .otp-box.filled { border-color: rgba(var(--red-rgb),0.4); background: rgba(var(--red-rgb),0.05); }

  .modal-btns { display: flex; gap: 8px; }
  .modal-btn-confirm { flex: 1; background: var(--red); color: white; border: none; border-radius: 3px; font-family: var(--font-sans); font-size: 13px; font-weight: 600; padding: 10px; cursor: pointer; transition: background 0.2s, filter 0.2s; text-transform: uppercase; }
  .modal-btn-confirm:hover { filter: brightness(0.88); }
  .modal-btn-confirm:disabled { background: #4b1c1c; color: #6b2f2f; cursor: not-allowed; }
  .modal-btn-cancel { flex: 1; background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text-dim); border-radius: 3px; font-family: var(--font-sans); font-size: 13px; font-weight: 500; padding: 10px; cursor: pointer; transition: background 0.2s, filter 0.2s; text-transform: uppercase; }
  .modal-btn-cancel:hover { background: rgba(255,255,255,0.08); color: var(--text); }

  .sending-spinner { text-align: center; padding: 1.5rem 0; color: var(--text-dim); font-size: 13px; }
  .sending-spinner::after { content: ''; display: block; width: 28px; height: 28px; border: 2px solid var(--border-dim); border-top-color: var(--red); border-radius: 50%; margin: 12px auto 0; animation: spin 0.8s linear infinite; }
  @keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>

<?php $nav_current = 'roles'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>Role Management</h1>
    <p>Manage user roles across companies.</p>
  </div>

  <?php
  $all_users_arr = [];
  $tmp = mysqli_query($conn, "SELECT role FROM users");
  while ($r = mysqli_fetch_assoc($tmp)) $all_users_arr[] = $r['role'];
  $total_users    = count($all_users_arr);
  $count_admin    = count(array_filter($all_users_arr, fn($r) => $r === 'admin'));
  $count_employee = count(array_filter($all_users_arr, fn($r) => $r === 'employee'));
  $count_client   = count(array_filter($all_users_arr, fn($r) => $r === 'client'));
?>
  <div class="stats-row">
    <div class="stat-card blue">
      <div class="stat-label">Total Users</div>
      <div class="stat-value"><?= $total_users ?></div>
    </div>
    <div class="stat-card green">
      <div class="stat-label">Admins</div>
      <div class="stat-value"><?= $count_admin ?></div>
    </div>
    <div class="stat-card yellow">
      <div class="stat-label">Employees</div>
      <div class="stat-value"><?= $count_employee ?></div>
    </div>
    <div class="stat-card purple">
      <div class="stat-label">Clients</div>
      <div class="stat-value"><?= $count_client ?></div>
    </div>
    <div class="stat-card blue">
      <div class="stat-label">Client Companies</div>
      <div class="stat-value"><?= $client_company_count ?></div>
    </div>
  </div>

  <div class="section" id="sec-roles">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
        Role Management
      </div>
      <button type="submit" form="roleForm" class="btn-save">
        <svg viewBox="0 0 24 24"><path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/></svg>
        Save All Changes
      </button>
    </div>

    <?php if (!$schema_ready): ?>
    <div class="migration-note">
      Companies aren't set up in the database yet, so users are split into staff and clients only.
      <a href="<?= get_base_url() ?>workspace/sysadmin/migrate">Run the database migration</a> once to enable company sections, domains and ID ranges.
    </div>
    <?php endif; ?>

    <div class="company-index" aria-label="Companies">
      <button type="button" class="company-chip active" data-target="all" onclick="showCompany('all')">
        <span class="chip-name">All companies</span>
        <span class="chip-meta"><?= count($user_company_key) ?> users</span>
      </button>
      <?php foreach ($company_sections as $sec): ?>
      <button type="button" class="company-chip" data-target="<?= $sec['key'] ?>" onclick="showCompany('<?= $sec['key'] ?>')">
        <span class="chip-name"><?= htmlspecialchars($sec['name']) ?></span>
        <span class="chip-meta"><?= htmlspecialchars($sec['range']) ?> · <?= count($sec['users']) ?></span>
      </button>
      <?php endforeach; ?>
    </div>
    <div class="filters user-search">
      <?php render_gooey_search('userSearch', 'Search name, email or ID', 'filterUsers()'); ?>
    </div>
    <div class="section-body">

      <?php if ($msg): ?>
      <?php $is_ok = strpos($msg, 'successfully') !== false; ?>
      <div class="alert <?= $is_ok ? 'success' : 'error' ?>">
        <svg viewBox="0 0 24 24">
          <?php if ($is_ok): ?>
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
          <?php else: ?>
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
          <?php endif; ?>
        </svg>
        <?= htmlspecialchars($msg) ?>
      </div>
      <?php endif; ?>

      <form method="POST" action="roles" id="roleForm">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

        <?php foreach ($company_sections as $sec):
          $role_counts = array_count_values(array_column($sec['users'], 'role'));
        ?>
        <div class="company-block" id="company-<?= $sec['key'] ?>" data-company="<?= $sec['key'] ?>">
          <div class="company-head">
            <div class="company-ident">
              <div class="company-name">
                <?= htmlspecialchars($sec['name']) ?>
                <?php if ($sec['internal']): ?>
                  <span class="badge badge-info">Internal</span>
                <?php elseif ($sec['key'] === 'none'): ?>
                  <span class="badge badge-user">Unassigned</span>
                <?php else: ?>
                  <span class="badge badge-client">Client company</span>
                <?php endif; ?>
              </div>
              <div class="company-meta">
                <span>Domain <b><?= htmlspecialchars($sec['domain']) ?></b></span>
                <span>IDs <b><?= htmlspecialchars($sec['range']) ?></b></span>
                <span>Users <b><?= count($sec['users']) ?></b></span>
                <?php if (!empty($sec['size'])): ?><span>Size <b><?= htmlspecialchars($sec['size']) ?></b></span><?php endif; ?>
                <?php if (!empty($sec['status'])): ?><span>Onboarding <b><?= htmlspecialchars(str_replace('_', ' ', $sec['status'])) ?></b></span><?php endif; ?>
                <?php foreach ($role_counts as $r => $n): ?>
                  <span><?= htmlspecialchars(str_replace('_', ' ', $r)) ?> <b><?= $n ?></b></span>
                <?php endforeach; ?>
              </div>
            </div>
            <?php if (!$sec['internal'] && !empty($sec['id']) && !$sec['users']): ?>
            <button type="submit" form="removeCompany-<?= $sec['id'] ?>" class="btn-delete">Remove company</button>
            <?php endif; ?>
            <?php if (count($role_counts) > 1): ?>
            <div class="role-filters" data-company="<?= $sec['key'] ?>">
              <button type="button" class="btn-clear active" data-role="all" onclick="filterRole('<?= $sec['key'] ?>', 'all')">All</button>
              <?php foreach (['sysadmin' => 'Sysadmin', 'admin' => 'Admin', 'employee' => 'Employee', 'pending_employee' => 'Pending Approval', 'client' => 'Client'] as $r => $label):
                if (empty($role_counts[$r])) continue; ?>
              <button type="button" class="btn-clear" data-role="<?= $r ?>" onclick="filterRole('<?= $sec['key'] ?>', '<?= $r ?>')"><?= $label ?></button>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>

          <?php if (!$sec['users']): ?>
          <p class="company-empty">No users in this company yet.</p>
          <?php else: ?>
          <div class="tbl-wrap">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Current Role</th>
                  <th>Change Role</th>
                  <th>Delete</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($sec['users'] as $row):
                  $is_own       = ($row["id"] == $_SESSION["user_id"]);
                  $is_primary   = ($PRIMARY_SYSADMIN_ID && $row["id"] == $PRIMARY_SYSADMIN_ID);
                  $is_protected = $is_own || $is_primary;
                  $is_staff     = in_array($row['role'], $STAFF_ROLES, true);
                  $search       = strtolower($row['id'] . ' ' . $row['name'] . ' ' . $row['email']);
                ?>
                <tr id="row-<?= $row['id'] ?>" class="<?= $is_own ? 'own-row' : '' ?>"
                    data-role="<?= htmlspecialchars($row['role']) ?>"
                    data-search="<?= htmlspecialchars($search) ?>">
                  <td class="muted"><?= htmlspecialchars($row["id"]) ?></td>
                  <td><?= htmlspecialchars($row["name"]) ?></td>
                  <td class="muted"><?= htmlspecialchars($row["email"]) ?></td>
                  <td>
                    <span class="badge badge-<?= htmlspecialchars($row['role']) ?>">
                      <?= htmlspecialchars(str_replace('_', ' ', $row["role"])) ?>
                    </span>
                    <?php if (!empty($row['client_role'])): ?>
                      <div class="cell-note" style="margin-top:4px;"><?= htmlspecialchars(client_role_label($row['client_role'])) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($is_own): ?>
                      <span class="cell-note">(your account)</span>
                    <?php elseif ($is_primary): ?>
                      <span class="cell-note">&#128274; Protected</span>
                    <?php elseif ($is_staff && $row['role'] === 'pending_employee'): ?>
                      <div style="display:flex; flex-direction:column; gap:6px;">
                        <select class="role-select assign-toggle"
                          onchange="toggleAssignRole(this, <?= $row['id'] ?>)">
                          <option value="no" selected>No: leave pending</option>
                          <option value="yes">Yes: assign role</option>
                        </select>
                        <select class="role-select" id="roleSelect-<?= $row['id'] ?>"
                          name="roles[<?= $row['id'] ?>]"
                          data-original="<?= htmlspecialchars($row['role']) ?>"
                          onchange="highlightRow(this, <?= $row['id'] ?>)"
                          style="display:none;" disabled>
                          <option value="employee">Employee</option>
                          <?php if ($sec['internal']): ?>
                          <option value="admin">Admin</option>
                          <option value="sysadmin">Sysadmin</option>
                          <?php endif; ?>
                        </select>
                      </div>
                    <?php elseif ($is_staff && $sec['internal']): ?>
                      <select class="role-select" name="roles[<?= $row['id'] ?>]"
                        data-original="<?= htmlspecialchars($row['role']) ?>"
                        onchange="highlightRow(this, <?= $row['id'] ?>)">
                        <option value="sysadmin" <?= $row['role'] === 'sysadmin' ? 'selected' : '' ?>>Sysadmin</option>
                        <option value="admin"    <?= $row['role'] === 'admin'    ? 'selected' : '' ?>>Admin</option>
                        <option value="employee" <?= $row['role'] === 'employee' ? 'selected' : '' ?>>Employee</option>
                      </select>
                    <?php else: ?>
                      <span class="cell-note">-</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($is_protected): ?>
                      <span class="cell-note">&#128274; Protected</span>
                    <?php else: ?>
                      <button type="button" class="btn-delete"
                        onclick="requestDelete(<?= $row['id'] ?>, '<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>')">
                        Delete
                      </button>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </form>
      <?php foreach ($company_sections as $sec): if (!$sec['internal'] && !empty($sec['id']) && !$sec['users']): ?>
      <form method="POST" action="roles" id="removeCompany-<?= $sec['id'] ?>"
            onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($sec['name']), ENT_QUOTES) ?>? It has no users. This can\'t be undone.')">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action" value="remove_company">
        <input type="hidden" name="company_id" value="<?= $sec['id'] ?>">
      </form>
      <?php endif; endforeach; ?>

    </div>
  </div>

</div>

<!-- ── DELETE MODAL ── -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal">
    <h3 id="modalTitle">Confirm Deletion</h3>
    <p id="modalDesc">Sending OTP to your email for verification...</p>
    <div class="modal-alert" id="modalAlert"></div>

    <div id="stepSending" class="sending-spinner" style="display:none;">Sending OTP to your email</div>

    <div id="stepOtp" style="display:none;">
      <div class="otp-boxes">
        <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="ob0">
        <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="ob1">
        <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="ob2">
        <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="ob3">
        <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="ob4">
        <input class="otp-box" type="text" maxlength="1" inputmode="numeric" id="ob5">
      </div>
      <div class="modal-btns">
        <button class="modal-btn-confirm" id="btnVerifyOtp" onclick="verifyOtp()" disabled>Confirm Delete</button>
        <button class="modal-btn-cancel" onclick="closeModal()">Cancel</button>
      </div>
    </div>

    <div id="stepDone" style="display:none;">
      <p class="modal-done">Account deleted. Refreshing…</p>
    </div>
  </div>
</div>

<script>
function highlightRow(select, rowId) {
  const row = document.getElementById('row-' + rowId);
  if (!row) return;
  row.classList.toggle('changed', select.value !== select.dataset.original);
}

function toggleAssignRole(toggleSelect, rowId) {
  const target = document.getElementById('roleSelect-' + rowId);
  const row     = document.getElementById('row-' + rowId);
  const show    = toggleSelect.value === 'yes';

  target.style.display = show ? 'inline-block' : 'none';
  target.disabled       = !show;

  if (show) {
    highlightRow(target, rowId);
  } else if (row) {
    row.classList.remove('changed');
  }
}

// ── OTP boxes ─────────────────────────────────────────────────────────────────
const otpBoxes = Array.from(document.querySelectorAll('.otp-box'));
otpBoxes.forEach((box, idx) => {
  box.addEventListener('input', () => {
    box.value = box.value.replace(/[^0-9]/g, '');
    box.classList.toggle('filled', box.value !== '');
    if (box.value && idx < 5) otpBoxes[idx + 1].focus();
    updateVerifyBtn();
  });
  box.addEventListener('keydown', e => {
    if (e.key === 'Backspace' && !box.value && idx > 0) {
      otpBoxes[idx - 1].value = '';
      otpBoxes[idx - 1].classList.remove('filled');
      otpBoxes[idx - 1].focus();
      updateVerifyBtn();
    }
  });
  box.addEventListener('paste', e => {
    e.preventDefault();
    const pasted = (e.clipboardData || window.clipboardData)
      .getData('text').replace(/[^0-9]/g, '').slice(0, 6);
    pasted.split('').forEach((char, i) => {
      if (otpBoxes[i]) { otpBoxes[i].value = char; otpBoxes[i].classList.add('filled'); }
    });
    if (otpBoxes[Math.min(pasted.length, 5)]) otpBoxes[Math.min(pasted.length, 5)].focus();
    updateVerifyBtn();
  });
});

function getOtpValue() { return otpBoxes.map(b => b.value).join(''); }
function updateVerifyBtn() {
  const btn = document.getElementById('btnVerifyOtp');
  if (btn) btn.disabled = getOtpValue().length < 6;
}

function clearOtpBoxes() {
  otpBoxes.forEach(b => { b.value = ''; b.classList.remove('filled'); });
  updateVerifyBtn();
}

// ── Delete flow ───────────────────────────────────────────────────────────────
let currentDeletionId = null;
const CSRF_TOKEN = "<?= generate_csrf_token() ?>";

function requestDelete(targetId, targetName) {
  currentDeletionId = null;
  clearOtpBoxes();

  document.getElementById('modalTitle').textContent  = 'Delete: ' + targetName;
  document.getElementById('modalDesc').textContent   = 'An OTP is being sent to your email to confirm this deletion.';
  document.getElementById('modalAlert').className    = 'modal-alert';
  document.getElementById('modalAlert').textContent  = '';
  document.getElementById('stepSending').style.display   = 'block';
  document.getElementById('stepOtp').style.display       = 'none';
  document.getElementById('stepDone').style.display      = 'none';
  document.getElementById('deleteModal').classList.add('open');

  fetch('delete-user', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'request_delete', target_id: targetId })
  })
  .then(r => r.json())
  .then(data => {
    document.getElementById('stepSending').style.display = 'none';
    if (data.success) {
      currentDeletionId = data.target_id;
      document.getElementById('modalDesc').textContent = 'Enter the 6-digit OTP sent to your email to confirm deletion of this account.';
      document.getElementById('stepOtp').style.display = 'block';
      otpBoxes[0].focus();
    } else {
      showModalAlert('error', data.message);
    }
  })
  .catch(() => showModalAlert('error', 'Request failed. Please try again.'));
}

function verifyOtp() {
  const otp = getOtpValue();
  if (otp.length < 6) return;

  const btn = document.getElementById('btnVerifyOtp');
  btn.disabled = true;
  btn.textContent = 'Verifying...';

  fetch('delete-user', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'verify_otp', target_id: currentDeletionId, otp: otp })
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.textContent = 'Confirm Delete';
    if (data.success) {
      document.getElementById('stepOtp').style.display  = 'none';
      document.getElementById('stepDone').style.display = 'block';
      document.getElementById('modalDesc').textContent  = data.message;
      setTimeout(() => { closeModal(); location.reload(); }, 1500);
    } else {
      showModalAlert('error', data.message);
      clearOtpBoxes();
      otpBoxes[0].focus();
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Confirm Delete';
    showModalAlert('error', 'Request failed. Please try again.');
  });
}

function closeModal() {
  document.getElementById('deleteModal').classList.remove('open');
}

function showModalAlert(type, msg) {
  const el = document.getElementById('modalAlert');
  el.className   = 'modal-alert ' + type;
  el.textContent = msg;
}

// ── Company index ─────────────────────────────────────────────────────────────
const roleFilter = {};

function showCompany(key) {
  document.querySelectorAll('.company-chip').forEach(chip => {
    chip.classList.toggle('active', chip.dataset.target === key);
  });
  document.querySelectorAll('.company-block').forEach(block => {
    block.hidden = key !== 'all' && block.dataset.company !== key;
  });
}

function filterRole(company, role) {
  roleFilter[company] = role;
  document.querySelectorAll('.role-filters[data-company="' + company + '"] .btn-clear').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.role === role);
  });
  filterUsers();
}

function filterUsers() {
  const q = document.getElementById('userSearch').value.trim().toLowerCase();
  document.querySelectorAll('.company-block').forEach(block => {
    const role = roleFilter[block.dataset.company] || 'all';
    block.querySelectorAll('tr[data-search]').forEach(row => {
      const roleOk   = role === 'all' || row.dataset.role === role;
      const searchOk = q === '' || row.dataset.search.includes(q);
      row.style.display = roleOk && searchOk ? 'table-row' : 'none';
    });
  });
}

showCompany('all');
</script>

</body>
</html>
