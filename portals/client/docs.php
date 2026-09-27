<?php
// portals/client/docs.php
// Documentation of your company's projects. Unlocks for the whole team once a
// project is delivered and its invoice is paid.
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "client");

$user_id = (int)$_SESSION["user_id"];
$ctx     = client_context($conn, $user_id);
$docs    = company_released_docs($conn, $ctx['member_ids']);

// Delivered but not yet paid — shown to PM / IT Manager so they know why the team can't see them yet.
$waiting = [];
if (client_can($ctx, 'view_deliveries')) {
    $ids = id_list($ctx['member_ids']);
    $res = mysqli_query($conn,
        "SELECT p.id, p.project_code, p.title, d.delivered_at
         FROM projects p
         JOIN requirements r ON r.id = p.requirement_id
         JOIN deliveries d   ON d.project_id = p.id
         WHERE r.user_id IN ($ids)
           AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.project_id = p.id AND i.status = 'paid')
         ORDER BY d.delivered_at DESC");
    $waiting = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
}
foreach ($docs as &$dd)    $dd['has_doc'] = (bool)approved_doc($conn, (int)$dd['id']);
unset($dd);
foreach ($waiting as &$ww) $ww['has_doc'] = (bool)approved_doc($conn, (int)$ww['id']);
unset($ww);
$company_name = $ctx['company']['company_name'] ?? 'Your company';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Documentation · Hastra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= get_base_url() ?>core/client_pages.css">
</head>
<body>
<?php $nav_current = 'docs'; include __DIR__ . '/_nav.php'; ?>

<div class="main">
  <div class="page-header">
    <h1>Project documentation</h1>
    <p>Final documentation for <?= htmlspecialchars($company_name) ?>'s projects. A project appears here once Hastra has delivered it and its invoice is paid.</p>
  </div>

  <section class="section">
    <div class="section-header">
      <div><div class="section-title">Available to your team</div><div class="section-sub"><?= count($docs) ?> project<?= count($docs) === 1 ? '' : 's' ?></div></div>
    </div>
    <?php if (!$docs): ?>
      <p class="empty">No documentation is available yet.</p>
    <?php else: ?>
    <div class="doc-list">
      <?php foreach ($docs as $d): $doc = safe_url($d['documentation_link']); $live = safe_url($d['deployment_link']); ?>
      <div class="doc-row">
        <div>
          <div class="doc-title"><span class="code-chip"><?= htmlspecialchars($d['project_code']) ?></span><?= htmlspecialchars($d['title']) ?></div>
          <div class="doc-meta">Delivered <?= htmlspecialchars(date('d M Y', strtotime($d['delivered_at']))) ?></div>
        </div>
        <div class="doc-links">
          <?php if ($d['has_doc']): ?><a class="btn primary" href="<?= get_base_url() ?>workspace/client/doc-view?project=<?= (int)$d['id'] ?>">Open documentation</a><?php endif; ?>
          <?php if ($doc): ?><a class="btn<?= $d['has_doc'] ? '' : ' primary' ?>" href="<?= htmlspecialchars($doc) ?>" target="_blank" rel="noopener noreferrer"><?= $d['has_doc'] ? 'External docs' : 'Open documentation' ?></a><?php endif; ?>
          <?php if ($live): ?><a class="btn" href="<?= htmlspecialchars($live) ?>" target="_blank" rel="noopener noreferrer">Live app</a><?php endif; ?>
          <?php if (!$doc && !$live && !$d['has_doc']): ?><span class="you">No link was provided. Ask your Project Manager.</span><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <?php if ($waiting): ?>
  <section class="section">
    <div class="section-header">
      <div><div class="section-title">Waiting for payment</div><div class="section-sub">Delivered, but your team can't see these until the invoice is paid</div></div>
    </div>
    <div class="doc-list">
      <?php foreach ($waiting as $w): ?>
      <div class="doc-row">
        <div class="doc-title"><span class="code-chip"><?= htmlspecialchars($w['project_code']) ?></span><?= htmlspecialchars($w['title']) ?></div>
        <div class="doc-links">
          <?php if ($w['has_doc']): ?><a class="btn" href="<?= get_base_url() ?>workspace/client/doc-view?project=<?= (int)$w['id'] ?>">Preview documentation</a><?php endif; ?>
          <a class="btn" href="<?= get_base_url() ?>workspace/client/">Pay in Projects</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</div>
</body>
</html>
