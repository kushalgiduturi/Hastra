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
    header("Location: " . get_base_url() . "portals/client/docs");
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Client Portal · Astra</title>
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

<div class="main">

  <div class="page-header">
    <h1><?= htmlspecialchars($ctx['company']['company_name'] ?? 'Client Portal') ?></h1>
    <p><?= $can_submit ? 'Submit your project requirements and track their progress.' : "Follow your company's requirements, projects and invoices." ?></p>
  </div>

  <div class="section-nav">
    <a class="section-nav-card" id="tour-submit-requirement" href="<?= get_base_url() ?>portals/client/submit_requirement">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg></span>
      <span class="section-nav-label"><?= $can_submit ? 'Submit New Requirement' : 'Requirements' ?></span>
    </a>
    <?php if ($has_delivery_or_billing): ?>
    <a class="section-nav-card" id="tour-delivery-billing" href="<?= get_base_url() ?>portals/client/delivery_billing">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4z"/></svg></span>
      <span class="section-nav-label">Delivery &amp; Billing</span>
    </a>
    <?php endif; ?>
    <a class="section-nav-card" id="tour-my-requirements" href="<?= get_base_url() ?>portals/client/my_requirements">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zm-1 9H7v-2h5v2zm3-4H7v-2h7v2z"/></svg></span>
      <span class="section-nav-label">My Requirements</span>
    </a>
    <a class="section-nav-card" id="tour-my-projects" href="<?= get_base_url() ?>portals/client/my_projects">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg></span>
      <span class="section-nav-label">My Projects</span>
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
