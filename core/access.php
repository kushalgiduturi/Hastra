<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// core/access.php
// Client-side access rules (P14): who in a client company can see and do what,
// when documentation unlocks, and the one-time security summary.
//
//   Project Manager  submits requirements, pays invoices, comments, sees deliveries
//                    and opens the one-time security summary.
//   IT Manager       read-only view of projects, can pay invoices and comment,
//                    manages the team.
//   Teammate         only sees documentation of projects that are delivered AND paid.

const SECURITY_LINK_MINUTES = 10;

const SECURITY_MEASURES = [
    'Every database query uses prepared statements (SQL injection)',
    'CSRF tokens on every form and state-changing request',
    'Passwords hashed with bcrypt; OTP second step on every login',
    'Account lockout and IP rate limiting on repeated failed logins',
    'Uploaded files checked by real MIME type and served through an access-checked download script',
    'Session hardening: HttpOnly + SameSite cookies, ID regeneration, 30-minute idle timeout',
    'Security headers: X-Frame-Options, X-Content-Type-Options, Content-Security-Policy',
    'Role checks on every portal page, plus per-project membership checks',
    'Dedicated security testing role with vulnerability classes and severity on every finding',
];

function access_schema_ready($conn) {
    static $ready = null;
    if ($ready === null) {
        $ready = onboarding_schema_ready($conn) && db_column_exists($conn, 'deliveries', 'security_viewed_at');
    }
    return $ready;
}

// Who is this client, and whose records may they see?
function client_context($conn, $user_id) {
    $ctx = ['role' => 'pm', 'company' => null, 'member_ids' => [(int)$user_id], 'label' => 'Client'];
    if (!onboarding_schema_ready($conn)) return $ctx;   // before the migration: old single-client behaviour

    $q = mysqli_prepare($conn, "SELECT company_id, client_role FROM users WHERE id = ?");
    mysqli_stmt_bind_param($q, "i", $user_id);
    mysqli_stmt_execute($q);
    $me = mysqli_fetch_assoc(mysqli_stmt_get_result($q)) ?: [];

    $ctx['role']  = isset(CLIENT_ROLES[$me['client_role'] ?? '']) ? $me['client_role'] : 'pm';
    $ctx['label'] = client_role_label($ctx['role']);
    $company      = get_company($conn, $me['company_id'] ?? 0);
    if ($company && empty($company['is_internal'])) {
        $ctx['company'] = $company;
        $m = mysqli_prepare($conn, "SELECT id FROM users WHERE company_id = ? AND role = 'client'");
        mysqli_stmt_bind_param($m, "i", $company['id']);
        mysqli_stmt_execute($m);
        $ids = array_map('intval', array_column(mysqli_fetch_all(mysqli_stmt_get_result($m), MYSQLI_ASSOC), 'id'));
        if ($ids) $ctx['member_ids'] = $ids;
    }
    return $ctx;
}

function client_can($ctx, $action) {
    $rules = [
        'submit_requirement'  => ['pm'],
        'delete_requirement'  => ['pm'],
        'comment'             => ['pm', 'it_manager'],
        'pay_invoice'         => ['pm', 'it_manager'],
        'view_projects'       => ['pm', 'it_manager'],
        'view_deliveries'     => ['pm', 'it_manager'],
        'security_summary'    => ['pm'],
        'manage_team'         => ['it_manager'],
    ];
    return in_array($ctx['role'], $rules[$action] ?? [], true);
}

// "12,15,19" — safe to inline: every value is cast to int.
function id_list(array $ids) {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    return $ids ? implode(',', $ids) : '0';
}

// Only http(s) links are ever rendered as href.
function safe_url($url) {
    $url = trim((string)$url);
    if ($url === '' || !preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) return '';
    return $url;
}

// Projects of this company that are delivered AND have a paid invoice.
function company_released_docs($conn, array $member_ids) {
    $ids = id_list($member_ids);
    $res = mysqli_query($conn,
        "SELECT p.id, p.project_code, p.title, d.documentation_link, d.deployment_link, d.delivered_at,
                MAX(i.paid_at) AS paid_at
         FROM projects p
         JOIN requirements r ON r.id = p.requirement_id
         JOIN deliveries d   ON d.project_id = p.id
         JOIN invoices i     ON i.project_id = p.id AND i.status = 'paid'
         WHERE r.user_id IN ($ids)
         GROUP BY p.id, p.project_code, p.title, d.documentation_link, d.deployment_link, d.delivered_at
         ORDER BY paid_at DESC");
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
}

// Creates a single-use, short-lived link for the security summary of a delivery.
// Returns the raw token (only its hash is stored) or null with $error set.
function create_security_link($conn, $delivery_id, $user_id, array $member_ids, &$error = null) {
    $ids = id_list($member_ids);
    $q = mysqli_prepare($conn,
        "SELECT d.id, d.security_viewed_at FROM deliveries d
         JOIN projects p ON p.id = d.project_id
         JOIN requirements r ON r.id = p.requirement_id
         WHERE d.id = ? AND r.user_id IN ($ids)");
    mysqli_stmt_bind_param($q, "i", $delivery_id);
    mysqli_stmt_execute($q);
    $d = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
    if (!$d)                        { $error = "Delivery not found."; return null; }
    if ($d['security_viewed_at'])   { $error = "The security summary for this project has already been viewed."; return null; }

    $del = mysqli_prepare($conn, "DELETE FROM security_disclosures WHERE delivery_id = ? OR expires_at < NOW()");
    mysqli_stmt_bind_param($del, "i", $delivery_id);
    mysqli_stmt_execute($del);

    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);
    $ins   = mysqli_prepare($conn,
        "INSERT INTO security_disclosures (delivery_id, user_id, token_hash, expires_at)
         VALUES (?, ?, ?, NOW() + INTERVAL " . SECURITY_LINK_MINUTES . " MINUTE)");
    mysqli_stmt_bind_param($ins, "iis", $delivery_id, $user_id, $hash);
    mysqli_stmt_execute($ins);
    return $token;
}

// Consumes a security link: deletes its record and marks the delivery as viewed.
// Returns the delivery row (with project info) or null with $error set.
function consume_security_link($conn, $token, $user_id, &$error = null) {
    if (!preg_match('/^[a-f0-9]{64}$/', (string)$token)) { $error = "This link is not valid."; return null; }
    $hash = hash('sha256', $token);

    mysqli_begin_transaction($conn);
    $q = mysqli_prepare($conn,
        "SELECT s.id AS disclosure_id, s.user_id, s.expires_at < NOW() AS expired,
                d.*, p.project_code, p.title AS project_title
         FROM security_disclosures s
         JOIN deliveries d ON d.id = s.delivery_id
         JOIN projects p   ON p.id = d.project_id
         WHERE s.token_hash = ? FOR UPDATE");
    mysqli_stmt_bind_param($q, "s", $hash);
    mysqli_stmt_execute($q);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($q));

    if (!$row) { mysqli_rollback($conn); $error = "This link has already been used or has expired."; return null; }

    // The record is removed as soon as the link is opened — whatever happens next.
    $del = mysqli_prepare($conn, "DELETE FROM security_disclosures WHERE id = ?");
    mysqli_stmt_bind_param($del, "i", $row['disclosure_id']);
    mysqli_stmt_execute($del);

    if ((int)$row['user_id'] !== (int)$user_id) { mysqli_commit($conn); $error = "This link was created for someone else."; return null; }
    if ($row['expired'])                        { mysqli_commit($conn); $error = "This link expired. Go back and open the summary again."; return null; }
    if ($row['security_viewed_at'])             { mysqli_commit($conn); $error = "The security summary for this project has already been viewed."; return null; }

    $mark = mysqli_prepare($conn, "UPDATE deliveries SET security_viewed_at = NOW(), security_viewed_by = ? WHERE id = ? AND security_viewed_at IS NULL");
    mysqli_stmt_bind_param($mark, "ii", $user_id, $row['id']);
    mysqli_stmt_execute($mark);
    if (mysqli_stmt_affected_rows($mark) !== 1) { mysqli_rollback($conn); $error = "The security summary for this project has already been viewed."; return null; }
    mysqli_commit($conn);
    return $row;
}
