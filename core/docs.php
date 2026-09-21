<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'docs.php') { http_response_code(404); exit(); }
// core/docs.php  (P16)
// Project documentation: drafting (AI via tools/doc_generator, or a built-in
// template), versioning, HTML sanitising and the approval gate for deployment.

const DOC_ALLOWED_TAGS = ['h1','h2','h3','h4','p','ul','ol','li','strong','em','b','i','u','code','pre',
                          'blockquote','a','table','thead','tbody','tr','th','td','br','hr'];
const DOC_DROP_WITH_CONTENT = ['script','style','iframe','object','embed','form','input','button',
                               'select','textarea','svg','math','template','noscript','link','meta','base','head','title'];
const DOC_MAX_BYTES = 400000;

function docs_schema_ready($conn) {
    static $ready = null;
    if ($ready === null) {
        $r = mysqli_query($conn, "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'doc_drafts'");
        $ready = $r && mysqli_num_rows($r) > 0 && db_column_exists($conn, 'projects', 'repo_url');
    }
    return $ready;
}

// GitHub repository links only: https://github.com/owner/repo(.git)
function normalize_repo_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (!preg_match('#^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $url, $m)) return null;
    return "https://github.com/{$m[1]}/{$m[2]}";
}

// ── Sanitiser ─────────────────────────────────────────────────────────────────
// Keeps only DOC_ALLOWED_TAGS, strips every attribute except safe <a href>.
function sanitize_doc_html($html) {
    $html = (string)$html;
    if (strlen($html) > DOC_MAX_BYTES) $html = substr($html, 0, DOC_MAX_BYTES);
    if (trim($html) === '') return '';

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="astra-root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $doc->getElementById('astra-root');
    if (!$root) return '';
    sanitize_doc_node($root);

    $out = '';
    foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
    return trim($out);
}

function sanitize_doc_node(DOMNode $node) {
    for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
        $child = $node->childNodes->item($i);
        if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
            $node->removeChild($child);
            continue;
        }
        if (!($child instanceof DOMElement)) continue;

        $tag = strtolower($child->tagName);
        if (in_array($tag, DOC_DROP_WITH_CONTENT, true)) {
            $node->removeChild($child);
            continue;
        }
        sanitize_doc_node($child);

        if (!in_array($tag, DOC_ALLOWED_TAGS, true)) {
            // Unknown wrapper (div, span, font…): keep its children, drop the tag.
            while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
            $node->removeChild($child);
            continue;
        }

        $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
        for ($a = $child->attributes->length - 1; $a >= 0; $a--) {
            $child->removeAttribute($child->attributes->item($a)->nodeName);
        }
        if ($tag === 'a') {
            if (preg_match('#^(https?://|mailto:|\#)#i', $href)) {
                $child->setAttribute('href', $href);
                if (stripos($href, 'http') === 0) {
                    $child->setAttribute('target', '_blank');
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }
    }
}

// ── Project context for drafting ──────────────────────────────────────────────
function project_doc_context($conn, $project_id) {
    $q = mysqli_prepare($conn,
        "SELECT p.*, r.requirement_id AS req_code, r.project_title, r.requirement_title, r.description AS req_description,
                r.expected_features, r.deadline, c.company_name
         FROM projects p
         JOIN requirements r ON r.id = p.requirement_id
         LEFT JOIN users cu ON cu.id = r.user_id
         LEFT JOIN companies c ON c.id = " . (company_schema_ready($conn) ? "cu.company_id" : "NULL") . "
         WHERE p.id = ?");
    mysqli_stmt_bind_param($q, "i", $project_id);
    mysqli_stmt_execute($q);
    $p = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
    if (!$p) return null;

    $pid  = (int)$project_id;
    $all  = fn($sql) => mysqli_fetch_all(mysqli_query($conn, $sql), MYSQLI_ASSOC);
    $cwe  = bugs_have_cwe($conn) ? "b.cwe_id" : "NULL AS cwe_id";
    return [
        'project' => [
            'code' => $p['project_code'], 'title' => $p['title'], 'description' => (string)$p['description'],
            'status' => $p['status'], 'client' => $p['company_name'] ?? '',
            'deployment_notes' => (string)$p['deployment_notes'], 'repo_url' => (string)($p['repo_url'] ?? ''),
        ],
        'requirement' => [
            'code' => $p['req_code'], 'title' => $p['requirement_title'], 'project_title' => $p['project_title'],
            'description' => (string)astra_db_decrypt($p['req_description']),
            'expected_features' => (string)astra_db_decrypt($p['expected_features']),
            'deadline' => $p['deadline'],
        ],
        'team'  => $all("SELECT u.name, pm.project_role AS role FROM project_members pm JOIN users u ON u.id = pm.user_id
                         WHERE pm.project_id = $pid ORDER BY FIELD(pm.project_role,'team_lead','developer','tester','security_tester','debugger')"),
        'tasks' => $all("SELECT t.task_code AS code, t.title, t.description, t.priority, t.status FROM tasks t
                         WHERE t.project_id = $pid ORDER BY t.id"),
        'bugs'  => $all("SELECT b.bug_code AS code, b.title, b.bug_type AS type, b.vuln_class, $cwe, b.severity, b.status
                         FROM bugs b WHERE b.project_id = $pid ORDER BY b.id"),
    ];
}

// ── Built-in template (no Python / no AI) ────────────────────────────────────
function build_template_doc(array $ctx) {
    $h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $p  = $ctx['project']; $r = $ctx['requirement'];
    $list = function ($text) use ($h) {
        $items = array_filter(array_map('trim', preg_split('/\r?\n/', (string)$text)));
        if (!$items) return '';
        return '<ul>' . implode('', array_map(fn($i) => '<li>' . $h(ltrim($i, "-*• \t")) . '</li>', $items)) . '</ul>';
    };
    $out  = '<h1>' . $h($p['title']) . '</h1>';
    $out .= '<p><strong>Project</strong> ' . $h($p['code']) . ' · <strong>Requirement</strong> ' . $h($r['code'])
          . ($p['client'] ? ' · <strong>Client</strong> ' . $h($p['client']) : '') . '</p>';
    $out .= '<h2>Overview</h2><p>' . nl2br($h($p['description'] ?: $r['description'])) . '</p>';
    if ($r['expected_features']) $out .= '<h2>Features</h2>' . $list($r['expected_features']);

    $done = array_filter($ctx['tasks'], fn($t) => $t['status'] === 'completed');
    if ($ctx['tasks']) {
        $out .= '<h2>What was built</h2><table><thead><tr><th>Task</th><th>Work</th><th>Status</th></tr></thead><tbody>';
        foreach ($ctx['tasks'] as $t) $out .= '<tr><td>' . $h($t['code']) . '</td><td>' . $h($t['title']) . '</td><td>' . $h(str_replace('_', ' ', $t['status'])) . '</td></tr>';
        $out .= '</tbody></table><p>' . count($done) . ' of ' . count($ctx['tasks']) . ' tasks completed.</p>';
    }
    $out .= '<h2>Architecture</h2><p><em>Describe the main components, data flow and hosting here.</em></p>';
    $out .= '<h2>Setup &amp; installation</h2>';
    $out .= $p['repo_url'] ? '<p>Source code: <a href="' . $h($p['repo_url']) . '">' . $h($p['repo_url']) . '</a></p>' : '';
    $out .= '<ol><li>Clone the repository.</li><li>Install the dependencies listed in the project manifest.</li><li>Configure environment variables and credentials (handed over separately).</li><li>Start the application and run the smoke tests below.</li></ol>';
    $out .= '<h2>Usage</h2><p><em>Explain how end users work with the main features.</em></p>';

    $sec = array_filter($ctx['bugs'], fn($b) => $b['type'] === 'security');
    $out .= '<h2>Testing &amp; quality</h2><p>' . count($ctx['bugs']) . ' issue(s) were logged during testing, ' . count($sec) . ' of them security findings.</p>';
    if ($ctx['bugs']) {
        $out .= '<table><thead><tr><th>Bug</th><th>Title</th><th>Type</th><th>Severity</th><th>Outcome</th></tr></thead><tbody>';
        foreach ($ctx['bugs'] as $b) {
            $type = $b['type'] . ($b['vuln_class'] ? ' · ' . $b['vuln_class'] : '') . ($b['cwe_id'] ? ' · ' . $b['cwe_id'] : '');
            $out .= '<tr><td>' . $h($b['code']) . '</td><td>' . $h($b['title']) . '</td><td>' . $h($type) . '</td><td>' . $h($b['severity']) . '</td><td>' . $h(str_replace('_', ' ', $b['status'])) . '</td></tr>';
        }
        $out .= '</tbody></table>';
    }
    $out .= '<h2>Security</h2><ul>' . implode('', array_map(fn($m) => '<li>' . $h($m) . '</li>', SECURITY_MEASURES)) . '</ul>';
    if ($p['deployment_notes']) $out .= '<h2>Deployment notes</h2><p>' . nl2br($h($p['deployment_notes'])) . '</p>';
    if ($ctx['team']) {
        $out .= '<h2>Team</h2><ul>' . implode('', array_map(fn($m) => '<li>' . $h($m['name']) . ' — ' . $h(str_replace('_', ' ', $m['role'])) . '</li>', $ctx['team'])) . '</ul>';
    }
    $out .= '<h2>Maintenance &amp; support</h2><p>Raise change requests from the Astra client portal against this project (' . $h($p['code']) . ').</p>';
    return $out;
}

// ── Drafting ─────────────────────────────────────────────────────────────────
function anthropic_api_key() {
    if (getenv('ANTHROPIC_API_KEY')) return getenv('ANTHROPIC_API_KEY');
    if (defined('ANTHROPIC_KEY_FILE') && is_file(ANTHROPIC_KEY_FILE)) return trim((string)file_get_contents(ANTHROPIC_KEY_FILE));
    return '';
}

// Runs tools/doc_generator/generate_docs.py; returns [html, engine, warnings] or null.
function run_python_doc_generator(array $ctx, array &$warnings) {
    if (!defined('PYTHON_BIN') || PYTHON_BIN === '' || !function_exists('proc_open')) return null;
    $script = realpath(__DIR__ . '/../tools/doc_generator/generate_docs.py');
    if (!$script) return null;

    $env = getenv();
    if (!is_array($env)) $env = [];
    foreach (['SystemRoot', 'SYSTEMROOT', 'PATH', 'Path', 'TEMP', 'TMP'] as $k) {
        if (getenv($k) !== false) $env[$k] = getenv($k);
    }
    $key = anthropic_api_key();
    if ($key !== '') $env['ANTHROPIC_API_KEY'] = $key;
    if (defined('DOC_AI_MODEL')) $env['ASTRA_LLM_MODEL'] = DOC_AI_MODEL;

    $cmd  = escapeshellarg(PYTHON_BIN) . ' ' . escapeshellarg($script);
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) return null;
    fwrite($pipes[0], json_encode($ctx));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);

    $data = json_decode((string)$stdout, true);
    if (!is_array($data) || empty($data['ok'])) {
        if (trim($stderr) !== '') error_log("Doc generator: " . trim($stderr));
        if (is_array($data) && !empty($data['error'])) $warnings[] = $data['error'];
        return null;
    }
    $warnings = array_merge($warnings, $data['warnings'] ?? []);
    return [(string)$data['html'], (string)($data['engine'] ?? 'python'), $warnings];
}

// Creates a new draft version. Returns the version number or null.
function generate_doc_draft($conn, $project_id, $user_id, &$info = []) {
    $ctx = project_doc_context($conn, $project_id);
    if (!$ctx) { $info = ['error' => 'Project not found.']; return null; }

    $warnings = [];
    $res = run_python_doc_generator($ctx, $warnings);
    if ($res) {
        [$html, $engine] = $res;
    } else {
        $html = build_template_doc($ctx);
        $engine = 'template';
        $warnings[] = "Python couldn't be run, so the built-in template was used.";
    }
    $version = save_doc_version($conn, $project_id, $user_id, $html, $engine);
    $info = ['engine' => $engine, 'warnings' => array_values(array_unique($warnings))];
    return $version;
}

// ── Versions ─────────────────────────────────────────────────────────────────
function save_doc_version($conn, $project_id, $user_id, $html, $source) {
    $clean = sanitize_doc_html($html);
    mysqli_begin_transaction($conn);
    $q = mysqli_prepare($conn, "SELECT COALESCE(MAX(version), 0) + 1 FROM doc_drafts WHERE project_id = ? FOR UPDATE");
    mysqli_stmt_bind_param($q, "i", $project_id);
    mysqli_stmt_execute($q);
    mysqli_stmt_bind_result($q, $version);
    mysqli_stmt_fetch($q);
    mysqli_stmt_close($q);
    $ins = mysqli_prepare($conn, "INSERT INTO doc_drafts (project_id, version, body_html, source, created_by) VALUES (?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($ins, "iissi", $project_id, $version, $clean, $source, $user_id);
    mysqli_stmt_execute($ins);
    mysqli_commit($conn);
    return (int)$version;
}

function doc_versions($conn, $project_id) {
    $q = mysqli_prepare($conn,
        "SELECT d.id, d.version, d.source, d.created_at, d.approved_at, u.name AS author, a.name AS approver
         FROM doc_drafts d LEFT JOIN users u ON u.id = d.created_by LEFT JOIN users a ON a.id = d.approved_by
         WHERE d.project_id = ? ORDER BY d.version DESC");
    mysqli_stmt_bind_param($q, "i", $project_id);
    mysqli_stmt_execute($q);
    return mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC);
}

function doc_version($conn, $project_id, $version = null) {
    $sql = "SELECT * FROM doc_drafts WHERE project_id = ?" . ($version ? " AND version = ?" : " ORDER BY version DESC LIMIT 1");
    $q = mysqli_prepare($conn, $sql);
    if ($version) mysqli_stmt_bind_param($q, "ii", $project_id, $version);
    else          mysqli_stmt_bind_param($q, "i", $project_id);
    mysqli_stmt_execute($q);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($q)) ?: null;
}

// Only one approved version per project: approving one clears the others.
function approve_doc_version($conn, $project_id, $version, $user_id) {
    mysqli_begin_transaction($conn);
    $clr = mysqli_prepare($conn, "UPDATE doc_drafts SET approved_at = NULL, approved_by = NULL WHERE project_id = ?");
    mysqli_stmt_bind_param($clr, "i", $project_id);
    mysqli_stmt_execute($clr);
    $set = mysqli_prepare($conn, "UPDATE doc_drafts SET approved_at = NOW(), approved_by = ? WHERE project_id = ? AND version = ?");
    mysqli_stmt_bind_param($set, "iii", $user_id, $project_id, $version);
    mysqli_stmt_execute($set);
    $ok = mysqli_stmt_affected_rows($set) === 1;
    $ok ? mysqli_commit($conn) : mysqli_rollback($conn);
    return $ok;
}

function approved_doc($conn, $project_id) {
    if (!docs_schema_ready($conn)) return null;
    $q = mysqli_prepare($conn, "SELECT * FROM doc_drafts WHERE project_id = ? AND approved_at IS NOT NULL ORDER BY version DESC LIMIT 1");
    mysqli_stmt_bind_param($q, "i", $project_id);
    mysqli_stmt_execute($q);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($q)) ?: null;
}

const DOC_ENGINE_LABELS = ['claude' => 'AI draft (Claude)', 'python' => 'Generated draft', 'template' => 'Template draft', 'admin' => 'Edited by admin'];
