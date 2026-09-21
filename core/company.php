<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'company.php') { http_response_code(404); exit(); }
// core/company.php
// Companies, company email domains and per-company user-ID blocks.
//
// ID layout
//   Internal company (INTERNAL_COMPANY_NAME, @cycops.com)
//     sysadmin / admin ............ 1000 – 1999
//     employee / pending_employee . 2000 – 2999
//   Each client company ........... its own 1000-wide block, starting at 3000
//   Clients with no company ....... 900000 – 999999
//
// Everything here degrades gracefully until the migration
// (config/migrations/2026_09_companies.php) has been run.

const ID_BLOCK_SIZE           = 1000;
const INTERNAL_ADMIN_RANGE    = [1000, 1999];
const INTERNAL_EMPLOYEE_RANGE = [2000, 2999];
const FIRST_CLIENT_BLOCK      = 3000;
const LAST_CLIENT_BLOCK       = 899000;
const INDEPENDENT_RANGE       = [900000, 999999];

// Every column that stores a users.id — kept in sync when a user's ID moves.
const USER_REFERENCE_COLUMNS = [
    ['bug_files',           'uploaded_by'],
    ['bugs',                'reported_by'],
    ['bugs',                'assigned_to'],
    ['companies',           'user_id'],
    ['companies',           'it_manager_id'],
    ['deleted_users',       'deleted_by'],
    ['deliveries',          'delivered_by'],
    ['doc_drafts',          'created_by'],
    ['doc_drafts',          'approved_by'],
    ['deliveries',          'security_viewed_by'],
    ['invoices',            'generated_by'],
    ['logs',                'user_id'],
    ['password_set_tokens', 'user_id'],
    ['project_comments',    'user_id'],
    ['project_members',     'user_id'],
    ['project_members',     'assigned_by'],
    ['projects',            'created_by'],
    ['projects',            'deployment_requested_by'],
    ['requirements',        'user_id'],
    ['requirements',        'reviewed_by'],
    ['roster_imports',      'uploaded_by'],
    ['roster_staging',      'user_id'],
    ['security_disclosures', 'user_id'],
    ['task_files',          'uploaded_by'],
    ['tasks',               'assigned_to'],
    ['tasks',               'created_by'],
];

// ── Schema helpers ────────────────────────────────────────────────────────────
function db_column_exists($conn, $table, $column) {
    $stmt = mysqli_prepare($conn,
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    mysqli_stmt_bind_param($stmt, "ss", $table, $column);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $exists = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    return $exists;
}

// True once the company migration has run.
function company_schema_ready($conn) {
    static $ready = null;
    if ($ready === null) {
        $ready = db_column_exists($conn, 'users', 'company_id')
              && db_column_exists($conn, 'companies', 'id_block_start');
    }
    return $ready;
}

// ── Domains ───────────────────────────────────────────────────────────────────
function normalize_domain($domain) {
    return strtolower(ltrim(trim((string)$domain), '@'));
}

// "Acme Solutions Pvt. Ltd." → "acmesolutions.com"
function company_domain_from_name($company_name) {
    $s     = strtolower((string)$company_name);
    $s     = preg_replace('/[^a-z0-9]+/', ' ', $s);
    $words = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY);
    $legal = ['pvt', 'private', 'ltd', 'limited', 'inc', 'incorporated', 'llc', 'llp',
              'plc', 'corp', 'corporation', 'co', 'company', 'gmbh', 'the'];
    $kept  = array_values(array_filter($words, fn($w) => !in_array($w, $legal, true)));
    if (!$kept) $kept = $words;
    $slug  = substr(implode('', $kept), 0, 50);
    return ($slug === '' ? 'company' : $slug) . '.com';
}

// email_domain is encrypted, so uniqueness is enforced and tested through its
// blind index (UNIQUE on companies.domain_bindex) rather than the column.
function company_domain_taken($conn, $domain, $except_company_id = 0) {
    $stmt = mysqli_prepare($conn, "SELECT id FROM companies WHERE domain_bindex = ? AND id <> ?");
    $bindex = astra_blind_index(normalize_domain($domain));
    mysqli_stmt_bind_param($stmt, "si", $bindex, $except_company_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $taken = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    return $taken;
}

// Derived domain, with a numeric suffix if another company already uses it.
function unique_company_domain($conn, $company_name, $except_company_id = 0) {
    $base   = company_domain_from_name($company_name);
    $stem   = substr($base, 0, -4);           // strip ".com"
    $domain = $base;
    for ($n = 2; company_domain_taken($conn, $domain, $except_company_id); $n++) {
        $domain = $stem . $n . '.com';
    }
    return $domain;
}

// The domain a company's employees use, always with a leading "@".
function company_email_domain($company) {
    if ($company && !empty($company['email_domain'])) {
        return '@' . normalize_domain($company['email_domain']);
    }
    return EMPLOYEE_EMAIL_DOMAIN;
}

function email_matches_domain($email, $domain_with_at) {
    $email  = strtolower(trim($email));
    $domain = '@' . normalize_domain($domain_with_at);
    return strlen($email) > strlen($domain) && substr($email, -strlen($domain)) === $domain;
}

// ── Company lookups ───────────────────────────────────────────────────────────
function get_company($conn, $company_id) {
    if (!company_schema_ready($conn) || !$company_id) return null;
    $stmt = mysqli_prepare($conn, "SELECT * FROM companies WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $company_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    astra_decrypt_company_row($row);
    return $row ?: null;
}

function get_internal_company($conn) {
    if (!company_schema_ready($conn)) return null;
    $res = mysqli_query($conn, "SELECT * FROM companies WHERE is_internal = 1 ORDER BY id LIMIT 1");
    if (!$res || !($row = mysqli_fetch_assoc($res))) return null;
    astra_decrypt_company_row($row);
    return $row;
}

// Internal company first, then client companies in block order.
function list_companies($conn) {
    if (!company_schema_ready($conn)) return [];
    $res = mysqli_query($conn,
        "SELECT * FROM companies
         ORDER BY is_internal DESC, id_block_start IS NULL, id_block_start ASC, company_name ASC");
    return $res ? astra_decrypt_company_rows(mysqli_fetch_all($res, MYSQLI_ASSOC)) : [];
}

// ── ID blocks ─────────────────────────────────────────────────────────────────
function user_id_range_for($company, $role) {
    if ($company && !empty($company['is_internal'])) {
        return in_array($role, ['sysadmin', 'admin'], true) ? INTERNAL_ADMIN_RANGE : INTERNAL_EMPLOYEE_RANGE;
    }
    if ($company && $company['id_block_start'] !== null && $company['id_block_start'] !== '') {
        $start = (int)$company['id_block_start'];
        return [$start, $start + ID_BLOCK_SIZE - 1];
    }
    return INDEPENDENT_RANGE;
}

function company_range_label($company) {
    if (!$company) return INDEPENDENT_RANGE[0] . '–' . INDEPENDENT_RANGE[1];
    if (!empty($company['is_internal'])) return INTERNAL_ADMIN_RANGE[0] . '–' . INTERNAL_EMPLOYEE_RANGE[1];
    if ($company['id_block_start'] === null) return 'unassigned';
    $start = (int)$company['id_block_start'];
    return $start . '–' . ($start + ID_BLOCK_SIZE - 1);
}

function id_in_range($id, $range) {
    return (int)$id >= $range[0] && (int)$id <= $range[1];
}

// A block is free when no user (other than $ignore_ids) already sits inside it.
function id_block_is_free($conn, $start, array $ignore_ids = []) {
    $end  = $start + ID_BLOCK_SIZE - 1;
    $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id BETWEEN ? AND ?");
    mysqli_stmt_bind_param($stmt, "ii", $start, $end);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($r = mysqli_fetch_assoc($res)) {
        if (!in_array((int)$r['id'], $ignore_ids, true)) return false;
    }
    return true;
}

function next_company_block($conn, array $ignore_ids = []) {
    $res   = mysqli_query($conn, "SELECT MAX(id_block_start) AS m FROM companies WHERE is_internal = 0");
    $max   = ($res && ($r = mysqli_fetch_assoc($res))) ? $r['m'] : null;
    $start = $max === null ? FIRST_CLIENT_BLOCK : max(FIRST_CLIENT_BLOCK, (int)$max + ID_BLOCK_SIZE);
    for (; $start <= LAST_CLIENT_BLOCK; $start += ID_BLOCK_SIZE) {
        if (id_block_is_free($conn, $start, $ignore_ids)) return $start;
    }
    return null;
}

// Lowest unused ID inside the range, or null when the range is full.
function first_free_id($conn, array $range, array $reserved = []) {
    $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id BETWEEN ? AND ? ORDER BY id");
    mysqli_stmt_bind_param($stmt, "ii", $range[0], $range[1]);
    mysqli_stmt_execute($stmt);
    $used = array_map('intval', array_column(mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC), 'id'));
    $used = array_flip(array_merge($used, $reserved));
    for ($id = $range[0]; $id <= $range[1]; $id++) {
        if (!isset($used[$id])) return $id;
    }
    return null;
}

// ── Company creation ──────────────────────────────────────────────────────────
// Client companies are matched by their derived domain, so "Acme Inc" and
// "ACME" count as the same company.
function find_company_by_name($conn, $company_name) {
    $company_name = trim((string)$company_name);
    if ($company_name === '' || !company_schema_ready($conn)) return null;
    $base = company_domain_from_name($company_name);
    // company_name stays plaintext, so it is still matched in SQL; the domain
    // half of the OR goes through the blind index.
    $stmt = mysqli_prepare($conn,
        "SELECT * FROM companies WHERE is_internal = 0 AND (domain_bindex = ? OR LOWER(company_name) = LOWER(?)) ORDER BY id LIMIT 1");
    $bindex = astra_blind_index(normalize_domain($base));
    mysqli_stmt_bind_param($stmt, "ss", $bindex, $company_name);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    astra_decrypt_company_row($existing);
    return $existing ?: null;
}

// New client company with its own domain and ID block. $extra: size_band, contract_ref.
function create_company($conn, $company_name, $owner_user_id = null, array $extra = []) {
    $company_name = trim((string)$company_name);
    if ($company_name === '' || !company_schema_ready($conn)) return null;
    $block = next_company_block($conn);
    if ($block === null) return null;
    $domain = unique_company_domain($conn, $company_name);

    $ins = mysqli_prepare($conn,
        "INSERT INTO companies (user_id, company_name, email_domain, domain_bindex, id_block_start, is_internal) VALUES (?, ?, ?, ?, ?, 0)");
    $domain_enc    = astra_db_encrypt(normalize_domain($domain));
    $domain_bindex = astra_blind_index(normalize_domain($domain));
    mysqli_stmt_bind_param($ins, "isssi", $owner_user_id, $company_name, $domain_enc, $domain_bindex, $block);
    if (!mysqli_stmt_execute($ins)) return null;
    $id = mysqli_insert_id($conn);

    if ($extra && db_column_exists($conn, 'companies', 'size_band')) {
        $size = $extra['size_band'] ?? null;
        $ref  = $extra['contract_ref'] ?? null;
        $upd  = mysqli_prepare($conn, "UPDATE companies SET size_band = ?, contract_ref = ? WHERE id = ?");
        mysqli_stmt_bind_param($upd, "ssi", $size, $ref, $id);
        mysqli_stmt_execute($upd);
    }

    if (!empty($extra['logo_url']) && db_column_exists($conn, 'companies', 'logo_url')) {
        $logo = $extra['logo_url'];
        $upd  = mysqli_prepare($conn, "UPDATE companies SET logo_url = ? WHERE id = ?");
        mysqli_stmt_bind_param($upd, "si", $logo, $id);
        mysqli_stmt_execute($upd);
    }

    if (!empty($extra['account_type']) && db_column_exists($conn, 'companies', 'account_type')) {
        $type = $extra['account_type'];
        $upd  = mysqli_prepare($conn, "UPDATE companies SET account_type = ? WHERE id = ?");
        mysqli_stmt_bind_param($upd, "si", $type, $id);
        mysqli_stmt_execute($upd);
    }

    // Enterprise "Full Organization" onboarding: leave policy + an
    // auto-generated biometric attendance webhook secret, so there's
    // nothing left to configure before the admin can actually use the
    // attendance/leave features (matches the button in
    // portals/admin/attendance.php's existing "Generate Secret" flow).
    if (!empty($extra['leave_policy']) && db_column_exists($conn, 'companies', 'leave_cycle')) {
        $lp = $extra['leave_policy'];
        $upd = mysqli_prepare($conn,
            "UPDATE companies SET leave_cycle = ?, monthly_general_leaves = ?, monthly_sick_leaves = ?, annual_leave_allowance = ? WHERE id = ?");
        mysqli_stmt_bind_param($upd, "siiii",
            $lp['leave_cycle'], $lp['monthly_general_leaves'], $lp['monthly_sick_leaves'], $lp['annual_leave_allowance'], $id);
        mysqli_stmt_execute($upd);
    }
    if (!empty($extra['generate_webhook_secret']) && db_column_exists($conn, 'companies', 'attendance_webhook_secret')) {
        $secret = bin2hex(random_bytes(24));
        $upd = mysqli_prepare($conn, "UPDATE companies SET attendance_webhook_secret = ? WHERE id = ?");
        mysqli_stmt_bind_param($upd, "si", $secret, $id);
        mysqli_stmt_execute($upd);
    }

    return get_company($conn, $id);
}

// Solo enterprise workspaces and individual clients have no team, so
// attendance/leave/roster/webhook UI would be dead weight — see
// portals/*/_nav.php.
function astra_is_solo_company($company) {
    return $company && in_array($company['account_type'] ?? '', ['solo_enterprise', 'client_individual'], true);
}

// The signed-in user's own company row (internal Cycops included, unlike
// astra_session_company_logo() which deliberately excludes it), or null if
// they're not tied to one. Used to decide whether to hide team/attendance/
// leave/webhook nav for a solo workspace — see portals/*/_nav.php.
function astra_session_company($conn) {
    if (!isset($_SESSION['user_id'])) return null;
    $stmt = mysqli_prepare($conn, "SELECT c.* FROM users u JOIN companies c ON c.id = u.company_id WHERE u.id = ?");
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

// Turns a pending registration's logo_data (an http(s) URL picked from the
// automated logo search, or a data: URI from the manual upload fallback —
// see auth/register.php) into a companies.logo_url value. An external URL
// is stored as-is; an upload is decoded and written to
// uploads/company_logos/ under a random name. Returns null on anything
// that doesn't look like a valid image (bad data, unsupported type, too
// large) rather than storing junk.
function astra_resolve_company_logo($logo_data) {
    $logo_data = trim((string)$logo_data);
    if ($logo_data === '') return null;

    if (preg_match('#^https?://#i', $logo_data)) {
        return mb_strlen($logo_data) <= 500 ? $logo_data : null;
    }

    if (!preg_match('#^data:image/(png|jpeg|jpg|webp|gif);base64,(.+)$#i', $logo_data, $m)) {
        return null;
    }
    $ext  = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
    $blob = base64_decode($m[2], true);
    if ($blob === false || strlen($blob) > 700 * 1024 || !@getimagesizefromstring($blob)) {
        return null;
    }

    $dir = __DIR__ . '/../uploads/company_logos/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (file_put_contents($dir . $name, $blob) === false) return null;

    return 'uploads/company_logos/' . $name;
}

// The signed-in user's company logo (absolute URL, or a base_url-relative
// uploads/ path), or null if they have none/belong to the internal company.
// Used by every portal's _nav.php to show it in the top bar.
function astra_session_company_logo($conn) {
    if (!isset($_SESSION['user_id']) || !db_column_exists($conn, 'companies', 'logo_url')) return null;
    $stmt = mysqli_prepare($conn, "SELECT c.logo_url FROM users u JOIN companies c ON c.id = u.company_id
                                    WHERE u.id = ? AND c.is_internal = 0 AND c.logo_url IS NOT NULL");
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$row || !$row['logo_url']) return null;
    return preg_match('#^https?://#i', $row['logo_url']) ? $row['logo_url'] : get_base_url() . $row['logo_url'];
}

function find_or_create_company($conn, $company_name, $owner_user_id = null) {
    return find_company_by_name($conn, $company_name) ?? create_company($conn, $company_name, $owner_user_id);
}

// ── User creation inside a company block ─────────────────────────────────────
// Inserts a user with an ID from the right block; retries if another request
// grabs the same ID first. Returns the new ID, or null (see $error).
function insert_user_in_company($conn, $company, array $u, &$error = null) {
    $error      = null;
    $ready      = company_schema_ready($conn);
    $company_id = $company['id'] ?? null;
    $range      = user_id_range_for($company, $u['role']);
    $email_bindex       = astra_blind_index($u['email'] ?? null);
    $phone_bindex       = astra_blind_index($u['phone_number'] ?? null);
    $u['phone_number']  = astra_db_encrypt($u['phone_number'] ?? null);
    $u['name']          = astra_db_encrypt($u['name'] ?? null);
    $u['email']         = astra_db_encrypt($u['email'] ?? null);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $id = null;
        if ($ready) {
            $id = first_free_id($conn, $range);
            if ($id === null) { $error = "The ID block for this company is full."; return null; }
        }

        if ($ready && isset($u['client_role'])) {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO users (id, name, email, email_bindex, phone_number, phone_bindex, password, role, company_id, client_role, login_attempts, locked_until)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)");
            mysqli_stmt_bind_param($stmt, "isssssssis",
                $id, $u['name'], $u['email'], $email_bindex, $u['phone_number'], $phone_bindex, $u['password'], $u['role'], $company_id, $u['client_role']);
        } elseif ($ready) {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO users (id, name, email, email_bindex, phone_number, phone_bindex, password, role, company_id, login_attempts, locked_until)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)");
            mysqli_stmt_bind_param($stmt, "isssssssi",
                $id, $u['name'], $u['email'], $email_bindex, $u['phone_number'], $phone_bindex, $u['password'], $u['role'], $company_id);
        } else {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO users (name, email, email_bindex, phone_number, phone_bindex, password, role, login_attempts, locked_until)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL)");
            mysqli_stmt_bind_param($stmt, "sssssss",
                $u['name'], $u['email'], $email_bindex, $u['phone_number'], $phone_bindex, $u['password'], $u['role']);
        }

        $errno = 0; $errmsg = '';
        try {
            $ok = mysqli_stmt_execute($stmt);
            if (!$ok) { $errno = mysqli_stmt_errno($stmt); $errmsg = mysqli_stmt_error($stmt); }
        } catch (mysqli_sql_exception $e) {
            $ok = false; $errno = (int)$e->getCode(); $errmsg = $e->getMessage();
        }
        if ($ok) return $ready ? $id : mysqli_insert_id($conn);

        // Lost a race for this ID — try the next free one.
        if ($ready && $errno === 1062 && stripos($errmsg, 'PRIMARY') !== false) continue;
        $error = ($errno === 1062) ? "An account with that email already exists." : "Could not create the account.";
        return null;
    }
    $error = "Could not reserve a user ID. Please try again.";
    return null;
}

// ── Moving a user to a new ID ─────────────────────────────────────────────────
// Updates users.id and every column that points at it, in one transaction.
function move_user_id($conn, $old_id, $new_id) {
    $old_id = (int)$old_id; $new_id = (int)$new_id;
    if ($old_id === $new_id) return true;

    static $columns = null;
    if ($columns === null) {
        $columns = array_values(array_filter(USER_REFERENCE_COLUMNS,
            fn($c) => db_column_exists($conn, $c[0], $c[1])));
    }

    mysqli_begin_transaction($conn);
    try {
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
        $u = mysqli_prepare($conn, "UPDATE users SET id = ? WHERE id = ?");
        mysqli_stmt_bind_param($u, "ii", $new_id, $old_id);
        if (!mysqli_stmt_execute($u) || mysqli_stmt_affected_rows($u) !== 1) {
            throw new RuntimeException("user $old_id not moved");
        }
        foreach ($columns as [$table, $column]) {
            $s = mysqli_prepare($conn, "UPDATE `$table` SET `$column` = ? WHERE `$column` = ?");
            mysqli_stmt_bind_param($s, "ii", $new_id, $old_id);
            mysqli_stmt_execute($s);
        }
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
        mysqli_commit($conn);
        return true;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
        error_log("move_user_id($old_id → $new_id) failed: " . $e->getMessage());
        return false;
    }
}

// ── Email suggestion ─────────────────────────────────────────────────────────
// "John Doe" + "@acmesolutions.com" → "johndoe@acmesolutions.com" (or johndoe2@…)
function generate_company_email($conn, $full_name, $domain_with_at) {
    $domain = '@' . normalize_domain($domain_with_at);
    $base   = strtolower(preg_replace('/[^a-zA-Z]/', '', (string)$full_name));
    if ($base === '') $base = 'employee';

    $email = $base . $domain;
    $check = mysqli_prepare($conn, "SELECT id FROM users WHERE email_bindex = ?");
    for ($suffix = 2; ; $suffix++) {
        $bindex = astra_blind_index($email);
        mysqli_stmt_bind_param($check, "s", $bindex);
        mysqli_stmt_execute($check);
        mysqli_stmt_store_result($check);
        if (mysqli_stmt_num_rows($check) === 0) return $email;
        $email = $base . $suffix . $domain;
    }
}
