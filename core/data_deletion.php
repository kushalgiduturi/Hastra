<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// core/data_deletion.php
// Self-service "right to be forgotten" (GDPR Art. 17 / CCPA deletion).
//
// Flow (legal/delete-data.php, address /privacy/delete-data):
//   1. The person enters their email. Whether or not an account exists, the
//      page says the same thing, so the form can't be used to probe for
//      accounts. If one exists, a single-use link valid for one hour is sent.
//   2. Opening the link shows a confirmation screen; nothing is deleted on a
//      GET, because mail scanners open links automatically.
//   3. Confirming erases the account: every personal field on the user row is
//      replaced or cleared, the sign-in index is removed so the address can
//      never sign in again, all sessions end, and personal convenience data
//      (tokens, tour progress, staged roster rows) is deleted.
//
// The row itself stays, anonymised, because projects, tasks, invoices and the
// tamper-evident audit chain refer to it; this is the "remove or encrypt-shred
// the personal details of an entry rather than the entry itself" approach the
// Privacy Policy describes. Business records an organization must keep remain
// with that organization.

const ASTRA_ERASURE_TOKEN_TTL = 3600; // seconds

function astra_erasure_table_ready($conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS data_deletion_requests (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      INT NOT NULL,
            token_hash   CHAR(64) NOT NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at   DATETIME NOT NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_deletion_token (token_hash),
            KEY idx_deletion_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ready = true;
    } catch (Throwable $e) {
        error_log('data_deletion_requests unavailable: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

function astra_erasure_find_user_by_email($conn, string $email): ?array {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    $bindex = astra_blind_index($email);
    $stmt = mysqli_prepare($conn, "SELECT id, name, email, role, company_id FROM users WHERE email_bindex = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $bindex);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    if ($row) astra_decrypt_user_row($row);
    return $row;
}

// Why this account can't be erased by itself (or null if it can). The platform
// administrator and anyone who still owns a workspace other people depend on
// must hand it over first, or erasing them would orphan everyone else.
function astra_erasure_blocker($conn, array $user): ?string {
    if (($user['role'] ?? '') === 'deleted') return 'This account has already been erased.';
    if (($user['role'] ?? '') === 'sysadmin') {
        return 'The platform administrator account cannot be erased from this page. Transfer the role first, then try again.';
    }
    $uid = (int)$user['id'];
    if (company_schema_ready($conn)) {
        $stmt = mysqli_prepare($conn,
            "SELECT c.id, (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.id <> ? AND u.role <> 'deleted') AS others
               FROM companies c WHERE c.user_id = ? OR c.it_manager_id = ?");
        mysqli_stmt_bind_param($stmt, "iii", $uid, $uid, $uid);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($c = mysqli_fetch_assoc($res)) {
            if ((int)$c['others'] > 0) {
                return 'You manage a workspace that other people still use. Make someone else its administrator (or remove the other members), then erase your account.';
            }
        }
    }
    return null;
}

// Creates a single-use token and returns the raw value (only ever emailed).
function astra_erasure_issue_token($conn, int $user_id): ?string {
    if (!astra_erasure_table_ready($conn)) return null;
    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);
    $ttl   = ASTRA_ERASURE_TOKEN_TTL;
    // one live request per account
    $old = mysqli_prepare($conn, "DELETE FROM data_deletion_requests WHERE user_id = ? AND completed_at IS NULL");
    mysqli_stmt_bind_param($old, "i", $user_id);
    mysqli_stmt_execute($old);
    $stmt = mysqli_prepare($conn,
        "INSERT INTO data_deletion_requests (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL $ttl SECOND)");
    mysqli_stmt_bind_param($stmt, "is", $user_id, $hash);
    return mysqli_stmt_execute($stmt) ? $token : null;
}

// The pending request a token belongs to, or null if unknown/expired/used.
function astra_erasure_lookup_token($conn, string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token) || !astra_erasure_table_ready($conn)) return null;
    $hash = hash('sha256', $token);
    $stmt = mysqli_prepare($conn,
        "SELECT id, user_id FROM data_deletion_requests
          WHERE token_hash = ? AND completed_at IS NULL AND expires_at > NOW() LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $hash);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

// Erases one account. Returns ['ok' => bool, 'message' => string, 'email' => original address].
function astra_erase_user($conn, int $user_id, ?int $request_id = null): array {
    $stmt = mysqli_prepare($conn, "SELECT id, name, email, role, company_id FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$user) return ['ok' => false, 'message' => 'This account no longer exists.', 'email' => ''];
    astra_decrypt_user_row($user);
    if ($why = astra_erasure_blocker($conn, $user)) return ['ok' => false, 'message' => $why, 'email' => ''];

    $original_email = (string)$user['email'];
    $name_enc  = astra_db_encrypt('Deleted user');
    $email_enc = astra_db_encrypt("deleted-{$user_id}@deleted.invalid");
    $password  = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT); // unusable

    mysqli_begin_transaction($conn);
    try {
        $sets = "name = ?, email = ?, password = ?, email_bindex = NULL, phone_number = NULL, phone_bindex = NULL,
                 google_id_bindex = NULL, github_url = NULL, linkedin_url = NULL, gender = NULL,
                 otp = NULL, otp_expiry = NULL, reset_token = '', token_expiry = '',
                 role = 'deleted', client_role = NULL, locked_until = '2099-12-31 00:00:00', profile_updated = 1";
        if (db_column_exists($conn, 'users', 'profile_focus')) $sets .= ", profile_focus = NULL";
        $upd = mysqli_prepare($conn, "UPDATE users SET $sets WHERE id = ?");
        mysqli_stmt_bind_param($upd, "sssi", $name_enc, $email_enc, $password, $user_id);
        mysqli_stmt_execute($upd);
        if (mysqli_stmt_affected_rows($upd) !== 1) throw new RuntimeException('user row not updated');

        foreach (['user_sessions', 'password_set_tokens', 'user_page_tours', 'roster_staging'] as $table) {
            if (!db_table_exists_safe($conn, $table)) continue;
            $del = mysqli_prepare($conn, "DELETE FROM `$table` WHERE user_id = ?");
            mysqli_stmt_bind_param($del, "i", $user_id);
            mysqli_stmt_execute($del);
        }

        // tombstone without personal data, so admins can see the account was erased
        $tomb = mysqli_prepare($conn,
            "INSERT INTO deleted_users (id, name, email, role, deleted_at, deleted_by, deleted_by_name)
             VALUES (?, NULL, NULL, ?, NOW(), ?, 'Self-service erasure')
             ON DUPLICATE KEY UPDATE deleted_at = NOW(), deleted_by_name = 'Self-service erasure', name = NULL, email = NULL");
        $role = (string)$user['role'];
        mysqli_stmt_bind_param($tomb, "isi", $user_id, $role, $user_id);
        mysqli_stmt_execute($tomb);

        if ($request_id) {
            $done = mysqli_prepare($conn, "UPDATE data_deletion_requests SET completed_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($done, "i", $request_id);
            mysqli_stmt_execute($done);
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log("astra_erase_user($user_id) failed: " . $e->getMessage());
        return ['ok' => false, 'message' => 'We could not erase the account just now. Nothing was changed. Try again, or email ' . LEGAL_SUPPORT_EMAIL . '.', 'email' => ''];
    }

    try { astra_log_chained('DATA_ERASURE', "Self-service erasure of account #$user_id", $user_id, $conn, 'info'); } catch (Throwable $e) {}
    return ['ok' => true, 'message' => 'Your account and personal data have been erased.', 'email' => $original_email];
}

function db_table_exists_safe($conn, string $table): bool {
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    mysqli_stmt_bind_param($stmt, "s", $table);
    mysqli_stmt_execute($stmt);
    return (bool)mysqli_fetch_row(mysqli_stmt_get_result($stmt));
}
