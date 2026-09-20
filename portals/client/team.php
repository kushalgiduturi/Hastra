<?php
// portals/client/team.php
// IT Manager workspace: upload the staff roster, review what the parser found,
// create accounts, send activation invites and track who has activated.
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "client");

$user_id = (int)$_SESSION["user_id"];
$ready   = onboarding_schema_ready($conn);

$me = null; $company = null;
if ($ready) {
    $q = mysqli_prepare($conn, "SELECT id, name, email, company_id, client_role FROM users WHERE id = ?");
    mysqli_stmt_bind_param($q, "i", $user_id);
    mysqli_stmt_execute($q);
    $me      = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
    $company = get_company($conn, $me["company_id"] ?? 0);
}
if (!$ready || !$me || $me["client_role"] !== "it_manager" || !$company || !empty($company["is_internal"])) {
    http_response_code(403);
    $reason = !$ready
        ? "Team management isn't switched on yet. Ask the Astra sysadmin to run the database migration."
        : "Only your company's IT Manager can manage the team.";
    echo "<!DOCTYPE html><meta charset='utf-8'><title>Team · Astra</title><p style='font-family:sans-serif;padding:2rem'>"
       . htmlspecialchars($reason) . " <a href='" . get_base_url() . "portals/client/client_portal'>Back to the client portal</a></p>";
    exit();
}
$company_id = (int)$company["id"];

$flash = $_SESSION["team_flash"] ?? null;
unset($_SESSION["team_flash"]);
function team_redirect($type, $text) {
    $_SESSION["team_flash"] = ['type' => $type, 'text' => $text];
    header("Location: " . get_base_url() . "portals/client/team");
    exit();
}

function open_import($conn, $company_id) {
    $q = mysqli_prepare($conn, "SELECT * FROM roster_imports WHERE company_id = ? AND status = 'parsed' ORDER BY id DESC LIMIT 1");
    mysqli_stmt_bind_param($q, "i", $company_id);
    mysqli_stmt_execute($q);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($q)) ?: null;
}

function staging_rows($conn, $import_id) {
    $q = mysqli_prepare($conn, "SELECT * FROM roster_staging WHERE import_id = ? ORDER BY status = 'pending' DESC, source_row IS NULL, source_row, id");
    mysqli_stmt_bind_param($q, "i", $import_id);
    mysqli_stmt_execute($q);
    return mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC);
}

function set_company_status($conn, $company_id, $status, array $only_from = []) {
    $sql = "UPDATE companies SET onboarding_status = ? WHERE id = ?";
    if ($only_from) $sql .= " AND onboarding_status IN ('" . implode("','", $only_from) . "')";
    $s = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($s, "si", $status, $company_id);
    mysqli_stmt_execute($s);
}

// Saves the review grid for the open import (edits, include toggles, added rows).
function save_staging($conn, $import_id) {
    $upd = mysqli_prepare($conn,
        "UPDATE roster_staging SET full_name = ?, email = ?, phone_number = ?, client_role = ?, include_row = ?
         WHERE id = ? AND import_id = ? AND status = 'pending'");
    foreach ((array)($_POST["rows"] ?? []) as $id => $r) {
        $name  = mb_substr(trim((string)($r["name"] ?? "")), 0, 100);
        $email = mb_substr(strtolower(trim((string)($r["email"] ?? ""))), 0, 100);
        $phone = mb_substr(preg_replace('/[^\d+]/', '', (string)($r["phone"] ?? "")), 0, 15);
        $role  = isset(CLIENT_ROLES[$r["role"] ?? ""]) ? $r["role"] : "teammate";
        $inc   = !empty($r["include"]) ? 1 : 0;
        $id    = (int)$id;
        mysqli_stmt_bind_param($upd, "ssssiii", $name, $email, $phone, $role, $inc, $id, $import_id);
        mysqli_stmt_execute($upd);
    }
    $ins = mysqli_prepare($conn,
        "INSERT INTO roster_staging (import_id, source_row, full_name, email, phone_number, client_role, confidence, include_row)
         VALUES (?, NULL, ?, ?, ?, ?, NULL, 1)");
    foreach ((array)($_POST["new"] ?? []) as $r) {
        $name  = mb_substr(trim((string)($r["name"] ?? "")), 0, 100);
        $email = mb_substr(strtolower(trim((string)($r["email"] ?? ""))), 0, 100);
        if ($name === "" && $email === "") continue;
        $phone = mb_substr(preg_replace('/[^\d+]/', '', (string)($r["phone"] ?? "")), 0, 15);
        $role  = isset(CLIENT_ROLES[$r["role"] ?? ""]) ? $r["role"] : "teammate";
        mysqli_stmt_bind_param($ins, "issss", $import_id, $name, $email, $phone, $role);
        mysqli_stmt_execute($ins);
    }
}

function member_in_company($conn, $member_id, $company_id) {
    $q = mysqli_prepare($conn, "SELECT id, name, email, client_role FROM users WHERE id = ? AND company_id = ? AND role = 'client'");
    mysqli_stmt_bind_param($q, "ii", $member_id, $company_id);
    mysqli_stmt_execute($q);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($q)) ?: null;
}

// ── Actions ───────────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $action = $_POST["action"] ?? "";

    if ($action === "export_logs") {
        export_company_logs_csv($conn, $company_id, $company["company_name"]);
        exit();
    }

    $import = open_import($conn, $company_id);

    if ($action === "upload_roster") {
        if ($import) team_redirect('error', "Finish or discard the roster you're reviewing before uploading another.");
        $f = $_FILES["roster"] ?? null;
        if (!$f || $f["error"] !== UPLOAD_ERR_OK)  team_redirect('error', "Choose a roster file to upload.");
        if ($f["size"] > ROSTER_MAX_BYTES)          team_redirect('error', "The file is larger than 2 MB. Split it or remove unused sheets.");
        $ext = strtolower(pathinfo($f["name"], PATHINFO_EXTENSION));
        if (!in_array($ext, ["xlsx", "csv"], true)) team_redirect('error', "Upload an Excel (.xlsx) or CSV file.");
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $f["tmp_name"]);
        finfo_close($finfo);
        $ok_mime = $ext === "xlsx"
            ? in_array($mime, ["application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "application/zip", "application/octet-stream"], true)
            : in_array($mime, ["text/csv", "text/plain", "application/csv", "text/x-csv", "application/vnd.ms-excel"], true);
        if (!$ok_mime) team_redirect('error', "That file doesn't look like a real ." . $ext . " file.");

        $parsed = parse_roster_file($f["tmp_name"], $ext);
        if (empty($parsed["ok"]))    team_redirect('error', $parsed["error"] ?? "Couldn't read the roster.");
        if (empty($parsed["rows"]))  team_redirect('error', "No people were found under the header row.");

        $file_name = mb_substr(basename($f["name"]), 0, 255);
        $parser    = $parsed["parser"] ?? "php";
        $notes     = json_encode([
            'header_row' => $parsed["header_row"] ?? null,
            'mapping'    => $parsed["mapping"] ?? null,
            'warnings'   => $parsed["warnings"] ?? [],
        ]);
        mysqli_begin_transaction($conn);
        $imp = mysqli_prepare($conn, "INSERT INTO roster_imports (company_id, uploaded_by, file_name, parser, notes) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($imp, "iisss", $company_id, $user_id, $file_name, $parser, $notes);
        mysqli_stmt_execute($imp);
        $import_id = mysqli_insert_id($conn);
        $row = mysqli_prepare($conn,
            "INSERT INTO roster_staging (import_id, source_row, full_name, email, phone_number, client_role, role_raw, confidence)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($parsed["rows"] as $r) {
            $src  = (int)$r["row"]; $name = (string)$r["name"]; $email = (string)$r["email"];
            $ph   = (string)$r["phone"]; $role = isset(CLIENT_ROLES[$r["role"]]) ? $r["role"] : "teammate";
            $raw  = (string)($r["role_raw"] ?? ""); $conf = (float)($r["confidence"] ?? 1);
            mysqli_stmt_bind_param($row, "iisssssd", $import_id, $src, $name, $email, $ph, $role, $raw, $conf);
            mysqli_stmt_execute($row);
        }
        mysqli_commit($conn);
        set_company_status($conn, $company_id, 'roster_staged', ['registered']);
        team_redirect('success', count($parsed["rows"]) . " people found in $file_name. Check the list below, fix anything flagged, then create the accounts.");
    }

    if (in_array($action, ["save_staging", "confirm_import", "discard_import"], true)) {
        if (!$import) team_redirect('error', "There's no roster waiting for review.");
        $import_id = (int)$import["id"];

        if ($action === "discard_import") {
            $d = mysqli_prepare($conn, "UPDATE roster_imports SET status = 'discarded' WHERE id = ?");
            mysqli_stmt_bind_param($d, "i", $import_id);
            mysqli_stmt_execute($d);
            team_redirect('success', "Roster discarded. No accounts were created from it.");
        }

        save_staging($conn, $import_id);
        if ($action === "save_staging") team_redirect('success', "Changes saved.");

        // confirm_import: create every included row that has no problems.
        $rows = validate_staging_rows($conn, staging_rows($conn, $import_id), $company);
        $created = 0; $unsent = 0; $blocked = 0; $full = false;
        $mark = mysqli_prepare($conn, "UPDATE roster_staging SET status = ?, user_id = ? WHERE id = ?");
        foreach ($rows as $r) {
            if ($r["status"] !== "pending" || !$r["include_row"]) continue;
            if ($r["problems"]) { $blocked++; continue; }
            $err = null;
            $new_id = insert_user_in_company($conn, $company, [
                'name'         => $r["full_name"],
                'email'        => strtolower(trim($r["email"])),
                'phone_number' => $r["phone_number"],
                'password'     => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                'role'         => 'client',
                'client_role'  => $r["client_role"],
            ], $err);
            if (!$new_id) { $blocked++; if ($err && strpos($err, 'full') !== false) $full = true; continue; }
            $status = 'created'; $rid = (int)$r["id"];
            mysqli_stmt_bind_param($mark, "sii", $status, $new_id, $rid);
            mysqli_stmt_execute($mark);
            send_activation_invite($conn, $new_id, $r["full_name"], $r["email"], $company["company_name"], $sent);
            $created++;
            if (!$sent) $unsent++;
        }

        if ($blocked === 0) {
            $skip = mysqli_prepare($conn, "UPDATE roster_staging SET status = 'skipped' WHERE import_id = ? AND status = 'pending'");
            mysqli_stmt_bind_param($skip, "i", $import_id);
            mysqli_stmt_execute($skip);
            $done = mysqli_prepare($conn, "UPDATE roster_imports SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($done, "i", $import_id);
            mysqli_stmt_execute($done);
            set_company_status($conn, $company_id, 'roster_confirmed', ['registered', 'roster_staged']);
        }
        try { log_activity($conn, $user_id, "roster_confirmed", $me["name"]); } catch (Throwable $e) {}

        $text = "$created account" . ($created === 1 ? "" : "s") . " created and invited.";
        if ($unsent)  $text .= " $unsent invite email" . ($unsent === 1 ? "" : "s") . " couldn't be sent — use Resend below.";
        if ($full)    $text .= " Your company's ID range is full; contact Astra support.";
        if ($blocked) team_redirect('error', $text . " $blocked row" . ($blocked === 1 ? " still needs" : "s still need") . " fixing — they're highlighted below.");
        team_redirect('success', $text);
    }

    if ($action === "resend_invite") {
        $m = member_in_company($conn, (int)($_POST["member_id"] ?? 0), $company_id);
        if (!$m || (int)$m["id"] === $user_id) team_redirect('error', "That person isn't in your team.");
        send_activation_invite($conn, (int)$m["id"], $m["name"], $m["email"], $company["company_name"], $sent);
        team_redirect($sent ? 'success' : 'error', $sent
            ? "A new activation link was sent to {$m['email']}."
            : "The email to {$m['email']} couldn't be sent. Check the mail server and try again.");
    }

    if ($action === "change_role") {
        $m    = member_in_company($conn, (int)($_POST["member_id"] ?? 0), $company_id);
        $role = $_POST["client_role"] ?? "";
        if (!$m)                            team_redirect('error', "That person isn't in your team.");
        if ((int)$m["id"] === $user_id)     team_redirect('error', "You can't change your own role. Make someone else IT Manager first, and ask them to change yours.");
        if (!isset(CLIENT_ROLES[$role]))    team_redirect('error', "Choose a valid role.");
        $u = mysqli_prepare($conn, "UPDATE users SET client_role = ? WHERE id = ?");
        mysqli_stmt_bind_param($u, "si", $role, $m["id"]);
        mysqli_stmt_execute($u);
        team_redirect('success', "{$m['name']} is now " . client_role_label($role) . ".");
    }

    team_redirect('error', "Unknown action.");
}

// ── Page data ─────────────────────────────────────────────────────────────────
$import  = open_import($conn, $company_id);
$rows    = $import ? validate_staging_rows($conn, staging_rows($conn, $import["id"]), $company) : [];
$notes   = $import ? (json_decode($import["notes"] ?? "", true) ?: []) : [];
$members = company_members_with_activation($conn, $company_id);
$counts  = ['active' => 0, 'pending' => 0, 'expired' => 0];
foreach ($members as $m) $counts[$m['state']]++;

if ($company["onboarding_status"] === 'roster_confirmed' && $counts['pending'] === 0 && $counts['expired'] === 0 && count($members) > 1) {
    set_company_status($conn, $company_id, 'active');
    $company["onboarding_status"] = 'active';
}

$status_labels = [
    'registered'       => 'Registered — upload your roster',
    'roster_staged'    => 'Roster in review',
    'roster_confirmed' => 'Invites sent',
    'active'           => 'Active',
];
$pending_count  = count(array_filter($rows, fn($r) => $r['status'] === 'pending'));
$problem_count  = count(array_filter($rows, fn($r) => $r['status'] === 'pending' && $r['include_row'] && $r['problems']));
$included_count = count(array_filter($rows, fn($r) => $r['status'] === 'pending' && $r['include_row']));
$state_labels   = ['active' => 'Active', 'pending' => 'Invite sent', 'expired' => 'Invite expired'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Team · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/custom-dropdowns.css?v=<?= ASSET_VERSION ?>">
<script src="<?= get_base_url() ?>assets/js/custom-dropdowns.js?v=<?= ASSET_VERSION ?>"></script>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= get_base_url() ?>core/client_pages.css">

<style>
  .modal-overlay {
    display: flex;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.75);
    z-index: 200;
    align-items: center;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
  }
  .modal-overlay.open { opacity: 1; pointer-events: auto; }

  .modal {
    background: var(--navy-card);
    border: 1px solid var(--border);
    border-radius: 4px;
    width: 100%;
    max-width: 420px;
    padding: 2rem;
    position: relative;
  }
  .modal::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--red), transparent);
    border-radius: 4px 4px 0 0;
  }
  .modal h3 { font-size: 16px; font-weight: 600; color: var(--text); margin-bottom: 0.5rem; }
  .modal p  { font-size: 13px; color: var(--text-dim); margin-bottom: 1.4rem; line-height: 1.5; }
  .modal-btns { display: flex; gap: 8px; }
  .modal-btn-confirm {
    flex: 1;
    background: var(--red);
    color: white;
    border: none;
    border-radius: 3px;
    font-family: var(--font-sans);
    font-size: 13px; font-weight: 600;
    padding: 10px;
    cursor: pointer;
    transition: background 0.2s;
    text-transform: uppercase;
  }
  .modal-btn-confirm:hover { background: #dc2626; }
  .modal-btn-cancel {
    flex: 1;
    background: var(--input-bg);
    border: 1px solid var(--border-dim);
    color: var(--text-dim);
    border-radius: 3px;
    font-family: var(--font-sans);
    font-size: 13px; font-weight: 500;
    padding: 10px;
    cursor: pointer;
    transition: background 0.2s;
    text-transform: uppercase;
  }
  .modal-btn-cancel:hover { background: rgba(255,255,255,0.08); color: var(--text); }
</style>
</head>
<body>

<?php $ctx = client_context($conn, $user_id); $nav_current = "team"; include __DIR__ . "/_nav.php"; ?>

<div class="main">
  <div class="page-header">
    <h1><?= htmlspecialchars($company["company_name"]) ?> team</h1>
    <p>Upload your staff roster, check what Astra read from it, and invite everyone. People can sign in once they open their activation email and choose a password.</p>
    <div class="company-meta">
      <span>Status <b><?= htmlspecialchars($status_labels[$company["onboarding_status"]] ?? $company["onboarding_status"]) ?></b></span>
      <span>Email domain <b>@<?= htmlspecialchars($company["email_domain"]) ?></b></span>
      <?php if (!empty($company["size_band"])): ?><span>Size <b><?= htmlspecialchars(COMPANY_SIZES[$company["size_band"]] ?? $company["size_band"]) ?></b></span><?php endif; ?>
      <?php if (!empty($company["contract_ref"])): ?><span>Contract <b><?= htmlspecialchars($company["contract_ref"]) ?></b></span><?php endif; ?>
      <span>Astra IDs <b><?= htmlspecialchars(company_range_label($company)) ?></b></span>
    </div>
    <form method="POST" action="team" style="margin-top:10px;">
      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
      <input type="hidden" name="action" value="export_logs">
      <button type="submit" class="btn" style="font-size:12px;">Export activity log (CSV)</button>
    </form>
  </div>

  <?php if ($flash): ?>
  <div class="flash <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['text']) ?></div>
  <?php endif; ?>

  <div class="stats">
    <div class="stat"><div class="label">Team members</div><div class="value"><?= count($members) ?></div></div>
    <div class="stat ok"><div class="label">Active</div><div class="value"><?= $counts['active'] ?></div></div>
    <div class="stat wait"><div class="label">Invite sent</div><div class="value"><?= $counts['pending'] ?></div></div>
    <div class="stat bad"><div class="label">Invite expired</div><div class="value"><?= $counts['expired'] ?></div></div>
  </div>

  <?php if (!$import): ?>
  <!-- ── UPLOAD ── -->
  <section class="section">
    <div class="section-header">
      <div><div class="section-title">Upload a roster</div><div class="section-sub">Excel (.xlsx) or CSV, up to 2 MB</div></div>
    </div>
    <div class="section-body">
      <form class="upload" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action" value="upload_roster">
        <input type="file" name="roster" id="roster" accept=".xlsx,.csv" required>
        <button type="submit" class="primary" id="btnUpload">Upload and read</button>
      </form>
      <p class="hint">
        Put one person per row with columns for name (or first and last name), email, phone and role — for example
        <code>Name · Email · Phone · Role</code>. Header names don't need to match exactly.
        Roles containing "project manager" or "PM" become <b>Project Manager</b>, "IT manager" or "IT admin" become
        <b>IT Manager</b>, and everyone else becomes <b>Teammate</b>. Nothing is created until you confirm.
      </p>
    </div>
  </section>
  <?php else: ?>
  <!-- ── REVIEW ── -->
  <section class="section">
    <div class="section-header">
      <div>
        <div class="section-title">Review <?= htmlspecialchars($import["file_name"]) ?></div>
      </div>
    </div>
    <form method="POST" id="reviewForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
      <div class="section-body" style="padding-bottom:0">
        <?php if (!empty($notes['warnings'])): ?>
        <div class="warnings"><?= implode('<br>', array_map('htmlspecialchars', $notes['warnings'])) ?></div>
        <?php endif; ?>
      </div>
      <div class="tbl-wrap">
        <table class="grid">
          <thead>
            <tr><th>Row</th><th>Invite</th><th>Name</th><th>Email</th><th>Phone</th><th>Astra role</th><th>Check</th></tr>
          </thead>
          <tbody id="gridBody">
            <?php foreach ($rows as $r):
              $pending = $r['status'] === 'pending';
              $cls = !$pending ? 'done' : (!$r['include_row'] ? 'excluded' : ($r['problems'] ? 'has-problem' : ($r['notes'] ? 'has-note' : '')));
              $rid = (int)$r['id'];
            ?>
            <tr class="<?= $cls ?>">
              <td class="num"><?= $r['source_row'] ? (int)$r['source_row'] : 'new' ?></td>
              <?php if ($pending): ?>
              <td><input type="checkbox" name="rows[<?= $rid ?>][include]" value="1" <?= $r['include_row'] ? 'checked' : '' ?> aria-label="Invite this person" onchange="this.closest('tr').classList.toggle('excluded', !this.checked)"></td>
              <td><input type="text"  name="rows[<?= $rid ?>][name]"  value="<?= htmlspecialchars($r['full_name']) ?>" maxlength="100" aria-label="Name"></td>
              <td><input type="email" name="rows[<?= $rid ?>][email]" value="<?= htmlspecialchars($r['email']) ?>" maxlength="100" aria-label="Email"></td>
              <td><input type="text"  name="rows[<?= $rid ?>][phone]" value="<?= htmlspecialchars($r['phone_number']) ?>" maxlength="15" aria-label="Phone"></td>
              <td>
                <select name="rows[<?= $rid ?>][role]" aria-label="Astra role">
                  <?php foreach (CLIENT_ROLES as $val => $label): ?>
                  <option value="<?= $val ?>" <?= $r['client_role'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if ($r['role_raw'] !== null && $r['role_raw'] !== ''): ?><div class="raw">Sheet: <?= htmlspecialchars($r['role_raw']) ?></div><?php endif; ?>
              </td>
              <td>
                <ul class="issues">
                  <?php foreach ($r['problems'] as $p): ?><li class="p"><?= htmlspecialchars($p) ?></li><?php endforeach; ?>
                  <?php foreach ($r['notes'] as $n): ?><li class="n"><?= htmlspecialchars($n) ?></li><?php endforeach; ?>
                  <?php if (!$r['problems'] && !$r['notes']): ?><li class="ok">Ready</li><?php endif; ?>
                </ul>
              </td>
              <?php else: ?>
              <td>—</td>
              <td><?= htmlspecialchars($r['full_name']) ?></td>
              <td><?= htmlspecialchars($r['email']) ?></td>
              <td><?= htmlspecialchars($r['phone_number']) ?></td>
              <td><?= htmlspecialchars(client_role_label($r['client_role'])) ?></td>
              <td><ul class="issues"><li class="ok"><?= $r['status'] === 'created' ? 'Account created' : 'Skipped' ?></li></ul></td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <template id="newRowTpl">
        <tr>
          <td class="num">new</td>
          <td><input type="checkbox" checked disabled aria-label="Invite this person"></td>
          <td><input type="text"  name="new[__i__][name]"  maxlength="100" aria-label="Name" placeholder="Full name"></td>
          <td><input type="email" name="new[__i__][email]" maxlength="100" aria-label="Email" placeholder="name@<?= htmlspecialchars($company['email_domain']) ?>"></td>
          <td><input type="text"  name="new[__i__][phone]" maxlength="15" aria-label="Phone"></td>
          <td><select name="new[__i__][role]" aria-label="Astra role">
            <?php foreach (CLIENT_ROLES as $val => $label): ?><option value="<?= $val ?>" <?= $val === 'teammate' ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
          </select></td>
          <td><ul class="issues"><li class="n">Checked when you save</li></ul></td>
        </tr>
      </template>
      <div class="grid-actions">
        <div class="left">
          <button type="button" onclick="addRow()">Add a person</button>
          <button type="submit" name="action" value="save_staging">Save changes</button>
          <span class="summary"><?= $included_count ?> to invite<?= $problem_count ? " · <b style='color:var(--red)'>$problem_count need fixing</b>" : '' ?></span>
        </div>
        <div class="right">
          <input type="hidden" name="action" id="reviewFormAction" value="">
          <button type="button" class="danger" formnovalidate
                  onclick="openConfirmModal('discard_import', 'Discard this roster?', 'No accounts will be created from it.', 'Discard roster')">Discard roster</button>
          <button type="button" class="primary" id="btnConfirm"
                  <?= $pending_count ? '' : 'disabled' ?>
                  onclick="openConfirmModal('confirm_import', 'Create accounts &amp; send invites?', 'Emails activation links to everyone ticked. Rows that still have problems will be left for you to fix.', 'Create accounts &amp; send invites')">Create accounts &amp; send invites</button>
        </div>
      </div>
    </form>
  </section>
  <?php endif; ?>

  <!-- ── TEAM ── -->
  <section class="section">
    <div class="section-header">
      <div><div class="section-title">Team &amp; activation</div><div class="section-sub">Invite links last <?= INVITE_VALID_HOURS ?> hours. Resend one if it expires.</div></div>
    </div>
    <?php if (!$members): ?>
      <p class="empty">No one yet — upload a roster to add your team.</p>
    <?php else: ?>
    <div class="tbl-wrap">
      <table>
        <thead><tr><th>Astra ID</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Invited</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($members as $m): $is_me = (int)$m['id'] === $user_id; ?>
          <tr>
            <td class="num"><?= (int)$m['id'] ?></td>
            <td><?= htmlspecialchars($m['name']) ?><?= $is_me ? ' <span class="you">(you)</span>' : '' ?></td>
            <td><?= htmlspecialchars($m['email']) ?></td>
            <td>
              <?php if ($is_me): ?>
                <?= htmlspecialchars(client_role_label($m['client_role'])) ?>
              <?php else: ?>
              <form method="POST" class="role-form">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="change_role">
                <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                <select name="client_role" aria-label="Role for <?= htmlspecialchars($m['name']) ?>" onchange="this.form.querySelector('button').hidden = false">
                  <?php foreach (CLIENT_ROLES as $val => $label): ?>
                  <option value="<?= $val ?>" <?= $m['client_role'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="small" hidden>Save</button>
              </form>
              <?php endif; ?>
            </td>
            <td><span class="pill <?= $m['state'] ?>"><?= $state_labels[$m['state']] ?></span></td>
            <td class="num"><?= $m['invited_at'] ? htmlspecialchars(date('d M, H:i', strtotime($m['invited_at']))) : '—' ?></td>
            <td>
              <?php if (!$is_me && $m['state'] !== 'active'): ?>
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="resend_invite">
                <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                <button type="submit" class="small">Resend invite</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
</div>

<div class="modal-overlay" id="confirmModal">
  <div class="modal">
    <h3 id="confirmModalTitle">Are you sure?</h3>
    <p id="confirmModalDesc"></p>
    <div class="modal-btns">
      <button type="button" class="modal-btn-confirm" id="confirmModalBtn" onclick="submitConfirmModal()">Confirm</button>
      <button type="button" class="modal-btn-cancel" onclick="closeConfirmModal()">Cancel</button>
    </div>
  </div>
</div>

<script>
let newRowIndex = 0;
function addRow() {
  const tpl = document.getElementById('newRowTpl').innerHTML.replaceAll('__i__', String(newRowIndex++));
  const body = document.getElementById('gridBody');
  body.insertAdjacentHTML('beforeend', tpl);
  body.lastElementChild.querySelector('input[type=text]').focus();
  document.getElementById('btnConfirm').disabled = false;
}

let pendingReviewAction = null;
function openConfirmModal(action, title, desc, confirmLabel) {
  pendingReviewAction = action;
  document.getElementById('confirmModalTitle').innerHTML = title;
  document.getElementById('confirmModalDesc').innerHTML = desc;
  document.getElementById('confirmModalBtn').textContent = confirmLabel || 'Confirm';
  document.getElementById('confirmModal').classList.add('open');
}
function closeConfirmModal() {
  pendingReviewAction = null;
  document.getElementById('confirmModal').classList.remove('open');
}
function submitConfirmModal() {
  if (!pendingReviewAction) return;
  const form = document.getElementById('reviewForm');
  document.getElementById('reviewFormAction').value = pendingReviewAction;
  closeConfirmModal();
  form.submit();
}
document.getElementById('confirmModal').addEventListener('click', function(e) {
  if (e.target === this) closeConfirmModal();
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeConfirmModal();
});
</script>
</body>
</html>
