<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__) && PHP_SAPI !== 'cli') { http_response_code(404); exit(); }
// core/purge_unreadable.php
// One-off cleanup after encryption keys were lost: accounts whose email can no
// longer be decrypted cannot sign in, never will again, and show up as blanks
// in admin lists. This removes them and the records that hang off them.
//
// Safety, in order:
//   1. Refuses unless the primary sysadmin (PRIMARY_SYSADMIN_EMAIL) exists and
//      decrypts. If the keys were wrong NOW, every row would look unreadable;
//      this check stops that from ever wiping a healthy database.
//   2. Refuses if more than half of all users look unreadable.
//   3. Dry run by default: apply=false only counts and prints.
//   4. Everything runs in one transaction; any failure rolls it all back.
//   5. Never touches the audit log (logs / chain), readable users, or the
//      internal company.

function astra_purge_unreadable($conn, bool $apply, callable $out): bool {
    $primary = astra_blind_index(PRIMARY_SYSADMIN_EMAIL);
    $st = mysqli_prepare($conn, "SELECT id, email FROM users WHERE email_bindex = ? LIMIT 1");
    mysqli_stmt_bind_param($st, "s", $primary);
    mysqli_stmt_execute($st);
    $me = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$me || astra_db_decrypt($me['email']) === null) {
        $out("REFUSED: the primary sysadmin account is missing or unreadable, so the keys cannot be trusted. Nothing was changed.");
        return false;
    }

    $unreadable = []; $readable = [];
    $res = mysqli_query($conn, "SELECT id, email, name FROM users");
    while ($u = mysqli_fetch_assoc($res)) {
        $bad = ($u['email'] !== null && $u['email'] !== '' && astra_db_decrypt($u['email']) === null)
            || ($u['name']  !== null && $u['name']  !== '' && astra_db_decrypt($u['name'])  === null);
        if ($bad) $unreadable[] = (int)$u['id']; else $readable[] = (int)$u['id'];
    }
    $total = count($unreadable) + count($readable);
    $out("users: " . count($readable) . " readable (kept), " . count($unreadable) . " unreadable");
    if ($total && count($unreadable) * 2 > $total) {
        $out("REFUSED: more than half of all users look unreadable. That points at a key problem, not at dead accounts. Nothing was changed.");
        return false;
    }
    if (!$unreadable) { $out("nothing to remove."); }

    $ids = $unreadable ? implode(',', $unreadable) : '0';
    $keep = $readable ? implode(',', $readable) : '0';
    $count = fn($sql) => (int)(mysqli_fetch_row(mysqli_query($conn, $sql))[0] ?? 0);

    // projects that belong to dead accounts only (created by one, no readable member)
    $projects = [];
    $r = mysqli_query($conn, "SELECT p.id FROM projects p WHERE p.created_by IN ($ids)
        AND NOT EXISTS (SELECT 1 FROM project_members m WHERE m.project_id = p.id AND m.user_id IN ($keep))");
    while ($x = mysqli_fetch_row($r)) $projects[] = (int)$x[0];
    $pids = $projects ? implode(',', $projects) : '0';

    // companies with nobody readable left (the internal company is never removed)
    $companies = [];
    if (db_table_exists_safe($conn, 'companies')) {
        $r = mysqli_query($conn, "SELECT c.id FROM companies c WHERE c.is_internal = 0
            AND EXISTS (SELECT 1 FROM users u WHERE u.company_id = c.id AND u.id IN ($ids))
            AND NOT EXISTS (SELECT 1 FROM users u WHERE u.company_id = c.id AND u.id IN ($keep))");
        while ($x = mysqli_fetch_row($r)) $companies[] = (int)$x[0];
    }
    $cids = $companies ? implode(',', $companies) : '0';

    $out("projects removed with them: " . count($projects));
    $out("companies left with no readable member: " . count($companies));
    $out("pending sign-ups that can no longer be completed: " . $count("SELECT COUNT(*) FROM pending_registrations"));
    if (!$apply) { $out("DRY RUN: nothing was deleted. Set HASTRA_PURGE_UNREADABLE=1 to apply."); return true; }

    mysqli_begin_transaction($conn);
    try {
        $q = fn($sql) => mysqli_query($conn, $sql);
        // records inside the doomed projects, children before parents
        $q("DELETE FROM deliveries WHERE project_id IN ($pids)");
        $q("DELETE FROM invoices WHERE project_id IN ($pids)");
        $q("UPDATE requirements SET related_project_id = NULL WHERE related_project_id IN ($pids)");
        $q("DELETE FROM projects WHERE id IN ($pids)");
        // what dead accounts submitted, filed or uploaded elsewhere
        $q("DELETE FROM requirements WHERE user_id IN ($ids) AND NOT EXISTS (SELECT 1 FROM projects p WHERE p.requirement_id = requirements.id)");
        foreach (['attendance', 'leave_requests', 'password_set_tokens', 'user_page_tours', 'roster_staging', 'user_sessions', 'security_disclosures'] as $t) {
            if (db_table_exists_safe($conn, $t) && db_column_exists($conn, $t, 'user_id')) $q("DELETE FROM `$t` WHERE user_id IN ($ids)");
        }
        if (db_table_exists_safe($conn, 'security_scans')) $q("DELETE FROM security_scans WHERE company_id IN ($cids) OR user_id IN ($ids)");
        if (db_table_exists_safe($conn, 'roster_imports')) $q("DELETE FROM roster_imports WHERE company_id IN ($cids)");
        $q("DELETE FROM pending_registrations");
        $q("DELETE FROM users WHERE id IN ($ids)");
        if (mysqli_errno($conn)) throw new RuntimeException(mysqli_error($conn));
        $q("DELETE FROM companies WHERE id IN ($cids) AND is_internal = 0");
        if (mysqli_errno($conn)) throw new RuntimeException(mysqli_error($conn));
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $out("FAILED and rolled back, nothing was changed: " . $e->getMessage());
        return false;
    }
    $out("APPLIED: removed " . count($unreadable) . " unreadable account(s), " . count($projects) . " project(s), " . count($companies) . " company(ies).");
    try { astra_log_chained('UNREADABLE_ACCOUNTS_PURGED', "Removed " . count($unreadable) . " unreadable accounts after key loss", (int)$me['id'], $conn, 'info'); } catch (Throwable $e) {}
    return true;
}
