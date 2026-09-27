<?php
// Shared top bar + sidebar for the employee portal pages. Expects
// $nav_current to be set by the including page ('my_tasks' | 'team_lead' |
// 'testing' | 'deployment') before this is included. Computes $is_lead /
// $has_qa_access itself.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
$nav_current = $nav_current ?? '';

$__nav_user_id = (int)$_SESSION["user_id"];

$__lead_check = mysqli_prepare($conn,
    "SELECT 1 FROM project_members WHERE user_id = ? AND project_role = 'team_lead' LIMIT 1"
);
mysqli_stmt_bind_param($__lead_check, "i", $__nav_user_id);
mysqli_stmt_execute($__lead_check);
mysqli_stmt_store_result($__lead_check);
$is_lead = mysqli_stmt_num_rows($__lead_check) > 0;

$__qa_check = mysqli_prepare($conn,
    "SELECT 1 FROM project_members
     WHERE user_id = ? AND project_role IN ('team_lead','tester','security_tester') LIMIT 1"
);
mysqli_stmt_bind_param($__qa_check, "i", $__nav_user_id);
mysqli_stmt_execute($__qa_check);
mysqli_stmt_store_result($__qa_check);
$has_qa_access = mysqli_stmt_num_rows($__qa_check) > 0;
?>
<nav class="topnav">
  <div class="nav-left">
    <button id="sidebar-toggle" class="hamburger-btn" aria-expanded="false" aria-controls="astra-sidebar" aria-label="Open menu">
      <span></span><span></span><span></span>
    </button>
    <a href="<?= get_base_url() ?>portals/index" class="nav-brand-link" title="All pages for your role">
      <div class="nav-icon"><svg viewBox="0 0 48 48"><defs><linearGradient id="astraMark" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="var(--accent-bright)"/><stop offset="1" stop-color="var(--purple, var(--accent-bright))"/></linearGradient></defs><path fill="url(#astraMark)" d="M24 3 L45 45 H34.4 L24 24.2 L13.6 45 H3 Z"/><path fill="url(#astraMark)" d="M24 14.5 L27.7 22 L35 25.5 L27.7 29 L24 36.5 L20.3 29 L13 25.5 L20.3 22 Z"/></svg></div>
      <span class="nav-title">Astra</span>
    </a>
    <a class="nav-badge <?= $is_lead ? 'lead' : '' ?>" href="<?= get_base_url() ?>portals/emlpoyee/employee_portal"><?= $is_lead ? 'Team Lead' : 'Employee' ?></a>
  </div>
  <?php $__company_logo = astra_session_company_logo($conn); if ($__company_logo): ?>
  <div class="nav-center-logo"><img src="<?= htmlspecialchars($__company_logo) ?>" alt="Company logo"></div>
  <?php endif; ?>
  <div class="nav-right">
    <button type="button" id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle" aria-label="Switch between light and dark theme">
      <span class="theme-icon" aria-hidden="true"></span>
      <span class="theme-label"></span>
    </button>
    <span class="nav-user">Signed in as <span><?= htmlspecialchars($_SESSION["user_name"]) ?></span></span>
    <a href="<?= get_base_url() ?>portals/user/profile" class="nav-avatar" title="My Profile"><?= strtoupper(substr($_SESSION["user_name"], 0, 1)) ?></a>
  </div>
</nav>
<?php
$__core_links = [
  ['key' => 'my_tasks', 'label' => 'My Tasks', 'href' => get_base_url() . 'portals/emlpoyee/my_tasks', 'current' => $nav_current === 'my_tasks'],
];
if ($has_qa_access) {
  $__core_links[] = ['key' => 'testing', 'label' => 'Testing & Bugs', 'href' => get_base_url() . 'portals/emlpoyee/testing_portal', 'current' => $nav_current === 'testing'];
}
if ($is_lead) {
  $__core_links[] = ['key' => 'deployment', 'label' => 'Deployment', 'href' => get_base_url() . 'portals/emlpoyee/deployment_portal', 'current' => $nav_current === 'deployment'];
}

$__team_links = [];
if ($is_lead) {
  $__team_links[] = ['key' => 'team_lead', 'label' => 'Team Roster', 'href' => get_base_url() . 'portals/emlpoyee/team_lead', 'current' => $nav_current === 'team_lead'];
}

render_sidebar(
  [
    ['label' => 'Core Delivery',     'links' => $__core_links],
    ['label' => 'Team & Governance', 'links' => $__team_links],
  ],
  [
    ['key' => 'profile', 'label' => 'Settings / Profile', 'href' => get_base_url() . 'portals/user/profile'],
    ['key' => 'help',    'label' => 'Help / Onboarding Guide', 'tag' => 'button', 'extra_class' => 'tour-restart-btn'],
    ['key' => 'logout',  'label' => 'Logout', 'href' => get_base_url() . 'auth/logout', 'extra_class' => 'logout'],
  ]
);
?>
<?php
$__dock_items = [
  ['key' => 'dashboard', 'label' => $is_lead ? 'Team Lead' : 'Employee', 'href' => get_base_url() . 'portals/emlpoyee/employee_portal', 'current' => ($nav_current ?? '') === ''],
  ['key' => 'my_tasks', 'label' => 'My Tasks', 'href' => get_base_url() . 'portals/emlpoyee/my_tasks', 'current' => ($nav_current ?? '') === 'my_tasks'],
];
if ($is_lead) {
  $__dock_items[] = ['key' => 'team_lead', 'label' => 'Team Lead', 'href' => get_base_url() . 'portals/emlpoyee/team_lead', 'current' => ($nav_current ?? '') === 'team_lead'];
}
if ($has_qa_access) {
  $__dock_items[] = ['key' => 'testing', 'label' => 'Testing & Bugs', 'href' => get_base_url() . 'portals/emlpoyee/testing_portal', 'current' => ($nav_current ?? '') === 'testing'];
}
if ($is_lead) {
  $__dock_items[] = ['key' => 'deployment', 'label' => 'Deployment', 'href' => get_base_url() . 'portals/emlpoyee/deployment_portal', 'current' => ($nav_current ?? '') === 'deployment'];
}
render_dock($__dock_items); astra_consent_banner();
?>
