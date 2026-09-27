#!/usr/bin/env python3
"""
Astra documentation generator.

Reads a project context (JSON on stdin, built by core/docs.php), optionally
downloads the project's public GitHub repository, and prints JSON:

    {"ok": true, "engine": "claude" | "python", "html": "<h1>…", "warnings": [...]}

With ANTHROPIC_API_KEY set, Claude writes the draft from the project records and
the source code. Without it, a structured draft is built from the records, the
repository's file tree and its README. Only the Python standard library is used.
"""
import io
import json
import os
import re
import sys
import urllib.error
import urllib.request
import zipfile
from html import escape

MAX_ZIP_BYTES = 25 * 1024 * 1024
MAX_TREE = 300
MAX_SOURCE_CHARS = 60_000
MAX_FILE_CHARS = 8_000
API_URL = "https://api.anthropic.com/v1/messages"
MODEL = os.environ.get("ASTRA_LLM_MODEL", "claude-sonnet-4-5")

SKIP_DIRS = {".git", "node_modules", "vendor", "dist", "build", "__pycache__", ".venv", "venv",
             ".next", "target", ".idea", ".vscode", "coverage", "uploads"}
MANIFESTS = ["README.md", "README", "readme.md", "package.json", "composer.json", "requirements.txt",
             "pyproject.toml", "pom.xml", "build.gradle", "go.mod", "Cargo.toml", "Dockerfile",
             "docker-compose.yml", ".env.example"]
SOURCE_EXT = {".py", ".js", ".ts", ".tsx", ".jsx", ".php", ".java", ".go", ".rb", ".cs", ".kt",
              ".sql", ".html", ".vue", ".svelte", ".yml", ".yaml", ".toml", ".ini"}
SECRET_HINTS = re.compile(r"(\.env$|secret|credential|\.key$|\.pem$|id_rsa|password)", re.I)

ALLOWED_TAGS = "h1, h2, h3, h4, p, ul, ol, li, strong, em, code, pre, blockquote, a, table, thead, tbody, tr, th, td, br, hr"


# ── Repository ────────────────────────────────────────────────────────────────
def fetch_github_repo(url, warnings):
    m = re.match(r"^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$", url or "")
    if not m:
        return None
    owner, repo = m.group(1), m.group(2)
    zip_url = f"https://codeload.github.com/{owner}/{repo}/zip/HEAD"
    try:
        req = urllib.request.Request(zip_url, headers={"User-Agent": "Astra-doc-generator"})
        with urllib.request.urlopen(req, timeout=30) as resp:
            data = resp.read(MAX_ZIP_BYTES + 1)
    except urllib.error.HTTPError as exc:
        warnings.append(f"Couldn't download {url} (HTTP {exc.code}). Is the repository public?")
        return None
    except Exception as exc:
        warnings.append(f"Couldn't download {url}: {exc}")
        return None
    if len(data) > MAX_ZIP_BYTES:
        warnings.append("The repository is larger than 25 MB, so only the project records were used.")
        return None

    files = {}
    with zipfile.ZipFile(io.BytesIO(data)) as zf:
        for info in zf.infolist():
            if info.is_dir():
                continue
            parts = info.filename.split("/", 1)
            if len(parts) < 2:
                continue
            rel = parts[1]
            if any(p in SKIP_DIRS for p in rel.split("/")):
                continue
            files[rel] = info
        tree = sorted(files)[:MAX_TREE]

        picked, total = {}, 0
        def take(rel):
            nonlocal total
            if rel in picked or SECRET_HINTS.search(rel) or total >= MAX_SOURCE_CHARS:
                return
            info = files[rel]
            if info.file_size > 200_000:
                return
            try:
                text = zf.read(info).decode("utf-8")
            except (UnicodeDecodeError, KeyError):
                return
            text = text[:MAX_FILE_CHARS]
            picked[rel] = text
            total += len(text)

        for name in MANIFESTS:
            for rel in files:
                if rel.split("/")[-1] == name and rel.count("/") <= 1:
                    take(rel)
        # Entry points and shallow source files first.
        for rel in sorted(files, key=lambda r: (r.count("/"), len(r))):
            if os.path.splitext(rel)[1].lower() in SOURCE_EXT:
                take(rel)

    return {"url": url, "tree": tree, "file_count": len(files), "files": picked}


# ── Claude ────────────────────────────────────────────────────────────────────
def claude_draft(ctx, repo, warnings):
    key = os.environ.get("ANTHROPIC_API_KEY", "").strip()
    if not key:
        return None
    source = ""
    if repo:
        source = "Repository file tree:\n" + "\n".join(repo["tree"]) + "\n\n"
        for rel, text in repo["files"].items():
            source += f"----- {rel} -----\n{text}\n\n"

    prompt = (
        "You are writing the final handover documentation for a software project delivered by Astra, "
        "a software development company, to its client. Write for the client's developers and project manager.\n\n"
        "Use ONLY these HTML tags, with no attributes except href on <a>: " + ALLOWED_TAGS + ". "
        "No <html>, <head>, <body>, <style>, <script>, classes or inline styles. Return only the HTML.\n\n"
        "Sections, in order: an <h1> title; Overview; Features; Architecture (components, data flow, tech stack); "
        "Setup & installation (prerequisites, steps, configuration and environment variables — never include secret values); "
        "Usage; API or module reference if the code has one; Testing & quality (summarise the bug list); "
        "Security; Known limitations; Maintenance & support. "
        "Base every technical statement on the code and records below. If something isn't shown, say what the reader should confirm "
        "instead of inventing it.\n\n"
        "Project records (JSON):\n" + json.dumps(ctx, indent=1)[:40_000] + "\n\n" + source
    )
    body = json.dumps({
        "model": MODEL,
        "max_tokens": 8000,
        "messages": [{"role": "user", "content": prompt}],
    }).encode()
    req = urllib.request.Request(API_URL, data=body, method="POST", headers={
        "x-api-key": key,
        "anthropic-version": "2023-06-01",
        "content-type": "application/json",
    })
    try:
        with urllib.request.urlopen(req, timeout=180) as resp:
            data = json.loads(resp.read())
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", "replace")[:300]
        warnings.append(f"The AI request failed (HTTP {exc.code}): {detail}")
        return None
    except Exception as exc:
        warnings.append(f"The AI request failed: {exc}")
        return None

    html = "".join(block.get("text", "") for block in data.get("content", []) if block.get("type") == "text").strip()
    html = re.sub(r"^```(?:html)?\s*|\s*```$", "", html)
    if data.get("stop_reason") == "max_tokens":
        warnings.append("The AI draft hit its length limit — check the end of the document.")
    return html or None


# ── Structured draft without AI ───────────────────────────────────────────────
def readme_to_html(md):
    out, in_list, in_code = [], False, False
    for line in md.splitlines():
        if line.strip().startswith("```"):
            out.append("</code></pre>" if in_code else "<pre><code>")
            in_code = not in_code
            continue
        if in_code:
            out.append(escape(line))
            continue
        m = re.match(r"^(#{1,4})\s+(.*)", line)
        if m:
            if in_list:
                out.append("</ul>"); in_list = False
            level = min(len(m.group(1)) + 2, 4)
            out.append(f"<h{level}>{escape(m.group(2))}</h{level}>")
        elif re.match(r"^\s*[-*]\s+", line):
            if not in_list:
                out.append("<ul>"); in_list = True
            item = re.sub(r"^\s*[-*]\s+", "", line)
            out.append(f"<li>{escape(item)}</li>")
        elif line.strip():
            if in_list:
                out.append("</ul>"); in_list = False
            out.append(f"<p>{escape(line.strip())}</p>")
    if in_list:
        out.append("</ul>")
    if in_code:
        out.append("</code></pre>")
    return "\n".join(out)


def detect_stack(repo):
    names = {rel.split("/")[-1] for rel in repo["tree"]}
    exts = {os.path.splitext(rel)[1].lower() for rel in repo["tree"]}
    stack = []
    checks = [("package.json", "Node.js / JavaScript"), ("composer.json", "PHP (Composer)"),
              ("requirements.txt", "Python"), ("pyproject.toml", "Python"), ("pom.xml", "Java (Maven)"),
              ("build.gradle", "Java/Kotlin (Gradle)"), ("go.mod", "Go"), ("Cargo.toml", "Rust"),
              ("Dockerfile", "Docker")]
    for name, label in checks:
        if name in names and label not in stack:
            stack.append(label)
    if ".php" in exts and not any("PHP" in s for s in stack):
        stack.append("PHP")
    if ".py" in exts and "Python" not in stack:
        stack.append("Python")
    if ".ipynb" in exts:
        stack.append("Jupyter notebooks")
    return stack


def structured_draft(ctx, repo):
    p, r = ctx["project"], ctx["requirement"]
    e = lambda s: escape(str(s or ""))
    out = [f"<h1>{e(p['title'])}</h1>",
           f"<p><strong>Project</strong> {e(p['code'])} · <strong>Requirement</strong> {e(r['code'])}"
           + (f" · <strong>Client</strong> {e(p['client'])}" if p.get("client") else "") + "</p>",
           "<h2>Overview</h2>", f"<p>{e(p['description'] or r['description'])}</p>"]
    feats = [f.strip(" -*•\t") for f in (r.get("expected_features") or "").splitlines() if f.strip()]
    if feats:
        out.append("<h2>Features</h2><ul>" + "".join(f"<li>{e(f)}</li>" for f in feats) + "</ul>")

    out.append("<h2>Architecture</h2>")
    if repo:
        stack = detect_stack(repo)
        if stack:
            out.append("<p><strong>Tech stack:</strong> " + e(", ".join(stack)) + "</p>")
        top = sorted({rel.split("/")[0] + ("/" if "/" in rel else "") for rel in repo["tree"]})
        out.append(f"<p>The repository contains {repo['file_count']} files. Top-level layout:</p>")
        out.append("<ul>" + "".join(f"<li><code>{e(t)}</code></li>" for t in top[:40]) + "</ul>")
    else:
        why = ("The repository couldn't be read" if p.get("repo_url") else "No repository was linked")
        out.append(f"<p><em>{why}. Describe the components, data flow and hosting here.</em></p>")

    out.append("<h2>Setup &amp; installation</h2>")
    if repo:
        out.append(f'<p>Source code: <a href="{e(repo["url"])}">{e(repo["url"])}</a></p>')
        readme = next((t for rel, t in repo["files"].items() if rel.lower().startswith("readme")), "")
        if readme:
            out.append("<h3>From the project README</h3>" + readme_to_html(readme))
    out.append("<ol><li>Clone the repository.</li><li>Install the dependencies from the manifest files.</li>"
               "<li>Set the configuration and credentials handed over separately.</li><li>Start the application and check the main flows.</li></ol>")

    if ctx["tasks"]:
        done = sum(1 for t in ctx["tasks"] if t["status"] == "completed")
        out.append("<h2>What was built</h2><table><thead><tr><th>Task</th><th>Work</th><th>Status</th></tr></thead><tbody>")
        out += [f"<tr><td>{e(t['code'])}</td><td>{e(t['title'])}</td><td>{e(t['status']).replace('_', ' ')}</td></tr>" for t in ctx["tasks"]]
        out.append(f"</tbody></table><p>{done} of {len(ctx['tasks'])} tasks completed.</p>")

    sec = [b for b in ctx["bugs"] if b["type"] == "security"]
    out.append(f"<h2>Testing &amp; quality</h2><p>{len(ctx['bugs'])} issue(s) were logged during testing, {len(sec)} of them security findings.</p>")
    if ctx["bugs"]:
        out.append("<table><thead><tr><th>Bug</th><th>Title</th><th>Type</th><th>Severity</th><th>Outcome</th></tr></thead><tbody>")
        for b in ctx["bugs"]:
            kind = " · ".join(x for x in [b["type"], b.get("vuln_class"), b.get("cwe_id")] if x)
            out.append(f"<tr><td>{e(b['code'])}</td><td>{e(b['title'])}</td><td>{e(kind)}</td>"
                       f"<td>{e(b['severity'])}</td><td>{e(b['status']).replace('_', ' ')}</td></tr>")
        out.append("</tbody></table>")
    if p.get("deployment_notes"):
        out.append(f"<h2>Deployment notes</h2><p>{e(p['deployment_notes'])}</p>")
    out.append(f"<h2>Maintenance &amp; support</h2><p>Raise change requests from the Astra client portal against project {e(p['code'])}.</p>")
    return "\n".join(out)


def main():
    try:
        ctx = json.load(sys.stdin)
    except Exception as exc:
        print(json.dumps({"ok": False, "error": f"Bad input: {exc}"}))
        return 1
    warnings = []
    repo = fetch_github_repo(ctx.get("project", {}).get("repo_url", ""), warnings)
    html = claude_draft(ctx, repo, warnings)
    engine = "claude" if html else "python"
    if not html:
        if not os.environ.get("ANTHROPIC_API_KEY"):
            warnings.append("No Anthropic API key is set up, so this is a structured draft without AI.")
        html = structured_draft(ctx, repo)
    sys.stdout.write(json.dumps({"ok": True, "engine": engine, "html": html, "warnings": warnings}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
