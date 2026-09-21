<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'ephemeral_dossier.php') { http_response_code(404); exit(); }
// Astra — ephemeral, self-destructing deliverable dossiers.
//
// A dossier is a one-shot (or few-shot) encrypted payload — handover
// credentials, a security report — reachable only via a random token that is
// never stored: the row keeps token_bindex (HMAC-SHA256 under ASTRA_INDEX_KEY,
// same scheme as every other blind index here) and looks the token up by
// that, exactly like core/access.php's security-disclosure links.
//
// What makes it "self-destructing" rather than just access-controlled: once
// view_count reaches max_views, or the clock passes expires_at, the payload
// column is overwritten with random bytes and the row is marked shredded.
// After that moment the ciphertext that used to be there is gone — there is
// no key that decrypts noise back into the original secret, so even a full
// database compromise after shredding recovers nothing.

require_once __DIR__ . '/crypto.php';

function astra_dossier_schema_ready($conn) {
    static $ready = null;
    if ($ready === null) {
        $ready = (bool) mysqli_fetch_row(mysqli_query($conn,
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ephemeral_dossiers'"));
    }
    return $ready;
}

// Creates a dossier and returns the raw token — the only time it ever exists
// outside the requester's clipboard. $max_views defaults to single-view;
// $expires_in_minutes defaults to 24 hours, matching the requirement's example.
function astra_dossier_create($conn, int $project_id, int $created_by, string $payload,
                               int $max_views = 1, int $expires_in_minutes = 1440, &$error = null): ?string {
    if (!astra_dossier_schema_ready($conn)) { $error = "Ephemeral dossiers aren't set up yet — run the database migration."; return null; }
    if (trim($payload) === '')  { $error = "Nothing to store — the payload is empty."; return null; }
    if ($max_views < 1)          { $error = "A dossier must allow at least one view."; return null; }
    if ($expires_in_minutes < 1) { $error = "The expiry window must be at least a minute."; return null; }

    $token   = bin2hex(random_bytes(32));
    $bindex  = astra_blind_index($token);
    $enc     = astra_db_encrypt($payload);

    $ins = mysqli_prepare($conn,
        "INSERT INTO ephemeral_dossiers (project_id, created_by, token_bindex, encrypted_payload, max_views, expires_at)
         VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ? MINUTE)");
    mysqli_stmt_bind_param($ins, "iissii", $project_id, $created_by, $bindex, $enc, $max_views, $expires_in_minutes);
    if (!mysqli_stmt_execute($ins)) { $error = "Failed to create the dossier."; return null; }

    return $token;
}

// Overwrites the payload with cryptographically random noise and marks the
// row shredded. Idempotent — shredding an already-shredded row is a no-op.
function astra_dossier_shred($conn, int $id): void {
    $noise = bin2hex(random_bytes(64));
    $stmt = mysqli_prepare($conn,
        "UPDATE ephemeral_dossiers SET encrypted_payload = ?, is_shredded = 1, shredded_at = NOW()
         WHERE id = ? AND is_shredded = 0");
    mysqli_stmt_bind_param($stmt, "si", $noise, $id);
    mysqli_stmt_execute($stmt);
}

// Looks up a dossier by its raw token without consuming it — used to render
// the "confirm before you view" step so a view isn't spent on a page reload.
function astra_dossier_peek($conn, string $token, &$error = null): ?array {
    if (!astra_dossier_schema_ready($conn)) { $error = "Ephemeral dossiers aren't set up yet."; return null; }
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) { $error = "This link is not valid."; return null; }

    $bindex = astra_blind_index($token);
    $stmt = mysqli_prepare($conn,
        "SELECT d.id, d.project_id, d.max_views, d.view_count, d.expires_at, d.is_shredded,
                p.project_code, p.title AS project_title
         FROM ephemeral_dossiers d JOIN projects p ON p.id = d.project_id
         WHERE d.token_bindex = ?");
    mysqli_stmt_bind_param($stmt, "s", $bindex);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$row) { $error = "This link doesn't exist, or was never valid."; return null; }
    return $row;
}

// The one-shot read. Locks the row, checks it is still live, decrypts,
// increments view_count, and shreds it in the same transaction if that view
// exhausted it or the expiry has passed — so a race between two simultaneous
// requests for the last view can never both see the payload.
function astra_dossier_consume($conn, string $token, &$error = null): ?array {
    if (!astra_dossier_schema_ready($conn)) { $error = "Ephemeral dossiers aren't set up yet."; return null; }
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) { $error = "This link is not valid."; return null; }
    $bindex = astra_blind_index($token);

    mysqli_begin_transaction($conn);
    $q = mysqli_prepare($conn,
        "SELECT d.*, p.project_code, p.title AS project_title
         FROM ephemeral_dossiers d JOIN projects p ON p.id = d.project_id
         WHERE d.token_bindex = ? FOR UPDATE");
    mysqli_stmt_bind_param($q, "s", $bindex);
    mysqli_stmt_execute($q);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($q));

    if (!$row) {
        mysqli_rollback($conn);
        $error = "This link doesn't exist, or was never valid.";
        return null;
    }

    $expired = strtotime($row['expires_at']) <= time();

    if ($row['is_shredded'] || $expired) {
        // Already gone, or its window closed since the last check — shred it
        // now if that hasn't happened yet, and report the same terminal state
        // either way rather than distinguishing "shredded" from "just expired".
        if (!$row['is_shredded']) astra_dossier_shred($conn, (int)$row['id']);
        mysqli_commit($conn);
        $error = "This dossier has expired and its cryptographic data has been permanently shredded from storage.";
        return null;
    }

    $payload = astra_db_decrypt($row['encrypted_payload']);

    $upd = mysqli_prepare($conn, "UPDATE ephemeral_dossiers SET view_count = view_count + 1 WHERE id = ?");
    mysqli_stmt_bind_param($upd, "i", $row['id']);
    mysqli_stmt_execute($upd);
    $new_view_count = (int)$row['view_count'] + 1;

    $exhausted = $new_view_count >= (int)$row['max_views'];
    if ($exhausted) astra_dossier_shred($conn, (int)$row['id']);

    mysqli_commit($conn);

    return [
        'payload'       => $payload,
        'project_code'  => $row['project_code'],
        'project_title' => $row['project_title'],
        'view_count'    => $new_view_count,
        'max_views'     => (int)$row['max_views'],
        'expires_at'    => $row['expires_at'],
        'shredded_now'  => $exhausted,
    ];
}

// Sweeps every dossier whose expiry has passed but hasn't been shredded yet —
// a dossier nobody ever came back to open still needs to self-destruct.
// Cheap enough to call opportunistically from the retrieval page.
function astra_dossier_sweep_expired($conn): int {
    if (!astra_dossier_schema_ready($conn)) return 0;
    $rows = mysqli_query($conn, "SELECT id FROM ephemeral_dossiers WHERE is_shredded = 0 AND expires_at <= NOW()");
    $n = 0;
    while ($r = mysqli_fetch_assoc($rows)) { astra_dossier_shred($conn, (int)$r['id']); $n++; }
    return $n;
}
