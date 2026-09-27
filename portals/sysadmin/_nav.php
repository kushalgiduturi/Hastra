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
    <button id="sidebar-toggle" class="hamburger-btn" aria-expanded="false" aria-controls="astra-sidebar" aria-label="Open menu">
      <span></span><span></span><span></span>
    </button>
    <a href="<?= get_base_url() ?>portals/index" class="nav-brand-link" title="All pages for your role">
      <div class="nav-icon"><svg viewBox="0 0 48 48"><defs><linearGradient id="astraMark" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="var(--accent-bright)"/><stop offset="1" stop-color="var(--purple, var(--accent-bright))"/></linearGradient></defs><path fill="url(#astraMark)" d="M24 3 L45 45 H34.4 L24 24.2 L13.6 45 H3 Z"/><path fill="url(#astraMark)" d="M24 14.5 L27.7 22 L35 25.5 L27.7 29 L24 36.5 L20.3 29 L13 25.5 L20.3 22 Z"/></svg></div>
      <span class="nav-title">Astra</span>
    </a>
    <a class="nav-badge" href="<?= get_base_url() ?>portals/sysadmin/sysadmin_portal">Sysadmin</a>
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
    <a href="<?= get_base_url() ?>portals/user/profile" class="nav-avatar" title="My Profile"><?= htmlspecialchars(strtoupper(substr($display_name ?: '?', 0, 1))) ?></a>
  </div>
</nav>
<?php
render_sidebar(
  [
    ['label' => 'Team & Governance', 'links' => [
      ['key' => 'roles', 'label' => 'Role Management', 'href' => get_base_url() . 'portals/sysadmin/roles', 'current' => $nav_current === 'roles'],
    ]],
    ['label' => 'System & Security', 'links' => [
      ['key' => 'logs',     'label' => 'Audit Logs & Security Architecture', 'href' => get_base_url() . 'portals/sysadmin/logs',              'current' => $nav_current === 'logs'],
      ['key' => 'security', 'label' => 'Security Dashboard',                 'href' => get_base_url() . 'portals/sysadmin/security_dashboard','current' => $nav_current === 'security'],
      ['key' => 'migrate',  'label' => 'Database Migration',                 'href' => get_base_url() . 'portals/sysadmin/migrate',           'current' => $nav_current === 'migrate'],
    ]],
  ],
  [
    ['key' => 'profile', 'label' => 'Settings / Profile', 'href' => get_base_url() . 'portals/user/profile'],
    ['key' => 'logout',  'label' => 'Logout', 'href' => get_base_url() . 'auth/logout', 'extra_class' => 'logout'],
  ]
);
?>
<?php
$__dock_items = [
  ['key' => 'dashboard', 'label' => 'Sysadmin Portal',     'href' => get_base_url() . 'portals/sysadmin/sysadmin_portal',    'current' => $nav_current === 'dashboard'],
  ['key' => 'roles',     'label' => 'Role Management',     'href' => get_base_url() . 'portals/sysadmin/roles',              'current' => $nav_current === 'roles'],
  ['key' => 'logs',      'label' => 'Activity Logs',       'href' => get_base_url() . 'portals/sysadmin/logs',               'current' => $nav_current === 'logs'],
  ['key' => 'security',  'label' => 'Security Dashboard',  'href' => get_base_url() . 'portals/sysadmin/security_dashboard', 'current' => $nav_current === 'security'],
  ['key' => 'migrate',   'label' => 'Migration',           'href' => get_base_url() . 'portals/sysadmin/migrate',            'current' => $nav_current === 'migrate'],
];
render_dock($__dock_items); astra_consent_banner();
?>
