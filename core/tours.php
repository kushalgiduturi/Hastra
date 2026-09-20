<?php
// core/tours.php  (P18)
// Persisted "has this user seen the onboarding tour for this page" state.
// Backs api/tour_status.php; the browser caches the answer in localStorage
// (assets/js/tour.js) so most page visits never call this at all.

function tours_schema_ready($conn) {
    static $ready = null;
    if ($ready === null) {
        $r = mysqli_query($conn, "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_page_tours'");
        $ready = $r && mysqli_num_rows($r) > 0;
    }
    return $ready;
}

// Page keys are short slugs like "client/client_portal" — never rendered as
// HTML, but still worth constraining before it touches a query or the DB.
function tour_page_key_valid($page_key) {
    return is_string($page_key) && $page_key !== '' && preg_match('/^[a-z0-9_\/]{1,64}$/', $page_key) === 1;
}

// 'completed' | 'skipped' | null (never seen, invalid key, or not migrated yet).
function get_tour_status($conn, $user_id, $page_key) {
    if (!tours_schema_ready($conn) || !tour_page_key_valid($page_key)) return null;
    $q = mysqli_prepare($conn, "SELECT status FROM user_page_tours WHERE user_id = ? AND page_key = ?");
    mysqli_stmt_bind_param($q, "is", $user_id, $page_key);
    mysqli_stmt_execute($q);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
    return $row ? $row['status'] : null;
}

function set_tour_status($conn, $user_id, $page_key, $status) {
    if (!tours_schema_ready($conn) || !tour_page_key_valid($page_key)) return false;
    if (!in_array($status, ['completed', 'skipped'], true)) return false;
    $ins = mysqli_prepare($conn,
        "INSERT INTO user_page_tours (user_id, page_key, status, completed_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE status = VALUES(status), completed_at = NOW()");
    mysqli_stmt_bind_param($ins, "iss", $user_id, $page_key, $status);
    return mysqli_stmt_execute($ins);
}
