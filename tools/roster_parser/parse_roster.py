#!/usr/bin/env python3
"""
Astra roster parser.

Reads an employee roster (.xlsx or .csv), finds the header row, maps columns to
name / email / phone / role, and prints JSON on stdout:

    {"ok": true, "parser": "python", "header_row": 1,
     "mapping": {"name": "Full Name", ...}, "confidence": {"name": 1.0, ...},
     "rows": [{"row": 2, "name": "...", "email": "...", "phone": "...",
               "role": "pm", "role_raw": "Project Manager", "confidence": 0.93}],
     "warnings": ["..."]}

Usage:
    python parse_roster.py roster.xlsx [--llm]

Needs openpyxl for .xlsx files (pip install openpyxl). --llm asks Claude to map
headers that the rules can't place; it needs the `anthropic` package and an
ANTHROPIC_API_KEY environment variable, and is skipped silently otherwise.
"""
import csv
import difflib
import json
import os
import re
import sys

FIELDS = {
    "name": ["name", "full name", "employee name", "staff name", "member", "person", "employee"],
    "first_name": ["first name", "firstname", "given name", "forename", "fname"],
    "last_name": ["last name", "lastname", "surname", "family name", "lname"],
    "email": ["email", "e-mail", "email address", "mail", "work email", "official email", "email id"],
    "phone": ["phone", "phone number", "mobile", "mobile number", "contact", "contact number", "cell", "tel", "telephone"],
    "role": ["role", "designation", "title", "job title", "position", "astra role", "access", "access level"],
}

ROLE_RULES = [
    ("it_manager", ["it manager", "it admin", "it administrator", "it head", "sysadmin", "system admin", "it lead"]),
    ("pm", ["project manager", "product manager", "program manager", "pm", "delivery manager", "project lead", "scrum master"]),
]

EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[a-z]{2,}$", re.I)
MAX_ROWS = 2000


def norm(text):
    return re.sub(r"[^a-z0-9]+", " ", str(text or "").lower()).strip()


def header_score(cell, synonyms):
    synonyms = [norm(x) for x in synonyms]
    h = norm(cell)
    if not h:
        return 0.0
    if h in synonyms:
        return 1.0
    best = max(difflib.SequenceMatcher(None, h, s).ratio() for s in synonyms)
    if any(s in h for s in synonyms if len(s) > 3):
        best = max(best, 0.85)
    return best


def map_headers(header):
    """Return {field: column_index} and {field: confidence}."""
    scores = []
    for idx, cell in enumerate(header):
        for field, syns in FIELDS.items():
            s = header_score(cell, syns)
            if s >= 0.72:
                scores.append((s, field, idx))
    scores.sort(reverse=True)
    mapping, confidence, used = {}, {}, set()
    for s, field, idx in scores:
        if field in mapping or idx in used:
            continue
        mapping[field] = idx
        confidence[field] = round(s, 2)
        used.add(idx)
    return mapping, confidence


def llm_map_headers(header, mapping):
    """Ask Claude to place fields the rules missed. Best effort only."""
    key = os.environ.get("ANTHROPIC_API_KEY")
    if not key:
        return mapping, []
    try:
        import anthropic  # type: ignore
    except ImportError:
        return mapping, ["--llm ignored: the anthropic package is not installed"]
    missing = [f for f in ("name", "email", "phone", "role") if f not in mapping]
    if not missing:
        return mapping, []
    prompt = (
        "These are the column headers of an employee roster spreadsheet, as a JSON list:\n"
        + json.dumps([str(h) for h in header])
        + "\nReturn only a JSON object mapping each of these fields to the 0-based index of the "
        + "column that holds it, or null if no column does: " + ", ".join(missing)
    )
    try:
        client = anthropic.Anthropic(api_key=key)
        msg = client.messages.create(
            model=os.environ.get("ASTRA_LLM_MODEL", "claude-sonnet-4-5"),
            max_tokens=200,
            messages=[{"role": "user", "content": prompt}],
        )
        text = "".join(getattr(b, "text", "") for b in msg.content)
        found = json.loads(text[text.index("{"): text.rindex("}") + 1])
    except Exception as exc:  # network, parsing, quota…
        return mapping, [f"AI header mapping skipped: {exc}"]
    used = set(mapping.values())
    for field, idx in found.items():
        if field in missing and isinstance(idx, int) and 0 <= idx < len(header) and idx not in used:
            mapping[field] = idx
            used.add(idx)
    return mapping, ["Some columns were matched by AI — check them before confirming."]


def normalize_role(raw):
    r = norm(raw)
    if not r:
        return "teammate", 0.6
    for role, words in ROLE_RULES:
        if r in words:
            return role, 1.0
        if any(re.search(r"\b" + re.escape(w) + r"\b", r) for w in words):
            return role, 0.85
    return "teammate", 0.9


def clean_phone(raw):
    s = str(raw or "").strip()
    if re.fullmatch(r"\d+\.0", s):          # Excel stores numbers as floats
        s = s[:-2]
    keep = re.sub(r"[^\d+]", "", s)
    return keep[:15]


def read_rows(path):
    ext = os.path.splitext(path)[1].lower()
    if ext == ".csv":
        with open(path, newline="", encoding="utf-8-sig", errors="replace") as fh:
            sample = fh.read(4096)
            fh.seek(0)
            try:
                dialect = csv.Sniffer().sniff(sample, delimiters=",;\t|")
            except csv.Error:
                dialect = csv.excel
            return [row for row in csv.reader(fh, dialect)]
    if ext in (".xlsx", ".xlsm"):
        from openpyxl import load_workbook  # type: ignore
        wb = load_workbook(path, read_only=True, data_only=True)
        ws = wb.worksheets[0]
        rows = []
        for row in ws.iter_rows(values_only=True):
            rows.append(["" if v is None else str(v) for v in row])
            if len(rows) > MAX_ROWS + 20:
                break
        return rows
    raise ValueError("Only .xlsx and .csv files are supported.")


def find_header(rows):
    best, best_idx = -1, 0
    for i, row in enumerate(rows[:15]):
        mapping, conf = map_headers(row)
        score = sum(conf.values()) + (2 if "email" in mapping else 0)
        if score > best:
            best, best_idx = score, i
    return best_idx


def parse(path, use_llm=False):
    numbered = [(i + 1, r) for i, r in enumerate(read_rows(path)) if any(str(c).strip() for c in r)]
    rows = [r for _, r in numbered]
    if not rows:
        return {"ok": False, "error": "The file is empty."}

    h = find_header(rows)
    header = rows[h]
    mapping, confidence = map_headers(header)
    warnings = []
    if use_llm:
        mapping, extra = llm_map_headers(header, mapping)
        warnings += extra

    if "email" not in mapping:
        return {"ok": False, "error": "Couldn't find an email column. Add a header such as \"Email\"."}
    if "name" not in mapping and "first_name" not in mapping:
        warnings.append("No name column found — names will need to be filled in.")

    out = []
    for offset, row in numbered[h + 1:]:
        cell = lambda f: str(row[mapping[f]]).strip() if f in mapping and mapping[f] < len(row) else ""
        name = cell("name") or " ".join(p for p in (cell("first_name"), cell("last_name")) if p)
        email = cell("email").lower()
        if not name and not email:
            continue
        role, role_conf = normalize_role(cell("role"))
        row_conf = min([role_conf, 1.0 if EMAIL_RE.match(email) else 0.3, 1.0 if name else 0.4])
        out.append({
            "row": offset,
            "name": re.sub(r"\s+", " ", name)[:100],
            "email": email[:100],
            "phone": clean_phone(cell("phone")),
            "role": role,
            "role_raw": cell("role")[:60],
            "confidence": round(row_conf, 2),
        })
        if len(out) >= MAX_ROWS:
            warnings.append(f"Only the first {MAX_ROWS} rows were read.")
            break

    return {
        "ok": True,
        "parser": "python",
        "header_row": numbered[h][0],
        "mapping": {f: str(header[i]) for f, i in mapping.items()},
        "confidence": confidence,
        "rows": out,
        "warnings": warnings,
    }


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    if len(args) != 1:
        print(json.dumps({"ok": False, "error": "usage: parse_roster.py FILE [--llm]"}))
        return 2
    try:
        result = parse(args[0], use_llm="--llm" in sys.argv)
    except ImportError:
        result = {"ok": False, "error": "openpyxl is not installed (pip install openpyxl)."}
    except Exception as exc:
        result = {"ok": False, "error": f"Couldn't read the file: {exc}"}
    sys.stdout.write(json.dumps(result))
    return 0 if result.get("ok") else 1


if __name__ == "__main__":
    sys.exit(main())
