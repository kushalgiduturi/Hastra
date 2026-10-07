<?php
// portals/security/scan_center.php
// Scan Center: SAST scans of uploaded source archives and OWASP ZAP report
// imports, with a findings matrix and remediation drawer. Open to every
// registered account that belongs to a company; everything shown is scoped
// to that company (core/security_scans.php).
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn);

$user_id = (int)$_SESSION['user_id'];
$role    = $_SESSION['user_role'] ?? '';
$ready   = astra_security_ready($conn);
$ctx     = $ready ? astra_security_context($conn, $user_id) : null;
$homes   = ['client' => 'client/client_portal', 'employee' => 'emlpoyee/employee_portal',
            'sysadmin' => 'sysadmin/sysadmin_portal', 'admin' => 'admin/admin_portal'];
$back    = get_base_url() . 'workspace/' . ($homes[$role] ?? 'admin/admin_portal');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Scan Center · Hastra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>"><link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-contrast.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-authkit.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/scan-center.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
</head>
<body class="sc-body">
<main class="sc-main">
  <a class="sc-back" href="<?= htmlspecialchars($back) ?>">&larr; Back</a>
  <header class="sc-header" data-cloth="report">
    <div>
      <p class="sc-eyebrow">Security</p>
      <h1>Scan Center</h1>
      <p class="sc-lede">Scan your source code for vulnerabilities, or import an OWASP ZAP report, and get a fix guide for every finding.</p>
    </div>
    <?php if ($ctx): ?><span class="sc-tenant" title="Results are private to this company"><?= htmlspecialchars($ctx['company_name'] ?? 'Your company') ?></span><?php endif; ?>
  </header>

<?php if (!$ready): ?>
  <div class="sc-notice">The Scan Center isn't set up yet. Ask the sysadmin to run the database migration.</div>
<?php elseif (!$ctx): ?>
  <div class="sc-notice">Your account isn't linked to a company yet, so there is nowhere to keep scan results. Ask your administrator to add you to your company.</div>
<?php else: ?>
  <section class="sc-intake" aria-label="Start a scan">
    <label class="sc-drop" id="dropCode" tabindex="0">
      <input type="file" id="fileCode" accept=".zip" hidden>
      <span class="sc-drop-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9.4 16.6 4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0L19.2 12l-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"/></svg></span>
      <span class="sc-drop-title">Scan source code</span>
      <span class="sc-drop-hint">Drop a .zip of your project, or click to choose. Up to 20 MB.</span>
      <span class="sc-drop-meta">Checks injection, XSS, unsafe includes, secrets, CSRF and more. Nothing is executed; the archive is deleted after the scan.</span>
    </label>
    <label class="sc-drop" id="dropZap" tabindex="0">
      <input type="file" id="fileZap" accept=".json,.xml" hidden>
      <span class="sc-drop-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3zm0 10h6c-.5 3.9-3 7.2-6 8.2V12H6V6.3l6-2.2V12z"/></svg></span>
      <span class="sc-drop-title">Import OWASP ZAP report</span>
      <span class="sc-drop-hint">Drop a ZAP report exported as JSON or XML.</span>
      <span class="sc-drop-meta">Repeated alerts are merged into one card per issue, with every affected endpoint listed.</span>
    </label>
  </section>

  <section class="sc-stage" id="stage" aria-live="polite" hidden>
    <div class="sc-stage-wash" aria-hidden="true"></div>
    <div class="sc-ring-wrap">
      <svg class="sc-ring" viewBox="0 0 120 120" aria-hidden="true">
        <circle class="sc-ring-track" cx="60" cy="60" r="52"></circle>
        <circle class="sc-ring-fill" id="ringFill" cx="60" cy="60" r="52"></circle>
      </svg>
      <div class="sc-ring-label"><span id="ringPct">0</span><small>%</small></div>
    </div>
    <div class="sc-stage-body">
      <div class="sc-stage-top">
        <span class="sc-stage-title" id="stageTitle">Preparing scan</span>
        <span class="sc-state" id="stageState">QUEUED</span>
      </div>
      <dl class="sc-metrics">
        <div><dt>Scanned</dt><dd id="mScanned">0</dd></div>
        <div><dt>Skipped</dt><dd id="mSkipped">0</dd></div>
        <div><dt>Issues</dt><dd id="mIssues">0</dd></div>
        <div><dt>Files</dt><dd id="mTotal">0</dd></div>
      </dl>
      <ol class="sc-ticker" id="ticker"></ol>
    </div>
  </section>

  <section class="sc-matrix" aria-labelledby="matrixTitle">
    <div class="sc-matrix-head">
      <h2 id="matrixTitle">Findings</h2>
      <div class="sc-sevbar" id="sevbar" role="group" aria-label="Filter by severity"></div>
    </div>
    <div class="sc-filters">
      <select id="fScan" aria-label="Scan"><option value="">All scans</option></select>
      <select id="fSource" aria-label="Source">
        <option value="">All sources</option><option value="sast_engine">Source code (SAST)</option><option value="owasp_zap">OWASP ZAP</option>
      </select>
      <select id="fStatus" aria-label="Status">
        <option value="open">Open</option><option value="">Any status</option><option value="resolved">Resolved</option><option value="false_positive">False positive</option>
      </select>
      <input type="search" id="fText" placeholder="Search title, file or URL" aria-label="Search findings">
    </div>
    <div class="sc-table-wrap">
      <table class="sc-table">
        <thead><tr>
          <th><button type="button" data-sort="severity">Severity</button></th>
          <th><button type="button" data-sort="title">Finding</button></th>
          <th>Location</th>
          <th><button type="button" data-sort="source">Source</button></th>
          <th><button type="button" data-sort="status">Status</button></th>
        </tr></thead>
        <tbody id="rows"></tbody>
      </table>
      <p class="sc-empty" id="empty" hidden>No findings match these filters.</p>
    </div>
  </section>

  <aside class="sc-drawer" id="drawer" role="dialog" aria-modal="true" aria-labelledby="dTitle" hidden>
    <div class="sc-drawer-panel">
      <header class="sc-drawer-head">
        <div>
          <span class="sc-sev" id="dSev"></span>
          <h3 id="dTitle"></h3>
          <p class="sc-drawer-meta" id="dMeta"></p>
        </div>
        <button type="button" class="sc-x" id="dClose" aria-label="Close">&times;</button>
      </header>
      <section><h4>Risk summary</h4><p id="dSummary"></p></section>
      <section><h4>Affected surface</h4><div id="dSurface"></div></section>
      <section><h4>How to fix it</h4><ol class="sc-steps" id="dSteps"></ol>
        <div class="sc-diff" id="dDiff">
          <div><span class="sc-diff-label bad">Before</span><pre id="dBefore"></pre></div>
          <div><span class="sc-diff-label good">After</span><pre id="dAfter"></pre></div>
        </div>
        <div id="dRefs"></div>
      </section>
      <footer class="sc-drawer-foot">
        <button type="button" class="sc-btn" data-status="resolved">Mark resolved</button>
        <button type="button" class="sc-btn ghost" data-status="false_positive">False positive</button>
        <button type="button" class="sc-btn ghost" data-status="open">Reopen</button>
      </footer>
    </div>
  </aside>
  <div class="sc-toast" id="toast" role="status" hidden></div>

  <script>window.SCAN_CENTER = <?= json_encode(['csrf' => generate_csrf_token(), 'api' => get_base_url() . 'api/security/']) ?>;</script>
  <script src="<?= get_base_url() ?>assets/js/scan-center.js?v=<?= ASSET_VERSION ?>"></script>
<?php endif; ?>
</main>
</body>
</html>
