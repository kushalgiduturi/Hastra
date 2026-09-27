<?php
// Shared top bar + sidebar for the sysadmin pages. Expects $nav_current to
// be set by the including page ('dashboard' | 'roles' | 'logs' | 'security'
// | 'migrate') before this include, and $logged_in_name (falls back to
// $_SESSION user_name).
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
$nav_current   = $nav_current ?? '';
$display_name  = $logged_in_name ?? ($_SESSION['user_name'] ?? '');
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
    <a class="nav-badge" href="<?= get_base_url() ?>workspace/sysadmin/">Sysadmin</a>
  </div>
  <?php $__company_logo = astra_session_company_logo($conn); if ($__company_logo): ?>
  <div class="nav-center-logo"><img src="<?= htmlspecialchars($__company_logo) ?>" alt="Company logo"></div>
  <?php endif; ?>
  <div class="nav-right">
    <button type="button" id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle" aria-label="Switch between light and dark theme">
      <span class="theme-icon" aria-hidden="true"></span>
      <span class="theme-label"></span>
    </button>
    <span class="nav-user">Signed in as <span><?= htmlspecialchars($display_name) ?></span></span>
    <a href="<?= get_base_url() ?>workspace/user/profile" class="nav-avatar" title="My Profile"><?= htmlspecialchars(strtoupper(substr($display_name ?: '?', 0, 1))) ?></a>
  </div>
</nav>
<?php
render_sidebar(
  [
    ['label' => 'Team & Governance', 'links' => [
      ['key' => 'roles', 'label' => 'Role Management', 'href' => get_base_url() . 'workspace/sysadmin/roles', 'current' => $nav_current === 'roles'],
    ]],
    ['label' => 'System & Security', 'links' => [
      ['key' => 'logs',     'label' => 'Audit Logs & Security Architecture', 'href' => get_base_url() . 'workspace/sysadmin/logs',              'current' => $nav_current === 'logs'],
      ['key' => 'security', 'label' => 'Security Dashboard',                 'href' => get_base_url() . 'workspace/sysadmin/security-dashboard','current' => $nav_current === 'security'],
      ['key' => 'migrate',  'label' => 'Database Migration',                 'href' => get_base_url() . 'workspace/sysadmin/migrate',           'current' => $nav_current === 'migrate'],
    ]],
  ],
  [
    ['key' => 'profile', 'label' => 'Settings / Profile', 'href' => get_base_url() . 'workspace/user/profile'],
    ['key' => 'logout',  'label' => 'Logout', 'href' => get_base_url() . 'signout', 'extra_class' => 'logout'],
  ]
);
?>
<?php
$__dock_items = [
  ['key' => 'dashboard', 'label' => 'Sysadmin Portal',     'href' => get_base_url() . 'workspace/sysadmin/',    'current' => $nav_current === 'dashboard'],
  ['key' => 'roles',     'label' => 'Role Management',     'href' => get_base_url() . 'workspace/sysadmin/roles',              'current' => $nav_current === 'roles'],
  ['key' => 'logs',      'label' => 'Activity Logs',       'href' => get_base_url() . 'workspace/sysadmin/logs',               'current' => $nav_current === 'logs'],
  ['key' => 'security',  'label' => 'Security Dashboard',  'href' => get_base_url() . 'workspace/sysadmin/security-dashboard', 'current' => $nav_current === 'security'],
  ['key' => 'migrate',   'label' => 'Migration',           'href' => get_base_url() . 'workspace/sysadmin/migrate',            'current' => $nav_current === 'migrate'],
];
render_dock($__dock_items); astra_consent_banner();
?>
