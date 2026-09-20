<?php
include __DIR__ . '/../core/db.php';
secure_session_start();
verify_session($conn);

$stmt = mysqli_prepare($conn, 'SELECT role, name FROM users WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'i', $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
$role = $user['role'] ?? ($_SESSION['user_role'] ?? 'newuser');
$name = $user['name'] ?? ($_SESSION['user_name'] ?? 'Member');

// Each entry: [title, description, link, icon key]
$pages = [
  'sysadmin' => [
    ['Access management', 'Manage user accounts and roles.', 'sysadmin/sysadmin_portal', 'shield'],
    ['My profile', 'Manage your account details.', 'user/profile', 'user'],
  ],
  'admin' => [
    ['Requirements', 'Review client requirements.', 'admin/admin_portal?view=requirements', 'clipboard'],
    ['Deployment approvals', 'Approve or return release requests.', 'admin/admin_portal?view=deployments', 'check'],
    ['Testing overview', 'Monitor bugs and test status.', 'admin/admin_portal?view=testing', 'bug'],
    ['Delivery', 'Publish project handover details.', 'admin/admin_portal?view=delivery', 'send'],
    ['Billing', 'Generate and manage invoices.', 'admin/admin_portal?view=billing', 'receipt'],
    ['Employees', 'Create accounts and manage the directory.', 'admin/admin_portal?view=employees', 'users'],
    ['Projects', 'Create projects, organise teams and follow progress.', 'admin/project_portal', 'folder'],
    ['My profile', 'Manage your account details.', 'user/profile', 'user'],
  ],
  'employee' => [
    ['My work', 'View assigned tasks and project activity.', 'emlpoyee/employee_portal', 'clipboard'],
    ['Testing & bugs', 'Report, review and verify issues.', 'emlpoyee/testing_portal', 'bug'],
    ['Deployment', 'Prepare approved work for release.', 'emlpoyee/deployment_portal', 'send'],
    ['My profile', 'Manage your account details.', 'user/profile', 'user'],
  ],
  'client' => [
    ['Project hub', 'Submit requirements and track project updates.', 'client/client_portal', 'briefcase'],
    ['My profile', 'Manage your account details.', 'user/profile', 'user'],
  ],
  'pending_employee' => [
    ['Account status', 'Check the status of your access request.', 'user/newuser_portal', 'info'],
    ['My profile', 'Manage your account details.', 'user/profile', 'user'],
  ],
  'newuser' => [
    ['Account status', 'Check the status of your access request.', 'user/newuser_portal', 'info'],
    ['My profile', 'Manage your account details.', 'user/profile', 'user'],
  ],
];
$items = $pages[$role] ?? $pages['newuser'];

$role_labels = [
  'sysadmin'         => 'System Administrator',
  'admin'            => 'Administrator',
  'employee'         => 'Employee',
  'client'           => 'Client',
  'pending_employee' => 'Pending Employee',
  'newuser'          => 'New User',
];
$role_label = $role_labels[$role] ?? 'Member';

$ICONS = [
  'shield'    => '<path d="M23 5.5V20c0 2.2-1.8 4-4 4h-7.3c-1.08 0-2.1-.43-2.85-1.19L1 14.83s1.26-1.23 1.3-1.25c.22-.19.49-.29.79-.29.22 0 .42.06.6.16.04.01 4.31 2.46 4.31 2.46V4c0-.83.67-1.5 1.5-1.5S11 3.17 11 4v7h1V1.5c0-.83.67-1.5 1.5-1.5S15 .67 15 1.5V11h1V2.5c0-.83.67-1.5 1.5-1.5s1.5.67 1.5 1.5V11h1V5.5c0-.83.67-1.5 1.5-1.5s1.5.67 1.5 1.5z"/>',
  'user'      => '<path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zm0 2c-3.9 0-9.5 1.96-9.5 5.5V21h19v-1.5c0-3.54-5.6-5.5-9.5-5.5z"/>',
  'clipboard' => '<path d="M15 2H9a1 1 0 0 0-1 1H6a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-2a1 1 0 0 0-1-1zM8 10h8v2H8v-2zm0 4h8v2H8v-2zm0-8h8v2H8V6z"/>',
  'check'     => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm-1.4 14.7-4.2-4.2 1.5-1.5 2.7 2.7 5.9-5.9 1.5 1.5z"/>',
  'bug'       => '<path d="M9 5.2V4a3 3 0 0 1 6 0v1.2c.8.4 1.5 1 2 1.7l1.6-1.6 1.4 1.4-1.8 1.8c.2.6.3 1.3.3 1.9h2v2h-2c0 .7-.1 1.3-.3 1.9l1.8 1.8-1.4 1.4-1.6-1.6c-.5.7-1.2 1.3-2 1.7V19a3 3 0 0 1-6 0v-1.3c-.8-.4-1.5-1-2-1.7l-1.6 1.6-1.4-1.4 1.8-1.8A5.9 5.9 0 0 1 5 12H3v-2h2c0-.6.1-1.3.3-1.9L3.5 6.3l1.4-1.4L6.5 6.5c.5-.7 1.2-1.3 2-1.7zM9 10v6a3 3 0 0 0 6 0v-6H9z"/>',
  'send'      => '<path d="M2 21l21-9L2 3v7l15 2-15 2z"/>',
  'receipt'   => '<path d="M6 2h12v20l-2.5-1.5L14 22l-2-1.5L10 22l-2.5-1.5L6 22V2zm2 5h8V5H8v2zm0 4h8V9H8v2zm0 4h5v-2H8v2z"/>',
  'users'     => '<path d="M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm7-1.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM9 14c-3.3 0-8 1.7-8 5v2h12.7c-.4-.7-.7-1.5-.7-2.4 0-2.2 1.5-4 3.6-4.7A14 14 0 0 0 9 14zm7.4.3c-.5 0-1 .1-1.5.2 1.4.9 2.3 2.4 2.3 4.1 0 .8-.2 1.6-.5 2.4H23v-1.7c0-2.5-3.9-5-6.6-5z"/>',
  'folder'    => '<path d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8l-2-2z"/>',
  'briefcase' => '<path d="M9 3a2 2 0 0 0-2 2v1H4a2 2 0 0 0-2 2v3h20V8a2 2 0 0 0-2-2h-3V5a2 2 0 0 0-2-2H9zm0 2h6v1H9V5zM2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6H2zm8 1h4v2h-4v-2z"/>',
  'info'      => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15h-2v-6h2zm0-8h-2V7h2z"/>',
];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script><link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
body.workspace-index{min-height:100vh;margin:0;background-color:var(--navy);background-image:linear-gradient(var(--grid-line) 1px,transparent 1px),linear-gradient(90deg,var(--grid-line) 1px,transparent 1px);background-size:40px 40px;color:var(--text);font-family:Inter,system-ui,sans-serif}
.workspace-index .bar{height:64px;display:flex;align-items:center;justify-content:space-between;padding:0 6vw;border-bottom:1px solid var(--border);background:var(--topnav-bg);position:sticky;top:0;z-index:5;backdrop-filter:blur(6px)}
.workspace-index .brand{display:flex;align-items:center;gap:10px;color:var(--text);font:600 14px 'Share Tech Mono',monospace;letter-spacing:.1em;text-decoration:none}
.workspace-index .brand-mark{width:28px;height:28px;display:flex;align-items:center;justify-content:center}
.workspace-index .brand-mark svg{width:14px;height:14px;fill:#fff}
.workspace-index .actions{display:flex;gap:.6rem;align-items:center}
.workspace-index .account{font-size:12px;color:var(--text-dim);text-decoration:none}
.workspace-index .btn-theme-toggle{display:flex;align-items:center;gap:6px;background:var(--input-bg);border:1px solid var(--border-dim);color:var(--text-dim);font-family:'Inter',sans-serif;font-size:12px;font-weight:500;letter-spacing:.04em;text-transform:uppercase;padding:6px 12px;border-radius:3px;cursor:pointer;transition:var(--transition)}
.workspace-index .btn-theme-toggle:hover{background:var(--hover-bg);color:var(--text);border-color:var(--border)}
.workspace-index .btn-theme-toggle .theme-icon svg{width:13px;height:13px;vertical-align:middle;fill:currentColor}
.workspace-index main{width:min(1080px,90vw);margin:0 auto;padding:clamp(2.4rem,6vw,4.2rem) 0 5rem}
.workspace-index .role-chip{display:inline-flex;align-items:center;gap:.45rem;padding:.32rem .75rem;border-radius:20px;background:var(--accent-soft);color:var(--accent-bright);font:600 11px 'Share Tech Mono',monospace;letter-spacing:.09em;text-transform:uppercase;margin-bottom:1.1rem}
.workspace-index .role-chip::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--accent-bright)}
.workspace-index h1{margin:0;font-size:clamp(1.9rem,4.4vw,2.7rem);letter-spacing:-.04em;text-wrap:balance}
.workspace-index .intro{max-width:560px;margin:.9rem 0 0;color:var(--text-dim);line-height:1.65;font-size:14.5px}
.workspace-index .dash-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:1.1rem;margin-top:2.6rem}
.workspace-index .dash-card{position:relative;display:flex;flex-direction:column;gap:.9rem;background:var(--navy-card);border:1px solid var(--border-dim);border-radius:6px;padding:1.5rem;text-decoration:none;color:inherit;overflow:hidden;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease;opacity:0;transform:translateY(10px);animation:dashIn .5s ease forwards}
.workspace-index .dash-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--accent);transform:scaleX(0);transform-origin:left;transition:transform .25s ease}
.workspace-index .dash-card:hover{transform:translateY(-3px);border-color:var(--border);box-shadow:0 14px 28px -16px var(--accent-glow)}
.workspace-index .dash-card:hover::before{transform:scaleX(1)}
.workspace-index .dash-card:focus-visible{outline:2px solid var(--accent-bright);outline-offset:2px}
.workspace-index .dash-card-top{display:flex;align-items:center;justify-content:space-between}
.workspace-index .dash-icon{width:44px;height:44px;border-radius:8px;background:var(--accent-soft);display:flex;align-items:center;justify-content:center;color:var(--accent-bright);flex:none}
.workspace-index .dash-icon svg{width:21px;height:21px;fill:currentColor}
.workspace-index .dash-arrow{color:var(--text-dim);font-size:18px;transition:transform .2s ease,color .2s ease}
.workspace-index .dash-card:hover .dash-arrow{transform:translateX(4px);color:var(--accent-bright)}
.workspace-index .dash-card h3{margin:0;font-size:16px;font-weight:600;letter-spacing:-.01em}
.workspace-index .dash-card p{margin:0;font-size:13px;line-height:1.55;color:var(--text-dim)}
@keyframes dashIn{to{opacity:1;transform:translateY(0)}}
@media (prefers-reduced-motion: reduce){.workspace-index .dash-card{animation:none;opacity:1;transform:none}}
@media(max-width:560px){.workspace-index .account{display:none}}
</style></head>
<body class="workspace-index">
<?php render_profile_barrier($conn); ?>
<header class="bar">
  <span class="brand"><span class="brand-mark"><svg viewBox="0 0 48 48"><defs><linearGradient id="astraMark" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="var(--accent-bright)"/><stop offset="1" stop-color="var(--purple, #a78bfa)"/></linearGradient></defs><path fill="url(#astraMark)" d="M24 3 L45 45 H34.4 L24 24.2 L13.6 45 H3 Z"/><path fill="url(#astraMark)" d="M24 14.5 L27.7 22 L35 25.5 L27.7 29 L24 36.5 L20.3 29 L13 25.5 L20.3 22 Z"/></svg></span>ASTRA / DASHBOARD</span>
  <div class="actions">
    <a class="account" href="<?= get_base_url() ?>portals/user/profile"><?= htmlspecialchars($name) ?></a>
    <button id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle"><span class="theme-icon"></span><span class="theme-label"></span></button>
  </div>
</header>
<main>
  <span class="role-chip"><?= htmlspecialchars($role_label) ?></span>
  <h1>Welcome back, <?= htmlspecialchars($name) ?>.</h1>
  <p class="intro">Here's everything available to your account. Pick a section below to get started &mdash; each one opens straight to that area.</p>
  <div class="dash-grid">
    <?php foreach ($items as $i => $item):
      $icon = $ICONS[$item[3]] ?? $ICONS['info'];
      $delay = min($i, 8) * 0.05;
    ?>
    <a class="dash-card" style="animation-delay:<?= $delay ?>s" href="<?= get_base_url() ?>portals/<?= $item[2] ?>">
      <div class="dash-card-top">
        <div class="dash-icon"><svg viewBox="0 0 24 24"><?= $icon ?></svg></div>
        <span class="dash-arrow">&rarr;</span>
      </div>
      <h3><?= htmlspecialchars($item[0]) ?></h3>
      <p><?= htmlspecialchars($item[1]) ?></p>
    </a>
    <?php endforeach; ?>
  </div>
</main>
</body></html>
