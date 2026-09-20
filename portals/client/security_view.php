<?php
// portals/client/security_view.php
// One-time security summary for a delivered project (Project Manager only).
// POST creates a single-use link that lasts a few minutes; opening it deletes the
// link's record and marks the summary as viewed, so it can't be opened again.
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "client");

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$user_id = (int)$_SESSION["user_id"];
$ctx     = client_context($conn, $user_id);
$error   = null;
$view    = null;

if (!access_schema_ready($conn)) {
    $error = "The security summary isn't switched on yet. Ask the Astra sysadmin to run the database migration.";
} elseif (!client_can($ctx, 'security_summary')) {
    $error = "Only your company's Project Manager can open the security summary.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $token = create_security_link($conn, (int)($_POST["delivery_id"] ?? 0), $user_id, $ctx['member_ids'], $error);
    if ($token) {
        header("Location: " . get_base_url() . "portals/client/security_view.php?t=" . $token);
        exit();
    }
} else {
    $view = consume_security_link($conn, $_GET["t"] ?? "", $user_id, $error);
    if ($view) {
        try { log_activity($conn, $user_id, "security_summary_viewed", $_SESSION["user_name"] ?? null); } catch (Throwable $e) {}
    }
}

$credentials = ''; $cred_error = null;
if ($view) {
    try { $credentials = decrypt_secret($view['credentials_note'] ?? ''); }
    catch (Throwable $e) { $cred_error = "The handover credentials couldn't be decrypted. Ask Astra to send them again."; error_log($e->getMessage()); }
}

$bugs = []; $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0]; $open = 0;
if ($view) {
    $q = mysqli_prepare($conn,
        "SELECT bug_code, title, vuln_class, severity, status, created_at, closed_at
         FROM bugs WHERE project_id = ? AND bug_type = 'security'
         ORDER BY FIELD(severity,'critical','high','medium','low'), created_at");
    mysqli_stmt_bind_param($q, "i", $view['project_id']);
    mysqli_stmt_execute($q);
    $bugs = mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC);
    foreach ($bugs as $b) {
        if (isset($counts[$b['severity']])) $counts[$b['severity']]++;
        if (!in_array($b['status'], ['closed', 'wont_fix'], true)) $open++;
    }
}
$status_words = ['open' => 'Open', 'in_progress' => 'In progress', 'fixed' => 'Fixed', 'retest' => 'Retesting', 'closed' => 'Fixed & verified', 'wont_fix' => "Accepted risk"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Security Summary · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= get_base_url() ?>core/client_pages.css">
</head>
<body>
<?php $nav_current = ''; include __DIR__ . '/_nav.php'; ?>

<div class="main">
<?php if (!$view): ?>
  <div class="page-header"><h1>Security summary</h1></div>
  <div class="flash error" role="alert"><?= htmlspecialchars($error ?? "This link is not valid.") ?></div>
  <p><a class="btn" href="<?= get_base_url() ?>portals/client/client_portal">Back to Projects</a></p>
<?php else: ?>
  <div class="page-header">
    <h1><span class="code-chip"><?= htmlspecialchars($view['project_code']) ?></span><?= htmlspecialchars($view['project_title']) ?> — security summary</h1>
    <p>What Astra did to secure this project, and every security finding raised during testing.</p>
  </div>

  <div class="once-banner" role="status">
    <b>This page can be opened only once.</b> The link has already been deleted. Save or print anything you need
    (<a href="#" onclick="window.print(); return false;" style="color:inherit">print this page</a>) before you leave.
  </div>

  <div class="stats">
    <div class="stat"><div class="label">Security findings</div><div class="value"><?= count($bugs) ?></div></div>
    <div class="stat bad"><div class="label">Critical / High</div><div class="value"><?= $counts['critical'] + $counts['high'] ?></div></div>
    <div class="stat wait"><div class="label">Medium / Low</div><div class="value"><?= $counts['medium'] + $counts['low'] ?></div></div>
    <div class="stat <?= $open ? 'bad' : 'ok' ?>"><div class="label">Still open</div><div class="value"><?= $open ?></div></div>
  </div>

  <section class="section">
    <div class="section-header"><div class="section-title">Security findings from testing</div></div>
    <?php if (!$bugs): ?>
      <p class="empty">No security findings were logged for this project.</p>
    <?php else: ?>
    <div class="tbl-wrap">
      <table>
        <thead><tr><th>Finding</th><th>Class</th><th>Severity</th><th>Outcome</th><th>Raised</th><th>Closed</th></tr></thead>
        <tbody>
          <?php foreach ($bugs as $b): ?>
          <tr>
            <td><span class="code-chip"><?= htmlspecialchars($b['bug_code']) ?></span><?= htmlspecialchars($b['title']) ?></td>
            <td><?= htmlspecialchars($b['vuln_class'] ?? '—') ?></td>
            <td><span class="sev <?= htmlspecialchars($b['severity']) ?>"><?= htmlspecialchars($b['severity']) ?></span></td>
            <td><?= htmlspecialchars($status_words[$b['status']] ?? $b['status']) ?></td>
            <td class="num"><?= htmlspecialchars(date('d M Y', strtotime($b['created_at']))) ?></td>
            <td class="num"><?= $b['closed_at'] ? htmlspecialchars(date('d M Y', strtotime($b['closed_at']))) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <section class="section">
    <div class="section-header"><div class="section-title">Security measures in the Astra delivery process</div></div>
    <div class="section-body">
      <ul class="measures">
        <?php foreach (SECURITY_MEASURES as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </section>

  <?php if (trim($credentials) !== '' || $cred_error): ?>
  <section class="section">
    <div class="section-header"><div class="section-title">Credentials handed over</div><div class="section-sub">Store these in your password manager now</div></div>
    <div class="section-body">
      <?php if ($cred_error): ?><div class="flash error"><?= htmlspecialchars($cred_error) ?></div>
      <?php else: ?><div class="secret"><?= htmlspecialchars($credentials) ?></div><?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
