<?php
// Shared top bar + sidebar for the client pages. Expects $ctx from
// client_context() and $nav_current.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
$nav_current    = $nav_current ?? '';
$__can_projects = client_can($ctx, 'view_projects');
// Individual/freelancer clients have no team to manage — a one-person
// "roster" is dead weight (see core/company.php's astra_is_solo_company()).
$__can_team     = client_can($ctx, 'manage_team') && !astra_is_solo_company($ctx['company'] ?? null);
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
    <a class="nav-badge" href="<?= get_base_url() ?>workspace/client/"><?= htmlspecialchars($ctx['label']) ?></a>
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
$__core_links = [];
if ($__can_projects) {
  $__core_links[] = ['key' => 'projects',     'label' => 'Projects',                 'href' => get_base_url() . 'workspace/client/',    'current' => $nav_current === 'projects'];
  $__core_links[] = ['key' => 'requirements', 'label' => 'Requirements & Deliveries','href' => get_base_url() . 'workspace/client/my-requirements',  'current' => $nav_current === 'requirements'];
}
$__core_links[] = ['key' => 'docs', 'label' => 'Documentation', 'href' => get_base_url() . 'workspace/client/docs', 'current' => $nav_current === 'docs'];

$__team_links = [];
if ($__can_team) {
  $__team_links[] = ['key' => 'team', 'label' => 'Team Roster', 'href' => get_base_url() . 'workspace/client/team', 'current' => $nav_current === 'team'];
}

render_sidebar(
  [
    ['label' => 'Core Delivery',      'links' => $__core_links],
    ['label' => 'Team',  'links' => $__team_links],
  ],
  [
    ['key' => 'profile', 'label' => 'Settings / Profile', 'href' => get_base_url() . 'workspace/user/profile'],
    ['key' => 'help',    'label' => 'Help / Onboarding Guide', 'tag' => 'button', 'extra_class' => 'tour-restart-btn'],
    ['key' => 'logout',  'label' => 'Logout', 'href' => get_base_url() . 'signout', 'extra_class' => 'logout'],
  ]
);
?>
<?php
$__dock_items = [
  ['key' => 'dashboard', 'label' => $ctx['label'], 'href' => get_base_url() . 'workspace/client/', 'current' => ($nav_current ?? '') === 'dashboard'],
];
if ($__can_projects) $__dock_items[] = ['key' => 'projects', 'label' => 'Projects', 'href' => get_base_url() . 'workspace/client/', 'current' => $nav_current === 'projects'];
$__dock_items[] = ['key' => 'docs', 'label' => 'Documentation', 'href' => get_base_url() . 'workspace/client/docs', 'current' => $nav_current === 'docs'];
if ($__can_team) $__dock_items[] = ['key' => 'team', 'label' => 'Team', 'href' => get_base_url() . 'workspace/client/team', 'current' => $nav_current === 'team'];
render_dock($__dock_items); astra_consent_banner();
?>
