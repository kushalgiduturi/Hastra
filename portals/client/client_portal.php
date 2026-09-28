<?php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "client");

// Who is this client? PMs and IT Managers see their whole company's work;
// teammates only get the documentation page.
$ctx           = client_context($conn, (int)$_SESSION["user_id"]);
$scope_ids     = id_list($ctx['member_ids']);
$is_it_manager = client_can($ctx, 'manage_team');
$can_submit    = client_can($ctx, 'submit_requirement');
if (!client_can($ctx, 'view_projects')) {
    header("Location: " . APP_URL . "workspace/client/docs");
    exit();
}

// Lightweight existence check only — the actual rows are fetched on the
// Delivery & Billing page itself.
$has_delivery_or_billing = false;
$dcheck = mysqli_prepare($conn,
    "SELECT
        (SELECT COUNT(*) FROM invoices inv
           JOIN projects p ON inv.project_id = p.id
           JOIN requirements r ON p.requirement_id = r.id
          WHERE r.user_id IN ($scope_ids)) AS inv_count,
        (SELECT COUNT(*) FROM deliveries d
           JOIN projects p ON d.project_id = p.id
           JOIN requirements r ON p.requirement_id = r.id
          WHERE r.user_id IN ($scope_ids)) AS del_count"
);
mysqli_stmt_execute($dcheck);
$dcheck_row = mysqli_stmt_get_result($dcheck)->fetch_assoc();
if ($dcheck_row && ((int)$dcheck_row['inv_count'] > 0 || (int)$dcheck_row['del_count'] > 0)) {
    $has_delivery_or_billing = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Client Portal · Hastra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= get_base_url() ?>core/client_pages.css">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/tour.css?v=<?= ASSET_VERSION ?>">
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

  .main { max-width: 1100px; margin: 0 auto; padding: 2rem; }

  .page-header { margin-bottom: 2rem; }
  .page-header h1 { font-size: 24px; font-weight: 600; color: var(--text); letter-spacing: -0.01em; margin-bottom: 4px; }
  .page-header p  { font-size: 13px; color: var(--text-dim); }
</style>
</head>
<body data-tour-page="client/client_portal">

<?php $nav_current = 'projects'; include __DIR__ . '/_nav.php'; ?>

<div class="main portal-surface-scrim">

  <div class="page-header">
    <h1><?= htmlspecialchars($ctx['company']['company_name'] ?? 'Client Portal') ?></h1>
    <p><?= $can_submit ? 'Submit your project requirements and track their progress.' : "Follow your company's requirements, projects and invoices." ?></p>
  </div>

  <div class="section-nav">
    <a class="section-nav-card" id="tour-submit-requirement" href="<?= get_base_url() ?>workspace/client/submit-requirement">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg></span>
      <span class="section-nav-label"><?= $can_submit ? 'Submit New Requirement' : 'Requirements' ?></span>
    </a>
    <?php if ($has_delivery_or_billing): ?>
    <a class="section-nav-card" id="tour-delivery-billing" href="<?= get_base_url() ?>workspace/client/delivery-billing">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4z"/></svg></span>
      <span class="section-nav-label">Delivery &amp; Billing</span>
    </a>
    <?php endif; ?>
    <a class="section-nav-card" id="tour-my-requirements" href="<?= get_base_url() ?>workspace/client/my-requirements">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zm-1 9H7v-2h5v2zm3-4H7v-2h7v2z"/></svg></span>
      <span class="section-nav-label">My Requirements</span>
    </a>
    <a class="section-nav-card" id="tour-my-projects" href="<?= get_base_url() ?>workspace/client/my-projects">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg></span>
      <span class="section-nav-label">My Projects</span>
    </a>
    <a class="section-nav-card" href="<?= get_base_url() ?>workspace/projects/signoff">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg></span>
      <span class="section-nav-label">Milestone Sign-Off</span>
    </a>
    <a class="section-nav-card" href="<?= get_base_url() ?>workspace/deliveries/dossier">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg></span>
      <span class="section-nav-label">Ephemeral Dossiers</span>
    </a>
    <a class="section-nav-card" href="<?= get_base_url() ?>workspace/client/requirements-diff">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg></span>
      <span class="section-nav-label">Requirement Revisions</span>
    </a>
    <a class="section-nav-card" href="<?= get_base_url() ?>workspace/security/scan-center">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3zm-1.2 13.6-3.4-3.4 1.4-1.4 2 2 4.6-4.6 1.4 1.4-6 6z"/></svg></span>
      <span class="section-nav-label">Scan Center</span>
    </a>
  </div>

</div>

<script>
  window.ASTRA_CSRF_TOKEN = "<?= generate_csrf_token() ?>";
  window.ASTRA_BASE_URL   = "<?= get_base_url() ?>";
</script>
<script src="<?= get_base_url() ?>assets/js/tour-config.js?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= get_base_url() ?>assets/js/tour.js?v=<?= ASSET_VERSION ?>"></script>

</body>
</html>
