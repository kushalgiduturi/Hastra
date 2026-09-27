<?php
// Shared top bar + sidebar for the admin portal pages. Expects $nav_current
// to be set by the including page ('dashboard' | 'projects') before this is
// included.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
$nav_current = $nav_current ?? '';
// Solo Enterprise admins have no team — hide roster/attendance/leave (and,
// since it lives inside the attendance page, the biometric webhook secret
// UI with it). See core/company.php's astra_is_solo_company().
$__admin_company = astra_session_company($conn);
$__is_solo        = astra_is_solo_company($__admin_company);
?>
<nav class="topnav">
  <div class="nav-left">
    <button id="sidebar-toggle" class="hamburger-btn" aria-expanded="false" aria-controls="hastra-sidebar" aria-label="Open menu">
      <span></span><span></span><span></span>
    </button>
    <a href="<?= get_base_url() ?>workspace/" class="nav-brand-link" title="All pages for your role">
      <div class="nav-icon"><svg viewBox="0 0 48 48"><defs><linearGradient id="hastra-crimson" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#7a1224"/><stop offset="1" stop-color="#e11d3c"/></linearGradient><linearGradient id="hastra-cobalt" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#1e3a8a"/><stop offset="1" stop-color="#3b82f6"/></linearGradient></defs><path class="hastra-primary-fill" fill="url(#hastra-crimson)" d="M8 5H16V43H8V5ZM32 5H40V43H32V5ZM4 21H44V27H4V21Z"/><path class="hastra-primary-fill" fill="url(#hastra-crimson)" d="M24 15L30 24L24 33L18 24Z"/></svg></div>
      <span class="nav-title">Hastra</span>
    </a>
    <a class="nav-badge" href="<?= get_base_url() ?>workspace/admin/">Admin</a>
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
    <a href="<?= get_base_url() ?>workspace/user/profile" class="nav-avatar" title="My Profile"><?= strtoupper(substr($_SESSION["user_name"], 0, 1)) ?></a>
  </div>
</nav>
<?php
render_sidebar(
  [
    ['label' => 'Core Delivery', 'links' => [
      ['key' => 'projects',      'label' => 'Projects',                 'href' => get_base_url() . 'workspace/admin/project-portal',  'current' => $nav_current === 'projects'],
      ['key' => 'requirements',  'label' => 'Requirements & Deliveries','href' => get_base_url() . 'workspace/admin/requirements',    'current' => $nav_current === 'requirements'],
      ['key' => 'deployment',    'label' => 'Deployments',              'href' => get_base_url() . 'workspace/admin/deployments',      'current' => $nav_current === 'deployments'],
    ]],
    ['label' => 'Governance', 'links' => $__is_solo ? [] : [
      ['key' => 'team',          'label' => 'Team Roster',              'href' => get_base_url() . 'workspace/admin/directory',        'current' => $nav_current === 'directory'],
      ['key' => 'attendance',    'label' => 'Attendance Matrix & Logs', 'href' => get_base_url() . 'workspace/admin/attendance',       'current' => $nav_current === 'attendance'],
      ['key' => 'leave',         'label' => 'Leave Management & Requests', 'href' => get_base_url() . 'workspace/admin/leave-management', 'current' => $nav_current === 'leave'],
    ]],
    ['label' => 'System Security', 'links' => [
      ['key' => 'security', 'label' => 'Security & Activity Log', 'href' => get_base_url() . 'workspace/admin/security', 'current' => $nav_current === 'security'],
    ]],
  ],
  [
    ['key' => 'profile', 'label' => 'Settings / Profile', 'href' => get_base_url() . 'workspace/user/profile', 'tag' => 'a'],
    ['key' => 'help',    'label' => 'Help / Onboarding Guide', 'tag' => 'button', 'extra_class' => 'tour-restart-btn'],
    ['key' => 'logout',  'label' => 'Logout', 'href' => get_base_url() . 'signout', 'extra_class' => 'logout'],
  ]
);
?>
<?php
$__dock_items = [
  ['key' => 'dashboard', 'label' => 'Admin Portal', 'href' => get_base_url() . 'workspace/admin/', 'current' => $nav_current === 'dashboard'],
  ['key' => 'projects',  'label' => 'Projects',     'href' => get_base_url() . 'workspace/admin/project-portal', 'current' => $nav_current === 'projects'],
];
render_dock($__dock_items); astra_consent_banner();
?>
