<?php
// portals/admin/admin_portal.php — hub page (cards only)
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "admin");
require_once __DIR__ . '/../../core/verify_audit.php';

// On-demand ledger verification (walking every block on each page load would
// get slower as the ledger grows).
$ledger = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_ledger') {
    verify_csrf_token();
    $ledger = astra_verify_audit_ledger($conn);
    if (!$ledger['ok'] && $ledger['broken_at'] !== null) {
        astra_log_chained('AUDIT_LEDGER_BREACH', $ledger['reason'], (int)$_SESSION['user_id'], $conn, 'critical', 'LEDGER_TAMPER');
    }
}
$ledger_anchor = astra_audit_anchor_read();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Portal · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
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

  .main { max-width: 1200px; margin: 0 auto; padding: 2rem; }
  .page-header { margin-bottom: 2rem; }
  .page-header h1 { font-size: 24px; font-weight: 600; letter-spacing: -0.01em; margin-bottom: 4px; }
  .page-header p { font-size: 13px; color: var(--text-dim); }

  /* ── Audit ledger panel (AuthKit deep glass) ── */
  .ledger {
    margin-top: 2rem; padding: 1.2rem 1.4rem; border-radius: 16px;
    background: rgba(5, 6, 15, 0.97); box-shadow: inset 0 0 0 1px rgba(186, 215, 247, 0.12);
    display: flex; flex-wrap: wrap; align-items: center; gap: 1rem 1.4rem;
  }
  .ledger-copy { flex: 1 1 320px; min-width: 0; }
  .ledger-title { font-family: 'Share Tech Mono', monospace; font-size: 11px; letter-spacing: 0.10em; text-transform: uppercase; color: #c7d3ea; margin-bottom: 4px; }
  .ledger-desc { font-size: 13px; color: #cbd5e1; line-height: 1.5; }
  .ledger-meta { font-size: 11.5px; color: #9fb2cc; margin-top: 6px; font-family: 'Share Tech Mono', monospace; }
  .ledger-btn {
    background: var(--accent); color: #fff; border: 0; border-radius: 999px; padding: 10px 20px;
    font: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
  }
  .ledger-btn:hover { background: var(--accent-dim); }
  .ledger-btn:focus-visible { outline: 2px solid #d1e4fa; outline-offset: 3px; }
  .ledger-ok {
    flex-basis: 100%; display: inline-flex; align-items: center; gap: 10px; width: fit-content;
    padding: 8px 14px; border-radius: 999px; background: rgba(209, 228, 250, 0.06);
    box-shadow: inset 0 0 0 1px rgba(209, 228, 250, 0.28);
    font-family: 'Share Tech Mono', monospace; font-size: 12.5px; letter-spacing: 0.08em; color: #d1e4fa;
  }
  .ledger-ok::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: #d1e4fa; box-shadow: 0 0 10px #d1e4fa; }
  .ledger-breach {
    flex-basis: 100%; padding: 14px 16px; border-radius: 10px; background: rgba(220, 38, 38, 0.12);
    box-shadow: inset 0 0 0 1px rgba(248, 113, 113, 0.55); color: #fecaca; font-size: 13px; line-height: 1.55;
  }
  .ledger-breach strong { display: block; color: #f87171; font-family: 'Share Tech Mono', monospace; letter-spacing: 0.08em; font-size: 12.5px; margin-bottom: 4px; }
  .ledger-breach code { font-family: 'Share Tech Mono', monospace; color: #fff; }
</style>
</head>
<body data-tour-page="admin/admin_portal">

<?php $nav_current = 'dashboard'; include __DIR__ . '/_nav.php'; ?>

<div class="main portal-surface-scrim">

  <div class="page-header">
    <h1>Admin Portal</h1>
    <p>Review client requirements and manage employee accounts.</p>
  </div>

  <div class="section-nav">
    <a class="section-nav-card" id="tour-requirements" href="<?= get_base_url() ?>portals/admin/requirements">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zm-1 9H7v-2h5v2zm3-4H7v-2h7v2z"/></svg></span>
      <span class="section-nav-label">Requirement Review</span>
    </a>
    <a class="section-nav-card" href="<?= get_base_url() ?>portals/admin/deployments">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg></span>
      <span class="section-nav-label">Deployment Approvals</span>
    </a>
    <a class="section-nav-card" href="<?= get_base_url() ?>portals/admin/testing">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M20 8h-2.81c-.45-.78-1.07-1.45-1.82-1.96L17 4.41 15.59 3l-2.17 2.17C12.96 5.06 12.49 5 12 5c-.49 0-.96.06-1.41.17L8.41 3 7 4.41l1.62 1.63C7.88 6.55 7.26 7.22 6.81 8H4v2h2.09c-.05.33-.09.66-.09 1v1H4v2h2v1c0 .34.04.67.09 1H4v2h2.81c1.04 1.79 2.97 3 5.19 3s4.15-1.21 5.19-3H20v-2h-2.09c.05-.33.09-.66.09-1v-1h2v-2h-2v-1c0-.34-.04-.67-.09-1H20V8z"/></svg></span>
      <span class="section-nav-label">Testing &amp; Bugs</span>
    </a>
    <a class="section-nav-card" id="tour-delivery" href="<?= get_base_url() ?>portals/admin/delivery">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm13.5-9l1.96 2.5H17V9.5h2.5zM18 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg></span>
      <span class="section-nav-label">Delivery</span>
    </a>
    <a class="section-nav-card" id="tour-billing" href="<?= get_base_url() ?>portals/admin/billing">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg></span>
      <span class="section-nav-label">Billing</span>
    </a>
    <a class="section-nav-card" href="<?= get_base_url() ?>portals/admin/create_employee">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M12 4a4 4 0 110 8 4 4 0 010-8zm0 10c4.42 0 8 1.79 8 4v2H4v-2c0-2.21 3.58-4 8-4z"/></svg></span>
      <span class="section-nav-label">Create Employee</span>
    </a>
    <a class="section-nav-card" href="<?= get_base_url() ?>portals/admin/directory">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></span>
      <span class="section-nav-label">User Directory</span>
    </a>
    <a class="section-nav-card" href="<?= get_base_url() ?>portals/projects/signoff">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg></span>
      <span class="section-nav-label">Milestone Sign-Off</span>
    </a>
    <a class="section-nav-card" href="<?= get_base_url() ?>portals/deliveries/dossier">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg></span>
      <span class="section-nav-label">Ephemeral Dossiers</span>
    </a>
    <a class="section-nav-card" href="<?= get_base_url() ?>portals/security/scan_center">
      <span class="section-nav-icon"><svg viewBox="0 0 24 24"><path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3zm-1.2 13.6-3.4-3.4 1.4-1.4 2 2 4.6-4.6 1.4 1.4-6 6z"/></svg></span>
      <span class="section-nav-label">Scan Center</span>
    </a>
  </div>

  <section class="ledger" aria-labelledby="ledgerTitle">
    <div class="ledger-copy">
      <div class="ledger-title" id="ledgerTitle">Tamper-evident audit ledger</div>
      <div class="ledger-desc">Every log entry is chained to the one before it with an HMAC-SHA256 hash. Verification recomputes every block and confirms nothing was edited, deleted, or reordered.</div>
      <?php if ($ledger_anchor): ?>
      <div class="ledger-meta">Last verified: block <?= (int)$ledger_anchor['chain_index'] ?> · <?= htmlspecialchars(date('d M Y, H:i', strtotime($ledger_anchor['verified_at'] ?? 'now'))) ?></div>
      <?php endif; ?>
    </div>
    <form method="POST" action="admin_portal">
      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
      <input type="hidden" name="action" value="verify_ledger">
      <button type="submit" class="ledger-btn">Verify Audit Ledger</button>
    </form>

    <?php if ($ledger && $ledger['ok']): ?>
    <div class="ledger-ok" role="status">LEDGER INTEGRITY VERIFIED (<?= number_format($ledger['blocks']) ?> BLOCKS)</div>
    <?php elseif ($ledger): ?>
    <div class="ledger-breach" role="alert">
      <strong>LEDGER INTEGRITY FAILURE<?= $ledger['broken_at'] !== null ? ' AT BLOCK ' . (int)$ledger['broken_at'] : '' ?></strong>
      <?= htmlspecialchars($ledger['reason']) ?>
      <?php if ($ledger['log_id']): ?> Affected row: <code>logs.id <?= (int)$ledger['log_id'] ?></code>.<?php endif; ?>
      <?php if ($ledger['broken_at'] !== null): ?> Blocks after this point can't be trusted until the cause is found. This failure has been recorded in the ledger.<?php endif; ?>
    </div>
    <?php endif; ?>
  </section>

</div>

<script>
  window.ASTRA_CSRF_TOKEN = "<?= generate_csrf_token() ?>";
  window.ASTRA_BASE_URL   = "<?= get_base_url() ?>";
</script>
<script src="<?= get_base_url() ?>assets/js/tour-config.js?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= get_base_url() ?>assets/js/tour.js?v=<?= ASSET_VERSION ?>"></script>

</body>
</html>
