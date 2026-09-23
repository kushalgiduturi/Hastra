<?php
// portals/admin/doc_editor.php
// Admin workspace for a project's documentation: generate a draft (AI when an
// Anthropic key is configured), edit it, keep versions, approve one version.
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "admin");

$admin_id   = (int)$_SESSION["user_id"];
$project_id = (int)($_GET["project"] ?? $_POST["project_id"] ?? 0);

if (!docs_schema_ready($conn)) {
    exit("Documentation drafts aren't switched on yet. Ask the sysadmin to run the database migration. <a href='admin_portal'>Back</a>");
}

$pq = mysqli_prepare($conn, "SELECT p.*, u.name AS requester_name FROM projects p LEFT JOIN users u ON u.id = p.deployment_requested_by WHERE p.id = ?");
mysqli_stmt_bind_param($pq, "i", $project_id);
mysqli_stmt_execute($pq);
$project = mysqli_fetch_assoc(mysqli_stmt_get_result($pq));
if ($project) $project['requester_name'] = astra_db_decrypt($project['requester_name']);
if (!$project) {
    http_response_code(404);
    exit("Project not found. <a href='admin_portal'>Back to the admin portal</a>");
}

$flash = $_SESSION["doc_flash"] ?? null;
unset($_SESSION["doc_flash"]);
function doc_redirect($project_id, $type, $text, $version = null) {
    $_SESSION["doc_flash"] = ['type' => $type, 'text' => $text];
    header("Location: doc_editor.php?project=" . (int)$project_id . ($version ? "&v=" . (int)$version : ""));
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $action = $_POST["action"] ?? "";

    if ($action === "set_repo") {
        $repo = normalize_repo_url($_POST["repo_url"] ?? "");
        if ($repo === null) doc_redirect($project_id, 'error', "Use a GitHub repository address like https://github.com/owner/repo.");
        $u = mysqli_prepare($conn, "UPDATE projects SET repo_url = ? WHERE id = ?");
        $repo = $repo === '' ? null : $repo;
        mysqli_stmt_bind_param($u, "si", $repo, $project_id);
        mysqli_stmt_execute($u);
        doc_redirect($project_id, 'success', $repo ? "Repository saved. New drafts will read it." : "Repository link removed.");
    }

    if ($action === "generate") {
        @set_time_limit(300);
        $info = [];
        $v = generate_doc_draft($conn, $project_id, $admin_id, $info);
        if (!$v) doc_redirect($project_id, 'error', $info['error'] ?? "Couldn't create a draft.");
        $label = DOC_ENGINE_LABELS[$info['engine']] ?? $info['engine'];
        $text  = "Version $v created ($label).";
        if (!empty($info['warnings'])) $text .= " Notes: " . implode(" ", $info['warnings']);
        doc_redirect($project_id, 'success', $text, $v);
    }

    if ($action === "save") {
        $html = (string)($_POST["body_html"] ?? "");
        if (trim(strip_tags($html)) === "") doc_redirect($project_id, 'error', "The document is empty, so nothing was saved.");
        $v = save_doc_version($conn, $project_id, $admin_id, $html, 'admin');
        doc_redirect($project_id, 'success', "Saved as version $v. Approve it when it's ready.", $v);
    }

    if ($action === "approve") {
        $v = (int)($_POST["version"] ?? 0);
        if (!approve_doc_version($conn, $project_id, $v, $admin_id)) doc_redirect($project_id, 'error', "That version doesn't exist.");
        try { log_activity($conn, $admin_id, "doc_approved " . $project["project_code"] . " v$v"); } catch (Throwable $e) {}
        doc_redirect($project_id, 'success', "Version $v approved. The deployment can now be approved.", $v);
    }
    doc_redirect($project_id, 'error', "Unknown action.");
}

$versions = doc_versions($conn, $project_id);
$current  = doc_version($conn, $project_id, isset($_GET["v"]) ? (int)$_GET["v"] : null);
$approved = approved_doc($conn, $project_id);
$has_key  = anthropic_api_key() !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Documentation <?= htmlspecialchars($project['project_code']) ?> · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= get_base_url() ?>core/client_pages.css">
<link rel="stylesheet" href="<?= get_base_url() ?>core/doc_content.css">
<style>
  .editor-layout { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 1.2rem; align-items: start; }
  @media (max-width: 960px) { .editor-layout { grid-template-columns: minmax(0, 1fr); } }
  .toolbar { display: flex; flex-wrap: wrap; gap: 4px; padding: 0.6rem 0.8rem; border-bottom: 1px solid var(--border-dim); background: var(--section-header-bg); position: sticky; top: 56px; z-index: 5; }
  .toolbar button { padding: 5px 9px; font-size: 12px; font-weight: 600; min-width: 32px; }
  .toolbar .sep { width: 1px; background: var(--border-dim); margin: 2px 4px; }
  .toolbar button[aria-pressed="true"] { border-color: var(--accent-bright); background: var(--hover-bg); }
  #editor { min-height: 480px; padding: 1.4rem 1.6rem; outline: none; }
  #editor:focus { box-shadow: inset 0 0 0 1px var(--border); }
  #source { display: none; width: 100%; min-height: 480px; border: 0; padding: 1rem 1.2rem; background: var(--navy-deep); color: var(--text); font-family: 'Share Tech Mono', monospace; font-size: 12.5px; line-height: 1.6; resize: vertical; }
  .side { display: flex; flex-direction: column; gap: 1rem; }
  .side .section-body { display: flex; flex-direction: column; gap: 10px; }
  .versions { list-style: none; margin: 0; padding: 0; }
  .versions li { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 1.2rem; border-bottom: 1px solid var(--border-dim); font-size: 13px; }
  .versions li:last-child { border-bottom: 0; }
  .versions li.current { background: var(--hover-bg); }
  .versions a { color: var(--text); text-decoration: none; }
  .versions .meta { font-size: 11px; color: var(--text-dim); }
  .side input[type=url] { width: 100%; background: var(--input-bg); border: 1px solid var(--border-dim); border-radius: 3px; color: var(--text); font-size: 13px; padding: 7px 9px; }
  .back { font-size: 12px; color: var(--text-dim); text-decoration: none; }
  .gate { padding: 0.7rem 0.9rem; border-radius: 3px; font-size: 13px; line-height: 1.5; }
  .gate.ok { background: var(--green-bg); color: var(--green); border: 1px solid rgba(34,197,94,0.3); }
  .gate.no { background: var(--yellow-bg); color: var(--yellow); border: 1px solid rgba(245,158,11,0.3); }
  .busy { display: none; font-size: 12px; color: var(--text-dim); }
  form.generating .busy { display: block; }
</style>
</head>
<body>
<?php render_profile_barrier($conn); ?>
<nav class="topnav">
  <div class="nav-left">
    <div class="nav-icon"><svg viewBox="0 0 48 48"><defs><linearGradient id="astraMark" x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="var(--accent-bright)"/><stop offset="1" stop-color="var(--purple, #a78bfa)"/></linearGradient></defs><path fill="url(#astraMark)" d="M24 3 L45 45 H34.4 L24 24.2 L13.6 45 H3 Z"/><path fill="url(#astraMark)" d="M24 14.5 L27.7 22 L35 25.5 L27.7 29 L24 36.5 L20.3 29 L13 25.5 L20.3 22 Z"/></svg></div>
    <span class="nav-title">Astra</span>
    <div class="nav-divider"></div>
    <a class="nav-badge" href="<?= get_base_url() ?>portals/admin/admin_portal">Admin</a>
    <a class="nav-link" href="<?= get_base_url() ?>portals/admin/admin_portal">Admin portal</a>
    <span class="nav-link current">Documentation</span>
  </div>
  <div class="nav-right">
    <button id="themeToggleBtn" onclick="toggleTheme()" class="btn-theme-toggle"><span class="theme-icon"></span><span class="theme-label"></span></button>
    <a href="<?= get_base_url() ?>auth/logout" class="btn-logout">Logout</a>
  </div>
</nav>

<div class="main">
  <div class="page-header">
    <a class="back" href="<?= get_base_url() ?>portals/admin/admin_portal">← Back to the admin portal</a>
    <h1 style="margin-top:8px;"><span class="code-chip"><?= htmlspecialchars($project['project_code']) ?></span><?= htmlspecialchars($project['title']) ?>: documentation</h1>
    <p>Generate a draft, edit it, and approve the version that ships with the delivery. A deployment can't be approved until one version is approved.</p>
  </div>

  <?php if ($flash): ?>
  <div class="flash <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['text']) ?></div>
  <?php endif; ?>

  <div class="editor-layout">
    <section class="section">
      <?php if (!$current): ?>
        <div class="section-body">
          <p class="empty" style="padding:0 0 1rem;">No draft yet. Link the GitHub repository (optional), then generate the first draft.</p>
          <form method="POST" onsubmit="this.classList.add('generating'); this.querySelector('button').disabled = true;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="project_id" value="<?= $project_id ?>">
            <input type="hidden" name="action" value="generate">
            <button type="submit" class="primary" id="btnGenerateFirst"><?= $has_key ? 'Generate AI draft' : 'Generate draft' ?></button>
            <p class="busy">Reading the project<?= $project['repo_url'] ? ' and repository' : '' ?>… this can take up to a minute.</p>
          </form>
        </div>
      <?php else: ?>
      <div class="section-header">
        <div>
          <div class="section-title">Version <?= (int)$current['version'] ?><?= $current['approved_at'] ? ' · approved' : '' ?></div>
          <div class="section-sub"><?= htmlspecialchars(DOC_ENGINE_LABELS[$current['source']] ?? $current['source']) ?> · <?= htmlspecialchars(date('d M Y, H:i', strtotime($current['created_at']))) ?></div>
        </div>
        <?php if (!$current['approved_at']): ?>
        <form method="POST" onsubmit="return confirmApprove()">
          <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
          <input type="hidden" name="project_id" value="<?= $project_id ?>">
          <input type="hidden" name="action" value="approve">
          <input type="hidden" name="version" value="<?= (int)$current['version'] ?>">
          <button type="submit" class="primary" id="btnApprove">Approve version <?= (int)$current['version'] ?></button>
        </form>
        <?php endif; ?>
      </div>
      <form method="POST" id="saveForm" onsubmit="syncEditor()">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="project_id" value="<?= $project_id ?>">
        <input type="hidden" name="action" value="save">
        <div class="toolbar" role="toolbar" aria-label="Formatting">
          <button type="button" data-block="h2" title="Heading">H2</button>
          <button type="button" data-block="h3" title="Subheading">H3</button>
          <button type="button" data-block="p" title="Paragraph">¶</button>
          <span class="sep"></span>
          <button type="button" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>
          <button type="button" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>
          <button type="button" data-inline="code" title="Inline code">&lt;/&gt;</button>
          <span class="sep"></span>
          <button type="button" data-cmd="insertUnorderedList" title="Bulleted list">• List</button>
          <button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
          <button type="button" data-block="blockquote" title="Quote">❝</button>
          <button type="button" data-block="pre" title="Code block">Code</button>
          <button type="button" id="btnLink" title="Link">Link</button>
          <span class="sep"></span>
          <button type="button" data-cmd="undo" title="Undo">↶</button>
          <button type="button" data-cmd="redo" title="Redo">↷</button>
          <span class="sep"></span>
          <button type="button" id="btnSource" aria-pressed="false" title="Edit HTML">HTML</button>
        </div>
        <div id="editor" class="doc-content" contenteditable="true" spellcheck="true"><?= sanitize_doc_html($current['body_html']) ?></div>
        <textarea id="source" name="body_html" aria-label="HTML source"></textarea>
        <div class="grid-actions">
          <span class="summary" id="dirtyNote">No unsaved changes.</span>
          <button type="submit" class="primary" id="btnSave">Save as version <?= count($versions) + 1 ?></button>
        </div>
      </form>
      <?php endif; ?>
    </section>

    <aside class="side">
      <div class="<?= $approved ? 'gate ok' : 'gate no' ?>">
        <?= $approved
            ? 'Version ' . (int)$approved['version'] . ' is approved' . ($project['status'] === 'deployment_pending' ? '. The deployment can be approved.' : '.')
            : 'No approved version yet' . ($project['status'] === 'deployment_pending' ? '. The deployment is waiting on this.' : '.') ?>
      </div>

      <section class="section">
        <div class="section-header"><div class="section-title">Versions</div></div>
        <?php if (!$versions): ?>
          <p class="empty">None yet.</p>
        <?php else: ?>
        <ul class="versions">
          <?php foreach ($versions as $v): ?>
          <li class="<?= $current && (int)$current['version'] === (int)$v['version'] ? 'current' : '' ?>">
            <div>
              <a href="doc_editor.php?project=<?= $project_id ?>&amp;v=<?= (int)$v['version'] ?>">Version <?= (int)$v['version'] ?></a>
              <div class="meta"><?= htmlspecialchars(DOC_ENGINE_LABELS[$v['source']] ?? $v['source']) ?> · <?= htmlspecialchars($v['author'] ?? '-') ?> · <?= htmlspecialchars(date('d M, H:i', strtotime($v['created_at']))) ?></div>
            </div>
            <?php if ($v['approved_at']): ?><span class="pill active">Approved</span><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </section>

      <section class="section">
        <div class="section-header"><div class="section-title">Draft source</div></div>
        <div class="section-body">
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="project_id" value="<?= $project_id ?>">
            <input type="hidden" name="action" value="set_repo">
            <label for="repo_url" class="hint" style="margin:0 0 4px;display:block;">Public GitHub repository</label>
            <input type="url" id="repo_url" name="repo_url" value="<?= htmlspecialchars($project['repo_url'] ?? '') ?>" placeholder="https://github.com/owner/repo">
            <button type="submit" class="small" style="margin-top:6px;">Save repository</button>
          </form>
          <?php if ($current): ?>
          <form method="POST" onsubmit="if (!confirm('Create a new draft? The current versions are kept.')) return false; this.classList.add('generating'); this.querySelector('button').disabled = true;">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="project_id" value="<?= $project_id ?>">
            <input type="hidden" name="action" value="generate">
            <button type="submit" id="btnRegenerate"><?= $has_key ? 'Generate new AI draft' : 'Generate new draft' ?></button>
            <p class="busy">Working… this can take up to a minute.</p>
          </form>
          <?php endif; ?>
          <p class="hint" style="margin:0;">
            <?= $has_key
              ? 'AI drafts use Claude (' . htmlspecialchars(DOC_AI_MODEL) . ') with the project records and the repository.'
              : 'AI is off: drafts are built from the project records, the repository layout and its README. Put an Anthropic API key in <code>config/anthropic.key</code> to switch AI on.' ?>
          </p>
        </div>
      </section>

      <?php if ($project['deployment_notes']): ?>
      <section class="section">
        <div class="section-header"><div class="section-title">Team Lead notes</div></div>
        <div class="section-body" style="font-size:13px;color:var(--text-dim);"><?= nl2br(htmlspecialchars($project['deployment_notes'])) ?></div>
      </section>
      <?php endif; ?>
    </aside>
  </div>
</div>

<script>
(function () {
  const editor = document.getElementById('editor');
  const source = document.getElementById('source');
  if (!editor) return;
  let dirty = false, sourceMode = false;
  const note = document.getElementById('dirtyNote');
  const markDirty = () => { dirty = true; note.textContent = 'Unsaved changes.'; };
  editor.addEventListener('input', markDirty);
  source.addEventListener('input', markDirty);
  window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  document.querySelectorAll('.toolbar [data-cmd]').forEach(b => b.addEventListener('click', () => {
    editor.focus(); document.execCommand(b.dataset.cmd, false, null); markDirty();
  }));
  document.querySelectorAll('.toolbar [data-block]').forEach(b => b.addEventListener('click', () => {
    editor.focus(); document.execCommand('formatBlock', false, b.dataset.block); markDirty();
  }));
  document.querySelector('.toolbar [data-inline="code"]').addEventListener('click', () => {
    const sel = window.getSelection();
    if (!sel.rangeCount || sel.isCollapsed) return;
    const code = document.createElement('code');
    code.textContent = sel.toString();
    const range = sel.getRangeAt(0); range.deleteContents(); range.insertNode(code);
    markDirty();
  });
  document.getElementById('btnLink').addEventListener('click', () => {
    const url = prompt('Link address (https://…)');
    if (!url) return;
    if (!/^(https?:\/\/|mailto:|#)/i.test(url)) { alert('Links must start with https://, http://, mailto: or #'); return; }
    editor.focus(); document.execCommand('createLink', false, url); markDirty();
  });
  document.getElementById('btnSource').addEventListener('click', function () {
    sourceMode = !sourceMode;
    this.setAttribute('aria-pressed', String(sourceMode));
    if (sourceMode) { source.value = editor.innerHTML; editor.style.display = 'none'; source.style.display = 'block'; source.focus(); }
    else { editor.innerHTML = source.value; source.style.display = 'none'; editor.style.display = 'block'; }
    document.querySelectorAll('.toolbar button:not(#btnSource)').forEach(x => x.disabled = sourceMode);
  });

  window.syncEditor = function () {
    if (!sourceMode) source.value = editor.innerHTML;
    dirty = false;
  };
  window.confirmApprove = function () {
    if (dirty) { alert('Save your changes first. Approval applies to the saved version.'); return false; }
    return confirm('Approve this version as the project documentation?');
  };
})();
</script>
</body>
</html>
