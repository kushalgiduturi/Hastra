<?php
// portals/deliveries/dossier.php
// Ephemeral, self-destructing deliverable dossiers.
//   • Admins create one for a delivered project: a credentials note or
//     security report, with an expiry window and a view limit.
//   • Whoever holds the resulting link — client or internal — opens it here.
//     The payload is shown once per allowed view; the moment the view limit
//     or expiry is reached, the stored ciphertext is overwritten with random
//     noise and the row is marked shredded. There is no "recover it" path
//     after that — that's the point.
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn); // any authenticated role — retrieval is scoped per-token below

$user_id   = (int)$_SESSION["user_id"];
$user_role = $_SESSION["user_role"] ?? '';

$msg      = "";
$msg_type = "error";
$revealed = null;   // set after a successful consume
$peek     = null;   // set when showing the "reveal" confirmation step
$new_token = null;  // set once, right after creation

// ── Retrieval: a token was supplied ──────────────────────────────────────────
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

// Honeytoken trap: a decoy dossier token planted in seeded documentation.
// Real dossier tokens are 64-char hex from random_bytes(32); this only ever
// matches the deliberately-formatted decoy.
if ($token !== '') astra_canary_check($conn, 'canary_doc', $token);

if ($token !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reveal') {
    verify_csrf_token();
    astra_dossier_sweep_expired($conn);
    $revealed = astra_dossier_consume($conn, $token, $error);
    if (!$revealed) { $msg = $error; $msg_type = 'error'; }
} elseif ($token !== '') {
    astra_dossier_sweep_expired($conn);
    $peek = astra_dossier_peek($conn, $token, $error);
    if (!$peek) { $msg = $error; $msg_type = 'error'; }
}

// ── Creation: admin only ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_dossier') {
    verify_csrf_token();
    if ($user_role !== 'admin') {
        $msg = "Only an admin can create a dossier.";
    } else {
        $project_id  = (int)($_POST['project_id'] ?? 0);
        $payload     = trim($_POST['payload'] ?? '');
        $max_views   = max(1, (int)($_POST['max_views'] ?? 1));
        $expires_min = max(1, (int)($_POST['expires_minutes'] ?? 1440));

        $pcheck = mysqli_prepare($conn, "SELECT id, project_code, title FROM projects WHERE id = ? AND status = 'completed'");
        mysqli_stmt_bind_param($pcheck, "i", $project_id);
        mysqli_stmt_execute($pcheck);
        $proj = mysqli_fetch_assoc(mysqli_stmt_get_result($pcheck));

        if (!$proj) {
            $msg = "Project not found, or not yet completed.";
        } else {
            $token_out = astra_dossier_create($conn, $project_id, $user_id, $payload, $max_views, $expires_min, $error);
            if ($token_out) {
                $new_token = $token_out;
                $link      = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                           . get_base_url() . 'portals/deliveries/dossier?token=' . $token_out;
                $msg       = "Dossier created for {$proj['project_code']}. This link works {$max_views} time(s) and expires in " . round($expires_min / 60, 1) . "h — it will not be shown again:";
                $msg_type  = 'success';
                try { log_activity($conn, $user_id, "dossier_created", $proj['project_code']); } catch (Throwable $e) {}
            } else {
                $msg = $error;
            }
        }
    }
}

// ── Data for the create form / listing (admin only) ─────────────────────────
$completed_projects = [];
$existing_dossiers   = [];
if ($user_role === 'admin' && $token === '') {
    $pr = mysqli_query($conn, "SELECT id, project_code, title FROM projects WHERE status = 'completed' ORDER BY updated_at DESC");
    $completed_projects = $pr ? mysqli_fetch_all($pr, MYSQLI_ASSOC) : [];

    if (astra_dossier_schema_ready($conn)) {
        $dr = mysqli_query($conn,
            "SELECT d.id, d.max_views, d.view_count, d.expires_at, d.is_shredded, d.created_at,
                    p.project_code, p.title AS project_title
             FROM ephemeral_dossiers d JOIN projects p ON p.id = d.project_id
             ORDER BY d.created_at DESC LIMIT 50");
        $existing_dossiers = $dr ? mysqli_fetch_all($dr, MYSQLI_ASSOC) : [];
    }
}
$nav_path = $user_role === 'client' ? 'client' : ($user_role === 'employee' ? 'emlpoyee' : 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ephemeral Dossier · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; background: var(--navy); color: var(--text); font-family: var(--font-sans);
         background-image: linear-gradient(var(--grid-line) 1px, transparent 1px), linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
         background-size: 40px 40px; }
  .main { max-width: 760px; margin: 0 auto; padding: 2rem 1.2rem 4rem; }
  h1 { font-size: 22px; font-weight: 600; margin: 0 0 4px; }
  .lede { color: var(--text-dim); font-size: 13px; margin: 0 0 1.4rem; line-height: 1.6; }
  .section { background: var(--navy-card); border: 1px solid var(--border-dim); border-radius: 4px; padding: 1.4rem; margin-bottom: 1.4rem; }
  .alert { border-radius: 3px; padding: 12px 14px; margin-bottom: 1.2rem; font-size: 13px; border-left: 3px solid; line-height: 1.6; }
  .alert.success { background: var(--green-bg); border-color: var(--green); color: #86efac; }
  .alert.error   { background: var(--red-bg);   border-color: var(--red);   color: #fca5a5; }
  .token-box { font-family: 'Share Tech Mono', monospace; font-size: 12px; background: var(--input-bg); border: 1px solid var(--border-dim);
               border-radius: 3px; padding: 10px 12px; word-break: break-all; margin-top: 8px; color: var(--accent-bright); }
  label { display: block; font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em; margin: 12px 0 6px; }
  input[type="text"], input[type="number"], textarea, select {
    width: 100%; background: var(--input-bg); border: 1px solid var(--border-dim); color: var(--text);
    border-radius: 3px; font-size: 14px; padding: 9px 12px; outline: none;
  }
  textarea { min-height: 100px; resize: vertical; font-family: inherit; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .btn { background: var(--accent); color: #fff; border: none; border-radius: 3px; font-size: 13px; font-weight: 600;
         letter-spacing: 0.04em; text-transform: uppercase; padding: 10px 20px; cursor: pointer; margin-top: 1rem; }
  .btn:hover { background: var(--accent-dim); }
  .btn.danger { background: var(--red); }
  .payload-box { font-family: 'Share Tech Mono', monospace; font-size: 13px; background: #0d1424; border: 1px solid var(--border-dim);
                 border-radius: 3px; padding: 1rem; white-space: pre-wrap; word-break: break-word; color: #e2e8f0; }
  .shred-note { color: var(--red); font-size: 12px; margin-top: 10px; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 8px; }
  th { text-align: left; padding: 8px 10px; color: var(--text-dim); font-family: 'Share Tech Mono', monospace; text-transform: uppercase; font-size: 10px; border-bottom: 1px solid var(--border-dim); }
  td { padding: 8px 10px; border-bottom: 1px solid rgba(255,255,255,0.04); }
  .badge { padding: 2px 8px; border-radius: 10px; font-size: 10px; text-transform: uppercase; }
  .badge.live { background: var(--green-bg); color: #86efac; }
  .badge.shredded { background: var(--red-bg); color: #fca5a5; }
  .back { font-size: 12px; color: var(--text-dim); text-decoration: none; }
</style>
</head>
<body>
<div class="main">
  <a class="back" href="<?= get_base_url() ?>portals/<?= $nav_path ?>/<?= $nav_path === 'admin' ? 'admin_portal' : ($nav_path === 'client' ? 'client_portal' : 'employee_portal') ?>">&larr; Back</a>
  <h1>Ephemeral Dossier</h1>
  <p class="lede">Single-view (or few-view), time-boxed handover of sensitive material. Once its view limit or expiry is reached, the stored ciphertext is overwritten with random noise — there is nothing left to decrypt afterward.</p>

  <?php if ($msg): ?>
  <div class="alert <?= $msg_type ?>"><?= htmlspecialchars($msg) ?>
    <?php if ($new_token): ?><div class="token-box"><?= htmlspecialchars($link) ?></div><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($revealed): ?>
  <div class="section">
    <div style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;text-transform:uppercase;margin-bottom:10px;">
      <?= htmlspecialchars($revealed['project_code']) ?> — <?= htmlspecialchars($revealed['project_title']) ?>
    </div>
    <div class="payload-box"><?= htmlspecialchars($revealed['payload']) ?></div>
    <div style="font-size:12px;color:var(--text-dim);margin-top:10px;">
      View <?= $revealed['view_count'] ?> of <?= $revealed['max_views'] ?>.
    </div>
    <?php if ($revealed['shredded_now']): ?>
    <div class="shred-note">This was the last permitted view. The dossier's cryptographic data has now been permanently shredded from storage — this link will not work again.</div>
    <?php endif; ?>
  </div>

  <?php elseif ($peek): ?>
  <div class="section">
    <div style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;text-transform:uppercase;margin-bottom:10px;">
      <?= htmlspecialchars($peek['project_code']) ?> — <?= htmlspecialchars($peek['project_title']) ?>
    </div>
    <p style="font-size:13px;color:var(--text-dim);line-height:1.6;">
      This dossier allows <?= (int)$peek['max_views'] ?> view(s) total — <?= (int)$peek['view_count'] ?> already used —
      and expires <?= htmlspecialchars(date('d M Y, H:i', strtotime($peek['expires_at']))) ?>.
      Opening it counts as one view.
    </p>
    <form method="POST" action="dossier">
      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
      <input type="hidden" name="action" value="reveal">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <button type="submit" class="btn danger">Reveal now (uses one view)</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($user_role === 'admin' && $token === ''): ?>
  <div class="section">
    <div style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;text-transform:uppercase;margin-bottom:10px;">Create a dossier</div>
    <?php if (empty($completed_projects)): ?>
      <p style="color:var(--text-dim);font-size:13px;">No completed projects yet.</p>
    <?php else: ?>
    <form method="POST" action="dossier">
      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
      <input type="hidden" name="action" value="create_dossier">
      <label>Project</label>
      <select name="project_id" required>
        <option value="">— Select completed project —</option>
        <?php foreach ($completed_projects as $p): ?>
        <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['project_code'] . ' — ' . $p['title']) ?></option>
        <?php endforeach; ?>
      </select>
      <label>Payload <span style="text-transform:none;color:#475569;">(credentials, security report text, ...)</span></label>
      <textarea name="payload" required></textarea>
      <div class="grid2">
        <div>
          <label>Max views</label>
          <input type="number" name="max_views" value="1" min="1" max="20">
        </div>
        <div>
          <label>Expires in (minutes)</label>
          <input type="number" name="expires_minutes" value="1440" min="1" max="43200">
        </div>
      </div>
      <button type="submit" class="btn">Create dossier link</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if ($existing_dossiers): ?>
  <div class="section">
    <div style="font-size:11px;color:var(--text-dim);font-family:'Share Tech Mono',monospace;text-transform:uppercase;margin-bottom:10px;">Recent dossiers</div>
    <table>
      <thead><tr><th>Project</th><th>Views</th><th>Expires</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($existing_dossiers as $d): ?>
        <tr>
          <td><?= htmlspecialchars($d['project_code']) ?><div style="color:var(--text-dim);font-size:11px;"><?= htmlspecialchars($d['project_title']) ?></div></td>
          <td><?= (int)$d['view_count'] ?> / <?= (int)$d['max_views'] ?></td>
          <td><?= htmlspecialchars(date('d M, H:i', strtotime($d['expires_at']))) ?></td>
          <td><span class="badge <?= $d['is_shredded'] ? 'shredded' : 'live' ?>"><?= $d['is_shredded'] ? 'Shredded' : 'Live' ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
