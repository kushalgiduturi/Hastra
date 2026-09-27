<?php
// auth/google_auth.php — "Continue with Google".
//
//   GET  ?action=login        start a sign-in
//   POST action=register      start a sign-up (from auth/register.php, carrying
//                             the chosen track and workspace details)
//   GET  ?code=…&state=…      Google's redirect back (GOOGLE_REDIRECT_URI)
include __DIR__ . '/../core/db.php';
secure_session_start();

function google_fail(string $page, string $code) {
    header('Location: ' . get_base_url() . ($page === 'register' ? 'signup' : 'signin') . '?google_error=' . urlencode($code));
    exit();
}

if (isset($_SESSION['user_id'])) {
    header('Location: ' . astra_role_home((string) $_SESSION['user_role']));
    exit();
}

if (!astra_google_configured() || !astra_google_schema_ready($conn)) {
    google_fail(($_POST['action'] ?? '') === 'register' ? 'register' : 'login', 'not_configured');
}

$VALID_FLOWS = ['client_company', 'client_individual', 'enterprise_full', 'enterprise_solo'];

// ── Start: sign-in ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'login') {
    header('Location: ' . astra_google_begin('login'));
    exit();
}

// ── Start: sign-up. Validates the workspace details before leaving Hastra, so
// a problem is reported on the form rather than after the Google round trip.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {
    verify_csrf_token();
    $flow = $_POST['flow'] ?? '';
    if (!in_array($flow, $VALID_FLOWS, true)) google_fail('register', 'bad_track');

    $needs_org_name = in_array($flow, ['client_company', 'enterprise_full', 'enterprise_solo'], true);
    $needs_org_size = in_array($flow, ['client_company', 'enterprise_full'], true);
    $needs_country  = in_array($flow, ['client_individual', 'enterprise_solo'], true);

    $ctx = [
        'flow'         => $flow,
        'company_name' => $needs_org_name ? trim($_POST['company_name'] ?? '') : '',
        'company_size' => $needs_org_size ? trim($_POST['company_size'] ?? '') : '',
        'country'      => $needs_country ? trim($_POST['country'] ?? '') : '',
        'contract_ref' => $flow === 'client_company' ? trim($_POST['contract_ref'] ?? '') : '',
        'leave_cycle'  => in_array($_POST['leave_cycle'] ?? '', ['monthly', 'yearly_rollover'], true) ? $_POST['leave_cycle'] : 'monthly',
        'monthly_general_leaves' => max(0, min(30, (int) ($_POST['monthly_general_leaves'] ?? 1))),
        'monthly_sick_leaves'    => max(0, min(30, (int) ($_POST['monthly_sick_leaves'] ?? 1))),
        'annual_leave_allowance' => max(0, min(90, (int) ($_POST['annual_leave_allowance'] ?? 18))),
    ];
    if (($_POST['accept_terms'] ?? '') !== '1')                                   google_fail('register', 'terms_required');
    if ($needs_org_name && $ctx['company_name'] === '')                           google_fail('register', 'company_required');
    if ($needs_org_size && !isset(COMPANY_SIZES[$ctx['company_size']]))           google_fail('register', 'size_required');
    if ($needs_country && ($ctx['country'] === '' || mb_strlen($ctx['country']) > 60)) google_fail('register', 'country_required');
    if (mb_strlen($ctx['company_name']) > 150 || mb_strlen($ctx['contract_ref']) > 60) google_fail('register', 'too_long');
    if ($ctx['company_name'] !== '' && find_company_by_name($conn, $ctx['company_name'])) google_fail('register', 'company_taken');

    header('Location: ' . astra_google_begin('register', $ctx));
    exit();
}

// ── Callback ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !isset($_GET['state'])) {
    header('Location: ' . get_base_url() . 'signin');
    exit();
}

$attempt = astra_google_take_attempt((string) $_GET['state']);
if (!$attempt) google_fail('login', 'expired');
$back = $attempt['action'] === 'register' ? 'register' : 'login';
if (isset($_GET['error'])) google_fail($back, 'cancelled');

// Same pre-credential gates as password sign-in (auth/login.php).
$client_ip  = astra_get_client_ip();
$ip_inspect = astra_inspect_ip($client_ip, $conn);
if ($ip_inspect['is_vpn']) {
    log_activity($conn, null, 'login_blocked_vpn', $ip_inspect['isp'] ?? 'Unknown ISP', $client_ip);
    google_fail($back, 'vpn');
}
if (check_ip_limit($conn)) google_fail($back, 'ip_locked');

$profile = astra_google_complete($attempt, (string) ($_GET['code'] ?? ''));
if (!$profile) {
    increment_ip_attempts($conn);
    google_fail($back, 'verify_failed');
}

astra_canary_check($conn, 'canary_user', $profile['email']);

[$user, $err] = astra_google_find_user($conn, $profile);
if ($err) google_fail($back, $err);

if ($user) {
    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) google_fail('login', 'locked');
    reset_ip_attempts($conn);
    astra_google_start_session($conn, $user);
    astra_same_site_redirect(astra_role_home($user['role']));
}

if ($attempt['action'] !== 'register') google_fail('register', 'no_account');

// ── New account from a verified Google identity (core/google_oauth.php).
$made = astra_google_provision($conn, $profile, $attempt['context']);
if (isset($made['error'])) google_fail('register', $made['error']);

// First entry lands behind the profile-completion barrier (core/auth_check.php),
// since gender and phone are not collected from Google.
astra_google_start_session($conn, $made);
astra_same_site_redirect(astra_role_home($made['role']));
