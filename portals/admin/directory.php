<?php
// portals/admin/directory.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "admin");

$dir_msg = "";

// ── ACTION: toggle_maternity_eligible ──────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "toggle_maternity_eligible") {
    verify_csrf_token();
    $target_id = (int)($_POST["user_id"] ?? 0);
    $eligible  = ($_POST["eligible"] ?? "") === "1" ? 1 : 0;

    // gender is encrypted, so it can't be filtered in SQL — fetch by
    // id+role, then check the decrypted value in PHP.
    $chk = mysqli_prepare($conn, "SELECT gender FROM users WHERE id = ? AND role = 'employee'");
    mysqli_stmt_bind_param($chk, "i", $target_id);
    mysqli_stmt_execute($chk);
    $chk_row = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    astra_decrypt_user_row($chk_row);

    if ($target_id && $chk_row && $chk_row['gender'] === 'female') {
        $upd = mysqli_prepare($conn, "UPDATE users SET maternity_leave_eligible = ? WHERE id = ?");
        mysqli_stmt_bind_param($upd, "ii", $eligible, $target_id);
        mysqli_stmt_execute($upd);
        $dir_msg = "Maternity leave eligibility updated.";
    }
}

$users = mysqli_query($conn, company_schema_ready($conn)
    ? "SELECT u.id, u.name, u.email, u.phone_number, u.role, u.gender, u.maternity_leave_eligible, c.company_name
       FROM users u LEFT JOIN companies c ON c.id = u.company_id
       WHERE u.role IN ('employee','pending_employee','client')
       ORDER BY u.id ASC"
    : "SELECT id, name, email, phone_number, role, gender, maternity_leave_eligible, NULL AS company_name
       FROM users
       WHERE role IN ('employee','pending_employee','client')
       ORDER BY id ASC"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Directory · Hastra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
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
  .page-header h1 { font-size: 24px; font-weight: 600; letter-spacing: -0.01em; margin-bottom: 4px; }
  .page-header p { font-size: 13px; color: var(--text-dim); }

  .section { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; overflow: hidden; margin-bottom: 1.5rem; }
  .section-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.4rem; border-bottom: 1px solid var(--border-dim); background: var(--section-header-bg); }
  .section-title { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; }
  .section-title svg { width: 15px; height: 15px; fill: var(--accent-bright); }

  .tbl-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead tr { background: var(--section-header-bg); border-bottom: 1px solid var(--border-dim); }
  th { padding: 10px 14px; text-align: left; font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-dim); font-weight: 400; white-space: nowrap; }
  tbody tr { border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.15s; }
  tbody tr:hover { background: var(--hover-bg); }
  tbody tr:last-child { border-bottom: none; }
  td { padding: 11px 14px; color: var(--text); vertical-align: middle; }
  td.muted { color: var(--text-dim); font-family: 'Share Tech Mono', monospace; font-size: 12px; }

  .badge { display: inline-block; padding: 2px 8px; border-radius: 2px; font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.05em; text-transform: uppercase; font-weight: 500; }
  .badge-employee         { background: rgba(59,130,246,0.1);   color: var(--blue-bright); border: 1px solid rgba(59,130,246,0.2); }
  .badge-pending_employee { background: rgba(245,158,11,0.1);   color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }
  .badge-client           { background: rgba(var(--purple-rgb),0.1);  color: var(--purple);      border: 1px solid rgba(var(--purple-rgb),0.2); }
</style>
</head>
<body>

<?php $nav_current = 'directory'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>User Directory</h1>
    <p>Browse employees, pending accounts, and clients.</p>
  </div>

  <?php if ($dir_msg): ?>
  <div class="alert success" style="display:flex;align-items:flex-start;gap:8px;border-radius:3px;padding:10px 14px;margin-bottom:1.2rem;font-size:13px;border-left:3px solid var(--green);background:var(--green-bg);color:#86efac;line-height:1.5;">
    <span><?= htmlspecialchars($dir_msg) ?></span>
  </div>
  <?php endif; ?>

  <div class="section" id="sec-directory">
    <div class="section-header">
      <div class="section-title">
        <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
        User Directory
      </div>
    </div>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>ID</th><th>Name</th><th>Email</th><th>Company</th><th>Phone</th><th>Role</th><th>Maternity Leave</th></tr>
        </thead>
        <tbody>
          <?php while ($row = mysqli_fetch_assoc($users)): astra_decrypt_user_row($row); ?>
          <tr>
            <td class="muted"><?= htmlspecialchars($row['id']) ?></td>
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td class="muted"><?= htmlspecialchars($row['email']) ?></td>
            <td><?= htmlspecialchars($row['company_name'] ?? '-') ?></td>
            <td class="muted"><?= htmlspecialchars(astra_db_decrypt($row['phone_number']) ?? '-') ?></td>
            <td><span class="badge badge-<?= htmlspecialchars($row['role']) ?>"><?= htmlspecialchars($row['role']) ?></span></td>
            <td>
              <?php if ($row['role'] === 'employee' && $row['gender'] === 'female'): ?>
              <form method="POST" action="directory" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="toggle_maternity_eligible">
                <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                <input type="hidden" name="eligible" value="<?= $row['maternity_leave_eligible'] ? '0' : '1' ?>">
                <button type="submit" class="action-btn <?= $row['maternity_leave_eligible'] ? 'approve' : '' ?>" style="padding:4px 10px;font-size:11px;border-radius:3px;border:1px solid var(--border-dim);background:<?= $row['maternity_leave_eligible'] ? 'rgba(34,197,94,0.1)' : 'var(--input-bg)' ?>;color:<?= $row['maternity_leave_eligible'] ? 'var(--green)' : 'var(--text-dim)' ?>;cursor:pointer;">
                  <?= $row['maternity_leave_eligible'] ? 'Eligible ✓' : 'Mark Eligible' ?>
                </button>
              </form>
              <?php else: ?>
              <span class="muted">-</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

</body>
</html>
