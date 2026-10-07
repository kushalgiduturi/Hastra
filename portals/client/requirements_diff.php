<?php
// portals/client/requirements_diff.php
// Scope-drift diff engine: every time a client revises a requirement that's
// already been through review, portals/client/my_requirements.php snapshots
// the prior state into requirement_versions before applying the edit. This
// page renders the line-level delta between any two of those versions,
// estimates how much the ask actually grew or shrank, and — for the client's
// company PM or an internal admin — gates the revision behind an explicit
// acknowledgment before portals/admin/project_portal.php's create_project
// will accept it (requirements.has_pending_revision).
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn); // client (scoped to their own requirement) or admin

$user_id   = (int)$_SESSION["user_id"];
$user_role = $_SESSION["user_role"] ?? '';
$req_id    = (int)($_GET['req_id'] ?? $_POST['req_id'] ?? 0);
$msg       = "";
$msg_type  = "error";

// ── Authorization: the requirement's own company (any client_context member) or admin ──
$req = null;
if ($req_id) {
    $rq = mysqli_prepare($conn, "SELECT * FROM requirements WHERE id = ?");
    mysqli_stmt_bind_param($rq, "i", $req_id);
    mysqli_stmt_execute($rq);
    $req = mysqli_fetch_assoc(mysqli_stmt_get_result($rq));
}
if (!$req) { http_response_code(404); exit("Requirement not found."); }

$authorized = false;
if ($user_role === 'admin') {
    $authorized = true;
} elseif ($user_role === 'client') {
    $ctx = client_context($conn, $user_id);
    $authorized = in_array((int)$req['user_id'], array_map('intval', $ctx['member_ids']), true);
}
if (!$authorized) { http_response_code(403); exit("Not authorized to view this requirement's history."); }

$req['requirement_title'] = $req['requirement_title']; // plaintext column, unchanged
$req['description']       = astra_db_decrypt($req['description']);
$req['expected_features'] = astra_db_decrypt($req['expected_features']);

// ── PM acknowledgment (admin can also acknowledge on the PM's behalf) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'acknowledge') {
    verify_csrf_token();
    $can_ack = $user_role === 'admin' || ($user_role === 'client' && client_can(client_context($conn, $user_id), 'submit_requirement'));
    if (!$can_ack) {
        $msg = "Only your company's Project Manager (or an Hastra admin) can acknowledge a revision.";
    } elseif (astra_reqver_acknowledge($conn, $req_id, $user_id, $error)) {
        $msg = "Revision acknowledged. It's now the active version feeding project creation.";
        $msg_type = "success";
        try { log_activity($conn, $user_id, "requirement_revision_acknowledged", $req['requirement_id']); } catch (Throwable $e) {}
        // refresh
        mysqli_stmt_execute($rq);
        $req = mysqli_fetch_assoc(mysqli_stmt_get_result($rq));
        $req['description']       = astra_db_decrypt($req['description']);
        $req['expected_features'] = astra_db_decrypt($req['expected_features']);
    } else {
        $msg = $error;
    }
}

$versions = astra_reqver_list($conn, $req_id);
$has_versions = count($versions) > 0;

// A snapshot only exists for the state a requirement had BEFORE an edit —
// astra_reqver_snapshot() is called right before the new content is written,
// so the row currently live in `requirements` is never itself a snapshot.
// It gets a synthetic version number one past the latest real one ("current")
// so it can still be selected and diffed against like any other version.
$latest_snapshot_v = $has_versions ? (int)end($versions)['version_number'] : 0;
$live_v = $latest_snapshot_v + 1;
$live_version = [
    'version_number' => $live_v, 'encrypted_title' => $req['requirement_title'],
    'encrypted_description' => $req['description'], 'scope_points' => null,
    'created_at' => $req['updated_at'] ?? $req['created_at'], 'pm_acknowledged_at' => null,
];

// Default comparison: the most recent snapshot (what was last reviewed)
// against the live row (what's there now) — i.e. exactly the delta a PM
// needs to acknowledge. Explicit ?from=&to= overrides pick among any two.
$to_v   = isset($_GET['to'])   ? (int)$_GET['to']   : $live_v;
$from_v = isset($_GET['from']) ? (int)$_GET['from'] : $latest_snapshot_v;

$find_version = function (int $n) use ($versions, $live_version, $live_v) {
    if ($n === $live_v) return $live_version;
    foreach ($versions as $v) if ((int)$v['version_number'] === $n) return $v;
    return null;
};
$from = $find_version($from_v);
$to   = $find_version($to_v);

$diff_lines    = ($from && $to) ? astra_reqver_diff_lines($from['encrypted_description'], $to['encrypted_description']) : [];
$drift_percent = ($from && $to) ? astra_reqver_scope_drift_percent($from['encrypted_description'], $to['encrypted_description']) : 0.0;
$title_changed = $from && $to && $from['encrypted_title'] !== $to['encrypted_title'];

$latest = $has_versions ? end($versions) : null;
$can_acknowledge = !empty($req['has_pending_revision']) && $latest && empty($latest['pm_acknowledged_at']);

$nav_path = $user_role === 'admin' ? 'admin' : 'client';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Requirement Revisions · Hastra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>"><link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-contrast.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; background: var(--navy); color: var(--text); font-family: var(--font-sans);
         background-image: linear-gradient(var(--grid-line) 1px, transparent 1px), linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
         background-size: 40px 40px; }
  .main { max-width: 900px; margin: 0 auto; padding: 2rem 1.2rem 4rem; }
  h1 { font-size: 22px; font-weight: 600; margin: 0 0 4px; }
  .lede { color: var(--text-dim); font-size: 13px; margin: 0 0 1.4rem; }
  .section { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; padding: 1.4rem; margin-bottom: 1.4rem; }
  .alert { border-radius: 3px; padding: 12px 14px; margin-bottom: 1.2rem; font-size: 13px; border-left: 3px solid; }
  .alert.success { background: var(--green-bg); border-color: var(--green); color: #86efac; }
  .alert.error   { background: var(--red-bg);   border-color: var(--red);   color: #fca5a5; }
  .stat-row { display: flex; gap: 1.6rem; flex-wrap: wrap; margin-bottom: 1rem; }
  .stat { font-family: 'Share Tech Mono', monospace; }
  .stat .num { font-size: 22px; font-weight: 600; }
  .stat .lbl { font-size: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.06em; }
  .drift-pos { color: var(--red); } .drift-neg { color: var(--green); } .drift-zero { color: var(--text-dim); }
  .ver-picker { display: flex; gap: 10px; align-items: center; font-size: 12px; margin-bottom: 1rem; }
  .ver-picker select { background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text); border-radius: 3px; padding: 6px 10px; }
  .diff-line { font-family: 'Share Tech Mono', monospace; font-size: 12.5px; padding: 3px 10px; white-space: pre-wrap; word-break: break-word; border-radius: 2px; }
  .diff-line.added   { background: var(--green-bg); color: #86efac; }
  .diff-line.removed { background: var(--red-bg); color: #fca5a5; text-decoration: line-through; text-decoration-color: rgba(252,165,165,0.5); }
  .diff-line.same    { color: var(--text-dim); }
  .btn { background: var(--accent); color: #fff; border: none; border-radius: 3px; font-size: 13px; font-weight: 600;
         letter-spacing: 0.04em; text-transform: uppercase; padding: 9px 18px; cursor: pointer; margin-top: 1rem; }
  .btn:hover { background: var(--accent-dim); }
  .badge { padding: 3px 10px; border-radius: 10px; font-size: 10px; text-transform: uppercase; }
  .badge.pending { background: var(--yellow-bg, rgba(234,179,8,0.12)); color: var(--yellow, #facc15); }
  .badge.ack { background: var(--green-bg); color: #86efac; }
  .back { font-size: 12px; color: var(--text-dim); text-decoration: none; }
</style>
</head>
<body>
<div class="main">
  <a class="back" href="<?= get_base_url() ?>workspace/<?= $nav_path ?>/<?= $nav_path === 'admin' ? 'admin_portal' : 'my_requirements' ?>">&larr; Back</a>
  <h1>Requirement Revisions</h1>
  <p class="lede"><?= htmlspecialchars($req['requirement_id']) ?>: <?= htmlspecialchars($req['requirement_title']) ?></p>

  <?php if ($msg): ?>
  <div class="alert <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <?php if (!$has_versions): ?>
  <div class="section"><p style="color:var(--text-dim);font-size:13px;">No version history yet for this requirement.</p></div>
  <?php else: ?>

  <div class="section">
    <div class="stat-row">
      <div class="stat"><div class="num"><?= count($versions) ?></div><div class="lbl">Version(s)</div></div>
      <div class="stat">
        <div class="num <?= $drift_percent > 0 ? 'drift-pos' : ($drift_percent < 0 ? 'drift-neg' : 'drift-zero') ?>">
          <?= $drift_percent > 0 ? '+' : '' ?><?= $drift_percent ?>%
        </div>
        <div class="lbl">Scope drift (v<?= $from_v === $live_v ? 'current' : $from_v ?> &rarr; v<?= $to_v === $live_v ? 'current' : $to_v ?>)</div>
      </div>
      <?php if (!empty($req['has_pending_revision'])): ?>
      <div class="stat"><span class="badge pending">Awaiting PM acknowledgment</span></div>
      <?php elseif ((int)$req['current_version'] > 1): ?>
      <div class="stat"><span class="badge ack">Acknowledged &middot; v<?= (int)$req['current_version'] ?> active</span></div>
      <?php endif; ?>
    </div>

    <?php
      $picker_options = $versions;
      $picker_options[] = $live_version; // selectable in both dropdowns, labeled "current"
    ?>
    <form method="GET" action="requirements-diff" class="ver-picker">
      <input type="hidden" name="req_id" value="<?= $req_id ?>">
      Compare v<select name="from" onchange="this.form.submit()">
        <?php foreach ($picker_options as $v): $n = (int)$v['version_number']; ?>
        <option value="<?= $n ?>" <?= $n === $from_v ? 'selected' : '' ?>>
          <?= $n === $live_v ? 'current' : $n ?> (<?= htmlspecialchars(date('d M', strtotime($v['created_at']))) ?>)
        </option>
        <?php endforeach; ?>
      </select>
      &rarr; v<select name="to" onchange="this.form.submit()">
        <?php foreach ($picker_options as $v): $n = (int)$v['version_number']; ?>
        <option value="<?= $n ?>" <?= $n === $to_v ? 'selected' : '' ?>>
          <?= $n === $live_v ? 'current' : $n ?> (<?= htmlspecialchars(date('d M', strtotime($v['created_at']))) ?>)
        </option>
        <?php endforeach; ?>
      </select>
    </form>

    <?php if ($title_changed): ?>
    <p style="font-size:12px;color:var(--text-dim);margin-bottom:10px;">
      Title: <span class="diff-line removed" style="display:inline;"><?= htmlspecialchars($from['encrypted_title']) ?></span>
      &rarr; <span class="diff-line added" style="display:inline;"><?= htmlspecialchars($to['encrypted_title']) ?></span>
    </p>
    <?php endif; ?>

    <div>
      <?php if (!$diff_lines): ?>
        <p style="color:var(--text-dim);font-size:12.5px;">No description text to compare.</p>
      <?php endif; ?>
      <?php foreach ($diff_lines as $line): ?>
      <div class="diff-line <?= $line['type'] ?>"><?= $line['type'] === 'added' ? '+ ' : ($line['type'] === 'removed' ? '- ' : '  ') ?><?= htmlspecialchars($line['text']) ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($can_acknowledge): ?>
  <div class="section">
    <p style="font-size:13px;color:var(--text-dim);margin-bottom:10px;">
      This revision hasn't been acknowledged yet, so <?= htmlspecialchars($req['requirement_id']) ?> cannot be turned into a project until a PM signs off on it.
    </p>
    <form method="POST" action="requirements-diff">
      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
      <input type="hidden" name="action" value="acknowledge">
      <input type="hidden" name="req_id" value="<?= $req_id ?>">
      <button type="submit" class="btn">Acknowledge Revision</button>
    </form>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>
</body>
</html>
