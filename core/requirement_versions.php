<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// Hastra — requirement version snapshots and the scope-drift diff engine.
//
// Every time a client edits a requirement that's already been through review,
// the state it had before the edit is preserved as a requirement_versions row
// (encrypted, same as the live requirements table). requirements_diff.php
// renders the line-level delta between any two versions and a scope-drift
// estimate, and a new revision cannot feed project creation
// (portals/admin/project_portal.php's create_project) until a PM
// acknowledges it — see requirements.has_pending_revision.

require_once __DIR__ . '/crypto.php';

function astra_reqver_schema_ready($conn) {
    static $ready = null;
    if ($ready === null) {
        $ready = (bool) mysqli_fetch_row(mysqli_query($conn,
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requirement_versions'"))
            && db_column_exists($conn, 'requirements', 'current_version');
    }
    return $ready;
}

function astra_reqver_latest($conn, int $requirement_id): ?array {
    if (!astra_reqver_schema_ready($conn)) return null;
    $stmt = mysqli_prepare($conn,
        "SELECT * FROM requirement_versions WHERE requirement_id = ? ORDER BY version_number DESC LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $requirement_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($row) { $row['encrypted_title'] = astra_db_decrypt($row['encrypted_title']); $row['encrypted_description'] = astra_db_decrypt($row['encrypted_description']); }
    return $row ?: null;
}

function astra_reqver_list($conn, int $requirement_id): array {
    if (!astra_reqver_schema_ready($conn)) return [];
    $stmt = mysqli_prepare($conn,
        "SELECT * FROM requirement_versions WHERE requirement_id = ? ORDER BY version_number ASC");
    mysqli_stmt_bind_param($stmt, "i", $requirement_id);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    foreach ($rows as &$r) { $r['encrypted_title'] = astra_db_decrypt($r['encrypted_title']); $r['encrypted_description'] = astra_db_decrypt($r['encrypted_description']); }
    unset($r);
    return $rows;
}

// Percentage change in description length between two snapshots — the
// cheapest honest proxy for "how much did the ask change" without trying to
// semantically parse free-text requirements. Positive = grew, negative =
// shrank. A brand-new (empty) baseline reports 100% so it doesn't divide by zero.
function astra_reqver_scope_drift_percent(string $old_desc, string $new_desc): float {
    $old_len = mb_strlen(trim($old_desc));
    $new_len = mb_strlen(trim($new_desc));
    if ($old_len === 0) return $new_len === 0 ? 0.0 : 100.0;
    return round((($new_len - $old_len) / $old_len) * 100, 1);
}

// Snapshots the requirement's CURRENT state (before the caller applies an
// edit) as the next version, then bumps requirements.has_pending_revision so
// project creation is gated until a PM acknowledges it. Call this BEFORE
// writing the new title/description to the requirements row itself.
function astra_reqver_snapshot($conn, int $requirement_id, int $created_by, &$error = null): ?int {
    if (!astra_reqver_schema_ready($conn)) { $error = "Requirement versioning isn't set up yet. Run the database migration."; return null; }

    $cur = mysqli_prepare($conn, "SELECT requirement_title, description FROM requirements WHERE id = ?");
    mysqli_stmt_bind_param($cur, "i", $requirement_id);
    mysqli_stmt_execute($cur);
    $req = mysqli_fetch_assoc(mysqli_stmt_get_result($cur));
    if (!$req) { $error = "Requirement not found."; return null; }

    $prev = astra_reqver_latest($conn, $requirement_id);
    $next_version = $prev ? (int)$prev['version_number'] + 1 : 1;

    $title_plain = $req['requirement_title'];
    $desc_plain  = astra_db_decrypt($req['description']);
    $scope_points = $prev ? (int) round(astra_reqver_scope_drift_percent($prev['encrypted_description'], $desc_plain)) : 0;

    $ins = mysqli_prepare($conn,
        "INSERT INTO requirement_versions (requirement_id, version_number, encrypted_title, encrypted_description, scope_points, created_by)
         VALUES (?, ?, ?, ?, ?, ?)");
    $title_enc = astra_db_encrypt($title_plain);
    $desc_enc  = astra_db_encrypt($desc_plain);
    mysqli_stmt_bind_param($ins, "iissii", $requirement_id, $next_version, $title_enc, $desc_enc, $scope_points, $created_by);
    if (!mysqli_stmt_execute($ins)) { $error = "Failed to snapshot the requirement."; return null; }

    // Every call here represents a live client edit to a requirement that
    // already existed (the migration's one-time history backfill writes
    // baseline rows directly, without going through this function) — so
    // every snapshot, including the first, marks a real revision that needs
    // a PM's eyes before it feeds project creation. A requirement that was
    // already reviewed/approved had, in effect, an implicit sign-off on its
    // pre-edit content; changing that content invalidates it regardless of
    // whether this happens to be requirement_versions row #1 or #5.
    mysqli_query($conn, "UPDATE requirements SET has_pending_revision = 1 WHERE id = " . (int)$requirement_id);

    return $next_version;
}

// The PM's acknowledgment gate. Marks the latest version acknowledged and
// promotes it to requirements.current_version — from this point on,
// project_portal.php's create_project will allow this requirement through.
function astra_reqver_acknowledge($conn, int $requirement_id, int $pm_user_id, &$error = null): bool {
    if (!astra_reqver_schema_ready($conn)) { $error = "Requirement versioning isn't set up yet."; return false; }

    $latest = astra_reqver_latest($conn, $requirement_id);
    if (!$latest) { $error = "No versions to acknowledge."; return false; }

    $upd = mysqli_prepare($conn,
        "UPDATE requirement_versions SET pm_acknowledged_at = NOW(), pm_acknowledged_by = ?
         WHERE requirement_id = ? AND version_number = ?");
    mysqli_stmt_bind_param($upd, "iii", $pm_user_id, $requirement_id, $latest['version_number']);
    mysqli_stmt_execute($upd);

    $mark = mysqli_prepare($conn,
        "UPDATE requirements SET current_version = ?, has_pending_revision = 0 WHERE id = ?");
    mysqli_stmt_bind_param($mark, "ii", $latest['version_number'], $requirement_id);
    mysqli_stmt_execute($mark);

    return true;
}

// Line-level diff between two texts, LCS-based (fine at requirement-
// description scale — dozens of lines, not thousands). Returns an ordered
// list of ['type' => 'same'|'added'|'removed', 'text' => line].
function astra_reqver_diff_lines(string $old, string $new): array {
    $old_lines = preg_split('/\r\n|\r|\n/', trim($old));
    $new_lines = preg_split('/\r\n|\r|\n/', trim($new));
    $old_lines = $old_lines === [''] ? [] : $old_lines;
    $new_lines = $new_lines === [''] ? [] : $new_lines;

    $m = count($old_lines); $n = count($new_lines);
    // dp[i][j] = length of the LCS of old_lines[i:] and new_lines[j:]
    $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
    for ($i = $m - 1; $i >= 0; $i--) {
        for ($j = $n - 1; $j >= 0; $j--) {
            $dp[$i][$j] = $old_lines[$i] === $new_lines[$j]
                ? $dp[$i + 1][$j + 1] + 1
                : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
        }
    }

    $out = []; $i = 0; $j = 0;
    while ($i < $m && $j < $n) {
        if ($old_lines[$i] === $new_lines[$j]) {
            $out[] = ['type' => 'same', 'text' => $old_lines[$i]];
            $i++; $j++;
        } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
            $out[] = ['type' => 'removed', 'text' => $old_lines[$i]];
            $i++;
        } else {
            $out[] = ['type' => 'added', 'text' => $new_lines[$j]];
            $j++;
        }
    }
    while ($i < $m) { $out[] = ['type' => 'removed', 'text' => $old_lines[$i]]; $i++; }
    while ($j < $n) { $out[] = ['type' => 'added',   'text' => $new_lines[$j]]; $j++; }

    return $out;
}
