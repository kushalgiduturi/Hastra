<?php
// portals/client/doc_view.php
// The approved project documentation, rendered inside Astra.
//   Project Manager / IT Manager: once the project is delivered.
//   Teammate:                     once it is delivered AND its invoice is paid.
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "client");

header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$user_id    = (int)$_SESSION["user_id"];
$ctx        = client_context($conn, $user_id);
$project_id = (int)($_GET["project"] ?? 0);
$error      = null;
$project    = null;
$doc        = null;

function client_doc_project($conn, $project_id, array $member_ids) {
    $ids = id_list($member_ids);
    $q = mysqli_prepare($conn,
        "SELECT p.id, p.project_code, p.title, d.delivered_at, d.deployment_link,
                EXISTS (SELECT 1 FROM invoices i WHERE i.project_id = p.id AND i.status = 'paid') AS is_paid
         FROM projects p
         JOIN requirements r ON r.id = p.requirement_id
         JOIN deliveries d   ON d.project_id = p.id
         WHERE p.id = ? AND r.user_id IN ($ids)
         LIMIT 1");
    mysqli_stmt_bind_param($q, "i", $project_id);
    mysqli_stmt_execute($q);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($q)) ?: null;
}

if (!docs_schema_ready($conn)) {
    $error = "Documentation inside Astra isn't switched on yet. Ask the Astra sysadmin to run the database migration.";
} elseif (!$project_id || !($project = client_doc_project($conn, $project_id, $ctx['member_ids']))) {
    $error = "This documentation isn't available to you. The project may not be delivered yet.";
} elseif (!client_can($ctx, 'view_deliveries') && !$project['is_paid']) {
    $project = null;
    $error   = "This documentation unlocks for your team once the project's invoice is paid.";
} elseif (!($doc = approved_doc($conn, $project_id))) {
    $error = "Astra hasn't published documentation for this project yet.";
}

$body = $doc ? sanitize_doc_html($doc['body_html']) : '';
$live = $project ? safe_url($project['deployment_link']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $project ? htmlspecialchars($project['project_code'] . ' documentation') : 'Documentation' ?> · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= get_base_url() ?>core/client_pages.css">
<link rel="stylesheet" href="<?= get_base_url() ?>core/doc_content.css">
<style>
  .doc-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 1.2rem; }
  .doc-head .doc-meta { margin-top: 4px; }
  .doc-paper { background: var(--card-bg, var(--navy-deep)); border: 1px solid var(--border-dim); border-radius: 4px; padding: 2rem clamp(1rem, 4vw, 2.6rem); }
  @media print {
    .topnav, .doc-actions, .back-link { display: none !important; }
    body { background: #fff !important; }
    .doc-paper { border: 0; padding: 0; background: none; }
  }
</style>
</head>
<body>
<?php $nav_current = 'docs'; include __DIR__ . '/_nav.php'; ?>

<div class="main">
  <a class="back-link nav-link" href="<?= get_base_url() ?>portals/client/docs.php">← All documentation</a>

  <?php if ($error): ?>
  <section class="section">
    <p class="flash error"><?= htmlspecialchars($error) ?></p>
  </section>
  <?php else: ?>
  <div class="doc-head">
    <div>
      <div class="doc-title"><span class="code-chip"><?= htmlspecialchars($project['project_code']) ?></span><?= htmlspecialchars($project['title']) ?></div>
      <div class="doc-meta">Version <?= (int)$doc['version'] ?> · published <?= htmlspecialchars(date('d M Y', strtotime($doc['approved_at']))) ?> · delivered <?= htmlspecialchars(date('d M Y', strtotime($project['delivered_at']))) ?></div>
    </div>
    <div class="doc-actions doc-links">
      <?php if ($live): ?><a class="btn" href="<?= htmlspecialchars($live) ?>" target="_blank" rel="noopener noreferrer">Live app</a><?php endif; ?>
      <button type="button" class="btn primary" onclick="window.print()">Print or save as PDF</button>
    </div>
  </div>
  <article class="doc-paper">
    <div class="doc-content"><?= $body ?></div>
  </article>
  <?php endif; ?>
</div>
</body>
</html>
