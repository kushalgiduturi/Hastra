<?php
// api/search_index.php
// Entries for the Ctrl/Cmd+K palette (assets/js/hastra-ux.js). Public pages
// for everyone; signed-in people also get their own workspace home and
// account pages. The palette adds the links the current page already shows
// (its nav and dock), which the server has filtered by role, so this file
// never has to restate who may open what.
include __DIR__ . '/../core/db.php';
header('Content-Type: application/json');
header('Cache-Control: private, max-age=300');
astra_rate_guard($conn, 'search:' . astra_rate_client_ip(), 60, 60);

$b = get_base_url();
$entries = [
    ['title' => 'Home', 'href' => $b, 'group' => 'Hastra', 'keywords' => 'landing start'],
    ['title' => 'Hastra Labs', 'href' => $b . 'labs/', 'group' => 'Labs', 'keywords' => 'free tools'],
    ['title' => 'Cryptography & File Armor', 'href' => $b . 'labs/crypto', 'group' => 'Labs', 'keywords' => 'encrypt aes pqc ml-kem hash password'],
    ['title' => 'SIEM / Threat Intel & SOC Lab', 'href' => $b . 'labs/siem', 'group' => 'Labs', 'keywords' => 'stix taxii misp soc alerts'],
    ['title' => 'Syllabus Accelerator & Video Tutor', 'href' => $b . 'labs/syllabus', 'group' => 'Labs', 'keywords' => 'study course video'],
    ['title' => 'Privacy Policy', 'href' => $b . 'legal/privacy', 'group' => 'Legal', 'keywords' => 'gdpr data'],
    ['title' => 'Terms & Conditions', 'href' => $b . 'legal/terms', 'group' => 'Legal', 'keywords' => 'agreement'],
    ['title' => 'Cookie Policy', 'href' => $b . 'legal/cookies', 'group' => 'Legal', 'keywords' => 'consent'],
    ['title' => 'Refund Policy', 'href' => $b . 'legal/refunds', 'group' => 'Legal', 'keywords' => 'billing money back'],
    ['title' => 'Delete my data', 'href' => $b . 'privacy/delete-data', 'group' => 'Privacy', 'keywords' => 'erase account gdpr right to be forgotten'],
];

// read-only peek: never creates a session or sets a cookie for a visitor
$signed_in = false;
$sid = $_COOKIE[session_name()] ?? '';
if (is_string($sid) && preg_match('/^[A-Za-z0-9,-]{22,128}$/', $sid)) {
    session_id($sid);
    if (@session_start(['read_and_close' => true, 'use_cookies' => 0, 'use_strict_mode' => 0])) {
        $signed_in = !empty($_SESSION['user_id']) && (time() - (int)($_SESSION['last_activity'] ?? 0)) <= 1800;
    }
    $_SESSION = [];
}
if ($signed_in) {
    array_unshift($entries,
        ['title' => 'My workspace', 'href' => $b . 'workspace/', 'group' => 'Workspace', 'keywords' => 'dashboard home portal'],
        ['title' => 'Settings / Profile', 'href' => $b . 'workspace/user/profile', 'group' => 'Workspace', 'keywords' => 'account settings'],
        ['title' => 'Sign out', 'href' => $b . 'signout', 'group' => 'Workspace', 'keywords' => 'logout']);
} else {
    array_unshift($entries,
        ['title' => 'Sign in', 'href' => $b . 'signin', 'group' => 'Account', 'keywords' => 'login'],
        ['title' => 'Create an account', 'href' => $b . 'signup', 'group' => 'Account', 'keywords' => 'register signup']);
}
echo json_encode(['ok' => true, 'support' => LEGAL_SUPPORT_EMAIL, 'entries' => $entries]);
