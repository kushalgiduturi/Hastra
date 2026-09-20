<?php
// Shared top bar + sidebar for the admin portal pages. Expects $nav_current
// to be set by the including page ('dashboard' | 'projects') before this is
// included.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === '_nav.php') { http_response_code(404); exit(); }
$nav_current = $nav_current ?? '';
// Solo Enterprise admins have no team — hide roster/attendance/leave (and,
// since it lives inside the attendance page, the biometric webhook secret
// UI with it). See core/company.php's astra_is_solo_company().
$__admin_company = astra_session_company($conn);
$__is_solo        = astra_is_solo_company($__admin_company);
?>
<nav class="topnav">
  <div class="nav-left">
    <button id="sidebar-toggle" class="hamburger-btn" aria-expanded="false" aria-controls="astra-sidebar" aria-label="Open menu">
      <span></span><span></span><span></span>
    </button>
    <a href="<?= get_base_url() ?>portals/index" class="nav-brand-link" title="All pages for your role">
      <div class="nav-icon"><svg viewBox="0 0 48 48"><defs><linearGradient id="astraMark" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="var(--accent-bright)"/><stop offset="1" stop-color="var(--purple, #a78bfa)"/></linearGradient></defs><path fill="url(#astraMark)" d="M24 3 L45 45 H34.4 L24 24.2 L13.6 45 H3 Z"/><path fill="url(#astraMark)" d="M24 14.5 L27.7 22 L35 25.5 L27.7 29 L24 36.5 L20.3 29 L13 25.5 L20.3 22 Z"/></svg></div>
      <span class="nav-title">Astra</span>
    </a>
    <a class="nav-badge" href="<?= get_base_url() ?>portals/admin/admin_portal">Admin</a>
  </div>
  <?php $__company_logo = astra_session_company_logo($conn); if ($__company_logo): ?>
  <div class="nav-center-logo"><img src="<?= htmlspecialchars($__company_logo) ?>" alt="Company logo"></div>
  <?php endif; ?>
  <div class="nav-right">
    <button id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle">
      <span class="theme-icon"></span>
      <span class="theme-label"></span>
    </button>
    <span class="nav-user">Signed in as <span><?= htmlspecialchars($_SESSION["user_name"]) ?></span></span>
    <a href="<?= get_base_url() ?>portals/user/profile" class="nav-avatar" title="My Profile"><?= strtoupper(substr($_SESSION["user_name"], 0, 1)) ?></a>
  </div>
</nav>
<?php
render_sidebar(
  [
    ['label' => 'Core Delivery', 'links' => [
      ['key' => 'projects',      'label' => 'Projects',                 'href' => get_base_url() . 'portals/admin/project_portal',  'current' => $nav_current === 'projects'],
      ['key' => 'requirements',  'label' => 'Requirements & Deliveries','href' => get_base_url() . 'portals/admin/requirements',    'current' => $nav_current === 'requirements'],
      ['key' => 'deployment',    'label' => 'Deployments',              'href' => get_base_url() . 'portals/admin/deployments',      'current' => $nav_current === 'deployments'],
    ]],
    ['label' => 'Team & Governance', 'links' => $__is_solo ? [] : [
      ['key' => 'team',          'label' => 'Team Roster',              'href' => get_base_url() . 'portals/admin/directory',        'current' => $nav_current === 'directory'],
      ['key' => 'attendance',    'label' => 'Attendance Matrix & Logs', 'href' => get_base_url() . 'portals/admin/attendance',       'current' => $nav_current === 'attendance'],
      ['key' => 'leave',         'label' => 'Leave Management & Requests', 'href' => get_base_url() . 'portals/admin/leave_management', 'current' => $nav_current === 'leave'],
    ]],
  ],
  [
    ['key' => 'profile', 'label' => 'Settings / Profile', 'href' => get_base_url() . 'portals/user/profile', 'tag' => 'a'],
    ['key' => 'help',    'label' => 'Help / Onboarding Guide', 'tag' => 'button', 'extra_class' => 'tour-restart-btn'],
    ['key' => 'logout',  'label' => 'Logout', 'href' => get_base_url() . 'auth/logout', 'extra_class' => 'logout'],
  ]
);
?>
<?php
$__dock_items = [
  ['key' => 'dashboard', 'label' => 'Admin Portal', 'href' => get_base_url() . 'portals/admin/admin_portal', 'current' => $nav_current === 'dashboard'],
  ['key' => 'projects',  'label' => 'Projects',     'href' => get_base_url() . 'portals/admin/project_portal', 'current' => $nav_current === 'projects'],
];
render_dock($__dock_items);
?>
