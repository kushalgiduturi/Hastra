<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// Astra — dual-key cryptographic milestone sign-off.
//
// A milestone (e.g. "Final Delivery") needs two independent signatures before
// it counts as complete: the project lead's, then the client's. Neither party
// can produce the other's signature, and the row that ends up "completed" can
// prove — after the fact — who signed, when, and from where, without either
// signer needing a PKI keypair of their own.
//
// The signature itself is HMAC-SHA256(project_id|milestone_name|user_id|
// timestamp|ip, ASTRA_INDEX_KEY) — the same key this app already uses for
// blind indexes (core/crypto.php). Reusing it is deliberate: ASTRA_INDEX_KEY
// never appears in ciphertext (it only ever feeds HMAC), so there is nothing
// to gain by adding a third key file that would need its own storage and
// rotation story for an identical security property. The signature is not a
// blind index (nothing looks values up by it) — it is a keyed, order-
// dependent binding over exactly those five fields, which is what makes it a
// signature rather than just a hash: change any one field (a different user,
// a replayed timestamp, a different IP) and it no longer verifies.
//
// This is HMAC-based proof of "this exact party, at this exact moment,
// approved this exact record" — not a substitute for a legal e-signature.

require_once __DIR__ . '/crypto.php';

// The one milestone name delivery.php and billing.php gate on. A project can
// still record other named milestones (this table supports any milestone_name
// per project), but only this one blocks the delivered/billed transition.
const ASTRA_DELIVERY_MILESTONE = 'Final Delivery';

function astra_signoff_schema_ready($conn) {
    static $ready = null;
    if ($ready === null) {
        $ready = (bool) mysqli_fetch_row(mysqli_query($conn,
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'milestone_signoffs'"));
    }
    return $ready;
}

function astra_signoff_signature(int $project_id, string $milestone_name, int $user_id, string $timestamp, string $ip): string {
    return hash_hmac('sha256', "$project_id|$milestone_name|$user_id|$timestamp|$ip", ASTRA_INDEX_KEY);
}

function astra_signoff_get($conn, int $project_id, string $milestone_name): ?array {
    if (!astra_signoff_schema_ready($conn)) return null;
    $stmt = mysqli_prepare($conn, "SELECT * FROM milestone_signoffs WHERE project_id = ? AND milestone_name = ?");
    mysqli_stmt_bind_param($stmt, "is", $project_id, $milestone_name);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

// True only once BOTH signatures are on record. This is the single function
// delivery.php / billing.php should call to gate a transition — never check
// pm_signed_at alone, since that is exactly the single-party approval the
// requirement forbids.
function astra_signoff_is_complete($conn, int $project_id, string $milestone_name = ASTRA_DELIVERY_MILESTONE): bool {
    $row = astra_signoff_get($conn, $project_id, $milestone_name);
    return $row !== null && $row['status'] === 'completed';
}

// Step 1 — the project lead (an admin, or the project's assigned team_lead)
// initiates. $client_user_id is resolved by the caller (the requirement's
// owning client) and recorded now so step 2 knows exactly who is authorized
// to countersign — not just "anyone with role=client".
// $escrow_amount is what the client owes for this milestone (core/escrow.php).
// Zero means the milestone releases as soon as both parties have signed.
function astra_signoff_initiate($conn, int $project_id, string $milestone_name, int $pm_user_id,
                                 int $client_user_id, string $ip, &$error = null, float $escrow_amount = 0.0): ?array {
    if (!astra_signoff_schema_ready($conn)) { $error = "Milestone sign-off isn't set up yet. Run the database migration."; return null; }
    $milestone_name = trim($milestone_name);
    if ($milestone_name === '') { $error = "Name this milestone before initiating sign-off."; return null; }

    $existing = astra_signoff_get($conn, $project_id, $milestone_name);
    if ($existing) { $error = "This milestone already has a sign-off in progress or completed."; return null; }
    if ($escrow_amount < 0 || $escrow_amount > 9999999999.99) { $error = "Enter a milestone value between 0 and 9,999,999,999.99."; return null; }

    $now = date('Y-m-d H:i:s');
    $sig = astra_signoff_signature($project_id, $milestone_name, $pm_user_id, $now, $ip);

    $ins = mysqli_prepare($conn,
        "INSERT INTO milestone_signoffs (project_id, milestone_name, pm_user_id, pm_signed_at, pm_signature_hash, pm_ip, client_user_id, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending_client')");
    mysqli_stmt_bind_param($ins, "isisssi", $project_id, $milestone_name, $pm_user_id, $now, $sig, $ip, $client_user_id);
    if (!mysqli_stmt_execute($ins)) { $error = "Failed to start the sign-off."; return null; }
    // Read the id before any other query runs: every query resets insert_id.
    $id = (int)mysqli_insert_id($conn);
    if (astra_escrow_ready($conn)) {
        $amt = round($escrow_amount, 2);
        $e = mysqli_prepare($conn, "UPDATE milestone_signoffs SET escrow_amount = ?, escrow_status = 'engineering_review' WHERE id = ?");
        mysqli_stmt_bind_param($e, "di", $amt, $id);
        mysqli_stmt_execute($e);
    }

    return astra_signoff_get($conn, $project_id, $milestone_name);
}

// Step 2 — the client stakeholder countersigns after their own OTP-verified
// review (the OTP challenge itself lives in portals/projects/signoff.php,
// same session-hash-TTL pattern as portals/sysadmin/delete_user.php; this
// function assumes that check already passed and just seals the record).
function astra_signoff_client_sign($conn, int $project_id, string $milestone_name, int $client_user_id,
                                    string $ip, &$error = null): ?array {
    if (!astra_signoff_schema_ready($conn)) { $error = "Milestone sign-off isn't set up yet."; return null; }

    $row = astra_signoff_get($conn, $project_id, $milestone_name);
    if (!$row)                                    { $error = "No pending sign-off for this milestone."; return null; }
    if ($row['status'] !== 'pending_client')      { $error = "This milestone is not awaiting a client signature."; return null; }
    if ((int)$row['client_user_id'] !== $client_user_id) {
        $error = "This milestone is awaiting sign-off from a different client stakeholder.";
        return null;
    }

    $now = date('Y-m-d H:i:s');
    $sig = astra_signoff_signature($project_id, $milestone_name, $client_user_id, $now, $ip);

    // The countersignature and the escrow invoice commit together, so a
    // milestone can never end up signed with no invoice (core/escrow.php).
    mysqli_begin_transaction($conn);
    try {
        $upd = mysqli_prepare($conn,
            "UPDATE milestone_signoffs SET client_signed_at = ?, client_signature_hash = ?, client_ip = ?, status = 'completed'
             WHERE project_id = ? AND milestone_name = ? AND status = 'pending_client'");
        mysqli_stmt_bind_param($upd, "sssis", $now, $sig, $ip, $project_id, $milestone_name);
        mysqli_stmt_execute($upd);
        if (mysqli_stmt_affected_rows($upd) !== 1) { mysqli_rollback($conn); $error = "This milestone was already resolved."; return null; }

        if (astra_escrow_ready($conn)) {
            astra_escrow_open_invoice($conn, astra_signoff_get($conn, $project_id, $milestone_name));
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('[astra-signoff] countersign failed: ' . $e->getMessage());
        $error = "Couldn't record your signature. Nothing was changed; try again.";
        return null;
    }

    return astra_signoff_get($conn, $project_id, $milestone_name);
}

// A client (or PM) can flag a completed or pending sign-off as disputed —
// e.g. deliverables didn't match what was reviewed. Locks it out of both
// astra_signoff_is_complete() (false once disputed) and re-initiation, so a
// human has to resolve it rather than the pipeline silently retrying.
function astra_signoff_dispute($conn, int $project_id, string $milestone_name, &$error = null): bool {
    if (!astra_signoff_schema_ready($conn)) { $error = "Milestone sign-off isn't set up yet."; return false; }
    $upd = mysqli_prepare($conn, astra_escrow_ready($conn)
        ? "UPDATE milestone_signoffs SET status = 'disputed', escrow_status = 'disputed' WHERE project_id = ? AND milestone_name = ?"
        : "UPDATE milestone_signoffs SET status = 'disputed' WHERE project_id = ? AND milestone_name = ?");
    mysqli_stmt_bind_param($upd, "is", $project_id, $milestone_name);
    mysqli_stmt_execute($upd);
    if (mysqli_stmt_affected_rows($upd) !== 1) { $error = "Sign-off record not found."; return false; }
    return true;
}

// Re-derives both signatures from the stored (project_id, milestone_name,
// user_id, timestamp, ip) fields and confirms they hash to what's on record —
// the actual cryptographic check behind the "visual audit trail", not just a
// format sanity check. A mismatch means the row's signed fields were altered
// after the fact, or ASTRA_INDEX_KEY was rotated without re-signing.
function astra_signoff_verify($conn, array $row): array {
    $result = ['pm_valid' => null, 'client_valid' => null];

    if (!empty($row['pm_signature_hash']) && !empty($row['pm_signed_at']) && !empty($row['pm_ip'])) {
        $expected = astra_signoff_signature(
            (int)$row['project_id'], $row['milestone_name'], (int)$row['pm_user_id'], $row['pm_signed_at'], $row['pm_ip']
        );
        $result['pm_valid'] = hash_equals($expected, $row['pm_signature_hash']);
    }

    if (!empty($row['client_signature_hash']) && !empty($row['client_signed_at']) && !empty($row['client_ip'])) {
        $expected = astra_signoff_signature(
            (int)$row['project_id'], $row['milestone_name'], (int)$row['client_user_id'], $row['client_signed_at'], $row['client_ip']
        );
        $result['client_valid'] = hash_equals($expected, $row['client_signature_hash']);
    }

    return $result;
}
