<?php
// portals/client/project_view.php
// Milestone escrow progress for the client's projects (core/escrow.php):
//   1. Engineering Sign-Off  ->  2. Invoice Settlement  ->  3. Secure Handover
// ?id=<project id> narrows to one project. Access codes are never stored in
// readable form (only their blind index), so this page can't show a link;
// the client opens the handover terminal and pastes the code their PM sent.
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "client");

$ctx       = client_context($conn, (int)$_SESSION["user_id"]);
$scope_ids = id_list($ctx['member_ids']);
$only_id   = (int)($_GET['id'] ?? 0);

$sql = "SELECT p.id, p.project_code, p.title FROM projects p
        JOIN requirements r ON r.id = p.requirement_id
        WHERE r.user_id IN ($scope_ids)" . ($only_id ? " AND p.id = $only_id" : "") . "
        ORDER BY p.updated_at DESC";
$projects = mysqli_fetch_all(mysqli_query($conn, $sql), MYSQLI_ASSOC);

$milestones = [];
if ($projects && astra_escrow_ready($conn)) {
    $ids = implode(',', array_map(fn($p) => (int)$p['id'], $projects));
    $r = mysqli_query($conn,
        "SELECT ms.*, i.invoice_code, i.status AS invoice_status, i.total_amount, i.paid_at,
                (SELECT COUNT(*) FROM ephemeral_dossiers d WHERE d.milestone_id = ms.id AND d.is_shredded = 0) AS live_dossiers
         FROM milestone_signoffs ms LEFT JOIN invoices i ON i.id = ms.invoice_id
         WHERE ms.project_id IN ($ids) ORDER BY ms.created_at");
    while ($m = mysqli_fetch_assoc($r)) $milestones[(int)$m['project_id']][] = $m;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Handover Status · Hastra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-authkit.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= get_base_url() ?>core/client_pages.css">
<style>
  .pv-project { margin-bottom: 1.6rem; }
  .pv-project h2 { font-size: 16px; font-weight: 600; margin: 0 0 10px; }
  .pv-project h2 .code { font-family: 'Share Tech Mono', monospace; font-size: 12px; color: var(--text-dim); margin-right: 8px; }
  .pv-ms {
    padding: 16px 18px; margin-bottom: 12px; border-radius: 12px;
    background: var(--surface-deep-glass, rgba(5, 6, 15, 0.97));
    box-shadow: inset 0 0 0 1px var(--color-glass-edge, rgba(186, 215, 247, 0.12));
  }
  .pv-ms-top { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; margin-bottom: 14px; font-size: 13px; }
  .pv-ms-top strong { color: var(--color-ice-highlight, var(--text)); }
  .pv-ms-top span { color: var(--color-moon-mist, var(--text-dim)); }

  /* 3-stage escrow bar */
  .pv-stages { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0; counter-reset: stage; }
  .pv-stage { position: relative; padding: 0 10px 0 0; counter-increment: stage; }
  .pv-stage::before {
    content: ''; position: absolute; top: 13px; left: 30px; right: 0; height: 2px;
    background: var(--color-glass-edge, rgba(186, 215, 247, 0.16));
  }
  .pv-stage:last-child::before { display: none; }
  .pv-stage.done::before { background: var(--accent); }
  .pv-dot {
    position: relative; z-index: 1; width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-family: 'Share Tech Mono', monospace; font-size: 12px;
    background: var(--surface-deep-glass, #05060f); color: var(--color-moon-mist, var(--text-dim));
    box-shadow: inset 0 0 0 1px var(--color-glass-edge, rgba(186, 215, 247, 0.3));
  }
  .pv-dot::after { content: counter(stage); }
  .pv-stage.done .pv-dot { background: var(--accent); color: #fff; box-shadow: none; }
  .pv-stage.done .pv-dot::after { content: '✓'; }
  .pv-stage.blocked .pv-dot { box-shadow: inset 0 0 0 1px #f87171; color: #f87171; }
  .pv-label { margin-top: 8px; font-size: 12.5px; font-weight: 600; color: var(--color-ice-highlight, var(--text)); }
  .pv-state { font-size: 12px; color: var(--color-moon-mist, var(--text-dim)); }
  .pv-stage.done .pv-state { color: #6fe3a3; }
  .pv-stage.blocked .pv-state { color: #f87171; }

  .pv-foot { margin-top: 14px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; font-size: 12.5px; color: var(--color-moon-mist, var(--text-dim)); }
  .pv-btn { background: var(--accent); color: #fff; text-decoration: none; border-radius: 999px; padding: 8px 16px; font-size: 12.5px; font-weight: 600; }
  .pv-btn:hover { background: var(--accent-dim); }
  @media (max-width: 560px) {
    .pv-stages { grid-template-columns: 1fr; gap: 12px; }
    .pv-stage::before { display: none; }
  }
</style>
</head>
<body>
<?php $nav_current = 'projects'; include __DIR__ . '/_nav.php'; ?>
<div class="main">
  <div class="page-header">
    <h1>Handover Status</h1>
    <p>Each milestone releases its deliverables in three steps: both parties sign, the invoice is settled, then the secure handover unlocks.</p>
  </div>

  <?php if (!$projects): ?>
    <p class="muted">No projects to show.</p>
  <?php endif; ?>

  <?php foreach ($projects as $p): $list = $milestones[(int)$p['id']] ?? []; ?>
  <section class="pv-project">
    <h2><span class="code"><?= htmlspecialchars($p['project_code']) ?></span><?= htmlspecialchars($p['title']) ?></h2>
    <?php if (!$list): ?>
      <p class="muted" style="font-size:13px;">No milestones have been opened for sign-off yet.</p>
    <?php endif; ?>
    <?php foreach ($list as $ms):
        $inv = $ms['invoice_code'] ? ['invoice_code' => $ms['invoice_code'], 'status' => $ms['invoice_status']] : null;
        $stages = astra_escrow_stages($ms, $inv);
        $disputed = $ms['escrow_status'] === 'disputed' || $ms['status'] === 'disputed';
    ?>
    <article class="pv-ms">
      <div class="pv-ms-top">
        <strong><?= htmlspecialchars($ms['milestone_name']) ?></strong>
        <span>₹<?= number_format((float)$ms['escrow_amount'], 2) ?><?= $ms['invoice_code'] ? ' · invoice ' . htmlspecialchars($ms['invoice_code']) : '' ?></span>
      </div>
      <ol class="pv-stages" aria-label="Escrow progress">
        <?php foreach ($stages as $i => $s):
            $blocked = $disputed && !$s['done'] && $i === 0; ?>
        <li class="pv-stage<?= $s['done'] ? ' done' : '' ?><?= $blocked ? ' blocked' : '' ?>">
          <div class="pv-dot" aria-hidden="true"></div>
          <div class="pv-label"><?= htmlspecialchars($s['label']) ?></div>
          <div class="pv-state"><?= htmlspecialchars($s['state']) ?></div>
        </li>
        <?php endforeach; ?>
      </ol>
      <div class="pv-foot">
        <?php if ($disputed): ?>
          This milestone is disputed. Nothing is released until it's resolved with your project lead.
        <?php elseif ($ms['status'] === 'pending_client'): ?>
          Waiting for your signature. <a class="pv-btn" href="<?= get_base_url() ?>workspace/projects/signoff">Review &amp; sign</a>
        <?php elseif ($ms['escrow_status'] === 'payment_pending'): ?>
          Your handover unlocks automatically once payment for <?= htmlspecialchars($ms['invoice_code']) ?> is confirmed.
        <?php elseif ($ms['escrow_status'] === 'released' && (int)$ms['live_dossiers'] > 0): ?>
          Unlocked. Use the access code your project manager sent you.
          <a class="pv-btn" href="<?= get_base_url() ?>workspace/deliveries/terminal">Open handover terminal</a>
        <?php elseif ($ms['escrow_status'] === 'released'): ?>
          Unlocked. Your project manager will send the secure handover code.
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </section>
  <?php endforeach; ?>
</div>
</body>
</html>
