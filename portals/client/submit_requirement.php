<?php
// portals/client/submit_requirement.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "client");

$ctx           = client_context($conn, (int)$_SESSION["user_id"]);
$scope_ids     = id_list($ctx['member_ids']);
$is_it_manager = client_can($ctx, 'manage_team');
$can_submit    = client_can($ctx, 'submit_requirement');
if (!client_can($ctx, 'view_projects')) {
    header("Location: " . get_base_url() . "portals/client/docs");
    exit();
}

$msg      = "";
$msg_type = "error";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    verify_csrf_token();

    $project_title     = trim($_POST["project_title"] ?? "");
    $requirement_title = trim($_POST["requirement_title"] ?? "");
    $description       = trim($_POST["description"] ?? "");
    $expected_features = trim($_POST["expected_features"] ?? "");
    $budget_min        = ($_POST["budget_min"] !== "") ? (float)$_POST["budget_min"] : null;
    $budget_max        = ($_POST["budget_max"] !== "") ? (float)$_POST["budget_max"] : null;
    $deadline          = ($_POST["deadline"] !== "") ? $_POST["deadline"] : null;

    $related_raw = (int)($_POST["related_project_id"] ?? 0);
    $related_ok  = true;
    if ($related_raw) {
        $rel = mysqli_prepare($conn,
            "SELECT p.id FROM projects p JOIN requirements r ON r.id = p.requirement_id
             WHERE p.id = ? AND p.status = 'completed' AND r.user_id IN ($scope_ids)");
        mysqli_stmt_bind_param($rel, "i", $related_raw);
        mysqli_stmt_execute($rel);
        mysqli_stmt_store_result($rel);
        $related_ok = mysqli_stmt_num_rows($rel) > 0;
    }

    if (!$can_submit) {
        $msg = "Only your company's Project Manager can submit requirements.";
    } elseif (!$related_ok) {
        $msg = "Change requests can only be raised against your company's completed projects.";
    } elseif ($project_title === "" || $requirement_title === "" || $description === "") {
        $msg = "Project title, requirement title and description are required.";
    } elseif ($budget_min !== null && $budget_max !== null && $budget_min > $budget_max) {
        $msg = "Minimum budget cannot be greater than maximum budget.";
    } elseif ($deadline !== null && strtotime($deadline) < strtotime("today")) {
        $msg = "Deadline cannot be in the past.";
    } else {
        $user_id = (int)$_SESSION["user_id"];

        // ── Requirement ID: 26R0001 (race-free, never reuses a number) ─────
        $requirement_id = next_code($conn, "R");

        $related_project_id = ($_POST["related_project_id"] ?? "") !== "" ? (int)$_POST["related_project_id"] : null;
        $request_type = $related_project_id ? "change_request" : "new_project";

        $description_enc       = astra_db_encrypt($description);
        $expected_features_enc = astra_db_encrypt($expected_features);

        $stmt = mysqli_prepare($conn, "INSERT INTO requirements (requirement_id, user_id, project_title, requirement_title, description, expected_features, budget_min, budget_max, deadline, status, related_project_id, request_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_review', ?, ?)");
        mysqli_stmt_bind_param($stmt, "sisssssssss",
          $requirement_id,
          $user_id,
          $project_title,
          $requirement_title,
          $description_enc,
          $expected_features_enc,
          $budget_min,
          $budget_max,
          $deadline,
          $related_project_id,
          $request_type
        );

        if (mysqli_stmt_execute($stmt)) {
            $msg      = "Requirement submitted successfully. Your requirement ID is: <strong>$requirement_id</strong>";
            $msg_type = "success";
        } else {
            $msg = "Failed to submit requirement. Please try again.";
        }
    }
}

// ── Fetch this client's completed projects for the "change request" option ───
$cp_stmt = mysqli_prepare($conn,
  "SELECT p.id, p.project_code, p.title FROM projects p
  JOIN requirements r ON p.requirement_id = r.id
  WHERE r.user_id IN ($scope_ids) AND p.status = 'completed'
  ORDER BY p.title ASC");
mysqli_stmt_execute($cp_stmt);
$my_completed_projects = mysqli_stmt_get_result($cp_stmt)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Submit Requirement · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/custom-dropdowns.css?v=<?= ASSET_VERSION ?>">
<script src="<?= get_base_url() ?>assets/js/custom-dropdowns.js?v=<?= ASSET_VERSION ?>"></script>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= get_base_url() ?>core/client_pages.css">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    min-height: 100vh;
    background-color: var(--navy);
    background-image:
      linear-gradient(var(--grid-line) 1px, transparent 1px),
      linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
    background-size: 40px 40px;
    font-family: 'Inter', sans-serif;
    color: var(--text);
    transition: var(--transition);
  }

  .main { max-width: 1100px; margin: 0 auto; padding: 2rem; }

  .page-header { margin-bottom: 2rem; }
  .page-header h1 { font-size: 24px; font-weight: 600; color: var(--text); letter-spacing: -0.01em; margin-bottom: 4px; }
  .page-header p  { font-size: 13px; color: var(--text-dim); }

  .section {
    background: var(--navy-card);
    border: 1px solid var(--border-dim);
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 1.5rem;
  }

  .section-header {
    display: flex; align-items: center; gap: 8px;
    padding: 1rem 1.4rem;
    border-bottom: 1px solid var(--border-dim);
    background: var(--section-header-bg);
    font-size: 13px; font-weight: 600;
    color: var(--text);
    letter-spacing: 0.03em; text-transform: uppercase;
  }
  .section-header svg { width: 15px; height: 15px; fill: var(--accent-bright); }

  .section-body { padding: 1.4rem; }

  .alert {
    display: flex; align-items: flex-start; gap: 8px;
    border-radius: 3px; padding: 10px 14px;
    margin-bottom: 1.2rem; font-size: 13px;
    border-left: 3px solid; line-height: 1.5;
  }
  .alert.success { background: var(--green-bg); border-color: var(--green); color: #86efac; }
  .alert.error   { background: var(--red-bg);   border-color: var(--red);   color: #fca5a5; }
  .alert svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; }
  .alert.success svg { fill: var(--green); }
  .alert.error   svg { fill: var(--red); }

  .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }

  .field { margin-bottom: 1.1rem; }

  label {
    display: block; font-size: 11px; font-weight: 500;
    color: var(--text-dim); letter-spacing: 0.07em;
    text-transform: uppercase; margin-bottom: 6px;
  }
  label .optional { text-transform: none; color: var(--text-dim); font-weight: 400; letter-spacing: 0; }

  input[type="text"],
  input[type="number"],
  input[type="date"],
  textarea {
    width: 100%;
    background: var(--input-bg);
    border: 1px solid var(--border-dim);
    border-radius: 3px;
    color: var(--text);
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    padding: 10px 12px;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
    resize: vertical;
  }

  input:focus, textarea:focus {
    border-color: var(--accent-bright);
    box-shadow: 0 0 0 3px rgba(var(--accent-rgb),0.1);
  }

  input::placeholder, textarea::placeholder { color: var(--text-dim); }
  textarea { min-height: 100px; }

  .char-count {
    text-align: right; font-size: 11px;
    color: var(--text-dim); margin-top: 4px;
    font-family: 'Share Tech Mono', monospace;
  }
  .char-count.warn { color: var(--yellow); }
  .char-count.over { color: var(--red); }

  .btn-submit {
    background: var(--accent); color: white;
    border: none; border-radius: 3px;
    font-family: 'Inter', sans-serif;
    font-size: 14px; font-weight: 600;
    letter-spacing: 0.04em; text-transform: uppercase;
    padding: 11px 28px; cursor: pointer;
    transition: background 0.2s, box-shadow 0.2s;
    margin-top: 0.5rem;
  }
  .btn-submit:hover { background: var(--accent-dim); box-shadow: 0 0 20px rgba(var(--accent-rgb),0.3); }
</style>
</head>
<body>

<?php $nav_current = 'projects'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1><?= $can_submit ? 'Submit New Requirement' : 'Requirements' ?></h1>
    <p><?= $can_submit ? 'Describe what you need built and track it through review.' : "Only your company's Project Manager can submit new requirements." ?></p>
  </div>

  <div class="section">
    <div class="section-header">
      <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg>
      <?= $can_submit ? 'Submit New Requirement' : 'Requirements' ?>
    </div>
    <div class="section-body">

      <?php if ($msg): ?>
      <div class="alert <?= $msg_type ?>">
        <?php if ($msg_type === 'success'): ?>
          <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
        <?php else: ?>
          <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
        <?php endif; ?>
        <span><?= $msg ?></span>
      </div>
      <?php endif; ?>

      <?php if (!$can_submit): ?>
      <p style="font-size:13px;color:var(--text-dim);line-height:1.6;">
        Only your company's Project Manager can submit new requirements.
        <?php if ($is_it_manager): ?>You can make someone a Project Manager on the <a href="<?= get_base_url() ?>portals/client/team" style="color:var(--accent-bright);">Team</a> page.<?php endif; ?>
      </p>
      <?php else: ?>
      <form method="POST" action="submit_requirement" id="reqForm">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

        <?php if (!empty($my_completed_projects)): ?>
        <div class="field">
          <label for="related_project_id">Request Type</label>
          <select name="related_project_id" id="related_project_id">
            <option value="">New Project (unrelated to existing work)</option>
            <?php foreach ($my_completed_projects as $cp): ?>
            <option value="<?= $cp['id'] ?>">Change Request — <?= htmlspecialchars($cp['project_code'] . ': ' . $cp['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div class="form-grid-2">
          <div class="field">
            <label for="project_title">Project Title</label>
            <input type="text" name="project_title" id="project_title"
                   maxlength="200" required placeholder="e.g. LearnEasy EdTech Platform">
          </div>
          <div class="field">
            <label for="requirement_title">Requirement Title</label>
            <input type="text" name="requirement_title" id="requirement_title"
                   maxlength="200" required placeholder="e.g. Student Video Learning Module">
          </div>
        </div>

        <div class="field">
          <label for="description">Detailed Description</label>
          <textarea name="description" id="description" maxlength="2000" required
                    placeholder="Describe exactly what you need built..."
                    oninput="countChars('description', 2000)"></textarea>
          <div class="char-count" id="description_count">0 / 2000</div>
        </div>

        <div class="field">
          <label for="expected_features">Expected Features <span class="optional">(optional)</span></label>
          <textarea name="expected_features" id="expected_features" maxlength="1000"
                    placeholder="List the key features you expect, one per line e.g.&#10;- User login&#10;- Video streaming&#10;- Progress tracker"
                    oninput="countChars('expected_features', 1000)"></textarea>
          <div class="char-count" id="expected_features_count">0 / 1000</div>
        </div>

        <div class="form-grid-3">
          <div class="field">
            <label for="budget_min">Budget Min <span class="optional">(₹, optional)</span></label>
            <input type="number" name="budget_min" id="budget_min" min="0" step="500" placeholder="e.g. 50000">
          </div>
          <div class="field">
            <label for="budget_max">Budget Max <span class="optional">(₹, optional)</span></label>
            <input type="number" name="budget_max" id="budget_max" min="0" step="500" placeholder="e.g. 150000">
          </div>
          <div class="field">
            <label for="deadline">Expected Deadline <span class="optional">(optional)</span></label>
            <input type="date" name="deadline" id="deadline">
          </div>
        </div>

        <button type="submit" class="btn-submit">Submit Requirement</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
function countChars(id, max) {
  const el    = document.getElementById(id);
  const count = document.getElementById(id + '_count');
  const len   = el.value.length;
  count.textContent = len + ' / ' + max;
  count.className   = 'char-count' + (len >= max ? ' over' : len >= max * 0.9 ? ' warn' : '');
}

const deadlineEl = document.getElementById('deadline');
if (deadlineEl) deadlineEl.min = new Date().toISOString().split('T')[0];

const reqForm = document.getElementById('reqForm');
if (reqForm) {
  reqForm.addEventListener('submit', function(e) {
    const min = parseFloat(document.getElementById('budget_min').value);
    const max = parseFloat(document.getElementById('budget_max').value);
    if (!isNaN(min) && !isNaN(max) && min > max) {
      e.preventDefault();
      alert('Minimum budget cannot be greater than maximum budget.');
    }
  });
}
</script>

</body>
</html>
